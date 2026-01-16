<?php
/**
 * Embedding Client for OpenRouter API
 *
 * @package Vibe\AIIndex\Services\KB
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Logger;
use Vibe\AIIndex\Services\Exceptions\RateLimitException;

/**
 * Client for generating embeddings via OpenRouter API.
 * Extends the pattern from AIClient but for embeddings endpoint.
 */
class EmbeddingClient
{
    /**
     * OpenRouter API base URL.
     */
    private string $baseUrl = 'https://openrouter.ai/api/v1';

    /**
     * The API key for OpenRouter.
     */
    private string $apiKey;

    /**
     * Logger instance.
     */
    private Logger $logger;

    /**
     * Maximum retry attempts.
     */
    private const MAX_RETRIES = 3;

    /**
     * Backoff multiplier for retries.
     */
    private const BACKOFF_MULTIPLIER = 2;

    /**
     * Base delay in seconds for exponential backoff.
     */
    private const BASE_DELAY_SECONDS = 5;

    /**
     * Request timeout in seconds.
     */
    private const REQUEST_TIMEOUT = 120;

    /**
     * Known embedding models and their dimensions.
     *
     * @var array<string, int>
     */
    private const MODEL_DIMENSIONS = [
        'openai/text-embedding-3-small' => 1536,
        'openai/text-embedding-3-large' => 3072,
        'openai/text-embedding-ada-002' => 1536,
        'cohere/embed-english-v3.0' => 1024,
        'cohere/embed-multilingual-v3.0' => 1024,
        'cohere/embed-english-light-v3.0' => 384,
        'cohere/embed-multilingual-light-v3.0' => 384,
        'voyage/voyage-2' => 1024,
        'voyage/voyage-large-2' => 1536,
        'voyage/voyage-code-2' => 1536,
    ];

    /**
     * Constructor.
     *
     * @param string|null $apiKey The OpenRouter API key. If null, reads from VIBE_AI_OPENROUTER_KEY constant.
     *
     * @throws \RuntimeException If API key is not available.
     */
    public function __construct(?string $apiKey = null)
    {
        if ($apiKey !== null) {
            $this->apiKey = $apiKey;
        } elseif (defined('VIBE_AI_OPENROUTER_KEY')) {
            $this->apiKey = VIBE_AI_OPENROUTER_KEY;
        } else {
            throw new \RuntimeException(
                'OpenRouter API key not configured. Define VIBE_AI_OPENROUTER_KEY in wp-config.php'
            );
        }

        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenRouter API key cannot be empty');
        }

        $this->logger = new Logger();
    }

    /**
     * Generate embeddings for multiple texts in one request.
     *
     * @param array<string> $texts Array of text strings to embed
     * @param string|null $model Override default model
     *
     * @return array{
     *   embeddings: array<array<float>>,
     *   model: string,
     *   dims: int,
     *   usage: array{prompt_tokens: int, total_tokens: int}
     * }
     *
     * @throws RateLimitException If rate limit is exceeded after all retries.
     * @throws \RuntimeException If embedding fails after all retries.
     */
    public function embed(array $texts, ?string $model = null): array
    {
        if (empty($texts)) {
            throw new \InvalidArgumentException('Text array cannot be empty');
        }

        $model = $model ?? Config::KB_EMBEDDING_MODEL;
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $startTime = microtime(true);

                $payload = [
                    'model' => $model,
                    'input' => $texts,
                ];

                $response = $this->makeRequest($payload);
                $duration = (int) ((microtime(true) - $startTime) * 1000);

                // Parse embeddings from response
                $embeddings = [];
                if (!isset($response['data']) || !is_array($response['data'])) {
                    throw new \RuntimeException('Invalid API response: missing data array');
                }

                foreach ($response['data'] as $item) {
                    if (!isset($item['embedding']) || !is_array($item['embedding'])) {
                        throw new \RuntimeException('Invalid API response: missing embedding array');
                    }
                    $embeddings[] = $item['embedding'];
                }

                // Determine dimensions
                $dims = !empty($embeddings) ? count($embeddings[0]) : 0;

                // Extract usage information
                $usage = [
                    'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                    'total_tokens' => $response['usage']['total_tokens'] ?? 0,
                ];

                // Log successful API call
                $this->logger->api($model, $duration, true);
                $this->logger->debug('Embedding request completed', [
                    'model' => $model,
                    'text_count' => count($texts),
                    'dims' => $dims,
                    'duration_ms' => $duration,
                    'prompt_tokens' => $usage['prompt_tokens'],
                ]);

                return [
                    'embeddings' => $embeddings,
                    'model' => $model,
                    'dims' => $dims,
                    'usage' => $usage,
                ];
            } catch (RateLimitException $e) {
                $lastException = $e;

                $this->logger->warning('Rate limit hit during embedding', [
                    'attempt' => $attempt,
                    'retry_after' => $e->getRetryAfter(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    $this->handleRateLimit($e->getRetryAfter());
                }
            } catch (\Exception $e) {
                $lastException = $e;

                $this->logger->warning('Embedding request failed', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    $delay = $this->calculateBackoffDelay($attempt);
                    $this->sleep($delay);
                }
            }
        }

        // Log final failure
        $this->logger->api($model, 0, false, $lastException ? $lastException->getMessage() : 'Unknown error');

        if ($lastException instanceof RateLimitException) {
            throw $lastException;
        }

        throw new \RuntimeException(
            sprintf(
                'Embedding generation failed after %d attempts: %s',
                self::MAX_RETRIES,
                $lastException ? $lastException->getMessage() : 'Unknown error'
            ),
            0,
            $lastException
        );
    }

    /**
     * Generate embedding for single text.
     *
     * @param string $text The text to embed
     * @param string|null $model Override default model
     *
     * @return array<float> The embedding vector
     *
     * @throws RateLimitException If rate limit is exceeded.
     * @throws \RuntimeException If embedding fails.
     */
    public function embedSingle(string $text, ?string $model = null): array
    {
        if (empty(trim($text))) {
            throw new \InvalidArgumentException('Text cannot be empty');
        }

        $result = $this->embed([$text], $model);

        return $result['embeddings'][0];
    }

    /**
     * Check if model supports embeddings.
     *
     * @param string $model The model identifier
     *
     * @return bool True if model is known to support embeddings
     */
    public function supportsEmbeddings(string $model): bool
    {
        // Check known models
        if (isset(self::MODEL_DIMENSIONS[$model])) {
            return true;
        }

        // Check model name patterns that typically indicate embedding support
        $embeddingPatterns = [
            '/embed/i',
            '/embedding/i',
            '/text-embedding/i',
            '/voyage/i',
        ];

        foreach ($embeddingPatterns as $pattern) {
            if (preg_match($pattern, $model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get embedding dimensions for model.
     *
     * @param string $model The model identifier
     *
     * @return int|null The dimension count or null if unknown
     */
    public function getModelDimensions(string $model): ?int
    {
        return self::MODEL_DIMENSIONS[$model] ?? null;
    }

    /**
     * Make HTTP request to OpenRouter API.
     *
     * @param array<string, mixed> $payload The request payload
     *
     * @return array<string, mixed> The API response
     *
     * @throws RateLimitException If API returns 429 status.
     * @throws \RuntimeException If request fails.
     */
    private function makeRequest(array $payload): array
    {
        $endpoint = $this->baseUrl . '/embeddings';

        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url(),
                'X-Title' => 'AI Entity Index - Knowledge Base',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => self::REQUEST_TIMEOUT,
            'sslverify' => true,
        ];

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'Embedding API request failed: ' . $response->get_error_message()
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);

        if ($statusCode === 429) {
            $headers = wp_remote_retrieve_headers($response);
            $headersArray = $headers instanceof \ArrayAccess ? iterator_to_array($headers) : (array) $headers;
            throw RateLimitException::fromHeaders($headersArray);
        }

        if ($statusCode !== 200) {
            // Try to extract error message from response
            $errorMessage = $responseBody;
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $errorMessage = $decoded['error']['message'];
            }

            throw new \RuntimeException(
                sprintf('Embedding API returned error status %d: %s', $statusCode, $errorMessage)
            );
        }

        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Failed to decode embedding API response: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }

    /**
     * Handle rate limiting by sleeping for the specified duration.
     *
     * @param int $retryAfter Seconds to wait
     */
    private function handleRateLimit(int $retryAfter): void
    {
        // Cap the wait time to avoid extremely long waits
        $waitTime = min($retryAfter, 120);

        $this->logger->debug('Rate limited, waiting before retry', [
            'wait_seconds' => $waitTime,
        ]);

        $this->sleep($waitTime);
    }

    /**
     * Calculate exponential backoff delay.
     *
     * @param int $attempt The current attempt number (1-based)
     *
     * @return int The delay in seconds
     */
    private function calculateBackoffDelay(int $attempt): int
    {
        return (int) (self::BASE_DELAY_SECONDS * pow(self::BACKOFF_MULTIPLIER, $attempt - 1));
    }

    /**
     * Sleep for the specified number of seconds.
     *
     * This method is extracted to allow for testing/mocking.
     *
     * @param int $seconds Number of seconds to sleep
     */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}

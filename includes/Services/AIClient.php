<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Services\Exceptions\RateLimitException;

/**
 * OpenRouter API Client for AI-powered entity extraction.
 *
 * Handles communication with the OpenRouter API, including:
 * - Exponential backoff retry logic
 * - Rate limiting awareness
 * - JSON response parsing and validation
 *
 * @package Vibe\AIIndex\Services
 */
class AIClient
{
    /**
     * Maximum input characters accepted for extraction.
     */
    public const MAX_INPUT_CHARS = 120000;

    /**
     * Timeout in seconds for API requests.
     */
    private const REQUEST_TIMEOUT = 30;

    /**
     * Circuit breaker option key.
     */
    private const CIRCUIT_OPTION = 'vibe_ai_openrouter_circuit';

    /**
     * Circuit breaker opens after this many consecutive failures.
     */
    private const CIRCUIT_FAILURE_THRESHOLD = 5;

    /**
     * Circuit breaker cool-off period in seconds.
     */
    private const CIRCUIT_COOLDOWN_SECONDS = 300;

    /**
     * OpenRouter API endpoint.
     */
    private const API_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Estimate token cost for a request.
     *
     * @param string $content The input content.
     * @param string $model   The model identifier.
     * @return array{tokens: int, estimated_cost_usd: float} Cost estimate.
     */
    public static function estimateCost(string $content, string $model = ''): array
    {
        $model = $model ?: Config::DEFAULT_MODEL;
        $char_count = strlen($content);
        // Rough estimate: 1 token ≈ 4 characters
        $estimated_tokens = (int) ceil($char_count / 4);
        // Cost per 1M tokens (approximate OpenRouter pricing, input only)
        $cost_per_million = match (true) {
            str_contains($model, 'gpt-4.1-mini') => 0.40,
            str_contains($model, 'sonnet') => 3.0,
            str_contains($model, 'opus') => 15.0,
            default => 5.0,
        };
        $estimated_cost = ($estimated_tokens / 1_000_000) * $cost_per_million;

        return [
            'tokens' => $estimated_tokens,
            'estimated_cost_usd' => round($estimated_cost, 6),
        ];
    }

    /**
     * The API key for OpenRouter.
     */
    private string $api_key;

    /**
     * Request count for rate limiting tracking.
     */
    private int $request_count = 0;

    /**
     * Timestamp of the first request in the current window.
     */
    private int $window_start = 0;

    /**
     * Constructor.
     *
     * @param string|null $api_key The OpenRouter API key. If null, reads from VIBE_AI_OPENROUTER_KEY constant.
     *
     * @throws \RuntimeException If API key is not available.
     */
    public function __construct(?string $api_key = null)
    {
        if ($api_key !== null) {
            $this->api_key = $api_key;
        } elseif (defined('VIBE_AI_OPENROUTER_KEY')) {
            $this->api_key = VIBE_AI_OPENROUTER_KEY;
        } else {
            throw new \RuntimeException(
                'OpenRouter API key not configured. Define VIBE_AI_OPENROUTER_KEY in wp-config.php'
            );
        }

        if (empty($this->api_key)) {
            throw new \RuntimeException('OpenRouter API key cannot be empty');
        }
    }

    /**
     * Extract entities from content using AI.
     *
     * Sends content to the OpenRouter API for entity extraction.
     * Implements exponential backoff retry logic for transient failures.
     *
     * @param string      $content The content to extract entities from.
     * @param string      $system_prompt The system prompt for the AI.
     * @param string|null $model   The model to use (defaults to Config::DEFAULT_MODEL).
     *
     * @return array<string, mixed> The parsed AI response containing entities.
     *
     * @throws RateLimitException If rate limit is exceeded after all retries.
     * @throws \RuntimeException  If extraction fails after all retries.
     */
    public function extract(string $content, string $system_prompt, ?string $model = null): array
    {
        if (trim($content) === '') {
            throw new \InvalidArgumentException('Content cannot be empty');
        }

        if (strlen($content) > self::MAX_INPUT_CHARS) {
            throw new \InvalidArgumentException('Content too large for extraction request');
        }

        $model = $model ?? Config::DEFAULT_MODEL;
        $last_exception = null;

        for ($attempt = 1; $attempt <= Config::RETRY_ATTEMPTS; $attempt++) {
            try {
                $this->guardCircuit();
                $this->checkRateLimit();

                $response = $this->makeRequest($content, $system_prompt, $model);
                $parsed = $this->parseResponse($response);
                $this->recordCircuitSuccess();

                return $parsed;
            } catch (RateLimitException $e) {
                $last_exception = $e;
                $this->recordCircuitFailure($e->getMessage());

                if ($attempt < Config::RETRY_ATTEMPTS) {
                    $delay = $this->calculateBackoffDelay($attempt);
                    $this->sleep($delay);
                }
            } catch (\Exception $e) {
                $last_exception = $e;
                $this->recordCircuitFailure($e->getMessage());

                if ($attempt < Config::RETRY_ATTEMPTS) {
                    $delay = $this->calculateBackoffDelay($attempt);
                    $this->sleep($delay);
                }
            }
        }

        if ($last_exception instanceof RateLimitException) {
            throw $last_exception;
        }

        throw new \RuntimeException(
            sprintf(
                'Entity extraction failed after %d attempts: %s',
                Config::RETRY_ATTEMPTS,
                $last_exception ? $last_exception->getMessage() : 'Unknown error'
            ),
            0,
            $last_exception
        );
    }

    /**
     * Make HTTP request to OpenRouter API.
     *
     * @param string $content       The content to process.
     * @param string $system_prompt The system prompt.
     * @param string $model         The model identifier.
     *
     * @return array<string, mixed> The raw API response.
     *
     * @throws RateLimitException If API returns 429 status.
     * @throws \RuntimeException  If request fails.
     */
    private function makeRequest(string $content, string $system_prompt, string $model): array
    {
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system_prompt,
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'max_tokens' => Config::MAX_TOKENS,
            'temperature' => Config::TEMPERATURE,
            'response_format' => [
                'type' => 'json_object',
            ],
        ];

        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url(),
                'X-Title' => 'AI Entity Index',
            ],
            'body' => wp_json_encode($body),
            'timeout' => self::REQUEST_TIMEOUT,
            'sslverify' => true,
        ];

        $estimate = self::estimateCost($content, $model);
        if ($estimate['estimated_cost_usd'] > 0.50) {
            error_log(sprintf(
                'Vibe AI: Expensive extraction estimated. Model: %s, Tokens: %d, Cost: $%.4f',
                $model,
                $estimate['tokens'],
                $estimate['estimated_cost_usd']
            ));
        }

        $response = wp_remote_post(self::API_ENDPOINT, $args);

        $this->incrementRequestCount();

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'API request failed: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code === 429) {
            $headers = wp_remote_retrieve_headers($response);
            $headers_array = $headers instanceof \ArrayAccess ? iterator_to_array($headers) : (array) $headers;
            throw RateLimitException::fromHeaders($headers_array);
        }

        if ($status_code !== 200) {
            throw new \RuntimeException(
                sprintf('API returned error status %d: %s', $status_code, $response_body)
            );
        }

        $decoded = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Failed to decode API response: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }

    /**
     * Parse and validate the API response.
     *
     * @param array<string, mixed> $response The raw API response.
     *
     * @return array<string, mixed> The parsed response content.
     *
     * @throws \RuntimeException If response structure is invalid.
     */
    private function parseResponse(array $response): array
    {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \RuntimeException('Invalid API response structure: missing content');
        }

        $content = $response['choices'][0]['message']['content'];

        $parsed = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'AI returned invalid JSON: ' . json_last_error_msg()
            );
        }

        if (!is_array($parsed)) {
            throw new \RuntimeException('AI response is not a valid JSON object');
        }

        return $parsed;
    }

    /**
     * Check if we're within rate limits.
     *
     * @throws RateLimitException If rate limit is about to be exceeded.
     */
    private function checkRateLimit(): void
    {
        $current_time = time();

        // Reset window if more than a minute has passed
        if ($current_time - $this->window_start >= 60) {
            $this->request_count = 0;
            $this->window_start = $current_time;
            return;
        }

        if ($this->request_count >= Config::REQUESTS_PER_MINUTE) {
            $wait_time = 60 - ($current_time - $this->window_start);
            throw new RateLimitException(
                'Local rate limit reached. Waiting for window reset.',
                max(1, $wait_time),
                'requests'
            );
        }
    }

    /**
     * Increment the request count for rate limiting.
     */
    private function incrementRequestCount(): void
    {
        if ($this->window_start === 0) {
            $this->window_start = time();
        }
        $this->request_count++;
    }

    /**
     * Calculate exponential backoff delay.
     *
     * @param int $attempt The current attempt number (1-based).
     *
     * @return int The delay in seconds.
     */
    private function calculateBackoffDelay(int $attempt): int
    {
        return (int) (Config::BASE_DELAY_SECONDS * pow(Config::BACKOFF_MULTIPLIER, $attempt - 1));
    }

    /**
     * Sleep for the specified number of seconds.
     *
     * This method is extracted to allow for testing/mocking.
     *
     * @param int $seconds Number of seconds to sleep.
     */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Prevent requests while circuit breaker is open.
     *
     * @return void
     */
    private function guardCircuit(): void
    {
        $state = get_option(self::CIRCUIT_OPTION, []);
        if (!is_array($state) || empty($state['open_until'])) {
            return;
        }

        $openUntil = (int) $state['open_until'];
        if ($openUntil > time()) {
            throw new \RuntimeException('OpenRouter circuit breaker is open; retry later');
        }

        delete_option(self::CIRCUIT_OPTION);
    }

    /**
     * Record a successful call for circuit breaker state.
     *
     * @return void
     */
    private function recordCircuitSuccess(): void
    {
        delete_option(self::CIRCUIT_OPTION);
    }

    /**
     * Record a failed call for circuit breaker state.
     *
     * @param string $reason Failure reason.
     * @return void
     */
    private function recordCircuitFailure(string $reason): void
    {
        $state = get_option(self::CIRCUIT_OPTION, []);
        if (!is_array($state)) {
            $state = [];
        }

        $failures = (int) ($state['failures'] ?? 0) + 1;
        $next = [
            'failures' => $failures,
            'last_error' => sanitize_text_field($reason),
            'updated_at' => time(),
        ];

        if ($failures >= self::CIRCUIT_FAILURE_THRESHOLD) {
            $next['open_until'] = time() + self::CIRCUIT_COOLDOWN_SECONDS;
        }

        update_option(self::CIRCUIT_OPTION, $next, false);
    }

    /**
     * Get the current request count in the rate limit window.
     *
     * @return int The number of requests made in the current window.
     */
    public function getRequestCount(): int
    {
        return $this->request_count;
    }

    /**
     * Reset the rate limit tracking.
     *
     * Useful for testing or when starting a new batch.
     */
    public function resetRateLimitTracking(): void
    {
        $this->request_count = 0;
        $this->window_start = 0;
    }
}

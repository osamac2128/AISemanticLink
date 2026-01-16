<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

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
     * OpenRouter API endpoint.
     */
    private const API_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Default model to use for extraction (Claude Opus 4.5 via OpenRouter).
     */
    private const DEFAULT_MODEL = 'anthropic/claude-opus-4.5';

    /**
     * Rate limiting: requests per minute.
     */
    private const REQUESTS_PER_MINUTE = 60;

    /**
     * Rate limiting: tokens per minute.
     */
    private const TOKENS_PER_MINUTE = 100000;

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
     * Maximum tokens for response.
     */
    private const MAX_TOKENS = 4096;

    /**
     * Temperature for AI responses.
     */
    private const TEMPERATURE = 0.1;

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
     * @param string|null $model   The model to use (defaults to claude-3.5-sonnet).
     *
     * @return array<string, mixed> The parsed AI response containing entities.
     *
     * @throws RateLimitException If rate limit is exceeded after all retries.
     * @throws \RuntimeException  If extraction fails after all retries.
     */
    public function extract(string $content, string $system_prompt, ?string $model = null): array
    {
        $model = $model ?? self::DEFAULT_MODEL;
        $last_exception = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->checkRateLimit();

                $response = $this->makeRequest($content, $system_prompt, $model);

                return $this->parseResponse($response);
            } catch (RateLimitException $e) {
                $last_exception = $e;

                if ($attempt < self::MAX_RETRIES) {
                    $delay = $this->calculateBackoffDelay($attempt);
                    $this->sleep($delay);
                }
            } catch (\Exception $e) {
                $last_exception = $e;

                if ($attempt < self::MAX_RETRIES) {
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
                self::MAX_RETRIES,
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
            'max_tokens' => self::MAX_TOKENS,
            'temperature' => self::TEMPERATURE,
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
            'timeout' => 60,
            'sslverify' => true,
        ];

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

        if ($this->request_count >= self::REQUESTS_PER_MINUTE) {
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
        return (int) (self::BASE_DELAY_SECONDS * pow(self::BACKOFF_MULTIPLIER, $attempt - 1));
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

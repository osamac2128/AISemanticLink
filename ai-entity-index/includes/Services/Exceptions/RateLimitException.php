<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services\Exceptions;

/**
 * Exception thrown when API rate limits are exceeded.
 *
 * This exception is thrown by AIClient when the OpenRouter API returns
 * a 429 status code indicating rate limiting. It includes information
 * about retry timing to enable proper backoff handling.
 */
class RateLimitException extends \Exception
{
    /**
     * Number of seconds to wait before retrying.
     */
    private int $retry_after;

    /**
     * The type of rate limit that was exceeded.
     */
    private string $limit_type;

    /**
     * Constructor.
     *
     * @param string          $message     The exception message.
     * @param int             $retry_after Seconds to wait before retrying.
     * @param string          $limit_type  Type of limit exceeded (requests|tokens).
     * @param int             $code        The exception code.
     * @param \Throwable|null $previous    The previous throwable for chaining.
     */
    public function __construct(
        string $message = 'Rate limit exceeded',
        int $retry_after = 60,
        string $limit_type = 'requests',
        int $code = 429,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->retry_after = $retry_after;
        $this->limit_type = $limit_type;
    }

    /**
     * Get the number of seconds to wait before retrying.
     *
     * @return int Seconds to wait.
     */
    public function getRetryAfter(): int
    {
        return $this->retry_after;
    }

    /**
     * Get the type of rate limit that was exceeded.
     *
     * @return string The limit type (requests|tokens).
     */
    public function getLimitType(): string
    {
        return $this->limit_type;
    }

    /**
     * Create exception from API response headers.
     *
     * @param array<string, mixed> $headers Response headers from the API.
     *
     * @return self
     */
    public static function fromHeaders(array $headers): self
    {
        $retry_after = 60;
        $limit_type = 'requests';

        if (isset($headers['retry-after'])) {
            $retry_after = (int) $headers['retry-after'];
        }

        if (isset($headers['x-ratelimit-limit-tokens'])) {
            $limit_type = 'tokens';
        }

        $message = sprintf(
            'Rate limit exceeded (%s). Retry after %d seconds.',
            $limit_type,
            $retry_after
        );

        return new self($message, $retry_after, $limit_type);
    }
}

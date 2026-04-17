<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Jobs\ExtractionJob;
use Vibe\AIIndex\Services\Exceptions\RateLimitException;

class ExtractionJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('VIBE_AI_OPENROUTER_KEY')) {
            define('VIBE_AI_OPENROUTER_KEY', 'test-key');
        }

        $GLOBALS['mock_options'] = [];
        $GLOBALS['mock_schedules'] = [];
    }

    public function testIsRateLimitErrorDetects429(): void
    {
        $method = new \ReflectionMethod(ExtractionJob::class, 'is_rate_limit_error');
        $method->setAccessible(true);

        $job = new class extends ExtractionJob {
            protected function log(string $level, string $message, array $context = []): void {}
        };

        $result = $method->invoke($job, new \RuntimeException('Got 429 Too Many Requests'));
        $this->assertTrue($result);
    }

    public function testIsRateLimitErrorDetectsRateLimitText(): void
    {
        $method = new \ReflectionMethod(ExtractionJob::class, 'is_rate_limit_error');
        $method->setAccessible(true);

        $job = new class extends ExtractionJob {
            protected function log(string $level, string $message, array $context = []): void {}
        };

        $result = $method->invoke($job, new \RuntimeException('API rate limit exceeded'));
        $this->assertTrue($result);
    }

    public function testIsRateLimitErrorDetectsTooManyRequests(): void
    {
        $method = new \ReflectionMethod(ExtractionJob::class, 'is_rate_limit_error');
        $method->setAccessible(true);

        $job = new class extends ExtractionJob {
            protected function log(string $level, string $message, array $context = []): void {}
        };

        $result = $method->invoke($job, new \RuntimeException('Too many requests in window'));
        $this->assertTrue($result);
    }

    public function testIsRateLimitErrorReturnsFalseForOtherErrors(): void
    {
        $method = new \ReflectionMethod(ExtractionJob::class, 'is_rate_limit_error');
        $method->setAccessible(true);

        $job = new class extends ExtractionJob {
            protected function log(string $level, string $message, array $context = []): void {}
        };

        $result = $method->invoke($job, new \RuntimeException('Connection timeout'));
        $this->assertFalse($result);
    }

    public function testHandleErrorRethrowsRateLimitException(): void
    {
        $method = new \ReflectionMethod(ExtractionJob::class, 'handle_error');
        $method->setAccessible(true);

        $job = new class extends ExtractionJob {
            protected function log(string $level, string $message, array $context = []): void {}
        };

        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $method->invoke($job, new RateLimitException('Rate limit exceeded', 60, 'requests'));
    }

    public function testHookConstantIsCorrect(): void
    {
        $this->assertSame('vibe_ai_phase_extraction', ExtractionJob::HOOK);
    }
}

<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Jobs\KB\EmbedChunksJob;
use Vibe\AIIndex\Services\Exceptions\RateLimitException;

class EmbedChunksJobTest extends TestCase
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

    public function testHandleRateLimitBackoff(): void
    {
        $method = new \ReflectionMethod(EmbedChunksJob::class, 'handleRateLimit');
        $method->setAccessible(true);

        $job = new class extends EmbedChunksJob {
            protected function log(string $level, string $message, array $context = []): void {}
        };

        update_option('vibe_ai_kb_embed_chunks_state', [
            'last_chunk_id' => 15,
            'retry_count' => 1
        ]);

        $exception = new RateLimitException('Embedding API rate limit', 10, 'requests');

        $now = time();
        $method->invoke($job, $exception);

        $state = get_option('vibe_ai_kb_embed_chunks_state');
        $this->assertEquals(2, $state['retry_count']);

        $this->assertCount(1, $GLOBALS['mock_schedules']);

        $schedule = $GLOBALS['mock_schedules'][0];
        $this->assertEquals(EmbedChunksJob::HOOK, $schedule['hook']);
        $this->assertEquals(['last_chunk_id' => 15], $schedule['args']);
        $this->assertTrue($schedule['timestamp'] >= $now + 10);
    }

    public function testHandleRateLimitIncrementsFromZero(): void
    {
        $method = new \ReflectionMethod(EmbedChunksJob::class, 'handleRateLimit');
        $method->setAccessible(true);

        $job = new class extends EmbedChunksJob {
            protected function log(string $level, string $message, array $context = []): void {}
        };

        update_option('vibe_ai_kb_embed_chunks_state', [
            'last_chunk_id' => 0,
            'retry_count' => 0
        ]);

        $exception = new RateLimitException('Rate limited', 5, 'requests');
        $method->invoke($job, $exception);

        $state = get_option('vibe_ai_kb_embed_chunks_state');
        $this->assertEquals(1, $state['retry_count']);
    }

    public function testHookConstantIsCorrect(): void
    {
        $this->assertSame('vibe_ai_kb_embed_chunks', EmbedChunksJob::HOOK);
    }
}

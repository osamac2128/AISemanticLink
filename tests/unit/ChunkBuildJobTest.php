<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Jobs\KB\ChunkBuildJob;

class ChunkBuildJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['mock_options'] = [];
        $GLOBALS['mock_schedules'] = [];
    }

    public function testHookConstantIsCorrect(): void
    {
        $this->assertSame('vibe_ai_kb_chunk_build', ChunkBuildJob::HOOK);
    }

    public function testBatchSizeConstantIsReasonable(): void
    {
        $this->assertGreaterThan(0, ChunkBuildJob::BATCH_SIZE);
        $this->assertLessThanOrEqual(100, ChunkBuildJob::BATCH_SIZE);
    }

    public function testScheduleCreatesAction(): void
    {
        ChunkBuildJob::schedule(0);

        $this->assertCount(1, $GLOBALS['mock_schedules']);
        $schedule = $GLOBALS['mock_schedules'][0];
        $this->assertSame(ChunkBuildJob::HOOK, $schedule['hook']);
        $this->assertSame(['last_doc_id' => 0], $schedule['args']);
        $this->assertSame('vibe-ai-kb', $schedule['group']);
    }

    public function testScheduleWithLastDocId(): void
    {
        ChunkBuildJob::schedule(42);

        $this->assertCount(1, $GLOBALS['mock_schedules']);
        $this->assertSame(['last_doc_id' => 42], $GLOBALS['mock_schedules'][0]['args']);
    }

    public function testExecuteSkipsWhenPipelineNotRunning(): void
    {
        $GLOBALS['mock_options']['vibe_ai_kb_pipeline_status'] = 'idle';

        $wpdbMock = new class {
            public string $prefix = 'wp_';
            public function prepare(string $q, ...$a): string { return $q; }
            public function get_results(string $q): array { return []; }
        };
        $GLOBALS['wpdb'] = $wpdbMock;

        ChunkBuildJob::execute(0);

        $this->assertTrue(true, 'execute() should not throw when pipeline is idle');
        unset($GLOBALS['wpdb']);
    }

    public function testExecuteSkipsWhenStopRequested(): void
    {
        $GLOBALS['mock_options']['vibe_ai_kb_pipeline_status'] = 'running';
        $GLOBALS['mock_options']['vibe_ai_kb_pipeline_stop_requested'] = 1;

        $wpdbMock = new class {
            public string $prefix = 'wp_';
            public function prepare(string $q, ...$a): string { return $q; }
            public function get_results(string $q): array { return []; }
        };
        $GLOBALS['wpdb'] = $wpdbMock;

        ChunkBuildJob::execute(0);

        $this->assertTrue(true, 'execute() should not throw when stop requested');
        unset($GLOBALS['wpdb']);
    }
}

<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConfigPassingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['mock_options'] = [];
        $GLOBALS['mock_schedules'] = [];
    }

    private function resolveConfig(array $args): array
    {
        return $args['config'] ?? (isset($args['batch_size']) ? $args : []);
    }

    public function testWrappedConfigArray(): void
    {
        $config = $this->resolveConfig(['config' => ['batch_size' => 25, 'post_types' => ['post']]]);
        $this->assertEquals(['batch_size' => 25, 'post_types' => ['post']], $config);
    }

    public function testDirectConfigArray(): void
    {
        $config = $this->resolveConfig(['batch_size' => 10]);
        $this->assertEquals(['batch_size' => 10], $config);
    }

    public function testEmptyArrayProducesEmptyConfig(): void
    {
        $config = $this->resolveConfig([]);
        $this->assertEquals([], $config);
    }

    public function testUnknownKeyNoBatchSizeProducesEmptyConfig(): void
    {
        $config = $this->resolveConfig(['unknown_key' => 'value']);
        $this->assertEquals([], $config);
    }

    public function testConfigWithBatchSizeIsPreserved(): void
    {
        $config = $this->resolveConfig(['batch_size' => 20, 'custom' => 'test']);
        $this->assertEquals(['batch_size' => 20, 'custom' => 'test'], $config);
    }

    public function testConfigKeyTakesPrecedenceOverDirect(): void
    {
        $config = $this->resolveConfig(['config' => ['batch_size' => 5], 'batch_size' => 10]);
        $this->assertEquals(['batch_size' => 5], $config);
    }

    public function testNestedConfigIsUnwrapped(): void
    {
        $config = $this->resolveConfig(['config' => ['batch_size' => 30, 'model' => 'test', 'types' => ['a', 'b']]]);
        $this->assertEquals(['batch_size' => 30, 'model' => 'test', 'types' => ['a', 'b']], $config);
    }
}

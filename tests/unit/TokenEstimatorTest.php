<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\KB\TokenEstimator;

class TokenEstimatorTest extends TestCase
{
    private TokenEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new TokenEstimator();
    }

    public function testEmptyStringReturnsZero(): void
    {
        $this->assertSame(0, $this->estimator->estimate(''));
    }

    public function testWhitespaceOnlyReturnsZero(): void
    {
        $this->assertSame(0, $this->estimator->estimate('   '));
    }

    public function testSingleWordReturnsAtLeastOne(): void
    {
        $tokens = $this->estimator->estimate('hello');
        $this->assertGreaterThanOrEqual(1, $tokens);
    }

    public function testLongerTextReturnsMoreTokens(): void
    {
        $short = $this->estimator->estimate('Hello world');
        $long = $this->estimator->estimate('This is a much longer sentence with many more words to estimate tokens for');
        $this->assertGreaterThan($short, $long);
    }

    public function testEstimateIsConsistent(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog';
        $first = $this->estimator->estimate($text);
        $second = $this->estimator->estimate($text);
        $this->assertEquals($first, $second);
    }

    public function testEstimateCharsForTokens(): void
    {
        $chars = $this->estimator->estimateCharsForTokens(100);
        $this->assertGreaterThan(0, $chars);
        $this->assertIsInt($chars);
    }

    public function testEstimateCharsForZeroTokens(): void
    {
        $this->assertSame(0, $this->estimator->estimateCharsForTokens(0));
    }

    public function testExceedsLimitReturnsTrue(): void
    {
        $text = str_repeat('This is a test sentence for token estimation. ', 50);
        $this->assertTrue($this->estimator->exceedsLimit($text, 10));
    }

    public function testExceedsLimitReturnsFalse(): void
    {
        $this->assertFalse($this->estimator->exceedsLimit('short text', 1000));
    }

    public function testSplitToTokenLimitSingleChunk(): void
    {
        $text = 'This is short text';
        $chunks = $this->estimator->splitToTokenLimit($text, 1000);
        $this->assertCount(1, $chunks);
        $this->assertEquals($text, $chunks[0]);
    }

    public function testSplitToTokenLimitMultipleChunks(): void
    {
        $text = str_repeat('This is a test paragraph that should be split into multiple chunks. ', 30);
        $chunks = $this->estimator->splitToTokenLimit($text, 50);
        $this->assertGreaterThan(1, count($chunks));
    }

    public function testSplitEmptyReturnsEmpty(): void
    {
        $this->assertSame([], $this->estimator->splitToTokenLimit('', 100));
        $this->assertSame([], $this->estimator->splitToTokenLimit('   ', 100));
    }

    public function testExceedsLimitThrowsOnZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->estimator->exceedsLimit('text', 0);
    }

    public function testSplitToTokenLimitThrowsOnZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->estimator->splitToTokenLimit('text', 0);
    }
}

<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\KB\Chunker;
use Vibe\AIIndex\Services\KB\TokenEstimator;
use Vibe\AIIndex\Services\KB\AnchorGenerator;

class ChunkerTest extends TestCase
{
    private Chunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $tokenEstimator = new TokenEstimator();
        $anchorGenerator = new AnchorGenerator();
        $this->chunker = new Chunker($tokenEstimator, $anchorGenerator);
    }

    public function testChunkEmptyContentReturnsEmpty(): void
    {
        $result = $this->chunker->chunk('', [], 1, 'hash');
        $this->assertSame([], $result);
    }

    public function testChunkWhitespaceOnlyReturnsEmpty(): void
    {
        $result = $this->chunker->chunk('   ', [], 1, 'hash');
        $this->assertSame([], $result);
    }

    public function testChunkShortContentReturnsSingleChunk(): void
    {
        $content = 'Hello world this is a test';
        $result = $this->chunker->chunk($content, [], 1, 'hash');

        $this->assertGreaterThan(0, count($result));
        $chunk = $result[0];

        $this->assertArrayHasKey('chunk_index', $chunk);
        $this->assertArrayHasKey('anchor', $chunk);
        $this->assertArrayHasKey('heading_path', $chunk);
        $this->assertArrayHasKey('chunk_text', $chunk);
        $this->assertArrayHasKey('chunk_hash', $chunk);
        $this->assertArrayHasKey('start_offset', $chunk);
        $this->assertArrayHasKey('end_offset', $chunk);
        $this->assertArrayHasKey('token_estimate', $chunk);
    }

    public function testChunkTextContainsOriginalContent(): void
    {
        $content = 'This is unique test content for chunking';
        $result = $this->chunker->chunk($content, [], 1, 'hash');
        $this->assertStringContainsString('unique test content', $result[0]['chunk_text']);
    }

    public function testChunkWithHeadings(): void
    {
        $content = "Introduction\n\nThis is the intro section.\n\nGetting Started\n\nHere is how to get started.";
        $headings = [
            ['level' => 2, 'text' => 'Introduction'],
            ['level' => 2, 'text' => 'Getting Started'],
        ];

        $result = $this->chunker->chunk($content, $headings, 1, 'hash');
        $this->assertGreaterThan(0, count($result));

        foreach ($result as $chunk) {
            $this->assertIsArray($chunk['heading_path']);
        }
    }

    public function testChunkLongContentMultipleChunks(): void
    {
        $paragraphs = [];
        for ($i = 0; $i < 50; $i++) {
            $paragraphs[] = "Paragraph {$i}: " . str_repeat("This is test content for paragraph number {$i}. ", 20);
        }
        $content = implode("\n\n", $paragraphs);

        $result = $this->chunker->chunk($content, [], 1, 'hash');
        $this->assertGreaterThan(1, count($result));
    }

    public function testChunkIndexIsSequential(): void
    {
        $content = str_repeat("This is a paragraph with enough content to generate multiple chunks when using small target tokens. ", 30);
        $tokenEstimator = new TokenEstimator();
        $anchorGenerator = new AnchorGenerator();
        $smallChunker = new Chunker($tokenEstimator, $anchorGenerator, 50, 10);

        $result = $smallChunker->chunk($content, [], 1, 'hash');

        for ($i = 0; $i < count($result); $i++) {
            $this->assertEquals($i, $result[$i]['chunk_index'], "Chunk at position {$i} should have chunk_index {$i}");
        }
    }

    public function testChunkHashIsConsistent(): void
    {
        $content = 'Same content same hash';
        $result1 = $this->chunker->chunk($content, [], 1, 'hash');
        $result2 = $this->chunker->chunk($content, [], 1, 'hash');

        $this->assertSame($result1[0]['chunk_hash'], $result2[0]['chunk_hash']);
    }

    public function testSetTargetTokensValidates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chunker->setTargetTokens(1);
    }

    public function testSetOverlapTokensValidatesNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chunker->setOverlapTokens(-1);
    }

    public function testSetOverlapTokensValidatesExceedsTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chunker->setTargetTokens(100);
        $this->chunker->setOverlapTokens(100);
    }

    public function testGetTargetTokensReturnsDefault(): void
    {
        $this->assertSame(450, $this->chunker->getTargetTokens());
    }

    public function testGetOverlapTokensReturnsDefault(): void
    {
        $this->assertSame(60, $this->chunker->getOverlapTokens());
    }

    public function testSetTargetTokensReturnsSelf(): void
    {
        $result = $this->chunker->setTargetTokens(200);
        $this->assertSame($this->chunker, $result);
        $this->assertSame(200, $this->chunker->getTargetTokens());
    }
}

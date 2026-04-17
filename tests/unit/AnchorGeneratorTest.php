<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\KB\AnchorGenerator;

class AnchorGeneratorTest extends TestCase
{
    private AnchorGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new AnchorGenerator();
    }

    public function testGenerateReturnsKbPrefixedAnchor(): void
    {
        $anchor = $this->generator->generate(1, ['Introduction'], 0, 'abc123');
        $this->assertStringStartsWith('kb-', $anchor);
    }

    public function testGenerateReturnsDeterministicAnchor(): void
    {
        $anchor1 = $this->generator->generate(1, ['Test'], 0, 'hash123');
        $anchor2 = $this->generator->generate(1, ['Test'], 0, 'hash123');
        $this->assertSame($anchor1, $anchor2);
    }

    public function testGenerateDiffersForDifferentInputs(): void
    {
        $anchor1 = $this->generator->generate(1, ['Test'], 0, 'hash1');
        $anchor2 = $this->generator->generate(2, ['Test'], 0, 'hash1');
        $anchor3 = $this->generator->generate(1, ['Different'], 0, 'hash1');
        $anchor4 = $this->generator->generate(1, ['Test'], 1, 'hash1');

        $this->assertNotSame($anchor1, $anchor2);
        $this->assertNotSame($anchor1, $anchor3);
        $this->assertNotSame($anchor1, $anchor4);
    }

    public function testGenerateWithEmptyHeadingPath(): void
    {
        $anchor = $this->generator->generate(5, [], 0, 'hash');
        $this->assertStringStartsWith('kb-', $anchor);
        $this->assertTrue($this->generator->isValid($anchor));
    }

    public function testIsValidAcceptsValidAnchor(): void
    {
        $anchor = $this->generator->generate(1, ['Test'], 0, 'hash');
        $this->assertTrue($this->generator->isValid($anchor));
    }

    public function testIsValidRejectsEmpty(): void
    {
        $this->assertFalse($this->generator->isValid(''));
    }

    public function testIsValidRejectsInvalidFormat(): void
    {
        $this->assertFalse($this->generator->isValid('invalid-anchor'));
        $this->assertFalse($this->generator->isValid('kb-short'));
        $this->assertFalse($this->generator->isValid('prefix-a1b2c3d4e5f6'));
    }

    public function testParseReturnsComponents(): void
    {
        $anchor = $this->generator->generate(1, ['Test'], 0, 'hash');
        $parsed = $this->generator->parse($anchor);
        $this->assertNotNull($parsed);
        $this->assertArrayHasKey('prefix', $parsed);
        $this->assertArrayHasKey('hash', $parsed);
        $this->assertSame('kb-', $parsed['prefix']);
        $this->assertSame(12, strlen($parsed['hash']));
    }

    public function testParseReturnsNullForInvalid(): void
    {
        $this->assertNull($this->generator->parse(''));
        $this->assertNull($this->generator->parse('invalid'));
    }

    public function testEqualsPerformsComparison(): void
    {
        $anchor1 = $this->generator->generate(1, ['Test'], 0, 'hash');
        $anchor2 = $this->generator->generate(1, ['Test'], 0, 'hash');
        $anchor3 = $this->generator->generate(2, ['Test'], 0, 'hash');

        $this->assertTrue($this->generator->equals($anchor1, $anchor2));
        $this->assertFalse($this->generator->equals($anchor1, $anchor3));
    }

    public function testGenerateReadableAnchor(): void
    {
        $anchor = $this->generator->generateReadableAnchor(['Introduction', 'Getting Started']);
        $this->assertStringStartsWith('kb-', $anchor);
        $this->assertStringContainsString('getting-started', $anchor);
    }

    public function testGenerateReadableAnchorEmptyPath(): void
    {
        $anchor = $this->generator->generateReadableAnchor([]);
        $this->assertSame('kb-root', $anchor);
    }
}

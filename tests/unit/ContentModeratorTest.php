<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\KB\PIIDetector;

final class ContentModeratorTest extends TestCase
{
    private PIIDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new PIIDetector();
    }

    public function testDetectsEmailAddresses(): void
    {
        $result = $this->detector->scan('Contact john@example.com for details');

        self::assertTrue($result->hasPii);
        self::assertContains('email', $result->findings);
    }

    public function testDetectsPhoneNumbers(): void
    {
        $result = $this->detector->scan('Call 555-123-4567 now');

        self::assertTrue($result->hasPii);
        self::assertContains('phone', $result->findings);
    }

    public function testDetectsSSNs(): void
    {
        $result = $this->detector->scan('SSN: 123-45-6789');

        self::assertTrue($result->hasPii);
        self::assertContains('ssn', $result->findings);
    }

    public function testDetectsMultiplePiiTypes(): void
    {
        $result = $this->detector->scan('Email: a@b.com and Phone: 555-123-4567');

        self::assertTrue($result->hasPii);
        self::assertCount(2, $result->findings);
        self::assertContains('email', $result->findings);
        self::assertContains('phone', $result->findings);
    }

    public function testCleanContentPasses(): void
    {
        $result = $this->detector->scan('Hello World');

        self::assertFalse($result->hasPii);
        self::assertSame([], $result->findings);
    }

    public function testRedactReplacesPii(): void
    {
        $redacted = $this->detector->redact('Email: john@example.com');

        self::assertStringContainsString('[REDACTED:', $redacted);
        self::assertStringNotContainsString('john@example.com', $redacted);
    }

    public function testRedactPreservesNonPii(): void
    {
        $redacted = $this->detector->redact('Hello World');

        self::assertSame('Hello World', $redacted);
    }

    public function testEmptyStringPasses(): void
    {
        $result = $this->detector->scan('');

        self::assertFalse($result->hasPii);
    }

    public function testDetectsCreditCards(): void
    {
        $result = $this->detector->scan('Card: 4111-1111-1111-1111');

        self::assertTrue($result->hasPii);
        self::assertContains('credit_card', $result->findings);
    }

    public function testScanReturnsOriginalContent(): void
    {
        $content = 'Contact john@example.com';
        $result = $this->detector->scan($content);

        self::assertSame($content, $result->original);
    }
}

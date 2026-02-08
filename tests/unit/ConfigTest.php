<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Config;

final class ConfigTest extends TestCase
{
    public function testValidTypeRecognition(): void
    {
        self::assertTrue(Config::isValidType('PERSON'));
        self::assertTrue(Config::isValidType('person'));
        self::assertFalse(Config::isValidType('NOT_A_TYPE'));
    }

    public function testConfidenceTierMapping(): void
    {
        self::assertSame('high', Config::getConfidenceTier(0.9));
        self::assertSame('medium', Config::getConfidenceTier(0.7));
        self::assertSame('low', Config::getConfidenceTier(0.5));
        self::assertSame('reject', Config::getConfidenceTier(0.1));
    }
}

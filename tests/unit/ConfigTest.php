<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Config;

final class ConfigTest extends TestCase
{
    public function testValidTypeRecognition(): void
    {
        self::assertTrue(Config::isValidType('PERSON'));
        self::assertTrue(Config::isValidType('ORG'));
        self::assertTrue(Config::isValidType('COMPANY'));
        self::assertTrue(Config::isValidType('LOCATION'));
        self::assertTrue(Config::isValidType('COUNTRY'));
        self::assertTrue(Config::isValidType('PRODUCT'));
        self::assertTrue(Config::isValidType('SOFTWARE'));
        self::assertTrue(Config::isValidType('EVENT'));
        self::assertTrue(Config::isValidType('WORK'));
        self::assertTrue(Config::isValidType('CONCEPT'));
    }

    public function testValidTypeIsCaseInsensitive(): void
    {
        self::assertTrue(Config::isValidType('person'));
        self::assertTrue(Config::isValidType('Person'));
        self::assertTrue(Config::isValidType('PERSON'));
    }

    public function testInvalidTypeRejected(): void
    {
        self::assertFalse(Config::isValidType('NOT_A_TYPE'));
        self::assertFalse(Config::isValidType(''));
        self::assertFalse(Config::isValidType('FOO'));
    }

    public function testValidStatusRecognition(): void
    {
        self::assertTrue(Config::isValidStatus('raw'));
        self::assertTrue(Config::isValidStatus('reviewed'));
        self::assertTrue(Config::isValidStatus('canonical'));
        self::assertTrue(Config::isValidStatus('trash'));
        self::assertTrue(Config::isValidStatus('rejected'));
    }

    public function testValidStatusIsCaseInsensitive(): void
    {
        self::assertTrue(Config::isValidStatus('RAW'));
        self::assertTrue(Config::isValidStatus('Canonical'));
    }

    public function testInvalidStatusRejected(): void
    {
        self::assertFalse(Config::isValidStatus('pending'));
        self::assertFalse(Config::isValidStatus(''));
        self::assertFalse(Config::isValidStatus('unknown'));
    }

    public function testConfidenceTierMapping(): void
    {
        self::assertSame('high', Config::getConfidenceTier(0.9));
        self::assertSame('high', Config::getConfidenceTier(0.85));
        self::assertSame('high', Config::getConfidenceTier(1.0));
        self::assertSame('medium', Config::getConfidenceTier(0.7));
        self::assertSame('medium', Config::getConfidenceTier(0.60));
        self::assertSame('low', Config::getConfidenceTier(0.5));
        self::assertSame('low', Config::getConfidenceTier(0.40));
        self::assertSame('reject', Config::getConfidenceTier(0.39));
        self::assertSame('reject', Config::getConfidenceTier(0.1));
        self::assertSame('reject', Config::getConfidenceTier(0.0));
    }

    public function testGetSchemaTypeMapping(): void
    {
        self::assertSame('Person', Config::getSchemaType('PERSON'));
        self::assertSame('Organization', Config::getSchemaType('ORG'));
        self::assertSame('Corporation', Config::getSchemaType('COMPANY'));
        self::assertSame('Place', Config::getSchemaType('LOCATION'));
        self::assertSame('Country', Config::getSchemaType('COUNTRY'));
        self::assertSame('Product', Config::getSchemaType('PRODUCT'));
        self::assertSame('SoftwareApplication', Config::getSchemaType('SOFTWARE'));
        self::assertSame('Event', Config::getSchemaType('EVENT'));
        self::assertSame('CreativeWork', Config::getSchemaType('WORK'));
        self::assertSame('Thing', Config::getSchemaType('CONCEPT'));
    }

    public function testGetSchemaTypeUnknownReturnsThing(): void
    {
        self::assertSame('Thing', Config::getSchemaType('UNKNOWN'));
        self::assertSame('Thing', Config::getSchemaType(''));
    }

    public function testTableConstants(): void
    {
        self::assertSame('ai_entities', Config::TABLE_ENTITIES);
        self::assertSame('ai_mentions', Config::TABLE_MENTIONS);
        self::assertSame('ai_aliases', Config::TABLE_ALIASES);
    }

    public function testConfidenceThresholds(): void
    {
        self::assertEqualsWithDelta(0.85, Config::CONFIDENCE_HIGH, 0.001);
        self::assertEqualsWithDelta(0.60, Config::CONFIDENCE_MEDIUM, 0.001);
        self::assertEqualsWithDelta(0.40, Config::CONFIDENCE_LOW, 0.001);
    }

    public function testAllEntityTypesAreCovered(): void
    {
        $types = ['PERSON', 'ORG', 'COMPANY', 'LOCATION', 'COUNTRY', 'PRODUCT', 'SOFTWARE', 'EVENT', 'WORK', 'CONCEPT'];
        foreach ($types as $type) {
            self::assertTrue(Config::isValidType($type), "Type {$type} should be valid");
        }
        self::assertCount(10, $types);
    }
}

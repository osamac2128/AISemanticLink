<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\EntityExtractor;

/**
 * Tests for EntityExtractor validation edge cases.
 *
 * Tests the parse_ai_response() public method which internally calls
 * the private validate_entity() method, covering all validation rules.
 */
final class EntityExtractorValidationTest extends TestCase
{
    private EntityExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('VIBE_AI_OPENROUTER_KEY')) {
            define('VIBE_AI_OPENROUTER_KEY', 'test-key');
        }

        $this->extractor = new EntityExtractor();
    }

    // ─── Name Validation ─────────────────────────────────────────────

    public function testEntityWithMissingNameIsRejected(): void
    {
        $response = ['entities' => [
            ['type' => 'PERSON', 'confidence' => 0.9],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result);
    }

    public function testEntityWithEmptyNameIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => '', 'type' => 'PERSON', 'confidence' => 0.9],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result);
    }

    public function testEntityWithWhitespaceOnlyNameIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => '   ', 'type' => 'PERSON', 'confidence' => 0.9],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result);
    }

    public function testEntityWithNonStringNameIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => 123, 'type' => 'PERSON', 'confidence' => 0.9],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result);
    }

    // ─── Type Validation ─────────────────────────────────────────────

    public function testEntityWithMissingTypeIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => 'Test Entity', 'confidence' => 0.9],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result);
    }

    public function testEntityWithNonStringTypeIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => 'Test', 'type' => 42, 'confidence' => 0.9],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result);
    }

    public function testValidTypesAreAccepted(): void
    {
        $expectedTypes = [
            'PERSON', 'ORG', 'COMPANY', 'LOCATION', 'COUNTRY',
            'PRODUCT', 'SOFTWARE', 'EVENT', 'WORK', 'CONCEPT',
            'TECHNOLOGY', 'BRAND',
        ];

        foreach ($expectedTypes as $type) {
            $response = ['entities' => [
                ['name' => "Test {$type}", 'type' => $type, 'confidence' => 0.9],
            ]];

            $result = $this->extractor->parse_ai_response($response);
            self::assertCount(1, $result, "Type '{$type}' should produce a valid entity");
            self::assertSame($type, $result[0]['type']);
        }
    }

    public function testTECHNOLOGYAndBRANDTypesAreAccepted(): void
    {
        $response = ['entities' => [
            ['name' => 'React', 'type' => 'TECHNOLOGY', 'confidence' => 0.95],
            ['name' => 'Nike', 'type' => 'BRAND', 'confidence' => 0.90],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(2, $result);
        self::assertSame('TECHNOLOGY', $result[0]['type']);
        self::assertSame('BRAND', $result[1]['type']);
    }

    public function testUnknownTypeIsNormalizedToConcept(): void
    {
        $response = ['entities' => [
            ['name' => 'Test', 'type' => 'TOTALLY_UNKNOWN_TYPE', 'confidence' => 0.9],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame('CONCEPT', $result[0]['type']);
    }

    public function testTypeNormalizationMapsOrganisationToOrg(): void
    {
        $response = ['entities' => [
            ['name' => 'Test Corp', 'type' => 'ORGANISATION', 'confidence' => 0.8],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame('ORG', $result[0]['type']);
    }

    public function testTypeNormalizationMapsCorpToCompany(): void
    {
        $response = ['entities' => [
            ['name' => 'Test Inc', 'type' => 'CORP', 'confidence' => 0.8],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame('COMPANY', $result[0]['type']);
    }

    public function testTypeNormalizationMapsCityToLocation(): void
    {
        $response = ['entities' => [
            ['name' => 'New York', 'type' => 'CITY', 'confidence' => 0.85],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame('LOCATION', $result[0]['type']);
    }

    public function testTypeNormalizationIsCaseInsensitive(): void
    {
        $response = ['entities' => [
            ['name' => 'Test', 'type' => 'organization', 'confidence' => 0.8],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame('ORG', $result[0]['type']);
    }

    // ─── Confidence Validation ────────────────────────────────────────

    public function testEntityBelowConfidenceThresholdIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => 'Low Confidence', 'type' => 'PERSON', 'confidence' => 0.3],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result);
    }

    public function testEntityAtExactThresholdIsAccepted(): void
    {
        $threshold = $this->extractor->get_min_confidence_threshold();

        $response = ['entities' => [
            ['name' => 'Threshold Entity', 'type' => 'PERSON', 'confidence' => $threshold],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertEqualsWithDelta($threshold, $result[0]['confidence'], 0.001);
    }

    public function testConfidenceAboveOneIsClamped(): void
    {
        $response = ['entities' => [
            ['name' => 'Over Confident', 'type' => 'PERSON', 'confidence' => 1.5],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertEqualsWithDelta(1.0, $result[0]['confidence'], 0.001);
    }

    public function testNegativeConfidenceIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => 'Negative', 'type' => 'PERSON', 'confidence' => -0.5],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result, 'Negative confidence should be rejected (< 0.4 threshold)');
    }

    public function testNonNumericConfidenceDefaultsToZeroAndIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => 'BadConfidence', 'type' => 'PERSON', 'confidence' => 'not_a_number'],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result, 'Non-numeric confidence defaults to 0.0 which is below threshold');
    }

    public function testMissingConfidenceDefaultsToZeroAndIsRejected(): void
    {
        $response = ['entities' => [
            ['name' => 'No Confidence', 'type' => 'PERSON'],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result, 'Missing confidence defaults to 0.0 which is below threshold');
    }

    // ─── Non-Array Entity Rejection ───────────────────────────────────

    public function testNonArrayEntityIsRejected(): void
    {
        $response = ['entities' => [
            'not_an_array',
            42,
            null,
            true,
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(0, $result);
    }

    // ─── Response Format Validation ──────────────────────────────────

    public function testResponseMissingEntitiesKeyThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing "entities" key');

        $this->extractor->parse_ai_response(['data' => []]);
    }

    public function testResponseWithNonArrayEntitiesThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"entities" must be an array');

        $this->extractor->parse_ai_response(['entities' => 'not_array']);
    }

    public function testNonArrayResponseThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->extractor->parse_ai_response(42);
    }

    // ─── JSON String Response ─────────────────────────────────────────

    public function testJsonStringResponseIsParsed(): void
    {
        $json = json_encode(['entities' => [
            ['name' => 'Test', 'type' => 'PERSON', 'confidence' => 0.9],
        ]]);

        $result = $this->extractor->parse_ai_response($json);
        self::assertCount(1, $result);
        self::assertSame('Test', $result[0]['name']);
    }

    public function testInvalidJsonStringThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->extractor->parse_ai_response('not valid json at all');
    }

    // ─── Entity Limit ─────────────────────────────────────────────────

    public function testMaxEntitiesPerPostIs50(): void
    {
        $entities = [];
        for ($i = 0; $i < 60; $i++) {
            $entities[] = ['name' => "Entity {$i}", 'type' => 'PERSON', 'confidence' => 0.9];
        }

        $response = ['entities' => $entities];
        $result = $this->extractor->parse_ai_response($response);

        self::assertCount(50, $result, 'Should cap at MAX_ENTITIES_PER_POST (50)');
    }

    // ─── Aliases ──────────────────────────────────────────────────────

    public function testEntityWithValidAliases(): void
    {
        $response = ['entities' => [
            [
                'name' => 'Microsoft',
                'type' => 'COMPANY',
                'confidence' => 0.95,
                'aliases' => ['MSFT', 'Microsoft Corp'],
            ],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertContains('MSFT', $result[0]['aliases']);
        self::assertContains('Microsoft Corp', $result[0]['aliases']);
    }

    public function testEntityWithEmptyAliasesArrayGetsEmptyArray(): void
    {
        $response = ['entities' => [
            ['name' => 'Test', 'type' => 'PERSON', 'confidence' => 0.9, 'aliases' => []],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame([], $result[0]['aliases']);
    }

    public function testEntityWithNonStringAliasesFiltersThem(): void
    {
        $response = ['entities' => [
            [
                'name' => 'Test',
                'type' => 'PERSON',
                'confidence' => 0.9,
                'aliases' => ['Valid Alias', 123, null, '', '  '],
            ],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame(['Valid Alias'], $result[0]['aliases']);
    }

    public function testEntityWithNonArrayAliasesDefaultsToEmptyArray(): void
    {
        $response = ['entities' => [
            ['name' => 'Test', 'type' => 'PERSON', 'confidence' => 0.9, 'aliases' => 'not_array'],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame([], $result[0]['aliases']);
    }

    public function testDuplicateAliasesAreDeduplicated(): void
    {
        $response = ['entities' => [
            [
                'name' => 'Test',
                'type' => 'PERSON',
                'confidence' => 0.9,
                'aliases' => ['Alias A', 'Alias A', 'Alias B'],
            ],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame(['Alias A', 'Alias B'], $result[0]['aliases']);
    }

    // ─── Fully Valid Entity ───────────────────────────────────────────

    public function testFullyValidEntityPassesAllChecks(): void
    {
        $response = ['entities' => [
            [
                'name' => 'Albert Einstein',
                'type' => 'PERSON',
                'confidence' => 0.95,
                'aliases' => ['Einstein'],
            ],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);

        $entity = $result[0];
        self::assertSame('Albert Einstein', $entity['name']);
        self::assertSame('PERSON', $entity['type']);
        self::assertEqualsWithDelta(0.95, $entity['confidence'], 0.001);
        self::assertContains('Einstein', $entity['aliases']);
    }

    // ─── Context Validation ──────────────────────────────────────────

    public function testEntityWithNonStringContextDefaultsToEmpty(): void
    {
        $response = ['entities' => [
            ['name' => 'Test', 'type' => 'PERSON', 'confidence' => 0.9, 'context' => 12345],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame('', $result[0]['context']);
    }

    public function testEntityWithMissingContextDefaultsToEmpty(): void
    {
        $response = ['entities' => [
            ['name' => 'Test', 'type' => 'PERSON', 'confidence' => 0.9],
        ]];

        $result = $this->extractor->parse_ai_response($response);
        self::assertCount(1, $result);
        self::assertSame('', $result[0]['context']);
    }

    // ─── Allowed Types Public API ────────────────────────────────────

    public function testGetAllowedTypesReturnsExpectedCount(): void
    {
        $types = $this->extractor->get_allowed_types();
        self::assertCount(12, $types);
    }

    public function testGetAllowedTypesIncludesTechnologyAndBrand(): void
    {
        $types = $this->extractor->get_allowed_types();
        self::assertContains('TECHNOLOGY', $types);
        self::assertContains('BRAND', $types);
    }

    public function testMinConfidenceThresholdIsPointFour(): void
    {
        self::assertEqualsWithDelta(0.4, $this->extractor->get_min_confidence_threshold(), 0.001);
    }
}

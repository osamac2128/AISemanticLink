<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Config;
use Vibe\AIIndex\Services\ModelRouter;

/**
 * Tests for KBPipelineManager phase configuration and model routing.
 *
 * KBPipelineManager is WP-dependent (singleton, repositories, Action Scheduler).
 * These tests verify the KB pipeline phase structure, model routing, and
 * configuration constants that drive the KB pipeline.
 */
final class KBPipelineManagerTest extends TestCase
{
    public function testKBPipelineHasFivePhases(): void
    {
        self::assertCount(5, Config::KB_PHASES);
    }

    public function testExpectedKBPhaseOrder(): void
    {
        $phases = Config::KB_PHASES;

        self::assertSame('kb_document_build', $phases[0]);
        self::assertSame('kb_chunk_build', $phases[1]);
        self::assertSame('kb_embed_chunks', $phases[2]);
        self::assertSame('kb_index_upsert', $phases[3]);
        self::assertSame('kb_cleanup', $phases[4]);
    }

    public function testNoDuplicateKBPhaseNames(): void
    {
        $phases = Config::KB_PHASES;
        $unique = array_unique($phases);

        self::assertCount(count($phases), $unique, 'KB pipeline phases must not contain duplicates');
    }

    public function testKBPhaseNamesAreSnakeCase(): void
    {
        foreach (Config::KB_PHASES as $phase) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $phase,
                "KB phase name '{$phase}' must be valid snake_case"
            );
        }
    }

    public function testKBPhaseNamesArePrefixed(): void
    {
        foreach (Config::KB_PHASES as $phase) {
            self::assertStringStartsWith(
                'kb_',
                $phase,
                "KB phase '{$phase}' must be prefixed with 'kb_'"
            );
        }
    }

    public function testKBPhasesDoNotOverlapWithEntityPipelinePhases(): void
    {
        $entityPhases = array_keys(Config::PIPELINE_PHASES);
        $kbPhases = Config::KB_PHASES;
        $overlap = array_intersect($entityPhases, $kbPhases);

        self::assertEmpty($overlap, 'Entity pipeline and KB pipeline phases must not overlap');
    }

    public function testHighVolumeKBPhasesUseBudgetModel(): void
    {
        $budgetPhases = ['kb_embed_chunks', 'kb_chunk_build'];

        foreach ($budgetPhases as $phase) {
            self::assertSame(
                Config::BUDGET_MODEL,
                ModelRouter::select($phase),
                "KB phase '{$phase}' should use BUDGET_MODEL"
            );
        }
    }

    public function testOtherKBPhasesUseDefaultModel(): void
    {
        $defaultPhases = ['kb_document_build', 'kb_index_upsert', 'kb_cleanup'];

        foreach ($defaultPhases as $phase) {
            self::assertSame(
                Config::DEFAULT_MODEL,
                ModelRouter::select($phase),
                "KB phase '{$phase}' should use DEFAULT_MODEL"
            );
        }
    }

    public function testAllKBPhasesHaveValidModelRouting(): void
    {
        foreach (Config::KB_PHASES as $phase) {
            $model = ModelRouter::select($phase);

            self::assertNotEmpty($model, "KB phase '{$phase}' must have model routing");
            self::assertTrue(
                $model === Config::DEFAULT_MODEL || $model === Config::BUDGET_MODEL,
                "KB phase '{$phase}' must route to DEFAULT_MODEL or BUDGET_MODEL"
            );
        }
    }

    public function testKBDocumentBuildIsFirstPhase(): void
    {
        self::assertSame('kb_document_build', Config::KB_PHASES[0]);
    }

    public function testKBCleanupIsLastPhase(): void
    {
        $lastIndex = count(Config::KB_PHASES) - 1;
        self::assertSame('kb_cleanup', Config::KB_PHASES[$lastIndex]);
    }

    public function testKBTableConstants(): void
    {
        self::assertSame('ai_kb_docs', Config::TABLE_KB_DOCS);
        self::assertSame('ai_kb_chunks', Config::TABLE_KB_CHUNKS);
        self::assertSame('ai_kb_vectors', Config::TABLE_KB_VECTORS);
    }

    public function testKBStatusConstants(): void
    {
        self::assertSame('pending', Config::KB_STATUS_PENDING);
        self::assertSame('chunked', Config::KB_STATUS_CHUNKED);
        self::assertSame('indexed', Config::KB_STATUS_INDEXED);
        self::assertSame('error', Config::KB_STATUS_ERROR);
        self::assertSame('excluded', Config::KB_STATUS_EXCLUDED);
    }

    public function testKBChunkTokenTargetIsValid(): void
    {
        self::assertGreaterThan(0, Config::KB_CHUNK_TOKENS_TARGET);
        self::assertGreaterThan(Config::KB_MIN_CHUNK_TOKENS, Config::KB_CHUNK_TOKENS_TARGET);
        self::assertLessThan(Config::KB_MAX_CHUNK_TOKENS, Config::KB_CHUNK_TOKENS_TARGET);
    }

    public function testKBOverlapIsLessThanChunkTarget(): void
    {
        self::assertLessThan(
            Config::KB_CHUNK_TOKENS_TARGET,
            Config::KB_CHUNK_OVERLAP_TOKENS,
            'Chunk overlap must be less than the chunk token target'
        );
    }
}

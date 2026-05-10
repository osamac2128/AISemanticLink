<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Config;
use Vibe\AIIndex\Services\ModelRouter;

/**
 * Unit + integration tests for ModelRouter.
 *
 * Covers phase → model mapping for all phases (entity + KB),
 * cross-pipeline coverage, model sanity checks, and edge cases.
 */
final class ModelRouterTest extends TestCase
{
    // ─── Entity Pipeline: Budget Model Phases ──────────────────────────

    /**
     * Extraction is a high-volume operation → budget model.
     */
    public function testExtractionReturnsBudgetModel(): void
    {
        self::assertSame(
            Config::BUDGET_MODEL,
            ModelRouter::select('extraction'),
            'extraction should route to BUDGET_MODEL'
        );
    }

    /**
     * Deduplication is a high-volume operation → budget model.
     */
    public function testDeduplicationReturnsBudgetModel(): void
    {
        self::assertSame(
            Config::BUDGET_MODEL,
            ModelRouter::select('deduplication'),
            'deduplication should route to BUDGET_MODEL'
        );
    }

    /**
     * Indexing is a high-volume operation → budget model.
     */
    public function testIndexingReturnsBudgetModel(): void
    {
        self::assertSame(
            Config::BUDGET_MODEL,
            ModelRouter::select('indexing'),
            'indexing should route to BUDGET_MODEL'
        );
    }

    // ─── Entity Pipeline: Default Model Phases ─────────────────────────

    /**
     * Schema build is a high-stakes operation → premium (default) model.
     */
    public function testSchemaBuildReturnsDefaultModel(): void
    {
        self::assertSame(
            Config::DEFAULT_MODEL,
            ModelRouter::select('schema_build'),
            'schema_build should route to DEFAULT_MODEL'
        );
    }

    /**
     * Linking is a high-stakes operation → premium (default) model.
     */
    public function testLinkingReturnsDefaultModel(): void
    {
        self::assertSame(
            Config::DEFAULT_MODEL,
            ModelRouter::select('linking'),
            'linking should route to DEFAULT_MODEL'
        );
    }

    /**
     * Preparation phase routes to default (premium) model.
     */
    public function testPreparationReturnsDefaultModel(): void
    {
        self::assertSame(
            Config::DEFAULT_MODEL,
            ModelRouter::select('preparation'),
            'preparation should route to DEFAULT_MODEL'
        );
    }

    // ─── KB Pipeline: Budget Model Phases ──────────────────────────────

    /**
     * KB chunk embedding routes to budget model.
     */
    public function testKBEmbedChunksReturnsBudgetModel(): void
    {
        self::assertSame(
            Config::BUDGET_MODEL,
            ModelRouter::select('kb_embed_chunks'),
            'kb_embed_chunks should route to BUDGET_MODEL'
        );
    }

    /**
     * KB chunk build routes to budget model.
     */
    public function testKBChunkBuildReturnsBudgetModel(): void
    {
        self::assertSame(
            Config::BUDGET_MODEL,
            ModelRouter::select('kb_chunk_build'),
            'kb_chunk_build should route to BUDGET_MODEL'
        );
    }

    // ─── KB Pipeline: Default Model Phases ─────────────────────────────

    public function testKBDocumentBuildRoutesToDefaultModel(): void
    {
        self::assertSame(Config::DEFAULT_MODEL, ModelRouter::select('kb_document_build'));
    }

    public function testKBIndexUpsertRoutesToDefaultModel(): void
    {
        self::assertSame(Config::DEFAULT_MODEL, ModelRouter::select('kb_index_upsert'));
    }

    public function testKBCleanupRoutesToDefaultModel(): void
    {
        self::assertSame(Config::DEFAULT_MODEL, ModelRouter::select('kb_cleanup'));
    }

    // ─── Cross-Pipeline Coverage ───────────────────────────────────────

    /**
     * All PIPELINE_PHASES must have a valid (non-empty) model routing.
     */
    public function testAllEntityPipelinePhasesHaveValidRouting(): void
    {
        foreach (Config::PIPELINE_PHASES as $phase => $description) {
            $model = ModelRouter::select($phase);
            self::assertNotEmpty($model, "Phase '{$phase}' must route to a non-empty model");
            self::assertTrue(
                $model === Config::DEFAULT_MODEL || $model === Config::BUDGET_MODEL,
                "Phase '{$phase}' must route to either DEFAULT_MODEL or BUDGET_MODEL, got: {$model}"
            );
        }
    }

    public function testAllEntityPipelinePhasesUseBothModelTiers(): void
    {
        $routedModels = [];

        foreach (Config::PIPELINE_PHASES as $phase => $description) {
            $model = ModelRouter::select($phase);

            self::assertNotEmpty($model, "Phase '{$phase}' must have a model");
            self::assertContains(
                $model,
                [Config::DEFAULT_MODEL, Config::BUDGET_MODEL],
                "Phase '{$phase}' must route to a known model, got: {$model}"
            );

            $routedModels[$phase] = $model;
        }

        // Verify that at least one phase uses each model tier
        $usedModels = array_unique($routedModels);
        self::assertCount(2, $usedModels, 'Pipeline must use both DEFAULT_MODEL and BUDGET_MODEL');
    }

    public function testAllKBPhasesHaveValidModels(): void
    {
        foreach (Config::KB_PHASES as $phase) {
            $model = ModelRouter::select($phase);

            self::assertNotEmpty($model, "KB phase '{$phase}' must have a model");
            self::assertContains(
                $model,
                [Config::DEFAULT_MODEL, Config::BUDGET_MODEL],
                "KB phase '{$phase}' must route to a known model, got: {$model}"
            );
        }
    }

    public function testAllPhasesAcrossBothPipelinesHaveModels(): void
    {
        $allPhases = array_merge(
            array_keys(Config::PIPELINE_PHASES),
            Config::KB_PHASES
        );

        foreach ($allPhases as $phase) {
            $model = ModelRouter::select($phase);

            self::assertNotEmpty($model, "Phase '{$phase}' must have a model routing");
            self::assertContains(
                $model,
                [Config::DEFAULT_MODEL, Config::BUDGET_MODEL],
                "Phase '{$phase}' must route to a valid model"
            );
        }

        self::assertCount(11, $allPhases, '6 entity phases + 5 KB phases = 11 total');
    }

    public function testBudgetOperationsMatchConfiguredPhases(): void
    {
        $expectedBudget = ['extraction', 'deduplication', 'indexing', 'kb_embed_chunks', 'kb_chunk_build'];

        foreach ($expectedBudget as $phase) {
            self::assertSame(
                Config::BUDGET_MODEL,
                ModelRouter::select($phase),
                "Phase '{$phase}' should be a budget operation"
            );
        }

        self::assertCount(5, $expectedBudget, 'Should have exactly 5 budget operations');
    }

    public function testNonBudgetOperationsUseDefaultModel(): void
    {
        $expectedDefault = ['preparation', 'linking', 'schema_build', 'kb_document_build', 'kb_index_upsert', 'kb_cleanup'];

        foreach ($expectedDefault as $phase) {
            self::assertSame(
                Config::DEFAULT_MODEL,
                ModelRouter::select($phase),
                "Phase '{$phase}' should use the default model"
            );
        }

        self::assertCount(6, $expectedDefault, 'Should have exactly 6 default model operations');
    }

    // ─── Model Configuration Sanity ────────────────────────────────────

    public function testDefaultAndBudgetModelsAreDifferent(): void
    {
        self::assertNotSame(
            Config::DEFAULT_MODEL,
            Config::BUDGET_MODEL,
            'DEFAULT_MODEL and BUDGET_MODEL must be different models'
        );
    }

    public function testDefaultModelIsNotEmpty(): void
    {
        self::assertNotEmpty(Config::DEFAULT_MODEL);
        self::assertIsString(Config::DEFAULT_MODEL);
    }

    public function testBudgetModelIsNotEmpty(): void
    {
        self::assertNotEmpty(Config::BUDGET_MODEL);
        self::assertIsString(Config::BUDGET_MODEL);
    }

    public function testFallbackModelIsNotEmpty(): void
    {
        self::assertNotEmpty(Config::FALLBACK_MODEL);
        self::assertIsString(Config::FALLBACK_MODEL);
    }

    public function testFallbackModelDiffersFromDefaultAndBudget(): void
    {
        self::assertNotSame(Config::DEFAULT_MODEL, Config::FALLBACK_MODEL);
        self::assertNotSame(Config::BUDGET_MODEL, Config::FALLBACK_MODEL);
    }

    // ─── Edge Cases: Unknown / Empty Phase ─────────────────────────────

    /**
     * Unknown operations fall back to the premium (default) model.
     */
    public function testUnknownOperationReturnsDefaultModel(): void
    {
        self::assertSame(
            Config::DEFAULT_MODEL,
            ModelRouter::select('nonexistent_operation'),
            'Unknown operations should fall back to DEFAULT_MODEL'
        );
    }

    public function testUnknownPhaseFallsBackToDefaultModel(): void
    {
        self::assertSame(
            Config::DEFAULT_MODEL,
            ModelRouter::select('totally_unknown_phase'),
            'Unknown phases must fall back to DEFAULT_MODEL'
        );
    }

    public function testEmptyStringPhaseFallsBackToDefaultModel(): void
    {
        self::assertSame(
            Config::DEFAULT_MODEL,
            ModelRouter::select(''),
            'Empty string phase must fall back to DEFAULT_MODEL'
        );
    }
}

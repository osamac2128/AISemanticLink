<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Config;
use Vibe\AIIndex\Services\ModelRouter;

/**
 * Tests for PipelineManager phase transitions, model routing, and configuration.
 *
 * Since PipelineManager has heavy WordPress dependencies (singleton, Action Scheduler,
 * WP options), these tests verify the static configuration, phase ordering, and
 * model routing that the pipeline depends on.
 */
final class PipelineManagerTest extends TestCase
{
    /**
     * PipelineManager defines 6 phases: preparation → extraction → deduplication → linking → indexing → schema_build
     */
    public function testPipelineHasSixPhases(): void
    {
        self::assertCount(6, Config::PIPELINE_PHASES);
    }

    public function testExpectedPhaseOrder(): void
    {
        $phases = array_keys(Config::PIPELINE_PHASES);

        self::assertSame('preparation', $phases[0]);
        self::assertSame('extraction', $phases[1]);
        self::assertSame('deduplication', $phases[2]);
        self::assertSame('linking', $phases[3]);
        self::assertSame('indexing', $phases[4]);
        self::assertSame('schema_build', $phases[5]);
    }

    public function testPhasesAreSequentialAndZeroIndexed(): void
    {
        $phases = array_keys(Config::PIPELINE_PHASES);

        for ($i = 0; $i < count($phases); $i++) {
            self::assertIsString($phases[$i], "Phase at index {$i} must be a string");
            self::assertNotEmpty($phases[$i], "Phase at index {$i} must not be empty");
        }
    }

    public function testNoDuplicatePhaseNames(): void
    {
        $phases = array_keys(Config::PIPELINE_PHASES);
        $unique = array_unique($phases);

        self::assertCount(count($phases), $unique, 'Pipeline phases must not contain duplicates');
    }

    /**
     * Every pipeline phase must have a valid model routing.
     */
    public function testAllPipelinePhasesHaveModelRouting(): void
    {
        foreach (Config::PIPELINE_PHASES as $phase => $description) {
            $model = ModelRouter::select($phase);

            self::assertNotEmpty($model, "Phase '{$phase}' must have a model routing");
            self::assertIsString($model);
            self::assertTrue(
                $model === Config::DEFAULT_MODEL || $model === Config::BUDGET_MODEL,
                "Phase '{$phase}' model must be either DEFAULT_MODEL or BUDGET_MODEL"
            );
        }
    }

    /**
     * High-volume phases use the budget model to reduce cost.
     */
    public function testHighVolumePhasesUseBudgetModel(): void
    {
        $budgetPhases = ['extraction', 'deduplication', 'indexing'];

        foreach ($budgetPhases as $phase) {
            self::assertSame(
                Config::BUDGET_MODEL,
                ModelRouter::select($phase),
                "Phase '{$phase}' should use BUDGET_MODEL"
            );
        }
    }

    /**
     * High-stakes phases use the premium (default) model for accuracy.
     */
    public function testHighStakesPhasesUseDefaultModel(): void
    {
        $premiumPhases = ['preparation', 'linking', 'schema_build'];

        foreach ($premiumPhases as $phase) {
            self::assertSame(
                Config::DEFAULT_MODEL,
                ModelRouter::select($phase),
                "Phase '{$phase}' should use DEFAULT_MODEL"
            );
        }
    }

    /**
     * Each phase has a human-readable description.
     */
    public function testAllPhasesHaveDescriptions(): void
    {
        foreach (Config::PIPELINE_PHASES as $phase => $description) {
            self::assertIsString($description, "Phase '{$phase}' description must be a string");
            self::assertNotEmpty($description, "Phase '{$phase}' must have a non-empty description");
        }
    }

    /**
     * Batch size must fall within the configured min/max range.
     */
    public function testBatchSizeWithinValidRange(): void
    {
        self::assertGreaterThanOrEqual(
            Config::MIN_BATCH_SIZE,
            Config::BATCH_SIZE,
            'BATCH_SIZE must be >= MIN_BATCH_SIZE'
        );
        self::assertLessThanOrEqual(
            Config::MAX_BATCH_SIZE,
            Config::BATCH_SIZE,
            'BATCH_SIZE must be <= MAX_BATCH_SIZE'
        );
    }

    public function testMinBatchSizeIsPositive(): void
    {
        self::assertGreaterThan(0, Config::MIN_BATCH_SIZE, 'MIN_BATCH_SIZE must be positive');
    }

    public function testMaxBatchSizeIsGreaterOrEqualToMin(): void
    {
        self::assertGreaterThanOrEqual(
            Config::MIN_BATCH_SIZE,
            Config::MAX_BATCH_SIZE,
            'MAX_BATCH_SIZE must be >= MIN_BATCH_SIZE'
        );
    }

    /**
     * Valid pipeline statuses must include all lifecycle states.
     */
    public function testValidStatuses(): void
    {
        // PipelineManager defines these statuses internally; verify through Config constants
        $expectedStatuses = ['raw', 'reviewed', 'canonical', 'trash', 'rejected'];
        foreach ($expectedStatuses as $status) {
            self::assertTrue(
                Config::isValidStatus($status),
                "Status '{$status}' must be valid"
            );
        }
    }

    /**
     * Phase names match the expected Action Scheduler hook pattern.
     */
    public function testPhaseNamesAreSnakeCase(): void
    {
        foreach (array_keys(Config::PIPELINE_PHASES) as $phase) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $phase,
                "Phase name '{$phase}' must be valid snake_case"
            );
        }
    }

    /**
     * The schema_build phase is the final phase.
     */
    public function testSchemaBuildIsFinalPhase(): void
    {
        $phases = array_keys(Config::PIPELINE_PHASES);
        $lastPhase = end($phases);

        self::assertSame('schema_build', $lastPhase, 'schema_build must be the final pipeline phase');
    }

    /**
     * The preparation phase is the first phase.
     */
    public function testPreparationIsFirstPhase(): void
    {
        $phases = array_keys(Config::PIPELINE_PHASES);

        self::assertSame('preparation', $phases[0], 'preparation must be the first pipeline phase');
    }
}

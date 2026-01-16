<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

/**
 * Dynamic batch size manager for processing optimization.
 *
 * Manages batch sizes for entity extraction jobs, automatically
 * adjusting based on processing performance to optimize throughput
 * while avoiding timeouts.
 *
 * @package Vibe\AIIndex\Services
 */
class BatchSizeManager
{
    /**
     * Minimum batch size.
     */
    private const MIN_BATCH = 5;

    /**
     * Maximum batch size.
     */
    private const MAX_BATCH = 50;

    /**
     * Target processing time in seconds.
     */
    private const TARGET_TIME = 5.0;

    /**
     * Threshold for increasing batch size (seconds).
     */
    private const INCREASE_THRESHOLD = 2.0;

    /**
     * Threshold for decreasing batch size (seconds).
     */
    private const DECREASE_THRESHOLD = 10.0;

    /**
     * Batch size adjustment increment.
     */
    private const ADJUSTMENT_INCREMENT = 5;

    /**
     * Option name for storing batch size in WordPress.
     */
    private const OPTION_NAME = 'vibe_ai_batch_size';

    /**
     * Option name for storing processing time history.
     */
    private const HISTORY_OPTION_NAME = 'vibe_ai_processing_history';

    /**
     * Maximum number of historical samples to keep.
     */
    private const MAX_HISTORY_SAMPLES = 10;

    /**
     * The current batch size.
     */
    private int $current_size;

    /**
     * Constructor.
     *
     * @param int|null $initial_size Optional initial batch size. If null, loads from options.
     */
    public function __construct(?int $initial_size = null)
    {
        if ($initial_size !== null) {
            $this->current_size = $this->clamp_size($initial_size);
        } else {
            $this->current_size = $this->load_batch_size();
        }
    }

    /**
     * Calculate the next batch size based on processing time.
     *
     * @param float $avg_process_time Average time to process the current batch (seconds).
     * @param int   $current_size     The current batch size.
     *
     * @return int The recommended next batch size.
     */
    public function calculate_next_batch_size(float $avg_process_time, int $current_size): int
    {
        if ($avg_process_time < self::INCREASE_THRESHOLD) {
            // Fast processing: increase batch size
            return min(self::MAX_BATCH, $current_size + self::ADJUSTMENT_INCREMENT);
        } elseif ($avg_process_time > self::DECREASE_THRESHOLD) {
            // Slow processing: decrease batch size
            return max(self::MIN_BATCH, $current_size - self::ADJUSTMENT_INCREMENT);
        }

        // Processing time is within target range
        return $current_size;
    }

    /**
     * Get the current batch size.
     *
     * @return int The current batch size.
     */
    public function get_current_size(): int
    {
        return $this->current_size;
    }

    /**
     * Update the batch size based on processing results.
     *
     * @param float $processing_time The time taken to process the last batch (seconds).
     * @param int   $items_processed The number of items processed in the last batch.
     *
     * @return int The new batch size.
     */
    public function update_from_result(float $processing_time, int $items_processed): int
    {
        if ($items_processed <= 0) {
            return $this->current_size;
        }

        // Calculate average time per item
        $avg_time_per_item = $processing_time / $items_processed;

        // Record this sample
        $this->record_processing_sample($avg_time_per_item);

        // Get rolling average
        $rolling_avg = $this->get_rolling_average();

        // Calculate new size based on rolling average
        $new_size = $this->calculate_next_batch_size($rolling_avg * $this->current_size, $this->current_size);

        // Update and persist
        $this->current_size = $new_size;
        $this->save_batch_size();

        return $this->current_size;
    }

    /**
     * Record a processing time sample for rolling average.
     *
     * @param float $avg_time_per_item Average processing time per item.
     */
    private function record_processing_sample(float $avg_time_per_item): void
    {
        $history = get_option(self::HISTORY_OPTION_NAME, []);

        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'time_per_item' => $avg_time_per_item,
            'timestamp' => time(),
        ];

        // Keep only the most recent samples
        if (count($history) > self::MAX_HISTORY_SAMPLES) {
            $history = array_slice($history, -self::MAX_HISTORY_SAMPLES);
        }

        update_option(self::HISTORY_OPTION_NAME, $history, false);
    }

    /**
     * Get the rolling average time per item.
     *
     * @return float The rolling average time per item.
     */
    private function get_rolling_average(): float
    {
        $history = get_option(self::HISTORY_OPTION_NAME, []);

        if (!is_array($history) || empty($history)) {
            return self::TARGET_TIME / self::MIN_BATCH; // Default assumption
        }

        $sum = 0.0;
        $count = 0;

        foreach ($history as $sample) {
            if (isset($sample['time_per_item']) && is_numeric($sample['time_per_item'])) {
                $sum += (float) $sample['time_per_item'];
                $count++;
            }
        }

        if ($count === 0) {
            return self::TARGET_TIME / self::MIN_BATCH;
        }

        return $sum / $count;
    }

    /**
     * Load the batch size from WordPress options.
     *
     * @return int The stored batch size or default minimum.
     */
    private function load_batch_size(): int
    {
        $stored = get_option(self::OPTION_NAME, self::MIN_BATCH);

        return $this->clamp_size((int) $stored);
    }

    /**
     * Save the current batch size to WordPress options.
     */
    private function save_batch_size(): void
    {
        update_option(self::OPTION_NAME, $this->current_size, false);
    }

    /**
     * Clamp a size value to valid range.
     *
     * @param int $size The size to clamp.
     *
     * @return int The clamped size.
     */
    private function clamp_size(int $size): int
    {
        return max(self::MIN_BATCH, min(self::MAX_BATCH, $size));
    }

    /**
     * Reset batch size to minimum.
     *
     * Useful when encountering persistent errors or rate limits.
     *
     * @return int The reset batch size.
     */
    public function reset(): int
    {
        $this->current_size = self::MIN_BATCH;
        $this->save_batch_size();

        // Clear history
        delete_option(self::HISTORY_OPTION_NAME);

        return $this->current_size;
    }

    /**
     * Get the minimum batch size.
     *
     * @return int The minimum batch size.
     */
    public function get_min_batch(): int
    {
        return self::MIN_BATCH;
    }

    /**
     * Get the maximum batch size.
     *
     * @return int The maximum batch size.
     */
    public function get_max_batch(): int
    {
        return self::MAX_BATCH;
    }

    /**
     * Get the target processing time.
     *
     * @return float The target processing time in seconds.
     */
    public function get_target_time(): float
    {
        return self::TARGET_TIME;
    }

    /**
     * Calculate the optimal batch size for a given per-item processing time.
     *
     * @param float $time_per_item Estimated time to process one item (seconds).
     *
     * @return int The optimal batch size.
     */
    public function calculate_optimal_size(float $time_per_item): int
    {
        if ($time_per_item <= 0) {
            return self::MAX_BATCH;
        }

        $optimal = (int) floor(self::TARGET_TIME / $time_per_item);

        return $this->clamp_size($optimal);
    }

    /**
     * Get processing statistics.
     *
     * @return array<string, mixed> Statistics about processing performance.
     */
    public function get_statistics(): array
    {
        $history = get_option(self::HISTORY_OPTION_NAME, []);

        if (!is_array($history) || empty($history)) {
            return [
                'samples' => 0,
                'avg_time_per_item' => 0.0,
                'current_batch_size' => $this->current_size,
                'estimated_batch_time' => 0.0,
            ];
        }

        $rolling_avg = $this->get_rolling_average();

        return [
            'samples' => count($history),
            'avg_time_per_item' => round($rolling_avg, 4),
            'current_batch_size' => $this->current_size,
            'estimated_batch_time' => round($rolling_avg * $this->current_size, 2),
        ];
    }
}

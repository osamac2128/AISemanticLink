<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs;

use Vibe\AIIndex\Pipeline\PipelineManager;
use Vibe\AIIndex\Services\EntityExtractor;

/**
 * ExtractionJob: Phase 2 of the AI Entity extraction pipeline.
 *
 * Uses EntityExtractor to process batches of posts and stores raw entity records.
 * Implements dynamic batch sizing based on processing performance.
 *
 * @package Vibe\AIIndex\Jobs
 */
class ExtractionJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_phase_extraction';

    /**
     * Minimum batch size.
     */
    private const MIN_BATCH_SIZE = 5;

    /**
     * Maximum batch size.
     */
    private const MAX_BATCH_SIZE = 50;

    /**
     * Target processing time per batch in seconds.
     */
    private const TARGET_BATCH_TIME = 5.0;

    /**
     * Option key for storing extracted entities.
     */
    private const OPTION_EXTRACTED_ENTITIES = 'vibe_ai_extracted_entities';

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_extraction_batch_state';

    /**
     * Meta key for tracking extraction timestamp.
     */
    private const META_EXTRACTED = '_vibe_ai_extracted_at';

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 1);
    }

    /**
     * Execute the extraction phase.
     *
     * @param array $args Job arguments containing config.
     * @return void
     */
    public static function execute(array $args = []): void {
        $config = $args['config'] ?? [];
        $job = new self();

        try {
            $job->run($config);
        } catch (\Throwable $e) {
            $job->handle_error($e);
        }
    }

    /**
     * Run the extraction job.
     *
     * @param array $config Pipeline configuration.
     * @return void
     */
    public function run(array $config): void {
        $pipeline = PipelineManager::get_instance();

        // Get queued posts from preparation phase
        $queued_posts = PreparationJob::get_queued_posts();

        if (empty($queued_posts)) {
            $this->log('info', 'No posts queued for extraction');
            do_action('vibe_ai_phase_extraction_complete');
            return;
        }

        // Get batch state
        $batch_state = $this->get_batch_state();
        $current_offset = $batch_state['offset'] ?? 0;
        $current_batch_size = $batch_state['batch_size'] ?? ($config['batch_size'] ?? self::MIN_BATCH_SIZE);

        // Check if we've processed all posts
        if ($current_offset >= count($queued_posts)) {
            $this->log('info', 'Extraction phase complete - all posts processed');
            $this->clear_batch_state();
            do_action('vibe_ai_phase_extraction_complete');
            return;
        }

        // Get current batch
        $batch = array_slice($queued_posts, $current_offset, $current_batch_size);
        $batch_number = (int) floor($current_offset / $current_batch_size) + 1;
        $total_batches = (int) ceil(count($queued_posts) / $current_batch_size);

        $this->log('info', "Processing batch {$batch_number}/{$total_batches}", [
            'offset'     => $current_offset,
            'batch_size' => $current_batch_size,
            'posts'      => count($batch),
        ]);

        // Update progress
        $pipeline->update_progress([
            'current_batch' => $batch_number,
            'total_batches' => $total_batches,
            'phase'         => [
                'total'     => count($queued_posts),
                'completed' => $current_offset,
            ],
        ]);

        // Process the batch
        $start_time = microtime(true);
        $results = $this->process_batch($batch);
        $processing_time = microtime(true) - $start_time;

        // Store extracted entities
        $this->store_extracted_entities($results['entities']);

        // Update progress
        $pipeline->update_progress([
            'completed'        => $current_offset + count($batch),
            'avg_process_time' => $processing_time / count($batch),
            'phase'            => [
                'total'     => count($queued_posts),
                'completed' => $current_offset + count($batch),
                'failed'    => $results['failed'],
            ],
        ]);

        // Calculate next batch size based on performance
        $next_batch_size = $this->calculate_next_batch_size(
            $processing_time / count($batch),
            $current_batch_size
        );

        // Update batch state
        $this->update_batch_state([
            'offset'     => $current_offset + count($batch),
            'batch_size' => $next_batch_size,
        ]);

        // Fire entities extracted action
        do_action('vibe_ai_entities_extracted_batch', $results['entities'], $batch);

        // Schedule next batch if there are more posts
        if ($current_offset + count($batch) < count($queued_posts)) {
            $this->schedule_next_batch($config);
        } else {
            // All batches complete
            $this->log('info', 'Extraction phase complete', [
                'total_entities' => $this->count_extracted_entities(),
            ]);
            $this->clear_batch_state();
            do_action('vibe_ai_phase_extraction_complete');
        }
    }

    /**
     * Process a batch of posts for entity extraction.
     *
     * @param array $batch Batch of prepared posts.
     * @return array Results with 'entities' and 'failed' count.
     */
    private function process_batch(array $batch): array {
        $extractor = $this->get_extractor();
        $all_entities = [];
        $failed = 0;

        foreach ($batch as $post_data) {
            $post_id = $post_data['post_id'];

            try {
                // Extract entities using AI
                $entities = $extractor->extract($post_data['content'], $post_id);

                if (!empty($entities)) {
                    foreach ($entities as $entity) {
                        $entity['source_post_id'] = $post_id;
                        $all_entities[] = $entity;
                    }

                    $this->log('info', "Extracted " . count($entities) . " entities from post {$post_id}");

                    // Fire action for extracted entities
                    do_action('vibe_ai_entities_extracted', $post_id, $entities);
                }

                // Mark post as extracted
                update_post_meta($post_id, self::META_EXTRACTED, current_time('mysql', true));

            } catch (\Throwable $e) {
                $failed++;
                $this->log('error', "Failed to extract entities from post {$post_id}: " . $e->getMessage(), [
                    'post_id'   => $post_id,
                    'exception' => get_class($e),
                ]);

                // Check for rate limit errors
                if ($this->is_rate_limit_error($e)) {
                    $this->handle_rate_limit($e);
                    throw $e; // Re-throw to trigger Action Scheduler retry
                }
            }
        }

        return [
            'entities' => $all_entities,
            'failed'   => $failed,
        ];
    }

    /**
     * Get the entity extractor service.
     *
     * @return EntityExtractor
     */
    private function get_extractor(): EntityExtractor {
        // Check if service container exists
        if (function_exists('vibe_ai_get_service')) {
            return vibe_ai_get_service(EntityExtractor::class);
        }

        // Fall back to direct instantiation
        return new EntityExtractor();
    }

    /**
     * Store extracted entities for the deduplication phase.
     *
     * @param array $entities Extracted entities.
     * @return void
     */
    private function store_extracted_entities(array $entities): void {
        $existing = get_option(self::OPTION_EXTRACTED_ENTITIES, []);
        $updated = array_merge($existing, $entities);
        update_option(self::OPTION_EXTRACTED_ENTITIES, $updated, false);
    }

    /**
     * Get all extracted entities.
     *
     * @return array Extracted entities.
     */
    public static function get_extracted_entities(): array {
        return get_option(self::OPTION_EXTRACTED_ENTITIES, []);
    }

    /**
     * Clear extracted entities.
     *
     * @return void
     */
    public static function clear_extracted_entities(): void {
        delete_option(self::OPTION_EXTRACTED_ENTITIES);
    }

    /**
     * Count extracted entities.
     *
     * @return int Entity count.
     */
    private function count_extracted_entities(): int {
        $entities = get_option(self::OPTION_EXTRACTED_ENTITIES, []);
        return count($entities);
    }

    /**
     * Calculate the next batch size based on processing performance.
     *
     * @param float $avg_process_time Average time per post in seconds.
     * @param int   $current_size Current batch size.
     * @return int Next batch size.
     */
    private function calculate_next_batch_size(float $avg_process_time, int $current_size): int {
        if ($avg_process_time < 2.0) {
            // Fast processing: increase batch
            return min(self::MAX_BATCH_SIZE, $current_size + 5);
        } elseif ($avg_process_time > 10.0) {
            // Slow processing: decrease batch
            return max(self::MIN_BATCH_SIZE, $current_size - 5);
        }

        return $current_size;
    }

    /**
     * Get current batch state.
     *
     * @return array Batch state.
     */
    private function get_batch_state(): array {
        return get_option(self::OPTION_BATCH_STATE, [
            'offset'     => 0,
            'batch_size' => self::MIN_BATCH_SIZE,
        ]);
    }

    /**
     * Update batch state.
     *
     * @param array $state New state.
     * @return void
     */
    private function update_batch_state(array $state): void {
        $current = $this->get_batch_state();
        $updated = wp_parse_args($state, $current);
        update_option(self::OPTION_BATCH_STATE, $updated, false);
    }

    /**
     * Clear batch state.
     *
     * @return void
     */
    private function clear_batch_state(): void {
        delete_option(self::OPTION_BATCH_STATE);
    }

    /**
     * Schedule the next batch.
     *
     * @param array $config Pipeline configuration.
     * @return void
     */
    private function schedule_next_batch(array $config): void {
        as_schedule_single_action(
            time() + 1, // Small delay between batches
            self::HOOK,
            ['config' => $config],
            'vibe-ai-index'
        );

        $this->log('debug', 'Next extraction batch scheduled');
    }

    /**
     * Check if an exception is a rate limit error.
     *
     * @param \Throwable $e Exception.
     * @return bool True if rate limit error.
     */
    private function is_rate_limit_error(\Throwable $e): bool {
        $message = strtolower($e->getMessage());
        return strpos($message, 'rate limit') !== false
            || strpos($message, '429') !== false
            || strpos($message, 'too many requests') !== false;
    }

    /**
     * Handle rate limit error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handle_rate_limit(\Throwable $e): void {
        $this->log('warning', 'Rate limit hit, backing off', [
            'message' => $e->getMessage(),
        ]);

        // Action Scheduler will handle retry with backoff
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handle_error(\Throwable $e): void {
        // Don't fail the whole pipeline on rate limits
        if ($this->is_rate_limit_error($e)) {
            $this->log('warning', 'Extraction paused due to rate limit', [
                'message' => $e->getMessage(),
            ]);
            return;
        }

        $this->log('error', 'Extraction phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        PipelineManager::get_instance()->fail(
            'Extraction phase failed: ' . $e->getMessage(),
            ['exception' => get_class($e)]
        );
    }

    /**
     * Log a message.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context.
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void {
        if (function_exists('vibe_ai_log')) {
            vibe_ai_log($level, '[Extraction] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'extraction', $level, $message, $context);
    }
}

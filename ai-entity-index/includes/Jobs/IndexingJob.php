<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs;

use Vibe\AIIndex\Pipeline\PipelineManager;

/**
 * IndexingJob: Phase 5 of the AI Entity extraction pipeline.
 *
 * Updates entity mention counts and builds the reverse index
 * for efficient entity-to-post lookups.
 *
 * @package Vibe\AIIndex\Jobs
 */
class IndexingJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_phase_indexing';

    /**
     * Batch size for processing entities.
     */
    private const BATCH_SIZE = 100;

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_indexing_batch_state';

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 1);
    }

    /**
     * Execute the indexing phase.
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
     * Run the indexing job.
     *
     * @param array $config Pipeline configuration.
     * @return void
     */
    public function run(array $config): void {
        global $wpdb;

        $pipeline = PipelineManager::get_instance();
        $entities_table = $wpdb->prefix . 'ai_entities';
        $mentions_table = $wpdb->prefix . 'ai_mentions';

        $this->log('info', 'Indexing phase started');

        // Get batch state
        $batch_state = $this->get_batch_state();
        $last_entity_id = $batch_state['last_entity_id'] ?? 0;

        // Count total entities to process
        $total_entities = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$entities_table}");

        if ($total_entities === 0) {
            $this->log('info', 'No entities to index');
            do_action('vibe_ai_phase_indexing_complete');
            return;
        }

        // Count already processed
        $processed_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$entities_table} WHERE id <= %d",
            $last_entity_id
        ));

        // Update progress
        $pipeline->update_progress([
            'phase' => [
                'total'     => $total_entities,
                'completed' => $processed_count,
            ],
        ]);

        // Get next batch of entities
        $entities = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM {$entities_table}
             WHERE id > %d
             ORDER BY id ASC
             LIMIT %d",
            $last_entity_id,
            self::BATCH_SIZE
        ));

        if (empty($entities)) {
            $this->complete_phase();
            return;
        }

        $indexed_count = 0;
        $max_entity_id = $last_entity_id;

        foreach ($entities as $entity) {
            // Update mention count for this entity
            $this->update_entity_mention_count((int) $entity->id);

            // Track progress
            $indexed_count++;
            $max_entity_id = (int) $entity->id;

            // Update progress periodically
            if ($indexed_count % 20 === 0) {
                $pipeline->update_progress([
                    'phase' => [
                        'total'     => $total_entities,
                        'completed' => $processed_count + $indexed_count,
                    ],
                ]);
            }
        }

        // Update batch state
        $this->update_batch_state(['last_entity_id' => $max_entity_id]);

        $this->log('info', "Indexed {$indexed_count} entities", [
            'last_entity_id' => $max_entity_id,
        ]);

        // Check if more to process
        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$entities_table} WHERE id > %d",
            $max_entity_id
        ));

        if ((int) $remaining > 0) {
            $this->schedule_next_batch($config);
        } else {
            // Build additional indexes
            $this->build_reverse_index();
            $this->complete_phase();
        }
    }

    /**
     * Update mention count for a specific entity.
     *
     * @param int $entity_id Entity ID.
     * @return void
     */
    private function update_entity_mention_count(int $entity_id): void {
        global $wpdb;

        $entities_table = $wpdb->prefix . 'ai_entities';
        $mentions_table = $wpdb->prefix . 'ai_mentions';

        $wpdb->query($wpdb->prepare(
            "UPDATE {$entities_table}
             SET mention_count = (
                 SELECT COUNT(*) FROM {$mentions_table} WHERE entity_id = %d
             ),
             updated_at = %s
             WHERE id = %d",
            $entity_id,
            current_time('mysql', true),
            $entity_id
        ));
    }

    /**
     * Build the reverse index for efficient lookups.
     *
     * This optimizes queries like "find all posts mentioning entity X"
     * by ensuring proper index usage.
     *
     * @return void
     */
    private function build_reverse_index(): void {
        global $wpdb;

        $mentions_table = $wpdb->prefix . 'ai_mentions';

        $this->log('info', 'Building reverse index');

        // Verify indexes exist
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$mentions_table}");
        $index_names = array_column($indexes, 'Key_name');

        // Check for required indexes
        $required_indexes = ['idx_entity_post', 'idx_post_id', 'idx_confidence'];

        foreach ($required_indexes as $index) {
            if (!in_array($index, $index_names, true)) {
                $this->log('warning', "Missing index: {$index}");
            }
        }

        // Analyze table for query optimization
        $wpdb->query("ANALYZE TABLE {$mentions_table}");

        // Also analyze entities table
        $entities_table = $wpdb->prefix . 'ai_entities';
        $wpdb->query("ANALYZE TABLE {$entities_table}");

        $this->log('info', 'Reverse index build complete');
    }

    /**
     * Calculate entity statistics.
     *
     * @return array Statistics.
     */
    private function calculate_statistics(): array {
        global $wpdb;

        $entities_table = $wpdb->prefix . 'ai_entities';
        $mentions_table = $wpdb->prefix . 'ai_mentions';

        // Get type distribution
        $type_distribution = $wpdb->get_results(
            "SELECT type, COUNT(*) as count, SUM(mention_count) as total_mentions
             FROM {$entities_table}
             GROUP BY type
             ORDER BY count DESC"
        );

        // Get confidence distribution
        $confidence_stats = $wpdb->get_row(
            "SELECT
                AVG(confidence) as avg_confidence,
                MIN(confidence) as min_confidence,
                MAX(confidence) as max_confidence,
                COUNT(*) as total_mentions
             FROM {$mentions_table}"
        );

        // Get top entities by mention count
        $top_entities = $wpdb->get_results(
            "SELECT id, name, type, mention_count
             FROM {$entities_table}
             ORDER BY mention_count DESC
             LIMIT 10"
        );

        // Get posts with most entities
        $top_posts = $wpdb->get_results(
            "SELECT post_id, COUNT(*) as entity_count
             FROM {$mentions_table}
             GROUP BY post_id
             ORDER BY entity_count DESC
             LIMIT 10"
        );

        return [
            'type_distribution'  => $type_distribution,
            'confidence_stats'   => $confidence_stats,
            'top_entities'       => $top_entities,
            'top_posts'          => $top_posts,
        ];
    }

    /**
     * Get current batch state.
     *
     * @return array Batch state.
     */
    private function get_batch_state(): array {
        return get_option(self::OPTION_BATCH_STATE, ['last_entity_id' => 0]);
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
            time(),
            self::HOOK,
            ['config' => $config],
            'vibe-ai-index'
        );

        $this->log('debug', 'Next indexing batch scheduled');
    }

    /**
     * Complete the indexing phase.
     *
     * @return void
     */
    private function complete_phase(): void {
        $stats = $this->calculate_statistics();

        $this->log('info', 'Indexing phase complete', ['stats' => $stats]);

        // Store statistics for dashboard
        update_option('vibe_ai_index_stats', $stats, false);

        $this->clear_batch_state();

        // Clear data from previous phases
        DeduplicationJob::clear_canonical_entities();

        do_action('vibe_ai_phase_indexing_complete');
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handle_error(\Throwable $e): void {
        $this->log('error', 'Indexing phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        PipelineManager::get_instance()->fail(
            'Indexing phase failed: ' . $e->getMessage(),
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
            vibe_ai_log($level, '[Indexing] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'indexing', $level, $message, $context);
    }
}

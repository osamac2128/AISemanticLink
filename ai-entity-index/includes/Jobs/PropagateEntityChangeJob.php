<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs;

use Vibe\AIIndex\Services\SchemaGenerator;

/**
 * PropagateEntityChangeJob: Chain-Link invalidation job.
 *
 * When an entity is updated, this job propagates the changes to all
 * affected posts by regenerating their Schema.org JSON-LD cache.
 * Uses recursive self-scheduling to process in batches without timeouts.
 *
 * @package Vibe\AIIndex\Jobs
 */
class PropagateEntityChangeJob {

    /**
     * Action hook for this job.
     */
    public const JOB_HOOK = 'vibe_ai_propagate_entity';

    /**
     * Batch size for processing posts.
     */
    private const BATCH_SIZE = 50;

    /**
     * Transient prefix for tracking propagation status.
     */
    private const TRANSIENT_PREFIX = 'vibe_ai_propagating_';

    /**
     * Transient expiration time in seconds.
     */
    private const TRANSIENT_EXPIRATION = HOUR_IN_SECONDS;

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::JOB_HOOK, [self::class, 'handle'], 10, 2);
    }

    /**
     * Schedule a propagation job for an entity.
     *
     * @param int $entity_id Entity ID to propagate changes for.
     * @param int $last_post_id Last processed post ID (for pagination).
     * @return void
     */
    public static function schedule(int $entity_id, int $last_post_id = 0): void {
        // Set propagation flag transient
        set_transient(
            self::TRANSIENT_PREFIX . $entity_id,
            [
                'started_at'   => current_time('mysql', true),
                'last_post_id' => $last_post_id,
            ],
            self::TRANSIENT_EXPIRATION
        );

        // Schedule the job
        as_schedule_single_action(
            time(),
            self::JOB_HOOK,
            [
                'entity_id'    => $entity_id,
                'last_post_id' => $last_post_id,
            ],
            'vibe-ai-index'
        );

        self::log('info', "Propagation scheduled for entity {$entity_id}", [
            'last_post_id' => $last_post_id,
        ]);
    }

    /**
     * Handle the scheduled job.
     *
     * @param int $entity_id Entity ID.
     * @param int $last_post_id Last processed post ID.
     * @return void
     */
    public static function handle(int $entity_id, int $last_post_id): void {
        $job = new self();

        try {
            $job->execute($entity_id, $last_post_id);
        } catch (\Throwable $e) {
            $job->handle_error($entity_id, $e);
        }
    }

    /**
     * Execute the propagation job.
     *
     * @param int $entity_id Entity ID.
     * @param int $last_post_id Last processed post ID.
     * @return void
     */
    public function execute(int $entity_id, int $last_post_id): void {
        global $wpdb;

        $mentions_table = $wpdb->prefix . 'ai_mentions';

        // Fetch next batch of affected posts
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id
             FROM {$mentions_table}
             WHERE entity_id = %d AND post_id > %d
             ORDER BY post_id ASC
             LIMIT %d",
            $entity_id,
            $last_post_id,
            self::BATCH_SIZE
        ));

        if (empty($post_ids)) {
            // Done - clear propagation flag
            $this->complete_propagation($entity_id);
            return;
        }

        self::log('info', "Processing batch for entity {$entity_id}", [
            'post_count'   => count($post_ids),
            'last_post_id' => $last_post_id,
        ]);

        // Regenerate Schema for each post
        $schema_builder = $this->get_schema_builder();
        $regenerated = 0;
        $failed = 0;

        foreach ($post_ids as $post_id) {
            try {
                $schema_builder->regenerate((int) $post_id);
                $regenerated++;
            } catch (\Throwable $e) {
                $failed++;
                self::log('error', "Failed to regenerate schema for post {$post_id}", [
                    'entity_id' => $entity_id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        self::log('info', "Batch complete for entity {$entity_id}", [
            'regenerated' => $regenerated,
            'failed'      => $failed,
        ]);

        // Update transient with progress
        $max_post_id = max(array_map('intval', $post_ids));
        $this->update_propagation_status($entity_id, $max_post_id);

        // Schedule next batch (recursive self-scheduling)
        self::schedule($entity_id, $max_post_id);
    }

    /**
     * Complete the propagation for an entity.
     *
     * @param int $entity_id Entity ID.
     * @return void
     */
    private function complete_propagation(int $entity_id): void {
        // Clear propagation flag
        delete_transient(self::TRANSIENT_PREFIX . $entity_id);

        self::log('info', "Propagation complete for entity {$entity_id}");

        // Fire completion action
        do_action('vibe_ai_entity_propagation_complete', $entity_id);
    }

    /**
     * Update propagation status in transient.
     *
     * @param int $entity_id Entity ID.
     * @param int $last_post_id Last processed post ID.
     * @return void
     */
    private function update_propagation_status(int $entity_id, int $last_post_id): void {
        $current = get_transient(self::TRANSIENT_PREFIX . $entity_id);

        if (!is_array($current)) {
            $current = ['started_at' => current_time('mysql', true)];
        }

        $current['last_post_id'] = $last_post_id;
        $current['updated_at'] = current_time('mysql', true);

        set_transient(
            self::TRANSIENT_PREFIX . $entity_id,
            $current,
            self::TRANSIENT_EXPIRATION
        );
    }

    /**
     * Check if an entity is currently propagating.
     *
     * @param int $entity_id Entity ID.
     * @return bool True if propagating.
     */
    public static function is_propagating(int $entity_id): bool {
        return get_transient(self::TRANSIENT_PREFIX . $entity_id) !== false;
    }

    /**
     * Get propagation status for an entity.
     *
     * @param int $entity_id Entity ID.
     * @return array|null Status data or null if not propagating.
     */
    public static function get_propagation_status(int $entity_id): ?array {
        $status = get_transient(self::TRANSIENT_PREFIX . $entity_id);
        return is_array($status) ? $status : null;
    }

    /**
     * Cancel propagation for an entity.
     *
     * @param int $entity_id Entity ID.
     * @return void
     */
    public static function cancel(int $entity_id): void {
        // Clear transient
        delete_transient(self::TRANSIENT_PREFIX . $entity_id);

        // Unschedule pending jobs
        as_unschedule_all_actions(
            self::JOB_HOOK,
            ['entity_id' => $entity_id],
            'vibe-ai-index'
        );

        self::log('info', "Propagation cancelled for entity {$entity_id}");
    }

    /**
     * Get the count of posts remaining to propagate for an entity.
     *
     * @param int $entity_id Entity ID.
     * @param int $last_post_id Last processed post ID.
     * @return int Remaining post count.
     */
    public static function get_remaining_count(int $entity_id, int $last_post_id = 0): int {
        global $wpdb;

        $mentions_table = $wpdb->prefix . 'ai_mentions';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id)
             FROM {$mentions_table}
             WHERE entity_id = %d AND post_id > %d",
            $entity_id,
            $last_post_id
        ));
    }

    /**
     * Get the schema builder instance.
     *
     * @return SchemaBuildJob
     */
    private function get_schema_builder(): SchemaBuildJob {
        if (function_exists('vibe_ai_get_service')) {
            return vibe_ai_get_service(SchemaBuildJob::class);
        }

        return new SchemaBuildJob();
    }

    /**
     * Handle job execution error.
     *
     * @param int        $entity_id Entity ID.
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handle_error(int $entity_id, \Throwable $e): void {
        self::log('error', "Propagation failed for entity {$entity_id}: " . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        // Don't clear the transient - allow retry
        // Action Scheduler will handle retry logic
    }

    /**
     * Log a message.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context.
     * @return void
     */
    private static function log(string $level, string $message, array $context = []): void {
        if (function_exists('vibe_ai_log')) {
            vibe_ai_log($level, '[Propagation] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'propagation', $level, $message, $context);
    }
}

<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Pipeline;

use Vibe\AIIndex\Jobs\PreparationJob;
use Vibe\AIIndex\Jobs\ExtractionJob;
use Vibe\AIIndex\Jobs\DeduplicationJob;
use Vibe\AIIndex\Jobs\LinkingJob;
use Vibe\AIIndex\Jobs\IndexingJob;
use Vibe\AIIndex\Jobs\SchemaBuildJob;

/**
 * PipelineManager: Orchestrates the 6-phase AI Entity extraction pipeline.
 *
 * Manages pipeline state, progress tracking, and phase transitions.
 * Uses WordPress options for persistent state storage.
 *
 * @package Vibe\AIIndex\Pipeline
 */
class PipelineManager {

    /**
     * Pipeline status option key.
     */
    private const OPTION_STATUS = 'vibe_ai_pipeline_status';

    /**
     * Current phase option key.
     */
    private const OPTION_PHASE = 'vibe_ai_pipeline_phase';

    /**
     * Progress data option key.
     */
    private const OPTION_PROGRESS = 'vibe_ai_pipeline_progress';

    /**
     * Pipeline options/configuration option key.
     */
    private const OPTION_CONFIG = 'vibe_ai_pipeline_config';

    /**
     * Last activity timestamp option key.
     */
    private const OPTION_LAST_ACTIVITY = 'vibe_ai_pipeline_last_activity';

    /**
     * Pipeline phases in order.
     */
    private const PHASES = [
        1 => 'preparation',
        2 => 'extraction',
        3 => 'deduplication',
        4 => 'linking',
        5 => 'indexing',
        6 => 'schema_build',
    ];

    /**
     * Phase job class mappings.
     */
    private const PHASE_JOBS = [
        'preparation'    => PreparationJob::class,
        'extraction'     => ExtractionJob::class,
        'deduplication'  => DeduplicationJob::class,
        'linking'        => LinkingJob::class,
        'indexing'       => IndexingJob::class,
        'schema_build'   => SchemaBuildJob::class,
    ];

    /**
     * Valid pipeline statuses.
     */
    private const VALID_STATUSES = ['idle', 'running', 'paused', 'completed', 'failed'];

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct() {
        $this->register_hooks();
    }

    /**
     * Register WordPress hooks for phase completion.
     *
     * @return void
     */
    private function register_hooks(): void {
        // Listen for phase completion events
        add_action('vibe_ai_phase_preparation_complete', [$this, 'handle_phase_complete']);
        add_action('vibe_ai_phase_extraction_complete', [$this, 'handle_phase_complete']);
        add_action('vibe_ai_phase_deduplication_complete', [$this, 'handle_phase_complete']);
        add_action('vibe_ai_phase_linking_complete', [$this, 'handle_phase_complete']);
        add_action('vibe_ai_phase_indexing_complete', [$this, 'handle_phase_complete']);
        add_action('vibe_ai_phase_schema_build_complete', [$this, 'handle_phase_complete']);
    }

    /**
     * Start the pipeline from Phase 1.
     *
     * @param array $options Pipeline configuration options.
     *                       - post_types: array of post types to process (default: ['post', 'page'])
     *                       - batch_size: initial batch size (default: 5)
     *                       - force_reprocess: bool to reprocess already extracted posts (default: false)
     * @return void
     * @throws \RuntimeException If pipeline is already running.
     */
    public function start(array $options = []): void {
        if ($this->is_running()) {
            throw new \RuntimeException('Pipeline is already running. Stop it first before starting a new run.');
        }

        // Apply filter for post types
        $default_post_types = apply_filters('vibe_ai_post_types', ['post', 'page']);

        // Merge options with defaults
        $config = wp_parse_args($options, [
            'post_types'       => $default_post_types,
            'batch_size'       => 5,
            'force_reprocess'  => false,
            'started_at'       => current_time('mysql', true),
            'started_by'       => get_current_user_id(),
        ]);

        // Store configuration
        update_option(self::OPTION_CONFIG, $config, false);

        // Initialize progress tracking
        $this->reset_progress();

        // Set status to running and phase to preparation
        update_option(self::OPTION_STATUS, 'running', false);
        update_option(self::OPTION_PHASE, 'preparation', false);
        $this->update_last_activity();

        // Log pipeline start
        $this->log('info', 'Pipeline started', [
            'config' => $config,
        ]);

        // Fire pipeline started action
        do_action('vibe_ai_pipeline_started', $config);

        // Schedule the first phase
        $this->schedule_current_phase();
    }

    /**
     * Stop the pipeline and cancel all pending jobs.
     *
     * @return void
     */
    public function stop(): void {
        // Cancel all scheduled pipeline jobs
        foreach (self::PHASE_JOBS as $phase => $job_class) {
            $hook = "vibe_ai_phase_{$phase}";
            as_unschedule_all_actions($hook);
        }

        // Update status
        $previous_status = get_option(self::OPTION_STATUS, 'idle');
        $previous_phase = get_option(self::OPTION_PHASE, '');

        update_option(self::OPTION_STATUS, 'idle', false);
        $this->update_last_activity();

        // Log pipeline stop
        $this->log('info', 'Pipeline stopped', [
            'previous_status' => $previous_status,
            'previous_phase'  => $previous_phase,
        ]);

        // Fire pipeline stopped action
        do_action('vibe_ai_pipeline_stopped', $previous_phase);
    }

    /**
     * Get the current pipeline status.
     *
     * @return array Pipeline status information.
     */
    public function get_status(): array {
        $status = get_option(self::OPTION_STATUS, 'idle');
        $phase = get_option(self::OPTION_PHASE, '');
        $progress = $this->get_progress();
        $config = get_option(self::OPTION_CONFIG, []);
        $last_activity = get_option(self::OPTION_LAST_ACTIVITY, '');

        // Get stats from database
        $stats = $this->get_pipeline_stats();

        // Get currently propagating entities
        $propagating_entities = $this->get_propagating_entities();

        return [
            'status'               => $status,
            'current_phase'        => $phase,
            'phase_number'         => $this->get_phase_number($phase),
            'total_phases'         => count(self::PHASES),
            'progress'             => $progress,
            'stats'                => $stats,
            'config'               => $config,
            'last_activity'        => $last_activity,
            'propagating_entities' => $propagating_entities,
        ];
    }

    /**
     * Advance to the next phase.
     *
     * @return void
     */
    public function advance_phase(): void {
        $current_phase = get_option(self::OPTION_PHASE, '');
        $current_number = $this->get_phase_number($current_phase);

        if ($current_number >= count(self::PHASES)) {
            // Pipeline complete
            $this->complete_pipeline();
            return;
        }

        // Move to next phase
        $next_number = $current_number + 1;
        $next_phase = self::PHASES[$next_number] ?? '';

        if (empty($next_phase)) {
            $this->complete_pipeline();
            return;
        }

        // Update phase
        update_option(self::OPTION_PHASE, $next_phase, false);
        $this->update_last_activity();

        // Reset phase-specific progress
        $this->reset_phase_progress();

        // Log phase transition
        $this->log('info', 'Phase advanced', [
            'from_phase' => $current_phase,
            'to_phase'   => $next_phase,
        ]);

        // Fire phase change action
        $stats = $this->get_pipeline_stats();
        do_action('vibe_ai_pipeline_phase_changed', $next_phase, $stats);

        // Schedule the new phase
        $this->schedule_current_phase();
    }

    /**
     * Check if the pipeline is currently running.
     *
     * @return bool True if running.
     */
    public function is_running(): bool {
        $status = get_option(self::OPTION_STATUS, 'idle');
        return $status === 'running';
    }

    /**
     * Get detailed progress information.
     *
     * @return array Progress data.
     */
    public function get_progress(): array {
        $progress = get_option(self::OPTION_PROGRESS, []);

        return wp_parse_args($progress, [
            'total'      => 0,
            'completed'  => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'percentage' => 0,
            'phase'      => [
                'total'      => 0,
                'completed'  => 0,
                'failed'     => 0,
                'percentage' => 0,
            ],
            'eta_seconds'       => null,
            'avg_process_time'  => 0,
            'current_batch'     => 0,
            'total_batches'     => 0,
        ]);
    }

    /**
     * Update progress data.
     *
     * @param array $data Progress data to update.
     * @return void
     */
    public function update_progress(array $data): void {
        $current = $this->get_progress();
        $updated = wp_parse_args($data, $current);

        // Calculate overall percentage
        if ($updated['total'] > 0) {
            $updated['percentage'] = (int) round(($updated['completed'] / $updated['total']) * 100);
        }

        // Calculate phase percentage
        if (isset($updated['phase']['total']) && $updated['phase']['total'] > 0) {
            $updated['phase']['percentage'] = (int) round(
                ($updated['phase']['completed'] / $updated['phase']['total']) * 100
            );
        }

        update_option(self::OPTION_PROGRESS, $updated, false);
        $this->update_last_activity();
    }

    /**
     * Increment progress counters.
     *
     * @param string $type Counter type: 'completed', 'failed', 'skipped'.
     * @param int    $count Amount to increment by.
     * @return void
     */
    public function increment_progress(string $type, int $count = 1): void {
        $progress = $this->get_progress();

        if (isset($progress[$type])) {
            $progress[$type] += $count;
        }

        if (isset($progress['phase'][$type])) {
            $progress['phase'][$type] += $count;
        }

        $this->update_progress($progress);
    }

    /**
     * Handle phase completion callback.
     *
     * @return void
     */
    public function handle_phase_complete(): void {
        // Check if there are more items to process in this phase
        $progress = $this->get_progress();

        if ($progress['phase']['completed'] + $progress['phase']['failed'] >= $progress['phase']['total']) {
            // Phase is complete, advance to next
            $this->advance_phase();
        }
    }

    /**
     * Get the current phase name.
     *
     * @return string Current phase name.
     */
    public function get_current_phase(): string {
        return get_option(self::OPTION_PHASE, '');
    }

    /**
     * Get pipeline configuration.
     *
     * @return array Configuration array.
     */
    public function get_config(): array {
        return get_option(self::OPTION_CONFIG, []);
    }

    /**
     * Update pipeline configuration.
     *
     * @param array $config Configuration updates.
     * @return void
     */
    public function update_config(array $config): void {
        $current = $this->get_config();
        $updated = wp_parse_args($config, $current);
        update_option(self::OPTION_CONFIG, $updated, false);
    }

    /**
     * Mark pipeline as failed.
     *
     * @param string $error Error message.
     * @param array  $context Additional context.
     * @return void
     */
    public function fail(string $error, array $context = []): void {
        update_option(self::OPTION_STATUS, 'failed', false);
        $this->update_last_activity();

        $this->log('error', 'Pipeline failed: ' . $error, $context);

        do_action('vibe_ai_pipeline_failed', $error, $context);
    }

    /**
     * Schedule the current phase job.
     *
     * @return void
     */
    private function schedule_current_phase(): void {
        $phase = $this->get_current_phase();

        if (empty($phase) || !isset(self::PHASE_JOBS[$phase])) {
            $this->log('error', 'Invalid phase for scheduling', ['phase' => $phase]);
            return;
        }

        $hook = "vibe_ai_phase_{$phase}";
        $config = $this->get_config();

        // Schedule using Action Scheduler
        as_schedule_single_action(
            time(),
            $hook,
            ['config' => $config],
            'vibe-ai-index'
        );

        $this->log('info', 'Phase scheduled', [
            'phase' => $phase,
            'hook'  => $hook,
        ]);
    }

    /**
     * Complete the pipeline.
     *
     * @return void
     */
    private function complete_pipeline(): void {
        update_option(self::OPTION_STATUS, 'completed', false);
        update_option(self::OPTION_PHASE, '', false);
        $this->update_last_activity();

        $stats = $this->get_pipeline_stats();
        $progress = $this->get_progress();

        $this->log('info', 'Pipeline completed', [
            'stats'    => $stats,
            'progress' => $progress,
        ]);

        do_action('vibe_ai_pipeline_completed', $stats, $progress);
    }

    /**
     * Reset all progress counters.
     *
     * @return void
     */
    private function reset_progress(): void {
        update_option(self::OPTION_PROGRESS, [
            'total'      => 0,
            'completed'  => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'percentage' => 0,
            'phase'      => [
                'total'      => 0,
                'completed'  => 0,
                'failed'     => 0,
                'percentage' => 0,
            ],
            'eta_seconds'       => null,
            'avg_process_time'  => 0,
            'current_batch'     => 0,
            'total_batches'     => 0,
        ], false);
    }

    /**
     * Reset phase-specific progress while keeping overall progress.
     *
     * @return void
     */
    private function reset_phase_progress(): void {
        $progress = $this->get_progress();
        $progress['phase'] = [
            'total'      => 0,
            'completed'  => 0,
            'failed'     => 0,
            'percentage' => 0,
        ];
        $progress['current_batch'] = 0;
        $progress['total_batches'] = 0;
        update_option(self::OPTION_PROGRESS, $progress, false);
    }

    /**
     * Get the phase number from phase name.
     *
     * @param string $phase Phase name.
     * @return int Phase number (0 if not found).
     */
    private function get_phase_number(string $phase): int {
        $flipped = array_flip(self::PHASES);
        return $flipped[$phase] ?? 0;
    }

    /**
     * Get pipeline statistics from the database.
     *
     * @return array Statistics.
     */
    private function get_pipeline_stats(): array {
        global $wpdb;

        $entities_table = $wpdb->prefix . 'ai_entities';
        $mentions_table = $wpdb->prefix . 'ai_mentions';

        // Check if tables exist
        $entities_exists = $wpdb->get_var("SHOW TABLES LIKE '{$entities_table}'") === $entities_table;

        if (!$entities_exists) {
            return [
                'total_entities'   => 0,
                'total_mentions'   => 0,
                'avg_confidence'   => 0,
                'entities_by_type' => [],
            ];
        }

        // Get total entities
        $total_entities = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$entities_table}");

        // Get total mentions
        $mentions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$mentions_table}'") === $mentions_table;
        $total_mentions = $mentions_exists
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mentions_table}")
            : 0;

        // Get average confidence
        $avg_confidence = $mentions_exists
            ? (float) $wpdb->get_var("SELECT AVG(confidence) FROM {$mentions_table}")
            : 0;

        // Get entities by type
        $entities_by_type = $wpdb->get_results(
            "SELECT type, COUNT(*) as count FROM {$entities_table} GROUP BY type",
            OBJECT_K
        );

        return [
            'total_entities'   => $total_entities,
            'total_mentions'   => $total_mentions,
            'avg_confidence'   => round($avg_confidence, 3),
            'entities_by_type' => array_map(function ($row) {
                return (int) $row->count;
            }, $entities_by_type),
        ];
    }

    /**
     * Get list of entities currently propagating.
     *
     * @return array Entity IDs.
     */
    private function get_propagating_entities(): array {
        global $wpdb;

        // Look for propagation transients
        $results = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_vibe_ai_propagating_%'"
        );

        $entity_ids = [];
        foreach ($results as $option_name) {
            if (preg_match('/vibe_ai_propagating_(\d+)$/', $option_name, $matches)) {
                $entity_ids[] = (int) $matches[1];
            }
        }

        return $entity_ids;
    }

    /**
     * Update last activity timestamp.
     *
     * @return void
     */
    private function update_last_activity(): void {
        update_option(self::OPTION_LAST_ACTIVITY, current_time('mysql', true), false);
    }

    /**
     * Log a message.
     *
     * @param string $level   Log level: debug, info, warning, error.
     * @param string $message Log message.
     * @param array  $context Additional context.
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void {
        if (function_exists('vibe_ai_log')) {
            vibe_ai_log($level, $message, $context);
        }

        // Fire action for external logging
        do_action('vibe_ai_pipeline_log', $level, $message, $context);
    }
}

<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Pipeline;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Jobs\KB\DocumentBuildJob;
use Vibe\AIIndex\Jobs\KB\ChunkBuildJob;
use Vibe\AIIndex\Jobs\KB\EmbedChunksJob;
use Vibe\AIIndex\Jobs\KB\IndexUpsertJob;
use Vibe\AIIndex\Jobs\KB\CleanupJob;
use Vibe\AIIndex\Repositories\KB\DocumentRepository;
use Vibe\AIIndex\Repositories\KB\ChunkRepository;
use Vibe\AIIndex\Repositories\KB\VectorRepository;

/**
 * KBPipelineManager: Orchestrates the KB indexing pipeline.
 *
 * Runs independently from the entity extraction pipeline.
 * Manages document processing, chunking, embedding generation,
 * and vector storage for RAG-style knowledge base retrieval.
 *
 * Phases:
 * - kb_document_build: Normalize posts into documents
 * - kb_chunk_build: Split documents into chunks
 * - kb_embed_chunks: Generate embeddings via OpenRouter
 * - kb_index_upsert: Store vectors, update status
 * - kb_cleanup: Remove stale data
 *
 * @package Vibe\AIIndex\Pipeline
 * @since 1.0.0
 */
class KBPipelineManager {

    /**
     * Pipeline status option key.
     */
    private const OPTION_STATUS = 'vibe_ai_kb_pipeline_status';

    /**
     * Current phase option key.
     */
    private const OPTION_PHASE = 'vibe_ai_kb_pipeline_phase';

    /**
     * Progress data option key.
     */
    private const OPTION_PROGRESS = 'vibe_ai_kb_pipeline_progress';

    /**
     * Pipeline started timestamp option key.
     */
    private const OPTION_STARTED_AT = 'vibe_ai_kb_pipeline_started_at';

    /**
     * Pipeline options/configuration option key.
     */
    private const OPTION_CONFIG = 'vibe_ai_kb_pipeline_config';

    /**
     * Last activity timestamp option key.
     */
    private const OPTION_LAST_ACTIVITY = 'vibe_ai_kb_pipeline_last_activity';

    /**
     * Pipeline phases in order.
     */
    public const PHASES = [
        'kb_document_build',
        'kb_chunk_build',
        'kb_embed_chunks',
        'kb_index_upsert',
        'kb_cleanup',
    ];

    /**
     * Phase to job class mapping.
     */
    private const PHASE_JOBS = [
        'kb_document_build' => DocumentBuildJob::class,
        'kb_chunk_build'    => ChunkBuildJob::class,
        'kb_embed_chunks'   => EmbedChunksJob::class,
        'kb_index_upsert'   => IndexUpsertJob::class,
        'kb_cleanup'        => CleanupJob::class,
    ];

    /**
     * Valid pipeline statuses.
     */
    private const VALID_STATUSES = ['idle', 'running', 'paused', 'completed', 'failed'];

    /**
     * Action Scheduler group name for KB jobs.
     */
    private const SCHEDULER_GROUP = 'vibe-ai-kb';

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Document repository instance.
     *
     * @var DocumentRepository
     */
    private DocumentRepository $docRepo;

    /**
     * Chunk repository instance.
     *
     * @var ChunkRepository
     */
    private ChunkRepository $chunkRepo;

    /**
     * Vector repository instance.
     *
     * @var VectorRepository
     */
    private VectorRepository $vectorRepo;

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
     * Constructor: Initialize repositories and register hooks.
     */
    public function __construct() {
        $this->docRepo    = new DocumentRepository();
        $this->chunkRepo  = new ChunkRepository();
        $this->vectorRepo = new VectorRepository();

        $this->register_hooks();
    }

    /**
     * Register WordPress hooks for phase completion and post updates.
     *
     * @return void
     */
    private function register_hooks(): void {
        // Listen for phase completion events
        foreach (self::PHASES as $phase) {
            add_action("vibe_ai_{$phase}_complete", [$this, 'handle_phase_complete']);
        }

        // Hook into post saves for automatic indexing
        add_action('save_post', [$this, 'on_save_post'], 20, 2);
        add_action('delete_post', [$this, 'on_delete_post'], 10, 1);
    }

    /**
     * Start the KB indexing pipeline.
     *
     * @param array $options {
     *     Pipeline configuration options.
     *
     *     @type string $scope      Indexing scope: 'all', 'post_type', or 'post_id'.
     *     @type string $post_type  Post type to index (when scope='post_type').
     *     @type int    $post_id    Single post ID to index (when scope='post_id').
     *     @type bool   $force      Reindex even if content unchanged.
     * }
     * @return void
     * @throws \RuntimeException If pipeline is already running.
     */
    public function start(array $options = []): void {
        if ($this->isRunning()) {
            throw new \RuntimeException('KB Pipeline is already running. Stop it first before starting a new run.');
        }

        // Apply filter for indexable post types
        $default_post_types = apply_filters('vibe_ai_kb_post_types', ['post', 'page']);

        // Merge options with defaults
        $config = wp_parse_args($options, [
            'scope'       => 'all',
            'post_type'   => '',
            'post_id'     => 0,
            'post_types'  => $default_post_types,
            'force'       => false,
            'batch_size'  => Config::BATCH_SIZE,
            'started_at'  => current_time('mysql', true),
            'started_by'  => get_current_user_id(),
        ]);

        // Validate scope-specific options
        if ($config['scope'] === 'post_type' && empty($config['post_type'])) {
            throw new \InvalidArgumentException('post_type is required when scope is "post_type".');
        }
        if ($config['scope'] === 'post_id' && empty($config['post_id'])) {
            throw new \InvalidArgumentException('post_id is required when scope is "post_id".');
        }

        // Store configuration
        update_option(self::OPTION_CONFIG, $config, false);
        update_option(self::OPTION_STARTED_AT, $config['started_at'], false);

        // Initialize progress tracking
        $this->initializeProgress($config);

        // Set status to running and phase to first phase
        update_option(self::OPTION_STATUS, 'running', false);
        update_option(self::OPTION_PHASE, self::PHASES[0], false);
        $this->updateLastActivity();

        // Log pipeline start
        $this->log('info', 'KB Pipeline started', [
            'config' => $config,
        ]);

        // Fire pipeline started action
        do_action('vibe_ai_kb_pipeline_started', $config);

        // Schedule the first phase
        $this->schedulePhaseJob(self::PHASES[0], $config);
    }

    /**
     * Stop/cancel the pipeline.
     *
     * Cancels all pending Action Scheduler jobs and resets status.
     *
     * @return void
     */
    public function stop(): void {
        // Cancel all scheduled pipeline jobs
        $this->cancelPendingJobs();

        // Capture previous state for logging
        $previous_status = get_option(self::OPTION_STATUS, 'idle');
        $previous_phase  = get_option(self::OPTION_PHASE, '');

        // Update status to idle
        update_option(self::OPTION_STATUS, 'idle', false);
        $this->updateLastActivity();

        // Log pipeline stop
        $this->log('info', 'KB Pipeline stopped', [
            'previous_status' => $previous_status,
            'previous_phase'  => $previous_phase,
        ]);

        // Fire pipeline stopped action
        do_action('vibe_ai_kb_pipeline_stopped');
    }

    /**
     * Check if pipeline is currently running.
     *
     * @return bool True if running.
     */
    public function isRunning(): bool {
        $status = get_option(self::OPTION_STATUS, 'idle');
        return $status === 'running';
    }

    /**
     * Get current pipeline status.
     *
     * @return array{
     *     status: string,
     *     current_phase: string|null,
     *     progress: array{total: int, completed: int, failed: int, percentage: int},
     *     stats: array{total_docs: int, total_chunks: int, total_vectors: int},
     *     started_at: string|null,
     *     last_activity: string|null
     * }
     */
    public function getStatus(): array {
        $status        = get_option(self::OPTION_STATUS, 'idle');
        $phase         = get_option(self::OPTION_PHASE, '');
        $progress      = $this->getProgress();
        $config        = get_option(self::OPTION_CONFIG, []);
        $started_at    = get_option(self::OPTION_STARTED_AT, '');
        $last_activity = get_option(self::OPTION_LAST_ACTIVITY, '');
        $stats         = $this->getStats();

        return [
            'status'        => $status,
            'current_phase' => $phase ?: null,
            'phase_number'  => $this->getPhaseIndex($phase) + 1,
            'total_phases'  => count(self::PHASES),
            'progress'      => $progress,
            'stats'         => $stats,
            'config'        => $config,
            'started_at'    => $started_at ?: null,
            'last_activity' => $last_activity ?: null,
        ];
    }

    /**
     * Get current progress within the active phase.
     *
     * @return array Progress data.
     */
    public function getProgress(): array {
        $progress = get_option(self::OPTION_PROGRESS, []);

        return wp_parse_args($progress, [
            'total'      => 0,
            'completed'  => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'percentage' => 0,
            'phase'      => [
                'name'       => '',
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
     * Advance to the next phase.
     *
     * @return void
     */
    public function advancePhase(): void {
        $current_phase = get_option(self::OPTION_PHASE, '');
        $current_index = $this->getPhaseIndex($current_phase);

        if ($current_index >= count(self::PHASES) - 1) {
            // Pipeline complete
            $this->complete();
            return;
        }

        // Move to next phase
        $next_phase = self::PHASES[$current_index + 1];

        // Update phase
        update_option(self::OPTION_PHASE, $next_phase, false);
        $this->updateLastActivity();

        // Reset phase-specific progress
        $this->resetPhaseProgress($next_phase);

        // Log phase transition
        $this->log('info', 'KB Pipeline phase advanced', [
            'from_phase' => $current_phase,
            'to_phase'   => $next_phase,
        ]);

        // Fire phase change action with stats
        $stats = $this->getStats();
        do_action('vibe_ai_kb_pipeline_phase_changed', $next_phase, $stats);

        // Schedule the new phase
        $config = get_option(self::OPTION_CONFIG, []);
        $this->schedulePhaseJob($next_phase, $config);
    }

    /**
     * Set current phase explicitly.
     *
     * @param string $phase Phase name (must be valid).
     * @return void
     * @throws \InvalidArgumentException If phase is invalid.
     */
    public function setPhase(string $phase): void {
        if (!in_array($phase, self::PHASES, true)) {
            throw new \InvalidArgumentException("Invalid KB pipeline phase: {$phase}");
        }

        update_option(self::OPTION_PHASE, $phase, false);
        $this->updateLastActivity();

        $this->log('info', 'KB Pipeline phase set', ['phase' => $phase]);
    }

    /**
     * Update progress counters.
     *
     * @param array $data Progress data to update.
     * @return void
     */
    public function updateProgress(array $data): void {
        $current = $this->getProgress();
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
        $this->updateLastActivity();
    }

    /**
     * Increment progress counters.
     *
     * @param string $type  Counter type: 'completed', 'failed', 'skipped'.
     * @param int    $count Amount to increment by.
     * @return void
     */
    public function incrementProgress(string $type, int $count = 1): void {
        $progress = $this->getProgress();

        if (isset($progress[$type])) {
            $progress[$type] += $count;
        }

        if (isset($progress['phase'][$type])) {
            $progress['phase'][$type] += $count;
        }

        $this->updateProgress($progress);
    }

    /**
     * Mark pipeline as completed.
     *
     * @return void
     */
    public function complete(): void {
        update_option(self::OPTION_STATUS, 'completed', false);
        update_option(self::OPTION_PHASE, '', false);
        $this->updateLastActivity();

        $stats    = $this->getStats();
        $progress = $this->getProgress();

        $this->log('info', 'KB Pipeline completed', [
            'stats'    => $stats,
            'progress' => $progress,
        ]);

        do_action('vibe_ai_kb_pipeline_completed', $stats);
    }

    /**
     * Mark pipeline as failed.
     *
     * @param string $reason Failure reason.
     * @return void
     */
    public function fail(string $reason): void {
        update_option(self::OPTION_STATUS, 'failed', false);
        $this->updateLastActivity();

        // Store failure reason in progress
        $progress = $this->getProgress();
        $progress['failure_reason'] = $reason;
        update_option(self::OPTION_PROGRESS, $progress, false);

        $this->log('error', 'KB Pipeline failed: ' . $reason, [
            'phase' => get_option(self::OPTION_PHASE, ''),
        ]);

        do_action('vibe_ai_kb_pipeline_failed', $reason);
    }

    /**
     * Schedule indexing for a single post.
     *
     * Called from save_post hook for automatic re-indexing.
     *
     * @param int $postId Post ID to schedule.
     * @return void
     */
    public function schedulePost(int $postId): void {
        if (!$this->shouldIndexPost($postId)) {
            return;
        }

        // Don't queue if a full pipeline is running
        if ($this->isRunning()) {
            $this->log('debug', 'Skipping post schedule, pipeline running', ['post_id' => $postId]);
            return;
        }

        // Schedule a single-post indexing job
        as_schedule_single_action(
            time() + 30, // Small delay to batch rapid saves
            'vibe_ai_kb_index_single_post',
            ['post_id' => $postId],
            self::SCHEDULER_GROUP
        );

        $this->log('debug', 'Scheduled single post for KB indexing', ['post_id' => $postId]);
    }

    /**
     * Schedule reindex for posts by type.
     *
     * @param string $postType Post type to reindex.
     * @return void
     */
    public function schedulePostType(string $postType): void {
        if (empty($postType)) {
            return;
        }

        // Start a scoped pipeline for this post type
        $this->start([
            'scope'     => 'post_type',
            'post_type' => $postType,
            'force'     => true,
        ]);
    }

    /**
     * Get index statistics.
     *
     * @return array{total_docs: int, total_chunks: int, total_vectors: int, by_post_type: array}
     */
    public function getStats(): array {
        global $wpdb;

        $docs_table    = $wpdb->prefix . 'ai_kb_documents';
        $chunks_table  = $wpdb->prefix . 'ai_kb_chunks';
        $vectors_table = $wpdb->prefix . 'ai_kb_vectors';

        // Initialize default stats
        $stats = [
            'total_docs'    => 0,
            'total_chunks'  => 0,
            'total_vectors' => 0,
            'by_post_type'  => [],
            'by_status'     => [],
        ];

        // Check if tables exist and get counts
        if ($wpdb->get_var("SHOW TABLES LIKE '{$docs_table}'") === $docs_table) {
            $stats['total_docs'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$docs_table}");

            // Get breakdown by post type
            $by_type = $wpdb->get_results(
                "SELECT post_type, COUNT(*) as count FROM {$docs_table} GROUP BY post_type",
                OBJECT_K
            );
            $stats['by_post_type'] = array_map(fn($row) => (int) $row->count, $by_type);

            // Get breakdown by status
            $by_status = $wpdb->get_results(
                "SELECT status, COUNT(*) as count FROM {$docs_table} GROUP BY status",
                OBJECT_K
            );
            $stats['by_status'] = array_map(fn($row) => (int) $row->count, $by_status);
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '{$chunks_table}'") === $chunks_table) {
            $stats['total_chunks'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$chunks_table}");
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '{$vectors_table}'") === $vectors_table) {
            $stats['total_vectors'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$vectors_table}");
        }

        return $stats;
    }

    /**
     * Check if a post should be indexed.
     *
     * @param int $postId Post ID to check.
     * @return bool True if post should be indexed.
     */
    public function shouldIndexPost(int $postId): bool {
        $post = get_post($postId);

        if (!$post) {
            return false;
        }

        // Check post status
        if ($post->post_status !== 'publish') {
            return false;
        }

        // Get indexable post types
        $indexable_types = apply_filters('vibe_ai_kb_post_types', ['post', 'page']);
        if (!in_array($post->post_type, $indexable_types, true)) {
            return false;
        }

        // Check for empty content
        if (empty(trim($post->post_content))) {
            return false;
        }

        // Allow filtering
        return apply_filters('vibe_ai_kb_should_index_post', true, $postId);
    }

    /**
     * Handle phase completion callback.
     *
     * @return void
     */
    public function handle_phase_complete(): void {
        $progress = $this->getProgress();

        // Check if phase work is complete
        if ($progress['phase']['completed'] + $progress['phase']['failed'] >= $progress['phase']['total']) {
            $this->advancePhase();
        }
    }

    /**
     * Handle save_post hook for automatic indexing.
     *
     * @param int      $postId Post ID.
     * @param \WP_Post $post   Post object.
     * @return void
     */
    public function on_save_post(int $postId, \WP_Post $post): void {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        // Schedule for indexing
        $this->schedulePost($postId);
    }

    /**
     * Handle delete_post hook for cleanup.
     *
     * @param int $postId Post ID being deleted.
     * @return void
     */
    public function on_delete_post(int $postId): void {
        // Schedule cleanup job
        as_schedule_single_action(
            time(),
            'vibe_ai_kb_cleanup_post',
            ['post_id' => $postId],
            self::SCHEDULER_GROUP
        );
    }

    /**
     * Get pipeline configuration.
     *
     * @return array Configuration array.
     */
    public function getConfig(): array {
        return get_option(self::OPTION_CONFIG, []);
    }

    /**
     * Get the phase index from phase name.
     *
     * @param string $phase Phase name.
     * @return int Phase index (0-based, -1 if not found).
     */
    private function getPhaseIndex(string $phase): int {
        $index = array_search($phase, self::PHASES, true);
        return $index !== false ? $index : -1;
    }

    /**
     * Schedule a phase job via Action Scheduler.
     *
     * @param string $phase Phase name.
     * @param array  $args  Job arguments.
     * @return void
     */
    private function schedulePhaseJob(string $phase, array $args = []): void {
        if (!isset(self::PHASE_JOBS[$phase])) {
            $this->log('error', 'Invalid phase for scheduling', ['phase' => $phase]);
            return;
        }

        $hook = "vibe_ai_{$phase}";

        // Schedule using Action Scheduler
        as_schedule_single_action(
            time(),
            $hook,
            ['config' => $args],
            self::SCHEDULER_GROUP
        );

        $this->log('info', 'KB Pipeline phase scheduled', [
            'phase' => $phase,
            'hook'  => $hook,
        ]);
    }

    /**
     * Cancel all pending KB pipeline jobs.
     *
     * @return void
     */
    private function cancelPendingJobs(): void {
        // Cancel phase jobs
        foreach (self::PHASES as $phase) {
            $hook = "vibe_ai_{$phase}";
            as_unschedule_all_actions($hook, [], self::SCHEDULER_GROUP);
        }

        // Cancel single-post jobs
        as_unschedule_all_actions('vibe_ai_kb_index_single_post', [], self::SCHEDULER_GROUP);
        as_unschedule_all_actions('vibe_ai_kb_cleanup_post', [], self::SCHEDULER_GROUP);

        $this->log('info', 'Cancelled all pending KB pipeline jobs');
    }

    /**
     * Initialize progress tracking based on scope.
     *
     * @param array $options Pipeline options.
     * @return void
     */
    private function initializeProgress(array $options): void {
        global $wpdb;

        // Calculate total items based on scope
        $total = 0;

        switch ($options['scope']) {
            case 'post_id':
                $total = 1;
                break;

            case 'post_type':
                $total = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                    $options['post_type']
                ));
                break;

            case 'all':
            default:
                $post_types = $options['post_types'] ?? ['post', 'page'];
                $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
                $total = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish'",
                    ...$post_types
                ));
                break;
        }

        // Calculate batch info
        $batch_size    = $options['batch_size'] ?? Config::BATCH_SIZE;
        $total_batches = (int) ceil($total / $batch_size);

        update_option(self::OPTION_PROGRESS, [
            'total'             => $total,
            'completed'         => 0,
            'failed'            => 0,
            'skipped'           => 0,
            'percentage'        => 0,
            'phase'             => [
                'name'       => self::PHASES[0],
                'total'      => $total,
                'completed'  => 0,
                'failed'     => 0,
                'percentage' => 0,
            ],
            'eta_seconds'       => null,
            'avg_process_time'  => 0,
            'current_batch'     => 0,
            'total_batches'     => $total_batches,
        ], false);
    }

    /**
     * Reset phase-specific progress while keeping overall progress.
     *
     * @param string $phaseName Name of the new phase.
     * @return void
     */
    private function resetPhaseProgress(string $phaseName): void {
        $progress = $this->getProgress();

        // Determine phase total based on phase type
        $phase_total = $this->calculatePhaseTotal($phaseName);

        $progress['phase'] = [
            'name'       => $phaseName,
            'total'      => $phase_total,
            'completed'  => 0,
            'failed'     => 0,
            'percentage' => 0,
        ];
        $progress['current_batch'] = 0;

        update_option(self::OPTION_PROGRESS, $progress, false);
    }

    /**
     * Calculate total items for a specific phase.
     *
     * @param string $phaseName Phase name.
     * @return int Total items to process.
     */
    private function calculatePhaseTotal(string $phaseName): int {
        global $wpdb;

        $docs_table   = $wpdb->prefix . 'ai_kb_documents';
        $chunks_table = $wpdb->prefix . 'ai_kb_chunks';

        switch ($phaseName) {
            case 'kb_document_build':
                // Count of posts to process
                $config     = $this->getConfig();
                $post_types = $config['post_types'] ?? ['post', 'page'];
                $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
                return (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish'",
                    ...$post_types
                ));

            case 'kb_chunk_build':
                // Count of documents pending chunking
                if ($wpdb->get_var("SHOW TABLES LIKE '{$docs_table}'") !== $docs_table) {
                    return 0;
                }
                return (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$docs_table} WHERE status IN ('new', 'updated')"
                );

            case 'kb_embed_chunks':
                // Count of chunks pending embedding
                if ($wpdb->get_var("SHOW TABLES LIKE '{$chunks_table}'") !== $chunks_table) {
                    return 0;
                }
                return (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$chunks_table} WHERE embedding_status = 'pending'"
                );

            case 'kb_index_upsert':
                // Count of chunks with embeddings ready for indexing
                if ($wpdb->get_var("SHOW TABLES LIKE '{$chunks_table}'") !== $chunks_table) {
                    return 0;
                }
                return (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$chunks_table} WHERE embedding_status = 'complete' AND index_status = 'pending'"
                );

            case 'kb_cleanup':
                // Cleanup is typically a single operation
                return 1;

            default:
                return 0;
        }
    }

    /**
     * Update last activity timestamp.
     *
     * @return void
     */
    private function updateLastActivity(): void {
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
        $context['pipeline'] = 'kb';

        if (function_exists('vibe_ai_log')) {
            vibe_ai_log($level, '[KB] ' . $message, $context);
        }

        // Fire action for external logging
        do_action('vibe_ai_kb_pipeline_log', $level, $message, $context);
    }
}

<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs\KB;

/**
 * KB Phase 5: Remove stale chunks and orphaned data.
 *
 * Cleans up orphaned chunks (where doc was deleted), orphaned vectors
 * (where chunk was deleted), and docs for trashed/deleted posts.
 * Also updates KB statistics.
 *
 * @package Vibe\AIIndex\Jobs\KB
 * @since 1.0.0
 */
class CleanupJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_kb_cleanup';

    /**
     * Option key for KB statistics.
     */
    private const OPTION_KB_STATS = 'vibe_ai_kb_stats';

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 0);
    }

    /**
     * Schedule the cleanup job.
     *
     * @return void
     */
    public static function schedule(): void {
        as_schedule_single_action(
            time(),
            self::HOOK,
            [],
            'vibe-ai-kb'
        );
    }

    /**
     * Execute the cleanup phase.
     *
     * @return void
     */
    public static function execute(): void {
        $job = new self();

        try {
            $job->run();
        } catch (\Throwable $e) {
            $job->handleError($e);
        }
    }

    /**
     * Run the cleanup job.
     *
     * @return void
     */
    public function run(): void {
        global $wpdb;

        $docsTable = $wpdb->prefix . 'ai_kb_docs';
        $chunksTable = $wpdb->prefix . 'ai_kb_chunks';
        $vectorsTable = $wpdb->prefix . 'ai_kb_vectors';

        $this->log('info', 'Cleanup phase started');

        $stats = [
            'orphaned_vectors_deleted' => 0,
            'orphaned_chunks_deleted'  => 0,
            'orphaned_docs_deleted'    => 0,
            'trashed_docs_deleted'     => 0,
        ];

        // 1. Delete orphaned vectors (chunk no longer exists)
        $orphanedVectors = $wpdb->query(
            "DELETE v FROM {$vectorsTable} v
             LEFT JOIN {$chunksTable} c ON v.chunk_id = c.id
             WHERE c.id IS NULL"
        );
        $stats['orphaned_vectors_deleted'] = (int) $orphanedVectors;

        if ($orphanedVectors > 0) {
            $this->log('info', "Deleted {$orphanedVectors} orphaned vectors");
        }

        // 2. Delete orphaned chunks (doc no longer exists)
        $orphanedChunks = $wpdb->query(
            "DELETE c FROM {$chunksTable} c
             LEFT JOIN {$docsTable} d ON c.doc_id = d.id
             WHERE d.id IS NULL"
        );
        $stats['orphaned_chunks_deleted'] = (int) $orphanedChunks;

        if ($orphanedChunks > 0) {
            $this->log('info', "Deleted {$orphanedChunks} orphaned chunks");
        }

        // 3. Delete docs for trashed or deleted posts
        $trashedDocs = $wpdb->query(
            "DELETE d FROM {$docsTable} d
             LEFT JOIN {$wpdb->posts} p ON d.post_id = p.ID
             WHERE p.ID IS NULL OR p.post_status IN ('trash', 'auto-draft')"
        );
        $stats['trashed_docs_deleted'] = (int) $trashedDocs;

        if ($trashedDocs > 0) {
            $this->log('info', "Deleted {$trashedDocs} docs for trashed/deleted posts");

            // Clean up any newly orphaned chunks and vectors after doc deletion
            $cascadeChunks = $wpdb->query(
                "DELETE c FROM {$chunksTable} c
                 LEFT JOIN {$docsTable} d ON c.doc_id = d.id
                 WHERE d.id IS NULL"
            );

            $cascadeVectors = $wpdb->query(
                "DELETE v FROM {$vectorsTable} v
                 LEFT JOIN {$chunksTable} c ON v.chunk_id = c.id
                 WHERE c.id IS NULL"
            );

            $stats['orphaned_chunks_deleted'] += (int) $cascadeChunks;
            $stats['orphaned_vectors_deleted'] += (int) $cascadeVectors;
        }

        // 4. Delete docs for posts that are now excluded
        $excludedDocs = $this->cleanupExcludedPosts();
        $stats['orphaned_docs_deleted'] = (int) $excludedDocs;

        // 5. Calculate and store KB statistics
        $kbStats = $this->calculateStatistics();
        update_option(self::OPTION_KB_STATS, $kbStats, false);

        // 6. Optimize tables (if significant cleanup occurred)
        $totalDeleted = array_sum($stats);
        if ($totalDeleted > 100) {
            $this->optimizeTables();
        }

        $this->log('info', 'Cleanup phase complete', [
            'stats'    => $stats,
            'kb_stats' => $kbStats,
        ]);

        // Fire completion action
        do_action('vibe_ai_kb_cleanup_complete', $stats);

        // Fire pipeline complete action
        do_action('vibe_ai_kb_pipeline_complete', $kbStats);
    }

    /**
     * Clean up docs for posts that are now excluded.
     *
     * @return int Number of docs deleted.
     */
    private function cleanupExcludedPosts(): int {
        global $wpdb;

        $docsTable = $wpdb->prefix . 'ai_kb_docs';

        // Find docs where the post now has the exclusion meta
        $excludedDocs = $wpdb->get_col(
            "SELECT d.id FROM {$docsTable} d
             INNER JOIN {$wpdb->postmeta} pm ON d.post_id = pm.post_id
             WHERE pm.meta_key = '_vibe_ai_kb_excluded'
             AND pm.meta_value = '1'"
        );

        if (empty($excludedDocs)) {
            return 0;
        }

        $docIds = implode(',', array_map('intval', $excludedDocs));

        // Delete associated chunks first
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ai_kb_chunks WHERE doc_id IN ({$docIds})"
        );

        // Delete docs
        $deleted = $wpdb->query(
            "DELETE FROM {$docsTable} WHERE id IN ({$docIds})"
        );

        if ($deleted > 0) {
            $this->log('info', "Deleted {$deleted} docs for excluded posts");
        }

        return (int) $deleted;
    }

    /**
     * Calculate KB statistics.
     *
     * @return array Statistics array.
     */
    private function calculateStatistics(): array {
        global $wpdb;

        $docsTable = $wpdb->prefix . 'ai_kb_docs';
        $chunksTable = $wpdb->prefix . 'ai_kb_chunks';
        $vectorsTable = $wpdb->prefix . 'ai_kb_vectors';

        // Total documents
        $totalDocs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$docsTable}");

        // Documents by status
        $statusCounts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$docsTable} GROUP BY status",
            OBJECT_K
        );

        // Total chunks
        $totalChunks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$chunksTable}");

        // Total vectors
        $totalVectors = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$vectorsTable}");

        // Average chunks per document
        $avgChunksPerDoc = $totalDocs > 0 ? round($totalChunks / $totalDocs, 2) : 0;

        // Total token count (estimated)
        $totalTokens = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(token_count), 0) FROM {$chunksTable}"
        );

        // Indexed documents count
        $indexedDocs = isset($statusCounts['indexed']) ? (int) $statusCounts['indexed']->count : 0;

        // Pending documents count
        $pendingDocs = isset($statusCounts['pending']) ? (int) $statusCounts['pending']->count : 0;

        // Chunked documents count
        $chunkedDocs = isset($statusCounts['chunked']) ? (int) $statusCounts['chunked']->count : 0;

        // Coverage percentage
        $coverage = $totalDocs > 0 ? round(($indexedDocs / $totalDocs) * 100, 1) : 0;

        // Last indexed timestamp
        $lastIndexed = $wpdb->get_var(
            "SELECT MAX(last_indexed_at) FROM {$docsTable} WHERE status = 'indexed'"
        );

        // Vector model info
        $vectorModel = $wpdb->get_row(
            "SELECT model, dimensions, COUNT(*) as count
             FROM {$vectorsTable}
             GROUP BY model, dimensions
             ORDER BY count DESC
             LIMIT 1"
        );

        return [
            'total_docs'        => $totalDocs,
            'indexed_docs'      => $indexedDocs,
            'pending_docs'      => $pendingDocs,
            'chunked_docs'      => $chunkedDocs,
            'total_chunks'      => $totalChunks,
            'total_vectors'     => $totalVectors,
            'total_tokens'      => $totalTokens,
            'avg_chunks_per_doc' => $avgChunksPerDoc,
            'coverage'          => $coverage,
            'last_indexed_at'   => $lastIndexed,
            'vector_model'      => $vectorModel ? $vectorModel->model : null,
            'vector_dimensions' => $vectorModel ? (int) $vectorModel->dimensions : null,
            'calculated_at'     => current_time('mysql', true),
        ];
    }

    /**
     * Optimize KB tables after significant cleanup.
     *
     * @return void
     */
    private function optimizeTables(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'ai_kb_docs',
            $wpdb->prefix . 'ai_kb_chunks',
            $wpdb->prefix . 'ai_kb_vectors',
        ];

        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE {$table}");
        }

        $this->log('debug', 'KB tables optimized');
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handleError(\Throwable $e): void {
        $this->log('error', 'Cleanup phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        do_action('vibe_ai_kb_job_failed', 'cleanup', $e->getMessage());
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
            vibe_ai_log($level, '[KB/Cleanup] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'kb_cleanup', $level, $message, $context);
    }
}

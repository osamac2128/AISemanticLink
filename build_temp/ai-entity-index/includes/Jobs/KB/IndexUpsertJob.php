<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs\KB;

/**
 * KB Phase 4: Finalize indexing, update document status.
 *
 * Verifies all chunks for each document have vectors, updates document
 * status to 'indexed', sets timestamps, and updates post meta.
 *
 * @package Vibe\AIIndex\Jobs\KB
 * @since 1.0.0
 */
class IndexUpsertJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_kb_index_upsert';

    /**
     * Default batch size for document verification.
     */
    public const BATCH_SIZE = 50;

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_kb_index_upsert_state';

    /**
     * Post meta key for indexed timestamp.
     */
    private const META_INDEXED_AT = '_vibe_ai_kb_indexed_at';

    /**
     * Post meta key for document ID reference.
     */
    private const META_DOC_ID = '_vibe_ai_kb_doc_id';

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 1);
    }

    /**
     * Schedule the index upsert job.
     *
     * @param int $lastDocId Last processed document ID.
     * @return void
     */
    public static function schedule(int $lastDocId = 0): void {
        as_schedule_single_action(
            time(),
            self::HOOK,
            ['last_doc_id' => $lastDocId],
            'vibe-ai-kb'
        );
    }

    /**
     * Execute the index upsert phase.
     *
     * @param int $lastDocId Last processed document ID.
     * @return void
     */
    public static function execute(int $lastDocId = 0): void {
        $job = new self();

        try {
            $job->run($lastDocId);
        } catch (\Throwable $e) {
            $job->handleError($e);
        }
    }

    /**
     * Run the index upsert job.
     *
     * @param int $lastDocId Last processed document ID.
     * @return void
     */
    public function run(int $lastDocId): void {
        global $wpdb;

        $docsTable = $wpdb->prefix . 'ai_kb_docs';
        $chunksTable = $wpdb->prefix . 'ai_kb_chunks';
        $vectorsTable = $wpdb->prefix . 'ai_kb_vectors';

        $this->log('info', 'Index upsert phase started', [
            'last_doc_id' => $lastDocId,
        ]);

        // Get documents that are chunked but not yet indexed
        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id, chunk_count
             FROM {$docsTable}
             WHERE status = 'chunked'
             AND id > %d
             ORDER BY id ASC
             LIMIT %d",
            $lastDocId,
            self::BATCH_SIZE
        ));

        if (empty($docs)) {
            $this->log('info', 'Index upsert phase complete - no more documents to verify');
            $this->clearBatchState();
            do_action('vibe_ai_kb_index_upsert_complete');
            $this->advanceToNextPhase();
            return;
        }

        $indexedCount = 0;
        $pendingCount = 0;
        $warningCount = 0;
        $maxDocId = $lastDocId;

        foreach ($docs as $doc) {
            $docId = (int) $doc->id;
            $postId = (int) $doc->post_id;
            $expectedChunks = (int) $doc->chunk_count;
            $maxDocId = max($maxDocId, $docId);

            // Count chunks with vectors for this document
            $vectorizedChunks = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$chunksTable} c
                 INNER JOIN {$vectorsTable} v ON c.id = v.chunk_id
                 WHERE c.doc_id = %d",
                $docId
            ));

            // Count total chunks for this document
            $totalChunks = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$chunksTable} WHERE doc_id = %d",
                $docId
            ));

            if ($vectorizedChunks === $totalChunks && $totalChunks > 0) {
                // All chunks have vectors - mark as indexed
                $now = current_time('mysql', true);

                $wpdb->update(
                    $docsTable,
                    [
                        'status'          => 'indexed',
                        'last_indexed_at' => $now,
                        'updated_at'      => $now,
                    ],
                    ['id' => $docId],
                    ['%s', '%s', '%s'],
                    ['%d']
                );

                // Update post meta
                update_post_meta($postId, self::META_INDEXED_AT, $now);
                update_post_meta($postId, self::META_DOC_ID, $docId);

                $indexedCount++;

                $this->log('debug', "Document {$docId} indexed", [
                    'post_id'     => $postId,
                    'chunk_count' => $totalChunks,
                ]);

                // Fire action for indexed document
                do_action('vibe_ai_kb_document_indexed', $postId);

            } elseif ($vectorizedChunks < $totalChunks) {
                // Some chunks missing vectors - keep pending
                $pendingCount++;

                $this->log('warning', "Document {$docId} has missing vectors", [
                    'post_id'          => $postId,
                    'total_chunks'     => $totalChunks,
                    'vectorized'       => $vectorizedChunks,
                    'missing'          => $totalChunks - $vectorizedChunks,
                ]);

                $warningCount++;

            } else {
                // No chunks at all - something went wrong
                $this->log('error', "Document {$docId} has no chunks", [
                    'post_id'        => $postId,
                    'expected'       => $expectedChunks,
                ]);

                $warningCount++;
            }
        }

        // Update batch state
        $state = $this->getBatchState();
        $this->updateBatchState([
            'last_doc_id'    => $maxDocId,
            'indexed_count'  => ($state['indexed_count'] ?? 0) + $indexedCount,
            'pending_count'  => $pendingCount,
            'warning_count'  => ($state['warning_count'] ?? 0) + $warningCount,
        ]);

        $this->log('info', "Processed batch", [
            'docs_checked' => count($docs),
            'indexed'      => $indexedCount,
            'pending'      => $pendingCount,
            'warnings'     => $warningCount,
            'last_doc_id'  => $maxDocId,
        ]);

        // Fire batch action
        do_action('vibe_ai_kb_index_upsert_batch', $indexedCount, $pendingCount);

        // If there are still pending documents, we might need to wait for embeddings
        if ($pendingCount > 0) {
            $this->log('info', "Some documents still pending vectorization");
        }

        // Schedule next batch
        $this->scheduleNextBatch($maxDocId);
    }

    /**
     * Get current batch state.
     *
     * @return array Batch state.
     */
    private function getBatchState(): array {
        return get_option(self::OPTION_BATCH_STATE, [
            'last_doc_id'    => 0,
            'indexed_count'  => 0,
            'pending_count'  => 0,
            'warning_count'  => 0,
        ]);
    }

    /**
     * Update batch state.
     *
     * @param array $state New state values.
     * @return void
     */
    private function updateBatchState(array $state): void {
        $current = $this->getBatchState();
        $updated = wp_parse_args($state, $current);
        update_option(self::OPTION_BATCH_STATE, $updated, false);
    }

    /**
     * Clear batch state.
     *
     * @return void
     */
    private function clearBatchState(): void {
        delete_option(self::OPTION_BATCH_STATE);
    }

    /**
     * Schedule the next batch.
     *
     * @param int $lastDocId Last processed document ID.
     * @return void
     */
    private function scheduleNextBatch(int $lastDocId): void {
        as_schedule_single_action(
            time() + 1,
            self::HOOK,
            ['last_doc_id' => $lastDocId],
            'vibe-ai-kb'
        );

        $this->log('debug', 'Next index upsert batch scheduled');
    }

    /**
     * Advance to the next phase (CleanupJob).
     *
     * @return void
     */
    private function advanceToNextPhase(): void {
        CleanupJob::schedule();
        $this->log('info', 'Advancing to cleanup phase');
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handleError(\Throwable $e): void {
        $this->log('error', 'Index upsert phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        do_action('vibe_ai_kb_job_failed', 'index_upsert', $e->getMessage());
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
            vibe_ai_log($level, '[KB/IndexUpsert] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'kb_index_upsert', $level, $message, $context);
    }
}

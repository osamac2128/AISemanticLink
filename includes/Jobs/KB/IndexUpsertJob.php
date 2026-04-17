<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs\KB;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Pipeline\KBPipelineManager;

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
    public static function execute(mixed $lastDocId = 0): void {
        $job = new self();
        $lastDocId = $job->normalizeExecutionArg($lastDocId, 'last_doc_id');

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

        if (get_option('vibe_ai_kb_pipeline_status', 'idle') !== 'running' || (bool) get_option('vibe_ai_kb_pipeline_stop_requested', 0)) {
            $this->log('info', 'Index upsert skipped because pipeline is not running');
            return;
        }

        $docsTable = $wpdb->prefix . Config::TABLE_KB_DOCS;
        $chunksTable = $wpdb->prefix . Config::TABLE_KB_CHUNKS;
        $vectorsTable = $wpdb->prefix . Config::TABLE_KB_VECTORS;
        $manager = KBPipelineManager::get_instance();
        $scope = $this->getScopedDocumentFilter();

        $this->log('info', 'Index upsert phase started', [
            'last_doc_id' => $lastDocId,
        ]);

        // Get documents that are chunked but not yet indexed
        $query = "SELECT id, post_id, chunk_count
            FROM {$docsTable}
            WHERE status = %s
            AND id > %d{$scope['sql']}
            ORDER BY id ASC
            LIMIT %d";
        $docs = $wpdb->get_results($wpdb->prepare(
            $query,
            ...array_merge([Config::KB_STATUS_CHUNKED, $lastDocId], $scope['args'], [self::BATCH_SIZE])
        ));

        if (empty($docs)) {
            $this->log('info', 'Index upsert phase complete - no more documents to verify');
            $this->clearBatchState();
            do_action('vibe_ai_kb_index_upsert_complete');
            return;
        }

        $indexedCount = 0;
        $failedCount = 0;
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
                        'status'          => Config::KB_STATUS_INDEXED,
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
                $wpdb->update(
                    $docsTable,
                    [
                        'status'     => Config::KB_STATUS_ERROR,
                        'updated_at' => current_time('mysql', true),
                    ],
                    ['id' => $docId],
                    ['%s', '%s'],
                    ['%d']
                );

                $failedCount++;

                $this->log('warning', "Document {$docId} has missing vectors", [
                    'post_id'          => $postId,
                    'total_chunks'     => $totalChunks,
                    'vectorized'       => $vectorizedChunks,
                    'missing'          => $totalChunks - $vectorizedChunks,
                ]);

            } else {
                $wpdb->update(
                    $docsTable,
                    [
                        'status'     => Config::KB_STATUS_ERROR,
                        'updated_at' => current_time('mysql', true),
                    ],
                    ['id' => $docId],
                    ['%s', '%s'],
                    ['%d']
                );

                $this->log('error', "Document {$docId} has no chunks", [
                    'post_id'        => $postId,
                    'expected'       => $expectedChunks,
                ]);

                $failedCount++;
            }
        }

        // Update batch state
        $state = $this->getBatchState();
        $this->updateBatchState([
            'last_doc_id'    => $maxDocId,
            'indexed_count'  => ($state['indexed_count'] ?? 0) + $indexedCount,
            'pending_count'  => 0,
            'warning_count'  => ($state['warning_count'] ?? 0) + $failedCount,
        ]);

        $this->log('info', "Processed batch", [
            'docs_checked' => count($docs),
            'indexed'      => $indexedCount,
            'failed'       => $failedCount,
            'last_doc_id'  => $maxDocId,
        ]);

        $manager->recordPhaseProgress($indexedCount, $failedCount, 0);

        // Fire batch action
        do_action('vibe_ai_kb_index_upsert_batch', $indexedCount, $failedCount);

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
     * Normalize Action Scheduler arguments across old and new payload shapes.
     *
     * @param mixed  $value Legacy or direct argument value.
     * @param string $key   Expected associative key.
     * @return int
     */
    private function normalizeExecutionArg(mixed $value, string $key): int {
        if (is_array($value)) {
            $value = $value[$key] ?? 0;
        }

        return (int) $value;
    }

    /**
     * Get the active pipeline's scope filter for document queries.
     *
     * @return array{sql: string, args: array<int, int|string>}
     */
    private function getScopedDocumentFilter(): array {
        $config = KBPipelineManager::get_instance()->getConfig();
        $scope  = (string) ($config['scope'] ?? 'all');

        if ($scope === 'post_id' && !empty($config['post_id'])) {
            return [
                'sql'  => ' AND post_id = %d',
                'args' => [(int) $config['post_id']],
            ];
        }

        if ($scope === 'post_type' && !empty($config['post_type'])) {
            return [
                'sql'  => ' AND post_type = %s',
                'args' => [sanitize_text_field((string) $config['post_type'])],
            ];
        }

        $postTypes = $config['post_types'] ?? [];
        if (is_array($postTypes) && !empty($postTypes)) {
            $sanitized = array_values(array_filter(array_map('sanitize_text_field', $postTypes)));
            if (!empty($sanitized)) {
                return [
                    'sql'  => ' AND post_type IN (' . implode(', ', array_fill(0, count($sanitized), '%s')) . ')',
                    'args' => $sanitized,
                ];
            }
        }

        return [
            'sql'  => '',
            'args' => [],
        ];
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

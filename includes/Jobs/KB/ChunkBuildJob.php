<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs\KB;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Pipeline\KBPipelineManager;
use Vibe\AIIndex\Services\KB\Chunker;
use Vibe\AIIndex\Services\KB\TokenEstimator;
use Vibe\AIIndex\Services\KB\AnchorGenerator;

class ChunkBuildJob {

    public const HOOK = 'vibe_ai_kb_chunk_build';

    public const BATCH_SIZE = 10;

    private const OPTION_BATCH_STATE = 'vibe_ai_kb_chunk_build_state';

    private static ?Chunker $chunker = null;
    private static ?TokenEstimator $tokenEstimator = null;

    /**
     * Schedule the chunk build job.
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
     * Execute the chunk build phase.
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
     * Run the chunk build job.
     *
     * @param int $lastDocId Last processed document ID.
     * @return void
     */
    public function run(int $lastDocId): void {
        global $wpdb;

        if (get_option('vibe_ai_kb_pipeline_status', 'idle') !== 'running' || (bool) get_option('vibe_ai_kb_pipeline_stop_requested', 0)) {
            $this->log('info', 'Chunk build skipped because pipeline is not running');
            return;
        }

        $docsTable = $wpdb->prefix . Config::TABLE_KB_DOCS;
        $chunksTable = $wpdb->prefix . Config::TABLE_KB_CHUNKS;
        $manager = KBPipelineManager::get_instance();
        $scope = $this->getScopedDocumentFilter();

        $this->log('info', 'Chunk build phase started', [
            'last_doc_id' => $lastDocId,
        ]);

        $query = "SELECT d.id, d.post_id, d.title, p.post_content
            FROM {$docsTable} d
            INNER JOIN {$wpdb->posts} p ON d.post_id = p.ID
            WHERE d.status = %s
            AND d.id > %d{$scope['sql']}
            ORDER BY d.id ASC
            LIMIT %d";
        $docs = $wpdb->get_results($wpdb->prepare(
            $query,
            ...array_merge([Config::KB_STATUS_PENDING, $lastDocId], $scope['args'], [self::BATCH_SIZE])
        ));

        if (empty($docs)) {
            $this->log('info', 'Chunk build phase complete - no more pending documents');
            $this->clearBatchState();
            do_action('vibe_ai_kb_chunk_build_complete');
            return;
        }

        $totalChunks = 0;
        $maxDocId = $lastDocId;
        $chunker = $this->getChunker();
        $processedCount = 0;
        $failedCount = 0;

        foreach ($docs as $doc) {
            $docId = (int) $doc->id;
            $postId = (int) $doc->post_id;
            $maxDocId = max($maxDocId, $docId);

            $wpdb->delete($chunksTable, ['doc_id' => $docId], ['%d']);

            $rawHtml = (string) $doc->post_content;
            $content = trim(wp_strip_all_tags($rawHtml));
            if ($content === '') {
                $wpdb->update(
                    $docsTable,
                    ['status' => Config::KB_STATUS_ERROR],
                    ['id' => $docId],
                    ['%s'],
                    ['%d']
                );
                $failedCount++;
                $this->log('warning', "No content available for doc {$docId}");
                continue;
            }

            $headings = [];
            if (preg_match_all('/<(h[1-6])[^>]*>(.*?)<\/\1>/i', $rawHtml, $headingMatches, PREG_SET_ORDER)) {
                foreach ($headingMatches as $hm) {
                    $level = (int) substr($hm[1], 1);
                    $text = trim(wp_strip_all_tags($hm[2]));
                    if ($text !== '') {
                        $headings[] = ['level' => $level, 'text' => $text];
                    }
                }
            }

            $contentHash = hash('sha256', $content);
            $chunks = $chunker->chunk($content, $headings, $postId, $contentHash);

            if (empty($chunks)) {
                $wpdb->update(
                    $docsTable,
                    ['status' => Config::KB_STATUS_ERROR],
                    ['id' => $docId],
                    ['%s'],
                    ['%d']
                );
                $failedCount++;
                $this->log('warning', "No chunks generated for doc {$docId}");
                continue;
            }

            if (!empty($doc->title)) {
                $titlePrefix = '[' . $doc->title . "]\n\n";
                $estimator = $this->getTokenEstimator();
                $chunks = array_map(function (array $c) use ($titlePrefix, $estimator) {
                    $c['chunk_text'] = $titlePrefix . $c['chunk_text'];
                    $c['chunk_hash'] = hash('sha256', $c['chunk_text']);
                    $c['token_estimate'] = $estimator->estimate($c['chunk_text']);
                    return $c;
                }, $chunks);
            }

            foreach ($chunks as $chunkData) {
                $wpdb->insert(
                    $chunksTable,
                    [
                        'doc_id'            => $docId,
                        'chunk_index'       => $chunkData['chunk_index'],
                        'anchor'            => $chunkData['anchor'],
                        'heading_path_json' => wp_json_encode($chunkData['heading_path']),
                        'chunk_text'        => $chunkData['chunk_text'],
                        'chunk_hash'        => $chunkData['chunk_hash'],
                        'start_offset'      => $chunkData['start_offset'],
                        'end_offset'        => $chunkData['end_offset'],
                        'token_estimate'    => $chunkData['token_estimate'],
                    ],
                    ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d']
                );
            }

            $wpdb->update(
                $docsTable,
                [
                    'chunk_count' => count($chunks),
                    'status'      => Config::KB_STATUS_CHUNKED,
                ],
                ['id' => $docId],
                ['%d', '%s'],
                ['%d']
            );

            $processedCount++;
            $totalChunks += count($chunks);

            $this->log('debug', "Generated chunks for doc {$docId}", [
                'post_id'     => $postId,
                'chunk_count' => count($chunks),
            ]);

            do_action('vibe_ai_kb_document_chunked', $docId, count($chunks));
        }

        $this->updateBatchState([
            'last_doc_id'   => $maxDocId,
            'total_chunks'  => ($this->getBatchState()['total_chunks'] ?? 0) + $totalChunks,
            'docs_processed' => ($this->getBatchState()['docs_processed'] ?? 0) + count($docs),
        ]);

        $this->log('info', "Processed batch", [
            'docs_processed' => $processedCount,
            'docs_failed'    => $failedCount,
            'chunks_created' => $totalChunks,
            'last_doc_id'    => $maxDocId,
        ]);

        $manager->recordPhaseProgress($processedCount, $failedCount, 0);

        do_action('vibe_ai_kb_chunk_build_batch', $processedCount, $totalChunks);

        $this->scheduleNextBatch($maxDocId);
    }

    private function getChunker(): Chunker {
        if (self::$chunker === null) {
            self::$tokenEstimator = new TokenEstimator();
            $anchorGenerator = new AnchorGenerator();
            self::$chunker = new Chunker(self::$tokenEstimator, $anchorGenerator);
        }
        return self::$chunker;
    }

    private function getTokenEstimator(): TokenEstimator {
        if (self::$tokenEstimator === null) {
            self::$tokenEstimator = new TokenEstimator();
        }
        return self::$tokenEstimator;
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
                'sql'  => ' AND d.post_id = %d',
                'args' => [(int) $config['post_id']],
            ];
        }

        if ($scope === 'post_type' && !empty($config['post_type'])) {
            return [
                'sql'  => ' AND d.post_type = %s',
                'args' => [sanitize_text_field((string) $config['post_type'])],
            ];
        }

        $postTypes = $config['post_types'] ?? [];
        if (is_array($postTypes) && !empty($postTypes)) {
            $sanitized = array_values(array_filter(array_map('sanitize_text_field', $postTypes)));
            if (!empty($sanitized)) {
                return [
                    'sql'  => ' AND d.post_type IN (' . implode(', ', array_fill(0, count($sanitized), '%s')) . ')',
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
     * Get current batch state.
     *
     * @return array Batch state.
     */
    private function getBatchState(): array {
        return get_option(self::OPTION_BATCH_STATE, [
            'last_doc_id'    => 0,
            'total_chunks'   => 0,
            'docs_processed' => 0,
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

        $this->log('debug', 'Next chunk build batch scheduled');
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handleError(\Throwable $e): void {
        $this->log('error', 'Chunk build phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        do_action('vibe_ai_kb_job_failed', 'chunk_build', $e->getMessage());
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
            vibe_ai_log($level, '[KB/ChunkBuild] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'kb_chunk_build', $level, $message, $context);
    }
}

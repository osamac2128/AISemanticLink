<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs\KB;

/**
 * KB Phase 2: Generate chunks for documents.
 *
 * Loads documents with status='pending', splits content into semantic chunks
 * using configurable chunking strategies, and stores chunks in wp_ai_kb_chunks.
 *
 * @package Vibe\AIIndex\Jobs\KB
 * @since 1.0.0
 */
class ChunkBuildJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_kb_chunk_build';

    /**
     * Default batch size for document processing.
     */
    public const BATCH_SIZE = 10;

    /**
     * Default chunk size in characters.
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Default chunk overlap in characters.
     */
    private const DEFAULT_CHUNK_OVERLAP = 200;

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_kb_chunk_build_state';

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 1);
    }

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
    public static function execute(int $lastDocId = 0): void {
        $job = new self();

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

        $docsTable = $wpdb->prefix . 'ai_kb_docs';
        $chunksTable = $wpdb->prefix . 'ai_kb_chunks';

        $this->log('info', 'Chunk build phase started', [
            'last_doc_id' => $lastDocId,
        ]);

        // Get pending documents
        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id, title, content
             FROM {$docsTable}
             WHERE status = 'pending'
             AND id > %d
             ORDER BY id ASC
             LIMIT %d",
            $lastDocId,
            self::BATCH_SIZE
        ));

        if (empty($docs)) {
            $this->log('info', 'Chunk build phase complete - no more pending documents');
            $this->clearBatchState();
            do_action('vibe_ai_kb_chunk_build_complete');
            $this->advanceToNextPhase();
            return;
        }

        $totalChunks = 0;
        $maxDocId = $lastDocId;

        foreach ($docs as $doc) {
            $docId = (int) $doc->id;
            $postId = (int) $doc->post_id;
            $maxDocId = max($maxDocId, $docId);

            // Delete existing chunks for this doc (in case of re-indexing)
            $wpdb->delete($chunksTable, ['doc_id' => $docId], ['%d']);

            // Generate chunks
            $chunks = $this->generateChunks($doc->content, $doc->title);

            if (empty($chunks)) {
                $this->log('warning', "No chunks generated for doc {$docId}");
                continue;
            }

            // Insert chunks
            $chunkIndex = 0;
            foreach ($chunks as $chunkText) {
                $wpdb->insert(
                    $chunksTable,
                    [
                        'doc_id'      => $docId,
                        'chunk_index' => $chunkIndex,
                        'chunk_text'  => $chunkText,
                        'token_count' => $this->estimateTokenCount($chunkText),
                        'created_at'  => current_time('mysql', true),
                    ],
                    ['%d', '%d', '%s', '%d', '%s']
                );
                $chunkIndex++;
            }

            // Update doc chunk count and status
            $wpdb->update(
                $docsTable,
                [
                    'chunk_count' => count($chunks),
                    'status'      => 'chunked',
                    'updated_at'  => current_time('mysql', true),
                ],
                ['id' => $docId],
                ['%d', '%s', '%s'],
                ['%d']
            );

            $totalChunks += count($chunks);

            $this->log('debug', "Generated chunks for doc {$docId}", [
                'post_id'     => $postId,
                'chunk_count' => count($chunks),
            ]);

            // Fire action for each document chunked
            do_action('vibe_ai_kb_chunk_build_complete', $docId, count($chunks));
        }

        // Update batch state
        $this->updateBatchState([
            'last_doc_id'   => $maxDocId,
            'total_chunks'  => ($this->getBatchState()['total_chunks'] ?? 0) + $totalChunks,
            'docs_processed' => ($this->getBatchState()['docs_processed'] ?? 0) + count($docs),
        ]);

        $this->log('info', "Processed batch", [
            'docs_processed' => count($docs),
            'chunks_created' => $totalChunks,
            'last_doc_id'    => $maxDocId,
        ]);

        // Fire batch action
        do_action('vibe_ai_kb_chunk_build_batch', count($docs), $totalChunks);

        // Schedule next batch
        $this->scheduleNextBatch($maxDocId);
    }

    /**
     * Generate chunks from document content.
     *
     * Uses a sliding window approach with configurable size and overlap
     * to create semantically meaningful chunks.
     *
     * @param string $content Document content.
     * @param string $title   Document title.
     * @return array Array of chunk strings.
     */
    private function generateChunks(string $content, string $title): array {
        $chunkSize = apply_filters('vibe_ai_kb_chunk_size', self::DEFAULT_CHUNK_SIZE);
        $chunkOverlap = apply_filters('vibe_ai_kb_chunk_overlap', self::DEFAULT_CHUNK_OVERLAP);

        // Split content into paragraphs first
        $paragraphs = preg_split('/\n\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($paragraphs)) {
            return [];
        }

        $chunks = [];
        $currentChunk = '';
        $titlePrefix = !empty($title) ? "[{$title}]\n\n" : '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if (empty($paragraph)) {
                continue;
            }

            // If adding this paragraph exceeds chunk size, save current chunk
            if (!empty($currentChunk) && mb_strlen($currentChunk . "\n\n" . $paragraph) > $chunkSize) {
                $chunks[] = $titlePrefix . trim($currentChunk);

                // Start new chunk with overlap from previous
                $overlapText = $this->getOverlapText($currentChunk, $chunkOverlap);
                $currentChunk = $overlapText . "\n\n" . $paragraph;
            } else {
                // Add paragraph to current chunk
                $currentChunk = empty($currentChunk) ? $paragraph : $currentChunk . "\n\n" . $paragraph;
            }

            // Handle very long paragraphs that exceed chunk size
            while (mb_strlen($currentChunk) > $chunkSize * 1.5) {
                $splitPoint = $this->findSplitPoint($currentChunk, $chunkSize);
                $chunks[] = $titlePrefix . trim(mb_substr($currentChunk, 0, $splitPoint));
                $currentChunk = mb_substr($currentChunk, $splitPoint - $chunkOverlap);
            }
        }

        // Add remaining content as final chunk
        if (!empty($currentChunk)) {
            $chunks[] = $titlePrefix . trim($currentChunk);
        }

        // Filter and apply post-processing
        $chunks = array_filter($chunks, function ($chunk) {
            return mb_strlen(trim($chunk)) >= 50; // Minimum chunk length
        });

        return apply_filters('vibe_ai_kb_generated_chunks', array_values($chunks), $content, $title);
    }

    /**
     * Get overlap text from the end of a chunk.
     *
     * @param string $text         Source text.
     * @param int    $overlapSize  Desired overlap size.
     * @return string Overlap text.
     */
    private function getOverlapText(string $text, int $overlapSize): string {
        if (mb_strlen($text) <= $overlapSize) {
            return $text;
        }

        // Try to find a sentence boundary within the overlap region
        $endPortion = mb_substr($text, -$overlapSize * 2);
        $sentenceEnd = mb_strrpos($endPortion, '. ');

        if ($sentenceEnd !== false && $sentenceEnd > mb_strlen($endPortion) / 2) {
            return trim(mb_substr($endPortion, $sentenceEnd + 2));
        }

        // Fall back to word boundary
        $overlap = mb_substr($text, -$overlapSize);
        $wordBoundary = mb_strpos($overlap, ' ');

        if ($wordBoundary !== false) {
            return trim(mb_substr($overlap, $wordBoundary));
        }

        return trim($overlap);
    }

    /**
     * Find a good split point in text.
     *
     * @param string $text      Text to split.
     * @param int    $maxLength Maximum length.
     * @return int Split position.
     */
    private function findSplitPoint(string $text, int $maxLength): int {
        // Try to split at sentence boundary
        $searchRegion = mb_substr($text, $maxLength - 200, 400);

        // Look for sentence endings
        $sentenceEndings = ['. ', '! ', '? ', ".\n", "!\n", "?\n"];
        $bestSplit = $maxLength;

        foreach ($sentenceEndings as $ending) {
            $pos = mb_strpos($searchRegion, $ending);
            if ($pos !== false) {
                $actualPos = $maxLength - 200 + $pos + mb_strlen($ending);
                if ($actualPos <= $maxLength + 100) {
                    $bestSplit = $actualPos;
                    break;
                }
            }
        }

        // Fall back to word boundary
        if ($bestSplit >= $maxLength + 100) {
            $portion = mb_substr($text, 0, $maxLength);
            $lastSpace = mb_strrpos($portion, ' ');
            if ($lastSpace !== false && $lastSpace > $maxLength * 0.7) {
                $bestSplit = $lastSpace + 1;
            }
        }

        return $bestSplit;
    }

    /**
     * Estimate token count for a chunk.
     *
     * Uses a simple approximation of ~4 characters per token.
     *
     * @param string $text Text to estimate.
     * @return int Estimated token count.
     */
    private function estimateTokenCount(string $text): int {
        // Rough estimation: ~4 characters per token for English
        // This is a common approximation used by OpenAI
        return (int) ceil(mb_strlen($text) / 4);
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
     * Advance to the next phase (EmbedChunksJob).
     *
     * @return void
     */
    private function advanceToNextPhase(): void {
        EmbedChunksJob::schedule(0);
        $this->log('info', 'Advancing to embed chunks phase');
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

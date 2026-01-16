<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs\KB;

use Vibe\AIIndex\Config;

/**
 * KB Phase 1: Build document records from posts.
 *
 * Collects publishable posts, normalizes content, computes content hashes,
 * and inserts/updates records in wp_ai_kb_docs. Only processes posts that
 * have changed or are new.
 *
 * @package Vibe\AIIndex\Jobs\KB
 * @since 1.0.0
 */
class DocumentBuildJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_kb_document_build';

    /**
     * Default batch size for post collection.
     */
    public const BATCH_SIZE = 20;

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_kb_doc_build_state';

    /**
     * Meta key for excluded posts.
     */
    private const META_EXCLUDED = '_vibe_ai_kb_excluded';

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 2);
    }

    /**
     * Schedule the document build job.
     *
     * @param array $options Pipeline options.
     * @return void
     */
    public static function schedule(array $options = []): void {
        as_schedule_single_action(
            time(),
            self::HOOK,
            [
                'last_post_id' => 0,
                'options'      => $options,
            ],
            'vibe-ai-kb'
        );
    }

    /**
     * Execute the document build phase.
     *
     * @param int   $lastPostId Last processed post ID.
     * @param array $options    Pipeline options.
     * @return void
     */
    public static function execute(int $lastPostId = 0, array $options = []): void {
        $job = new self();

        try {
            $job->run($lastPostId, $options);
        } catch (\Throwable $e) {
            $job->handleError($e);
        }
    }

    /**
     * Run the document build job.
     *
     * @param int   $lastPostId Last processed post ID.
     * @param array $options    Pipeline options.
     * @return void
     */
    public function run(int $lastPostId, array $options): void {
        global $wpdb;

        $docsTable = $wpdb->prefix . 'ai_kb_docs';

        $this->log('info', 'Document build phase started', [
            'last_post_id' => $lastPostId,
            'options'      => $options,
        ]);

        // Get post types to process
        $postTypes = $options['post_types'] ?? $this->getKBPostTypes();
        $batchSize = $options['batch_size'] ?? self::BATCH_SIZE;

        // Query posts after last processed ID
        $posts = $this->getPublishablePosts($postTypes, $lastPostId, $batchSize);

        if (empty($posts)) {
            $this->log('info', 'Document build phase complete - no more posts');
            $this->clearBatchState();
            do_action('vibe_ai_kb_document_build_complete');
            $this->advanceToNextPhase($options);
            return;
        }

        $processedCount = 0;
        $insertedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $maxPostId = $lastPostId;

        foreach ($posts as $post) {
            $postId = (int) $post->ID;
            $maxPostId = max($maxPostId, $postId);

            // Check if post is excluded
            if ($this->isExcluded($postId)) {
                $skippedCount++;
                $this->log('debug', "Post {$postId} is excluded from KB");
                continue;
            }

            // Normalize content
            $normalizedContent = $this->normalizeContent($post);

            if (empty($normalizedContent)) {
                $skippedCount++;
                $this->log('debug', "Post {$postId} has no content after normalization");
                continue;
            }

            // Compute content hash
            $contentHash = hash('sha256', $normalizedContent);

            // Check if doc exists and hash changed
            $existingDoc = $wpdb->get_row($wpdb->prepare(
                "SELECT id, content_hash FROM {$docsTable} WHERE post_id = %d",
                $postId
            ));

            if ($existingDoc) {
                // Document exists - check if content changed
                if ($existingDoc->content_hash === $contentHash) {
                    // Content unchanged, skip
                    $skippedCount++;
                    continue;
                }

                // Content changed - update
                $wpdb->update(
                    $docsTable,
                    [
                        'title'        => $post->post_title,
                        'content'      => $normalizedContent,
                        'content_hash' => $contentHash,
                        'status'       => 'pending',
                        'chunk_count'  => 0,
                        'updated_at'   => current_time('mysql', true),
                    ],
                    ['id' => $existingDoc->id],
                    ['%s', '%s', '%s', '%s', '%d', '%s'],
                    ['%d']
                );
                $updatedCount++;

                $this->log('debug', "Updated document for post {$postId}");
            } else {
                // New document - insert
                $wpdb->insert(
                    $docsTable,
                    [
                        'post_id'      => $postId,
                        'title'        => $post->post_title,
                        'content'      => $normalizedContent,
                        'content_hash' => $contentHash,
                        'status'       => 'pending',
                        'chunk_count'  => 0,
                        'created_at'   => current_time('mysql', true),
                        'updated_at'   => current_time('mysql', true),
                    ],
                    ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
                );
                $insertedCount++;

                $this->log('debug', "Inserted document for post {$postId}");
            }

            $processedCount++;

            // Fire action for each document
            do_action('vibe_ai_kb_document_built', $postId, $normalizedContent);
        }

        // Update batch state
        $this->updateBatchState([
            'last_post_id'    => $maxPostId,
            'processed_count' => ($this->getBatchState()['processed_count'] ?? 0) + $processedCount,
        ]);

        $this->log('info', "Processed batch", [
            'batch_size'     => count($posts),
            'processed'      => $processedCount,
            'inserted'       => $insertedCount,
            'updated'        => $updatedCount,
            'skipped'        => $skippedCount,
            'last_post_id'   => $maxPostId,
        ]);

        // Fire batch complete action
        do_action('vibe_ai_kb_document_build_batch', $processedCount, $insertedCount, $updatedCount);

        // Schedule next batch
        $this->scheduleNextBatch($maxPostId, $options);
    }

    /**
     * Get post types for KB indexing.
     *
     * @return array Post types.
     */
    private function getKBPostTypes(): array {
        $postTypes = get_option('vibe_ai_kb_post_types', ['post', 'page']);
        return apply_filters('vibe_ai_kb_post_types', $postTypes);
    }

    /**
     * Get publishable posts after the given ID.
     *
     * @param array $postTypes  Post types to query.
     * @param int   $lastPostId Last processed post ID.
     * @param int   $limit      Number of posts to fetch.
     * @return array Post objects.
     */
    private function getPublishablePosts(array $postTypes, int $lastPostId, int $limit): array {
        global $wpdb;

        $postTypesPlaceholder = implode(',', array_fill(0, count($postTypes), '%s'));

        $query = $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type IN ({$postTypesPlaceholder})
             AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            ...array_merge($postTypes, [$lastPostId, $limit])
        );

        return $wpdb->get_results($query);
    }

    /**
     * Check if a post is excluded from KB indexing.
     *
     * @param int $postId Post ID.
     * @return bool True if excluded.
     */
    private function isExcluded(int $postId): bool {
        $excluded = get_post_meta($postId, self::META_EXCLUDED, true);
        return !empty($excluded);
    }

    /**
     * Normalize post content for indexing.
     *
     * @param object $post Post object.
     * @return string Normalized content.
     */
    private function normalizeContent(object $post): string {
        $content = $post->post_content;

        // Strip shortcodes
        $content = strip_shortcodes($content);

        // Strip Gutenberg block comments
        $content = preg_replace('/<!--\s*\/?wp:[^>]*-->/s', '', $content);

        // Remove script and style tags with content
        $content = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $content);

        // Replace common block elements with newlines
        $content = preg_replace('/<(p|div|h[1-6]|li|br|tr)[^>]*>/i', "\n", $content);

        // Strip remaining HTML tags
        $content = wp_strip_all_tags($content);

        // Decode HTML entities
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n\s*\n/', "\n\n", $content);
        $content = trim($content);

        // Prepend title
        $fullText = $post->post_title . "\n\n" . $content;

        // Apply filter for custom normalization
        return apply_filters('vibe_ai_kb_normalize_content', $fullText, $post);
    }

    /**
     * Get current batch state.
     *
     * @return array Batch state.
     */
    private function getBatchState(): array {
        return get_option(self::OPTION_BATCH_STATE, [
            'last_post_id'    => 0,
            'processed_count' => 0,
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
     * @param int   $lastPostId Last processed post ID.
     * @param array $options    Pipeline options.
     * @return void
     */
    private function scheduleNextBatch(int $lastPostId, array $options): void {
        as_schedule_single_action(
            time() + 1,
            self::HOOK,
            [
                'last_post_id' => $lastPostId,
                'options'      => $options,
            ],
            'vibe-ai-kb'
        );

        $this->log('debug', 'Next document build batch scheduled');
    }

    /**
     * Advance to the next phase (ChunkBuildJob).
     *
     * @param array $options Pipeline options.
     * @return void
     */
    private function advanceToNextPhase(array $options): void {
        ChunkBuildJob::schedule(0);
        $this->log('info', 'Advancing to chunk build phase');
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handleError(\Throwable $e): void {
        $this->log('error', 'Document build phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        do_action('vibe_ai_kb_job_failed', 'document_build', $e->getMessage());
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
            vibe_ai_log($level, '[KB/DocumentBuild] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'kb_document_build', $level, $message, $context);
    }
}

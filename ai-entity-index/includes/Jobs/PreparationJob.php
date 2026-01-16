<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs;

use Vibe\AIIndex\Pipeline\PipelineManager;

/**
 * PreparationJob: Phase 1 of the AI Entity extraction pipeline.
 *
 * Collects publishable post IDs, strips HTML from content to plain text,
 * and queues posts for extraction in Phase 2.
 *
 * @package Vibe\AIIndex\Jobs
 */
class PreparationJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_phase_preparation';

    /**
     * Default batch size for post collection.
     */
    private const DEFAULT_BATCH_SIZE = 100;

    /**
     * Meta key for tracking extraction status.
     */
    private const META_EXTRACTED = '_vibe_ai_extracted_at';

    /**
     * Option key for storing queued post IDs.
     */
    private const OPTION_QUEUED_POSTS = 'vibe_ai_queued_posts';

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 1);
    }

    /**
     * Execute the preparation phase.
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
     * Run the preparation job.
     *
     * @param array $config Pipeline configuration.
     * @return void
     */
    public function run(array $config): void {
        $pipeline = PipelineManager::get_instance();

        $this->log('info', 'Preparation phase started', ['config' => $config]);

        // Get post types to process
        $post_types = $config['post_types'] ?? ['post', 'page'];
        $force_reprocess = $config['force_reprocess'] ?? false;

        // Collect all publishable post IDs
        $post_ids = $this->collect_post_ids($post_types, $force_reprocess);

        if (empty($post_ids)) {
            $this->log('info', 'No posts to process');
            $pipeline->update_progress([
                'total' => 0,
                'phase' => ['total' => 0, 'completed' => 0],
            ]);
            do_action('vibe_ai_phase_preparation_complete');
            return;
        }

        $total_posts = count($post_ids);
        $this->log('info', "Found {$total_posts} posts to process");

        // Update pipeline progress
        $pipeline->update_progress([
            'total' => $total_posts,
            'phase' => [
                'total'     => $total_posts,
                'completed' => 0,
                'failed'    => 0,
            ],
        ]);

        // Process posts in batches - strip HTML and prepare content
        $batch_size = $config['batch_size'] ?? self::DEFAULT_BATCH_SIZE;
        $prepared_posts = [];
        $completed = 0;

        foreach (array_chunk($post_ids, $batch_size) as $batch) {
            foreach ($batch as $post_id) {
                $prepared = $this->prepare_post($post_id);

                if ($prepared !== null) {
                    $prepared_posts[] = $prepared;
                    $completed++;
                } else {
                    $pipeline->increment_progress('skipped');
                }

                // Update progress periodically
                if ($completed % 10 === 0) {
                    $pipeline->update_progress([
                        'phase' => [
                            'total'     => $total_posts,
                            'completed' => $completed,
                        ],
                    ]);
                }
            }
        }

        // Store prepared posts for extraction phase
        $this->store_queued_posts($prepared_posts);

        // Calculate batch information for extraction
        $extraction_batch_size = $config['batch_size'] ?? 5;
        $total_batches = (int) ceil(count($prepared_posts) / $extraction_batch_size);

        $pipeline->update_progress([
            'total'         => count($prepared_posts),
            'total_batches' => $total_batches,
            'phase'         => [
                'total'     => $total_posts,
                'completed' => $completed,
            ],
        ]);

        $this->log('info', 'Preparation phase complete', [
            'total_posts'     => $total_posts,
            'prepared_posts'  => count($prepared_posts),
            'total_batches'   => $total_batches,
        ]);

        // Fire completion action
        do_action('vibe_ai_phase_preparation_complete');
    }

    /**
     * Collect publishable post IDs.
     *
     * @param array $post_types Post types to collect.
     * @param bool  $force_reprocess Whether to include already processed posts.
     * @return array Post IDs.
     */
    private function collect_post_ids(array $post_types, bool $force_reprocess): array {
        global $wpdb;

        $post_types_placeholder = implode(',', array_fill(0, count($post_types), '%s'));

        if ($force_reprocess) {
            // Get all published posts
            $query = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                 AND post_type IN ({$post_types_placeholder})
                 ORDER BY ID ASC",
                ...$post_types
            );
        } else {
            // Get only posts not yet extracted
            $query = $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_status = 'publish'
                 AND p.post_type IN ({$post_types_placeholder})
                 AND pm.meta_value IS NULL
                 ORDER BY p.ID ASC",
                self::META_EXTRACTED,
                ...$post_types
            );
        }

        return $wpdb->get_col($query);
    }

    /**
     * Prepare a single post for extraction.
     *
     * @param int $post_id Post ID.
     * @return array|null Prepared post data or null if should be skipped.
     */
    private function prepare_post(int $post_id): ?array {
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return null;
        }

        // Get raw content
        $content = $post->post_content;

        // Strip shortcodes
        $content = strip_shortcodes($content);

        // Strip HTML blocks (Gutenberg)
        $content = $this->strip_gutenberg_blocks($content);

        // Convert HTML to plain text
        $plain_text = $this->html_to_plain_text($content);

        // Skip if content is too short (less than 50 characters)
        if (mb_strlen($plain_text) < 50) {
            $this->log('debug', "Post {$post_id} skipped: content too short");
            return null;
        }

        // Combine title and content
        $full_text = $post->post_title . "\n\n" . $plain_text;

        // Truncate to reasonable length for AI processing (max 10000 chars)
        $max_length = apply_filters('vibe_ai_max_content_length', 10000);
        if (mb_strlen($full_text) > $max_length) {
            $full_text = mb_substr($full_text, 0, $max_length) . '...';
        }

        return [
            'post_id'     => $post_id,
            'title'       => $post->post_title,
            'content'     => $full_text,
            'char_count'  => mb_strlen($full_text),
            'prepared_at' => current_time('mysql', true),
        ];
    }

    /**
     * Strip Gutenberg block comments and unwanted blocks.
     *
     * @param string $content Content with blocks.
     * @return string Cleaned content.
     */
    private function strip_gutenberg_blocks(string $content): string {
        // Remove block comments
        $content = preg_replace('/<!--\s*\/?wp:[^>]*-->/s', '', $content);

        // Remove specific blocks that shouldn't be processed
        $blocks_to_remove = [
            'wp:html',
            'wp:shortcode',
            'wp:embed',
            'wp:video',
            'wp:audio',
            'wp:file',
            'wp:code',
        ];

        foreach ($blocks_to_remove as $block) {
            $pattern = '/<!--\s*' . preg_quote($block, '/') . '.*?-->.*?<!--\s*\/' . preg_quote($block, '/') . '\s*-->/s';
            $content = preg_replace($pattern, '', $content);
        }

        return $content;
    }

    /**
     * Convert HTML content to plain text.
     *
     * @param string $html HTML content.
     * @return string Plain text.
     */
    private function html_to_plain_text(string $html): string {
        // Replace common block elements with newlines
        $html = preg_replace('/<(p|div|h[1-6]|li|br|tr)[^>]*>/i', "\n", $html);

        // Remove script and style tags with content
        $html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html);

        // Strip remaining HTML tags
        $text = wp_strip_all_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Store queued posts for extraction phase.
     *
     * @param array $posts Prepared post data.
     * @return void
     */
    private function store_queued_posts(array $posts): void {
        update_option(self::OPTION_QUEUED_POSTS, $posts, false);
    }

    /**
     * Get queued posts for extraction.
     *
     * @return array Prepared post data.
     */
    public static function get_queued_posts(): array {
        return get_option(self::OPTION_QUEUED_POSTS, []);
    }

    /**
     * Clear queued posts.
     *
     * @return void
     */
    public static function clear_queued_posts(): void {
        delete_option(self::OPTION_QUEUED_POSTS);
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handle_error(\Throwable $e): void {
        $this->log('error', 'Preparation phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        PipelineManager::get_instance()->fail(
            'Preparation phase failed: ' . $e->getMessage(),
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
            vibe_ai_log($level, '[Preparation] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'preparation', $level, $message, $context);
    }
}

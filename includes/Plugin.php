<?php
/**
 * Main plugin class
 *
 * @package Vibe\AIIndex
 */

declare(strict_types=1);

namespace Vibe\AIIndex;

/**
 * Main plugin class that initializes all components and registers hooks.
 */
class Plugin
{
    /** @var string Plugin version */
    private string $version;

    /** @var string Plugin text domain */
    private string $textDomain = 'ai-entity-index';

    /** @var Logger Logger instance */
    private Logger $logger;

    /**
     * Initialize the plugin.
     */
    public function __construct()
    {
        $this->version = VIBE_AI_VERSION;
        $this->logger = new Logger();
    }

    /**
     * Run the plugin - register all hooks and initialize components.
     *
     * @return void
     */
    public function run(): void
    {
        $this->logger->info('Plugin initialization started', ['version' => $this->version]);

        // Check for database upgrades
        Activator::maybeUpgrade();
        $this->logger->debug('Database upgrade check completed');

        // Initialize Services
        $adminRenderer = new \Vibe\AIIndex\Admin\AdminRenderer($this->version);
        $adminRenderer->register();
        $this->logger->debug('Admin renderer registered');

        // Register REST API routes
        $this->registerRestRoutes();

        // Register Core Hooks
        $this->registerPublicHooks();
        $this->logger->debug('Public hooks registered');

        $this->registerActionSchedulerHooks();
        $this->logger->debug('Action Scheduler hooks registered');

        $this->registerEntityHooks();
        $this->logger->debug('Entity hooks registered');

        // Register KB hooks
        $this->registerKBHooks();
        $this->logger->debug('KB hooks registered');

        $this->logger->info('Plugin initialization completed successfully');
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    private function registerRestRoutes(): void
    {
        // Register REST API routes on the proper hook
        add_action('rest_api_init', function () {
            $this->logger->debug('REST API init hook triggered');

            // Main REST controller (entities, pipeline, logs, status)
            $restController = new \Vibe\AIIndex\REST\RestController();
            $restController->register_routes();
            $this->logger->debug('RestController routes registered');

            // Knowledge Base REST controller
            $kbController = new \Vibe\AIIndex\REST\KBController();
            $kbController->register_routes();
            $this->logger->debug('KBController routes registered');
        });
    }

    /**
     * Register public-facing hooks.
     *
     * @return void
     */
    private function registerPublicHooks(): void
    {
        // Inject Schema.org JSON-LD into head
        add_action('wp_head', [$this, 'injectSchema'], 1);

        // Public AI publishing endpoints.
        add_action('init', [$this, 'registerAIPublishingRoutes']);
        add_filter('query_vars', [$this, 'registerAIPublishingQueryVars']);
        add_action('template_redirect', [$this, 'handleAIPublishingRequest']);
    }

    /**
     * Register Action Scheduler hooks.
     *
     * @return void
     */
    private function registerActionSchedulerHooks(): void
    {
        // Entity pipeline phase jobs — wired directly to Job class methods
        add_action(\Vibe\AIIndex\Jobs\PreparationJob::HOOK, [\Vibe\AIIndex\Jobs\PreparationJob::class, 'execute'], 10, 1);
        add_action(\Vibe\AIIndex\Jobs\ExtractionJob::HOOK, [\Vibe\AIIndex\Jobs\ExtractionJob::class, 'execute'], 10, 1);
        add_action(\Vibe\AIIndex\Jobs\DeduplicationJob::HOOK, [\Vibe\AIIndex\Jobs\DeduplicationJob::class, 'execute'], 10, 1);
        add_action(\Vibe\AIIndex\Jobs\LinkingJob::HOOK, [\Vibe\AIIndex\Jobs\LinkingJob::class, 'execute'], 10, 1);
        add_action(\Vibe\AIIndex\Jobs\IndexingJob::HOOK, [\Vibe\AIIndex\Jobs\IndexingJob::class, 'execute'], 10, 1);
        add_action(\Vibe\AIIndex\Jobs\SchemaBuildJob::HOOK, [\Vibe\AIIndex\Jobs\SchemaBuildJob::class, 'execute'], 10, 1);

        // Entity propagation job
        add_action(\Vibe\AIIndex\Jobs\PropagateEntityChangeJob::HOOK, [\Vibe\AIIndex\Jobs\PropagateEntityChangeJob::class, 'execute'], 10, 2);

        // Daily cleanup
        add_action('vibe_ai_daily_cleanup', [$this, 'dailyCleanup']);
    }

    /**
     * Register entity-related hooks and filters.
     *
     * @return void
     */
    private function registerEntityHooks(): void
    {
        // Post save hook for triggering extraction
        add_action('save_post', [$this, 'onPostSave'], 10, 3);

        // Post delete hook for cleaning up mentions
        add_action('before_delete_post', [$this, 'onPostDelete']);

        // Entity update hook for triggering propagation
        add_action('vibe_ai_entity_updated', [$this, 'onEntityUpdated'], 10, 2);

        // Filters
        add_filter('vibe_ai_post_types', [$this, 'filterPostTypes']);
        add_filter('vibe_ai_schema_post_types', [$this, 'filterPostTypes']);
        add_filter('vibe_ai_confidence_threshold', [$this, 'filterConfidenceThreshold']);
    }

    /**
     * Inject Schema.org JSON-LD into page head.
     *
     * @return void
     */
    public function injectSchema(): void
    {
        if (!is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        $schema = get_post_meta($post_id, Config::META_SCHEMA_CACHE, true);

        if (empty($schema)) {
            return;
        }

        $decoded = json_decode((string) $schema, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return;
        }

        $safe_json = wp_json_encode(
            $decoded,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        if ($safe_json === false) {
            return;
        }

        printf('<script type="application/ld+json">%s</script>' . "\n", $safe_json);
    }

    /**
     * Handle post save for triggering extraction.
     *
     * @param int      $post_id Post ID
     * @param \WP_Post $post    Post object
     * @param bool     $update  Whether this is an update
     * @return void
     */
    public function onPostSave(int $post_id, \WP_Post $post, bool $update): void
    {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Check if post type should be processed
        $post_types = apply_filters('vibe_ai_post_types', Config::DEFAULT_POST_TYPES);
        if (!in_array($post->post_type, $post_types, true)) {
            return;
        }

        // Only process published posts
        if ($post->post_status !== 'publish') {
            return;
        }

        // Mark post for extraction (will be picked up by pipeline)
        update_post_meta($post_id, '_vibe_ai_needs_extraction', true);

        // Invalidate schema cache on content changes.
        delete_post_meta($post_id, Config::META_SCHEMA_CACHE);
        delete_post_meta($post_id, Config::META_SCHEMA_VERSION);

        $this->logger->debug('Post marked for extraction', ['post_id' => $post_id]);
    }

    /**
     * Handle post deletion - clean up mentions.
     *
     * @param int $post_id Post ID being deleted
     * @return void
     */
    public function onPostDelete(int $post_id): void
    {
        global $wpdb;

        $mentions_table = $wpdb->prefix . Config::TABLE_MENTIONS;

        // Foreign key will handle cascade delete, but we log it
        $this->logger->debug('Post deleted, mentions will be cleaned up', ['post_id' => $post_id]);
    }

    /**
     * Handle entity update - trigger propagation if needed.
     *
     * @param int   $entity_id Entity ID
     * @param array $changes   Array of changed fields
     * @return void
     */
    public function onEntityUpdated(int $entity_id, array $changes): void
    {
        $propagation_triggers = ['name', 'schema_type', 'same_as_url', 'wikidata_id'];

        if (array_intersect($propagation_triggers, array_keys($changes))) {
            // Schedule propagation job
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(
                    time(),
                    'vibe_ai_propagate_entity',
                    ['entity_id' => $entity_id, 'last_post_id' => 0],
                    'vibe-ai-index'
                );

                $this->logger->info('Entity propagation scheduled', [
                    'entity_id' => $entity_id,
                    'changes' => array_keys($changes),
                ]);
            }
        }
    }

    /**
     * Daily cleanup task.
     *
     * @return void
     */
    public function dailyCleanup(): void
    {
        // Clean up old log files (keep last 30 days)
        $this->logger->cleanup(30);

        // Clean up orphaned data
        $this->cleanupOrphanedData();

        $this->logger->info('Daily cleanup completed');
    }

    /**
     * Clean up orphaned data.
     *
     * @return void
     */
    private function cleanupOrphanedData(): void
    {
        global $wpdb;

        // Remove transients for entities that no longer exist
        $entities_table = $wpdb->prefix . Config::TABLE_ENTITIES;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_vibe_ai_propagating_%'
             AND CAST(SUBSTRING(option_name, 28) AS UNSIGNED) NOT IN (
                 SELECT id FROM {$entities_table}
             )"
        );
    }

    /**
     * Filter: Modify processable post types.
     *
     * @param array $post_types Current post types
     * @return array Modified post types
     */
    public function filterPostTypes(array $post_types): array
    {
        $saved = get_option('vibe_ai_post_types', Config::DEFAULT_POST_TYPES);
        return is_array($saved) ? $saved : $post_types;
    }

    /**
     * Filter: Modify confidence threshold.
     *
     * @param float $threshold Current threshold
     * @return float Modified threshold
     */
    public function filterConfidenceThreshold(float $threshold): float
    {
        $saved = get_option('vibe_ai_confidence_threshold', Config::SCHEMA_MIN_CONFIDENCE);
        return is_numeric($saved) ? (float) $saved : $threshold;
    }

    /**
     * Get the logger instance.
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    // =================================================================
    // Knowledge Base Integration
    // =================================================================

    /**
     * Register KB-specific hooks.
     */
    private function registerKBHooks(): void
    {
        // Only register if KB is enabled
        if (!Config::isKBEnabled()) {
            return;
        }

        // Bootstrap the singleton so KB-specific hooks are registered exactly once.
        $this->getKBPipelineManager();

        // Schedule KB reindex on post save
        add_action('save_post', [$this, 'scheduleKBReindex'], 20, 3);

        // Remove from KB on post delete
        add_action('before_delete_post', [$this, 'removeFromKB']);

        // Exclude from KB on trash
        add_action('wp_trash_post', [$this, 'excludeFromKB']);

        // Restore to KB on untrash
        add_action('untrash_post', [$this, 'includeInKB']);

        // Register KB Action Scheduler hooks
        $this->registerKBJobHooks();
    }

    /**
     * Schedule KB reindex for a post on save.
     */
    public function scheduleKBReindex(int $postId, \WP_Post $post, bool $update): void
    {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        // Skip if not a supported post type
        $kbPostTypes = apply_filters('vibe_ai_kb_post_types', Config::getKBPostTypes());
        if (!in_array($post->post_type, $kbPostTypes, true)) {
            return;
        }

        // Skip if post is not published
        if ($post->post_status !== 'publish') {
            return;
        }

        // Skip if explicitly excluded
        if (get_post_meta($postId, Config::KB_META_EXCLUDED, true)) {
            return;
        }

        // Schedule reindex via KBPipelineManager
        $kbManager = $this->getKBPipelineManager();
        $kbManager->schedulePost($postId);

        do_action('vibe_ai_kb_post_scheduled', $postId);
    }

    /**
     * Remove post from KB on delete.
     */
    public function removeFromKB(int $postId): void
    {
        $docRepo = new \Vibe\AIIndex\Repositories\KB\DocumentRepository();
        $docRepo->deleteByPostId($postId);

        do_action('vibe_ai_kb_post_removed', $postId);
    }

    /**
     * Mark post as excluded from KB on trash.
     */
    public function excludeFromKB(int $postId): void
    {
        update_post_meta($postId, Config::KB_META_EXCLUDED, '1');

        $docRepo = new \Vibe\AIIndex\Repositories\KB\DocumentRepository();
        $doc = $docRepo->findByPostId($postId);
        if ($doc) {
            $docRepo->setStatus($doc->id, Config::KB_STATUS_EXCLUDED);
        }

        do_action('vibe_ai_kb_post_excluded', $postId);
    }

    /**
     * Re-include post in KB on untrash.
     */
    public function includeInKB(int $postId): void
    {
        delete_post_meta($postId, Config::KB_META_EXCLUDED);

        // Schedule for reindex
        $kbManager = $this->getKBPipelineManager();
        $kbManager->schedulePost($postId);

        do_action('vibe_ai_kb_post_included', $postId);
    }

    /**
     * Register KB REST API routes.
     */
    public function registerKBRoutes(): void
    {
        $controller = new \Vibe\AIIndex\REST\KBController();
        $controller->register_routes();
    }

    /**
     * Register KB job hooks with Action Scheduler.
     */
    private function registerKBJobHooks(): void
    {
        add_action(
            \Vibe\AIIndex\Jobs\KB\DocumentBuildJob::HOOK,
            [\Vibe\AIIndex\Jobs\KB\DocumentBuildJob::class, 'execute'],
            10,
            2
        );

        add_action(
            \Vibe\AIIndex\Jobs\KB\ChunkBuildJob::HOOK,
            [\Vibe\AIIndex\Jobs\KB\ChunkBuildJob::class, 'execute'],
            10,
            1
        );

        add_action(
            \Vibe\AIIndex\Jobs\KB\EmbedChunksJob::HOOK,
            [\Vibe\AIIndex\Jobs\KB\EmbedChunksJob::class, 'execute'],
            10,
            1
        );

        add_action(
            \Vibe\AIIndex\Jobs\KB\IndexUpsertJob::HOOK,
            [\Vibe\AIIndex\Jobs\KB\IndexUpsertJob::class, 'execute'],
            10,
            1
        );

        add_action(
            \Vibe\AIIndex\Jobs\KB\CleanupJob::HOOK,
            [\Vibe\AIIndex\Jobs\KB\CleanupJob::class, 'execute'],
            10,
            0
        );
    }

    /**
     * Register public AI publishing rewrite rules.
     *
     * @return void
     */
    public function registerAIPublishingRoutes(): void
    {
        add_rewrite_rule('^llms\.txt$', 'index.php?vibe_ai_public_asset=llms', 'top');
        add_rewrite_rule('^ai-sitemap/?$', 'index.php?vibe_ai_public_asset=sitemap', 'top');
        add_rewrite_rule('^ai-sitemap\.xml$', 'index.php?vibe_ai_public_asset=sitemap_xml', 'top');
        add_rewrite_rule('^ai-sitemap\.json$', 'index.php?vibe_ai_public_asset=sitemap_json', 'top');
        add_rewrite_rule('^changes/?$', 'index.php?vibe_ai_public_asset=changes', 'top');
    }

    /**
     * Register public AI publishing query vars.
     *
     * @param array $vars Existing query vars.
     * @return array Updated query vars.
     */
    public function registerAIPublishingQueryVars(array $vars): array
    {
        $vars[] = 'vibe_ai_public_asset';
        return $vars;
    }

    /**
     * Handle public AI publishing requests.
     *
     * @return void
     */
    public function handleAIPublishingRequest(): void
    {
        $asset = (string) get_query_var('vibe_ai_public_asset', '');

        if ($asset === '') {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        switch ($asset) {
            case 'llms':
                $generator = new \Vibe\AIIndex\Services\KB\LlmsTxtGenerator();
                $content = $generator->generate();
                $etag = md5($content);
                if ($this->sendNotModifiedIfMatch($etag, 'text/plain; charset=utf-8', 3600)) {
                    return;
                }

                echo $content;
                exit;

            case 'sitemap':
            case 'sitemap_json':
                $generator = new \Vibe\AIIndex\Services\KB\AISitemapGenerator();
                $data = $generator->generateJSON();
                $etag = (string) ($data['content_hash'] ?? md5(wp_json_encode($data)));
                if ($this->sendNotModifiedIfMatch($etag, 'application/json; charset=utf-8', 3600)) {
                    return;
                }

                echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            case 'sitemap_xml':
                $generator = new \Vibe\AIIndex\Services\KB\AISitemapGenerator();
                $content = $generator->generateXML();
                $etag = md5($content);
                if ($this->sendNotModifiedIfMatch($etag, 'application/xml; charset=utf-8', 3600)) {
                    return;
                }

                echo $content;
                exit;

            case 'changes':
                $generator = new \Vibe\AIIndex\Services\KB\ChangeFeedGenerator();
                $result = $generator->getConditionalResponse(
                    isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : null
                );

                status_header((int) $result['status_code']);
                foreach ($result['headers'] as $header => $value) {
                    header($header . ': ' . $value);
                }

                header('Content-Type: application/json; charset=utf-8');
                header('X-Robots-Tag: noindex, follow');

                if ((int) $result['status_code'] === 304) {
                    exit;
                }

                echo wp_json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
        }
    }

    /**
     * Send shared cache/etag headers and short-circuit on conditional GET matches.
     *
     * @param string $etag Cache validator.
     * @param string $contentType Response content type.
     * @param int    $maxAge Cache TTL in seconds.
     * @return bool True when a 304 response was sent.
     */
    private function sendNotModifiedIfMatch(string $etag, string $contentType, int $maxAge): bool
    {
        header('Content-Type: ' . $contentType);
        header('X-Robots-Tag: noindex, follow');
        header('Cache-Control: public, max-age=' . $maxAge);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('ETag: "' . $etag . '"');

        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $clientEtag = trim((string) $_SERVER['HTTP_IF_NONE_MATCH'], '"');
            if ($clientEtag === $etag) {
                status_header(304);
                exit;
            }
        }

        return false;
    }

    /**
     * Get the singleton KB pipeline manager.
     *
     * @return \Vibe\AIIndex\Pipeline\KBPipelineManager
     */
    private function getKBPipelineManager(): \Vibe\AIIndex\Pipeline\KBPipelineManager
    {
        return \Vibe\AIIndex\Pipeline\KBPipelineManager::get_instance();
    }
}

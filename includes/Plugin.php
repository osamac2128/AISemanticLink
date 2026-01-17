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
    }

    /**
     * Register Action Scheduler hooks.
     *
     * @return void
     */
    private function registerActionSchedulerHooks(): void
    {
        // Pipeline processing hooks
        add_action('vibe_ai_process_batch', [$this, 'processBatch'], 10, 2);
        add_action('vibe_ai_propagate_entity', [$this, 'propagateEntityChange'], 10, 2);
        add_action('vibe_ai_generate_schema', [$this, 'generateSchema'], 10, 1);

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

        // Output pre-sanitized JSON-LD
        printf(
            '<script type="application/ld+json">%s</script>' . "\n",
            $schema
        );
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
                    ['entity_id' => $entity_id, 'last_post_id' => 0]
                );

                $this->logger->info('Entity propagation scheduled', [
                    'entity_id' => $entity_id,
                    'changes' => array_keys($changes),
                ]);
            }
        }
    }

    /**
     * Action Scheduler callback: Process a batch of posts.
     *
     * @param string $phase      Pipeline phase
     * @param array  $batch_data Batch data
     * @return void
     */
    public function processBatch(string $phase, array $batch_data): void
    {
        // Placeholder - will be implemented by PipelineManager
        $this->logger->debug('Processing batch', ['phase' => $phase, 'batch' => $batch_data]);
    }

    /**
     * Action Scheduler callback: Propagate entity changes.
     *
     * @param int $entity_id   Entity ID
     * @param int $last_post_id Last processed post ID
     * @return void
     */
    public function propagateEntityChange(int $entity_id, int $last_post_id): void
    {
        // Placeholder - will be implemented by PropagateEntityChangeJob
        $this->logger->debug('Propagating entity change', [
            'entity_id' => $entity_id,
            'last_post_id' => $last_post_id,
        ]);
    }

    /**
     * Action Scheduler callback: Generate schema for a post.
     *
     * @param int $post_id Post ID
     * @return void
     */
    public function generateSchema(int $post_id): void
    {
        // Placeholder - will be implemented by SchemaGenerator
        $this->logger->debug('Generating schema', ['post_id' => $post_id]);
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

        // Schedule KB reindex on post save
        add_action('save_post', [$this, 'scheduleKBReindex'], 20, 3);

        // Remove from KB on post delete
        add_action('before_delete_post', [$this, 'removeFromKB']);

        // Exclude from KB on trash
        add_action('wp_trash_post', [$this, 'excludeFromKB']);

        // Restore to KB on untrash
        add_action('untrash_post', [$this, 'includeInKB']);

        // Register KB REST routes
        add_action('rest_api_init', [$this, 'registerKBRoutes']);

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
        $kbManager = new \Vibe\AIIndex\Pipeline\KBPipelineManager();
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
        $kbManager = new \Vibe\AIIndex\Pipeline\KBPipelineManager();
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
}

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
        // Check for database upgrades
        Activator::maybeUpgrade();

        // Register hooks
        $this->registerAdminHooks();
        $this->registerPublicHooks();
        $this->registerRestApi();
        $this->registerActionSchedulerHooks();
        $this->registerEntityHooks();
    }

    /**
     * Register admin-specific hooks.
     *
     * @return void
     */
    private function registerAdminHooks(): void
    {
        // Admin menu
        add_action('admin_menu', [$this, 'registerAdminMenu']);

        // Admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // Admin notices
        add_action('admin_notices', [$this, 'displayAdminNotices']);

        // AJAX handlers for admin
        add_action('wp_ajax_vibe_ai_dismiss_notice', [$this, 'dismissNotice']);
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
     * Register REST API endpoints.
     *
     * @return void
     */
    private function registerRestApi(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
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
     * Register the admin menu page.
     *
     * @return void
     */
    public function registerAdminMenu(): void
    {
        add_menu_page(
            __('AI Entity Index', $this->textDomain),
            __('AI Entity Index', $this->textDomain),
            Config::REQUIRED_CAPABILITY,
            'vibe-ai-index',
            [$this, 'renderAdminPage'],
            'dashicons-networking',
            30
        );

        // Submenus
        add_submenu_page(
            'vibe-ai-index',
            __('Dashboard', $this->textDomain),
            __('Dashboard', $this->textDomain),
            Config::REQUIRED_CAPABILITY,
            'vibe-ai-index',
            [$this, 'renderAdminPage']
        );

        add_submenu_page(
            'vibe-ai-index',
            __('Entities', $this->textDomain),
            __('Entities', $this->textDomain),
            Config::REQUIRED_CAPABILITY,
            'vibe-ai-index#/entities',
            [$this, 'renderAdminPage']
        );

        add_submenu_page(
            'vibe-ai-index',
            __('Settings', $this->textDomain),
            __('Settings', $this->textDomain),
            Config::REQUIRED_CAPABILITY,
            'vibe-ai-index#/settings',
            [$this, 'renderAdminPage']
        );

        add_submenu_page(
            'vibe-ai-index',
            __('Logs', $this->textDomain),
            __('Logs', $this->textDomain),
            Config::REQUIRED_CAPABILITY,
            'vibe-ai-index#/logs',
            [$this, 'renderAdminPage']
        );
    }

    /**
     * Render the admin page (React SPA container).
     *
     * @return void
     */
    public function renderAdminPage(): void
    {
        // Security check
        if (!current_user_can(Config::REQUIRED_CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to access this page.', $this->textDomain));
        }

        echo '<div id="vibe-ai-admin-root" class="wrap"></div>';
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hook The current admin page hook
     * @return void
     */
    public function enqueueAdminAssets(string $hook): void
    {
        // Only load on our admin pages
        if (strpos($hook, 'vibe-ai-index') === false) {
            return;
        }

        $asset_file = VIBE_AI_PLUGIN_DIR . 'build/index.asset.php';
        $asset = file_exists($asset_file)
            ? require $asset_file
            : ['dependencies' => [], 'version' => $this->version];

        // Enqueue React app
        wp_enqueue_script(
            'vibe-ai-admin',
            VIBE_AI_PLUGIN_URL . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'vibe-ai-admin',
            VIBE_AI_PLUGIN_URL . 'build/index.css',
            [],
            $asset['version']
        );

        // Localize script with necessary data
        wp_localize_script('vibe-ai-admin', 'vibeAiConfig', [
            'apiUrl' => rest_url(Config::REST_NAMESPACE),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => $this->version,
            'pollingInterval' => Config::POLLING_INTERVAL_MS,
            'adminUrl' => admin_url('admin.php?page=vibe-ai-index'),
        ]);
    }

    /**
     * Display admin notices.
     *
     * @return void
     */
    public function displayAdminNotices(): void
    {
        // Check if API key is configured
        if (!defined('VIBE_AI_OPENROUTER_KEY') || empty(VIBE_AI_OPENROUTER_KEY)) {
            $dismissed = get_option('vibe_ai_api_key_notice_dismissed', false);

            if (!$dismissed) {
                echo '<div class="notice notice-warning is-dismissible" data-notice="vibe_ai_api_key">';
                echo '<p><strong>' . esc_html__('AI Entity Index:', $this->textDomain) . '</strong> ';
                echo esc_html__('Please configure your OpenRouter API key in wp-config.php to enable entity extraction.', $this->textDomain);
                echo '</p></div>';
            }
        }
    }

    /**
     * AJAX handler for dismissing notices.
     *
     * @return void
     */
    public function dismissNotice(): void
    {
        check_ajax_referer('vibe_ai_nonce', 'nonce');

        if (!current_user_can(Config::REQUIRED_CAPABILITY)) {
            wp_send_json_error('Unauthorized');
        }

        $notice = sanitize_text_field($_POST['notice'] ?? '');

        if ($notice === 'vibe_ai_api_key') {
            update_option('vibe_ai_api_key_notice_dismissed', true);
        }

        wp_send_json_success();
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function registerRestRoutes(): void
    {
        // Status endpoint
        register_rest_route(Config::REST_NAMESPACE, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkAdminPermission'],
        ]);

        // Entities endpoints
        register_rest_route(Config::REST_NAMESPACE, '/entities', [
            'methods' => 'GET',
            'callback' => [$this, 'getEntities'],
            'permission_callback' => [$this, 'checkAdminPermission'],
        ]);

        register_rest_route(Config::REST_NAMESPACE, '/entities/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getEntity'],
                'permission_callback' => [$this, 'checkAdminPermission'],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateEntity'],
                'permission_callback' => [$this, 'checkAdminPermission'],
            ],
        ]);

        register_rest_route(Config::REST_NAMESPACE, '/entities/merge', [
            'methods' => 'POST',
            'callback' => [$this, 'mergeEntities'],
            'permission_callback' => [$this, 'checkAdminPermission'],
        ]);

        // Pipeline endpoints
        register_rest_route(Config::REST_NAMESPACE, '/pipeline/start', [
            'methods' => 'POST',
            'callback' => [$this, 'startPipeline'],
            'permission_callback' => [$this, 'checkAdminPermission'],
        ]);

        register_rest_route(Config::REST_NAMESPACE, '/pipeline/stop', [
            'methods' => 'POST',
            'callback' => [$this, 'stopPipeline'],
            'permission_callback' => [$this, 'checkAdminPermission'],
        ]);
    }

    /**
     * Check if current user has admin permissions.
     *
     * @return bool
     */
    public function checkAdminPermission(): bool
    {
        return current_user_can(Config::REQUIRED_CAPABILITY);
    }

    /**
     * REST callback: Get pipeline status.
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response
     */
    public function getStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        // Placeholder - will be implemented by StatusService
        return new \WP_REST_Response([
            'status' => 'idle',
            'current_phase' => null,
            'progress' => [
                'total' => 0,
                'completed' => 0,
                'failed' => 0,
                'percentage' => 0,
            ],
            'stats' => $this->getStats(),
            'last_activity' => null,
            'propagating_entities' => [],
        ]);
    }

    /**
     * Get entity statistics.
     *
     * @return array<string, mixed>
     */
    private function getStats(): array
    {
        global $wpdb;

        $entities_table = $wpdb->prefix . Config::TABLE_ENTITIES;
        $mentions_table = $wpdb->prefix . Config::TABLE_MENTIONS;

        $total_entities = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$entities_table}");
        $total_mentions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mentions_table}");
        $avg_confidence = (float) $wpdb->get_var("SELECT AVG(confidence) FROM {$mentions_table}") ?: 0.0;

        return [
            'total_entities' => $total_entities,
            'total_mentions' => $total_mentions,
            'avg_confidence' => round($avg_confidence, 3),
        ];
    }

    /**
     * REST callback: Get entities list.
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response
     */
    public function getEntities(\WP_REST_Request $request): \WP_REST_Response
    {
        // Placeholder - will be implemented by EntityRepository
        return new \WP_REST_Response([
            'entities' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 20,
        ]);
    }

    /**
     * REST callback: Get single entity.
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response
     */
    public function getEntity(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        // Placeholder - will be implemented by EntityRepository
        return new \WP_REST_Response(['entity' => null], 404);
    }

    /**
     * REST callback: Update entity.
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response
     */
    public function updateEntity(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        // Placeholder - will be implemented by EntityRepository
        return new \WP_REST_Response(['success' => false], 501);
    }

    /**
     * REST callback: Merge entities.
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response
     */
    public function mergeEntities(\WP_REST_Request $request): \WP_REST_Response
    {
        // Placeholder - will be implemented by EntityRepository
        return new \WP_REST_Response(['success' => false], 501);
    }

    /**
     * REST callback: Start pipeline.
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response
     */
    public function startPipeline(\WP_REST_Request $request): \WP_REST_Response
    {
        // Placeholder - will be implemented by PipelineManager
        $this->logger->info('Pipeline start requested');
        return new \WP_REST_Response(['success' => true, 'message' => 'Pipeline started']);
    }

    /**
     * REST callback: Stop pipeline.
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response
     */
    public function stopPipeline(\WP_REST_Request $request): \WP_REST_Response
    {
        // Placeholder - will be implemented by PipelineManager
        $this->logger->info('Pipeline stop requested');
        return new \WP_REST_Response(['success' => true, 'message' => 'Pipeline stopped']);
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
}

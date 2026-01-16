<?php
/**
 * Plugin activation and deactivation handler
 *
 * @package Vibe\AIIndex
 */

declare(strict_types=1);

namespace Vibe\AIIndex;

/**
 * Handles plugin activation, deactivation, and database schema management.
 */
class Activator
{
    /** @var string Option key for storing database version */
    private const DB_VERSION_OPTION = 'vibe_ai_db_version';

    /** @var string Current database schema version */
    private const DB_VERSION = '1.0.0';

    /**
     * Plugin activation callback.
     * Creates database tables and initializes plugin options.
     *
     * @return void
     */
    public static function activate(): void
    {
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                'AI Entity Index requires PHP 8.1 or higher.',
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }

        // Create or update database tables
        self::createTables();

        // Set default options
        self::setDefaultOptions();

        // Create log directory
        self::createLogDirectory();

        // Update database version
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

        // Clear any cached data
        wp_cache_flush();

        // Schedule cleanup cron if not already scheduled
        if (!wp_next_scheduled('vibe_ai_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'vibe_ai_daily_cleanup');
        }
    }

    /**
     * Plugin deactivation callback.
     * Cleans up scheduled tasks but preserves data.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        // Clear scheduled events
        wp_clear_scheduled_hook('vibe_ai_daily_cleanup');

        // Cancel any pending Action Scheduler jobs
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('vibe_ai_process_batch');
            as_unschedule_all_actions('vibe_ai_propagate_entity');
            as_unschedule_all_actions('vibe_ai_generate_schema');
        }

        // Clear transients
        self::clearTransients();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create database tables using dbDelta.
     *
     * @return void
     */
    private static function createTables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Include WordPress upgrade functions for dbDelta
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Entities table - The Canonical Truth
        $entities_table = $wpdb->prefix . Config::TABLE_ENTITIES;
        $entities_sql = "CREATE TABLE {$entities_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            type varchar(50) DEFAULT 'CONCEPT',
            schema_type varchar(100) DEFAULT 'Thing',
            description text DEFAULT NULL,
            same_as_url varchar(2048) DEFAULT NULL,
            wikidata_id varchar(50) DEFAULT NULL,
            status varchar(20) DEFAULT 'raw',
            mention_count int unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_slug (slug),
            KEY idx_type (type),
            KEY idx_status (status),
            KEY idx_mention_count (mention_count)
        ) {$charset_collate};";

        dbDelta($entities_sql);

        // Mentions table - The Edges
        $mentions_table = $wpdb->prefix . Config::TABLE_MENTIONS;
        $mentions_sql = "CREATE TABLE {$mentions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            confidence float DEFAULT 0.0,
            context_snippet text,
            is_primary tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_entity_post (entity_id, post_id),
            KEY idx_post_id (post_id),
            KEY idx_confidence (confidence)
        ) {$charset_collate};";

        dbDelta($mentions_sql);

        // Aliases table - Surface Form Resolution
        $aliases_table = $wpdb->prefix . Config::TABLE_ALIASES;
        $aliases_sql = "CREATE TABLE {$aliases_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            canonical_id bigint(20) unsigned NOT NULL,
            alias varchar(255) NOT NULL,
            alias_slug varchar(255) NOT NULL,
            source varchar(50) DEFAULT 'ai',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_alias_slug (alias_slug),
            KEY idx_canonical (canonical_id)
        ) {$charset_collate};";

        dbDelta($aliases_sql);

        // Add foreign keys (dbDelta doesn't handle these well, so we add them separately)
        self::addForeignKeys();
    }

    /**
     * Add foreign key constraints to tables.
     * Note: These are added separately as dbDelta doesn't handle FK well.
     *
     * @return void
     */
    private static function addForeignKeys(): void
    {
        global $wpdb;

        $entities_table = $wpdb->prefix . Config::TABLE_ENTITIES;
        $mentions_table = $wpdb->prefix . Config::TABLE_MENTIONS;
        $aliases_table = $wpdb->prefix . Config::TABLE_ALIASES;
        $posts_table = $wpdb->posts;

        // Check if foreign keys already exist before adding
        $fk_check_mentions_entity = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$mentions_table}'
             AND CONSTRAINT_NAME = 'fk_mentions_entity'"
        );

        if ((int) $fk_check_mentions_entity === 0) {
            $wpdb->query(
                "ALTER TABLE {$mentions_table}
                 ADD CONSTRAINT fk_mentions_entity
                 FOREIGN KEY (entity_id) REFERENCES {$entities_table} (id) ON DELETE CASCADE"
            );
        }

        $fk_check_mentions_post = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$mentions_table}'
             AND CONSTRAINT_NAME = 'fk_mentions_post'"
        );

        if ((int) $fk_check_mentions_post === 0) {
            $wpdb->query(
                "ALTER TABLE {$mentions_table}
                 ADD CONSTRAINT fk_mentions_post
                 FOREIGN KEY (post_id) REFERENCES {$posts_table} (ID) ON DELETE CASCADE"
            );
        }

        $fk_check_alias = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$aliases_table}'
             AND CONSTRAINT_NAME = 'fk_alias_canonical'"
        );

        if ((int) $fk_check_alias === 0) {
            $wpdb->query(
                "ALTER TABLE {$aliases_table}
                 ADD CONSTRAINT fk_alias_canonical
                 FOREIGN KEY (canonical_id) REFERENCES {$entities_table} (id) ON DELETE CASCADE"
            );
        }
    }

    /**
     * Set default plugin options.
     *
     * @return void
     */
    private static function setDefaultOptions(): void
    {
        $defaults = [
            'vibe_ai_model' => Config::DEFAULT_MODEL,
            'vibe_ai_batch_size' => Config::BATCH_SIZE,
            'vibe_ai_confidence_threshold' => Config::SCHEMA_MIN_CONFIDENCE,
            'vibe_ai_post_types' => Config::DEFAULT_POST_TYPES,
            'vibe_ai_logging_enabled' => true,
            'vibe_ai_log_level' => 'info',
        ];

        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
    }

    /**
     * Create the log directory if it doesn't exist.
     *
     * @return void
     */
    private static function createLogDirectory(): void
    {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/' . Config::LOG_DIRECTORY;

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);

            // Create .htaccess to protect logs
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents($log_dir . '/.htaccess', $htaccess_content);

            // Create index.php to prevent directory listing
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
        }
    }

    /**
     * Clear plugin transients.
     *
     * @return void
     */
    private static function clearTransients(): void
    {
        global $wpdb;

        // Delete all plugin transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_vibe_ai_%'
             OR option_name LIKE '_transient_timeout_vibe_ai_%'"
        );
    }

    /**
     * Check if database needs upgrade and perform if necessary.
     *
     * @return void
     */
    public static function maybeUpgrade(): void
    {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0');

        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            self::createTables();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    /**
     * Uninstall the plugin completely (called on plugin deletion).
     * This removes all data and tables.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        global $wpdb;

        // Only allow uninstall if user has proper permissions
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // Drop tables (in correct order due to foreign keys)
        $mentions_table = $wpdb->prefix . Config::TABLE_MENTIONS;
        $aliases_table = $wpdb->prefix . Config::TABLE_ALIASES;
        $entities_table = $wpdb->prefix . Config::TABLE_ENTITIES;

        $wpdb->query("DROP TABLE IF EXISTS {$mentions_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$aliases_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$entities_table}");

        // Delete all plugin options
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vibe_ai_%'"
        );

        // Delete all plugin post meta
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_vibe_ai_%'"
        );

        // Clear transients
        self::clearTransients();

        // Remove log directory
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/' . Config::LOG_DIRECTORY;

        if (is_dir($log_dir)) {
            self::removeDirectory($log_dir);
        }
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Directory path to remove
     * @return void
     */
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

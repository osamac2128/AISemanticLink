<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

use Vibe\AIIndex\Jobs\PropagateEntityChangeJob;

/**
 * CacheInvalidator: Handle cache invalidation coordination.
 *
 * Coordinates schema cache invalidation when entities are updated,
 * ensuring all affected posts have their JSON-LD regenerated.
 *
 * @package Vibe\AIIndex\Services
 * @since 1.0.0
 */
class CacheInvalidator
{
    /**
     * Meta key for schema cache (matches SchemaGenerator).
     */
    private const META_SCHEMA_CACHE = '_vibe_ai_schema_cache';

    /**
     * Meta key for schema version.
     */
    private const META_SCHEMA_VERSION = '_vibe_ai_schema_version';

    /**
     * Meta key for extraction timestamp.
     */
    private const META_EXTRACTED_AT = '_vibe_ai_extracted_at';

    /**
     * Fields that affect schema output.
     *
     * @var array<string>
     */
    private const SCHEMA_AFFECTING_FIELDS = [
        'name',
        'schema_type',
        'same_as_url',
        'wikidata_id',
        'description',
        'type',
    ];

    /**
     * Schema generator instance.
     *
     * @var SchemaGenerator|null
     */
    private ?SchemaGenerator $generator = null;

    /**
     * WordPress database instance.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Mentions table name.
     *
     * @var string
     */
    private string $mentions_table;

    /**
     * Constructor.
     *
     * @param SchemaGenerator|null $generator Optional generator instance.
     */
    public function __construct(?SchemaGenerator $generator = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->mentions_table = $wpdb->prefix . 'ai_mentions';
        $this->generator = $generator;
    }

    /**
     * Invalidate schema cache for all posts mentioning an entity.
     *
     * Schedules a PropagateEntityChangeJob to handle the invalidation
     * in batches to avoid timeout issues with large numbers of posts.
     *
     * @param int $entity_id The entity ID.
     *
     * @return void
     */
    public function invalidate_for_entity(int $entity_id): void
    {
        // Get count of affected posts for logging
        $affected_count = $this->get_affected_post_count($entity_id);

        if ($affected_count === 0) {
            $this->log('debug', "No posts to invalidate for entity {$entity_id}");
            return;
        }

        $this->log('info', "Scheduling propagation for entity {$entity_id}", [
            'affected_posts' => $affected_count,
        ]);

        // Schedule the propagation job
        PropagateEntityChangeJob::schedule($entity_id);

        /**
         * Fires when entity cache invalidation is scheduled.
         *
         * @param int $entity_id      The entity ID.
         * @param int $affected_count Number of affected posts.
         */
        do_action('vibe_ai_entity_invalidation_scheduled', $entity_id, $affected_count);
    }

    /**
     * Invalidate schema cache for a single post.
     *
     * @param int $post_id The post ID.
     *
     * @return void
     */
    public function invalidate_for_post(int $post_id): void
    {
        delete_post_meta($post_id, self::META_SCHEMA_CACHE);
        delete_post_meta($post_id, self::META_SCHEMA_VERSION);
        delete_post_meta($post_id, self::META_EXTRACTED_AT);

        $this->log('debug', "Invalidated schema cache for post {$post_id}");

        /**
         * Fires when a post's schema cache is invalidated.
         *
         * @param int $post_id The post ID.
         */
        do_action('vibe_ai_post_schema_invalidated', $post_id);
    }

    /**
     * Invalidate schema cache for multiple posts efficiently.
     *
     * @param array<int> $post_ids Array of post IDs.
     *
     * @return void
     */
    public function bulk_invalidate(array $post_ids): void
    {
        if (empty($post_ids)) {
            return;
        }

        // Sanitize post IDs
        $post_ids = array_map('intval', $post_ids);
        $post_ids = array_filter($post_ids, function ($id) {
            return $id > 0;
        });

        if (empty($post_ids)) {
            return;
        }

        // Use direct SQL for efficient bulk deletion
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

        // Delete schema cache
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->wpdb->postmeta}
                 WHERE post_id IN ({$placeholders})
                 AND meta_key IN (%s, %s, %s)",
                array_merge(
                    $post_ids,
                    [self::META_SCHEMA_CACHE, self::META_SCHEMA_VERSION, self::META_EXTRACTED_AT]
                )
            )
        );

        $this->log('info', 'Bulk invalidated schema cache', [
            'post_count' => count($post_ids),
        ]);

        /**
         * Fires when multiple posts' schema cache is invalidated.
         *
         * @param array $post_ids Array of post IDs.
         */
        do_action('vibe_ai_bulk_schema_invalidated', $post_ids);
    }

    /**
     * Hook callback for entity updates.
     *
     * Checks if the changes affect schema output and triggers
     * propagation if necessary.
     *
     * @param int   $entity_id The entity ID.
     * @param array $changes   Array of changed field names and their new values.
     *
     * @return void
     */
    public function on_entity_update(int $entity_id, array $changes): void
    {
        // Check if any schema-affecting fields changed
        $affected_fields = array_intersect(
            array_keys($changes),
            self::SCHEMA_AFFECTING_FIELDS
        );

        if (empty($affected_fields)) {
            $this->log('debug', "Entity {$entity_id} updated but no schema-affecting fields changed", [
                'changed_fields' => array_keys($changes),
            ]);
            return;
        }

        $this->log('info', "Entity {$entity_id} schema-affecting fields changed", [
            'affected_fields' => $affected_fields,
        ]);

        // Trigger propagation
        $this->invalidate_for_entity($entity_id);
    }

    /**
     * Register WordPress hooks for automatic cache invalidation.
     *
     * @return void
     */
    public function register_hooks(): void
    {
        // Invalidate when post is updated
        add_action('save_post', [$this, 'handle_post_save'], 20, 2);

        // Invalidate when post is trashed or deleted
        add_action('wp_trash_post', [$this, 'handle_post_delete']);
        add_action('before_delete_post', [$this, 'handle_post_delete']);

        // Hook into entity updates (custom action from EntityRepository)
        add_action('vibe_ai_entity_updated', [$this, 'on_entity_update'], 10, 2);
    }

    /**
     * Handle post save - invalidate schema cache.
     *
     * @param int      $post_id The post ID.
     * @param \WP_Post $post    The post object.
     *
     * @return void
     */
    public function handle_post_save(int $post_id, \WP_Post $post): void
    {
        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Only process published posts
        if ($post->post_status !== 'publish') {
            // If unpublished, remove schema cache
            $this->invalidate_for_post($post_id);
            return;
        }

        // Invalidate to force regeneration on next view
        $this->invalidate_for_post($post_id);
    }

    /**
     * Handle post deletion - invalidate schema cache.
     *
     * @param int $post_id The post ID.
     *
     * @return void
     */
    public function handle_post_delete(int $post_id): void
    {
        $this->invalidate_for_post($post_id);
    }

    /**
     * Get count of posts affected by an entity change.
     *
     * @param int $entity_id The entity ID.
     *
     * @return int Number of affected posts.
     */
    public function get_affected_post_count(int $entity_id): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id)
                 FROM {$this->mentions_table}
                 WHERE entity_id = %d",
                $entity_id
            )
        );
    }

    /**
     * Get all post IDs affected by an entity.
     *
     * @param int $entity_id The entity ID.
     *
     * @return array<int> Array of post IDs.
     */
    public function get_affected_post_ids(int $entity_id): array
    {
        $results = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT DISTINCT post_id
                 FROM {$this->mentions_table}
                 WHERE entity_id = %d
                 ORDER BY post_id ASC",
                $entity_id
            )
        );

        return array_map('intval', $results ?: []);
    }

    /**
     * Invalidate all schema caches.
     *
     * Use with caution - this affects all posts with entities.
     *
     * @return int Number of posts invalidated.
     */
    public function invalidate_all(): int
    {
        // Get all posts with schema cache
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id)
                 FROM {$this->wpdb->postmeta}
                 WHERE meta_key = %s",
                self::META_SCHEMA_CACHE
            )
        );

        // Delete all schema-related meta
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->wpdb->postmeta}
                 WHERE meta_key IN (%s, %s, %s)",
                self::META_SCHEMA_CACHE,
                self::META_SCHEMA_VERSION,
                self::META_EXTRACTED_AT
            )
        );

        $this->log('warning', 'Invalidated all schema caches', [
            'post_count' => $count,
        ]);

        /**
         * Fires when all schema caches are invalidated.
         *
         * @param int $count Number of posts affected.
         */
        do_action('vibe_ai_all_schema_invalidated', $count);

        return $count;
    }

    /**
     * Check if propagation is in progress for an entity.
     *
     * @param int $entity_id The entity ID.
     *
     * @return bool True if propagating.
     */
    public function is_propagating(int $entity_id): bool
    {
        return PropagateEntityChangeJob::is_propagating($entity_id);
    }

    /**
     * Get propagation status for an entity.
     *
     * @param int $entity_id The entity ID.
     *
     * @return array|null Status data or null if not propagating.
     */
    public function get_propagation_status(int $entity_id): ?array
    {
        return PropagateEntityChangeJob::get_propagation_status($entity_id);
    }

    /**
     * Cancel propagation for an entity.
     *
     * @param int $entity_id The entity ID.
     *
     * @return void
     */
    public function cancel_propagation(int $entity_id): void
    {
        PropagateEntityChangeJob::cancel($entity_id);
    }

    /**
     * Get the schema generator instance.
     *
     * @return SchemaGenerator
     */
    private function get_generator(): SchemaGenerator
    {
        if ($this->generator === null) {
            if (function_exists('vibe_ai_get_service')) {
                $this->generator = vibe_ai_get_service(SchemaGenerator::class);
            } else {
                $this->generator = new SchemaGenerator();
            }
        }

        return $this->generator;
    }

    /**
     * Log a message.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context.
     *
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('vibe_ai_log')) {
            vibe_ai_log($level, '[CacheInvalidator] ' . $message, $context);
        }

        do_action('vibe_ai_service_log', 'cache_invalidator', $level, $message, $context);
    }
}

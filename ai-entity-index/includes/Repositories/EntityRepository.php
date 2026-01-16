<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Repositories;

/**
 * EntityRepository: The Gatekeeper of Truth.
 *
 * Handles strict SQL operations for the Knowledge Graph.
 * All entity CRUD operations, alias management, and mention linking
 * flow through this single point of data access.
 *
 * @package Vibe\AIIndex\Repositories
 * @since 1.0.0
 */
class EntityRepository {

    /**
     * WordPress database instance.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Entities table name with prefix.
     *
     * @var string
     */
    private string $entities_table;

    /**
     * Mentions table name with prefix.
     *
     * @var string
     */
    private string $mentions_table;

    /**
     * Aliases table name with prefix.
     *
     * @var string
     */
    private string $aliases_table;

    /**
     * Initialize the repository with database connection.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb           = $wpdb;
        $this->entities_table = $wpdb->prefix . 'ai_entities';
        $this->mentions_table = $wpdb->prefix . 'ai_mentions';
        $this->aliases_table  = $wpdb->prefix . 'ai_aliases';
    }

    /**
     * Upsert an Entity.
     *
     * Logic: Check aliases first, then slug. Create if not found.
     * This ensures we always resolve to the canonical entity when one exists.
     *
     * @param string $name    Raw name from AI (e.g., "Apple Inc.").
     * @param string $type    Entity Type (e.g., "ORG").
     * @param array  $aliases Optional alternate names.
     *
     * @return int The Entity ID.
     */
    public function upsert_entity( string $name, string $type, array $aliases = [] ): int {
        $clean_name = sanitize_text_field( $name );
        $slug       = sanitize_title( $clean_name );

        // 1. Check if any alias resolves to existing canonical.
        $canonical_id = $this->resolve_alias( $slug );
        if ( $canonical_id ) {
            return $canonical_id;
        }

        // 2. Check for existing entity by slug.
        $existing_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->entities_table} WHERE slug = %s",
                $slug
            )
        );

        if ( $existing_id ) {
            return (int) $existing_id;
        }

        // 3. Insert new entity (atomic with duplicate protection).
        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->entities_table} (name, slug, type, status)
                 VALUES (%s, %s, %s, 'raw')
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
                $clean_name,
                $slug,
                strtoupper( sanitize_text_field( $type ) )
            )
        );

        $entity_id = (int) $this->wpdb->insert_id;

        // 4. Register aliases.
        foreach ( $aliases as $alias ) {
            $this->register_alias( $entity_id, $alias );
        }

        return $entity_id;
    }

    /**
     * Resolve an alias to its canonical entity ID.
     *
     * @param string $alias_slug The alias slug to resolve.
     *
     * @return int|null The canonical entity ID or null if not found.
     */
    public function resolve_alias( string $alias_slug ): ?int {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT canonical_id FROM {$this->aliases_table} WHERE alias_slug = %s",
                sanitize_title( $alias_slug )
            )
        );

        return $result ? (int) $result : null;
    }

    /**
     * Register an alias for a canonical entity.
     *
     * Uses INSERT IGNORE to prevent duplicate alias registration.
     *
     * @param int    $canonical_id The canonical entity ID.
     * @param string $alias        The alias name to register.
     *
     * @return void
     */
    public function register_alias( int $canonical_id, string $alias ): void {
        $clean_alias = sanitize_text_field( $alias );
        $alias_slug  = sanitize_title( $clean_alias );

        // Skip empty aliases.
        if ( empty( $alias_slug ) ) {
            return;
        }

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO {$this->aliases_table} (canonical_id, alias, alias_slug, source)
                 VALUES (%d, %s, %s, 'ai')",
                $canonical_id,
                $clean_alias,
                $alias_slug
            )
        );
    }

    /**
     * Link an Entity to a Post (create a mention).
     *
     * Enforces one-link-per-post-per-entity via UNIQUE KEY.
     * Updates confidence/snippet if new extraction scores higher.
     *
     * @param int    $entity_id  The entity ID.
     * @param int    $post_id    The post ID.
     * @param float  $confidence Confidence score (0.0 - 1.0).
     * @param string $context    Context snippet where entity appears.
     * @param bool   $is_primary Whether this is the primary entity for the post.
     *
     * @return void
     */
    public function link_mention(
        int $entity_id,
        int $post_id,
        float $confidence,
        string $context,
        bool $is_primary = false
    ): void {
        // Sanitize and truncate context to 500 characters.
        $clean_context = wp_kses_post( mb_substr( $context, 0, 500 ) );

        // Clamp confidence to valid range.
        $confidence = max( 0.0, min( 1.0, $confidence ) );

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->mentions_table}
                    (entity_id, post_id, confidence, context_snippet, is_primary)
                 VALUES (%d, %d, %f, %s, %d)
                 ON DUPLICATE KEY UPDATE
                    confidence = GREATEST(confidence, VALUES(confidence)),
                    context_snippet = IF(VALUES(confidence) > confidence, VALUES(context_snippet), context_snippet),
                    is_primary = VALUES(is_primary)",
                $entity_id,
                $post_id,
                $confidence,
                $clean_context,
                $is_primary ? 1 : 0
            )
        );

        // Update mention count on entity.
        $this->update_mention_count( $entity_id );
    }

    /**
     * Update cached mention count for an entity.
     *
     * @param int $entity_id The entity ID.
     *
     * @return void
     */
    private function update_mention_count( int $entity_id ): void {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->entities_table}
                 SET mention_count = (
                     SELECT COUNT(*) FROM {$this->mentions_table} WHERE entity_id = %d
                 )
                 WHERE id = %d",
                $entity_id,
                $entity_id
            )
        );
    }

    /**
     * Fetch entities for a post (for Schema generation).
     *
     * Returns entities linked to the post above the confidence threshold,
     * ordered by primary status and confidence descending.
     *
     * @param int   $post_id        The post ID.
     * @param float $min_confidence Minimum confidence threshold (default 0.6).
     *
     * @return array Array of entity objects with mention data.
     */
    public function get_entities_for_post( int $post_id, float $min_confidence = 0.6 ): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    e.id,
                    e.name,
                    e.slug,
                    e.type,
                    e.schema_type,
                    e.same_as_url,
                    e.wikidata_id,
                    e.description,
                    m.confidence,
                    m.context_snippet,
                    m.is_primary
                 FROM {$this->mentions_table} m
                 JOIN {$this->entities_table} e ON m.entity_id = e.id
                 WHERE m.post_id = %d
                   AND e.status NOT IN ('trash', 'rejected')
                   AND m.confidence >= %f
                 ORDER BY m.is_primary DESC, m.confidence DESC",
                $post_id,
                $min_confidence
            )
        );

        return $results ?: [];
    }

    /**
     * Merge duplicate entities into a canonical target.
     *
     * Transfers all mentions and aliases from source entities to the target,
     * then deletes the source entities.
     *
     * @param int   $target_id  The canonical entity ID to merge into.
     * @param array $source_ids Array of entity IDs to merge from.
     *
     * @return array Array of affected post IDs requiring Schema regeneration.
     */
    public function merge_entities( int $target_id, array $source_ids ): array {
        $affected_posts = [];

        foreach ( $source_ids as $source_id ) {
            $source_id = (int) $source_id;

            // Skip if trying to merge entity into itself.
            if ( $source_id === $target_id ) {
                continue;
            }

            // Get posts affected by this merge.
            $posts = $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT DISTINCT post_id FROM {$this->mentions_table} WHERE entity_id = %d",
                    $source_id
                )
            );
            $affected_posts = array_merge( $affected_posts, $posts );

            // Transfer mentions (update or skip if duplicate due to UNIQUE KEY).
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE IGNORE {$this->mentions_table}
                     SET entity_id = %d
                     WHERE entity_id = %d",
                    $target_id,
                    $source_id
                )
            );

            // Delete any remaining duplicate mentions that couldn't be transferred.
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$this->mentions_table} WHERE entity_id = %d",
                    $source_id
                )
            );

            // Transfer aliases.
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE IGNORE {$this->aliases_table}
                     SET canonical_id = %d
                     WHERE canonical_id = %d",
                    $target_id,
                    $source_id
                )
            );

            // Create alias from merged entity name.
            $source_name = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT name FROM {$this->entities_table} WHERE id = %d",
                    $source_id
                )
            );

            if ( $source_name ) {
                $this->register_alias( $target_id, $source_name );
            }

            // Delete source entity (cascades remaining aliases due to FK).
            $this->wpdb->delete(
                $this->entities_table,
                [ 'id' => $source_id ],
                [ '%d' ]
            );
        }

        // Update target mention count.
        $this->update_mention_count( $target_id );

        return array_unique( array_map( 'intval', $affected_posts ) );
    }

    /**
     * Get a single entity by ID.
     *
     * @param int $id The entity ID.
     *
     * @return object|null The entity object or null if not found.
     */
    public function get_entity( int $id ): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    name,
                    slug,
                    type,
                    schema_type,
                    description,
                    same_as_url,
                    wikidata_id,
                    status,
                    mention_count,
                    created_at,
                    updated_at
                 FROM {$this->entities_table}
                 WHERE id = %d",
                $id
            )
        );

        return $result ?: null;
    }

    /**
     * Get a paginated list of entities with filtering.
     *
     * @param array $args {
     *     Optional. Arguments for filtering and pagination.
     *
     *     @type int    $page     Page number (1-indexed). Default 1.
     *     @type int    $per_page Items per page. Default 20.
     *     @type string $type     Filter by entity type.
     *     @type string $status   Filter by status.
     *     @type string $search   Search term for name matching.
     *     @type string $orderby  Column to order by. Default 'created_at'.
     *     @type string $order    Sort order (ASC or DESC). Default 'DESC'.
     * }
     *
     * @return array {
     *     @type array $items      Array of entity objects.
     *     @type int   $total      Total number of matching entities.
     *     @type int   $pages      Total number of pages.
     *     @type int   $page       Current page number.
     *     @type int   $per_page   Items per page.
     * }
     */
    public function get_entities( array $args = [] ): array {
        $defaults = [
            'page'     => 1,
            'per_page' => 20,
            'type'     => '',
            'status'   => '',
            'search'   => '',
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ];

        $args = wp_parse_args( $args, $defaults );

        // Sanitize and validate arguments.
        $page     = max( 1, (int) $args['page'] );
        $per_page = max( 1, min( 100, (int) $args['per_page'] ) );
        $offset   = ( $page - 1 ) * $per_page;

        // Build WHERE clauses.
        $where_clauses = [];
        $where_values  = [];

        if ( ! empty( $args['type'] ) ) {
            $where_clauses[] = 'type = %s';
            $where_values[]  = strtoupper( sanitize_text_field( $args['type'] ) );
        }

        if ( ! empty( $args['status'] ) ) {
            $where_clauses[] = 'status = %s';
            $where_values[]  = sanitize_text_field( $args['status'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $where_clauses[] = 'name LIKE %s';
            $where_values[]  = '%' . $this->wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
        }

        $where_sql = '';
        if ( ! empty( $where_clauses ) ) {
            $where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
        }

        // Validate orderby column (whitelist approach for security).
        $allowed_orderby = [ 'id', 'name', 'type', 'status', 'mention_count', 'created_at', 'updated_at' ];
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';

        // Validate order direction.
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Get total count.
        $count_query = "SELECT COUNT(*) FROM {$this->entities_table} {$where_sql}";
        if ( ! empty( $where_values ) ) {
            $count_query = $this->wpdb->prepare( $count_query, ...$where_values );
        }
        $total = (int) $this->wpdb->get_var( $count_query );

        // Get items.
        $items_query = "SELECT
            id,
            name,
            slug,
            type,
            schema_type,
            description,
            same_as_url,
            wikidata_id,
            status,
            mention_count,
            created_at,
            updated_at
         FROM {$this->entities_table}
         {$where_sql}
         ORDER BY {$orderby} {$order}
         LIMIT %d OFFSET %d";

        $query_values   = array_merge( $where_values, [ $per_page, $offset ] );
        $prepared_query = $this->wpdb->prepare( $items_query, ...$query_values );
        $items          = $this->wpdb->get_results( $prepared_query );

        return [
            'items'    => $items ?: [],
            'total'    => $total,
            'pages'    => (int) ceil( $total / $per_page ),
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Update an entity.
     *
     * @param int   $id   The entity ID.
     * @param array $data Associative array of fields to update.
     *
     * @return bool True on success, false on failure.
     */
    public function update_entity( int $id, array $data ): bool {
        // Whitelist of allowed fields.
        $allowed_fields = [
            'name',
            'type',
            'schema_type',
            'description',
            'same_as_url',
            'wikidata_id',
            'status',
        ];

        $update_data   = [];
        $update_format = [];

        foreach ( $allowed_fields as $field ) {
            if ( ! array_key_exists( $field, $data ) ) {
                continue;
            }

            $value = $data[ $field ];

            switch ( $field ) {
                case 'name':
                    $update_data['name'] = sanitize_text_field( $value );
                    $update_data['slug'] = sanitize_title( $update_data['name'] );
                    $update_format[]     = '%s';
                    $update_format[]     = '%s';
                    break;

                case 'type':
                    $update_data['type'] = strtoupper( sanitize_text_field( $value ) );
                    $update_format[]     = '%s';
                    break;

                case 'schema_type':
                case 'status':
                    $update_data[ $field ] = sanitize_text_field( $value );
                    $update_format[]       = '%s';
                    break;

                case 'description':
                    $update_data['description'] = wp_kses_post( $value );
                    $update_format[]            = '%s';
                    break;

                case 'same_as_url':
                    $update_data['same_as_url'] = esc_url_raw( $value );
                    $update_format[]            = '%s';
                    break;

                case 'wikidata_id':
                    // Validate Wikidata ID format (Q followed by digits).
                    $wikidata_id = sanitize_text_field( $value );
                    if ( empty( $wikidata_id ) || preg_match( '/^Q[0-9]+$/', $wikidata_id ) ) {
                        $update_data['wikidata_id'] = $wikidata_id ?: null;
                        $update_format[]            = '%s';
                    }
                    break;
            }
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $this->wpdb->update(
            $this->entities_table,
            $update_data,
            [ 'id' => $id ],
            $update_format,
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Delete an entity.
     *
     * Note: Due to CASCADE foreign keys, this will also delete
     * all associated mentions and aliases.
     *
     * @param int $id The entity ID.
     *
     * @return bool True on success, false on failure.
     */
    public function delete_entity( int $id ): bool {
        $result = $this->wpdb->delete(
            $this->entities_table,
            [ 'id' => $id ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Get all aliases for an entity.
     *
     * @param int $entity_id The entity ID.
     *
     * @return array Array of alias objects.
     */
    public function get_aliases_for_entity( int $entity_id ): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    alias,
                    alias_slug,
                    source,
                    created_at
                 FROM {$this->aliases_table}
                 WHERE canonical_id = %d
                 ORDER BY created_at ASC",
                $entity_id
            )
        );

        return $results ?: [];
    }

    /**
     * Get all mentions for an entity.
     *
     * @param int $entity_id The entity ID.
     *
     * @return array Array of mention objects with post data.
     */
    public function get_mentions_for_entity( int $entity_id ): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    m.id,
                    m.post_id,
                    m.confidence,
                    m.context_snippet,
                    m.is_primary,
                    m.created_at,
                    p.post_title,
                    p.post_status
                 FROM {$this->mentions_table} m
                 LEFT JOIN {$this->wpdb->posts} p ON m.post_id = p.ID
                 WHERE m.entity_id = %d
                 ORDER BY m.confidence DESC, m.created_at DESC",
                $entity_id
            )
        );

        return $results ?: [];
    }

    /**
     * Get aggregate statistics for the entity index.
     *
     * @return array {
     *     @type int   $total_entities  Total number of entities.
     *     @type int   $total_mentions  Total number of mentions.
     *     @type float $avg_confidence  Average confidence score across all mentions.
     * }
     */
    public function get_stats(): array {
        // Get total entities.
        $total_entities = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->entities_table}"
        );

        // Get total mentions and average confidence.
        $mention_stats = $this->wpdb->get_row(
            "SELECT
                COUNT(*) as total_mentions,
                COALESCE(AVG(confidence), 0) as avg_confidence
             FROM {$this->mentions_table}"
        );

        return [
            'total_entities' => $total_entities,
            'total_mentions' => (int) ( $mention_stats->total_mentions ?? 0 ),
            'avg_confidence' => round( (float) ( $mention_stats->avg_confidence ?? 0 ), 3 ),
        ];
    }

    /**
     * Delete an alias by ID.
     *
     * @param int $alias_id The alias ID.
     *
     * @return bool True on success, false on failure.
     */
    public function delete_alias( int $alias_id ): bool {
        $result = $this->wpdb->delete(
            $this->aliases_table,
            [ 'id' => $alias_id ],
            [ '%d' ]
        );

        return $result !== false;
    }
}

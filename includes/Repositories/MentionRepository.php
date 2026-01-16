<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Repositories;

/**
 * MentionRepository: Handle mention-specific operations.
 *
 * Manages the edges between entities and posts in the Knowledge Graph.
 * Separated from EntityRepository to maintain single responsibility.
 *
 * @package Vibe\AIIndex\Repositories
 * @since 1.0.0
 */
class MentionRepository {

    /**
     * WordPress database instance.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Mentions table name with prefix.
     *
     * @var string
     */
    private string $mentions_table;

    /**
     * Entities table name with prefix.
     *
     * @var string
     */
    private string $entities_table;

    /**
     * Initialize the repository with database connection.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb           = $wpdb;
        $this->mentions_table = $wpdb->prefix . 'ai_mentions';
        $this->entities_table = $wpdb->prefix . 'ai_entities';
    }

    /**
     * Get all mentions for a specific post.
     *
     * Returns all entity mentions linked to a post, including
     * full entity details for each mention.
     *
     * @param int $post_id The post ID.
     *
     * @return array Array of mention objects with entity data.
     */
    public function get_mentions_for_post( int $post_id ): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    m.id AS mention_id,
                    m.entity_id,
                    m.confidence,
                    m.context_snippet,
                    m.is_primary,
                    m.created_at AS mention_created_at,
                    e.name AS entity_name,
                    e.slug AS entity_slug,
                    e.type AS entity_type,
                    e.schema_type,
                    e.description,
                    e.same_as_url,
                    e.wikidata_id,
                    e.status AS entity_status
                 FROM {$this->mentions_table} m
                 JOIN {$this->entities_table} e ON m.entity_id = e.id
                 WHERE m.post_id = %d
                 ORDER BY m.is_primary DESC, m.confidence DESC",
                $post_id
            )
        );

        return $results ?: [];
    }

    /**
     * Delete all mentions for a specific post.
     *
     * Used when re-extracting entities for a post or when
     * a post is deleted. Updates the mention_count for all
     * affected entities.
     *
     * @param int $post_id The post ID.
     *
     * @return void
     */
    public function delete_mentions_for_post( int $post_id ): void {
        // Get affected entity IDs before deletion.
        $affected_entity_ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT DISTINCT entity_id FROM {$this->mentions_table} WHERE post_id = %d",
                $post_id
            )
        );

        // Delete mentions.
        $this->wpdb->delete(
            $this->mentions_table,
            [ 'post_id' => $post_id ],
            [ '%d' ]
        );

        // Update mention counts for affected entities.
        foreach ( $affected_entity_ids as $entity_id ) {
            $this->update_entity_mention_count( (int) $entity_id );
        }
    }

    /**
     * Get all posts that mention a specific entity.
     *
     * Returns posts linked to an entity, including post metadata
     * and mention details.
     *
     * @param int $entity_id The entity ID.
     *
     * @return array Array of post objects with mention data.
     */
    public function get_posts_for_entity( int $entity_id ): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    m.id AS mention_id,
                    m.post_id,
                    m.confidence,
                    m.context_snippet,
                    m.is_primary,
                    m.created_at AS mention_created_at,
                    p.post_title,
                    p.post_status,
                    p.post_type,
                    p.post_date,
                    p.post_modified
                 FROM {$this->mentions_table} m
                 JOIN {$this->wpdb->posts} p ON m.post_id = p.ID
                 WHERE m.entity_id = %d
                 ORDER BY m.is_primary DESC, m.confidence DESC, p.post_date DESC",
                $entity_id
            )
        );

        return $results ?: [];
    }

    /**
     * Get a single mention by ID.
     *
     * @param int $mention_id The mention ID.
     *
     * @return object|null The mention object or null if not found.
     */
    public function get_mention( int $mention_id ): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    m.id,
                    m.entity_id,
                    m.post_id,
                    m.confidence,
                    m.context_snippet,
                    m.is_primary,
                    m.created_at
                 FROM {$this->mentions_table} m
                 WHERE m.id = %d",
                $mention_id
            )
        );

        return $result ?: null;
    }

    /**
     * Update a mention's confidence score.
     *
     * @param int   $mention_id The mention ID.
     * @param float $confidence The new confidence score (0.0 - 1.0).
     *
     * @return bool True on success, false on failure.
     */
    public function update_confidence( int $mention_id, float $confidence ): bool {
        // Clamp confidence to valid range.
        $confidence = max( 0.0, min( 1.0, $confidence ) );

        $result = $this->wpdb->update(
            $this->mentions_table,
            [ 'confidence' => $confidence ],
            [ 'id' => $mention_id ],
            [ '%f' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Update a mention's primary status.
     *
     * @param int  $mention_id The mention ID.
     * @param bool $is_primary Whether this should be the primary entity.
     *
     * @return bool True on success, false on failure.
     */
    public function update_primary_status( int $mention_id, bool $is_primary ): bool {
        $result = $this->wpdb->update(
            $this->mentions_table,
            [ 'is_primary' => $is_primary ? 1 : 0 ],
            [ 'id' => $mention_id ],
            [ '%d' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Delete a single mention.
     *
     * Updates the mention_count for the affected entity.
     *
     * @param int $mention_id The mention ID.
     *
     * @return bool True on success, false on failure.
     */
    public function delete_mention( int $mention_id ): bool {
        // Get entity ID before deletion.
        $entity_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT entity_id FROM {$this->mentions_table} WHERE id = %d",
                $mention_id
            )
        );

        $result = $this->wpdb->delete(
            $this->mentions_table,
            [ 'id' => $mention_id ],
            [ '%d' ]
        );

        // Update mention count for affected entity.
        if ( $result && $entity_id ) {
            $this->update_entity_mention_count( (int) $entity_id );
        }

        return $result !== false;
    }

    /**
     * Get mentions with confidence below a threshold.
     *
     * Useful for identifying mentions that need review.
     *
     * @param float $threshold Confidence threshold.
     * @param int   $limit     Maximum number of mentions to return.
     *
     * @return array Array of mention objects.
     */
    public function get_low_confidence_mentions( float $threshold = 0.6, int $limit = 50 ): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    m.id AS mention_id,
                    m.entity_id,
                    m.post_id,
                    m.confidence,
                    m.context_snippet,
                    m.is_primary,
                    m.created_at,
                    e.name AS entity_name,
                    e.type AS entity_type,
                    p.post_title
                 FROM {$this->mentions_table} m
                 JOIN {$this->entities_table} e ON m.entity_id = e.id
                 JOIN {$this->wpdb->posts} p ON m.post_id = p.ID
                 WHERE m.confidence < %f
                 ORDER BY m.confidence ASC
                 LIMIT %d",
                $threshold,
                max( 1, min( 100, $limit ) )
            )
        );

        return $results ?: [];
    }

    /**
     * Get mention counts grouped by entity type.
     *
     * @return array Associative array of type => count.
     */
    public function get_mention_counts_by_type(): array {
        $results = $this->wpdb->get_results(
            "SELECT
                e.type,
                COUNT(m.id) AS mention_count
             FROM {$this->mentions_table} m
             JOIN {$this->entities_table} e ON m.entity_id = e.id
             GROUP BY e.type
             ORDER BY mention_count DESC"
        );

        $counts = [];
        foreach ( $results as $row ) {
            $counts[ $row->type ] = (int) $row->mention_count;
        }

        return $counts;
    }

    /**
     * Get posts without any entity mentions.
     *
     * Useful for identifying posts that need processing.
     *
     * @param array $post_types Post types to check (default: ['post', 'page']).
     * @param int   $limit      Maximum number of posts to return.
     *
     * @return array Array of post IDs.
     */
    public function get_posts_without_mentions( array $post_types = [ 'post', 'page' ], int $limit = 100 ): array {
        // Sanitize post types.
        $post_types = array_map( 'sanitize_text_field', $post_types );

        if ( empty( $post_types ) ) {
            return [];
        }

        // Build placeholders for post types.
        $placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

        $query = $this->wpdb->prepare(
            "SELECT p.ID
             FROM {$this->wpdb->posts} p
             LEFT JOIN {$this->mentions_table} m ON p.ID = m.post_id
             WHERE p.post_status = 'publish'
               AND p.post_type IN ({$placeholders})
               AND m.id IS NULL
             ORDER BY p.post_date DESC
             LIMIT %d",
            array_merge( $post_types, [ max( 1, min( 500, $limit ) ) ] )
        );

        $results = $this->wpdb->get_col( $query );

        return array_map( 'intval', $results ?: [] );
    }

    /**
     * Update the cached mention count for an entity.
     *
     * @param int $entity_id The entity ID.
     *
     * @return void
     */
    private function update_entity_mention_count( int $entity_id ): void {
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
     * Bulk update mention counts for all entities.
     *
     * Useful for maintenance and after bulk operations.
     *
     * @return int Number of entities updated.
     */
    public function recalculate_all_mention_counts(): int {
        $result = $this->wpdb->query(
            "UPDATE {$this->entities_table} e
             SET mention_count = (
                 SELECT COUNT(*) FROM {$this->mentions_table} m WHERE m.entity_id = e.id
             )"
        );

        return (int) $result;
    }
}

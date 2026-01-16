<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Repositories\KB;

use Vibe\AIIndex\Config;

/**
 * Repository for wp_ai_kb_docs table operations.
 *
 * Manages document records in the Knowledge Base, tracking which WordPress
 * posts have been processed for semantic search and their indexing status.
 *
 * @package Vibe\AIIndex\Repositories\KB
 * @since 1.0.0
 */
class DocumentRepository {

    /**
     * WordPress database instance.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Documents table name with prefix.
     *
     * @var string
     */
    private string $table;

    /**
     * Chunks table name with prefix.
     *
     * @var string
     */
    private string $chunks_table;

    /**
     * Vectors table name with prefix.
     *
     * @var string
     */
    private string $vectors_table;

    /**
     * Initialize the repository with database connection.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->table         = $wpdb->prefix . 'ai_kb_docs';
        $this->chunks_table  = $wpdb->prefix . 'ai_kb_chunks';
        $this->vectors_table = $wpdb->prefix . 'ai_kb_vectors';
    }

    /**
     * Create or update a document record.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert.
     *
     * @param array $data {
     *     Document data to upsert.
     *
     *     @type int    $post_id      Required. The WordPress post ID.
     *     @type string $post_type    Post type (e.g., 'post', 'page').
     *     @type string $content_hash MD5 hash of the content.
     *     @type string $title        Document title.
     *     @type string $status       Document status (pending, indexed, error, excluded).
     *     @type int    $chunk_count  Number of chunks.
     * }
     *
     * @return int The document ID.
     */
    public function upsert(array $data): int {
        $post_id      = (int) ($data['post_id'] ?? 0);
        $post_type    = sanitize_text_field($data['post_type'] ?? 'post');
        $content_hash = sanitize_text_field($data['content_hash'] ?? '');
        $title        = sanitize_text_field($data['title'] ?? '');
        $status       = sanitize_text_field($data['status'] ?? 'pending');
        $chunk_count  = (int) ($data['chunk_count'] ?? 0);

        if ($post_id <= 0) {
            throw new \InvalidArgumentException('post_id is required and must be positive');
        }

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->table}
                    (post_id, post_type, content_hash, title, status, chunk_count, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %d, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    post_type = VALUES(post_type),
                    content_hash = VALUES(content_hash),
                    title = VALUES(title),
                    status = VALUES(status),
                    chunk_count = VALUES(chunk_count),
                    updated_at = NOW(),
                    id = LAST_INSERT_ID(id)",
                $post_id,
                $post_type,
                $content_hash,
                $title,
                $status,
                $chunk_count
            )
        );

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Get document by ID.
     *
     * @param int $id The document ID.
     *
     * @return object|null The document object or null if not found.
     */
    public function find(int $id): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    post_id,
                    post_type,
                    content_hash,
                    title,
                    status,
                    chunk_count,
                    indexed_at,
                    created_at,
                    updated_at
                 FROM {$this->table}
                 WHERE id = %d",
                $id
            )
        );

        return $result ?: null;
    }

    /**
     * Get document by post ID.
     *
     * @param int $postId The WordPress post ID.
     *
     * @return object|null The document object or null if not found.
     */
    public function findByPostId(int $postId): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    post_id,
                    post_type,
                    content_hash,
                    title,
                    status,
                    chunk_count,
                    indexed_at,
                    created_at,
                    updated_at
                 FROM {$this->table}
                 WHERE post_id = %d",
                $postId
            )
        );

        return $result ?: null;
    }

    /**
     * Update document status.
     *
     * @param int    $id     The document ID.
     * @param string $status The new status (pending, indexed, error, excluded).
     *
     * @return void
     */
    public function setStatus(int $id, string $status): void {
        $allowed_statuses = ['pending', 'indexed', 'error', 'excluded'];
        $status = sanitize_text_field($status);

        if (!in_array($status, $allowed_statuses, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $this->wpdb->update(
            $this->table,
            [
                'status'     => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Update chunk count.
     *
     * @param int $id    The document ID.
     * @param int $count The chunk count.
     *
     * @return void
     */
    public function setChunkCount(int $id, int $count): void {
        $this->wpdb->update(
            $this->table,
            [
                'chunk_count' => max(0, $count),
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Mark as indexed with timestamp.
     *
     * @param int $id The document ID.
     *
     * @return void
     */
    public function markIndexed(int $id): void {
        $this->wpdb->update(
            $this->table,
            [
                'status'     => 'indexed',
                'indexed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Get documents by status.
     *
     * @param string $status The status to filter by.
     * @param int    $limit  Maximum number of documents to return.
     * @param int    $offset Offset for pagination.
     *
     * @return array<object> Array of document objects.
     */
    public function getByStatus(string $status, int $limit = 100, int $offset = 0): array {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    post_id,
                    post_type,
                    content_hash,
                    title,
                    status,
                    chunk_count,
                    indexed_at,
                    created_at,
                    updated_at
                 FROM {$this->table}
                 WHERE status = %s
                 ORDER BY updated_at ASC
                 LIMIT %d OFFSET %d",
                sanitize_text_field($status),
                $limit,
                $offset
            )
        );

        return $results ?: [];
    }

    /**
     * Get pending documents for indexing.
     *
     * Returns documents that need to be processed, ordered by creation date.
     *
     * @param int $limit Maximum number of documents to return.
     *
     * @return array Array of document objects.
     */
    public function getPending(int $limit = 50): array {
        $limit = max(1, min(500, $limit));

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    d.id,
                    d.post_id,
                    d.post_type,
                    d.content_hash,
                    d.title,
                    d.status,
                    d.chunk_count,
                    d.indexed_at,
                    d.created_at,
                    d.updated_at
                 FROM {$this->table} d
                 JOIN {$this->wpdb->posts} p ON d.post_id = p.ID
                 WHERE d.status = 'pending'
                   AND p.post_status = 'publish'
                 ORDER BY d.created_at ASC
                 LIMIT %d",
                $limit
            )
        );

        return $results ?: [];
    }

    /**
     * Get documents by post type.
     *
     * @param string $postType The post type to filter by.
     * @param int    $limit    Maximum number of documents to return.
     * @param int    $offset   Offset for pagination.
     *
     * @return array Array of document objects.
     */
    public function getByPostType(string $postType, int $limit = 100, int $offset = 0): array {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    post_id,
                    post_type,
                    content_hash,
                    title,
                    status,
                    chunk_count,
                    indexed_at,
                    created_at,
                    updated_at
                 FROM {$this->table}
                 WHERE post_type = %s
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                sanitize_text_field($postType),
                $limit,
                $offset
            )
        );

        return $results ?: [];
    }

    /**
     * Delete document and cascade to chunks and vectors.
     *
     * Uses a transaction to ensure atomicity.
     *
     * @param int $id The document ID.
     *
     * @return void
     */
    public function delete(int $id): void {
        $this->wpdb->query('START TRANSACTION');

        try {
            // Get chunk IDs for this document.
            $chunk_ids = $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->chunks_table} WHERE doc_id = %d",
                    $id
                )
            );

            // Delete vectors for all chunks.
            if (!empty($chunk_ids)) {
                $placeholders = implode(', ', array_fill(0, count($chunk_ids), '%d'));
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        "DELETE FROM {$this->vectors_table} WHERE chunk_id IN ({$placeholders})",
                        ...$chunk_ids
                    )
                );
            }

            // Delete chunks.
            $this->wpdb->delete(
                $this->chunks_table,
                ['doc_id' => $id],
                ['%d']
            );

            // Delete document.
            $this->wpdb->delete(
                $this->table,
                ['id' => $id],
                ['%d']
            );

            $this->wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Delete by post ID.
     *
     * @param int $postId The WordPress post ID.
     *
     * @return void
     */
    public function deleteByPostId(int $postId): void {
        $doc = $this->findByPostId($postId);

        if ($doc) {
            $this->delete((int) $doc->id);
        }
    }

    /**
     * Check if content has changed (by hash).
     *
     * @param int    $postId  The WordPress post ID.
     * @param string $newHash The new content hash to compare.
     *
     * @return bool True if content has changed or document doesn't exist.
     */
    public function hasContentChanged(int $postId, string $newHash): bool {
        $existing_hash = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT content_hash FROM {$this->table} WHERE post_id = %d",
                $postId
            )
        );

        // If no existing document, consider it "changed" (needs processing).
        if ($existing_hash === null) {
            return true;
        }

        return $existing_hash !== $newHash;
    }

    /**
     * Get statistics.
     *
     * @return array{total: int, indexed: int, pending: int, error: int, excluded: int}
     */
    public function getStats(): array {
        $results = $this->wpdb->get_results(
            "SELECT status, COUNT(*) as count
             FROM {$this->table}
             GROUP BY status"
        );

        $stats = [
            'total'    => 0,
            'indexed'  => 0,
            'pending'  => 0,
            'error'    => 0,
            'excluded' => 0,
        ];

        foreach ($results as $row) {
            $status = $row->status;
            $count  = (int) $row->count;

            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }

            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * Get paginated list with filters.
     *
     * @param int   $page    Page number (1-indexed).
     * @param int   $perPage Items per page.
     * @param array $filters {
     *     Optional filters.
     *
     *     @type string $status    Filter by status.
     *     @type string $post_type Filter by post type.
     *     @type string $search    Search term for title.
     *     @type string $orderby   Column to order by.
     *     @type string $order     Sort order (ASC or DESC).
     * }
     *
     * @return array {
     *     @type array $items    Array of document objects.
     *     @type int   $total    Total number of matching documents.
     *     @type int   $pages    Total number of pages.
     *     @type int   $page     Current page number.
     *     @type int   $per_page Items per page.
     * }
     */
    public function getPaginated(int $page = 1, int $perPage = 20, array $filters = []): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        // Build WHERE clauses.
        $where_clauses = [];
        $where_values  = [];

        if (!empty($filters['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[]  = sanitize_text_field($filters['status']);
        }

        if (!empty($filters['post_type'])) {
            $where_clauses[] = 'post_type = %s';
            $where_values[]  = sanitize_text_field($filters['post_type']);
        }

        if (!empty($filters['search'])) {
            $where_clauses[] = 'title LIKE %s';
            $where_values[]  = '%' . $this->wpdb->esc_like(sanitize_text_field($filters['search'])) . '%';
        }

        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Validate orderby column.
        $allowed_orderby = ['id', 'post_id', 'post_type', 'title', 'status', 'chunk_count', 'indexed_at', 'created_at', 'updated_at'];
        $orderby         = in_array($filters['orderby'] ?? '', $allowed_orderby, true)
            ? $filters['orderby']
            : 'created_at';

        // Validate order direction.
        $order = strtoupper($filters['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Get total count.
        $count_query = "SELECT COUNT(*) FROM {$this->table} {$where_sql}";
        if (!empty($where_values)) {
            $count_query = $this->wpdb->prepare($count_query, ...$where_values);
        }
        $total = (int) $this->wpdb->get_var($count_query);

        // Get items.
        $items_query = "SELECT
            id,
            post_id,
            post_type,
            content_hash,
            title,
            status,
            chunk_count,
            indexed_at,
            created_at,
            updated_at
         FROM {$this->table}
         {$where_sql}
         ORDER BY {$orderby} {$order}
         LIMIT %d OFFSET %d";

        $query_values   = array_merge($where_values, [$perPage, $offset]);
        $prepared_query = $this->wpdb->prepare($items_query, ...$query_values);
        $items          = $this->wpdb->get_results($prepared_query);

        return [
            'items'    => $items ?: [],
            'total'    => $total,
            'pages'    => (int) ceil($total / $perPage),
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get total count with filters.
     *
     * @param array $filters Optional filters (same as getPaginated).
     *
     * @return int Total count of matching documents.
     */
    public function getCount(array $filters = []): int {
        $where_clauses = [];
        $where_values  = [];

        if (!empty($filters['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[]  = sanitize_text_field($filters['status']);
        }

        if (!empty($filters['post_type'])) {
            $where_clauses[] = 'post_type = %s';
            $where_values[]  = sanitize_text_field($filters['post_type']);
        }

        if (!empty($filters['search'])) {
            $where_clauses[] = 'title LIKE %s';
            $where_values[]  = '%' . $this->wpdb->esc_like(sanitize_text_field($filters['search'])) . '%';
        }

        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        $query = "SELECT COUNT(*) FROM {$this->table} {$where_sql}";
        if (!empty($where_values)) {
            $query = $this->wpdb->prepare($query, ...$where_values);
        }

        return (int) $this->wpdb->get_var($query);
    }

    // =========================================================================
    // AI Publishing Methods (for LlmsTxt, Sitemap, and Feed generators)
    // =========================================================================

    /**
     * Option key for pinned pages.
     *
     * @var string
     */
    private const PINNED_PAGES_OPTION = 'vibe_ai_kb_pinned_pages';

    /**
     * Get all indexed documents for AI publishing.
     *
     * @param array $args {
     *     Optional. Arguments for filtering.
     *
     *     @type int    $limit   Maximum documents to return. Default 100.
     *     @type int    $offset  Offset for pagination. Default 0.
     *     @type string $orderby Column to order by. Default 'updated_at'.
     *     @type string $order   Sort order (ASC or DESC). Default 'DESC'.
     * }
     *
     * @return array<array{
     *     post_id: int,
     *     url: string,
     *     title: string,
     *     content_hash: string,
     *     chunk_count: int,
     *     updated_at: string,
     *     indexed_at: string|null
     * }>
     */
    public function getAllDocuments(array $args = []): array {
        $defaults = [
            'limit'   => 100,
            'offset'  => 0,
            'orderby' => 'updated_at',
            'order'   => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        // Validate orderby
        $allowed_orderby = ['id', 'post_id', 'title', 'updated_at', 'indexed_at', 'created_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'updated_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $limit  = max(1, min(10000, (int) $args['limit']));
        $offset = max(0, (int) $args['offset']);

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    d.id,
                    d.post_id,
                    d.title,
                    d.content_hash,
                    d.chunk_count,
                    d.updated_at,
                    d.indexed_at
                 FROM {$this->table} d
                 JOIN {$this->wpdb->posts} p ON d.post_id = p.ID
                 WHERE d.status = 'indexed'
                   AND p.post_status = 'publish'
                 ORDER BY d.{$orderby} {$order}
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        $documents = [];
        foreach ($results ?: [] as $row) {
            $documents[] = [
                'post_id'      => (int) $row->post_id,
                'url'          => get_permalink((int) $row->post_id),
                'title'        => $row->title,
                'content_hash' => $row->content_hash,
                'chunk_count'  => (int) $row->chunk_count,
                'updated_at'   => $row->updated_at,
                'indexed_at'   => $row->indexed_at,
            ];
        }

        return $documents;
    }

    /**
     * Get recently updated documents for change feed.
     *
     * @param string $since ISO 8601 datetime string.
     * @param int    $limit Maximum documents to return.
     *
     * @return array<array{
     *     post_id: int,
     *     url: string,
     *     title: string,
     *     content_hash: string,
     *     updated_at: string,
     *     change_type: string
     * }>
     */
    public function getRecentlyUpdated(string $since, int $limit = 50): array {
        $sinceTimestamp = strtotime($since);

        if ($sinceTimestamp === false) {
            $sinceTimestamp = strtotime('-7 days');
        }

        $sinceDate = gmdate('Y-m-d H:i:s', $sinceTimestamp);
        $limit = max(1, min(1000, $limit));

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    d.id,
                    d.post_id,
                    d.title,
                    d.content_hash,
                    d.updated_at,
                    d.indexed_at,
                    d.created_at
                 FROM {$this->table} d
                 JOIN {$this->wpdb->posts} p ON d.post_id = p.ID
                 WHERE d.updated_at >= %s
                   AND d.status = 'indexed'
                   AND p.post_status = 'publish'
                 ORDER BY d.updated_at DESC
                 LIMIT %d",
                $sinceDate,
                $limit
            )
        );

        $documents = [];
        foreach ($results ?: [] as $row) {
            // Determine change type based on timestamps
            $changeType = 'modified';
            if ($row->created_at === $row->updated_at || $row->indexed_at === null) {
                $changeType = 'added';
            }

            $documents[] = [
                'post_id'      => (int) $row->post_id,
                'url'          => get_permalink((int) $row->post_id),
                'title'        => $row->title,
                'content_hash' => $row->content_hash,
                'updated_at'   => $row->updated_at,
                'change_type'  => $changeType,
            ];
        }

        return $documents;
    }

    /**
     * Get pinned/curated pages for llms.txt.
     *
     * @return array<int> Array of post IDs in pinned order.
     */
    public function getPinnedPages(): array {
        $pinned = get_option(self::PINNED_PAGES_OPTION, []);

        if (!is_array($pinned)) {
            return [];
        }

        return array_map('intval', $pinned);
    }

    /**
     * Set pinned pages order.
     *
     * @param array<int> $postIds Array of post IDs in desired order.
     *
     * @return bool True on success, false on failure.
     */
    public function setPinnedPages(array $postIds): bool {
        $sanitized = array_map('intval', array_filter($postIds, 'is_numeric'));

        return update_option(self::PINNED_PAGES_OPTION, $sanitized);
    }

    /**
     * Get total indexed document count.
     *
     * @return int Total number of indexed documents.
     */
    public function getDocumentCount(): int {
        $count = $this->wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$this->table}
             WHERE status = 'indexed'"
        );

        return (int) $count;
    }

    /**
     * Calculate a hash representing the current state of indexed content.
     *
     * Used for change detection by AI crawlers.
     *
     * @return string MD5 hash of current content state.
     */
    public function calculateContentHash(): string {
        $hashes = $this->wpdb->get_col(
            "SELECT content_hash
             FROM {$this->table}
             WHERE status = 'indexed'
             ORDER BY id ASC
             LIMIT 10000"
        );

        if (empty($hashes)) {
            return md5('empty');
        }

        return md5(implode('', $hashes));
    }
}

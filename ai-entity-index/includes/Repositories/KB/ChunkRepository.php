<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Repositories\KB;

/**
 * Repository for wp_ai_kb_chunks table operations.
 *
 * Manages chunk records in the Knowledge Base. Chunks are text segments
 * extracted from documents, ready for embedding and vector search.
 *
 * @package Vibe\AIIndex\Repositories\KB
 * @since 1.0.0
 */
class ChunkRepository {

    /**
     * WordPress database instance.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Chunks table name with prefix.
     *
     * @var string
     */
    private string $table;

    /**
     * Documents table name with prefix.
     *
     * @var string
     */
    private string $docs_table;

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
        $this->table         = $wpdb->prefix . 'ai_kb_chunks';
        $this->docs_table    = $wpdb->prefix . 'ai_kb_docs';
        $this->vectors_table = $wpdb->prefix . 'ai_kb_vectors';
    }

    /**
     * Insert multiple chunks for a document.
     *
     * Uses a transaction to ensure all chunks are inserted atomically.
     *
     * @param int $docId The document ID.
     * @param array<array{
     *   chunk_index: int,
     *   anchor: string,
     *   heading_path: array,
     *   chunk_text: string,
     *   chunk_hash: string,
     *   start_offset: int,
     *   end_offset: int,
     *   token_estimate: int
     * }> $chunks Array of chunk data.
     *
     * @return array<int> Inserted chunk IDs.
     */
    public function insertBatch(int $docId, array $chunks): array {
        if (empty($chunks)) {
            return [];
        }

        $inserted_ids = [];

        $this->wpdb->query('START TRANSACTION');

        try {
            foreach ($chunks as $chunk) {
                $chunk_index    = (int) ($chunk['chunk_index'] ?? 0);
                $anchor         = sanitize_text_field($chunk['anchor'] ?? '');
                $heading_path   = $chunk['heading_path'] ?? [];
                $chunk_text     = $chunk['chunk_text'] ?? '';
                $chunk_hash     = sanitize_text_field($chunk['chunk_hash'] ?? '');
                $start_offset   = (int) ($chunk['start_offset'] ?? 0);
                $end_offset     = (int) ($chunk['end_offset'] ?? 0);
                $token_estimate = (int) ($chunk['token_estimate'] ?? 0);

                // Encode heading path as JSON.
                $heading_path_json = wp_json_encode($heading_path);

                $this->wpdb->insert(
                    $this->table,
                    [
                        'doc_id'         => $docId,
                        'chunk_index'    => $chunk_index,
                        'anchor'         => $anchor,
                        'heading_path'   => $heading_path_json,
                        'chunk_text'     => $chunk_text,
                        'chunk_hash'     => $chunk_hash,
                        'start_offset'   => $start_offset,
                        'end_offset'     => $end_offset,
                        'token_estimate' => $token_estimate,
                        'created_at'     => current_time('mysql'),
                    ],
                    ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s']
                );

                $inserted_ids[] = (int) $this->wpdb->insert_id;
            }

            $this->wpdb->query('COMMIT');

            return $inserted_ids;
        } catch (\Exception $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Get chunk by ID.
     *
     * @param int $id The chunk ID.
     *
     * @return object|null The chunk object or null if not found.
     */
    public function find(int $id): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    doc_id,
                    chunk_index,
                    anchor,
                    heading_path,
                    chunk_text,
                    chunk_hash,
                    start_offset,
                    end_offset,
                    token_estimate,
                    created_at
                 FROM {$this->table}
                 WHERE id = %d",
                $id
            )
        );

        if ($result) {
            $result->heading_path = json_decode($result->heading_path, true) ?: [];
        }

        return $result ?: null;
    }

    /**
     * Get chunk by anchor.
     *
     * @param int    $docId  The document ID.
     * @param string $anchor The anchor identifier.
     *
     * @return object|null The chunk object or null if not found.
     */
    public function findByAnchor(int $docId, string $anchor): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    doc_id,
                    chunk_index,
                    anchor,
                    heading_path,
                    chunk_text,
                    chunk_hash,
                    start_offset,
                    end_offset,
                    token_estimate,
                    created_at
                 FROM {$this->table}
                 WHERE doc_id = %d AND anchor = %s",
                $docId,
                sanitize_text_field($anchor)
            )
        );

        if ($result) {
            $result->heading_path = json_decode($result->heading_path, true) ?: [];
        }

        return $result ?: null;
    }

    /**
     * Get all chunks for a document.
     *
     * @param int $docId The document ID.
     *
     * @return array<object> Array of chunk objects.
     */
    public function getByDocId(int $docId): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    doc_id,
                    chunk_index,
                    anchor,
                    heading_path,
                    chunk_text,
                    chunk_hash,
                    start_offset,
                    end_offset,
                    token_estimate,
                    created_at
                 FROM {$this->table}
                 WHERE doc_id = %d
                 ORDER BY chunk_index ASC",
                $docId
            )
        );

        if ($results) {
            foreach ($results as $result) {
                $result->heading_path = json_decode($result->heading_path, true) ?: [];
            }
        }

        return $results ?: [];
    }

    /**
     * Get chunks by IDs.
     *
     * @param array $ids Array of chunk IDs.
     *
     * @return array<object> Array of chunk objects.
     */
    public function getByIds(array $ids): array {
        if (empty($ids)) {
            return [];
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    doc_id,
                    chunk_index,
                    anchor,
                    heading_path,
                    chunk_text,
                    chunk_hash,
                    start_offset,
                    end_offset,
                    token_estimate,
                    created_at
                 FROM {$this->table}
                 WHERE id IN ({$placeholders})
                 ORDER BY doc_id ASC, chunk_index ASC",
                ...$ids
            )
        );

        if ($results) {
            foreach ($results as $result) {
                $result->heading_path = json_decode($result->heading_path, true) ?: [];
            }
        }

        return $results ?: [];
    }

    /**
     * Delete all chunks for a document.
     *
     * Also deletes associated vectors via transaction.
     *
     * @param int $docId The document ID.
     *
     * @return void
     */
    public function deleteByDocId(int $docId): void {
        $this->wpdb->query('START TRANSACTION');

        try {
            // Get chunk IDs.
            $chunk_ids = $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE doc_id = %d",
                    $docId
                )
            );

            // Delete vectors for these chunks.
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
                $this->table,
                ['doc_id' => $docId],
                ['%d']
            );

            $this->wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Delete specific chunks by hash (for incremental updates).
     *
     * @param int   $docId  The document ID.
     * @param array $hashes Array of chunk hashes to delete.
     *
     * @return void
     */
    public function deleteByHashes(int $docId, array $hashes): void {
        if (empty($hashes)) {
            return;
        }

        $hashes = array_map('sanitize_text_field', $hashes);
        $placeholders = implode(', ', array_fill(0, count($hashes), '%s'));

        $this->wpdb->query('START TRANSACTION');

        try {
            // Get chunk IDs for these hashes.
            $chunk_ids = $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->table}
                     WHERE doc_id = %d AND chunk_hash IN ({$placeholders})",
                    $docId,
                    ...$hashes
                )
            );

            // Delete vectors for these chunks.
            if (!empty($chunk_ids)) {
                $id_placeholders = implode(', ', array_fill(0, count($chunk_ids), '%d'));
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        "DELETE FROM {$this->vectors_table} WHERE chunk_id IN ({$id_placeholders})",
                        ...$chunk_ids
                    )
                );
            }

            // Delete chunks.
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$this->table}
                     WHERE doc_id = %d AND chunk_hash IN ({$placeholders})",
                    $docId,
                    ...$hashes
                )
            );

            $this->wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Get chunk IDs that don't match given hashes (stale chunks).
     *
     * Useful for identifying chunks that need to be removed during
     * incremental updates when content has changed.
     *
     * @param int   $docId         The document ID.
     * @param array $currentHashes Array of current valid chunk hashes.
     *
     * @return array Array of stale chunk IDs.
     */
    public function getStaleChunkIds(int $docId, array $currentHashes): array {
        if (empty($currentHashes)) {
            // If no current hashes, all chunks for this doc are stale.
            return $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE doc_id = %d",
                    $docId
                )
            );
        }

        $currentHashes = array_map('sanitize_text_field', $currentHashes);
        $placeholders  = implode(', ', array_fill(0, count($currentHashes), '%s'));

        $results = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table}
                 WHERE doc_id = %d AND chunk_hash NOT IN ({$placeholders})",
                $docId,
                ...$currentHashes
            )
        );

        return array_map('intval', $results ?: []);
    }

    /**
     * Get chunks without vectors (need embedding).
     *
     * Returns chunks that haven't been embedded yet.
     *
     * @param int $limit Maximum number of chunks to return.
     *
     * @return array Array of chunk objects with document info.
     */
    public function getChunksWithoutVectors(int $limit = 50): array {
        $limit = max(1, min(500, $limit));

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    c.id,
                    c.doc_id,
                    c.chunk_index,
                    c.anchor,
                    c.heading_path,
                    c.chunk_text,
                    c.chunk_hash,
                    c.start_offset,
                    c.end_offset,
                    c.token_estimate,
                    c.created_at,
                    d.post_id,
                    d.title AS doc_title
                 FROM {$this->table} c
                 JOIN {$this->docs_table} d ON c.doc_id = d.id
                 LEFT JOIN {$this->vectors_table} v ON c.id = v.chunk_id
                 WHERE v.id IS NULL
                   AND d.status NOT IN ('error', 'excluded')
                 ORDER BY c.created_at ASC
                 LIMIT %d",
                $limit
            )
        );

        if ($results) {
            foreach ($results as $result) {
                $result->heading_path = json_decode($result->heading_path, true) ?: [];
            }
        }

        return $results ?: [];
    }

    /**
     * Get total chunk count.
     *
     * @param array $filters Optional filters.
     *
     * @return int Total count of chunks.
     */
    public function getCount(array $filters = []): int {
        $where_clauses = [];
        $where_values  = [];

        if (!empty($filters['doc_id'])) {
            $where_clauses[] = 'doc_id = %d';
            $where_values[]  = (int) $filters['doc_id'];
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

    /**
     * Get failed/error chunks.
     *
     * Returns chunks from documents that are in error status.
     *
     * @param int $limit Maximum number of chunks to return.
     *
     * @return array Array of chunk objects with error information.
     */
    public function getFailedChunks(int $limit = 100): array {
        $limit = max(1, min(500, $limit));

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    c.id,
                    c.doc_id,
                    c.chunk_index,
                    c.anchor,
                    c.heading_path,
                    c.chunk_text,
                    c.chunk_hash,
                    c.start_offset,
                    c.end_offset,
                    c.token_estimate,
                    c.created_at,
                    d.post_id,
                    d.title AS doc_title,
                    d.status AS doc_status
                 FROM {$this->table} c
                 JOIN {$this->docs_table} d ON c.doc_id = d.id
                 WHERE d.status = 'error'
                 ORDER BY c.created_at DESC
                 LIMIT %d",
                $limit
            )
        );

        if ($results) {
            foreach ($results as $result) {
                $result->heading_path = json_decode($result->heading_path, true) ?: [];
            }
        }

        return $results ?: [];
    }

    /**
     * Update chunk text and hash.
     *
     * Used when re-processing a chunk with updated content.
     *
     * @param int    $id         The chunk ID.
     * @param string $chunkText  The new chunk text.
     * @param string $chunkHash  The new chunk hash.
     *
     * @return bool True on success, false on failure.
     */
    public function updateChunkContent(int $id, string $chunkText, string $chunkHash): bool {
        $result = $this->wpdb->update(
            $this->table,
            [
                'chunk_text' => $chunkText,
                'chunk_hash' => sanitize_text_field($chunkHash),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get chunks for a list of documents.
     *
     * @param array $docIds Array of document IDs.
     *
     * @return array Array of chunk objects grouped by doc_id.
     */
    public function getByDocIds(array $docIds): array {
        if (empty($docIds)) {
            return [];
        }

        $docIds = array_map('intval', $docIds);
        $placeholders = implode(', ', array_fill(0, count($docIds), '%d'));

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    id,
                    doc_id,
                    chunk_index,
                    anchor,
                    heading_path,
                    chunk_text,
                    chunk_hash,
                    start_offset,
                    end_offset,
                    token_estimate,
                    created_at
                 FROM {$this->table}
                 WHERE doc_id IN ({$placeholders})
                 ORDER BY doc_id ASC, chunk_index ASC",
                ...$docIds
            )
        );

        $grouped = [];
        if ($results) {
            foreach ($results as $result) {
                $result->heading_path = json_decode($result->heading_path, true) ?: [];
                $doc_id = (int) $result->doc_id;

                if (!isset($grouped[$doc_id])) {
                    $grouped[$doc_id] = [];
                }

                $grouped[$doc_id][] = $result;
            }
        }

        return $grouped;
    }
}

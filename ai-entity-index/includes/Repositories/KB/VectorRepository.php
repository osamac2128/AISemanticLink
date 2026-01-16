<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Repositories\KB;

use Vibe\AIIndex\Services\KB\VectorStore\VectorStoreInterface;
use Vibe\AIIndex\Services\KB\VectorStore\MySQLVectorStore;

/**
 * Repository wrapper for vector operations.
 *
 * Delegates to VectorStoreInterface implementation for actual storage.
 * This abstraction allows swapping between MySQL, Pinecone, or other
 * vector storage backends.
 *
 * @package Vibe\AIIndex\Repositories\KB
 * @since 1.0.0
 */
class VectorRepository {

    /**
     * Vector store implementation.
     *
     * @var VectorStoreInterface
     */
    private VectorStoreInterface $store;

    /**
     * WordPress database instance.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Vectors table name with prefix.
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
     * Documents table name with prefix.
     *
     * @var string
     */
    private string $docs_table;

    /**
     * Initialize the repository with database connection and vector store.
     *
     * @param VectorStoreInterface|null $store Optional vector store implementation.
     */
    public function __construct(?VectorStoreInterface $store = null) {
        global $wpdb;
        $this->wpdb         = $wpdb;
        $this->table        = $wpdb->prefix . 'ai_kb_vectors';
        $this->chunks_table = $wpdb->prefix . 'ai_kb_chunks';
        $this->docs_table   = $wpdb->prefix . 'ai_kb_docs';
        $this->store        = $store ?? new MySQLVectorStore();
    }

    /**
     * Store vector for chunk.
     *
     * @param int      $chunkId The chunk ID.
     * @param array    $vector  The embedding vector.
     * @param string   $model   The embedding model name.
     * @param int|null $dims    Vector dimensions (auto-detected if null).
     *
     * @return void
     */
    public function store(int $chunkId, array $vector, string $model, ?int $dims = null): void {
        $dims = $dims ?? count($vector);

        // Serialize vector to binary format for efficient storage.
        $vector_blob = $this->serializeVector($vector);

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->table}
                    (chunk_id, vector, model, dims, created_at)
                 VALUES (%d, %s, %s, %d, NOW())
                 ON DUPLICATE KEY UPDATE
                    vector = VALUES(vector),
                    model = VALUES(model),
                    dims = VALUES(dims),
                    created_at = NOW()",
                $chunkId,
                $vector_blob,
                sanitize_text_field($model),
                $dims
            )
        );
    }

    /**
     * Delete vector for chunk.
     *
     * @param int $chunkId The chunk ID.
     *
     * @return void
     */
    public function delete(int $chunkId): void {
        $this->wpdb->delete(
            $this->table,
            ['chunk_id' => $chunkId],
            ['%d']
        );
    }

    /**
     * Delete all vectors for document.
     *
     * @param int $docId The document ID.
     *
     * @return void
     */
    public function deleteByDocId(int $docId): void {
        // Get chunk IDs for this document.
        $chunk_ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->chunks_table} WHERE doc_id = %d",
                $docId
            )
        );

        if (empty($chunk_ids)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($chunk_ids), '%d'));
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE chunk_id IN ({$placeholders})",
                ...$chunk_ids
            )
        );
    }

    /**
     * Search for similar vectors.
     *
     * Performs cosine similarity search against stored vectors.
     *
     * @param array $queryVector The query embedding vector.
     * @param int   $topK        Number of results to return.
     * @param array $filters     Optional filters (post_type, post_id, etc.).
     *
     * @return array Array of results with chunk data and similarity scores.
     */
    public function search(array $queryVector, int $topK = 8, array $filters = []): array {
        // Delegate to vector store for search.
        return $this->store->search($queryVector, $topK, $filters);
    }

    /**
     * Check if chunk has vector.
     *
     * @param int $chunkId The chunk ID.
     *
     * @return bool True if vector exists.
     */
    public function hasVector(int $chunkId): bool {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT 1 FROM {$this->table} WHERE chunk_id = %d LIMIT 1",
                $chunkId
            )
        );

        return $result !== null;
    }

    /**
     * Get vector metadata (model, dims, created_at).
     *
     * @param int $chunkId The chunk ID.
     *
     * @return array|null Array with model, dims, created_at or null if not found.
     */
    public function getMetadata(int $chunkId): ?array {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT model, dims, created_at
                 FROM {$this->table}
                 WHERE chunk_id = %d",
                $chunkId
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Get total vector count.
     *
     * @return int Total number of vectors.
     */
    public function getCount(): int {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}"
        );
    }

    /**
     * Get chunk IDs with vectors for a document.
     *
     * @param int $docId The document ID.
     *
     * @return array Array of chunk IDs.
     */
    public function getChunkIdsWithVectors(int $docId): array {
        $results = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT v.chunk_id
                 FROM {$this->table} v
                 JOIN {$this->chunks_table} c ON v.chunk_id = c.id
                 WHERE c.doc_id = %d",
                $docId
            )
        );

        return array_map('intval', $results ?: []);
    }

    /**
     * Bulk check which chunks have vectors.
     *
     * @param array $chunkIds Array of chunk IDs to check.
     *
     * @return array<int> Chunk IDs that have vectors.
     */
    public function filterChunksWithVectors(array $chunkIds): array {
        if (empty($chunkIds)) {
            return [];
        }

        $chunkIds = array_map('intval', $chunkIds);
        $placeholders = implode(', ', array_fill(0, count($chunkIds), '%d'));

        $results = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT chunk_id FROM {$this->table} WHERE chunk_id IN ({$placeholders})",
                ...$chunkIds
            )
        );

        return array_map('intval', $results ?: []);
    }

    /**
     * Store multiple vectors in batch.
     *
     * @param array $vectors Array of [chunkId => vector] pairs.
     * @param string $model  The embedding model name.
     *
     * @return void
     */
    public function storeBatch(array $vectors, string $model): void {
        if (empty($vectors)) {
            return;
        }

        $this->wpdb->query('START TRANSACTION');

        try {
            foreach ($vectors as $chunkId => $vector) {
                $this->store((int) $chunkId, $vector, $model);
            }

            $this->wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Get vector by chunk ID.
     *
     * @param int $chunkId The chunk ID.
     *
     * @return array|null The vector array or null if not found.
     */
    public function getVector(int $chunkId): ?array {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT vector FROM {$this->table} WHERE chunk_id = %d",
                $chunkId
            )
        );

        if ($result === null) {
            return null;
        }

        return $this->deserializeVector($result);
    }

    /**
     * Get vectors by chunk IDs.
     *
     * @param array $chunkIds Array of chunk IDs.
     *
     * @return array Associative array of chunkId => vector.
     */
    public function getVectorsByChunkIds(array $chunkIds): array {
        if (empty($chunkIds)) {
            return [];
        }

        $chunkIds = array_map('intval', $chunkIds);
        $placeholders = implode(', ', array_fill(0, count($chunkIds), '%d'));

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT chunk_id, vector FROM {$this->table} WHERE chunk_id IN ({$placeholders})",
                ...$chunkIds
            )
        );

        $vectors = [];
        foreach ($results as $row) {
            $vectors[(int) $row->chunk_id] = $this->deserializeVector($row->vector);
        }

        return $vectors;
    }

    /**
     * Get statistics about stored vectors.
     *
     * @return array{total: int, by_model: array<string, int>}
     */
    public function getStats(): array {
        $total = $this->getCount();

        $by_model_results = $this->wpdb->get_results(
            "SELECT model, COUNT(*) as count
             FROM {$this->table}
             GROUP BY model"
        );

        $by_model = [];
        foreach ($by_model_results as $row) {
            $by_model[$row->model] = (int) $row->count;
        }

        return [
            'total'    => $total,
            'by_model' => $by_model,
        ];
    }

    /**
     * Serialize vector to binary format.
     *
     * Stores floats as 32-bit IEEE 754 format for efficiency.
     *
     * @param array $vector The vector array.
     *
     * @return string Binary representation.
     */
    private function serializeVector(array $vector): string {
        return pack('f*', ...$vector);
    }

    /**
     * Deserialize binary vector to array.
     *
     * @param string $blob Binary representation.
     *
     * @return array The vector array.
     */
    private function deserializeVector(string $blob): array {
        $floats = unpack('f*', $blob);
        return array_values($floats);
    }

    /**
     * Delete vectors that reference non-existent chunks.
     *
     * Maintenance operation to clean up orphaned vectors.
     *
     * @return int Number of deleted vectors.
     */
    public function deleteOrphaned(): int {
        $result = $this->wpdb->query(
            "DELETE v FROM {$this->table} v
             LEFT JOIN {$this->chunks_table} c ON v.chunk_id = c.id
             WHERE c.id IS NULL"
        );

        return (int) $result;
    }

    /**
     * Get the vector store implementation.
     *
     * @return VectorStoreInterface
     */
    public function getStore(): VectorStoreInterface {
        return $this->store;
    }

    /**
     * Set the vector store implementation.
     *
     * @param VectorStoreInterface $store The vector store to use.
     *
     * @return void
     */
    public function setStore(VectorStoreInterface $store): void {
        $this->store = $store;
    }
}

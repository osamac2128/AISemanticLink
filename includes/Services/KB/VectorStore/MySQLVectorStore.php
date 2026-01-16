<?php
/**
 * MySQL Vector Store Implementation
 *
 * @package Vibe\AIIndex\Services\KB\VectorStore
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB\VectorStore;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Logger;

/**
 * MySQL-based vector storage using LONGBLOB for packed floats.
 * Brute-force cosine similarity with filter pre-screening.
 */
class MySQLVectorStore implements VectorStoreInterface
{
    /**
     * WordPress database instance.
     */
    private \wpdb $wpdb;

    /**
     * Vectors table name with prefix.
     */
    private string $vectorsTable;

    /**
     * Chunks table name with prefix.
     */
    private string $chunksTable;

    /**
     * Documents table name with prefix.
     */
    private string $docsTable;

    /**
     * Logger instance.
     */
    private Logger $logger;

    /**
     * Constructor.
     */
    public function __construct()
    {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->vectorsTable = $wpdb->prefix . Config::TABLE_KB_VECTORS;
        $this->chunksTable = $wpdb->prefix . Config::TABLE_KB_CHUNKS;
        $this->docsTable = $wpdb->prefix . Config::TABLE_KB_DOCS;
        $this->logger = new Logger();
    }

    /**
     * Store a vector for a chunk.
     *
     * @param int $chunkId The chunk ID to associate with the vector
     * @param array<float> $vector The embedding vector
     * @param array<string, mixed> $metadata Optional metadata for the vector
     *
     * @throws \RuntimeException If storage fails
     */
    public function store(int $chunkId, array $vector, array $metadata = []): void
    {
        if (empty($vector)) {
            throw new \InvalidArgumentException('Vector cannot be empty');
        }

        $packedVector = $this->packVector($vector);
        $model = $metadata['model'] ?? Config::KB_EMBEDDING_MODEL;
        $dims = count($vector);

        // Check if vector already exists for this chunk
        if ($this->exists($chunkId)) {
            // Update existing vector
            $result = $this->wpdb->update(
                $this->vectorsTable,
                [
                    'vector_payload' => $packedVector,
                    'model' => $model,
                    'dims' => $dims,
                ],
                ['chunk_id' => $chunkId],
                ['%s', '%s', '%d'],
                ['%d']
            );
        } else {
            // Insert new vector
            $result = $this->wpdb->insert(
                $this->vectorsTable,
                [
                    'chunk_id' => $chunkId,
                    'vector_payload' => $packedVector,
                    'provider' => 'openrouter',
                    'model' => $model,
                    'dims' => $dims,
                ],
                ['%d', '%s', '%s', '%s', '%d']
            );
        }

        if ($result === false) {
            $this->logger->error('Failed to store vector', [
                'chunk_id' => $chunkId,
                'error' => $this->wpdb->last_error,
            ]);
            throw new \RuntimeException('Failed to store vector: ' . $this->wpdb->last_error);
        }

        $this->logger->debug('Vector stored', [
            'chunk_id' => $chunkId,
            'dims' => $dims,
        ]);
    }

    /**
     * Delete vector for a chunk.
     *
     * @param int $chunkId The chunk ID whose vector should be deleted
     *
     * @return bool True if a vector was deleted, false if not found
     */
    public function delete(int $chunkId): bool
    {
        $result = $this->wpdb->delete(
            $this->vectorsTable,
            ['chunk_id' => $chunkId],
            ['%d']
        );

        if ($result === false) {
            $this->logger->warning('Failed to delete vector', [
                'chunk_id' => $chunkId,
                'error' => $this->wpdb->last_error,
            ]);
            return false;
        }

        return $result > 0;
    }

    /**
     * Delete all vectors for a document's chunks.
     *
     * @param int $docId The document ID whose chunk vectors should be deleted
     *
     * @return int Number of vectors deleted
     */
    public function deleteByDocId(int $docId): int
    {
        // Get all chunk IDs for this document
        $chunkIds = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->chunksTable} WHERE doc_id = %d",
                $docId
            )
        );

        if (empty($chunkIds)) {
            return 0;
        }

        // Delete vectors for all chunks
        $placeholders = implode(',', array_fill(0, count($chunkIds), '%d'));
        $deletedCount = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->vectorsTable} WHERE chunk_id IN ({$placeholders})",
                ...$chunkIds
            )
        );

        if ($deletedCount === false) {
            $this->logger->warning('Failed to delete vectors by doc_id', [
                'doc_id' => $docId,
                'error' => $this->wpdb->last_error,
            ]);
            return 0;
        }

        $this->logger->debug('Vectors deleted for document', [
            'doc_id' => $docId,
            'count' => $deletedCount,
        ]);

        return (int) $deletedCount;
    }

    /**
     * Search for similar vectors.
     *
     * @param array<float> $queryVector The query embedding vector
     * @param int $topK Number of results to return
     * @param array<string, mixed> $filters Optional filters (post_type, taxonomy, etc.)
     *
     * @return array<array{chunk_id: int, score: float, metadata: array}>
     *
     * @throws \RuntimeException If search fails
     */
    public function search(array $queryVector, int $topK = 8, array $filters = []): array
    {
        if (empty($queryVector)) {
            throw new \InvalidArgumentException('Query vector cannot be empty');
        }

        $startTime = microtime(true);

        // Apply filters to get candidate chunk IDs
        $candidateChunkIds = $this->applyFilters($filters);

        // If filters produced no candidates, return empty
        if (!empty($filters) && empty($candidateChunkIds)) {
            $this->logger->debug('Search returned no candidates after filtering', [
                'filters' => array_keys($filters),
            ]);
            return [];
        }

        // Build query to fetch vectors
        $query = "SELECT v.chunk_id, v.vector_payload, v.model, c.doc_id
                  FROM {$this->vectorsTable} v
                  JOIN {$this->chunksTable} c ON v.chunk_id = c.id";

        $params = [];

        if (!empty($candidateChunkIds)) {
            // Respect max scan limit
            $scanLimit = Config::KB_MAX_SCAN_VECTORS;
            if (count($candidateChunkIds) > $scanLimit) {
                $candidateChunkIds = array_slice($candidateChunkIds, 0, $scanLimit);
            }

            $placeholders = implode(',', array_fill(0, count($candidateChunkIds), '%d'));
            $query .= " WHERE v.chunk_id IN ({$placeholders})";
            $params = $candidateChunkIds;
        } else {
            // No filters, but still respect scan limit
            $query .= sprintf(' LIMIT %d', Config::KB_MAX_SCAN_VECTORS);
        }

        // Execute query
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($query, ...$params);
        } else {
            $sql = $query;
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if ($rows === null) {
            $this->logger->error('Vector search query failed', [
                'error' => $this->wpdb->last_error,
            ]);
            throw new \RuntimeException('Vector search failed: ' . $this->wpdb->last_error);
        }

        // Calculate similarities
        $similarities = [];
        $vectorsScanned = 0;

        foreach ($rows as $row) {
            $vector = $this->unpackVector($row['vector_payload']);
            if (empty($vector)) {
                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $vector);
            $vectorsScanned++;

            $similarities[] = [
                'chunk_id' => (int) $row['chunk_id'],
                'score' => $score,
                'metadata' => [
                    'doc_id' => (int) $row['doc_id'],
                    'model' => $row['model'],
                ],
            ];
        }

        // Sort by score descending
        usort($similarities, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Take top K
        $results = array_slice($similarities, 0, $topK);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        $this->logger->debug('Vector search completed', [
            'vectors_scanned' => $vectorsScanned,
            'results_count' => count($results),
            'top_k' => $topK,
            'duration_ms' => $duration,
        ]);

        return $results;
    }

    /**
     * Get total vector count.
     *
     * @param array<string, mixed> $filters Optional filters to apply
     *
     * @return int Total number of vectors matching filters
     */
    public function count(array $filters = []): int
    {
        if (empty($filters)) {
            $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->vectorsTable}");
            return (int) $count;
        }

        // Apply filters to get candidate chunk IDs
        $candidateChunkIds = $this->applyFilters($filters);

        if (empty($candidateChunkIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($candidateChunkIds), '%d'));
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->vectorsTable} WHERE chunk_id IN ({$placeholders})",
                ...$candidateChunkIds
            )
        );

        return (int) $count;
    }

    /**
     * Check if vector exists for chunk.
     *
     * @param int $chunkId The chunk ID to check
     *
     * @return bool True if vector exists
     */
    public function exists(int $chunkId): bool
    {
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT 1 FROM {$this->vectorsTable} WHERE chunk_id = %d LIMIT 1",
                $chunkId
            )
        );

        return $exists !== null;
    }

    /**
     * Get vector by chunk ID.
     *
     * @param int $chunkId The chunk ID
     *
     * @return array<float>|null The vector or null if not found
     */
    public function get(int $chunkId): ?array
    {
        $payload = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT vector_payload FROM {$this->vectorsTable} WHERE chunk_id = %d",
                $chunkId
            )
        );

        if ($payload === null) {
            return null;
        }

        return $this->unpackVector($payload);
    }

    /**
     * Pack float array to binary for storage.
     *
     * @param array<float> $vector The vector to pack
     *
     * @return string Binary representation
     */
    private function packVector(array $vector): string
    {
        return pack('f*', ...$vector);
    }

    /**
     * Unpack binary to float array.
     *
     * @param string $binary The binary data to unpack
     *
     * @return array<float> The unpacked vector
     */
    private function unpackVector(string $binary): array
    {
        if (empty($binary)) {
            return [];
        }

        $unpacked = unpack('f*', $binary);

        if ($unpacked === false) {
            return [];
        }

        // unpack returns 1-indexed array, reindex to 0-indexed
        return array_values($unpacked);
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param array<float> $a First vector
     * @param array<float> $b Second vector
     *
     * @return float Cosine similarity score (0.0 to 1.0)
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new \InvalidArgumentException('Vectors must have the same dimensions');
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $length = count($a);

        for ($i = 0; $i < $length; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Pre-filter chunk IDs by post_type, taxonomy, date, etc.
     *
     * @param array<string, mixed> $filters Filter criteria
     *
     * @return array<int> Chunk IDs to scan
     */
    private function applyFilters(array $filters): array
    {
        if (empty($filters)) {
            return [];
        }

        $query = "SELECT c.id FROM {$this->chunksTable} c
                  JOIN {$this->docsTable} d ON c.doc_id = d.id
                  WHERE 1=1";

        $params = [];

        // Filter by post_type
        if (!empty($filters['post_type'])) {
            $postTypes = (array) $filters['post_type'];
            $placeholders = implode(',', array_fill(0, count($postTypes), '%s'));
            $query .= " AND d.post_type IN ({$placeholders})";
            $params = array_merge($params, $postTypes);
        }

        // Filter by post_id
        if (!empty($filters['post_id'])) {
            $postIds = (array) $filters['post_id'];
            $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
            $query .= " AND d.post_id IN ({$placeholders})";
            $params = array_merge($params, $postIds);
        }

        // Filter by doc_id
        if (!empty($filters['doc_id'])) {
            $docIds = (array) $filters['doc_id'];
            $placeholders = implode(',', array_fill(0, count($docIds), '%d'));
            $query .= " AND d.id IN ({$placeholders})";
            $params = array_merge($params, $docIds);
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $query .= ' AND d.status = %s';
            $params[] = $filters['status'];
        }

        // Filter by date range
        if (!empty($filters['date_after'])) {
            $query .= ' AND d.created_at >= %s';
            $params[] = $filters['date_after'];
        }

        if (!empty($filters['date_before'])) {
            $query .= ' AND d.created_at <= %s';
            $params[] = $filters['date_before'];
        }

        // Limit to max scan
        $query .= sprintf(' LIMIT %d', Config::KB_MAX_SCAN_VECTORS);

        // Execute query
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($query, ...$params);
        } else {
            $sql = $query;
        }

        $chunkIds = $this->wpdb->get_col($sql);

        return array_map('intval', $chunkIds);
    }
}

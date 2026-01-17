<?php
/**
 * Vector Store Interface
 *
 * @package Vibe\AIIndex\Services\KB\VectorStore
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB\VectorStore;

/**
 * Interface for vector storage backends.
 * Allows swapping MySQL for pgvector, Qdrant, Pinecone later.
 */
interface VectorStoreInterface
{
    /**
     * Store a vector for a chunk.
     *
     * @param int $chunkId The chunk ID to associate with the vector
     * @param array<float> $vector The embedding vector
     * @param array<string, mixed> $metadata Optional metadata for the vector
     *
     * @throws \RuntimeException If storage fails
     */
    public function store(int $chunkId, array $vector, array $metadata = []): void;

    /**
     * Delete vector for a chunk.
     *
     * @param int $chunkId The chunk ID whose vector should be deleted
     *
     * @return bool True if a vector was deleted, false if not found
     */
    public function delete(int $chunkId): bool;

    /**
     * Delete all vectors for a document's chunks.
     *
     * @param int $docId The document ID whose chunk vectors should be deleted
     *
     * @return int Number of vectors deleted
     */
    public function deleteByDocId(int $docId): int;

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
    public function search(array $queryVector, int $topK = 8, array $filters = []): array;

    /**
     * Get total vector count.
     *
     * @param array<string, mixed> $filters Optional filters to apply
     *
     * @return int Total number of vectors matching filters
     */
    public function count(array $filters = []): int;

    /**
     * Check if vector exists for chunk.
     *
     * @param int $chunkId The chunk ID to check
     *
     * @return bool True if vector exists
     */
    public function exists(int $chunkId): bool;

    /**
     * Get vector by chunk ID.
     *
     * @param int $chunkId The chunk ID
     *
     * @return array<float>|null The vector or null if not found
     */
    public function get(int $chunkId): ?array;
}

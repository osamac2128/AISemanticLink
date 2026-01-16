<?php
/**
 * Similarity Search Service
 *
 * @package Vibe\AIIndex\Services\KB
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Logger;
use Vibe\AIIndex\Services\KB\VectorStore\VectorStoreInterface;

/**
 * High-level search service that combines embedding + vector search.
 */
class SimilaritySearch
{
    /**
     * Embedding client for generating query vectors.
     */
    private EmbeddingClient $embeddingClient;

    /**
     * Vector store for similarity search.
     */
    private VectorStoreInterface $vectorStore;

    /**
     * WordPress database instance.
     */
    private \wpdb $wpdb;

    /**
     * Logger instance.
     */
    private Logger $logger;

    /**
     * Chunks table name with prefix.
     */
    private string $chunksTable;

    /**
     * Documents table name with prefix.
     */
    private string $docsTable;

    /**
     * Constructor.
     *
     * @param EmbeddingClient $embeddingClient Client for generating embeddings
     * @param VectorStoreInterface $vectorStore Store for vector operations
     */
    public function __construct(
        EmbeddingClient $embeddingClient,
        VectorStoreInterface $vectorStore
    ) {
        global $wpdb;

        $this->embeddingClient = $embeddingClient;
        $this->vectorStore = $vectorStore;
        $this->wpdb = $wpdb;
        $this->logger = new Logger();
        $this->chunksTable = $wpdb->prefix . Config::TABLE_KB_CHUNKS;
        $this->docsTable = $wpdb->prefix . Config::TABLE_KB_DOCS;
    }

    /**
     * Search for similar content.
     *
     * @param string $query The search query text
     * @param int $topK Number of results to return
     * @param array<string, mixed> $filters Optional filters (post_type, taxonomy, etc.)
     *
     * @return array<array{
     *   chunk_id: int,
     *   doc_id: int,
     *   post_id: int,
     *   title: string,
     *   url: string,
     *   anchor: string,
     *   heading_path: array,
     *   chunk_text: string,
     *   score: float
     * }>
     *
     * @throws \RuntimeException If search fails
     */
    public function search(string $query, int $topK = 8, array $filters = []): array
    {
        if (empty(trim($query))) {
            throw new \InvalidArgumentException('Search query cannot be empty');
        }

        $startTime = microtime(true);

        $this->logger->debug('Starting similarity search', [
            'query_length' => strlen($query),
            'top_k' => $topK,
            'has_filters' => !empty($filters),
        ]);

        try {
            // Generate embedding for the query
            $queryVector = $this->embeddingClient->embedSingle($query);

            // Search for similar vectors
            $vectorResults = $this->vectorStore->search($queryVector, $topK, $filters);

            if (empty($vectorResults)) {
                $this->logger->debug('No similar vectors found');
                return [];
            }

            // Enrich results with document metadata
            $enrichedResults = $this->enrichResults($vectorResults);

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            $this->logger->info('Similarity search completed', [
                'results_count' => count($enrichedResults),
                'duration_ms' => $duration,
            ]);

            return $enrichedResults;
        } catch (\Exception $e) {
            $this->logger->error('Similarity search failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Search with pre-computed query vector.
     *
     * Useful when the query vector is already available (e.g., cached or from another source).
     *
     * @param array<float> $queryVector The pre-computed query vector
     * @param int $topK Number of results to return
     * @param array<string, mixed> $filters Optional filters
     *
     * @return array<array{
     *   chunk_id: int,
     *   doc_id: int,
     *   post_id: int,
     *   title: string,
     *   url: string,
     *   anchor: string,
     *   heading_path: array,
     *   chunk_text: string,
     *   score: float
     * }>
     */
    public function searchWithVector(array $queryVector, int $topK = 8, array $filters = []): array
    {
        if (empty($queryVector)) {
            throw new \InvalidArgumentException('Query vector cannot be empty');
        }

        $vectorResults = $this->vectorStore->search($queryVector, $topK, $filters);

        if (empty($vectorResults)) {
            return [];
        }

        return $this->enrichResults($vectorResults);
    }

    /**
     * Find documents similar to a given document.
     *
     * @param int $postId The post ID to find similar content for
     * @param int $topK Number of results to return
     * @param array<string, mixed> $filters Optional filters
     *
     * @return array<array{
     *   chunk_id: int,
     *   doc_id: int,
     *   post_id: int,
     *   title: string,
     *   url: string,
     *   anchor: string,
     *   heading_path: array,
     *   chunk_text: string,
     *   score: float
     * }>
     */
    public function findSimilarToPost(int $postId, int $topK = 8, array $filters = []): array
    {
        // Get the document's first chunk vector as representative
        $docId = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->docsTable} WHERE post_id = %d",
                $postId
            )
        );

        if (!$docId) {
            $this->logger->debug('Document not found for post', ['post_id' => $postId]);
            return [];
        }

        // Get the first chunk for this document
        $chunkId = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->chunksTable} WHERE doc_id = %d ORDER BY chunk_index ASC LIMIT 1",
                $docId
            )
        );

        if (!$chunkId) {
            $this->logger->debug('No chunks found for document', ['doc_id' => $docId]);
            return [];
        }

        // Get the vector for this chunk
        $vector = $this->vectorStore->get((int) $chunkId);

        if (!$vector) {
            $this->logger->debug('No vector found for chunk', ['chunk_id' => $chunkId]);
            return [];
        }

        // Exclude the source document from results
        $excludeFilters = array_merge($filters, [
            'exclude_doc_id' => (int) $docId,
        ]);

        // Search for similar content, getting extra results to account for exclusions
        $results = $this->searchWithVector($vector, $topK + 5, $filters);

        // Filter out results from the same document
        $filteredResults = array_filter($results, function ($result) use ($docId) {
            return $result['doc_id'] !== (int) $docId;
        });

        // Re-index and limit to topK
        return array_slice(array_values($filteredResults), 0, $topK);
    }

    /**
     * Enrich results with document metadata.
     *
     * @param array<array{chunk_id: int, score: float, metadata: array}> $results Vector search results
     *
     * @return array<array{
     *   chunk_id: int,
     *   doc_id: int,
     *   post_id: int,
     *   title: string,
     *   url: string,
     *   anchor: string,
     *   heading_path: array,
     *   chunk_text: string,
     *   score: float
     * }>
     */
    private function enrichResults(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        // Extract chunk IDs
        $chunkIds = array_column($results, 'chunk_id');

        // Create score lookup
        $scoreMap = [];
        foreach ($results as $result) {
            $scoreMap[$result['chunk_id']] = $result['score'];
        }

        // Fetch chunk and document data in one query
        $placeholders = implode(',', array_fill(0, count($chunkIds), '%d'));
        $query = "SELECT
                    c.id AS chunk_id,
                    c.doc_id,
                    c.anchor,
                    c.heading_path_json,
                    c.chunk_text,
                    d.post_id,
                    d.title,
                    d.url
                  FROM {$this->chunksTable} c
                  JOIN {$this->docsTable} d ON c.doc_id = d.id
                  WHERE c.id IN ({$placeholders})";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($query, ...$chunkIds),
            ARRAY_A
        );

        if (empty($rows)) {
            return [];
        }

        // Build enriched results
        $enrichedResults = [];
        foreach ($rows as $row) {
            $chunkId = (int) $row['chunk_id'];

            // Parse heading path JSON
            $headingPath = [];
            if (!empty($row['heading_path_json'])) {
                $decoded = json_decode($row['heading_path_json'], true);
                if (is_array($decoded)) {
                    $headingPath = $decoded;
                }
            }

            $enrichedResults[] = [
                'chunk_id' => $chunkId,
                'doc_id' => (int) $row['doc_id'],
                'post_id' => (int) $row['post_id'],
                'title' => $row['title'],
                'url' => $row['url'],
                'anchor' => $row['anchor'],
                'heading_path' => $headingPath,
                'chunk_text' => $row['chunk_text'],
                'score' => $scoreMap[$chunkId] ?? 0.0,
            ];
        }

        // Sort by score descending (preserve original ranking)
        usort($enrichedResults, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $enrichedResults;
    }

    /**
     * Get search statistics.
     *
     * @return array{
     *   total_documents: int,
     *   total_chunks: int,
     *   total_vectors: int,
     *   indexed_post_types: array<string, int>
     * }
     */
    public function getStats(): array
    {
        $totalDocs = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->docsTable}"
        );

        $totalChunks = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->chunksTable}"
        );

        $totalVectors = $this->vectorStore->count();

        $postTypeCounts = $this->wpdb->get_results(
            "SELECT post_type, COUNT(*) as count
             FROM {$this->docsTable}
             GROUP BY post_type",
            ARRAY_A
        );

        $indexedPostTypes = [];
        foreach ($postTypeCounts as $row) {
            $indexedPostTypes[$row['post_type']] = (int) $row['count'];
        }

        return [
            'total_documents' => $totalDocs,
            'total_chunks' => $totalChunks,
            'total_vectors' => $totalVectors,
            'indexed_post_types' => $indexedPostTypes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs\KB;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Services\Exceptions\RateLimitException;

/**
 * KB Phase 3: Generate embeddings for chunks.
 *
 * Processes chunks without vectors, calls the embedding API in batches,
 * handles rate limits with exponential backoff, and stores vectors
 * in wp_ai_kb_vectors.
 *
 * @package Vibe\AIIndex\Jobs\KB
 * @since 1.0.0
 */
class EmbedChunksJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_kb_embed_chunks';

    /**
     * Default batch size for embedding requests.
     */
    private const DEFAULT_BATCH_SIZE = 20;

    /**
     * Maximum batch size for embedding requests.
     */
    private const MAX_BATCH_SIZE = 100;

    /**
     * Default embedding model.
     */
    private const DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-small';

    /**
     * Default embedding dimensions.
     */
    private const DEFAULT_EMBEDDING_DIMS = 1536;

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_kb_embed_chunks_state';

    /**
     * Maximum retries for rate limit errors.
     */
    private const MAX_RETRIES = 5;

    /**
     * Base delay in seconds for exponential backoff.
     */
    private const BASE_BACKOFF_DELAY = 5;

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 1);
    }

    /**
     * Schedule the embed chunks job.
     *
     * @param int $lastChunkId Last processed chunk ID.
     * @return void
     */
    public static function schedule(int $lastChunkId = 0): void {
        as_schedule_single_action(
            time(),
            self::HOOK,
            ['last_chunk_id' => $lastChunkId],
            'vibe-ai-kb'
        );
    }

    /**
     * Execute the embed chunks phase.
     *
     * @param int $lastChunkId Last processed chunk ID.
     * @return void
     */
    public static function execute(int $lastChunkId = 0): void {
        $job = new self();

        try {
            $job->run($lastChunkId);
        } catch (RateLimitException $e) {
            $job->handleRateLimit($e);
        } catch (\Throwable $e) {
            $job->handleError($e);
        }
    }

    /**
     * Run the embed chunks job.
     *
     * @param int $lastChunkId Last processed chunk ID.
     * @return void
     */
    public function run(int $lastChunkId): void {
        global $wpdb;

        $chunksTable = $wpdb->prefix . 'ai_kb_chunks';
        $vectorsTable = $wpdb->prefix . 'ai_kb_vectors';

        $this->log('info', 'Embed chunks phase started', [
            'last_chunk_id' => $lastChunkId,
        ]);

        // Get batch size from config or use default
        $batchSize = $this->getBatchSize();

        // Get chunks without vectors
        $chunks = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.doc_id, c.chunk_text
             FROM {$chunksTable} c
             LEFT JOIN {$vectorsTable} v ON c.id = v.chunk_id
             WHERE v.id IS NULL
             AND c.id > %d
             ORDER BY c.id ASC
             LIMIT %d",
            $lastChunkId,
            $batchSize
        ));

        if (empty($chunks)) {
            $this->log('info', 'Embed chunks phase complete - no more chunks without vectors');
            $this->clearBatchState();
            do_action('vibe_ai_kb_embed_chunks_complete');
            $this->advanceToNextPhase();
            return;
        }

        // Extract chunk texts for batch embedding
        $chunkTexts = [];
        $chunkIds = [];
        foreach ($chunks as $chunk) {
            $chunkTexts[] = $chunk->chunk_text;
            $chunkIds[] = (int) $chunk->id;
        }

        $maxChunkId = max($chunkIds);

        // Call embedding API
        $embeddings = $this->generateEmbeddings($chunkTexts);

        if (empty($embeddings)) {
            $this->log('error', 'Failed to generate embeddings for batch');
            $this->scheduleNextBatch($lastChunkId, 30); // Retry with delay
            return;
        }

        // Verify we got the expected number of embeddings
        if (count($embeddings) !== count($chunks)) {
            $this->log('error', 'Embedding count mismatch', [
                'expected' => count($chunks),
                'received' => count($embeddings),
            ]);
            throw new \RuntimeException('Embedding count mismatch');
        }

        // Get model metadata
        $model = $this->getEmbeddingModel();
        $dims = $this->getEmbeddingDimensions();

        // Store vectors
        $storedCount = 0;
        foreach ($chunks as $index => $chunk) {
            $embedding = $embeddings[$index];

            // Serialize embedding vector for storage
            $vectorBlob = pack('f*', ...$embedding);

            $wpdb->insert(
                $vectorsTable,
                [
                    'chunk_id'   => $chunk->id,
                    'vector'     => $vectorBlob,
                    'model'      => $model,
                    'dimensions' => $dims,
                    'created_at' => current_time('mysql', true),
                ],
                ['%d', '%s', '%s', '%d', '%s']
            );

            $storedCount++;
        }

        // Update batch state
        $state = $this->getBatchState();
        $this->updateBatchState([
            'last_chunk_id'  => $maxChunkId,
            'embedded_count' => ($state['embedded_count'] ?? 0) + $storedCount,
            'retry_count'    => 0, // Reset retry count on success
        ]);

        $this->log('info', "Embedded {$storedCount} chunks", [
            'last_chunk_id' => $maxChunkId,
            'model'         => $model,
            'dimensions'    => $dims,
        ]);

        // Fire logging action
        do_action('vibe_ai_job_log', 'kb_embed', 'info', "Embedded {$storedCount} chunks");

        // Fire batch action
        do_action('vibe_ai_kb_embed_chunks_batch', $storedCount, $model);

        // Schedule next batch
        $this->scheduleNextBatch($maxChunkId);
    }

    /**
     * Generate embeddings for a batch of texts.
     *
     * @param array $texts Array of text strings to embed.
     * @return array Array of embedding vectors.
     * @throws RateLimitException When rate limit is exceeded.
     */
    private function generateEmbeddings(array $texts): array {
        // Check if EmbeddingClient service exists
        if (class_exists('\\Vibe\\AIIndex\\Services\\KB\\EmbeddingClient')) {
            $client = new \Vibe\AIIndex\Services\KB\EmbeddingClient();
            return $client->embed($texts);
        }

        // Fall back to direct API call
        return $this->callEmbeddingAPI($texts);
    }

    /**
     * Call embedding API directly.
     *
     * @param array $texts Texts to embed.
     * @return array Embedding vectors.
     * @throws RateLimitException When rate limit is exceeded.
     */
    private function callEmbeddingAPI(array $texts): array {
        $apiKey = get_option('vibe_ai_openai_api_key', '');

        if (empty($apiKey)) {
            $this->log('error', 'OpenAI API key not configured');
            return [];
        }

        $model = $this->getEmbeddingModel();

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'input' => $texts,
            ]),
        ]);

        if (is_wp_error($response)) {
            $this->log('error', 'Embedding API error: ' . $response->get_error_message());
            return [];
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        // Handle rate limiting
        if ($statusCode === 429) {
            $headers = wp_remote_retrieve_headers($response);
            $retryAfter = isset($headers['retry-after']) ? (int) $headers['retry-after'] : 60;

            throw new RateLimitException(
                'Embedding API rate limit exceeded',
                $retryAfter,
                'requests'
            );
        }

        if ($statusCode !== 200) {
            $this->log('error', "Embedding API returned status {$statusCode}", [
                'body' => wp_remote_retrieve_body($response),
            ]);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['data']) || !is_array($body['data'])) {
            $this->log('error', 'Invalid embedding API response');
            return [];
        }

        // Extract embeddings in order
        $embeddings = [];
        foreach ($body['data'] as $item) {
            $embeddings[$item['index']] = $item['embedding'];
        }

        // Sort by index to ensure correct order
        ksort($embeddings);

        return array_values($embeddings);
    }

    /**
     * Get the embedding model to use.
     *
     * @return string Model name.
     */
    private function getEmbeddingModel(): string {
        return get_option('vibe_ai_kb_embedding_model', self::DEFAULT_EMBEDDING_MODEL);
    }

    /**
     * Get the embedding dimensions.
     *
     * @return int Dimensions.
     */
    private function getEmbeddingDimensions(): int {
        return (int) get_option('vibe_ai_kb_embedding_dims', self::DEFAULT_EMBEDDING_DIMS);
    }

    /**
     * Get the batch size for embedding requests.
     *
     * @return int Batch size.
     */
    private function getBatchSize(): int {
        $batchSize = (int) get_option('vibe_ai_kb_embed_batch_size', self::DEFAULT_BATCH_SIZE);
        return min($batchSize, self::MAX_BATCH_SIZE);
    }

    /**
     * Handle rate limit exception.
     *
     * @param RateLimitException $e Exception.
     * @return void
     */
    private function handleRateLimit(RateLimitException $e): void {
        $state = $this->getBatchState();
        $retryCount = ($state['retry_count'] ?? 0) + 1;

        if ($retryCount > self::MAX_RETRIES) {
            $this->log('error', 'Max retries exceeded for rate limit');
            do_action('vibe_ai_kb_job_failed', 'embed_chunks', 'Max retries exceeded');
            return;
        }

        // Calculate exponential backoff delay
        $delay = $e->getRetryAfter();
        if ($delay < self::BASE_BACKOFF_DELAY) {
            $delay = self::BASE_BACKOFF_DELAY * pow(2, $retryCount - 1);
        }

        $this->updateBatchState(['retry_count' => $retryCount]);

        $this->log('warning', "Rate limit hit, backing off", [
            'retry_count'  => $retryCount,
            'delay'        => $delay,
            'limit_type'   => $e->getLimitType(),
        ]);

        // Schedule retry with delay
        $lastChunkId = $state['last_chunk_id'] ?? 0;
        $this->scheduleNextBatch($lastChunkId, $delay);
    }

    /**
     * Get current batch state.
     *
     * @return array Batch state.
     */
    private function getBatchState(): array {
        return get_option(self::OPTION_BATCH_STATE, [
            'last_chunk_id'  => 0,
            'embedded_count' => 0,
            'retry_count'    => 0,
        ]);
    }

    /**
     * Update batch state.
     *
     * @param array $state New state values.
     * @return void
     */
    private function updateBatchState(array $state): void {
        $current = $this->getBatchState();
        $updated = wp_parse_args($state, $current);
        update_option(self::OPTION_BATCH_STATE, $updated, false);
    }

    /**
     * Clear batch state.
     *
     * @return void
     */
    private function clearBatchState(): void {
        delete_option(self::OPTION_BATCH_STATE);
    }

    /**
     * Schedule the next batch.
     *
     * @param int $lastChunkId Last processed chunk ID.
     * @param int $delay       Optional delay in seconds.
     * @return void
     */
    private function scheduleNextBatch(int $lastChunkId, int $delay = 1): void {
        as_schedule_single_action(
            time() + $delay,
            self::HOOK,
            ['last_chunk_id' => $lastChunkId],
            'vibe-ai-kb'
        );

        $this->log('debug', "Next embed chunks batch scheduled", [
            'delay' => $delay,
        ]);
    }

    /**
     * Advance to the next phase (IndexUpsertJob).
     *
     * @return void
     */
    private function advanceToNextPhase(): void {
        IndexUpsertJob::schedule(0);
        $this->log('info', 'Advancing to index upsert phase');
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handleError(\Throwable $e): void {
        $this->log('error', 'Embed chunks phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        do_action('vibe_ai_kb_job_failed', 'embed_chunks', $e->getMessage());
    }

    /**
     * Log a message.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context.
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void {
        if (function_exists('vibe_ai_log')) {
            vibe_ai_log($level, '[KB/EmbedChunks] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'kb_embed_chunks', $level, $message, $context);
    }
}

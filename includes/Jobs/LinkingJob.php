<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs;

use Vibe\AIIndex\Pipeline\PipelineManager;
use Vibe\AIIndex\Repositories\EntityRepository;

/**
 * LinkingJob: Phase 4 of the AI Entity extraction pipeline.
 *
 * Creates mention records connecting entities to posts using EntityRepository::link_mention().
 * Establishes the edges between entities and their source posts.
 *
 * @package Vibe\AIIndex\Jobs
 */
class LinkingJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_phase_linking';

    /**
     * Batch size for processing mentions.
     */
    private const BATCH_SIZE = 100;

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_linking_batch_state';

    /**
     * Entity repository instance.
     *
     * @var EntityRepository
     */
    private EntityRepository $repository;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->repository = $this->get_repository();
    }

    /**
     * Register the job with Action Scheduler.
     *
     * @return void
     */
    public static function register(): void {
        add_action(self::HOOK, [self::class, 'execute'], 10, 1);
    }

    /**
     * Execute the linking phase.
     *
     * @param array $args Job arguments containing config.
     * @return void
     */
    public static function execute(array $args = []): void {
        $config = $args['config'] ?? [];
        $job = new self();

        try {
            $job->run($config);
        } catch (\Throwable $e) {
            $job->handle_error($e);
        }
    }

    /**
     * Run the linking job.
     *
     * @param array $config Pipeline configuration.
     * @return void
     */
    public function run(array $config): void {
        $pipeline = PipelineManager::get_instance();

        // Get canonical entities from deduplication phase
        $canonical_entities = DeduplicationJob::get_canonical_entities();

        if (empty($canonical_entities)) {
            $this->log('info', 'No entities to link');
            do_action('vibe_ai_phase_linking_complete');
            return;
        }

        // Get extracted entities for context/confidence data
        $raw_entities = $this->get_raw_entity_data();

        $this->log('info', 'Linking phase started', [
            'total_entities' => count($canonical_entities),
        ]);

        // Build linking queue: entity_id -> post_id -> context data
        $linking_queue = $this->build_linking_queue($canonical_entities, $raw_entities);
        $total_links = count($linking_queue);

        // Get batch state
        $batch_state = $this->get_batch_state();
        $processed_count = $batch_state['processed'] ?? 0;

        // Check if already complete
        if ($processed_count >= $total_links) {
            $this->complete_phase();
            return;
        }

        // Update progress
        $pipeline->update_progress([
            'phase' => [
                'total'     => $total_links,
                'completed' => $processed_count,
            ],
        ]);

        // Process a batch
        $batch = array_slice($linking_queue, $processed_count, self::BATCH_SIZE);
        $linked_count = 0;
        $failed_count = 0;

        foreach ($batch as $link_data) {
            try {
                $this->create_mention($link_data);
                $linked_count++;
            } catch (\Throwable $e) {
                $failed_count++;
                $this->log('error', 'Failed to create mention', [
                    'entity_id' => $link_data['entity_id'],
                    'post_id'   => $link_data['post_id'],
                    'error'     => $e->getMessage(),
                ]);
            }

            // Update progress periodically
            if (($linked_count + $failed_count) % 20 === 0) {
                $pipeline->update_progress([
                    'phase' => [
                        'total'     => $total_links,
                        'completed' => $processed_count + $linked_count + $failed_count,
                        'failed'    => $failed_count,
                    ],
                ]);
            }
        }

        // Update batch state
        $new_processed = $processed_count + $linked_count + $failed_count;
        $this->update_batch_state(['processed' => $new_processed]);

        $this->log('info', "Linked {$linked_count} mentions, {$failed_count} failed");

        // Check if more to process
        if ($new_processed < $total_links) {
            $this->schedule_next_batch($config);
        } else {
            $this->complete_phase();
        }
    }

    /**
     * Build the linking queue from canonical entities.
     *
     * @param array $canonical_entities Canonical entity mappings.
     * @param array $raw_entities Raw entity data with context/confidence.
     * @return array Linking queue.
     */
    private function build_linking_queue(array $canonical_entities, array $raw_entities): array {
        $queue = [];

        foreach ($canonical_entities as $entity_id => $entity_data) {
            $post_ids = $entity_data['source_post_ids'] ?? [];
            $entity_name = $entity_data['name'] ?? '';

            foreach ($post_ids as $post_id) {
                // Find the best context data for this entity-post combination
                $context_data = $this->find_context_data($entity_name, $post_id, $raw_entities);

                $queue[] = [
                    'entity_id'  => (int) $entity_id,
                    'post_id'    => (int) $post_id,
                    'confidence' => $context_data['confidence'] ?? 0.5,
                    'context'    => $context_data['context'] ?? '',
                    'is_primary' => $this->is_primary_entity($entity_id, $post_id, $canonical_entities),
                ];
            }
        }

        return $queue;
    }

    /**
     * Find context data for an entity-post combination.
     *
     * @param string $entity_name Entity name.
     * @param int    $post_id Post ID.
     * @param array  $raw_entities Raw entity data.
     * @return array Context data with 'confidence' and 'context'.
     */
    private function find_context_data(string $entity_name, int $post_id, array $raw_entities): array {
        $best_confidence = 0.5;
        $best_context = '';

        $entity_name_lower = strtolower(trim($entity_name));

        foreach ($raw_entities as $raw) {
            $raw_name_lower = strtolower(trim($raw['name'] ?? ''));
            $raw_post_id = $raw['source_post_id'] ?? 0;

            // Match by post ID and similar name
            if ($raw_post_id === $post_id) {
                // Check if names are similar
                if ($raw_name_lower === $entity_name_lower ||
                    strpos($raw_name_lower, $entity_name_lower) !== false ||
                    strpos($entity_name_lower, $raw_name_lower) !== false) {

                    $confidence = $raw['confidence'] ?? 0.5;
                    if ($confidence > $best_confidence) {
                        $best_confidence = $confidence;
                        $best_context = $raw['context'] ?? '';
                    }
                }
            }
        }

        return [
            'confidence' => $best_confidence,
            'context'    => $best_context,
        ];
    }

    /**
     * Determine if an entity is the primary entity for a post.
     *
     * The primary entity is typically the most relevant/prominent entity.
     *
     * @param int   $entity_id Entity ID.
     * @param int   $post_id Post ID.
     * @param array $canonical_entities All canonical entities.
     * @return bool True if primary.
     */
    private function is_primary_entity(int $entity_id, int $post_id, array $canonical_entities): bool {
        // For now, mark as primary if this entity has the highest mention count for this post
        // This is a simple heuristic; could be improved with more sophisticated analysis
        $max_mentions = 0;
        $primary_id = 0;

        foreach ($canonical_entities as $id => $entity_data) {
            $post_ids = $entity_data['source_post_ids'] ?? [];
            if (in_array($post_id, $post_ids, true)) {
                $mention_count = count($post_ids);
                if ($mention_count > $max_mentions) {
                    $max_mentions = $mention_count;
                    $primary_id = (int) $id;
                }
            }
        }

        return $entity_id === $primary_id;
    }

    /**
     * Create a mention record.
     *
     * @param array $link_data Linking data.
     * @return void
     */
    private function create_mention(array $link_data): void {
        $this->repository->link_mention(
            $link_data['entity_id'],
            $link_data['post_id'],
            $link_data['confidence'],
            $link_data['context'],
            $link_data['is_primary']
        );

        $this->log('debug', "Linked entity {$link_data['entity_id']} to post {$link_data['post_id']}", [
            'confidence' => $link_data['confidence'],
            'is_primary' => $link_data['is_primary'],
        ]);
    }

    /**
     * Get raw entity data from previous phases.
     *
     * @return array Raw entity data.
     */
    private function get_raw_entity_data(): array {
        // Try to get from the extraction job storage
        // Note: This may have been cleared after deduplication
        return get_option('vibe_ai_extracted_entities_backup', []);
    }

    /**
     * Get current batch state.
     *
     * @return array Batch state.
     */
    private function get_batch_state(): array {
        return get_option(self::OPTION_BATCH_STATE, ['processed' => 0]);
    }

    /**
     * Update batch state.
     *
     * @param array $state New state.
     * @return void
     */
    private function update_batch_state(array $state): void {
        $current = $this->get_batch_state();
        $updated = wp_parse_args($state, $current);
        update_option(self::OPTION_BATCH_STATE, $updated, false);
    }

    /**
     * Clear batch state.
     *
     * @return void
     */
    private function clear_batch_state(): void {
        delete_option(self::OPTION_BATCH_STATE);
    }

    /**
     * Schedule the next batch.
     *
     * @param array $config Pipeline configuration.
     * @return void
     */
    private function schedule_next_batch(array $config): void {
        as_schedule_single_action(
            time(),
            self::HOOK,
            ['config' => $config],
            'vibe-ai-index'
        );

        $this->log('debug', 'Next linking batch scheduled');
    }

    /**
     * Complete the linking phase.
     *
     * @return void
     */
    private function complete_phase(): void {
        $this->log('info', 'Linking phase complete');

        $this->clear_batch_state();

        do_action('vibe_ai_phase_linking_complete');
    }

    /**
     * Get the entity repository.
     *
     * @return EntityRepository
     */
    private function get_repository(): EntityRepository {
        if (function_exists('vibe_ai_get_service')) {
            return vibe_ai_get_service(EntityRepository::class);
        }

        return new EntityRepository();
    }

    /**
     * Handle job execution error.
     *
     * @param \Throwable $e Exception.
     * @return void
     */
    private function handle_error(\Throwable $e): void {
        $this->log('error', 'Linking phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        PipelineManager::get_instance()->fail(
            'Linking phase failed: ' . $e->getMessage(),
            ['exception' => get_class($e)]
        );
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
            vibe_ai_log($level, '[Linking] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'linking', $level, $message, $context);
    }
}

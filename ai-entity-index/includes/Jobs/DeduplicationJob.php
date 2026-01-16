<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs;

use Vibe\AIIndex\Pipeline\PipelineManager;
use Vibe\AIIndex\Repositories\EntityRepository;

/**
 * DeduplicationJob: Phase 3 of the AI Entity extraction pipeline.
 *
 * Resolves aliases to canonical entities and merges duplicate entities.
 * Creates or updates entities in the database with proper normalization.
 *
 * @package Vibe\AIIndex\Jobs
 */
class DeduplicationJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_phase_deduplication';

    /**
     * Batch size for processing entities.
     */
    private const BATCH_SIZE = 100;

    /**
     * Option key for storing canonical entities.
     */
    private const OPTION_CANONICAL_ENTITIES = 'vibe_ai_canonical_entities';

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_dedup_batch_state';

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
     * Execute the deduplication phase.
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
     * Run the deduplication job.
     *
     * @param array $config Pipeline configuration.
     * @return void
     */
    public function run(array $config): void {
        $pipeline = PipelineManager::get_instance();

        // Get extracted entities from previous phase
        $extracted_entities = ExtractionJob::get_extracted_entities();

        if (empty($extracted_entities)) {
            $this->log('info', 'No entities to deduplicate');
            do_action('vibe_ai_phase_deduplication_complete');
            return;
        }

        $this->log('info', 'Deduplication phase started', [
            'total_raw_entities' => count($extracted_entities),
        ]);

        // Group entities by normalized name
        $grouped = $this->group_entities_by_name($extracted_entities);

        $this->log('info', 'Entities grouped', [
            'unique_groups' => count($grouped),
        ]);

        // Get batch state
        $batch_state = $this->get_batch_state();
        $processed_groups = $batch_state['processed'] ?? [];
        $group_keys = array_keys($grouped);

        // Filter to unprocessed groups
        $unprocessed_keys = array_diff($group_keys, $processed_groups);

        if (empty($unprocessed_keys)) {
            $this->complete_phase();
            return;
        }

        // Update progress
        $pipeline->update_progress([
            'phase' => [
                'total'     => count($group_keys),
                'completed' => count($processed_groups),
            ],
        ]);

        // Process a batch of groups
        $batch_keys = array_slice($unprocessed_keys, 0, self::BATCH_SIZE);
        $canonical_entities = [];
        $processed_count = 0;

        foreach ($batch_keys as $key) {
            $group = $grouped[$key];
            $canonical = $this->process_entity_group($key, $group);

            if ($canonical !== null) {
                $canonical_entities[$canonical['id']] = $canonical;
            }

            $processed_groups[] = $key;
            $processed_count++;

            // Update progress periodically
            if ($processed_count % 10 === 0) {
                $pipeline->update_progress([
                    'phase' => [
                        'total'     => count($group_keys),
                        'completed' => count($processed_groups),
                    ],
                ]);
            }
        }

        // Store canonical entities mapping
        $this->store_canonical_entities($canonical_entities);

        // Update batch state
        $this->update_batch_state(['processed' => $processed_groups]);

        // Check if there are more groups to process
        $remaining = count($group_keys) - count($processed_groups);

        if ($remaining > 0) {
            $this->log('info', "Processed {$processed_count} groups, {$remaining} remaining");
            $this->schedule_next_batch($config);
        } else {
            $this->complete_phase();
        }
    }

    /**
     * Group extracted entities by normalized name.
     *
     * @param array $entities Raw extracted entities.
     * @return array Grouped entities by normalized key.
     */
    private function group_entities_by_name(array $entities): array {
        $groups = [];

        foreach ($entities as $entity) {
            $name = $entity['name'] ?? '';
            if (empty($name)) {
                continue;
            }

            // Normalize the name for grouping
            $key = $this->normalize_for_grouping($name);

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = $entity;
        }

        return $groups;
    }

    /**
     * Normalize a name for grouping purposes.
     *
     * @param string $name Entity name.
     * @return string Normalized key.
     */
    private function normalize_for_grouping(string $name): string {
        $normalized = sanitize_title($name);
        $normalized = preg_replace('/[^a-z0-9]/', '', $normalized);
        return $normalized;
    }

    /**
     * Process a group of related entities.
     *
     * @param string $key Group key.
     * @param array  $group Array of entities in this group.
     * @return array|null Canonical entity data or null.
     */
    private function process_entity_group(string $key, array $group): ?array {
        if (empty($group)) {
            return null;
        }

        // Determine the best canonical name (longest, most common, or highest confidence)
        $canonical_data = $this->select_canonical($group);

        if ($canonical_data === null) {
            return null;
        }

        // Check if this entity already exists (by alias or slug)
        $existing_id = $this->repository->resolve_alias(sanitize_title($canonical_data['name']));

        if ($existing_id === null) {
            // Check by slug directly
            $slug = sanitize_title($canonical_data['name']);
            $existing_id = $this->find_entity_by_slug($slug);
        }

        if ($existing_id !== null) {
            // Entity exists - collect aliases and update
            $aliases = $this->collect_aliases($group, $canonical_data['name']);

            foreach ($aliases as $alias) {
                $this->repository->register_alias($existing_id, $alias);
            }

            $this->log('debug', "Entity '{$canonical_data['name']}' merged with existing ID {$existing_id}");

            return [
                'id'              => $existing_id,
                'name'            => $canonical_data['name'],
                'type'            => $canonical_data['type'],
                'source_post_ids' => $this->collect_source_posts($group),
                'aliases'         => $aliases,
                'is_new'          => false,
            ];
        }

        // Create new entity
        $aliases = $this->collect_aliases($group, $canonical_data['name']);
        $entity_id = $this->repository->upsert_entity(
            $canonical_data['name'],
            $canonical_data['type'],
            $aliases
        );

        $this->log('debug', "Created new entity '{$canonical_data['name']}' with ID {$entity_id}");

        return [
            'id'              => $entity_id,
            'name'            => $canonical_data['name'],
            'type'            => $canonical_data['type'],
            'source_post_ids' => $this->collect_source_posts($group),
            'aliases'         => $aliases,
            'is_new'          => true,
        ];
    }

    /**
     * Select the canonical entity from a group.
     *
     * @param array $group Entity group.
     * @return array|null Canonical entity data.
     */
    private function select_canonical(array $group): ?array {
        if (empty($group)) {
            return null;
        }

        // Score each entity
        $scored = [];
        foreach ($group as $entity) {
            $name = $entity['name'] ?? '';
            $confidence = $entity['confidence'] ?? 0.5;
            $type = $entity['type'] ?? 'CONCEPT';

            // Calculate score based on:
            // - Name length (prefer complete names)
            // - Confidence score
            // - Frequency in group
            $score = mb_strlen($name) * 0.1 + $confidence * 10;

            $key = strtolower(trim($name));
            if (!isset($scored[$key])) {
                $scored[$key] = [
                    'name'       => $name,
                    'type'       => $type,
                    'score'      => $score,
                    'confidence' => $confidence,
                    'frequency'  => 1,
                ];
            } else {
                $scored[$key]['frequency']++;
                $scored[$key]['score'] += 5; // Bonus for frequency
                // Keep highest confidence
                if ($confidence > $scored[$key]['confidence']) {
                    $scored[$key]['confidence'] = $confidence;
                    $scored[$key]['name'] = $name; // Prefer higher confidence name variant
                }
            }
        }

        // Sort by score descending
        uasort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Return the best candidate
        $best = reset($scored);
        return $best ?: null;
    }

    /**
     * Collect aliases from a group, excluding the canonical name.
     *
     * @param array  $group Entity group.
     * @param string $canonical_name Canonical name to exclude.
     * @return array Unique aliases.
     */
    private function collect_aliases(array $group, string $canonical_name): array {
        $aliases = [];
        $canonical_lower = strtolower(trim($canonical_name));

        foreach ($group as $entity) {
            $name = $entity['name'] ?? '';
            $name_lower = strtolower(trim($name));

            // Skip if same as canonical
            if ($name_lower === $canonical_lower) {
                continue;
            }

            // Skip if empty
            if (empty($name)) {
                continue;
            }

            $aliases[] = $name;

            // Also collect any explicit aliases from the entity
            $entity_aliases = $entity['aliases'] ?? [];
            foreach ($entity_aliases as $alias) {
                $alias_lower = strtolower(trim($alias));
                if ($alias_lower !== $canonical_lower && !empty($alias)) {
                    $aliases[] = $alias;
                }
            }
        }

        return array_unique($aliases);
    }

    /**
     * Collect source post IDs from a group.
     *
     * @param array $group Entity group.
     * @return array Post IDs.
     */
    private function collect_source_posts(array $group): array {
        $post_ids = [];

        foreach ($group as $entity) {
            if (isset($entity['source_post_id'])) {
                $post_ids[] = (int) $entity['source_post_id'];
            }
        }

        return array_unique($post_ids);
    }

    /**
     * Find an entity by slug.
     *
     * @param string $slug Entity slug.
     * @return int|null Entity ID or null.
     */
    private function find_entity_by_slug(string $slug): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_entities';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE slug = %s",
            $slug
        ));

        return $result ? (int) $result : null;
    }

    /**
     * Store canonical entities mapping.
     *
     * @param array $entities Canonical entities.
     * @return void
     */
    private function store_canonical_entities(array $entities): void {
        $existing = get_option(self::OPTION_CANONICAL_ENTITIES, []);
        $updated = array_merge($existing, $entities);
        update_option(self::OPTION_CANONICAL_ENTITIES, $updated, false);
    }

    /**
     * Get canonical entities mapping.
     *
     * @return array Canonical entities.
     */
    public static function get_canonical_entities(): array {
        return get_option(self::OPTION_CANONICAL_ENTITIES, []);
    }

    /**
     * Clear canonical entities.
     *
     * @return void
     */
    public static function clear_canonical_entities(): void {
        delete_option(self::OPTION_CANONICAL_ENTITIES);
    }

    /**
     * Get current batch state.
     *
     * @return array Batch state.
     */
    private function get_batch_state(): array {
        return get_option(self::OPTION_BATCH_STATE, ['processed' => []]);
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

        $this->log('debug', 'Next deduplication batch scheduled');
    }

    /**
     * Complete the deduplication phase.
     *
     * @return void
     */
    private function complete_phase(): void {
        $canonical = self::get_canonical_entities();

        $this->log('info', 'Deduplication phase complete', [
            'total_canonical_entities' => count($canonical),
        ]);

        $this->clear_batch_state();

        // Clear extracted entities from previous phase
        ExtractionJob::clear_extracted_entities();

        do_action('vibe_ai_phase_deduplication_complete');
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
        $this->log('error', 'Deduplication phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        PipelineManager::get_instance()->fail(
            'Deduplication phase failed: ' . $e->getMessage(),
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
            vibe_ai_log($level, '[Deduplication] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'deduplication', $level, $message, $context);
    }
}

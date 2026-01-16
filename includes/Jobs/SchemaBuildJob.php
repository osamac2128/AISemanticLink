<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Jobs;

use Vibe\AIIndex\Pipeline\PipelineManager;
use Vibe\AIIndex\Repositories\EntityRepository;

/**
 * SchemaBuildJob: Phase 6 of the AI Entity extraction pipeline.
 *
 * Generates JSON-LD Schema.org blobs for each post and caches them
 * in post_meta (_vibe_ai_schema_cache) for zero-query frontend loading.
 *
 * @package Vibe\AIIndex\Jobs
 */
class SchemaBuildJob {

    /**
     * Action hook for this job.
     */
    public const HOOK = 'vibe_ai_phase_schema_build';

    /**
     * Batch size for processing posts.
     */
    private const BATCH_SIZE = 50;

    /**
     * Option key for batch state.
     */
    private const OPTION_BATCH_STATE = 'vibe_ai_schema_build_batch_state';

    /**
     * Meta key for schema cache.
     */
    private const META_SCHEMA_CACHE = '_vibe_ai_schema_cache';

    /**
     * Meta key for schema version.
     */
    private const META_SCHEMA_VERSION = '_vibe_ai_schema_version';

    /**
     * Current schema version.
     */
    private const SCHEMA_VERSION = 1;

    /**
     * Minimum confidence threshold for including entities in schema.
     */
    private const MIN_CONFIDENCE = 0.6;

    /**
     * Entity type to Schema.org type mapping.
     */
    private const TYPE_MAPPING = [
        'PERSON'   => 'Person',
        'ORG'      => 'Organization',
        'COMPANY'  => 'Corporation',
        'LOCATION' => 'Place',
        'COUNTRY'  => 'Country',
        'PRODUCT'  => 'Product',
        'SOFTWARE' => 'SoftwareApplication',
        'EVENT'    => 'Event',
        'WORK'     => 'CreativeWork',
        'CONCEPT'  => 'Thing',
    ];

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
     * Execute the schema build phase.
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
     * Run the schema build job.
     *
     * @param array $config Pipeline configuration.
     * @return void
     */
    public function run(array $config): void {
        global $wpdb;

        $pipeline = PipelineManager::get_instance();
        $mentions_table = $wpdb->prefix . 'ai_mentions';

        $this->log('info', 'Schema build phase started');

        // Get all unique post IDs with mentions
        $post_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$mentions_table} ORDER BY post_id ASC"
        );

        if (empty($post_ids)) {
            $this->log('info', 'No posts with entities to process');
            do_action('vibe_ai_phase_schema_build_complete');
            return;
        }

        $total_posts = count($post_ids);

        // Get batch state
        $batch_state = $this->get_batch_state();
        $processed_count = $batch_state['processed'] ?? 0;

        // Check if already complete
        if ($processed_count >= $total_posts) {
            $this->complete_phase();
            return;
        }

        // Update progress
        $pipeline->update_progress([
            'phase' => [
                'total'     => $total_posts,
                'completed' => $processed_count,
            ],
        ]);

        // Get current batch
        $batch = array_slice($post_ids, $processed_count, self::BATCH_SIZE);
        $built_count = 0;
        $failed_count = 0;

        foreach ($batch as $post_id) {
            try {
                $this->build_schema_for_post((int) $post_id);
                $built_count++;
            } catch (\Throwable $e) {
                $failed_count++;
                $this->log('error', "Failed to build schema for post {$post_id}: " . $e->getMessage());
            }

            // Update progress periodically
            if (($built_count + $failed_count) % 10 === 0) {
                $pipeline->update_progress([
                    'phase' => [
                        'total'     => $total_posts,
                        'completed' => $processed_count + $built_count + $failed_count,
                        'failed'    => $failed_count,
                    ],
                ]);
            }
        }

        // Update batch state
        $new_processed = $processed_count + $built_count + $failed_count;
        $this->update_batch_state(['processed' => $new_processed]);

        $this->log('info', "Built schema for {$built_count} posts, {$failed_count} failed");

        // Check if more to process
        if ($new_processed < $total_posts) {
            $this->schedule_next_batch($config);
        } else {
            $this->complete_phase();
        }
    }

    /**
     * Build JSON-LD schema for a single post.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function build_schema_for_post(int $post_id): void {
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return;
        }

        // Get minimum confidence threshold (allow filtering)
        $min_confidence = apply_filters('vibe_ai_confidence_threshold', self::MIN_CONFIDENCE);

        // Get entities for this post
        $entities = $this->repository->get_entities_for_post($post_id, $min_confidence);

        if (empty($entities)) {
            // Remove any existing schema cache
            delete_post_meta($post_id, self::META_SCHEMA_CACHE);
            delete_post_meta($post_id, self::META_SCHEMA_VERSION);
            return;
        }

        // Build the schema
        $schema = $this->generate_schema($post, $entities);

        // Allow filtering of the schema
        $schema = apply_filters('vibe_ai_schema_json', $schema, $post_id);

        // Encode with proper escaping
        $json = wp_json_encode($schema, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode schema as JSON');
        }

        // Store in post meta
        update_post_meta($post_id, self::META_SCHEMA_CACHE, $json);
        update_post_meta($post_id, self::META_SCHEMA_VERSION, self::SCHEMA_VERSION);

        $this->log('debug', "Built schema for post {$post_id}", [
            'entity_count' => count($entities),
        ]);
    }

    /**
     * Generate JSON-LD schema for a post and its entities.
     *
     * @param \WP_Post $post Post object.
     * @param array    $entities Entity data.
     * @return array Schema.org JSON-LD structure.
     */
    private function generate_schema(\WP_Post $post, array $entities): array {
        $site_url = get_site_url();
        $post_url = get_permalink($post);
        $post_id = "#{$post->post_name}";

        // Build the @graph array
        $graph = [];

        // Article node
        $article = [
            '@type'         => $this->get_article_type($post),
            '@id'           => $post_url . '#article',
            'headline'      => $post->post_title,
            'datePublished' => get_the_date('c', $post),
            'dateModified'  => get_the_modified_date('c', $post),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $post_url,
            ],
        ];

        // Add author if available
        $author = get_userdata($post->post_author);
        if ($author) {
            $article['author'] = [
                '@type' => 'Person',
                '@id'   => $site_url . '/#/author/' . $author->user_nicename,
                'name'  => $author->display_name,
            ];
        }

        // Build mentions array and entity nodes
        $mentions = [];
        $entity_nodes = [];

        foreach ($entities as $entity) {
            $entity_id = $site_url . '/#/entity/' . sanitize_title($entity->name);
            $schema_type = $this->map_type($entity->type, $entity->schema_type);

            // Add to mentions array
            $mentions[] = ['@id' => $entity_id];

            // Build entity node
            $entity_node = [
                '@type' => $schema_type,
                '@id'   => $entity_id,
                'name'  => $entity->name,
            ];

            // Add description if available
            if (!empty($entity->description)) {
                $entity_node['description'] = $entity->description;
            }

            // Add sameAs links
            $same_as = [];

            if (!empty($entity->same_as_url)) {
                $same_as[] = $entity->same_as_url;
            }

            if (!empty($entity->wikidata_id)) {
                $same_as[] = 'https://www.wikidata.org/wiki/' . $entity->wikidata_id;
            }

            if (!empty($same_as)) {
                $entity_node['sameAs'] = count($same_as) === 1 ? $same_as[0] : $same_as;
            }

            $entity_nodes[] = $entity_node;
        }

        // Add mentions to article
        if (!empty($mentions)) {
            $article['mentions'] = $mentions;
        }

        // Add primary entity as "about" if available
        foreach ($entities as $entity) {
            if (!empty($entity->is_primary)) {
                $article['about'] = [
                    '@id' => $site_url . '/#/entity/' . sanitize_title($entity->name),
                ];
                break;
            }
        }

        // Assemble graph
        $graph[] = $article;
        $graph = array_merge($graph, $entity_nodes);

        return [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];
    }

    /**
     * Get the Schema.org article type for a post.
     *
     * @param \WP_Post $post Post object.
     * @return string Schema.org type.
     */
    private function get_article_type(\WP_Post $post): string {
        $type_map = [
            'post' => 'Article',
            'page' => 'WebPage',
        ];

        return $type_map[$post->post_type] ?? 'Article';
    }

    /**
     * Map internal entity type to Schema.org type.
     *
     * @param string      $internal_type Internal type.
     * @param string|null $schema_type Override schema type.
     * @return string Schema.org type.
     */
    private function map_type(string $internal_type, ?string $schema_type): string {
        // Use override if provided
        if (!empty($schema_type)) {
            return $schema_type;
        }

        $type_upper = strtoupper($internal_type);
        return self::TYPE_MAPPING[$type_upper] ?? 'Thing';
    }

    /**
     * Regenerate schema for a specific post.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function regenerate(int $post_id): void {
        $this->build_schema_for_post($post_id);
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

        $this->log('debug', 'Next schema build batch scheduled');
    }

    /**
     * Complete the schema build phase.
     *
     * @return void
     */
    private function complete_phase(): void {
        $this->log('info', 'Schema build phase complete');

        $this->clear_batch_state();

        // Clear queued posts from preparation phase
        PreparationJob::clear_queued_posts();

        do_action('vibe_ai_phase_schema_build_complete');
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
        $this->log('error', 'Schema build phase failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        PipelineManager::get_instance()->fail(
            'Schema build phase failed: ' . $e->getMessage(),
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
            vibe_ai_log($level, '[SchemaBuild] ' . $message, $context);
        }

        do_action('vibe_ai_job_log', 'schema_build', $level, $message, $context);
    }
}

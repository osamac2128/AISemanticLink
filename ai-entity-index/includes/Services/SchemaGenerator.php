<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

use Vibe\AIIndex\Repositories\EntityRepository;

/**
 * SchemaGenerator: Generate Schema.org JSON-LD for posts.
 *
 * Creates structured data markup containing article information
 * and all mentioned entities for enhanced SEO and AI discoverability.
 *
 * @package Vibe\AIIndex\Services
 * @since 1.0.0
 */
class SchemaGenerator
{
    /**
     * Meta key for schema cache.
     */
    public const META_SCHEMA_CACHE = '_vibe_ai_schema_cache';

    /**
     * Meta key for extraction timestamp.
     */
    public const META_EXTRACTED_AT = '_vibe_ai_extracted_at';

    /**
     * Meta key for schema version.
     */
    public const META_SCHEMA_VERSION = '_vibe_ai_schema_version';

    /**
     * Current schema version.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Minimum confidence threshold for including entities in schema.
     */
    private const MIN_CONFIDENCE = 0.6;

    /**
     * Entity type to Schema.org type mapping.
     *
     * @var array<string, string>
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
     *
     * @param EntityRepository|null $repository Optional repository instance.
     */
    public function __construct(?EntityRepository $repository = null)
    {
        $this->repository = $repository ?? $this->create_repository();
    }

    /**
     * Generate complete JSON-LD schema for a post.
     *
     * Creates @graph array with Article/post and all mentioned entities.
     *
     * @param int $post_id The post ID to generate schema for.
     *
     * @return string JSON-encoded schema string.
     *
     * @throws \InvalidArgumentException If post does not exist.
     * @throws \RuntimeException If schema encoding fails.
     */
    public function generate(int $post_id): string
    {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            throw new \InvalidArgumentException(
                sprintf('Post with ID %d does not exist', $post_id)
            );
        }

        // Skip non-published posts
        if ($post->post_status !== 'publish') {
            return '{}';
        }

        // Get minimum confidence threshold (allow filtering)
        $min_confidence = (float) apply_filters('vibe_ai_confidence_threshold', self::MIN_CONFIDENCE);

        // Get entities for this post
        $entities = $this->repository->get_entities_for_post($post_id, $min_confidence);

        // Build the schema structure
        $schema = $this->build_schema_structure($post, $entities);

        // Allow filtering of the schema
        $schema = apply_filters('vibe_ai_schema_json', $schema, $post_id);

        // Encode with proper escaping for safe HTML embedding
        $json = wp_json_encode(
            $schema,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new \RuntimeException('Failed to encode schema as JSON');
        }

        return $json;
    }

    /**
     * Regenerate and cache schema for a post.
     *
     * @param int $post_id The post ID to regenerate schema for.
     *
     * @return void
     *
     * @throws \InvalidArgumentException If post does not exist.
     * @throws \RuntimeException If schema encoding fails.
     */
    public function regenerate(int $post_id): void
    {
        $json = $this->generate($post_id);

        // Save to post meta
        update_post_meta($post_id, self::META_SCHEMA_CACHE, $json);
        update_post_meta($post_id, self::META_EXTRACTED_AT, current_time('mysql', true));
        update_post_meta($post_id, self::META_SCHEMA_VERSION, self::SCHEMA_VERSION);

        $this->log('debug', "Regenerated schema for post {$post_id}");
    }

    /**
     * Get cached schema for a post.
     *
     * @param int $post_id The post ID.
     *
     * @return string|null Cached JSON schema or null if not cached.
     */
    public function get_cached(int $post_id): ?string
    {
        $cached = get_post_meta($post_id, self::META_SCHEMA_CACHE, true);

        if (empty($cached) || !is_string($cached)) {
            return null;
        }

        return $cached;
    }

    /**
     * Invalidate cached schema for a post.
     *
     * @param int $post_id The post ID.
     *
     * @return void
     */
    public function invalidate(int $post_id): void
    {
        delete_post_meta($post_id, self::META_SCHEMA_CACHE);
        delete_post_meta($post_id, self::META_SCHEMA_VERSION);

        $this->log('debug', "Invalidated schema cache for post {$post_id}");
    }

    /**
     * Build schema for a single entity.
     *
     * @param object $entity Entity object with properties: name, slug, type, schema_type,
     *                       same_as_url, wikidata_id, description.
     *
     * @return array<string, mixed> Schema.org entity structure.
     */
    public function build_entity_schema(object $entity): array
    {
        $site_url = get_site_url();
        $slug = !empty($entity->slug) ? $entity->slug : sanitize_title($entity->name);
        $entity_id = $site_url . '/#/entity/' . $slug;

        // Map the type
        $schema_type = $this->map_entity_type(
            $entity->type ?? 'CONCEPT',
            $entity->schema_type ?? null
        );

        $entity_schema = [
            '@type' => $schema_type,
            '@id'   => $entity_id,
            'name'  => $entity->name,
        ];

        // Add description if available
        if (!empty($entity->description)) {
            $entity_schema['description'] = $entity->description;
        }

        // Build sameAs array
        $same_as = [];

        if (!empty($entity->same_as_url)) {
            $same_as[] = $entity->same_as_url;
        }

        if (!empty($entity->wikidata_id)) {
            // Add Wikidata URL
            $same_as[] = 'https://www.wikidata.org/wiki/' . $entity->wikidata_id;

            // Also add Wikipedia URL derived from Wikidata ID (common pattern)
            // Note: This is a simplified approach; actual Wikipedia URL would need API lookup
        }

        if (!empty($same_as)) {
            $entity_schema['sameAs'] = count($same_as) === 1 ? $same_as[0] : $same_as;
        }

        return $entity_schema;
    }

    /**
     * Build article schema with mentions.
     *
     * @param \WP_Post $post     The post object.
     * @param array    $entities Array of entity objects.
     *
     * @return array<string, mixed> Schema.org article structure.
     */
    public function build_article_schema(\WP_Post $post, array $entities): array
    {
        $site_url = get_site_url();
        $post_url = get_permalink($post);

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

        // Build mentions array
        $mentions = [];
        foreach ($entities as $entity) {
            $slug = !empty($entity->slug) ? $entity->slug : sanitize_title($entity->name);
            $mentions[] = ['@id' => $site_url . '/#/entity/' . $slug];
        }

        if (!empty($mentions)) {
            $article['mentions'] = $mentions;
        }

        // Add primary entity as "about" if available
        foreach ($entities as $entity) {
            if (!empty($entity->is_primary)) {
                $slug = !empty($entity->slug) ? $entity->slug : sanitize_title($entity->name);
                $article['about'] = [
                    '@id' => $site_url . '/#/entity/' . $slug,
                ];
                break;
            }
        }

        return $article;
    }

    /**
     * Build complete schema structure for a post.
     *
     * @param \WP_Post $post     The post object.
     * @param array    $entities Array of entity objects.
     *
     * @return array<string, mixed> Complete JSON-LD structure.
     */
    private function build_schema_structure(\WP_Post $post, array $entities): array
    {
        // Build the @graph array
        $graph = [];

        // Add article node
        $graph[] = $this->build_article_schema($post, $entities);

        // Add entity nodes
        foreach ($entities as $entity) {
            $graph[] = $this->build_entity_schema($entity);
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];
    }

    /**
     * Get the Schema.org article type for a post.
     *
     * @param \WP_Post $post Post object.
     *
     * @return string Schema.org type.
     */
    private function get_article_type(\WP_Post $post): string
    {
        $type_map = [
            'post' => 'Article',
            'page' => 'WebPage',
        ];

        /**
         * Filter the article type mapping.
         *
         * @param array    $type_map Post type to Schema.org type mapping.
         * @param \WP_Post $post     The post object.
         */
        $type_map = apply_filters('vibe_ai_article_type_map', $type_map, $post);

        return $type_map[$post->post_type] ?? 'Article';
    }

    /**
     * Map internal entity type to Schema.org type.
     *
     * @param string      $internal_type Internal type (e.g., PERSON, ORG).
     * @param string|null $schema_type   Override schema type if set.
     *
     * @return string Schema.org type.
     */
    private function map_entity_type(string $internal_type, ?string $schema_type): string
    {
        // Use override if provided
        if (!empty($schema_type)) {
            return $schema_type;
        }

        $type_upper = strtoupper($internal_type);

        return self::TYPE_MAPPING[$type_upper] ?? 'Thing';
    }

    /**
     * Check if a post has valid cached schema.
     *
     * @param int $post_id The post ID.
     *
     * @return bool True if cache is valid.
     */
    public function has_valid_cache(int $post_id): bool
    {
        $cached = $this->get_cached($post_id);

        if ($cached === null) {
            return false;
        }

        $version = (int) get_post_meta($post_id, self::META_SCHEMA_VERSION, true);

        return $version === self::SCHEMA_VERSION;
    }

    /**
     * Get schema cache metadata.
     *
     * @param int $post_id The post ID.
     *
     * @return array{cached: bool, version: int|null, extracted_at: string|null}
     */
    public function get_cache_metadata(int $post_id): array
    {
        $cached = $this->get_cached($post_id);

        return [
            'cached'       => $cached !== null,
            'version'      => $cached !== null
                ? (int) get_post_meta($post_id, self::META_SCHEMA_VERSION, true)
                : null,
            'extracted_at' => $cached !== null
                ? get_post_meta($post_id, self::META_EXTRACTED_AT, true) ?: null
                : null,
        ];
    }

    /**
     * Create entity repository instance.
     *
     * @return EntityRepository
     */
    private function create_repository(): EntityRepository
    {
        if (function_exists('vibe_ai_get_service')) {
            return vibe_ai_get_service(EntityRepository::class);
        }

        return new EntityRepository();
    }

    /**
     * Log a message.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context.
     *
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('vibe_ai_log')) {
            vibe_ai_log($level, '[SchemaGenerator] ' . $message, $context);
        }

        do_action('vibe_ai_service_log', 'schema_generator', $level, $message, $context);
    }
}

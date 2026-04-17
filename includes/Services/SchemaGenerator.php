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
    public const SCHEMA_VERSION = 2;

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
        $site_url = $this->get_site_url();
        $post_url = get_permalink($post);
        $description = $this->get_post_description($post);
        $image = $this->get_post_image($post);

        $article = [
            '@type'            => $this->get_article_type($post),
            '@id'              => $post_url . '#article',
            'url'              => $post_url,
            'name'             => $post->post_title,
            'headline'         => $post->post_title,
            'datePublished'    => get_the_date('c', $post),
            'dateModified'     => get_the_modified_date('c', $post),
            'mainEntityOfPage' => [
                '@id' => $post_url,
            ],
            'isPartOf'         => [
                '@id' => $site_url . '#website',
            ],
            'publisher'        => [
                '@id' => $site_url . '#publisher',
            ],
        ];

        if (!empty($description)) {
            $article['description'] = $description;
        }

        if (!empty($image)) {
            $article['image'] = $image;
        }

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
            $mentions[] = $this->build_entity_reference($entity);
        }

        if (!empty($mentions)) {
            $article['mentions'] = $mentions;
        }

        // Add primary entity as "about" if available
        $primary_entity = $this->get_primary_entity_reference($entities);
        if ($primary_entity !== null) {
            $article['about'] = $primary_entity;
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

        $graph[] = $this->build_website_schema();
        $graph[] = $this->build_publisher_schema();

        if ($this->should_add_webpage_node($post)) {
            $graph[] = $this->build_webpage_schema($post, $entities);
        }

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
     * Build a site-wide WebSite node.
     *
     * @return array<string, mixed>
     */
    private function build_website_schema(): array
    {
        $site_url = $this->get_site_url();
        $site_name = $this->get_site_name();
        $site_description = $this->get_site_description();

        $website = [
            '@type'     => 'WebSite',
            '@id'       => $site_url . '#website',
            'url'       => $site_url,
            'name'      => $site_name,
            'publisher' => [
                '@id' => $site_url . '#publisher',
            ],
        ];

        if (!empty($site_description)) {
            $website['description'] = $site_description;
        }

        return $website;
    }

    /**
     * Build the site publisher node.
     *
     * @return array<string, mixed>
     */
    private function build_publisher_schema(): array
    {
        $site_url = $this->get_site_url();
        $publisher_type = (string) apply_filters('vibe_ai_site_publisher_type', 'Organization');
        $publisher = [
            '@type' => $publisher_type,
            '@id'   => $site_url . '#publisher',
            'name'  => $this->get_site_name(),
            'url'   => $site_url,
        ];

        $site_description = $this->get_site_description();
        if (!empty($site_description)) {
            $publisher['description'] = $site_description;
        }

        $logo_url = $this->get_site_logo_url();
        if (!empty($logo_url)) {
            $publisher['logo'] = [
                '@type' => 'ImageObject',
                'url'   => $logo_url,
            ];
        }

        return $publisher;
    }

    /**
     * Build a canonical WebPage node for the current post.
     *
     * @param \WP_Post $post     The post object.
     * @param array    $entities Array of entity objects.
     *
     * @return array<string, mixed>
     */
    private function build_webpage_schema(\WP_Post $post, array $entities): array
    {
        $site_url = $this->get_site_url();
        $post_url = get_permalink($post);
        $description = $this->get_post_description($post);
        $image = $this->get_post_image($post);

        $page = [
            '@type'         => 'WebPage',
            '@id'           => $post_url,
            'url'           => $post_url,
            'name'          => $post->post_title,
            'datePublished' => get_the_date('c', $post),
            'dateModified'  => get_the_modified_date('c', $post),
            'isPartOf'      => [
                '@id' => $site_url . '#website',
            ],
        ];

        if (!empty($description)) {
            $page['description'] = $description;
        }

        if (!empty($image)) {
            $page['primaryImageOfPage'] = [
                '@type' => 'ImageObject',
                'url'   => $image,
            ];
        }

        $primary_entity = $this->get_primary_entity_reference($entities);
        if ($primary_entity !== null) {
            $page['about'] = $primary_entity;
        }

        return $page;
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
     * Determine whether an additional WebPage node should be emitted.
     *
     * When the primary content node is already a WebPage (for example, pages),
     * we avoid duplicating the canonical page node.
     *
     * @param \WP_Post $post Post object.
     * @return bool True when a separate WebPage node should be added.
     */
    private function should_add_webpage_node(\WP_Post $post): bool
    {
        return $this->get_article_type($post) !== 'WebPage';
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
     * Build a reference object for an entity node.
     *
     * @param object $entity Entity object.
     * @return array<string, string>
     */
    private function build_entity_reference(object $entity): array
    {
        $site_url = $this->get_site_url();
        $slug = !empty($entity->slug) ? $entity->slug : sanitize_title($entity->name);

        return [
            '@id' => $site_url . '/#/entity/' . $slug,
        ];
    }

    /**
     * Get the primary entity reference for a post, if available.
     *
     * @param array $entities Array of entity objects.
     * @return array<string, string>|null
     */
    private function get_primary_entity_reference(array $entities): ?array
    {
        foreach ($entities as $entity) {
            if (!empty($entity->is_primary)) {
                return $this->build_entity_reference($entity);
            }
        }

        return null;
    }

    /**
     * Get the current site URL.
     *
     * @return string
     */
    private function get_site_url(): string
    {
        if (function_exists('get_site_url')) {
            return (string) get_site_url();
        }

        return (string) home_url();
    }

    /**
     * Get the current site name.
     *
     * @return string
     */
    private function get_site_name(): string
    {
        if (function_exists('get_bloginfo')) {
            return (string) get_bloginfo('name');
        }

        return 'Website';
    }

    /**
     * Get the current site description.
     *
     * @return string|null
     */
    private function get_site_description(): ?string
    {
        if (!function_exists('get_bloginfo')) {
            return null;
        }

        $description = trim((string) get_bloginfo('description'));

        return $description !== '' ? $description : null;
    }

    /**
     * Get a post description suitable for schema output.
     *
     * @param \WP_Post $post Post object.
     * @return string|null
     */
    private function get_post_description(\WP_Post $post): ?string
    {
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags((string) $post->post_excerpt);
        }

        $yoast_description = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
        if (!empty($yoast_description)) {
            return (string) $yoast_description;
        }

        $rank_math_description = get_post_meta($post->ID, 'rank_math_description', true);
        if (!empty($rank_math_description)) {
            return (string) $rank_math_description;
        }

        if (empty($post->post_content)) {
            return null;
        }

        return wp_trim_words(wp_strip_all_tags((string) $post->post_content), 30, '...');
    }

    /**
     * Get a site logo URL, if WordPress exposes one.
     *
     * @return string|null
     */
    private function get_site_logo_url(): ?string
    {
        if (!function_exists('get_site_icon_url')) {
            return null;
        }

        $logo_url = get_site_icon_url();

        return !empty($logo_url) ? (string) $logo_url : null;
    }

    /**
     * Get a representative post image URL, if one exists.
     *
     * @param \WP_Post $post Post object.
     * @return string|null
     */
    private function get_post_image(\WP_Post $post): ?string
    {
        if (!function_exists('has_post_thumbnail') || !function_exists('get_the_post_thumbnail_url')) {
            return null;
        }

        if (!has_post_thumbnail($post)) {
            return null;
        }

        $image_url = get_the_post_thumbnail_url($post, 'full');

        return !empty($image_url) ? (string) $image_url : null;
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

<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

/**
 * SchemaInjector: Handle frontend JSON-LD injection.
 *
 * Injects Schema.org JSON-LD structured data into the <head> section
 * of singular pages for enhanced SEO and AI discoverability.
 *
 * @package Vibe\AIIndex\Services
 * @since 1.0.0
 */
class SchemaInjector
{
    /**
     * Meta key for schema cache (matches SchemaGenerator).
     */
    private const META_SCHEMA_CACHE = '_vibe_ai_schema_cache';

    /**
     * Schema generator instance.
     *
     * @var SchemaGenerator
     */
    private SchemaGenerator $generator;

    /**
     * Whether hooks have been registered.
     *
     * @var bool
     */
    private bool $hooks_registered = false;

    /**
     * Constructor.
     *
     * @param SchemaGenerator|null $generator Optional generator instance.
     */
    public function __construct(?SchemaGenerator $generator = null)
    {
        $this->generator = $generator ?? $this->create_generator();
    }

    /**
     * Register WordPress hooks for schema injection.
     *
     * @return void
     */
    public function register_hooks(): void
    {
        if ($this->hooks_registered) {
            return;
        }

        // Hook into wp_head for JSON-LD output
        add_action('wp_head', [$this, 'inject'], 99);

        $this->hooks_registered = true;
    }

    /**
     * Inject JSON-LD schema into page head.
     *
     * Called on wp_head hook. Only outputs schema on singular pages.
     *
     * @return void
     */
    public function inject(): void
    {
        // Only inject on singular pages (posts, pages, custom post types)
        if (!is_singular()) {
            return;
        }

        $post_id = get_the_ID();

        if (!$post_id) {
            return;
        }

        // Check if injection is enabled for this post type
        if (!$this->should_inject($post_id)) {
            return;
        }

        // Try to get cached schema
        $schema = $this->generator->get_cached($post_id);

        // If no cache, generate on-the-fly (but log warning)
        if ($schema === null) {
            $this->log('warning', "No cached schema for post {$post_id}, generating on-the-fly", [
                'post_id' => $post_id,
                'url'     => get_permalink($post_id),
            ]);

            try {
                $schema = $this->generator->generate($post_id);
            } catch (\Throwable $e) {
                $this->log('error', "Failed to generate schema for post {$post_id}", [
                    'error' => $e->getMessage(),
                ]);
                return;
            }
        }

        // Don't output empty schema
        if (empty($schema) || $schema === '{}') {
            return;
        }

        // Validate JSON before output
        $decoded = json_decode($schema, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', "Invalid JSON in schema cache for post {$post_id}", [
                'error' => json_last_error_msg(),
            ]);
            return;
        }

        // Re-encode with security flags for safe HTML embedding
        $safe_json = wp_json_encode(
            $decoded,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        if ($safe_json === false) {
            return;
        }

        // Output the JSON-LD script tag
        $this->output_script($safe_json);
    }

    /**
     * Output the JSON-LD script tag.
     *
     * @param string $json The JSON-LD content.
     *
     * @return void
     */
    private function output_script(string $json): void
    {
        // Use KSES-compatible output
        echo '<script type="application/ld+json">';
        // JSON is already escaped with JSON_HEX_* flags for safe embedding
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $json;
        echo '</script>' . "\n";
    }

    /**
     * Check if schema should be injected for a post.
     *
     * @param int $post_id The post ID.
     *
     * @return bool True if schema should be injected.
     */
    private function should_inject(int $post_id): bool
    {
        $post = get_post($post_id);

        if (!$post) {
            return false;
        }

        // Only inject for published posts
        if ($post->post_status !== 'publish') {
            return false;
        }

        // Get allowed post types (default: post, page)
        $allowed_types = $this->get_allowed_post_types();

        if (!in_array($post->post_type, $allowed_types, true)) {
            return false;
        }

        // Check for per-post disable meta
        $disabled = get_post_meta($post_id, '_vibe_ai_schema_disabled', true);
        if ($disabled === '1' || $disabled === true) {
            return false;
        }

        /**
         * Filter whether to inject schema for a specific post.
         *
         * @param bool     $should_inject Whether to inject schema.
         * @param int      $post_id       The post ID.
         * @param \WP_Post $post          The post object.
         */
        return (bool) apply_filters('vibe_ai_should_inject_schema', true, $post_id, $post);
    }

    /**
     * Get post types allowed for schema injection.
     *
     * @return array<string> Array of post type names.
     */
    private function get_allowed_post_types(): array
    {
        $default_types = ['post', 'page'];

        /**
         * Filter the post types allowed for schema injection.
         *
         * @param array $post_types Array of post type names.
         */
        return (array) apply_filters('vibe_ai_schema_post_types', $default_types);
    }

    /**
     * Manually inject schema for a specific post.
     *
     * Useful for AJAX or REST API responses.
     *
     * @param int $post_id The post ID.
     *
     * @return string The JSON-LD script tag HTML, or empty string on failure.
     */
    public function get_script_tag(int $post_id): string
    {
        $schema = $this->generator->get_cached($post_id);

        if ($schema === null) {
            try {
                $schema = $this->generator->generate($post_id);
            } catch (\Throwable $e) {
                return '';
            }
        }

        if (empty($schema) || $schema === '{}') {
            return '';
        }

        // Validate and re-encode
        $decoded = json_decode($schema, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }

        $safe_json = wp_json_encode(
            $decoded,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        if ($safe_json === false) {
            return '';
        }

        return '<script type="application/ld+json">' . $safe_json . '</script>';
    }

    /**
     * Check if schema injection is currently enabled globally.
     *
     * @return bool True if enabled.
     */
    public function is_enabled(): bool
    {
        return (bool) get_option('vibe_ai_schema_injection_enabled', true);
    }

    /**
     * Enable or disable schema injection globally.
     *
     * @param bool $enabled Whether to enable injection.
     *
     * @return void
     */
    public function set_enabled(bool $enabled): void
    {
        update_option('vibe_ai_schema_injection_enabled', $enabled);
    }

    /**
     * Create schema generator instance.
     *
     * @return SchemaGenerator
     */
    private function create_generator(): SchemaGenerator
    {
        if (function_exists('vibe_ai_get_service')) {
            return vibe_ai_get_service(SchemaGenerator::class);
        }

        return new SchemaGenerator();
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
            vibe_ai_log($level, '[SchemaInjector] ' . $message, $context);
        }

        do_action('vibe_ai_service_log', 'schema_injector', $level, $message, $context);
    }
}

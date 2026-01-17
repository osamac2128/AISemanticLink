<?php
/**
 * Configuration constants for AI Entity Index
 *
 * @package Vibe\AIIndex
 */

declare(strict_types=1);

namespace Vibe\AIIndex;

/**
 * Central configuration class containing all plugin constants.
 */
class Config
{
    // =================================================================
    // Processing Configuration
    // =================================================================

    /** @var int Default batch size for processing posts */
    public const BATCH_SIZE = 50;

    /** @var int Minimum batch size (dynamic sizing floor) */
    public const MIN_BATCH_SIZE = 5;

    /** @var int Maximum batch size (dynamic sizing ceiling) */
    public const MAX_BATCH_SIZE = 50;

    /** @var int Maximum concurrent batches allowed */
    public const MAX_CONCURRENT_BATCHES = 3;

    /** @var int Batch size for entity propagation jobs */
    public const PROPAGATION_BATCH_SIZE = 50;

    /** @var float Target processing time in seconds for dynamic batch sizing */
    public const TARGET_PROCESS_TIME = 5.0;

    // =================================================================
    // AI Model Configuration
    // =================================================================

    /** @var string Default AI model for entity extraction (Claude Opus 4.5 via OpenRouter) */
    public const DEFAULT_MODEL = 'anthropic/claude-opus-4.5';

    /** @var string Legacy alias for extraction model */
    public const DEFAULT_ENTITY_EXTRACTION_MODEL = self::DEFAULT_MODEL;

    /** @var string Fallback model when primary is unavailable */
    public const FALLBACK_MODEL = 'anthropic/claude-opus-4.5';

    /** @var string Budget model for high-volume processing */
    public const BUDGET_MODEL = 'anthropic/claude-opus-4.5';

    /** @var int Maximum tokens for AI response */
    public const MAX_TOKENS = 4096;

    /** @var float Temperature setting for AI model (low for consistency) */
    public const TEMPERATURE = 0.1;

    // =================================================================
    // Rate Limiting Configuration
    // =================================================================

    /** @var int Maximum API requests per minute */
    public const REQUESTS_PER_MINUTE = 60;

    /** @var int Maximum tokens per minute */
    public const TOKENS_PER_MINUTE = 100000;

    /** @var int Number of retry attempts for failed API calls */
    public const RETRY_ATTEMPTS = 3;

    /** @var int Backoff multiplier for exponential retry */
    public const BACKOFF_MULTIPLIER = 2;

    /** @var int Base delay in seconds before retry */
    public const BASE_DELAY_SECONDS = 5;

    // =================================================================
    // Confidence Thresholds
    // =================================================================

    /** @var float High confidence threshold (auto-approve) */
    public const CONFIDENCE_HIGH = 0.85;

    /** @var float Medium confidence threshold (include, flag for review) */
    public const CONFIDENCE_MEDIUM = 0.60;

    /** @var float Low confidence threshold (store but exclude from schema) */
    public const CONFIDENCE_LOW = 0.40;

    /** @var float Minimum confidence for Schema.org inclusion */
    public const SCHEMA_MIN_CONFIDENCE = 0.60;

    // =================================================================
    // Limits
    // =================================================================

    /** @var int Maximum entities allowed per post */
    public const MAX_ENTITIES_PER_POST = 50;

    /** @var int Maximum context snippet length in characters */
    public const MAX_CONTEXT_LENGTH = 500;

    /** @var int Maximum aliases allowed per entity */
    public const MAX_ALIASES_PER_ENTITY = 20;

    /** @var int Maximum description length in characters */
    public const MAX_DESCRIPTION_LENGTH = 500;

    // =================================================================
    // Cache Configuration
    // =================================================================

    /** @var int Schema cache version for invalidation */
    public const SCHEMA_CACHE_VERSION = 1;

    /** @var int Propagation timeout in seconds (1 hour) */
    public const PROPAGATION_TIMEOUT = 3600;

    /** @var int Status polling interval in milliseconds */
    public const POLLING_INTERVAL_MS = 2000;

    // =================================================================
    // Database Configuration
    // =================================================================

    /** @var string Database table name for entities (without prefix) */
    public const TABLE_ENTITIES = 'ai_entities';

    /** @var string Database table name for mentions (without prefix) */
    public const TABLE_MENTIONS = 'ai_mentions';

    /** @var string Database table name for aliases (without prefix) */
    public const TABLE_ALIASES = 'ai_aliases';

    /** @var string Database table name for KB documents (without prefix) */
    public const TABLE_KB_DOCS = 'ai_kb_docs';

    /** @var string Database table name for KB chunks (without prefix) */
    public const TABLE_KB_CHUNKS = 'ai_kb_chunks';

    /** @var string Database table name for KB vectors (without prefix) */
    public const TABLE_KB_VECTORS = 'ai_kb_vectors';

    // =================================================================
    // Post Meta Keys
    // =================================================================

    /** @var string Post meta key for cached Schema.org JSON-LD */
    public const META_SCHEMA_CACHE = '_vibe_ai_schema_cache';

    /** @var string Post meta key for last extraction timestamp */
    public const META_EXTRACTED_AT = '_vibe_ai_extracted_at';

    /** @var string Post meta key for schema cache version */
    public const META_SCHEMA_VERSION = '_vibe_ai_schema_version';

    // =================================================================
    // Entity Types and Schema.org Mapping
    // =================================================================

    /** @var array<string, string> Internal type to Schema.org type mapping */
    public const TYPE_MAPPING = [
        'PERSON' => 'Person',
        'ORG' => 'Organization',
        'COMPANY' => 'Corporation',
        'LOCATION' => 'Place',
        'COUNTRY' => 'Country',
        'PRODUCT' => 'Product',
        'SOFTWARE' => 'SoftwareApplication',
        'EVENT' => 'Event',
        'WORK' => 'CreativeWork',
        'CONCEPT' => 'Thing',
    ];

    /** @var array<string> Valid entity types */
    public const VALID_TYPES = [
        'PERSON',
        'ORG',
        'COMPANY',
        'LOCATION',
        'COUNTRY',
        'PRODUCT',
        'SOFTWARE',
        'EVENT',
        'WORK',
        'CONCEPT',
    ];

    /** @var array<string> Valid entity statuses */
    public const VALID_STATUSES = [
        'raw',
        'reviewed',
        'canonical',
        'trash',
        'rejected',
    ];

    // =================================================================
    // Pipeline Phases
    // =================================================================

    /** @var array<string, string> Pipeline phase definitions */
    public const PIPELINE_PHASES = [
        'preparation' => 'Collect post IDs, strip HTML to plain text',
        'extraction' => 'Send content to AI, parse entity JSON response',
        'deduplication' => 'Resolve aliases, merge duplicates, normalize names',
        'linking' => 'Create mention records connecting entities to posts',
        'indexing' => 'Update entity counts, build reverse index',
        'schema_build' => 'Generate JSON-LD blobs, cache in post_meta',
    ];

    // =================================================================
    // Default Post Types
    // =================================================================

    /** @var array<string> Default post types to process (full site coverage) */
    public const DEFAULT_POST_TYPES = ['post', 'page', 'product', 'attachment'];

    /** @var array<string> Common custom post types to auto-detect */
    public const COMMON_POST_TYPES = [
        'post',
        'page',
        'product',           // WooCommerce
        'attachment',        // Media
        'portfolio',         // Portfolio plugins
        'project',           // Project management
        'team',              // Team members
        'testimonial',       // Testimonials
        'service',           // Services
        'event',             // Events
        'course',            // LMS courses
        'lesson',            // LMS lessons
        'download',          // Easy Digital Downloads
        'tribe_events',      // The Events Calendar
        'elementor_library', // Elementor templates
    ];

    // =================================================================
    // Logging Configuration
    // =================================================================

    /** @var string Log directory name within uploads */
    public const LOG_DIRECTORY = 'vibe-ai-logs';

    /** @var array<string> Valid log levels */
    public const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];

    // =================================================================
    // REST API Configuration
    // =================================================================

    /** @var string REST API namespace */
    public const REST_NAMESPACE = 'vibe-ai/v1';

    /** @var string Required capability for admin endpoints */
    public const REQUIRED_CAPABILITY = 'manage_options';

    // =================================================================
    // Knowledge Base Configuration
    // =================================================================

    /** @var bool Whether KB feature is enabled by default */
    public const KB_ENABLED = false;

    /** @var string Default embedding model for KB vectors */
    public const KB_EMBEDDING_MODEL = 'openai/text-embedding-3-small';

    /** @var int Target token count per chunk */
    public const KB_CHUNK_TOKENS_TARGET = 450;

    /** @var int Token overlap between consecutive chunks */
    public const KB_CHUNK_OVERLAP_TOKENS = 60;

    /** @var int Default number of top results to return from vector search */
    public const KB_TOP_K_DEFAULT = 8;

    /** @var int Maximum vectors to scan during search */
    public const KB_MAX_SCAN_VECTORS = 5000;

    /** @var int Batch size for processing chunks */
    public const KB_BATCH_SIZE_CHUNKS = 25;

    /** @var int Minimum tokens for a valid chunk */
    public const KB_MIN_CHUNK_TOKENS = 50;

    /** @var int Maximum tokens allowed per chunk */
    public const KB_MAX_CHUNK_TOKENS = 800;

    // =================================================================
    // KB Post Meta Keys
    // =================================================================

    /** @var string Post meta key for KB enabled flag */
    public const KB_META_ENABLED = '_vibe_ai_kb_enabled';

    /** @var string Post meta key for last KB index timestamp */
    public const KB_META_INDEXED_AT = '_vibe_ai_kb_indexed_at';

    /** @var string Post meta key for KB index version */
    public const KB_META_VERSION = '_vibe_ai_kb_version';

    /** @var string Post meta key for KB exclusion flag */
    public const KB_META_EXCLUDED = '_vibe_ai_kb_excluded';

    // =================================================================
    // KB Pipeline Phases
    // =================================================================

    /** @var array<string> KB pipeline processing phases */
    public const KB_PHASES = [
        'kb_document_build',
        'kb_chunk_build',
        'kb_embed_chunks',
        'kb_index_upsert',
        'kb_cleanup',
    ];

    // =================================================================
    // KB Status Values
    // =================================================================

    /** @var string KB document status: pending processing */
    public const KB_STATUS_PENDING = 'pending';

    /** @var string KB document status: successfully indexed */
    public const KB_STATUS_INDEXED = 'indexed';

    /** @var string KB document status: error during processing */
    public const KB_STATUS_ERROR = 'error';

    /** @var string KB document status: excluded from indexing */
    public const KB_STATUS_EXCLUDED = 'excluded';

    /**
     * Get the Schema.org type for an internal entity type.
     *
     * @param string $internalType The internal entity type
     * @return string The corresponding Schema.org type
     */
    public static function getSchemaType(string $internalType): string
    {
        return self::TYPE_MAPPING[strtoupper($internalType)] ?? 'Thing';
    }

    /**
     * Check if an entity type is valid.
     *
     * @param string $type The entity type to validate
     * @return bool True if valid, false otherwise
     */
    public static function isValidType(string $type): bool
    {
        return in_array(strtoupper($type), self::VALID_TYPES, true);
    }

    /**
     * Check if an entity status is valid.
     *
     * @param string $status The status to validate
     * @return bool True if valid, false otherwise
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array(strtolower($status), self::VALID_STATUSES, true);
    }

    /**
     * Get confidence tier for a given confidence score.
     *
     * @param float $confidence The confidence score (0.0-1.0)
     * @return string The confidence tier (high, medium, low, reject)
     */
    public static function getConfidenceTier(float $confidence): string
    {
        if ($confidence >= self::CONFIDENCE_HIGH) {
            return 'high';
        }
        if ($confidence >= self::CONFIDENCE_MEDIUM) {
            return 'medium';
        }
        if ($confidence >= self::CONFIDENCE_LOW) {
            return 'low';
        }
        return 'reject';
    }

    /**
     * Get post types enabled for Knowledge Base indexing.
     *
     * @return array<string> Filterable array of post types
     */
    public static function getKBPostTypes(): array
    {
        $default_types = self::DEFAULT_POST_TYPES;

        /**
         * Filter the post types enabled for KB indexing.
         *
         * @param array<string> $post_types Array of post type slugs
         */
        return apply_filters('vibe_ai_kb_post_types', $default_types);
    }

    /**
     * Check if Knowledge Base feature is enabled.
     *
     * @return bool True if KB is enabled, false otherwise
     */
    public static function isKBEnabled(): bool
    {
        return (bool) get_option('vibe_ai_kb_enabled', self::KB_ENABLED);
    }

    /**
     * Get the configured embedding model for KB vectors.
     *
     * @return string The embedding model identifier
     */
    public static function getEmbeddingModel(): string
    {
        return get_option('vibe_ai_kb_embedding_model', self::KB_EMBEDDING_MODEL);
    }
}

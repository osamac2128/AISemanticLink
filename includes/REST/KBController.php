<?php
/**
 * REST API Controller for Knowledge Base endpoints
 *
 * Provides a complete REST API layer for managing the Knowledge Base,
 * including semantic search, document management, and pipeline operations.
 *
 * @package Vibe\AIIndex\REST
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Vibe\AIIndex\REST;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Logger;
use Vibe\AIIndex\Pipeline\KBPipelineManager;
use Vibe\AIIndex\Services\KB\SimilaritySearch;
use Vibe\AIIndex\Services\KB\EmbeddingClient;
use Vibe\AIIndex\Services\KB\LlmsTxtGenerator;
use Vibe\AIIndex\Services\KB\AISitemapGenerator;
use Vibe\AIIndex\Services\KB\ChangeFeedGenerator;
use Vibe\AIIndex\Repositories\KB\DocumentRepository;
use Vibe\AIIndex\Repositories\KB\ChunkRepository;
use Vibe\AIIndex\Repositories\KB\VectorRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST API controller for Knowledge Base endpoints.
 * Base: /wp-json/vibe-ai/v1/kb/
 */
class KBController
{
    /**
     * REST API namespace.
     *
     * @var string
     */
    private const NAMESPACE = 'vibe-ai/v1';

    /**
     * Base route for KB endpoints.
     *
     * @var string
     */
    private const BASE = 'kb';

    /**
     * Maximum query length for semantic search.
     *
     * @var int
     */
    private const MAX_QUERY_LENGTH = 2000;

    /**
     * Default number of results for search.
     *
     * @var int
     */
    private const DEFAULT_TOP_K = 8;

    /**
     * Maximum number of results for search.
     *
     * @var int
     */
    private const MAX_TOP_K = 50;

    /**
     * Logger instance.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * KB Pipeline manager instance (lazy loaded).
     *
     * @var KBPipelineManager|null
     */
    private ?KBPipelineManager $pipelineManager = null;

    /**
     * Document repository instance (lazy loaded).
     *
     * @var DocumentRepository|null
     */
    private ?DocumentRepository $docRepo = null;

    /**
     * Chunk repository instance (lazy loaded).
     *
     * @var ChunkRepository|null
     */
    private ?ChunkRepository $chunkRepo = null;

    /**
     * Vector repository instance (lazy loaded).
     *
     * @var VectorRepository|null
     */
    private ?VectorRepository $vectorRepo = null;

    /**
     * LlmsTxt generator instance (lazy loaded).
     *
     * @var LlmsTxtGenerator|null
     */
    private ?LlmsTxtGenerator $llmsTxtGenerator = null;

    /**
     * AI Sitemap generator instance (lazy loaded).
     *
     * @var AISitemapGenerator|null
     */
    private ?AISitemapGenerator $sitemapGenerator = null;

    /**
     * Change feed generator instance (lazy loaded).
     *
     * @var ChangeFeedGenerator|null
     */
    private ?ChangeFeedGenerator $feedGenerator = null;

    /**
     * Initialize the KB controller.
     */
    public function __construct()
    {
        $this->logger = new Logger();
    }

    /**
     * Register all KB REST API routes.
     *
     * Called via rest_api_init action hook.
     *
     * @return void
     */
    public function register_routes(): void
    {
        // =================================================================
        // Search Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/search', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'search'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'query' => [
                        'description'       => __('The search query string.', 'ai-entity-index'),
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => [$this, 'validate_search_query'],
                    ],
                    'top_k' => [
                        'description'       => __('Number of results to return.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'default'           => self::DEFAULT_TOP_K,
                        'minimum'           => 1,
                        'maximum'           => self::MAX_TOP_K,
                        'sanitize_callback' => 'absint',
                    ],
                    'filters' => [
                        'description' => __('Filter criteria for search results.', 'ai-entity-index'),
                        'type'        => 'object',
                        'default'     => [],
                        'properties'  => [
                            'post_type' => [
                                'type'  => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'post_ids' => [
                                'type'  => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                            'exclude_ids' => [
                                'type'  => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                            'date_after' => [
                                'type'   => 'string',
                                'format' => 'date',
                            ],
                            'date_before' => [
                                'type'   => 'string',
                                'format' => 'date',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Status Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/status', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_status'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        // =================================================================
        // Documents Collection Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/docs', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_documents'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => $this->get_documents_collection_params(),
            ],
        ]);

        // =================================================================
        // Single Document Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/docs/(?P<post_id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_document'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'post_id' => [
                        'description'       => __('The post ID of the document.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Document Exclusion Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/docs/(?P<post_id>\d+)/exclude', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'exclude_document'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'post_id' => [
                        'description'       => __('The post ID to exclude from KB.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Document Inclusion Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/docs/(?P<post_id>\d+)/include', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'include_document'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'post_id' => [
                        'description'       => __('The post ID to include in KB.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Single Document Reindex Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/docs/(?P<post_id>\d+)/reindex', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'reindex_document'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'post_id' => [
                        'description'       => __('The post ID to reindex.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Full Reindex Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/reindex', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'trigger_reindex'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'post_types' => [
                        'description'       => __('Post types to index.', 'ai-entity-index'),
                        'type'              => 'array',
                        'items'             => ['type' => 'string'],
                        'default'           => Config::DEFAULT_POST_TYPES,
                        'sanitize_callback' => [$this, 'sanitize_string_array'],
                    ],
                    'force' => [
                        'description' => __('Force reindex of all documents, even if unchanged.', 'ai-entity-index'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Stop Pipeline Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/stop', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'stop_pipeline'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        // =================================================================
        // Single Chunk Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/chunks/(?P<chunk_id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_chunk'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'chunk_id' => [
                        'description'       => __('The chunk ID.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Logs Endpoint
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/logs', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_logs'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'level' => [
                        'description'       => __('Minimum log level to include.', 'ai-entity-index'),
                        'type'              => 'string',
                        'enum'              => Config::LOG_LEVELS,
                        'default'           => 'info',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'description'       => __('Maximum number of log entries to return.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'default'           => 50,
                        'minimum'           => 1,
                        'maximum'           => 500,
                        'sanitize_callback' => 'absint',
                    ],
                    'component' => [
                        'description'       => __('Filter logs by component (kb, embedding, chunking).', 'ai-entity-index'),
                        'type'              => 'string',
                        'enum'              => ['kb', 'embedding', 'chunking', 'all'],
                        'default'           => 'all',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Settings Endpoints
        // =================================================================

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/settings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => $this->get_settings_update_params(),
            ],
        ]);

        // =================================================================
        // AI Publishing Endpoints (Public - for AI crawlers)
        // Phase 2 scaffold - provides llms.txt, sitemap, and change feed
        // =================================================================

        // GET /kb/llms-txt - Get llms.txt content for AI crawlers
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/llms-txt', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_llms_txt'],
                'permission_callback' => '__return_true', // Public endpoint
                'args'                => [
                    'mode' => [
                        'description'       => __('Generation mode: curated or full.', 'ai-entity-index'),
                        'type'              => 'string',
                        'default'           => 'curated',
                        'enum'              => ['curated', 'full'],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'include_descriptions' => [
                        'description' => __('Whether to include page descriptions.', 'ai-entity-index'),
                        'type'        => 'boolean',
                        'default'     => true,
                    ],
                    'max_entries' => [
                        'description'       => __('Maximum entries in full index.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'default'           => 100,
                        'minimum'           => 1,
                        'maximum'           => 1000,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // GET /kb/sitemap - Get AI-specific sitemap
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/sitemap', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_ai_sitemap'],
                'permission_callback' => '__return_true', // Public endpoint
                'args'                => [
                    'format' => [
                        'description'       => __('Output format: json or xml.', 'ai-entity-index'),
                        'type'              => 'string',
                        'default'           => 'json',
                        'enum'              => ['json', 'xml'],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // GET /kb/feed - Get change feed for AI agents
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/feed', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_change_feed'],
                'permission_callback' => '__return_true', // Public endpoint
                'args'                => [
                    'since' => [
                        'description'       => __('ISO 8601 datetime to get changes since.', 'ai-entity-index'),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => [$this, 'validate_iso_datetime'],
                    ],
                    'limit' => [
                        'description'       => __('Maximum number of changes to return.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'default'           => 50,
                        'minimum'           => 1,
                        'maximum'           => 500,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // GET/PUT /kb/config/pinned-pages - Manage pinned pages for llms.txt
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/config/pinned-pages', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_pinned_pages'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'set_pinned_pages'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'post_ids' => [
                        'description'       => __('Array of post IDs in desired order.', 'ai-entity-index'),
                        'type'              => 'array',
                        'required'          => true,
                        'items'             => ['type' => 'integer'],
                        'sanitize_callback' => [$this, 'sanitize_integer_array'],
                    ],
                ],
            ],
        ]);
    }

    // =========================================================================
    // Permission Callbacks
    // =========================================================================

    /**
     * Check if the current user has admin permissions.
     *
     * @return bool|WP_Error True if authorized, WP_Error otherwise.
     */
    public function check_admin_permission(): bool|WP_Error
    {
        if (!current_user_can(Config::REQUIRED_CAPABILITY)) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this resource.', 'ai-entity-index'),
                ['status' => 403]
            );
        }

        return true;
    }

    // =========================================================================
    // Validation Callbacks
    // =========================================================================

    /**
     * Validate a positive integer.
     *
     * @param mixed           $value   The value to validate.
     * @param WP_REST_Request $request The request object.
     * @param string          $param   The parameter name.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_positive_integer(mixed $value, WP_REST_Request $request, string $param): bool|WP_Error
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            return new WP_Error(
                'rest_invalid_param',
                sprintf(__('%s must be a positive integer.', 'ai-entity-index'), $param),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Validate ISO 8601 datetime string.
     *
     * @param mixed           $value   The value to validate.
     * @param WP_REST_Request $request The request object.
     * @param string          $param   The parameter name.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_iso_datetime(mixed $value, WP_REST_Request $request, string $param): bool|WP_Error
    {
        if (empty($value)) {
            return true; // Optional parameter
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return new WP_Error(
                'rest_invalid_param',
                sprintf(__('%s must be a valid ISO 8601 datetime.', 'ai-entity-index'), $param),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Validate search query.
     *
     * @param mixed           $value   The value to validate.
     * @param WP_REST_Request $request The request object.
     * @param string          $param   The parameter name.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_search_query(mixed $value, WP_REST_Request $request, string $param): bool|WP_Error
    {
        if (!is_string($value) || trim($value) === '') {
            return new WP_Error(
                'rest_invalid_param',
                __('Search query must be a non-empty string.', 'ai-entity-index'),
                ['status' => 400]
            );
        }

        if (mb_strlen($value) > self::MAX_QUERY_LENGTH) {
            return new WP_Error(
                'rest_invalid_param',
                sprintf(
                    __('Search query must not exceed %d characters.', 'ai-entity-index'),
                    self::MAX_QUERY_LENGTH
                ),
                ['status' => 400]
            );
        }

        return true;
    }

    // =========================================================================
    // Sanitization Callbacks
    // =========================================================================

    /**
     * Sanitize an array of strings.
     *
     * @param mixed $value The value to sanitize.
     * @return array<string> Sanitized array of strings.
     */
    public function sanitize_string_array(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_map('sanitize_text_field', array_filter($value, 'is_string'));
    }

    /**
     * Sanitize an array of integers.
     *
     * @param mixed $value The value to sanitize.
     * @return array<int> Sanitized array of integers.
     */
    public function sanitize_integer_array(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_map('absint', array_filter($value, 'is_numeric'));
    }

    // =========================================================================
    // Endpoint Parameter Definitions
    // =========================================================================

    /**
     * Get parameters for documents collection endpoint.
     *
     * @return array<string, array<string, mixed>> Parameter definitions.
     */
    private function get_documents_collection_params(): array
    {
        return [
            'page' => [
                'description'       => __('Current page of the collection.', 'ai-entity-index'),
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'description'       => __('Maximum number of items per page.', 'ai-entity-index'),
                'type'              => 'integer',
                'default'           => 20,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'description'       => __('Search documents by title.', 'ai-entity-index'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'description'       => __('Filter by indexing status.', 'ai-entity-index'),
                'type'              => 'string',
                'enum'              => ['', 'pending', 'indexed', 'failed', 'excluded'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'post_type' => [
                'description'       => __('Filter by post type.', 'ai-entity-index'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'description'       => __('Sort collection by attribute.', 'ai-entity-index'),
                'type'              => 'string',
                'default'           => 'last_indexed_at',
                'enum'              => ['id', 'post_id', 'title', 'status', 'chunk_count', 'last_indexed_at', 'created_at'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order' => [
                'description'       => __('Order sort attribute ascending or descending.', 'ai-entity-index'),
                'type'              => 'string',
                'default'           => 'DESC',
                'enum'              => ['ASC', 'DESC'],
                'sanitize_callback' => 'strtoupper',
            ],
        ];
    }

    /**
     * Get parameters for settings update endpoint.
     *
     * @return array<string, array<string, mixed>> Parameter definitions.
     */
    private function get_settings_update_params(): array
    {
        return [
            'kb_enabled' => [
                'description' => __('Enable or disable the Knowledge Base.', 'ai-entity-index'),
                'type'        => 'boolean',
            ],
            'embedding_model' => [
                'description'       => __('The embedding model to use.', 'ai-entity-index'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'chunk_size' => [
                'description'       => __('Target chunk size in tokens.', 'ai-entity-index'),
                'type'              => 'integer',
                'minimum'           => 100,
                'maximum'           => 2000,
                'sanitize_callback' => 'absint',
            ],
            'chunk_overlap' => [
                'description'       => __('Chunk overlap in tokens.', 'ai-entity-index'),
                'type'              => 'integer',
                'minimum'           => 0,
                'maximum'           => 500,
                'sanitize_callback' => 'absint',
            ],
            'post_types' => [
                'description'       => __('Post types to include in KB.', 'ai-entity-index'),
                'type'              => 'array',
                'items'             => ['type' => 'string'],
                'sanitize_callback' => [$this, 'sanitize_string_array'],
            ],
            'auto_index' => [
                'description' => __('Automatically index new/updated posts.', 'ai-entity-index'),
                'type'        => 'boolean',
            ],
        ];
    }

    // =========================================================================
    // Endpoint Callbacks
    // =========================================================================

    /**
     * POST /kb/search
     * Semantic similarity search.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function search(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $query = trim($request->get_param('query'));
        $topK = (int) ($request->get_param('top_k') ?? self::DEFAULT_TOP_K);
        $filters = $request->get_param('filters') ?? [];

        // Check if KB is enabled
        if (!$this->is_kb_enabled()) {
            return new WP_Error(
                'kb_disabled',
                __('Knowledge Base is not enabled.', 'ai-entity-index'),
                ['status' => 400]
            );
        }

        $startTime = microtime(true);

        try {
            // Initialize similarity search
            $similaritySearch = new SimilaritySearch(
                $this->get_vector_repository(),
                $this->get_chunk_repository(),
                $this->get_document_repository(),
                new EmbeddingClient()
            );

            // Execute search
            $searchResults = $similaritySearch->search($query, $topK, $filters);

            $endTime = microtime(true);
            $queryTimeMs = (int) round(($endTime - $startTime) * 1000);

            // Format results
            $results = array_map(function ($result) {
                return [
                    'chunk_id'       => (int) $result->chunk_id,
                    'post_id'        => (int) $result->post_id,
                    'doc_id'         => (int) $result->doc_id,
                    'title'          => $result->title,
                    'url'            => $result->url,
                    'anchor'         => $result->anchor,
                    'heading_path'   => $result->heading_path ?? [],
                    'chunk_text'     => $result->chunk_text,
                    'score'          => round((float) $result->score, 4),
                    'token_estimate' => (int) $result->token_estimate,
                ];
            }, $searchResults->results);

            $this->logger->info('KB semantic search performed', [
                'query_length'  => mb_strlen($query),
                'top_k'         => $topK,
                'results_count' => count($results),
                'query_time_ms' => $queryTimeMs,
            ]);

            return rest_ensure_response([
                'results'       => $results,
                'query'         => $query,
                'top_k'         => $topK,
                'total_scanned' => $searchResults->total_scanned,
                'query_time_ms' => $queryTimeMs,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('KB search failed', [
                'error' => $e->getMessage(),
                'query' => mb_substr($query, 0, 100),
            ]);

            return new WP_Error(
                'kb_search_failed',
                __('Search failed: ', 'ai-entity-index') . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * GET /kb/status
     * Get KB pipeline and index status.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_status(WP_REST_Request $request): WP_REST_Response
    {
        $docRepo = $this->get_document_repository();
        $chunkRepo = $this->get_chunk_repository();
        $vectorRepo = $this->get_vector_repository();

        // Get pipeline status
        $pipeline = $this->get_pipeline_manager();
        $pipelineStatus = $pipeline->get_status();

        // Get document stats
        $docStats = $docRepo->get_stats();

        // Get last indexed timestamp
        $lastIndexedAt = $docRepo->get_last_indexed_at();

        $response_data = [
            'kb_enabled' => $this->is_kb_enabled(),
            'pipeline'   => [
                'status'        => $pipelineStatus['status'],
                'current_phase' => $pipelineStatus['current_phase'] ?? null,
                'progress'      => [
                    'total'      => $pipelineStatus['progress']['total'] ?? 0,
                    'completed'  => $pipelineStatus['progress']['completed'] ?? 0,
                    'failed'     => $pipelineStatus['progress']['failed'] ?? 0,
                    'percentage' => $pipelineStatus['progress']['percentage'] ?? 100,
                ],
            ],
            'stats' => [
                'total_docs'    => (int) $docStats['total_docs'],
                'indexed_docs'  => (int) $docStats['indexed_docs'],
                'pending_docs'  => (int) $docStats['pending_docs'],
                'excluded_docs' => (int) $docStats['excluded_docs'],
                'failed_docs'   => (int) $docStats['failed_docs'],
                'total_chunks'  => (int) $chunkRepo->count(),
                'total_vectors' => (int) $vectorRepo->count(),
                'failed_chunks' => (int) $chunkRepo->count_failed(),
            ],
            'last_indexed_at' => $lastIndexedAt,
        ];

        $response = rest_ensure_response($response_data);
        $response->add_links($this->get_status_links());

        return $response;
    }

    /**
     * GET /kb/docs
     * Get paginated list of indexed documents.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_documents(WP_REST_Request $request): WP_REST_Response
    {
        $docRepo = $this->get_document_repository();

        $args = [
            'page'      => $request->get_param('page') ?? 1,
            'per_page'  => $request->get_param('per_page') ?? 20,
            'search'    => $request->get_param('search') ?? '',
            'status'    => $request->get_param('status') ?? '',
            'post_type' => $request->get_param('post_type') ?? '',
            'orderby'   => $request->get_param('orderby') ?? 'last_indexed_at',
            'order'     => $request->get_param('order') ?? 'DESC',
        ];

        $result = $docRepo->get_documents($args);

        // Format documents for response
        $documents = array_map(function ($doc) {
            return $this->format_document_response($doc);
        }, $result['items']);

        $response_data = [
            'documents'   => $documents,
            'total'       => $result['total'],
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $result['pages'],
        ];

        $response = rest_ensure_response($response_data);

        // Add pagination headers
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['pages']);

        // Add pagination links
        $response->add_links($this->get_documents_collection_links($result, $args));

        return $response;
    }

    /**
     * GET /kb/docs/{post_id}
     * Get single document with its chunks.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function get_document(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request->get_param('post_id');

        $docRepo = $this->get_document_repository();
        $chunkRepo = $this->get_chunk_repository();

        // Get document by post ID
        $document = $docRepo->get_by_post_id($postId);

        if ($document === null) {
            return new WP_Error(
                'kb_document_not_found',
                __('Document not found in Knowledge Base.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        // Get chunks for this document
        $chunks = $chunkRepo->get_chunks_for_document((int) $document->id);

        // Format response
        $response_data = $this->format_document_response($document, true);
        $response_data['chunks'] = array_map(function ($chunk) {
            return [
                'id'             => (int) $chunk->id,
                'chunk_index'    => (int) $chunk->chunk_index,
                'anchor'         => $chunk->anchor,
                'heading_path'   => json_decode($chunk->heading_path_json ?? '[]', true),
                'chunk_text'     => $chunk->chunk_text,
                'token_estimate' => (int) $chunk->token_estimate,
                'has_vector'     => (bool) $chunk->has_vector,
                'created_at'     => $chunk->created_at,
            ];
        }, $chunks);

        $response = rest_ensure_response($response_data);
        $response->add_links($this->get_document_links($postId));

        return $response;
    }

    /**
     * POST /kb/docs/{post_id}/exclude
     * Exclude a document from the Knowledge Base.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function exclude_document(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request->get_param('post_id');

        // Verify post exists
        $post = get_post($postId);
        if (!$post) {
            return new WP_Error(
                'rest_post_not_found',
                __('Post not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        $docRepo = $this->get_document_repository();

        // Mark as excluded
        $success = $docRepo->exclude_document($postId);

        if (!$success) {
            return new WP_Error(
                'kb_exclude_failed',
                __('Failed to exclude document from Knowledge Base.', 'ai-entity-index'),
                ['status' => 500]
            );
        }

        $this->logger->info('Document excluded from KB', ['post_id' => $postId]);

        // Get updated document
        $document = $docRepo->get_by_post_id($postId);

        return rest_ensure_response([
            'success'  => true,
            'message'  => __('Document excluded from Knowledge Base.', 'ai-entity-index'),
            'document' => $document ? $this->format_document_response($document) : null,
        ]);
    }

    /**
     * POST /kb/docs/{post_id}/include
     * Include a previously excluded document in the Knowledge Base.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function include_document(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request->get_param('post_id');

        // Verify post exists
        $post = get_post($postId);
        if (!$post) {
            return new WP_Error(
                'rest_post_not_found',
                __('Post not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        $docRepo = $this->get_document_repository();

        // Mark as pending (to be indexed)
        $success = $docRepo->include_document($postId);

        if (!$success) {
            return new WP_Error(
                'kb_include_failed',
                __('Failed to include document in Knowledge Base.', 'ai-entity-index'),
                ['status' => 500]
            );
        }

        $this->logger->info('Document included in KB', ['post_id' => $postId]);

        // Get updated document
        $document = $docRepo->get_by_post_id($postId);

        return rest_ensure_response([
            'success'  => true,
            'message'  => __('Document included in Knowledge Base. It will be indexed on next pipeline run.', 'ai-entity-index'),
            'document' => $document ? $this->format_document_response($document) : null,
        ]);
    }

    /**
     * POST /kb/docs/{post_id}/reindex
     * Reindex a single document.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function reindex_document(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request->get_param('post_id');

        // Verify post exists
        $post = get_post($postId);
        if (!$post) {
            return new WP_Error(
                'rest_post_not_found',
                __('Post not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        try {
            $pipeline = $this->get_pipeline_manager();
            $pipeline->reindex_single($postId);

            $this->logger->info('Single document reindex triggered', ['post_id' => $postId]);

            return rest_ensure_response([
                'success' => true,
                'message' => __('Document reindex has been scheduled.', 'ai-entity-index'),
                'post_id' => $postId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to trigger document reindex', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);

            return new WP_Error(
                'kb_reindex_failed',
                __('Failed to schedule document reindex: ', 'ai-entity-index') . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * POST /kb/reindex
     * Trigger full KB reindex.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function trigger_reindex(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Check if KB is enabled
        if (!$this->is_kb_enabled()) {
            return new WP_Error(
                'kb_disabled',
                __('Knowledge Base is not enabled.', 'ai-entity-index'),
                ['status' => 400]
            );
        }

        $pipeline = $this->get_pipeline_manager();

        // Check if already running
        if ($pipeline->is_running()) {
            return new WP_Error(
                'kb_pipeline_running',
                __('KB pipeline is already running. Stop it first before starting a new run.', 'ai-entity-index'),
                ['status' => 409]
            );
        }

        $options = [
            'post_types' => $request->get_param('post_types') ?? Config::DEFAULT_POST_TYPES,
            'force'      => (bool) $request->get_param('force'),
        ];

        try {
            $pipeline->start($options);

            $this->logger->info('KB full reindex started via REST API', [
                'options' => $options,
                'user_id' => get_current_user_id(),
            ]);

            $status = $pipeline->get_status();

            return rest_ensure_response([
                'success' => true,
                'message' => __('KB reindex started successfully.', 'ai-entity-index'),
                'status'  => $status['status'],
                'phase'   => $status['current_phase'],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to start KB reindex', ['error' => $e->getMessage()]);

            return new WP_Error(
                'kb_reindex_start_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * POST /kb/stop
     * Stop the KB indexing pipeline.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function stop_pipeline(WP_REST_Request $request): WP_REST_Response
    {
        $pipeline = $this->get_pipeline_manager();

        $wasRunning = $pipeline->is_running();
        $pipeline->stop();

        $this->logger->info('KB pipeline stopped via REST API', [
            'was_running' => $wasRunning,
            'user_id'     => get_current_user_id(),
        ]);

        $status = $pipeline->get_status();

        return rest_ensure_response([
            'success' => true,
            'message' => $wasRunning
                ? __('KB pipeline stopped successfully.', 'ai-entity-index')
                : __('KB pipeline was not running.', 'ai-entity-index'),
            'status'  => $status['status'],
        ]);
    }

    /**
     * GET /kb/chunks/{chunk_id}
     * Get chunk details.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function get_chunk(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $chunkId = (int) $request->get_param('chunk_id');

        $chunkRepo = $this->get_chunk_repository();
        $vectorRepo = $this->get_vector_repository();
        $docRepo = $this->get_document_repository();

        $chunk = $chunkRepo->get_by_id($chunkId);

        if ($chunk === null) {
            return new WP_Error(
                'kb_chunk_not_found',
                __('Chunk not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        // Get parent document info
        $document = $docRepo->get_by_id((int) $chunk->doc_id);

        // Check if vector exists
        $vector = $vectorRepo->get_by_chunk_id($chunkId);

        $response_data = [
            'id'             => (int) $chunk->id,
            'doc_id'         => (int) $chunk->doc_id,
            'chunk_index'    => (int) $chunk->chunk_index,
            'anchor'         => $chunk->anchor,
            'heading_path'   => json_decode($chunk->heading_path_json ?? '[]', true),
            'chunk_text'     => $chunk->chunk_text,
            'chunk_hash'     => $chunk->chunk_hash,
            'start_offset'   => (int) $chunk->start_offset,
            'end_offset'     => (int) $chunk->end_offset,
            'token_estimate' => (int) $chunk->token_estimate,
            'created_at'     => $chunk->created_at,
            'has_vector'     => $vector !== null,
            'vector_info'    => $vector ? [
                'provider' => $vector->provider,
                'model'    => $vector->model,
                'dims'     => (int) $vector->dims,
            ] : null,
            'document'       => $document ? [
                'id'       => (int) $document->id,
                'post_id'  => (int) $document->post_id,
                'title'    => $document->title,
                'url'      => $document->url,
            ] : null,
        ];

        $response = rest_ensure_response($response_data);

        if ($document) {
            $response->add_links([
                'document' => [
                    ['href' => rest_url(self::NAMESPACE . '/' . self::BASE . '/docs/' . $document->post_id)],
                ],
            ]);
        }

        return $response;
    }

    /**
     * GET /kb/logs
     * Get KB-specific logs.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_logs(WP_REST_Request $request): WP_REST_Response
    {
        $level = $request->get_param('level') ?? 'info';
        $limit = $request->get_param('limit') ?? 50;
        $component = $request->get_param('component') ?? 'all';

        // Get recent logs
        $allLogs = $this->logger->getRecentLogs($limit * 3, $level); // Get more to filter

        // Filter by component if needed
        if ($component !== 'all') {
            $filteredLogs = array_filter($allLogs, function ($entry) use ($component) {
                $message = strtolower($entry['message']);
                switch ($component) {
                    case 'kb':
                        return strpos($message, 'kb ') !== false || strpos($message, 'knowledge base') !== false;
                    case 'embedding':
                        return strpos($message, 'embedding') !== false || strpos($message, 'vector') !== false;
                    case 'chunking':
                        return strpos($message, 'chunk') !== false;
                    default:
                        return true;
                }
            });
            $logs = array_slice(array_values($filteredLogs), 0, $limit);
        } else {
            // Filter for KB-related logs only
            $kbLogs = array_filter($allLogs, function ($entry) {
                $message = strtolower($entry['message']);
                return strpos($message, 'kb') !== false
                    || strpos($message, 'knowledge base') !== false
                    || strpos($message, 'embedding') !== false
                    || strpos($message, 'chunk') !== false
                    || strpos($message, 'vector') !== false
                    || strpos($message, 'semantic search') !== false;
            });
            $logs = array_slice(array_values($kbLogs), 0, $limit);
        }

        return rest_ensure_response([
            'entries'   => $logs,
            'count'     => count($logs),
            'level'     => $level,
            'component' => $component,
        ]);
    }

    /**
     * GET /kb/settings
     * Get KB settings.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        $settings = [
            'kb_enabled'      => $this->is_kb_enabled(),
            'embedding_model' => get_option('vibe_ai_kb_embedding_model', 'text-embedding-3-small'),
            'chunk_size'      => (int) get_option('vibe_ai_kb_chunk_size', 500),
            'chunk_overlap'   => (int) get_option('vibe_ai_kb_chunk_overlap', 50),
            'post_types'      => get_option('vibe_ai_kb_post_types', Config::DEFAULT_POST_TYPES),
            'auto_index'      => (bool) get_option('vibe_ai_kb_auto_index', true),
        ];

        return rest_ensure_response($settings);
    }

    /**
     * POST /kb/settings
     * Update KB settings.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $updatedFields = [];

        // KB enabled
        if ($request->has_param('kb_enabled')) {
            $enabled = (bool) $request->get_param('kb_enabled');
            update_option('vibe_ai_kb_enabled', $enabled);
            $updatedFields[] = 'kb_enabled';
        }

        // Embedding model
        if ($request->has_param('embedding_model')) {
            $model = $request->get_param('embedding_model');
            update_option('vibe_ai_kb_embedding_model', $model);
            $updatedFields[] = 'embedding_model';
        }

        // Chunk size
        if ($request->has_param('chunk_size')) {
            $chunkSize = (int) $request->get_param('chunk_size');
            update_option('vibe_ai_kb_chunk_size', $chunkSize);
            $updatedFields[] = 'chunk_size';
        }

        // Chunk overlap
        if ($request->has_param('chunk_overlap')) {
            $chunkOverlap = (int) $request->get_param('chunk_overlap');
            update_option('vibe_ai_kb_chunk_overlap', $chunkOverlap);
            $updatedFields[] = 'chunk_overlap';
        }

        // Post types
        if ($request->has_param('post_types')) {
            $postTypes = $request->get_param('post_types');
            update_option('vibe_ai_kb_post_types', $postTypes);
            $updatedFields[] = 'post_types';
        }

        // Auto index
        if ($request->has_param('auto_index')) {
            $autoIndex = (bool) $request->get_param('auto_index');
            update_option('vibe_ai_kb_auto_index', $autoIndex);
            $updatedFields[] = 'auto_index';
        }

        if (empty($updatedFields)) {
            return new WP_Error(
                'rest_no_update_data',
                __('No valid settings provided for update.', 'ai-entity-index'),
                ['status' => 400]
            );
        }

        $this->logger->info('KB settings updated', ['fields' => $updatedFields]);

        // Return updated settings
        return $this->get_settings($request);
    }

    // =========================================================================
    // AI Publishing Endpoint Callbacks (Phase 2 scaffolds)
    // =========================================================================

    /**
     * GET /kb/llms-txt
     * Get llms.txt content for AI crawlers.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_llms_txt(WP_REST_Request $request): WP_REST_Response
    {
        $generator = $this->get_llms_txt_generator();

        $options = [
            'mode'                 => $request->get_param('mode') ?? 'curated',
            'include_descriptions' => (bool) $request->get_param('include_descriptions'),
            'max_entries'          => (int) $request->get_param('max_entries'),
        ];

        $content = $generator->generate($options);

        // Return as plain text
        $response = new WP_REST_Response($content);
        $response->set_status(200);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('X-Robots-Tag', 'noindex, follow');
        $response->header('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    /**
     * GET /kb/sitemap
     * Get AI-specific sitemap.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_ai_sitemap(WP_REST_Request $request): WP_REST_Response
    {
        $generator = $this->get_sitemap_generator();
        $format = $request->get_param('format') ?? 'json';

        if ($format === 'xml') {
            $content = $generator->generateXML();

            $response = new WP_REST_Response($content);
            $response->set_status(200);
            $response->header('Content-Type', 'application/xml; charset=utf-8');
        } else {
            $content = $generator->generateJSON();

            $response = rest_ensure_response($content);
        }

        // Common headers
        $response->header('X-Robots-Tag', 'noindex, follow');
        $response->header('Cache-Control', 'public, max-age=3600');

        // ETag for conditional requests
        $etag = md5(is_array($content) ? wp_json_encode($content) : $content);
        $response->header('ETag', '"' . $etag . '"');

        return $response;
    }

    /**
     * GET /kb/feed
     * Get change feed for AI agents.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_change_feed(WP_REST_Request $request): WP_REST_Response
    {
        $generator = $this->get_feed_generator();

        // Handle conditional GET via If-None-Match header
        $ifNoneMatch = $request->get_header('If-None-Match');

        $options = [
            'since' => $request->get_param('since'),
            'limit' => (int) $request->get_param('limit'),
        ];

        // Filter empty options
        $options = array_filter($options, fn($v) => $v !== null && $v !== '');

        $result = $generator->getConditionalResponse($ifNoneMatch, $options);

        $response = rest_ensure_response($result['body']);
        $response->set_status($result['status_code']);

        foreach ($result['headers'] as $header => $value) {
            $response->header($header, $value);
        }

        return $response;
    }

    /**
     * GET /kb/config/pinned-pages
     * Get pinned pages configuration.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_pinned_pages(WP_REST_Request $request): WP_REST_Response
    {
        $docRepo = $this->get_document_repository();
        $pinnedIds = $docRepo->getPinnedPages();

        // Get full post data for pinned pages
        $pages = [];
        foreach ($pinnedIds as $postId) {
            $post = get_post($postId);

            if ($post && $post->post_status === 'publish') {
                $pages[] = [
                    'post_id' => $post->ID,
                    'title'   => $post->post_title,
                    'url'     => get_permalink($post->ID),
                    'type'    => $post->post_type,
                ];
            }
        }

        return rest_ensure_response([
            'pinned_pages' => $pages,
            'post_ids'     => $pinnedIds,
        ]);
    }

    /**
     * PUT /kb/config/pinned-pages
     * Set pinned pages configuration.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function set_pinned_pages(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postIds = $request->get_param('post_ids');
        $docRepo = $this->get_document_repository();

        // Validate all post IDs exist and are published
        foreach ($postIds as $postId) {
            $post = get_post($postId);

            if (!$post || $post->post_status !== 'publish') {
                return new WP_Error(
                    'rest_invalid_post',
                    sprintf(__('Post ID %d does not exist or is not published.', 'ai-entity-index'), $postId),
                    ['status' => 400]
                );
            }
        }

        $success = $docRepo->setPinnedPages($postIds);

        if (!$success) {
            return new WP_Error(
                'rest_update_failed',
                __('Failed to update pinned pages.', 'ai-entity-index'),
                ['status' => 500]
            );
        }

        $this->logger->info('Pinned pages updated', ['post_ids' => $postIds]);

        // Return updated configuration
        return $this->get_pinned_pages($request);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Check if Knowledge Base is enabled.
     *
     * @return bool True if enabled, false otherwise.
     */
    private function is_kb_enabled(): bool
    {
        return (bool) get_option('vibe_ai_kb_enabled', false);
    }

    /**
     * Get KB pipeline manager instance (lazy loading).
     *
     * @return KBPipelineManager The manager instance.
     */
    private function get_pipeline_manager(): KBPipelineManager
    {
        if ($this->pipelineManager === null) {
            $this->pipelineManager = KBPipelineManager::get_instance();
        }

        return $this->pipelineManager;
    }

    /**
     * Get document repository instance (lazy loading).
     *
     * @return DocumentRepository The repository instance.
     */
    private function get_document_repository(): DocumentRepository
    {
        if ($this->docRepo === null) {
            $this->docRepo = new DocumentRepository();
        }

        return $this->docRepo;
    }

    /**
     * Get chunk repository instance (lazy loading).
     *
     * @return ChunkRepository The repository instance.
     */
    private function get_chunk_repository(): ChunkRepository
    {
        if ($this->chunkRepo === null) {
            $this->chunkRepo = new ChunkRepository();
        }

        return $this->chunkRepo;
    }

    /**
     * Get vector repository instance (lazy loading).
     *
     * @return VectorRepository The repository instance.
     */
    private function get_vector_repository(): VectorRepository
    {
        if ($this->vectorRepo === null) {
            $this->vectorRepo = new VectorRepository();
        }

        return $this->vectorRepo;
    }

    /**
     * Get LlmsTxt generator instance (lazy loading).
     *
     * @return LlmsTxtGenerator The generator instance.
     */
    private function get_llms_txt_generator(): LlmsTxtGenerator
    {
        if ($this->llmsTxtGenerator === null) {
            $this->llmsTxtGenerator = new LlmsTxtGenerator();
        }

        return $this->llmsTxtGenerator;
    }

    /**
     * Get AI Sitemap generator instance (lazy loading).
     *
     * @return AISitemapGenerator The generator instance.
     */
    private function get_sitemap_generator(): AISitemapGenerator
    {
        if ($this->sitemapGenerator === null) {
            $this->sitemapGenerator = new AISitemapGenerator();
        }

        return $this->sitemapGenerator;
    }

    /**
     * Get Change Feed generator instance (lazy loading).
     *
     * @return ChangeFeedGenerator The generator instance.
     */
    private function get_feed_generator(): ChangeFeedGenerator
    {
        if ($this->feedGenerator === null) {
            $this->feedGenerator = new ChangeFeedGenerator();
        }

        return $this->feedGenerator;
    }

    /**
     * Format document object for response.
     *
     * @param object $document The document object.
     * @param bool   $detailed Whether to include detailed information.
     * @return array<string, mixed> Formatted document data.
     */
    private function format_document_response(object $document, bool $detailed = false): array
    {
        $data = [
            'id'              => (int) $document->id,
            'post_id'         => (int) $document->post_id,
            'post_type'       => $document->post_type,
            'title'           => $document->title,
            'url'             => $document->url,
            'status'          => $document->status,
            'chunk_count'     => (int) ($document->chunk_count ?? 0),
            'last_indexed_at' => $document->last_indexed_at,
            'created_at'      => $document->created_at,
        ];

        if ($detailed) {
            $data['content_hash'] = $document->content_hash;
            $data['updated_at'] = $document->updated_at;
        }

        return $data;
    }

    /**
     * Get HATEOAS links for status endpoint.
     *
     * @return array<string, array<array<string, string>>> Link definitions.
     */
    private function get_status_links(): array
    {
        $base = self::NAMESPACE . '/' . self::BASE;

        return [
            'self' => [
                ['href' => rest_url($base . '/status')],
            ],
            'documents' => [
                ['href' => rest_url($base . '/docs')],
            ],
            'search' => [
                ['href' => rest_url($base . '/search')],
            ],
            'reindex' => [
                ['href' => rest_url($base . '/reindex')],
            ],
            'settings' => [
                ['href' => rest_url($base . '/settings')],
            ],
        ];
    }

    /**
     * Get HATEOAS links for a single document.
     *
     * @param int $postId The post ID.
     * @return array<string, array<array<string, string>>> Link definitions.
     */
    private function get_document_links(int $postId): array
    {
        $base = self::NAMESPACE . '/' . self::BASE;

        return [
            'self' => [
                ['href' => rest_url($base . '/docs/' . $postId)],
            ],
            'collection' => [
                ['href' => rest_url($base . '/docs')],
            ],
            'reindex' => [
                ['href' => rest_url($base . '/docs/' . $postId . '/reindex')],
            ],
            'exclude' => [
                ['href' => rest_url($base . '/docs/' . $postId . '/exclude')],
            ],
            'include' => [
                ['href' => rest_url($base . '/docs/' . $postId . '/include')],
            ],
            'post' => [
                ['href' => get_permalink($postId)],
            ],
            'edit' => [
                ['href' => get_edit_post_link($postId, 'raw')],
            ],
        ];
    }

    /**
     * Get pagination links for documents collection response.
     *
     * @param array<string, mixed> $result The query result with pagination info.
     * @param array<string, mixed> $args   The original query arguments.
     * @return array<string, array<array<string, string>>> Link definitions.
     */
    private function get_documents_collection_links(array $result, array $args): array
    {
        $base_url = rest_url(self::NAMESPACE . '/' . self::BASE . '/docs');

        $links = [
            'self' => [
                ['href' => $this->build_collection_url($base_url, $args)],
            ],
        ];

        $currentPage = $args['page'];
        $totalPages = $result['pages'];

        // First page
        if ($currentPage > 1) {
            $links['first'] = [
                ['href' => $this->build_collection_url($base_url, array_merge($args, ['page' => 1]))],
            ];
        }

        // Previous page
        if ($currentPage > 1) {
            $links['prev'] = [
                ['href' => $this->build_collection_url($base_url, array_merge($args, ['page' => $currentPage - 1]))],
            ];
        }

        // Next page
        if ($currentPage < $totalPages) {
            $links['next'] = [
                ['href' => $this->build_collection_url($base_url, array_merge($args, ['page' => $currentPage + 1]))],
            ];
        }

        // Last page
        if ($currentPage < $totalPages) {
            $links['last'] = [
                ['href' => $this->build_collection_url($base_url, array_merge($args, ['page' => $totalPages]))],
            ];
        }

        return $links;
    }

    /**
     * Build URL with query parameters.
     *
     * @param string               $base_url Base URL.
     * @param array<string, mixed> $args     Query arguments.
     * @return string The full URL.
     */
    private function build_collection_url(string $base_url, array $args): string
    {
        // Filter out empty values
        $query_args = array_filter($args, function ($value) {
            return $value !== '' && $value !== null;
        });

        if (empty($query_args)) {
            return $base_url;
        }

        return add_query_arg($query_args, $base_url);
    }
}

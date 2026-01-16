<?php
/**
 * REST API Controller for AI Entity Index
 *
 * Provides a complete REST API layer for managing entities, aliases,
 * pipeline operations, and system status.
 *
 * @package Vibe\AIIndex\REST
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Vibe\AIIndex\REST;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Logger;
use Vibe\AIIndex\Repositories\EntityRepository;
use Vibe\AIIndex\Pipeline\PipelineManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST Controller for the AI Entity Index plugin.
 *
 * Registers all REST API endpoints under the vibe-ai/v1 namespace
 * and handles request processing, validation, and response formatting.
 */
class RestController
{
    /**
     * REST API namespace.
     *
     * @var string
     */
    private string $namespace;

    /**
     * Logger instance.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Entity repository instance.
     *
     * @var EntityRepository|null
     */
    private ?EntityRepository $entityRepository = null;

    /**
     * Pipeline manager instance.
     *
     * @var PipelineManager|null
     */
    private ?PipelineManager $pipelineManager = null;

    /**
     * Initialize the REST controller.
     */
    public function __construct()
    {
        $this->namespace = Config::REST_NAMESPACE;
        $this->logger = new Logger();
    }

    /**
     * Register REST API routes.
     *
     * Called via rest_api_init action hook.
     *
     * @return void
     */
    public function register_routes(): void
    {
        // =================================================================
        // Status Endpoint
        // =================================================================

        register_rest_route($this->namespace, '/status', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_status'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        // =================================================================
        // Entities Collection Endpoints
        // =================================================================

        register_rest_route($this->namespace, '/entities', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_entities'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => $this->get_entities_collection_params(),
            ],
        ]);

        // =================================================================
        // Single Entity Endpoints
        // =================================================================

        register_rest_route($this->namespace, '/entities/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_entity'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'id' => [
                        'description'       => __('Unique identifier for the entity.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_entity'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => $this->get_entity_update_params(),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_entity'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'id' => [
                        'description'       => __('Unique identifier for the entity.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                    'force' => [
                        'description' => __('Whether to permanently delete the entity (bypass trash).', 'ai-entity-index'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Entity Merge Endpoint
        // =================================================================

        register_rest_route($this->namespace, '/entities/merge', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'merge_entities'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'target_id' => [
                        'description'       => __('The canonical entity ID to merge into.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                    'source_ids' => [
                        'description'       => __('Array of entity IDs to merge from.', 'ai-entity-index'),
                        'type'              => 'array',
                        'required'          => true,
                        'items'             => [
                            'type' => 'integer',
                        ],
                        'validate_callback' => [$this, 'validate_source_ids'],
                        'sanitize_callback' => [$this, 'sanitize_integer_array'],
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Entity Aliases Endpoints
        // =================================================================

        register_rest_route($this->namespace, '/entities/(?P<id>[\d]+)/aliases', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'add_alias'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'id' => [
                        'description'       => __('Unique identifier for the entity.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                    'alias' => [
                        'description'       => __('The alias name to add.', 'ai-entity-index'),
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => [$this, 'validate_non_empty_string'],
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/entities/(?P<id>[\d]+)/aliases/(?P<alias_id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_alias'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'id' => [
                        'description'       => __('Unique identifier for the entity.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                    'alias_id' => [
                        'description'       => __('Unique identifier for the alias.', 'ai-entity-index'),
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => [$this, 'validate_positive_integer'],
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // =================================================================
        // Pipeline Control Endpoints
        // =================================================================

        register_rest_route($this->namespace, '/pipeline/start', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'start_pipeline'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'post_types' => [
                        'description'       => __('Post types to process.', 'ai-entity-index'),
                        'type'              => 'array',
                        'items'             => [
                            'type' => 'string',
                        ],
                        'default'           => Config::DEFAULT_POST_TYPES,
                        'sanitize_callback' => [$this, 'sanitize_string_array'],
                    ],
                    'force_reprocess' => [
                        'description' => __('Whether to reprocess already extracted posts.', 'ai-entity-index'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/pipeline/stop', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'stop_pipeline'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        // =================================================================
        // Logs Endpoint
        // =================================================================

        register_rest_route($this->namespace, '/logs', [
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
                    'date' => [
                        'description'       => __('Date for log file (YYYY-MM-DD format).', 'ai-entity-index'),
                        'type'              => 'string',
                        'format'            => 'date',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => [$this, 'validate_date_format'],
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
     * Validate a non-empty string.
     *
     * @param mixed           $value   The value to validate.
     * @param WP_REST_Request $request The request object.
     * @param string          $param   The parameter name.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_non_empty_string(mixed $value, WP_REST_Request $request, string $param): bool|WP_Error
    {
        if (!is_string($value) || trim($value) === '') {
            return new WP_Error(
                'rest_invalid_param',
                sprintf(__('%s must be a non-empty string.', 'ai-entity-index'), $param),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Validate source_ids array for merge operation.
     *
     * @param mixed           $value   The value to validate.
     * @param WP_REST_Request $request The request object.
     * @param string          $param   The parameter name.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_source_ids(mixed $value, WP_REST_Request $request, string $param): bool|WP_Error
    {
        if (!is_array($value) || empty($value)) {
            return new WP_Error(
                'rest_invalid_param',
                __('source_ids must be a non-empty array of entity IDs.', 'ai-entity-index'),
                ['status' => 400]
            );
        }

        foreach ($value as $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                return new WP_Error(
                    'rest_invalid_param',
                    __('All source_ids must be positive integers.', 'ai-entity-index'),
                    ['status' => 400]
                );
            }
        }

        return true;
    }

    /**
     * Validate date format (YYYY-MM-DD).
     *
     * @param mixed           $value   The value to validate.
     * @param WP_REST_Request $request The request object.
     * @param string          $param   The parameter name.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_date_format(mixed $value, WP_REST_Request $request, string $param): bool|WP_Error
    {
        if ($value === null || $value === '') {
            return true; // Optional parameter
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return new WP_Error(
                'rest_invalid_param',
                sprintf(__('%s must be in YYYY-MM-DD format.', 'ai-entity-index'), $param),
                ['status' => 400]
            );
        }

        // Validate it's a real date
        $parts = explode('-', $value);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return new WP_Error(
                'rest_invalid_param',
                sprintf(__('%s must be a valid date.', 'ai-entity-index'), $param),
                ['status' => 400]
            );
        }

        return true;
    }

    // =========================================================================
    // Sanitization Callbacks
    // =========================================================================

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

    // =========================================================================
    // Endpoint Parameter Definitions
    // =========================================================================

    /**
     * Get parameters for entities collection endpoint.
     *
     * @return array<string, array<string, mixed>> Parameter definitions.
     */
    private function get_entities_collection_params(): array
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
                'description'       => __('Maximum number of items to be returned per page.', 'ai-entity-index'),
                'type'              => 'integer',
                'default'           => 20,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'description'       => __('Limit results to those matching a search string.', 'ai-entity-index'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'type' => [
                'description'       => __('Limit results to entities of a specific type.', 'ai-entity-index'),
                'type'              => 'string',
                'enum'              => array_merge([''], Config::VALID_TYPES),
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'description'       => __('Limit results to entities with a specific status.', 'ai-entity-index'),
                'type'              => 'string',
                'enum'              => array_merge([''], Config::VALID_STATUSES),
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'description'       => __('Sort collection by attribute.', 'ai-entity-index'),
                'type'              => 'string',
                'default'           => 'created_at',
                'enum'              => ['id', 'name', 'type', 'status', 'mention_count', 'created_at', 'updated_at'],
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
     * Get parameters for entity update endpoint.
     *
     * @return array<string, array<string, mixed>> Parameter definitions.
     */
    private function get_entity_update_params(): array
    {
        return [
            'id' => [
                'description'       => __('Unique identifier for the entity.', 'ai-entity-index'),
                'type'              => 'integer',
                'required'          => true,
                'validate_callback' => [$this, 'validate_positive_integer'],
                'sanitize_callback' => 'absint',
            ],
            'name' => [
                'description'       => __('The entity name.', 'ai-entity-index'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'type' => [
                'description'       => __('The entity type.', 'ai-entity-index'),
                'type'              => 'string',
                'enum'              => Config::VALID_TYPES,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'schema_type' => [
                'description'       => __('The Schema.org type for the entity.', 'ai-entity-index'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'description'       => __('The entity status.', 'ai-entity-index'),
                'type'              => 'string',
                'enum'              => Config::VALID_STATUSES,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'description' => [
                'description'       => __('A brief description of the entity.', 'ai-entity-index'),
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
            ],
            'same_as_url' => [
                'description'       => __('A URL identifying the entity (e.g., Wikipedia page).', 'ai-entity-index'),
                'type'              => 'string',
                'format'            => 'uri',
                'sanitize_callback' => 'esc_url_raw',
            ],
            'wikidata_id' => [
                'description'       => __('Wikidata identifier (e.g., Q12345).', 'ai-entity-index'),
                'type'              => 'string',
                'pattern'           => '^Q[0-9]+$',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    // =========================================================================
    // Endpoint Callbacks
    // =========================================================================

    /**
     * Get pipeline status and progress.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_status(WP_REST_Request $request): WP_REST_Response
    {
        $pipeline = $this->get_pipeline_manager();
        $status = $pipeline->get_status();
        $repository = $this->get_entity_repository();
        $stats = $repository->get_stats();

        $response_data = [
            'status'               => $status['status'],
            'current_phase'        => $status['current_phase'] ?: null,
            'progress'             => [
                'total'      => $status['progress']['total'],
                'completed'  => $status['progress']['completed'],
                'failed'     => $status['progress']['failed'],
                'percentage' => $status['progress']['percentage'],
            ],
            'stats'                => [
                'total_entities'  => $stats['total_entities'],
                'total_mentions'  => $stats['total_mentions'],
                'avg_confidence'  => $stats['avg_confidence'],
            ],
            'last_activity'        => $status['last_activity'] ?: null,
            'propagating_entities' => $status['propagating_entities'] ?? [],
        ];

        $response = rest_ensure_response($response_data);

        // Add HATEOAS links
        $response->add_links($this->get_status_links());

        return $response;
    }

    /**
     * Get paginated list of entities.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_entities(WP_REST_Request $request): WP_REST_Response
    {
        $repository = $this->get_entity_repository();

        $args = [
            'page'     => $request->get_param('page') ?? 1,
            'per_page' => $request->get_param('per_page') ?? 20,
            'search'   => $request->get_param('search') ?? '',
            'type'     => $request->get_param('type') ?? '',
            'status'   => $request->get_param('status') ?? '',
            'orderby'  => $request->get_param('orderby') ?? 'created_at',
            'order'    => $request->get_param('order') ?? 'DESC',
        ];

        $result = $repository->get_entities($args);

        // Format entities with links
        $entities = array_map(function ($entity) {
            return $this->format_entity_response($entity);
        }, $result['items']);

        $response_data = [
            'entities' => $entities,
            'total'    => $result['total'],
            'pages'    => $result['pages'],
        ];

        $response = rest_ensure_response($response_data);

        // Add pagination headers
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['pages']);

        // Add pagination links
        $response->add_links($this->get_collection_links($result, $args));

        return $response;
    }

    /**
     * Get a single entity by ID.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function get_entity(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $repository = $this->get_entity_repository();

        $entity = $repository->get_entity($id);

        if ($entity === null) {
            return new WP_Error(
                'rest_entity_not_found',
                __('Entity not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        // Get additional data
        $aliases = $repository->get_aliases_for_entity($id);
        $mentions = $repository->get_mentions_for_entity($id);

        // Format related posts from mentions
        $related_posts = array_map(function ($mention) {
            return [
                'post_id'         => (int) $mention->post_id,
                'post_title'      => $mention->post_title ?? '',
                'post_status'     => $mention->post_status ?? '',
                'confidence'      => (float) $mention->confidence,
                'context_snippet' => $mention->context_snippet ?? '',
                'is_primary'      => (bool) $mention->is_primary,
                '_links'          => [
                    'post' => [
                        'href' => get_permalink((int) $mention->post_id),
                    ],
                    'edit' => [
                        'href' => get_edit_post_link((int) $mention->post_id, 'raw'),
                    ],
                ],
            ];
        }, $mentions);

        $response_data = $this->format_entity_response($entity, true);
        $response_data['aliases'] = array_map(function ($alias) {
            return [
                'id'         => (int) $alias->id,
                'alias'      => $alias->alias,
                'alias_slug' => $alias->alias_slug,
                'source'     => $alias->source,
                'created_at' => $alias->created_at,
            ];
        }, $aliases);
        $response_data['mentions'] = $related_posts;

        $response = rest_ensure_response($response_data);

        // Add HATEOAS links
        $response->add_links($this->get_entity_links($id));

        return $response;
    }

    /**
     * Update an entity.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function update_entity(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $repository = $this->get_entity_repository();

        // Check entity exists
        $entity = $repository->get_entity($id);
        if ($entity === null) {
            return new WP_Error(
                'rest_entity_not_found',
                __('Entity not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        // Build update data from request
        $update_fields = ['name', 'type', 'schema_type', 'status', 'description', 'same_as_url', 'wikidata_id'];
        $update_data = [];
        $schema_affecting_fields = ['name', 'schema_type', 'same_as_url', 'wikidata_id'];
        $changed_schema_fields = [];

        foreach ($update_fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $update_data[$field] = $value;

                // Track schema-affecting changes
                if (in_array($field, $schema_affecting_fields, true)) {
                    $old_value = $entity->$field ?? null;
                    if ($old_value !== $value) {
                        $changed_schema_fields[] = $field;
                    }
                }
            }
        }

        if (empty($update_data)) {
            return new WP_Error(
                'rest_no_update_data',
                __('No valid fields provided for update.', 'ai-entity-index'),
                ['status' => 400]
            );
        }

        // Validate wikidata_id format if provided
        if (isset($update_data['wikidata_id']) && !empty($update_data['wikidata_id'])) {
            if (!preg_match('/^Q[0-9]+$/', $update_data['wikidata_id'])) {
                return new WP_Error(
                    'rest_invalid_wikidata_id',
                    __('Wikidata ID must be in format Q followed by digits (e.g., Q12345).', 'ai-entity-index'),
                    ['status' => 400]
                );
            }
        }

        // Perform update
        $success = $repository->update_entity($id, $update_data);

        if (!$success) {
            $this->logger->error('Failed to update entity', ['entity_id' => $id, 'data' => $update_data]);
            return new WP_Error(
                'rest_update_failed',
                __('Failed to update entity.', 'ai-entity-index'),
                ['status' => 500]
            );
        }

        $this->logger->info('Entity updated', ['entity_id' => $id, 'fields' => array_keys($update_data)]);

        // Trigger propagation if schema-affecting fields changed
        if (!empty($changed_schema_fields)) {
            /**
             * Fires when an entity is updated with schema-affecting changes.
             *
             * @param int   $id              The entity ID.
             * @param array $changed_fields  Array of changed field names.
             */
            do_action('vibe_ai_entity_updated', $id, array_fill_keys($changed_schema_fields, true));

            $this->logger->info('Entity propagation triggered', [
                'entity_id'      => $id,
                'changed_fields' => $changed_schema_fields,
            ]);
        }

        // Fetch updated entity
        $updated_entity = $repository->get_entity($id);
        $response_data = $this->format_entity_response($updated_entity);

        $response = rest_ensure_response($response_data);
        $response->add_links($this->get_entity_links($id));

        return $response;
    }

    /**
     * Delete an entity.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function delete_entity(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $force = (bool) $request->get_param('force');
        $repository = $this->get_entity_repository();

        // Check entity exists
        $entity = $repository->get_entity($id);
        if ($entity === null) {
            return new WP_Error(
                'rest_entity_not_found',
                __('Entity not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        if ($force) {
            // Hard delete
            $success = $repository->delete_entity($id);

            if (!$success) {
                $this->logger->error('Failed to delete entity', ['entity_id' => $id]);
                return new WP_Error(
                    'rest_delete_failed',
                    __('Failed to delete entity.', 'ai-entity-index'),
                    ['status' => 500]
                );
            }

            $this->logger->info('Entity permanently deleted', ['entity_id' => $id, 'name' => $entity->name]);

            return rest_ensure_response([
                'deleted'  => true,
                'previous' => $this->format_entity_response($entity),
            ]);
        } else {
            // Soft delete (move to trash)
            $success = $repository->update_entity($id, ['status' => 'trash']);

            if (!$success) {
                $this->logger->error('Failed to trash entity', ['entity_id' => $id]);
                return new WP_Error(
                    'rest_trash_failed',
                    __('Failed to move entity to trash.', 'ai-entity-index'),
                    ['status' => 500]
                );
            }

            $this->logger->info('Entity moved to trash', ['entity_id' => $id, 'name' => $entity->name]);

            // Return updated entity
            $updated_entity = $repository->get_entity($id);
            $response = rest_ensure_response($this->format_entity_response($updated_entity));
            $response->add_links($this->get_entity_links($id));

            return $response;
        }
    }

    /**
     * Merge multiple entities into a target entity.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function merge_entities(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $target_id = (int) $request->get_param('target_id');
        $source_ids = $request->get_param('source_ids');
        $repository = $this->get_entity_repository();

        // Validate target exists
        $target = $repository->get_entity($target_id);
        if ($target === null) {
            return new WP_Error(
                'rest_entity_not_found',
                __('Target entity not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        // Filter out target from source_ids if present
        $source_ids = array_filter($source_ids, function ($id) use ($target_id) {
            return (int) $id !== $target_id;
        });

        if (empty($source_ids)) {
            return new WP_Error(
                'rest_invalid_merge',
                __('No valid source entities to merge.', 'ai-entity-index'),
                ['status' => 400]
            );
        }

        // Validate all source entities exist
        foreach ($source_ids as $source_id) {
            $source = $repository->get_entity((int) $source_id);
            if ($source === null) {
                return new WP_Error(
                    'rest_entity_not_found',
                    sprintf(__('Source entity %d not found.', 'ai-entity-index'), $source_id),
                    ['status' => 404]
                );
            }
        }

        // Perform merge
        $affected_posts = $repository->merge_entities($target_id, $source_ids);

        $this->logger->info('Entities merged', [
            'target_id'      => $target_id,
            'source_ids'     => $source_ids,
            'affected_posts' => count($affected_posts),
        ]);

        // Trigger propagation for affected posts
        if (!empty($affected_posts)) {
            /**
             * Fires when an entity merge affects posts.
             *
             * @param int   $target_id      The target entity ID.
             * @param array $affected_posts Array of affected post IDs.
             */
            do_action('vibe_ai_entity_merged', $target_id, $affected_posts);
        }

        // Get updated target entity
        $updated_target = $repository->get_entity($target_id);

        $response_data = [
            'success'        => true,
            'entity'         => $this->format_entity_response($updated_target),
            'merged_count'   => count($source_ids),
            'affected_posts' => count($affected_posts),
        ];

        $response = rest_ensure_response($response_data);
        $response->add_links($this->get_entity_links($target_id));

        return $response;
    }

    /**
     * Add an alias to an entity.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function add_alias(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $entity_id = (int) $request->get_param('id');
        $alias = $request->get_param('alias');
        $repository = $this->get_entity_repository();

        // Check entity exists
        $entity = $repository->get_entity($entity_id);
        if ($entity === null) {
            return new WP_Error(
                'rest_entity_not_found',
                __('Entity not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        // Check alias count limit
        $existing_aliases = $repository->get_aliases_for_entity($entity_id);
        if (count($existing_aliases) >= Config::MAX_ALIASES_PER_ENTITY) {
            return new WP_Error(
                'rest_alias_limit_reached',
                sprintf(
                    __('Maximum number of aliases (%d) reached for this entity.', 'ai-entity-index'),
                    Config::MAX_ALIASES_PER_ENTITY
                ),
                ['status' => 400]
            );
        }

        // Check for duplicate alias
        $alias_slug = sanitize_title($alias);
        foreach ($existing_aliases as $existing) {
            if ($existing->alias_slug === $alias_slug) {
                return new WP_Error(
                    'rest_alias_exists',
                    __('This alias already exists for this entity.', 'ai-entity-index'),
                    ['status' => 409]
                );
            }
        }

        // Register alias
        $repository->register_alias($entity_id, $alias);

        $this->logger->info('Alias added to entity', ['entity_id' => $entity_id, 'alias' => $alias]);

        // Get updated aliases
        $updated_aliases = $repository->get_aliases_for_entity($entity_id);

        // Find the newly added alias
        $new_alias = null;
        foreach ($updated_aliases as $a) {
            if ($a->alias_slug === $alias_slug) {
                $new_alias = $a;
                break;
            }
        }

        $response_data = [
            'success' => true,
            'alias'   => $new_alias ? [
                'id'         => (int) $new_alias->id,
                'alias'      => $new_alias->alias,
                'alias_slug' => $new_alias->alias_slug,
                'source'     => $new_alias->source,
                'created_at' => $new_alias->created_at,
            ] : null,
        ];

        $response = rest_ensure_response($response_data);
        $response->set_status(201);

        return $response;
    }

    /**
     * Delete an alias from an entity.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function delete_alias(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $entity_id = (int) $request->get_param('id');
        $alias_id = (int) $request->get_param('alias_id');
        $repository = $this->get_entity_repository();

        // Check entity exists
        $entity = $repository->get_entity($entity_id);
        if ($entity === null) {
            return new WP_Error(
                'rest_entity_not_found',
                __('Entity not found.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        // Verify alias belongs to this entity
        $aliases = $repository->get_aliases_for_entity($entity_id);
        $alias_found = false;
        foreach ($aliases as $alias) {
            if ((int) $alias->id === $alias_id) {
                $alias_found = true;
                break;
            }
        }

        if (!$alias_found) {
            return new WP_Error(
                'rest_alias_not_found',
                __('Alias not found for this entity.', 'ai-entity-index'),
                ['status' => 404]
            );
        }

        // Delete alias
        $success = $repository->delete_alias($alias_id);

        if (!$success) {
            $this->logger->error('Failed to delete alias', ['entity_id' => $entity_id, 'alias_id' => $alias_id]);
            return new WP_Error(
                'rest_delete_failed',
                __('Failed to delete alias.', 'ai-entity-index'),
                ['status' => 500]
            );
        }

        $this->logger->info('Alias deleted from entity', ['entity_id' => $entity_id, 'alias_id' => $alias_id]);

        return rest_ensure_response([
            'deleted' => true,
        ]);
    }

    /**
     * Start the extraction pipeline.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object or error.
     */
    public function start_pipeline(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pipeline = $this->get_pipeline_manager();

        // Check if already running
        if ($pipeline->is_running()) {
            return new WP_Error(
                'rest_pipeline_running',
                __('Pipeline is already running. Stop it first before starting a new run.', 'ai-entity-index'),
                ['status' => 409]
            );
        }

        $options = [
            'post_types'      => $request->get_param('post_types') ?? Config::DEFAULT_POST_TYPES,
            'force_reprocess' => (bool) $request->get_param('force_reprocess'),
        ];

        try {
            $pipeline->start($options);

            $this->logger->info('Pipeline started via REST API', [
                'options'    => $options,
                'user_id'    => get_current_user_id(),
            ]);

            $status = $pipeline->get_status();

            $response = rest_ensure_response([
                'success' => true,
                'message' => __('Pipeline started successfully.', 'ai-entity-index'),
                'status'  => $status['status'],
                'phase'   => $status['current_phase'],
            ]);

            $response->add_links($this->get_status_links());

            return $response;
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to start pipeline', ['error' => $e->getMessage()]);
            return new WP_Error(
                'rest_pipeline_start_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Stop the extraction pipeline.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function stop_pipeline(WP_REST_Request $request): WP_REST_Response
    {
        $pipeline = $this->get_pipeline_manager();

        $was_running = $pipeline->is_running();
        $pipeline->stop();

        $this->logger->info('Pipeline stopped via REST API', [
            'was_running' => $was_running,
            'user_id'     => get_current_user_id(),
        ]);

        $status = $pipeline->get_status();

        $response = rest_ensure_response([
            'success' => true,
            'message' => $was_running
                ? __('Pipeline stopped successfully.', 'ai-entity-index')
                : __('Pipeline was not running.', 'ai-entity-index'),
            'status'  => $status['status'],
        ]);

        $response->add_links($this->get_status_links());

        return $response;
    }

    /**
     * Get recent log entries.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function get_logs(WP_REST_Request $request): WP_REST_Response
    {
        $level = $request->get_param('level') ?? 'info';
        $limit = $request->get_param('limit') ?? 50;
        $date = $request->get_param('date');

        // If specific date requested, we need to handle it
        if ($date) {
            $logs = $this->get_logs_for_date($date, $level, $limit);
        } else {
            $logs = $this->logger->getRecentLogs($limit, $level);
        }

        // Get available log files for reference
        $log_files = $this->logger->getLogFiles();

        $response_data = [
            'entries'    => $logs,
            'count'      => count($logs),
            'level'      => $level,
            'date'       => $date ?: gmdate('Y-m-d'),
            'log_files'  => array_map(function ($file) {
                return [
                    'date' => $file['date'],
                    'size' => $file['size'],
                ];
            }, array_slice($log_files, 0, 30)), // Last 30 days
        ];

        return rest_ensure_response($response_data);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get entity repository instance (lazy loading).
     *
     * @return EntityRepository The repository instance.
     */
    private function get_entity_repository(): EntityRepository
    {
        if ($this->entityRepository === null) {
            $this->entityRepository = new EntityRepository();
        }

        return $this->entityRepository;
    }

    /**
     * Get pipeline manager instance (lazy loading).
     *
     * @return PipelineManager The manager instance.
     */
    private function get_pipeline_manager(): PipelineManager
    {
        if ($this->pipelineManager === null) {
            $this->pipelineManager = PipelineManager::get_instance();
        }

        return $this->pipelineManager;
    }

    /**
     * Format entity object for response.
     *
     * @param object $entity   The entity object.
     * @param bool   $detailed Whether to include detailed information.
     * @return array<string, mixed> Formatted entity data.
     */
    private function format_entity_response(object $entity, bool $detailed = false): array
    {
        $data = [
            'id'            => (int) $entity->id,
            'name'          => $entity->name,
            'slug'          => $entity->slug,
            'type'          => $entity->type,
            'schema_type'   => $entity->schema_type ?? Config::getSchemaType($entity->type),
            'status'        => $entity->status,
            'mention_count' => (int) ($entity->mention_count ?? 0),
            'created_at'    => $entity->created_at,
            'updated_at'    => $entity->updated_at,
        ];

        if ($detailed) {
            $data['description'] = $entity->description ?? '';
            $data['same_as_url'] = $entity->same_as_url ?? '';
            $data['wikidata_id'] = $entity->wikidata_id ?? '';
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
        return [
            'self' => [
                [
                    'href' => rest_url($this->namespace . '/status'),
                ],
            ],
            'entities' => [
                [
                    'href' => rest_url($this->namespace . '/entities'),
                ],
            ],
            'pipeline_start' => [
                [
                    'href' => rest_url($this->namespace . '/pipeline/start'),
                ],
            ],
            'pipeline_stop' => [
                [
                    'href' => rest_url($this->namespace . '/pipeline/stop'),
                ],
            ],
            'logs' => [
                [
                    'href' => rest_url($this->namespace . '/logs'),
                ],
            ],
        ];
    }

    /**
     * Get HATEOAS links for a single entity.
     *
     * @param int $entity_id The entity ID.
     * @return array<string, array<array<string, string>>> Link definitions.
     */
    private function get_entity_links(int $entity_id): array
    {
        return [
            'self' => [
                [
                    'href' => rest_url($this->namespace . '/entities/' . $entity_id),
                ],
            ],
            'collection' => [
                [
                    'href' => rest_url($this->namespace . '/entities'),
                ],
            ],
            'aliases' => [
                [
                    'href' => rest_url($this->namespace . '/entities/' . $entity_id . '/aliases'),
                ],
            ],
        ];
    }

    /**
     * Get pagination links for collection response.
     *
     * @param array<string, mixed> $result The query result with pagination info.
     * @param array<string, mixed> $args   The original query arguments.
     * @return array<string, array<array<string, string>>> Link definitions.
     */
    private function get_collection_links(array $result, array $args): array
    {
        $links = [
            'self' => [
                [
                    'href' => $this->build_collection_url($args),
                ],
            ],
        ];

        // First page
        if ($result['page'] > 1) {
            $links['first'] = [
                [
                    'href' => $this->build_collection_url(array_merge($args, ['page' => 1])),
                ],
            ];
        }

        // Previous page
        if ($result['page'] > 1) {
            $links['prev'] = [
                [
                    'href' => $this->build_collection_url(array_merge($args, ['page' => $result['page'] - 1])),
                ],
            ];
        }

        // Next page
        if ($result['page'] < $result['pages']) {
            $links['next'] = [
                [
                    'href' => $this->build_collection_url(array_merge($args, ['page' => $result['page'] + 1])),
                ],
            ];
        }

        // Last page
        if ($result['page'] < $result['pages']) {
            $links['last'] = [
                [
                    'href' => $this->build_collection_url(array_merge($args, ['page' => $result['pages']])),
                ],
            ];
        }

        return $links;
    }

    /**
     * Build URL for entities collection with query parameters.
     *
     * @param array<string, mixed> $args Query arguments.
     * @return string The full URL.
     */
    private function build_collection_url(array $args): string
    {
        $base_url = rest_url($this->namespace . '/entities');

        // Filter out empty values
        $query_args = array_filter($args, function ($value) {
            return $value !== '' && $value !== null;
        });

        if (empty($query_args)) {
            return $base_url;
        }

        return add_query_arg($query_args, $base_url);
    }

    /**
     * Get logs for a specific date.
     *
     * @param string $date  The date in YYYY-MM-DD format.
     * @param string $level Minimum log level.
     * @param int    $limit Maximum entries to return.
     * @return array<array<string, mixed>> Log entries.
     */
    private function get_logs_for_date(string $date, string $level, int $limit): array
    {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/' . Config::LOG_DIRECTORY;
        $log_file = $log_dir . '/' . $date . '.log';

        if (!file_exists($log_file)) {
            return [];
        }

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $lines = array_reverse($lines);
        $entries = [];
        $level_priorities = [
            'debug'   => 0,
            'info'    => 1,
            'warning' => 2,
            'error'   => 3,
        ];
        $min_priority = $level_priorities[strtolower($level)] ?? 0;

        foreach ($lines as $line) {
            if (count($entries) >= $limit) {
                break;
            }

            $entry = $this->parse_log_line($line);

            if ($entry === null) {
                continue;
            }

            $entry_priority = $level_priorities[strtolower($entry['level'])] ?? 0;

            if ($entry_priority >= $min_priority) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Parse a log line into structured data.
     *
     * @param string $line The log line.
     * @return array<string, mixed>|null Parsed entry or null.
     */
    private function parse_log_line(string $line): ?array
    {
        // Pattern: [HH:MM:SS] [LEVEL] Message {json}
        $pattern = '/^\[(\d{2}:\d{2}:\d{2})\]\s+\[([A-Z]+)\]\s+(.+)$/';

        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        $message = $matches[3];
        $context = [];

        // Check for JSON context at end of message
        if (preg_match('/^(.+?)\s+(\{.+\})$/', $message, $msg_matches)) {
            $message = $msg_matches[1];
            $decoded = json_decode($msg_matches[2], true);

            if (is_array($decoded)) {
                $context = $decoded;
            }
        }

        return [
            'timestamp' => $matches[1],
            'level'     => $matches[2],
            'message'   => $message,
            'context'   => $context,
        ];
    }
}

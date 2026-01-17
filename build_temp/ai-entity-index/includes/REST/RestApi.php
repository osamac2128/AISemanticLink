<?php

declare(strict_types=1);

namespace Vibe\AIIndex\REST;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Logger;
use Vibe\AIIndex\Pipeline\PipelineManager;

/**
 * Handle REST API registration and callbacks.
 */
class RestApi
{
    /** @var Logger */
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register REST API routes.
     */
    public function registerRoutes(): void
    {
        // Status endpoint
        register_rest_route(Config::REST_NAMESPACE, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Entities endpoints
        register_rest_route(Config::REST_NAMESPACE, '/entities', [
            'methods' => 'GET',
            'callback' => [$this, 'getEntities'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(Config::REST_NAMESPACE, '/entities/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getEntity'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateEntity'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route(Config::REST_NAMESPACE, '/entities/merge', [
            'methods' => 'POST',
            'callback' => [$this, 'mergeEntities'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Pipeline endpoints
        register_rest_route(Config::REST_NAMESPACE, '/pipeline/start', [
            'methods' => 'POST',
            'callback' => [$this, 'startPipeline'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(Config::REST_NAMESPACE, '/pipeline/stop', [
            'methods' => 'POST',
            'callback' => [$this, 'stopPipeline'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    /**
     * Check if current user has admin permissions.
     */
    public function checkPermission(): bool
    {
        return current_user_can(Config::REQUIRED_CAPABILITY);
    }

    /**
     * REST callback: Get pipeline status.
     */
    public function getStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        // Use PipelineManager for real status
        $manager = PipelineManager::get_instance();
        $status_data = $manager->get_status();

        return new \WP_REST_Response([
            'status' => $status_data['status'],
            'current_phase' => $status_data['current_phase'],
            'progress' => $status_data['progress'],
            'stats' => $status_data['stats'],
            'last_activity' => $status_data['last_activity'],
            'propagating_entities' => $status_data['propagating_entities'],
        ]);
    }

    /**
     * REST callback: Get entities list.
     */
    public function getEntities(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new \Vibe\AIIndex\Repositories\EntityRepository();
        $params = $request->get_params();

        $result = $repo->get_entities([
            'page' => $params['page'] ?? 1,
            'per_page' => $params['per_page'] ?? 20,
            'search' => $params['search'] ?? '',
            'type' => $params['type'] ?? '',
            'status' => $params['status'] ?? '',
        ]);

        return new \WP_REST_Response([
            'entities' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    /**
     * REST callback: Get single entity.
     */
    public function getEntity(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $repo = new \Vibe\AIIndex\Repositories\EntityRepository();
        $entity = $repo->get_entity($id);

        if (!$entity) {
            return new \WP_REST_Response(['entity' => null], 404);
        }

        // Get extras
        $aliases = $repo->get_aliases_for_entity($id);
        $mentions = $repo->get_mentions_for_entity($id);

        // Cast object to array to append extras
        $data = (array) $entity;
        $data['aliases'] = $aliases;
        $data['mentions'] = $mentions;

        return new \WP_REST_Response(['entity' => $data]);
    }

    /**
     * REST callback: Update entity.
     */
    public function updateEntity(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $data = $request->get_json_params();

        $repo = new \Vibe\AIIndex\Repositories\EntityRepository();
        $success = $repo->update_entity($id, $data);

        return new \WP_REST_Response(['success' => $success], $success ? 200 : 400);
    }

    /**
     * REST callback: Merge entities.
     */
    public function mergeEntities(\WP_REST_Request $request): \WP_REST_Response
    {
        $target_id = (int) $request->get_param('target_id');
        $source_ids = $request->get_param('source_ids');

        if (!$target_id || empty($source_ids) || !is_array($source_ids)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid parameters'], 400);
        }

        $repo = new \Vibe\AIIndex\Repositories\EntityRepository();
        $affected_posts = $repo->merge_entities($target_id, $source_ids);

        // Regenerate schema for affected posts
        foreach ($affected_posts as $post_id) {
            // In a real scenario we'd schedule this async, but for now:
            do_action('vibe_ai_generate_schema', $post_id);
        }

        return new \WP_REST_Response(['success' => true, 'affected_posts' => count($affected_posts)]);
    }

    /**
     * REST callback: Start pipeline.
     */
    public function startPipeline(\WP_REST_Request $request): \WP_REST_Response
    {
        $options = $request->get_json_params() ?: [];
        $manager = PipelineManager::get_instance();

        try {
            $manager->start($options);
            $this->logger->info('Pipeline started via REST API');
            return new \WP_REST_Response(['success' => true, 'message' => 'Pipeline started']);
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 409);
        }
    }

    /**
     * REST callback: Stop pipeline.
     */
    public function stopPipeline(\WP_REST_Request $request): \WP_REST_Response
    {
        $manager = PipelineManager::get_instance();
        $manager->stop();
        $this->logger->info('Pipeline stopped via REST API');
        return new \WP_REST_Response(['success' => true, 'message' => 'Pipeline stopped']);
    }
}

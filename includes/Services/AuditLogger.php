<?php
/**
 * Audit logging service for entity mutations
 *
 * Tracks all entity CRUD operations with before/after snapshots,
 * user IDs, and timestamps.
 *
 * @package Vibe\AIIndex\Services
 * @since 1.2.0
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

/**
 * Records audit trail entries for entity mutations.
 *
 * Each log entry captures the entity ID, action performed, the user who
 * performed it, and optional before/after data snapshots.
 */
class AuditLogger
{
    /**
     * Log an entity mutation to the audit trail.
     *
     * @param int         $entity_id The entity ID that was mutated.
     * @param string      $action    The action performed (create, update, delete, merge, status_change).
     * @param array|null  $before    Entity state before the mutation, or null.
     * @param array|null  $after     Entity state after the mutation, or null.
     * @return void
     */
    public static function log(int $entity_id, string $action, ?array $before = null, ?array $after = null): void
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'ai_audit_log', [
            'entity_id'   => $entity_id,
            'action'      => $action,
            'user_id'     => get_current_user_id(),
            'before_data' => $before !== null ? wp_json_encode($before) : null,
            'after_data'  => $after !== null ? wp_json_encode($after) : null,
            'created_at'  => current_time('mysql'),
        ]);
    }
}

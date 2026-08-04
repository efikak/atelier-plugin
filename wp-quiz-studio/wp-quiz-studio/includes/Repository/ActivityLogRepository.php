<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

/** Immutable audit log for Organization and quiz actions. */
final class ActivityLogRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpqs_activity_log';
    }

    /** @param array<string,mixed> $details */
    public function log(string $action, string $objectType, int $objectId = 0, int $organizationId = 0, int $userId = 0, array $details = []): void
    {
        global $wpdb;
        $wpdb->insert($this->table, [
            'organization_id' => $organizationId ?: null,
            'user_id' => $userId ?: get_current_user_id(),
            'action' => sanitize_key($action),
            'object_type' => sanitize_key($objectType),
            'object_id' => $objectId ?: null,
            'details' => wp_json_encode($details),
            'created_at' => current_time('mysql', true),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function all(int $organizationId = 0, int $limit = 100): array
    {
        global $wpdb;
        $users = $wpdb->users;
        $where = $organizationId > 0 ? $wpdb->prepare('WHERE l.organization_id=%d', $organizationId) : '';
        $limit = max(1, min(500, $limit));
        $rows = $wpdb->get_results(
            "SELECT l.*, u.display_name, u.user_email
             FROM {$this->table} l
             LEFT JOIN {$users} u ON u.ID=l.user_id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT {$limit}",
            ARRAY_A
        ) ?: [];

        return array_map(static function (array $row): array {
            foreach (['id', 'organization_id', 'user_id', 'object_id'] as $key) {
                $row[$key] = (int) ($row[$key] ?? 0);
            }
            $details = json_decode((string) ($row['details'] ?? ''), true);
            $row['details'] = is_array($details) ? $details : [];
            return $row;
        }, $rows);
    }
}

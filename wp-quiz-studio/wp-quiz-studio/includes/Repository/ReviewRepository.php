<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

/** Review comments and workflow history for quiz approval. */
final class ReviewRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpqs_review_comments';
    }

    public function add(int $quizId, int $organizationId, int $userId, string $action, string $comment): int
    {
        global $wpdb;
        $action = sanitize_key($action);
        if (!in_array($action, ['submitted', 'changes_requested', 'approved', 'published', 'comment'], true)) {
            $action = 'comment';
        }
        $wpdb->insert($this->table, [
            'quiz_id' => $quizId,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'action' => $action,
            'comment' => sanitize_textarea_field($comment),
            'created_at' => current_time('mysql', true),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** @return list<array<string,mixed>> */
    public function all(int $quizId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name FROM {$this->table} r LEFT JOIN {$wpdb->users} u ON u.ID=r.user_id WHERE r.quiz_id=%d ORDER BY r.created_at DESC",
            $quizId
        ), ARRAY_A) ?: [];
        return array_map(static function (array $row): array {
            foreach (['id', 'quiz_id', 'organization_id', 'user_id'] as $key) {
                $row[$key] = (int) ($row[$key] ?? 0);
            }
            return $row;
        }, $rows);
    }
}

<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

/** Stores compact quiz snapshots for manual saves and restores. */
final class RevisionRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpqs_revisions';
    }

    public function create(int $quizId, array $snapshot, int $authorId): int
    {
        global $wpdb;
        $version = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(version_number), 0) + 1 FROM {$this->table} WHERE quiz_id = %d",
            $quizId
        ));

        $inserted = $wpdb->insert($this->table, [
            'quiz_id' => $quizId,
            'version_number' => $version,
            'snapshot' => wp_json_encode($snapshot),
            'author_id' => $authorId,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%d', '%s', '%d', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η δημιουργία έκδοσης του quiz.');
        }

        $this->trim($quizId, 30);
        return (int) $wpdb->insert_id;
    }

    public function all(int $quizId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, quiz_id, version_number, author_id, created_at FROM {$this->table} WHERE quiz_id = %d ORDER BY version_number DESC",
            $quizId
        ), ARRAY_A) ?: [];
    }

    public function find(int $quizId, int $revisionId): ?array
    {
        global $wpdb;
        $snapshot = $wpdb->get_var($wpdb->prepare(
            "SELECT snapshot FROM {$this->table} WHERE id = %d AND quiz_id = %d",
            $revisionId,
            $quizId
        ));

        if (!is_string($snapshot) || $snapshot === '') {
            return null;
        }

        $decoded = json_decode($snapshot, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function deleteForQuiz(int $quizId): void
    {
        global $wpdb;
        $wpdb->delete($this->table, ['quiz_id' => $quizId], ['%d']);
    }

    private function trim(int $quizId, int $keep): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE quiz_id = %d ORDER BY version_number DESC LIMIT 18446744073709551615 OFFSET %d",
            $quizId,
            $keep
        ));

        if (!$ids) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE id IN ({$placeholders})", ...array_map('intval', $ids)));
    }
}

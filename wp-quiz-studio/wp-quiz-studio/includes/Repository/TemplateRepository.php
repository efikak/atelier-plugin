<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

/** Reusable quiz templates scoped to one Organization or all Organizations. */
final class TemplateRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpqs_templates';
    }

    /** @return list<array<string,mixed>> */
    public function all(int $organizationId, bool $includeUniversal = true): array
    {
        global $wpdb;
        if ($includeUniversal) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE scope='universal' OR organization_id=%d ORDER BY scope DESC, updated_at DESC",
                $organizationId
            ), ARRAY_A) ?: [];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE organization_id=%d ORDER BY updated_at DESC",
                $organizationId
            ), ARRAY_A) ?: [];
        }
        return array_map(fn (array $row): array => $this->decode($row), $rows);
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id), ARRAY_A);
        return $row ? $this->decode($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function save(array $data, int $organizationId, int $userId, bool $allowUniversal): int
    {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $scope = sanitize_key((string) ($data['scope'] ?? 'organization'));
        if ($scope === 'universal' && !$allowUniversal) {
            throw new \RuntimeException(__('Δεν έχετε δικαίωμα δημιουργίας Universal template.', 'wp-quiz-studio'));
        }
        if (!in_array($scope, ['organization', 'universal'], true)) {
            $scope = 'organization';
        }
        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException(__('Ο τίτλος template είναι υποχρεωτικός.', 'wp-quiz-studio'));
        }

        $snapshot = (array) ($data['snapshot'] ?? []);
        unset($snapshot['id'], $snapshot['created_at'], $snapshot['updated_at'], $snapshot['author_id']);
        $record = [
            'organization_id' => $scope === 'universal' ? null : $organizationId,
            'scope' => $scope,
            'title' => $title,
            'slug' => sanitize_title((string) ($data['slug'] ?? $title)),
            'quiz_type' => sanitize_key((string) ($data['quiz_type'] ?? ($snapshot['quiz_type'] ?? 'knowledge'))),
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'thumbnail_url' => esc_url_raw((string) ($data['thumbnail_url'] ?? ($snapshot['settings']['intro']['image_url'] ?? ''))),
            'snapshot' => wp_json_encode($snapshot),
            'updated_at' => current_time('mysql', true),
        ];

        if ($id) {
            if ($wpdb->update($this->table, $record, ['id' => $id]) === false) {
                throw new \RuntimeException($wpdb->last_error ?: __('Δεν αποθηκεύτηκε το template.', 'wp-quiz-studio'));
            }
            return $id;
        }

        $record['created_by'] = $userId;
        $record['created_at'] = current_time('mysql', true);
        if ($wpdb->insert($this->table, $record) === false) {
            throw new \RuntimeException($wpdb->last_error ?: __('Δεν δημιουργήθηκε το template.', 'wp-quiz-studio'));
        }
        return (int) $wpdb->insert_id;
    }

    public function delete(int $id): void
    {
        global $wpdb;
        if ($wpdb->delete($this->table, ['id' => $id]) === false) {
            throw new \RuntimeException($wpdb->last_error ?: __('Δεν διαγράφηκε το template.', 'wp-quiz-studio'));
        }
    }

    private function decode(array $row): array
    {
        foreach (['id', 'organization_id', 'created_by'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $snapshot = json_decode((string) ($row['snapshot'] ?? ''), true);
        $row['snapshot'] = is_array($snapshot) ? $snapshot : [];
        return $row;
    }
}

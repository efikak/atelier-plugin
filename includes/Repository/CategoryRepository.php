<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

use WPQuizStudio\Security\AccessManager;

/** Stores Organization-scoped quiz categories outside WordPress taxonomies. */
final class CategoryRepository
{
    private string $categories;
    private string $quizzes;

    public function __construct(private ?AccessManager $access = null)
    {
        global $wpdb;
        $this->categories = $wpdb->prefix . 'wpqs_categories';
        $this->quizzes = $wpdb->prefix . 'wpqs_quizzes';
        $this->access ??= new AccessManager();
    }

    public function all(bool $publicOnly = false): array
    {
        global $wpdb;
        $availability = $publicOnly ? "AND q.status='published' AND (q.expires_at IS NULL OR q.expires_at>UTC_TIMESTAMP())" : '';
        $where = '';
        $params = [];
        if (!$publicOnly && !$this->access->context()['is_super_admin']) {
            $where = 'WHERE c.organization_id=%d';
            $params[] = $this->access->currentOrganizationId();
        }
        $sql = "SELECT c.*, (SELECT COUNT(*) FROM {$this->quizzes} q WHERE q.category_id=c.id {$availability}) AS quiz_count
                FROM {$this->categories} c {$where} ORDER BY c.name ASC";
        if ($params !== []) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        return array_map([$this, 'normalise'], $rows);
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->categories} WHERE id=%d", $id), ARRAY_A);
        return $row ? $this->normalise($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->categories} WHERE slug=%s ORDER BY id ASC LIMIT 1", sanitize_title($slug)), ARRAY_A);
        return $row ? $this->normalise($row) : null;
    }

    public function save(array $data): int
    {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $name = sanitize_text_field((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException(__('Το όνομα κατηγορίας είναι υποχρεωτικό.', 'wp-quiz-studio'));
        }
        $organizationId = absint($data['organization_id'] ?? $this->access->currentOrganizationId());
        $record = [
            'organization_id' => $organizationId ?: null,
            'name' => $name,
            'slug' => $this->uniqueSlug((string) ($data['slug'] ?? $name), $id, $organizationId),
            'description' => wp_kses_post((string) ($data['description'] ?? '')),
            'color' => sanitize_hex_color((string) ($data['color'] ?? '#d9bd85')) ?: '#d9bd85',
            'icon' => $this->sanitizeIcon((string) ($data['icon'] ?? 'folder')),
            'updated_at' => current_time('mysql', true),
        ];
        if ($id) {
            $existing = $this->find($id);
            if (!$existing || (!$this->access->context()['is_super_admin'] && (int) $existing['organization_id'] !== $organizationId)) {
                throw new \RuntimeException(__('Η κατηγορία δεν βρέθηκε.', 'wp-quiz-studio'));
            }
            if ($wpdb->update($this->categories, $record, ['id' => $id]) === false) {
                throw new \RuntimeException($wpdb->last_error ?: __('Δεν ενημερώθηκε η κατηγορία.', 'wp-quiz-studio'));
            }
            return $id;
        }
        $record['created_at'] = current_time('mysql', true);
        if ($wpdb->insert($this->categories, $record) === false) {
            throw new \RuntimeException($wpdb->last_error ?: __('Δεν δημιουργήθηκε η κατηγορία.', 'wp-quiz-studio'));
        }
        return (int) $wpdb->insert_id;
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $category = $this->find($id);
        if (!$category || (!$this->access->context()['is_super_admin'] && (int) $category['organization_id'] !== $this->access->currentOrganizationId())) {
            throw new \RuntimeException(__('Η κατηγορία δεν βρέθηκε.', 'wp-quiz-studio'));
        }
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->update($this->quizzes, ['category_id' => null], ['category_id' => $id]);
            if ($wpdb->delete($this->categories, ['id' => $id]) === false) {
                throw new \RuntimeException($wpdb->last_error ?: __('Δεν διαγράφηκε η κατηγορία.', 'wp-quiz-studio'));
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    private function uniqueSlug(string $value, int $excludeId, int $organizationId): string
    {
        global $wpdb;
        $base = sanitize_title($value) ?: 'category';
        $candidate = $base;
        $suffix = 2;
        while ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->categories} WHERE slug=%s AND organization_id=%d AND id!=%d",
            $candidate,
            $organizationId,
            $excludeId
        )) > 0) {
            $candidate = $base . '-' . $suffix++;
        }
        return $candidate;
    }

    private function sanitizeIcon(string $icon): string
    {
        $allowed = ['folder', 'news', 'sports', 'culture', 'fun', 'education', 'personality', 'poll', 'star'];
        $icon = sanitize_key($icon);
        return in_array($icon, $allowed, true) ? $icon : 'folder';
    }

    private function normalise(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['organization_id'] = (int) ($row['organization_id'] ?? 0);
        $row['quiz_count'] = (int) ($row['quiz_count'] ?? 0);
        $row['color'] = sanitize_hex_color((string) ($row['color'] ?? '#d9bd85')) ?: '#d9bd85';
        $row['icon'] = $this->sanitizeIcon((string) ($row['icon'] ?? 'folder'));
        return $row;
    }
}

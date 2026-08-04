<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

use WPQuizStudio\Security\AccessManager;

/** Data gateway for quiz aggregate storage. */
final class QuizRepository
{
    private string $quizzes;
    private string $questions;
    private string $answers;
    private string $categories;
    private AccessManager $access;

    /** @var list<string> */
    private array $quizTypes = ['knowledge', 'poll', 'personality', 'survey'];

    /** @var list<string> */
    private array $questionTypes = [
        'multiple_choice',
        'multiple_answers',
        'true_false',
        'image_choice',
        'poll',
        'open_text',
        'slider',
        'numeric',
        'rating',
        'ordering',
        'ranking',
        'matching',
    ];

    public function __construct(?AccessManager $access = null)
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'wpqs_';
        $this->quizzes = $prefix . 'quizzes';
        $this->questions = $prefix . 'questions';
        $this->answers = $prefix . 'answers';
        $this->categories = $prefix . 'categories';
        $this->access = $access ?: new AccessManager();
    }

    public function all(): array
    {
        global $wpdb;
        $analytics = $wpdb->prefix . 'wpqs_analytics';
        $context = $this->access->context();
        $where = '1=1';
        $params = [];
        if (!$context['is_super_admin']) {
            $organizationId = (int) $context['organization_id'];
            $userId = (int) $context['user_id'];
            if ($context['is_universal_manager']) {
                $where = "(q.visibility_scope='universal' OR q.organization_id=%d)";
                $params[] = $organizationId;
            } elseif ($context['organization_role'] === 'creator_admin') {
                $where = "(q.organization_id=%d OR q.visibility_scope='universal')";
                $params[] = $organizationId;
            } else {
                $where = "(q.visibility_scope='universal' OR (q.organization_id=%d AND (q.visibility_scope='organization' OR q.author_id=%d)))";
                $params[] = $organizationId;
                $params[] = $userId;
            }
        }
        $sql = "SELECT q.*, c.name AS category_name, c.slug AS category_slug, c.color AS category_color, c.icon AS category_icon,
                u.display_name AS author_name,
                (SELECT COUNT(*) FROM {$analytics} a WHERE a.quiz_id=q.id AND a.event_type='view') AS views,
                (SELECT COUNT(*) FROM {$analytics} a WHERE a.quiz_id=q.id AND a.event_type='start') AS starts,
                (SELECT COUNT(*) FROM {$analytics} a WHERE a.quiz_id=q.id AND a.event_type='complete') AS completions
             FROM {$this->quizzes} q
             LEFT JOIN {$this->categories} c ON c.id=q.category_id
             LEFT JOIN {$wpdb->users} u ON u.ID=q.author_id
             WHERE {$where}
             ORDER BY q.updated_at DESC";
        if ($params !== []) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $row = $this->decodeQuizRow($row);
            $row['views'] = (int) ($row['views'] ?? 0);
            $row['starts'] = (int) ($row['starts'] ?? 0);
            $row['completions'] = (int) ($row['completions'] ?? 0);
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $quiz = $wpdb->get_row($wpdb->prepare(
            "SELECT q.*, c.name AS category_name, c.slug AS category_slug, c.color AS category_color, c.icon AS category_icon, u.display_name AS author_name
             FROM {$this->quizzes} q
             LEFT JOIN {$this->categories} c ON c.id = q.category_id
             LEFT JOIN {$wpdb->users} u ON u.ID = q.author_id
             WHERE q.id = %d",
            $id
        ), ARRAY_A);

        if (!$quiz) {
            return null;
        }

        $quiz = $this->decodeQuizRow($quiz);
        $questions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->questions} WHERE quiz_id = %d ORDER BY position",
            $id
        ), ARRAY_A) ?: [];

        foreach ($questions as &$question) {
            $question['id'] = (int) $question['id'];
            $question['quiz_id'] = (int) $question['quiz_id'];
            $question['position'] = (int) $question['position'];
            $question['content'] = $this->decodeJson((string) $question['content']);
            $question['settings'] = $this->decodeJson((string) ($question['settings'] ?? ''));
            $question['answers'] = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->answers} WHERE question_id = %d ORDER BY position",
                $question['id']
            ), ARRAY_A) ?: [];

            foreach ($question['answers'] as &$answer) {
                $answer['id'] = (int) $answer['id'];
                $answer['question_id'] = (int) $answer['question_id'];
                $answer['position'] = (int) $answer['position'];
                $answer['content'] = $this->decodeJson((string) $answer['content']);
                $answer['is_correct'] = (bool) $answer['is_correct'];
                $answer['score'] = (float) $answer['score'];
            }
        }

        $quiz['questions'] = $questions;
        return $quiz;
    }

    public function save(array $data, int $userId, bool $createRevision = true): int
    {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $existing = $id ? $this->find($id) : null;

        if ($id && !$existing) {
            throw new \RuntimeException('Το quiz δεν υπάρχει πλέον.');
        }

        $now = current_time('mysql', true);
        $status = $this->normaliseStatus((string) ($data['status'] ?? 'draft'));
        $scheduledAt = $this->normaliseScheduledAt($data['scheduled_at'] ?? null, $status);
        $expiresAt = $this->normaliseDateTime($data['expires_at'] ?? null);
        if ($status === 'scheduled' && !$scheduledAt) {
            $status = 'draft';
        }

        $title = sanitize_text_field((string) ($data['title'] ?? __('Χωρίς τίτλο', 'wp-quiz-studio')));
        if ($title === '') {
            $title = __('Χωρίς τίτλο', 'wp-quiz-studio');
        }

        $context = $this->access->context();
        $organizationId = $existing
            ? (int) ($existing['organization_id'] ?? 0)
            : absint($data['organization_id'] ?? $context['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            $organizationId = (new OrganizationRepository())->defaultOrganizationId();
        }
        $visibility = $this->normaliseVisibility((string) ($data['visibility_scope'] ?? ($existing['visibility_scope'] ?? 'personal')));
        if ($visibility === 'universal' && !$this->access->canManageUniversal()) {
            $visibility = $existing ? (string) ($existing['visibility_scope'] ?? 'personal') : 'personal';
        }
        $workflow = $this->normaliseWorkflow((string) ($data['workflow_status'] ?? ($existing['workflow_status'] ?? 'draft')));

        $record = [
            'organization_id' => $organizationId,
            'title' => $title,
            'slug' => $this->uniqueSlug((string) ($data['slug'] ?? $title), $id),
            'description' => wp_kses_post((string) ($data['description'] ?? '')),
            'quiz_type' => $this->normaliseQuizType((string) ($data['quiz_type'] ?? 'knowledge')),
            'status' => $status,
            'workflow_status' => $workflow,
            'visibility_scope' => $visibility,
            'scheduled_at' => $scheduledAt,
            'expires_at' => $expiresAt,
            'category_id' => absint($data['category_id'] ?? 0) ?: null,
            'template_id' => absint($data['template_id'] ?? 0) ?: null,
            'author_id' => $existing ? (int) $existing['author_id'] : $userId,
            'review_comment' => sanitize_textarea_field((string) ($data['review_comment'] ?? ($existing['review_comment'] ?? ''))),
            'settings' => wp_json_encode($this->sanitizeSettings((array) ($data['settings'] ?? []))),
            'theme' => wp_json_encode($this->sanitizeTheme((array) ($data['theme'] ?? []))),
            'updated_at' => $now,
        ];

        $wpdb->query('START TRANSACTION');
        try {
            if ($id) {
                if ($createRevision && $existing) {
                    (new RevisionRepository())->create($id, $existing, $userId);
                }

                $updated = $wpdb->update($this->quizzes, $record, ['id' => $id]);
                if ($updated === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η ενημέρωση του quiz.');
                }
            } else {
                $record['created_at'] = $now;
                $inserted = $wpdb->insert($this->quizzes, $record);
                if ($inserted === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η δημιουργία του quiz.');
                }
                $id = (int) $wpdb->insert_id;
            }

            if (array_key_exists('questions', $data)) {
                $this->syncQuestions($id, (array) $data['questions']);
            }

            $wpdb->query('COMMIT');
            return $id;
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Updates lightweight list fields without replacing questions or answers.
     *
     * @param array<string,mixed> $data
     */
    public function quickUpdate(int $id, array $data): array
    {
        global $wpdb;
        $existing = $this->find($id);
        if (!$existing) {
            throw new \RuntimeException('Το quiz δεν βρέθηκε.');
        }

        $record = ['updated_at' => current_time('mysql', true)];
        if (array_key_exists('quiz_type', $data)) {
            $record['quiz_type'] = $this->normaliseQuizType((string) $data['quiz_type']);
        }
        if (array_key_exists('visibility_scope', $data)) {
            $visibility = $this->normaliseVisibility((string) $data['visibility_scope']);
            if ($visibility === 'universal' && !$this->access->canManageUniversal()) {
                throw new \RuntimeException('Δεν έχετε δικαίωμα Universal ορατότητας.');
            }
            $record['visibility_scope'] = $visibility;
        }
        if (array_key_exists('workflow_status', $data)) {
            $record['workflow_status'] = $this->normaliseWorkflow((string) $data['workflow_status']);
        }
        if (array_key_exists('status', $data)) {
            $status = $this->normaliseStatus((string) $data['status']);
            if ($status === 'scheduled' && empty($existing['scheduled_at'])) {
                throw new \RuntimeException('Ορίστε πρώτα ημερομηνία προγραμματισμένης δημοσίευσης μέσα από την επεξεργασία του quiz.');
            }
            $record['status'] = $status;
            if ($status !== 'scheduled') {
                $record['scheduled_at'] = null;
            }
        }

        $updated = $wpdb->update($this->quizzes, $record, ['id' => $id]);
        if ($updated === false) {
            throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η γρήγορη ενημέρωση του quiz.');
        }

        return $this->find($id) ?: $existing;
    }

    public function duplicate(int $id, int $userId): int
    {
        $quiz = $this->find($id);
        if (!$quiz) {
            throw new \RuntimeException('Το quiz δεν βρέθηκε.');
        }

        unset($quiz['id'], $quiz['created_at'], $quiz['updated_at'], $quiz['scheduled_at'], $quiz['expires_at']);
        $quiz['title'] = sprintf(__('%s (Αντίγραφο)', 'wp-quiz-studio'), $quiz['title']);
        $quiz['slug'] = sanitize_title($quiz['title']);
        $quiz['status'] = 'draft';
        $quiz['workflow_status'] = 'draft';
        $quiz['visibility_scope'] = 'personal';
        $quiz['organization_id'] = $this->access->currentOrganizationId();
        $quiz['expires_at'] = null;
        $quiz['author_id'] = $userId;

        foreach ($quiz['questions'] as &$question) {
            unset($question['id'], $question['quiz_id']);
            foreach ($question['answers'] as &$answer) {
                unset($answer['id'], $answer['question_id']);
            }
        }

        return $this->save($quiz, $userId, false);
    }

    /**
     * Returns lightweight published quizzes for the public directory shortcode.
     *
     * @return list<array<string,mixed>>
     */
    public function publicDirectory(int $categoryId = 0): array
    {
        global $wpdb;
        $where = "q.status = 'published' AND (q.expires_at IS NULL OR q.expires_at > UTC_TIMESTAMP())";
        $params = [];
        if ($categoryId > 0) {
            $where .= ' AND q.category_id = %d';
            $params[] = $categoryId;
        }

        $sql = "SELECT q.id, q.organization_id, q.template_id, q.title, q.slug, q.description, q.quiz_type, q.expires_at, q.settings, q.theme,
                       c.id AS category_id, c.name AS category_name, c.slug AS category_slug, c.color AS category_color, c.icon AS category_icon
                FROM {$this->quizzes} q
                LEFT JOIN {$this->categories} c ON c.id = q.category_id
                WHERE {$where}
                ORDER BY q.updated_at DESC";
        if ($params !== []) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        $row['organization_id'] = (int) ($row['organization_id'] ?? 0);
        $row['template_id'] = (int) ($row['template_id'] ?? 0);
            $row['category_id'] = (int) ($row['category_id'] ?? 0);
            $row['settings'] = $this->decodeJson((string) ($row['settings'] ?? ''));
            $row['theme'] = $this->decodeJson((string) ($row['theme'] ?? ''));
            $row['category'] = $row['category_id'] > 0 ? [
                'id' => $row['category_id'],
                'name' => (string) ($row['category_name'] ?? ''),
                'slug' => (string) ($row['category_slug'] ?? ''),
                'color' => sanitize_hex_color((string) ($row['category_color'] ?? '#d9bd85')) ?: '#d9bd85',
                'icon' => sanitize_key((string) ($row['category_icon'] ?? 'folder')),
            ] : null;
            unset($row['category_name'], $row['category_slug'], $row['category_color'], $row['category_icon']);
        }

        return $rows;
    }

    public function isAvailable(array $quiz): bool
    {
        if (($quiz['status'] ?? '') !== 'published') {
            return false;
        }

        $expiresAt = (string) ($quiz['expires_at'] ?? '');
        if ($expiresAt === '') {
            return true;
        }

        $expiresTimestamp = strtotime($expiresAt . (str_contains($expiresAt, 'Z') ? '' : ' UTC'));
        return $expiresTimestamp !== false && $expiresTimestamp > time();
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $questionIds = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$this->questions} WHERE quiz_id = %d",
                $id
            )) ?: [];

            foreach ($questionIds as $questionId) {
                if ($wpdb->delete($this->answers, ['question_id' => (int) $questionId]) === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η διαγραφή των απαντήσεων του quiz.');
                }
            }

            $tables = [
                $this->questions,
                $wpdb->prefix . 'wpqs_results',
                $wpdb->prefix . 'wpqs_analytics',
                $wpdb->prefix . 'wpqs_sessions',
                $wpdb->prefix . 'wpqs_embeds',
                $wpdb->prefix . 'wpqs_revisions',
            ];
            foreach ($tables as $table) {
                if ($wpdb->delete($table, ['quiz_id' => $id]) === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η διαγραφή των δεδομένων του quiz.');
                }
            }

            if ($wpdb->delete($this->quizzes, ['id' => $id]) === false) {
                throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η διαγραφή του quiz.');
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    private function syncQuestions(int $quizId, array $questions): void
    {
        global $wpdb;
        $existingIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->questions} WHERE quiz_id = %d",
            $quizId
        )) ?: []);
        $keptQuestionIds = [];

        foreach (array_values($questions) as $position => $question) {
            $question = (array) $question;
            $questionId = absint($question['id'] ?? 0);
            $isExisting = $questionId > 0 && in_array($questionId, $existingIds, true);
            $record = [
                'quiz_id' => $quizId,
                'position' => $position,
                'type' => $this->normaliseQuestionType((string) ($question['type'] ?? 'multiple_choice')),
                'content' => wp_json_encode($this->sanitizeQuestionContent((array) ($question['content'] ?? []))),
                'settings' => wp_json_encode($this->sanitizeQuestionSettings((array) ($question['settings'] ?? []))),
            ];

            if ($isExisting) {
                $updated = $wpdb->update($this->questions, $record, ['id' => $questionId, 'quiz_id' => $quizId]);
                if ($updated === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η ενημέρωση μιας ερώτησης.');
                }
            } else {
                $inserted = $wpdb->insert($this->questions, $record);
                if ($inserted === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η δημιουργία μιας ερώτησης.');
                }
                $questionId = (int) $wpdb->insert_id;
            }

            $keptQuestionIds[] = $questionId;
            $this->syncAnswers($questionId, (array) ($question['answers'] ?? []));
        }

        $removed = array_diff($existingIds, $keptQuestionIds);
        foreach ($removed as $questionId) {
            if ($wpdb->delete($this->answers, ['question_id' => $questionId]) === false
                || $wpdb->delete($this->questions, ['id' => $questionId, 'quiz_id' => $quizId]) === false) {
                throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η αφαίρεση μιας ερώτησης.');
            }
        }
    }

    private function syncAnswers(int $questionId, array $answers): void
    {
        global $wpdb;
        $existingIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->answers} WHERE question_id = %d",
            $questionId
        )) ?: []);
        $keptIds = [];

        foreach (array_values($answers) as $position => $answer) {
            $answer = (array) $answer;
            $answerId = absint($answer['id'] ?? 0);
            $isExisting = $answerId > 0 && in_array($answerId, $existingIds, true);
            $record = [
                'question_id' => $questionId,
                'position' => $position,
                'content' => wp_json_encode($this->sanitizeAnswerContent((array) ($answer['content'] ?? $answer))),
                'is_correct' => !empty($answer['is_correct']) ? 1 : 0,
                'score' => round((float) ($answer['score'] ?? 0), 2),
            ];

            if ($isExisting) {
                $updated = $wpdb->update($this->answers, $record, ['id' => $answerId, 'question_id' => $questionId]);
                if ($updated === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η ενημέρωση μιας απάντησης.');
                }
            } else {
                $inserted = $wpdb->insert($this->answers, $record);
                if ($inserted === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η δημιουργία μιας απάντησης.');
                }
                $answerId = (int) $wpdb->insert_id;
            }
            $keptIds[] = $answerId;
        }

        $removed = array_diff($existingIds, $keptIds);
        foreach ($removed as $answerId) {
            if ($wpdb->delete($this->answers, ['id' => $answerId, 'question_id' => $questionId]) === false) {
                throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η αφαίρεση μιας απάντησης.');
            }
        }
    }

    private function uniqueSlug(string $value, int $excludeId): string
    {
        global $wpdb;
        $base = sanitize_title($value) ?: 'quiz';
        $candidate = $base;
        $suffix = 2;

        while ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->quizzes} WHERE slug = %s AND id != %d",
            $candidate,
            $excludeId
        )) > 0) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function decodeQuizRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['organization_id'] = (int) ($row['organization_id'] ?? 0);
        $row['template_id'] = (int) ($row['template_id'] ?? 0);
        $row['author_id'] = (int) $row['author_id'];
        $row['workflow_status'] = $this->normaliseWorkflow((string) ($row['workflow_status'] ?? 'draft'));
        $row['visibility_scope'] = $this->normaliseVisibility((string) ($row['visibility_scope'] ?? 'personal'));
        $row['quiz_type'] = $this->normaliseQuizType((string) ($row['quiz_type'] ?? 'knowledge'));
        $row['category_id'] = (int) ($row['category_id'] ?? 0);
        $row['settings'] = $this->decodeJson((string) ($row['settings'] ?? ''));
        $row['theme'] = $this->decodeJson((string) ($row['theme'] ?? ''));
        $row['category'] = $row['category_id'] > 0 ? [
            'id' => $row['category_id'],
            'name' => (string) ($row['category_name'] ?? ''),
            'slug' => (string) ($row['category_slug'] ?? ''),
            'color' => sanitize_hex_color((string) ($row['category_color'] ?? '#d9bd85')) ?: '#d9bd85',
            'icon' => sanitize_key((string) ($row['category_icon'] ?? 'folder')),
        ] : null;
        unset($row['category_name'], $row['category_slug'], $row['category_color'], $row['category_icon']);
        return $row;
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value !== '' ? $value : '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normaliseVisibility(string $visibility): string
    {
        return in_array($visibility, ['personal', 'organization', 'universal'], true) ? $visibility : 'personal';
    }

    private function normaliseWorkflow(string $workflow): string
    {
        return in_array($workflow, ['draft', 'submitted', 'changes_requested', 'approved', 'published', 'archived'], true) ? $workflow : 'draft';
    }

    private function normaliseStatus(string $status): string
    {
        return in_array($status, ['draft', 'published', 'scheduled', 'private', 'expired'], true) ? $status : 'draft';
    }

    private function normaliseQuizType(string $type): string
    {
        return in_array($type, $this->quizTypes, true) ? $type : 'knowledge';
    }

    private function normaliseQuestionType(string $type): string
    {
        return in_array($type, $this->questionTypes, true) ? $type : 'multiple_choice';
    }

    private function normaliseScheduledAt(mixed $value, string $status): ?string
    {
        if ($status !== 'scheduled' || !is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    private function normaliseDateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    private function sanitizeSettings(array $settings): array
    {
        $intro = (array) ($settings['intro'] ?? []);
        $results = [];
        foreach ((array) ($settings['results'] ?? []) as $range) {
            $range = (array) $range;
            $results[] = [
                'min' => (float) ($range['min'] ?? 0),
                'max' => (float) ($range['max'] ?? 0),
                'title' => sanitize_text_field((string) ($range['title'] ?? 'Αποτέλεσμα')),
                'description' => wp_kses_post((string) ($range['description'] ?? '')),
                'image_id' => absint($range['image_id'] ?? 0),
                'image_url' => esc_url_raw((string) ($range['image_url'] ?? '')),
                'cta_label' => sanitize_text_field((string) ($range['cta_label'] ?? '')),
                'cta_url' => esc_url_raw((string) ($range['cta_url'] ?? '')),
            ];
        }

        $profiles = [];
        foreach ((array) ($settings['personality_profiles'] ?? []) as $profile) {
            $profile = (array) $profile;
            $key = sanitize_key((string) ($profile['key'] ?? ''));
            if ($key === '') {
                $key = 'profile_' . sanitize_key(wp_generate_password(10, false, false));
            }
            $profiles[] = [
                'key' => $key,
                'title' => sanitize_text_field((string) ($profile['title'] ?? 'Προσωπικότητα')),
                'description' => wp_kses_post((string) ($profile['description'] ?? '')),
                'image_id' => absint($profile['image_id'] ?? 0),
                'image_url' => esc_url_raw((string) ($profile['image_url'] ?? '')),
                'cta_label' => sanitize_text_field((string) ($profile['cta_label'] ?? '')),
                'cta_url' => esc_url_raw((string) ($profile['cta_url'] ?? '')),
            ];
        }

        $embedMode = (string) ($settings['embed_mode'] ?? 'inherit');
        if (!in_array($embedMode, ['inherit', 'public', 'restricted'], true)) {
            $embedMode = 'inherit';
        }
        $embedDomains = $settings['embed_domains'] ?? [];
        if (is_string($embedDomains)) {
            $embedDomains = preg_split('/[\r\n,;]+/', $embedDomains) ?: [];
        }
        $embedDomains = array_values(array_unique(array_filter(array_map(static function (mixed $domain): string {
            $domain = strtolower(trim((string) $domain));
            if (str_contains($domain, '://')) {
                $domain = (string) wp_parse_url($domain, PHP_URL_HOST);
            }
            $domain = preg_replace('/^www\./', '', explode('/', $domain)[0]) ?: '';
            return preg_match('/^(\*\.)?[a-z0-9.-]+$/', $domain) ? $domain : '';
        }, (array) $embedDomains))));

        return [
            'intro' => [
                'title' => sanitize_text_field((string) ($intro['title'] ?? 'Έτοιμοι να ξεκινήσουμε;')),
                'subtitle' => sanitize_text_field((string) ($intro['subtitle'] ?? '')),
                'button' => sanitize_text_field((string) ($intro['button'] ?? 'Έναρξη quiz')),
                'image_id' => absint($intro['image_id'] ?? 0),
                'image_url' => esc_url_raw((string) ($intro['image_url'] ?? '')),
            ],
            'category' => sanitize_text_field((string) ($settings['category'] ?? '')),
            'show_progress' => !array_key_exists('show_progress', $settings) || !empty($settings['show_progress']),
            'random_questions' => !empty($settings['random_questions']),
            'random_question_limit' => min(500, max(0, absint($settings['random_question_limit'] ?? 0))),
            'show_feedback' => !array_key_exists('show_feedback', $settings) || !empty($settings['show_feedback']),
            'show_correct_answer' => !array_key_exists('show_correct_answer', $settings) || !empty($settings['show_correct_answer']),
            'allow_restart' => !array_key_exists('allow_restart', $settings) || !empty($settings['allow_restart']),
            'review_answers' => !array_key_exists('review_answers', $settings) || !empty($settings['review_answers']),
            'show_pass_fail' => !empty($settings['show_pass_fail']),
            'pass_score' => round((float) ($settings['pass_score'] ?? 0), 2),
            'results' => $results,
            'personality_profiles' => $profiles,
            'personality_tie_strategy' => in_array((string) ($settings['personality_tie_strategy'] ?? 'first'), ['first', 'all'], true)
                ? (string) $settings['personality_tie_strategy'] : 'first',
            'embed_mode' => $embedMode,
            'embed_domains' => $embedDomains,
            'embed_block_message' => sanitize_textarea_field((string) ($settings['embed_block_message'] ?? '')),
        ];
    }

    private function sanitizeTheme(array $theme): array
    {
        $shadow = sanitize_key((string) ($theme['shadow'] ?? 'strong'));
        if (!in_array($shadow, ['none', 'soft', 'strong'], true)) {
            $shadow = 'soft';
        }

        return [
            'preset' => sanitize_key((string) ($theme['preset'] ?? 'atelier')),
            'primary' => sanitize_hex_color((string) ($theme['primary'] ?? '#d9bd85')) ?: '#d9bd85',
            'secondary' => sanitize_hex_color((string) ($theme['secondary'] ?? '#b9a7ff')) ?: '#b9a7ff',
            'page' => sanitize_hex_color((string) ($theme['page'] ?? '#08080a')) ?: '#08080a',
            'background' => sanitize_hex_color((string) ($theme['background'] ?? '#15151b')) ?: '#15151b',
            'text' => sanitize_hex_color((string) ($theme['text'] ?? '#f6f4ef')) ?: '#f6f4ef',
            'muted' => sanitize_hex_color((string) ($theme['muted'] ?? '#b8b5be')) ?: '#b8b5be',
            'button' => sanitize_hex_color((string) ($theme['button'] ?? $theme['primary'] ?? '#d9bd85')) ?: '#d9bd85',
            'button_text' => sanitize_hex_color((string) ($theme['button_text'] ?? '#111111')) ?: '#111111',
            'answer' => sanitize_hex_color((string) ($theme['answer'] ?? '#202027')) ?: '#202027',
            'border' => sanitize_hex_color((string) ($theme['border'] ?? '#4a4852')) ?: '#4a4852',
            'correct' => sanitize_hex_color((string) ($theme['correct'] ?? '#91d7b4')) ?: '#91d7b4',
            'wrong' => sanitize_hex_color((string) ($theme['wrong'] ?? '#ff8b8b')) ?: '#ff8b8b',
            'radius' => min(40, max(0, absint($theme['radius'] ?? 22))),
            'font' => sanitize_key((string) ($theme['font'] ?? 'serif')),
            'shadow' => $shadow,
        ];
    }

    private function sanitizeQuestionContent(array $content): array
    {
        return [
            'title' => sanitize_text_field((string) ($content['title'] ?? 'Ερώτηση')),
            'image_id' => absint($content['image_id'] ?? 0),
            'image_url' => esc_url_raw((string) ($content['image_url'] ?? '')),
            'video_url' => esc_url_raw((string) ($content['video_url'] ?? '')),
            'audio_url' => esc_url_raw((string) ($content['audio_url'] ?? '')),
        ];
    }

    private function sanitizeQuestionSettings(array $settings): array
    {
        $condition = (array) ($settings['condition'] ?? []);
        $key = sanitize_key((string) ($settings['key'] ?? ''));
        if ($key === '') {
            $key = 'q_' . sanitize_key(wp_generate_password(12, false, false));
        }

        $rules = [];
        $rawRules = (array) ($condition['rules'] ?? []);
        if ($rawRules === [] && !empty($condition['question_key'])) {
            $rawRules[] = $condition;
        }
        foreach ($rawRules as $rule) {
            $rule = (array) $rule;
            $operator = (string) ($rule['operator'] ?? 'equals');
            if (!in_array($operator, ['equals', 'not_equals', 'answered', 'not_answered'], true)) {
                $operator = 'equals';
            }
            $rules[] = [
                'operator' => $operator,
                'question_key' => sanitize_key((string) ($rule['question_key'] ?? '')),
                'answer_key' => sanitize_key((string) ($rule['answer_key'] ?? '')),
            ];
        }

        return [
            'key' => $key,
            'hint' => sanitize_text_field((string) ($settings['hint'] ?? '')),
            'explanation' => wp_kses_post((string) ($settings['explanation'] ?? '')),
            'points' => round((float) ($settings['points'] ?? 1), 2),
            'timer' => min(3600, max(0, absint($settings['timer'] ?? 0))),
            'shuffle_answers' => !empty($settings['shuffle_answers']),
            'required' => !array_key_exists('required', $settings) || !empty($settings['required']),
            'slider_min' => (float) ($settings['slider_min'] ?? 0),
            'slider_max' => (float) ($settings['slider_max'] ?? 100),
            'slider_step' => max(0.01, (float) ($settings['slider_step'] ?? 1)),
            'correct_min' => (float) ($settings['correct_min'] ?? 0),
            'correct_max' => (float) ($settings['correct_max'] ?? 100),
            'numeric_answer' => (float) ($settings['numeric_answer'] ?? 0),
            'numeric_tolerance' => max(0.0, (float) ($settings['numeric_tolerance'] ?? 0)),
            'rating_max' => min(20, max(2, absint($settings['rating_max'] ?? 5))),
            'multiple_scoring' => in_array((string) ($settings['multiple_scoring'] ?? 'exact'), ['exact', 'partial'], true)
                ? (string) $settings['multiple_scoring'] : 'exact',
            'order_scoring' => in_array((string) ($settings['order_scoring'] ?? 'exact'), ['exact', 'partial'], true)
                ? (string) $settings['order_scoring'] : 'exact',
            'matching_scoring' => in_array((string) ($settings['matching_scoring'] ?? 'exact'), ['exact', 'partial'], true)
                ? (string) $settings['matching_scoring'] : 'exact',
            'text_case_sensitive' => !empty($settings['text_case_sensitive']),
            'text_ignore_accents' => !array_key_exists('text_ignore_accents', $settings) || !empty($settings['text_ignore_accents']),
            'text_ignore_punctuation' => !array_key_exists('text_ignore_punctuation', $settings) || !empty($settings['text_ignore_punctuation']),
            'rating_style' => in_array((string) ($settings['rating_style'] ?? 'stars'), ['stars', 'numbers'], true)
                ? (string) $settings['rating_style'] : 'stars',
            'condition' => [
                'enabled' => !empty($condition['enabled']),
                'match' => in_array((string) ($condition['match'] ?? 'all'), ['all', 'any'], true) ? (string) $condition['match'] : 'all',
                'rules' => $rules,
                // Legacy fields remain for compatibility with the 0.6 player/editor.
                'operator' => (string) ($rules[0]['operator'] ?? 'equals'),
                'question_key' => (string) ($rules[0]['question_key'] ?? ''),
                'answer_key' => (string) ($rules[0]['answer_key'] ?? ''),
            ],
        ];
    }

    private function sanitizeAnswerContent(array $content): array
    {
        $key = sanitize_key((string) ($content['key'] ?? ''));
        if ($key === '') {
            $key = 'a_' . sanitize_key(wp_generate_password(12, false, false));
        }

        $weights = [];
        foreach ((array) ($content['personality_weights'] ?? []) as $profileKey => $weight) {
            $profileKey = sanitize_key((string) $profileKey);
            if ($profileKey !== '') {
                $weights[$profileKey] = round((float) $weight, 2);
            }
        }

        return [
            'key' => $key,
            'text' => sanitize_text_field((string) ($content['text'] ?? '')),
            'match_text' => sanitize_text_field((string) ($content['match_text'] ?? '')),
            'image_id' => absint($content['image_id'] ?? 0),
            'image_url' => esc_url_raw((string) ($content['image_url'] ?? '')),
            'emoji' => sanitize_text_field((string) ($content['emoji'] ?? '')),
            'icon' => sanitize_key((string) ($content['icon'] ?? '')),
            'personality_weights' => $weights,
        ];
    }
}

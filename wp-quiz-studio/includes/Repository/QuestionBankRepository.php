<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

use WPQuizStudio\Security\AccessManager;

/** Stores reusable question templates independently from individual quizzes. */
final class QuestionBankRepository
{
    private string $table;

    /** @var list<string> */
    private array $questionTypes = [
        'multiple_choice',
        'multiple_answers',
        'true_false',
        'image_choice',
        'poll',
        'open_text',
    ];

    public function __construct(private ?AccessManager $access = null)
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpqs_question_bank';
        $this->access ??= new AccessManager();
    }

    public function all(): array
    {
        global $wpdb;
        $context = $this->access->context();
        if ($context['is_super_admin']) {
            $sql = "SELECT * FROM {$this->table} ORDER BY updated_at DESC";
        } elseif ($context['organization_role'] === 'creator_admin') {
            $sql = $wpdb->prepare("SELECT * FROM {$this->table} WHERE organization_id=%d OR visibility_scope='universal' ORDER BY updated_at DESC", (int) $context['organization_id']);
        } else {
            $sql = $wpdb->prepare("SELECT * FROM {$this->table} WHERE visibility_scope='universal' OR (organization_id=%d AND (visibility_scope='organization' OR author_id=%d)) ORDER BY updated_at DESC", (int) $context['organization_id'], (int) $context['user_id']);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['organization_id'] = (int) ($row['organization_id'] ?? 0);
            $row['author_id'] = (int) $row['author_id'];
            $decoded = json_decode((string) $row['question'], true);
            $row['question'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    public function create(string $title, array $question, int $userId, string $visibility = 'personal'): int
    {
        global $wpdb;
        $cleanQuestion = $this->sanitizeQuestion($question);
        $cleanTitle = sanitize_text_field($title !== '' ? $title : (string) ($cleanQuestion['content']['title'] ?? __('Επαναχρησιμοποιήσιμη ερώτηση', 'wp-quiz-studio')));
        $now = current_time('mysql', true);
        $visibility = in_array($visibility, ['personal','organization','universal'], true) ? $visibility : 'personal';
        if ($visibility === 'universal' && !$this->access->canManageUniversal()) { $visibility = 'personal'; }
        $inserted = $wpdb->insert($this->table, [
            'organization_id' => $this->access->currentOrganizationId(),
            'visibility_scope' => $visibility,
            'title' => $cleanTitle ?: __('Επαναχρησιμοποιήσιμη ερώτηση', 'wp-quiz-studio'),
            'type' => (string) $cleanQuestion['type'],
            'question' => wp_json_encode($cleanQuestion),
            'author_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === false) {
            throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η αποθήκευση της ερώτησης στην τράπεζα.');
        }
        return (int) $wpdb->insert_id;
    }

    public function delete(int $id): void
    {
        global $wpdb;
        if ($wpdb->delete($this->table, ['id' => $id]) === false) {
            throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η διαγραφή της ερώτησης από την τράπεζα.');
        }
    }

    private function sanitizeQuestion(array $question): array
    {
        $type = sanitize_key((string) ($question['type'] ?? 'multiple_choice'));
        if (!in_array($type, $this->questionTypes, true)) {
            $type = 'multiple_choice';
        }

        $content = (array) ($question['content'] ?? []);
        $settings = (array) ($question['settings'] ?? []);
        $condition = (array) ($settings['condition'] ?? []);
        $answers = [];
        foreach (array_values((array) ($question['answers'] ?? [])) as $answer) {
            $answer = (array) $answer;
            $answerContent = (array) ($answer['content'] ?? $answer);
            $answers[] = [
                'content' => [
                    'key' => sanitize_key((string) ($answerContent['key'] ?? '')),
                    'text' => sanitize_text_field((string) ($answerContent['text'] ?? '')),
                    'image_id' => absint($answerContent['image_id'] ?? 0),
                    'image_url' => esc_url_raw((string) ($answerContent['image_url'] ?? '')),
                    'emoji' => sanitize_text_field((string) ($answerContent['emoji'] ?? '')),
                    'icon' => sanitize_key((string) ($answerContent['icon'] ?? '')),
                ],
                'is_correct' => !empty($answer['is_correct']),
                'score' => round((float) ($answer['score'] ?? 0), 2),
            ];
        }

        return [
            'type' => $type,
            'content' => [
                'title' => sanitize_text_field((string) ($content['title'] ?? 'Ερώτηση')),
                'image_id' => absint($content['image_id'] ?? 0),
                'image_url' => esc_url_raw((string) ($content['image_url'] ?? '')),
                'video_url' => esc_url_raw((string) ($content['video_url'] ?? '')),
                'audio_url' => esc_url_raw((string) ($content['audio_url'] ?? '')),
            ],
            'settings' => [
                'key' => sanitize_key((string) ($settings['key'] ?? '')),
                'hint' => sanitize_text_field((string) ($settings['hint'] ?? '')),
                'explanation' => wp_kses_post((string) ($settings['explanation'] ?? '')),
                'points' => round((float) ($settings['points'] ?? 1), 2),
                'timer' => min(3600, max(0, absint($settings['timer'] ?? 0))),
                'shuffle_answers' => !empty($settings['shuffle_answers']),
                'required' => !array_key_exists('required', $settings) || !empty($settings['required']),
                'condition' => [
                    'enabled' => !empty($condition['enabled']),
                    'operator' => in_array((string) ($condition['operator'] ?? 'equals'), ['equals', 'not_equals'], true) ? (string) $condition['operator'] : 'equals',
                    'question_key' => sanitize_key((string) ($condition['question_key'] ?? '')),
                    'answer_key' => sanitize_key((string) ($condition['answer_key'] ?? '')),
                ],
            ],
            'answers' => $answers,
        ];
    }
}

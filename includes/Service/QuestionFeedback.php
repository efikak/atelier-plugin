<?php

declare(strict_types=1);

namespace WPQuizStudio\Service;

/** Builds immediate per-question feedback after the visitor submits an answer. */
final class QuestionFeedback
{
    public function evaluate(array $quiz, int $questionId, mixed $response): ?array
    {
        $question = null;
        foreach ((array) ($quiz['questions'] ?? []) as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $questionId) {
                $question = (array) $candidate;
                break;
            }
        }
        if (!$question) {
            return null;
        }

        $miniQuiz = $quiz;
        $miniQuiz['questions'] = [$question];
        $scored = (new QuizScorer())->score($miniQuiz, [(string) $questionId => $response]);
        $questionResult = (array) ($scored['question_results'][0] ?? []);
        $review = (array) ($scored['review'][0] ?? []);
        $correctAnswers = [];
        foreach ((array) ($review['correct_answers'] ?? []) as $label) {
            $correctAnswers[] = ['id' => 0, 'text' => sanitize_text_field((string) $label), 'image_url' => '', 'emoji' => ''];
        }

        return [
            'question_id' => $questionId,
            'question_title' => sanitize_text_field((string) ($question['content']['title'] ?? '')),
            'type' => (string) ($question['type'] ?? 'multiple_choice'),
            'gradable' => (bool) ($questionResult['gradable'] ?? false),
            'correct' => !empty($questionResult['gradable']) ? (bool) ($questionResult['correct'] ?? false) : null,
            'skipped' => (bool) ($questionResult['skipped'] ?? false),
            'selected_answer_ids' => array_values(array_map('intval', (array) ($questionResult['selected_answer_ids'] ?? []))),
            'selected_answers' => array_values(array_map('sanitize_text_field', (array) ($review['selected_answers'] ?? []))),
            'correct_answers' => $correctAnswers,
            'explanation' => wp_kses_post((string) ($review['explanation'] ?? '')),
        ];
    }
}

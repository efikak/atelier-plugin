<?php

declare(strict_types=1);

namespace {
    if (!function_exists('absint')) {
        function absint(mixed $value): int
        {
            return abs((int) $value);
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(mixed $value): string
        {
            return trim(strip_tags((string) $value));
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(mixed $value): string
        {
            return filter_var((string) $value, FILTER_SANITIZE_URL) ?: '';
        }
    }
    if (!function_exists('wp_kses_post')) {
        function wp_kses_post(mixed $value): string
        {
            return (string) $value;
        }
    }
}

namespace WPQuizStudio\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use WPQuizStudio\Service\QuestionFeedback;

    final class QuestionFeedbackTest extends TestCase
    {
        public function testWrongAnswerReturnsCorrectAnswerThenExplanationData(): void
        {
            $quiz = [
                'questions' => [[
                    'id' => 10,
                    'type' => 'multiple_choice',
                    'content' => ['title' => 'Ποια είναι η πρωτεύουσα;'],
                    'settings' => ['explanation' => 'Η Αθήνα είναι η πρωτεύουσα της Ελλάδας.'],
                    'answers' => [
                        ['id' => 1, 'is_correct' => true, 'content' => ['text' => 'Αθήνα']],
                        ['id' => 2, 'is_correct' => false, 'content' => ['text' => 'Πάτρα']],
                    ],
                ]],
            ];

            $feedback = (new QuestionFeedback())->evaluate($quiz, 10, 2);

            self::assertNotNull($feedback);
            self::assertFalse($feedback['correct']);
            self::assertSame('Αθήνα', $feedback['correct_answers'][0]['text']);
            self::assertSame('Η Αθήνα είναι η πρωτεύουσα της Ελλάδας.', $feedback['explanation']);
        }

        public function testMultipleAnswersAreOrderIndependent(): void
        {
            $quiz = [
                'questions' => [[
                    'id' => 20,
                    'type' => 'multiple_answers',
                    'content' => ['title' => 'Επιλέξτε δύο'],
                    'settings' => ['explanation' => ''],
                    'answers' => [
                        ['id' => 3, 'is_correct' => true, 'content' => ['text' => 'Α']],
                        ['id' => 4, 'is_correct' => true, 'content' => ['text' => 'Β']],
                    ],
                ]],
            ];

            $feedback = (new QuestionFeedback())->evaluate($quiz, 20, [4, 3]);

            self::assertNotNull($feedback);
            self::assertTrue($feedback['correct']);
        }
    }
}

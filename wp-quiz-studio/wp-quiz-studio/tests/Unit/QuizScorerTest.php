<?php

declare(strict_types=1);

namespace WPQuizStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPQuizStudio\Service\QuizScorer;

final class QuizScorerTest extends TestCase
{
    public function testScoresSingleMultipleAndOpenTextQuestions(): void
    {
        $quiz = [
            'settings' => [
                'results' => [
                    ['min' => 3, 'max' => 3, 'title' => 'Perfect'],
                ],
            ],
            'questions' => [
                [
                    'id' => 10,
                    'type' => 'multiple_choice',
                    'answers' => [
                        ['id' => 1, 'is_correct' => true, 'score' => 1, 'content' => ['text' => 'A']],
                        ['id' => 2, 'is_correct' => false, 'score' => 0, 'content' => ['text' => 'B']],
                    ],
                ],
                [
                    'id' => 20,
                    'type' => 'multiple_answers',
                    'answers' => [
                        ['id' => 3, 'is_correct' => true, 'score' => 0.5, 'content' => ['text' => 'A']],
                        ['id' => 4, 'is_correct' => true, 'score' => 0.5, 'content' => ['text' => 'B']],
                    ],
                ],
                [
                    'id' => 30,
                    'type' => 'open_text',
                    'answers' => [
                        ['id' => 5, 'is_correct' => true, 'score' => 1, 'content' => ['text' => 'Athens']],
                    ],
                ],
            ],
        ];

        $result = (new QuizScorer())->score($quiz, [
            '10' => 1,
            '20' => [3, 4],
            '30' => '  ATHENS ',
        ]);

        self::assertSame(3.0, $result['score']);
        self::assertSame(3, $result['correct']);
        self::assertSame(3, $result['total']);
        self::assertSame('Perfect', $result['result']['title']);
    }
    public function testExcludesConditionallyHiddenQuestions(): void
    {
        $quiz = [
            'settings' => ['results' => []],
            'questions' => [
                [
                    'id' => 10,
                    'type' => 'multiple_choice',
                    'answers' => [
                        ['id' => 1, 'is_correct' => true, 'score' => 1, 'content' => ['text' => 'A']],
                    ],
                ],
                [
                    'id' => 20,
                    'type' => 'multiple_choice',
                    'answers' => [
                        ['id' => 2, 'is_correct' => true, 'score' => 1, 'content' => ['text' => 'B']],
                    ],
                ],
            ],
        ];

        $result = (new QuizScorer())->score($quiz, ['10' => 1], [], [20]);

        self::assertSame(1.0, $result['score']);
        self::assertSame(1, $result['correct']);
        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['question_results']);
    }

}

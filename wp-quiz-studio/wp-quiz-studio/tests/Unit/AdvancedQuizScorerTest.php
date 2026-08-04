<?php

declare(strict_types=1);

namespace WPQuizStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPQuizStudio\Service\QuizScorer;

final class AdvancedQuizScorerTest extends TestCase
{
    public function testAdvancedQuestionEnginesAndPassScore(): void
    {
        $quiz = [
            'quiz_type' => 'knowledge',
            'settings' => ['show_pass_fail' => true, 'pass_score' => 5, 'results' => []],
            'questions' => [
                ['id' => 1, 'type' => 'slider', 'content' => ['title' => 'Slider'], 'settings' => ['correct_min' => 4, 'correct_max' => 6, 'points' => 2], 'answers' => []],
                ['id' => 2, 'type' => 'numeric', 'content' => ['title' => 'Numeric'], 'settings' => ['numeric_answer' => 10, 'numeric_tolerance' => .5, 'points' => 1], 'answers' => []],
                ['id' => 3, 'type' => 'ordering', 'content' => ['title' => 'Order'], 'settings' => ['points' => 1], 'answers' => [
                    ['id' => 31, 'content' => ['text' => 'One']], ['id' => 32, 'content' => ['text' => 'Two']],
                ]],
                ['id' => 4, 'type' => 'matching', 'content' => ['title' => 'Match'], 'settings' => ['points' => 1], 'answers' => [
                    ['id' => 41, 'content' => ['text' => 'A', 'match_text' => '1']], ['id' => 42, 'content' => ['text' => 'B', 'match_text' => '2']],
                ]],
            ],
        ];

        $result = (new QuizScorer())->score($quiz, [
            '1' => 5, '2' => 10.3, '3' => [31, 32], '4' => ['41' => 41, '42' => 42],
        ]);

        self::assertSame(5.0, $result['score']);
        self::assertSame(4, $result['correct']);
        self::assertTrue($result['pass']);
        self::assertCount(4, $result['review']);
    }

    public function testPersonalityWeightsSelectWinningProfile(): void
    {
        $quiz = [
            'quiz_type' => 'personality',
            'settings' => ['personality_profiles' => [
                ['key' => 'creative', 'title' => 'Creative'], ['key' => 'analytical', 'title' => 'Analytical'],
            ]],
            'questions' => [[
                'id' => 10, 'type' => 'multiple_choice', 'content' => ['title' => 'Profile'], 'settings' => [],
                'answers' => [
                    ['id' => 101, 'content' => ['text' => 'A', 'personality_weights' => ['creative' => 2]]],
                    ['id' => 102, 'content' => ['text' => 'B', 'personality_weights' => ['analytical' => 3]]],
                ],
            ]],
        ];

        $result = (new QuizScorer())->score($quiz, ['10' => 102]);
        self::assertSame('analytical', $result['result']['key']);
        self::assertSame(100.0, $result['result']['percentage']);
    }
}

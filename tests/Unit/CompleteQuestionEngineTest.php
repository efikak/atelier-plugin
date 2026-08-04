<?php

declare(strict_types=1);

namespace WPQuizStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPQuizStudio\Service\QuizScorer;

final class CompleteQuestionEngineTest extends TestCase
{
    private function answer(int $id, string $text, bool $correct = false, float $score = 0, string $match = ''): array
    {
        return ['id' => $id, 'is_correct' => $correct, 'score' => $score, 'content' => ['text' => $text, 'match_text' => $match]];
    }

    public function testEverySupportedQuestionTypeIsScored(): void
    {
        $a = fn (int $id, string $text, bool $correct = false, float $score = 0, string $match = ''): array => $this->answer($id, $text, $correct, $score, $match);
        $quiz = [
            'quiz_type' => 'knowledge',
            'settings' => ['results' => []],
            'questions' => [
                ['id'=>1,'type'=>'multiple_choice','content'=>['title'=>'MC'],'settings'=>['points'=>1],'answers'=>[$a(11,'A',true,1),$a(12,'B')]],
                ['id'=>2,'type'=>'multiple_answers','content'=>['title'=>'MA'],'settings'=>['points'=>2],'answers'=>[$a(21,'A',true,1),$a(22,'B',true,1),$a(23,'C')]],
                ['id'=>3,'type'=>'true_false','content'=>['title'=>'TF'],'settings'=>['points'=>1],'answers'=>[$a(31,'Σωστό',true,1),$a(32,'Λάθος')]],
                ['id'=>4,'type'=>'image_choice','content'=>['title'=>'Image'],'settings'=>['points'=>1],'answers'=>[$a(41,'A',true,1),$a(42,'B')]],
                ['id'=>5,'type'=>'poll','content'=>['title'=>'Poll'],'settings'=>[],'answers'=>[$a(51,'A'),$a(52,'B')]],
                ['id'=>6,'type'=>'open_text','content'=>['title'=>'Text'],'settings'=>['points'=>1,'text_ignore_accents'=>true],'answers'=>[$a(61,'Αθήνα',true,1)]],
                ['id'=>7,'type'=>'slider','content'=>['title'=>'Slider'],'settings'=>['points'=>1,'correct_min'=>4,'correct_max'=>6],'answers'=>[]],
                ['id'=>8,'type'=>'numeric','content'=>['title'=>'Numeric'],'settings'=>['points'=>1,'numeric_answer'=>10,'numeric_tolerance'=>.5],'answers'=>[]],
                ['id'=>9,'type'=>'rating','content'=>['title'=>'Rating'],'settings'=>['points'=>1,'correct_min'=>4,'correct_max'=>5,'rating_max'=>5],'answers'=>[]],
                ['id'=>10,'type'=>'ordering','content'=>['title'=>'Order'],'settings'=>['points'=>1],'answers'=>[$a(101,'1'),$a(102,'2'),$a(103,'3')]],
                ['id'=>11,'type'=>'ranking','content'=>['title'=>'Rank'],'settings'=>['points'=>1],'answers'=>[$a(111,'1'),$a(112,'2')]],
                ['id'=>12,'type'=>'matching','content'=>['title'=>'Match'],'settings'=>['points'=>1],'answers'=>[$a(121,'A',false,0,'1'),$a(122,'B',false,0,'2')]],
            ],
        ];

        $result = (new QuizScorer())->score($quiz, [
            '1'=>11,'2'=>[21,22],'3'=>31,'4'=>41,'5'=>52,'6'=>'ΑΘΗΝΑ!',
            '7'=>5,'8'=>10.2,'9'=>5,'10'=>[101,102,103],'11'=>[111,112],
            '12'=>['121'=>121,'122'=>122],
        ]);

        self::assertSame(11, $result['correct']);
        self::assertSame(11, $result['total']);
        self::assertSame(12.0, $result['score']);
        self::assertCount(12, $result['question_results']);
    }

    public function testPartialScoringWorksForMultiOrderAndMatching(): void
    {
        $a = fn (int $id, string $text, bool $correct = false, float $score = 0, string $match = ''): array => $this->answer($id, $text, $correct, $score, $match);
        $quiz = [
            'quiz_type' => 'knowledge',
            'settings' => [],
            'questions' => [
                ['id'=>1,'type'=>'multiple_answers','content'=>['title'=>'Multi'],'settings'=>['points'=>2,'multiple_scoring'=>'partial'],'answers'=>[$a(11,'A',true,1),$a(12,'B',true,1),$a(13,'C')]],
                ['id'=>2,'type'=>'ordering','content'=>['title'=>'Order'],'settings'=>['points'=>3,'order_scoring'=>'partial'],'answers'=>[$a(21,'A'),$a(22,'B'),$a(23,'C')]],
                ['id'=>3,'type'=>'matching','content'=>['title'=>'Match'],'settings'=>['points'=>2,'matching_scoring'=>'partial'],'answers'=>[$a(31,'A',false,0,'1'),$a(32,'B',false,0,'2')]],
            ],
        ];

        $result = (new QuizScorer())->score($quiz, [
            '1'=>[11],
            '2'=>[21,23,22],
            '3'=>['31'=>31,'32'=>31],
        ]);

        self::assertSame(3.0, $result['score']); // 1 + 1 correct position + 1 correct match.
        self::assertSame(0, $result['correct']);
    }
}

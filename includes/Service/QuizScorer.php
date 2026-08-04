<?php

declare(strict_types=1);

namespace WPQuizStudio\Service;

/**
 * Scores every Quiz Atelier question engine on the server.
 *
 * Correct-answer data is never required by the browser. The scorer supports
 * exact and partial scoring while keeping a consistent review payload for the
 * player, analytics and immediate feedback endpoint.
 */
final class QuizScorer
{
    public function score(array $quiz, array $responses, array $timings = [], array $excludedQuestionIds = []): array
    {
        $score = 0.0;
        $maxScore = 0.0;
        $correctCount = 0;
        $gradableCount = 0;
        $questionResults = [];
        $review = [];
        $profileScores = [];
        $excludedQuestionIds = array_map('intval', $excludedQuestionIds);
        $isPersonality = (string) ($quiz['quiz_type'] ?? 'knowledge') === 'personality';

        foreach ((array) ($quiz['settings']['personality_profiles'] ?? []) as $profile) {
            $key = sanitize_key((string) ($profile['key'] ?? ''));
            if ($key !== '') {
                $profileScores[$key] = 0.0;
            }
        }

        foreach ((array) ($quiz['questions'] ?? []) as $question) {
            $questionId = (int) ($question['id'] ?? 0);
            if (!$questionId || in_array($questionId, $excludedQuestionIds, true)) {
                continue;
            }

            $response = $responses[(string) $questionId] ?? null;
            $evaluation = $this->evaluateQuestion((array) $question, $response, $isPersonality);
            $evaluation['time'] = max(0.0, (float) ($timings[(string) $questionId] ?? 0));
            $evaluation['question_id'] = $questionId;

            if ($evaluation['gradable']) {
                $gradableCount++;
                $maxScore += (float) $evaluation['max_score'];
                if ($evaluation['correct']) {
                    $correctCount++;
                }
            }
            $score += (float) $evaluation['score'];

            foreach ((array) $evaluation['personality_weights'] as $profileKey => $weight) {
                $profileScores[$profileKey] = (float) ($profileScores[$profileKey] ?? 0) + (float) $weight;
            }

            $questionResults[] = [
                'question_id' => $questionId,
                'correct' => (bool) $evaluation['correct'],
                'skipped' => (bool) $evaluation['skipped'],
                'score' => round((float) $evaluation['score'], 2),
                'max_score' => round((float) $evaluation['max_score'], 2),
                'gradable' => (bool) $evaluation['gradable'],
                'time' => (float) $evaluation['time'],
                'selected_answer_ids' => array_values(array_map('intval', (array) $evaluation['selected_answer_ids'])),
                'response_value' => $evaluation['response_value'],
            ];
            $review[] = $evaluation['review'];
        }

        $score = round($score, 2);
        $maxScore = round($maxScore, 2);
        $personality = $isPersonality ? $this->matchPersonality($quiz, $profileScores) : null;
        $result = $personality ?: $this->matchResult((array) ($quiz['settings']['results'] ?? []), $score);
        $passScore = (float) ($quiz['settings']['pass_score'] ?? 0);
        $pass = !empty($quiz['settings']['show_pass_fail']) ? $score >= $passScore : null;

        return [
            'score' => $score,
            'max_score' => $maxScore,
            'correct' => $correctCount,
            'total' => $gradableCount,
            'question_results' => $questionResults,
            'review' => $review,
            'result' => $result,
            'pass' => $pass,
            'pass_score' => $passScore,
            'personality_scores' => array_map(static fn (float $value): float => round($value, 2), $profileScores),
        ];
    }

    /** @return array<string,mixed> */
    private function evaluateQuestion(array $question, mixed $response, bool $isPersonality): array
    {
        $type = (string) ($question['type'] ?? 'multiple_choice');
        $answers = array_values((array) ($question['answers'] ?? []));
        $settings = (array) ($question['settings'] ?? []);
        $isUngraded = $type === 'poll' || $isPersonality;
        $isSkipped = $this->isEmptyResponse($response);
        $isCorrect = false;
        $questionScore = 0.0;
        $maxScore = 0.0;
        $selectedIds = [];
        $responseValue = null;
        $selectedLabels = [];
        $correctLabels = [];
        $personalityWeights = [];

        if (in_array($type, ['slider', 'numeric', 'rating'], true)) {
            $value = is_numeric($response) ? (float) $response : null;
            $responseValue = $value;
            $isSkipped = $value === null;
            $points = max(0.0, (float) ($settings['points'] ?? 1));
            $maxScore = $points;

            if ($type === 'numeric') {
                $expected = (float) ($settings['numeric_answer'] ?? 0);
                $tolerance = max(0.0, (float) ($settings['numeric_tolerance'] ?? 0));
                $isCorrect = !$isSkipped && abs((float) $value - $expected) <= $tolerance;
                $correctLabels[] = $tolerance > 0
                    ? sprintf('%s ± %s', $this->formatNumber($expected), $this->formatNumber($tolerance))
                    : $this->formatNumber($expected);
            } else {
                $defaultMinimum = $type === 'rating' ? 1.0 : (float) ($settings['slider_min'] ?? 0);
                $defaultMaximum = $type === 'rating'
                    ? (float) ($settings['rating_max'] ?? 5)
                    : (float) ($settings['slider_max'] ?? 100);
                $minimum = (float) ($settings['correct_min'] ?? $defaultMinimum);
                $maximum = (float) ($settings['correct_max'] ?? $defaultMaximum);
                $isCorrect = !$isSkipped && (float) $value >= $minimum && (float) $value <= $maximum;
                $correctLabels[] = $minimum === $maximum
                    ? $this->formatNumber($minimum)
                    : sprintf('%s – %s', $this->formatNumber($minimum), $this->formatNumber($maximum));
            }

            if (!$isSkipped) {
                $selectedLabels[] = $this->formatNumber((float) $value);
            }
            $questionScore = $isCorrect ? $points : 0.0;
        } elseif (in_array($type, ['ordering', 'ranking'], true)) {
            $selectedIds = array_values(array_unique(array_map('intval', (array) $response)));
            $correctIds = array_values(array_map(static fn (array $answer): int => (int) ($answer['id'] ?? 0), $answers));
            $isSkipped = $selectedIds === [];
            $isCorrect = !$isSkipped && $selectedIds === $correctIds;
            $points = max(0.0, (float) ($settings['points'] ?? 1));
            $maxScore = $points;

            if (!$isSkipped && (string) ($settings['order_scoring'] ?? 'exact') === 'partial' && $correctIds !== []) {
                $correctPositions = 0;
                foreach ($correctIds as $position => $answerId) {
                    if (($selectedIds[$position] ?? 0) === $answerId) {
                        $correctPositions++;
                    }
                }
                $questionScore = round(($correctPositions / count($correctIds)) * $points, 4);
            } else {
                $questionScore = $isCorrect ? $points : 0.0;
            }
            $selectedLabels = $this->labelsForIds($answers, $selectedIds);
            $correctLabels = $this->labelsForIds($answers, $correctIds);
        } elseif ($type === 'matching') {
            $mapping = is_array($response) ? $response : [];
            $isSkipped = $mapping === [];
            $correctPairs = 0;

            foreach ($answers as $answer) {
                $leftId = (int) ($answer['id'] ?? 0);
                $selectedId = absint($mapping[(string) $leftId] ?? $mapping[$leftId] ?? 0);
                $left = (string) ($answer['content']['text'] ?? '');
                $correctRight = (string) ($answer['content']['match_text'] ?? '');
                $selectedRight = '';

                if ($selectedId === $leftId && $selectedId > 0) {
                    $correctPairs++;
                }
                foreach ($answers as $candidate) {
                    if ((int) ($candidate['id'] ?? 0) === $selectedId) {
                        $selectedRight = (string) ($candidate['content']['match_text'] ?? '');
                        break;
                    }
                }
                if ($selectedId) {
                    $selectedIds[] = $selectedId;
                    $selectedLabels[] = trim($left . ' → ' . $selectedRight);
                }
                $correctLabels[] = trim($left . ' → ' . $correctRight);
            }

            $isCorrect = !$isSkipped && $answers !== [] && $correctPairs === count($answers);
            $points = max(0.0, (float) ($settings['points'] ?? 1));
            $maxScore = $points;
            if (!$isSkipped && (string) ($settings['matching_scoring'] ?? 'exact') === 'partial' && $answers !== []) {
                $questionScore = round(($correctPairs / count($answers)) * $points, 4);
            } else {
                $questionScore = $isCorrect ? $points : 0.0;
            }
            $responseValue = $mapping;
        } elseif ($type === 'multiple_answers') {
            $selectedIds = array_values(array_unique(array_map('intval', (array) $response)));
            sort($selectedIds);
            $correctIds = [];
            $scoreById = [];

            foreach ($answers as $answer) {
                $answerId = (int) ($answer['id'] ?? 0);
                if (!empty($answer['is_correct'])) {
                    $correctIds[] = $answerId;
                    $scoreById[$answerId] = max(0.0, (float) ($answer['score'] ?? 0));
                }
            }
            sort($correctIds);
            $configuredPoints = max(0.0, (float) ($settings['points'] ?? 1));
            $maxScore = array_sum($scoreById);
            if ($maxScore <= 0 && $correctIds !== []) {
                $maxScore = $configuredPoints;
                $equal = $configuredPoints / count($correctIds);
                foreach ($correctIds as $id) {
                    $scoreById[$id] = $equal;
                }
            }

            $isSkipped = $selectedIds === [];
            $isCorrect = !$isSkipped && $selectedIds === $correctIds;
            if ((string) ($settings['multiple_scoring'] ?? 'exact') === 'partial' && !$isSkipped) {
                $earned = 0.0;
                foreach ($selectedIds as $id) {
                    if (isset($scoreById[$id])) {
                        $earned += $scoreById[$id];
                    } elseif ($answers !== []) {
                        // A wrong selection removes one equal share, but never creates a negative score.
                        $earned -= $maxScore / max(1, count($correctIds));
                    }
                }
                $questionScore = min($maxScore, max(0.0, $earned));
            } else {
                $questionScore = $isCorrect ? $maxScore : 0.0;
            }
            $selectedLabels = $this->labelsForIds($answers, $selectedIds);
            $correctLabels = $this->labelsForIds($answers, $correctIds);
        } elseif ($type === 'open_text') {
            $candidate = $this->normaliseText((string) $response, $settings);
            $isSkipped = $candidate === '';
            if (!$isSkipped) {
                $selectedLabels[] = sanitize_text_field((string) $response);
            }

            foreach ($answers as $answer) {
                if (empty($answer['is_correct'])) {
                    continue;
                }
                $label = (string) ($answer['content']['text'] ?? '');
                $correctLabels[] = $label;
                $answerScore = max(0.0, (float) ($answer['score'] ?? 0));
                $maxScore = max($maxScore, $answerScore);
                if ($candidate !== '' && $candidate === $this->normaliseText($label, $settings)) {
                    $isCorrect = true;
                    $questionScore = $answerScore;
                }
            }
            if ($maxScore <= 0) {
                $maxScore = max(0.0, (float) ($settings['points'] ?? 1));
                if ($isCorrect) {
                    $questionScore = $maxScore;
                }
            }
            $responseValue = $isSkipped ? null : '[text]';
        } else {
            // Single-choice engines: multiple choice, true/false, image choice and poll.
            $selectedId = absint($response);
            if ($selectedId > 0) {
                $selectedIds[] = $selectedId;
            }

            $fallbackPoints = max(0.0, (float) ($settings['points'] ?? 1));
            foreach ($answers as $answer) {
                $answerId = (int) ($answer['id'] ?? 0);
                if (!empty($answer['is_correct'])) {
                    $correctLabels[] = $this->answerLabel($answer);
                    $maxScore = max($maxScore, max(0.0, (float) ($answer['score'] ?? 0)));
                }
                if ($answerId === $selectedId) {
                    $selectedLabels[] = $this->answerLabel($answer);
                    $isCorrect = !empty($answer['is_correct']);
                    if ($isCorrect) {
                        $questionScore = max(0.0, (float) ($answer['score'] ?? 0));
                    }
                    foreach ((array) ($answer['content']['personality_weights'] ?? []) as $profileKey => $weight) {
                        $personalityWeights[sanitize_key((string) $profileKey)] = (float) $weight;
                    }
                }
            }
            if (!$isUngraded && $maxScore <= 0) {
                $maxScore = $fallbackPoints;
                if ($isCorrect) {
                    $questionScore = $fallbackPoints;
                }
            }
            $isSkipped = $selectedId === 0;
            if ($isUngraded) {
                $maxScore = 0.0;
                $questionScore = 0.0;
            }
        }

        if ($isPersonality && $type === 'multiple_answers') {
            foreach ($answers as $answer) {
                if (!in_array((int) ($answer['id'] ?? 0), $selectedIds, true)) {
                    continue;
                }
                foreach ((array) ($answer['content']['personality_weights'] ?? []) as $profileKey => $weight) {
                    $profileKey = sanitize_key((string) $profileKey);
                    $personalityWeights[$profileKey] = (float) ($personalityWeights[$profileKey] ?? 0) + (float) $weight;
                }
            }
        }

        return [
            'correct' => $isCorrect,
            'skipped' => $isSkipped,
            'score' => $questionScore,
            'max_score' => $maxScore,
            'gradable' => !$isUngraded,
            'selected_answer_ids' => $selectedIds,
            'response_value' => $responseValue,
            'personality_weights' => $personalityWeights,
            'review' => [
                'question_id' => (int) ($question['id'] ?? 0),
                'question' => sanitize_text_field((string) ($question['content']['title'] ?? '')),
                'type' => $type,
                'correct' => $isUngraded ? null : $isCorrect,
                'skipped' => $isSkipped,
                'selected_answers' => array_values(array_filter(array_map('sanitize_text_field', $selectedLabels), static fn (string $value): bool => $value !== '')),
                'correct_answers' => array_values(array_filter(array_map('sanitize_text_field', $correctLabels), static fn (string $value): bool => $value !== '')),
                'explanation' => wp_kses_post((string) ($settings['explanation'] ?? '')),
            ],
        ];
    }

    private function isEmptyResponse(mixed $response): bool
    {
        if ($response === null || $response === '' || $response === false) {
            return true;
        }
        return is_array($response) && $response === [];
    }

    private function matchPersonality(array $quiz, array $scores): ?array
    {
        $profiles = array_values((array) ($quiz['settings']['personality_profiles'] ?? []));
        if ($profiles === []) {
            return null;
        }
        arsort($scores, SORT_NUMERIC);
        $winnerKey = (string) array_key_first($scores);
        $max = $winnerKey !== '' ? (float) ($scores[$winnerKey] ?? 0) : 0.0;
        $ties = array_keys(array_filter($scores, static fn (float $value): bool => abs($value - $max) < 0.0001));
        $winner = null;
        foreach ($profiles as $profile) {
            if ((string) ($profile['key'] ?? '') === $winnerKey) {
                $winner = $profile;
                break;
            }
        }
        if (!$winner) {
            $winner = $profiles[0];
            $winnerKey = (string) ($winner['key'] ?? '');
        }
        $total = array_sum(array_map(static fn (float $value): float => max(0.0, $value), $scores));
        $tieProfiles = [];
        if ((string) ($quiz['settings']['personality_tie_strategy'] ?? 'first') === 'all' && count($ties) > 1) {
            foreach ($profiles as $profile) {
                $profileKey = (string) ($profile['key'] ?? '');
                if (in_array($profileKey, $ties, true)) {
                    $tieProfiles[] = [
                        'key' => $profileKey,
                        'title' => (string) ($profile['title'] ?? 'Αποτέλεσμα'),
                        'percentage' => $total > 0 ? round((max(0.0, (float) ($scores[$profileKey] ?? 0)) / $total) * 100, 1) : 0,
                    ];
                }
            }
        }

        return [
            'key' => $winnerKey,
            'title' => (string) ($winner['title'] ?? 'Αποτέλεσμα'),
            'description' => (string) ($winner['description'] ?? ''),
            'image_url' => (string) ($winner['image_url'] ?? ''),
            'cta_label' => (string) ($winner['cta_label'] ?? ''),
            'cta_url' => (string) ($winner['cta_url'] ?? ''),
            'percentage' => $total > 0 ? round((max(0.0, (float) ($scores[$winnerKey] ?? 0)) / $total) * 100, 1) : 0,
            'ties' => $ties,
            'tie_profiles' => $tieProfiles,
            'profile_scores' => $scores,
        ];
    }

    private function matchResult(array $ranges, float $score): ?array
    {
        foreach ($ranges as $range) {
            $minimum = (float) ($range['min'] ?? 0);
            $maximum = (float) ($range['max'] ?? PHP_FLOAT_MAX);
            if ($score >= $minimum && $score <= $maximum) {
                return [
                    'title' => (string) ($range['title'] ?? 'Ολοκληρώθηκε'),
                    'description' => (string) ($range['description'] ?? ''),
                    'image_url' => (string) ($range['image_url'] ?? ''),
                    'cta_label' => (string) ($range['cta_label'] ?? ''),
                    'cta_url' => (string) ($range['cta_url'] ?? ''),
                ];
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $answers @param list<int> $ids @return list<string> */
    private function labelsForIds(array $answers, array $ids): array
    {
        $labels = [];
        foreach ($ids as $id) {
            foreach ($answers as $answer) {
                if ((int) ($answer['id'] ?? 0) === (int) $id) {
                    $labels[] = $this->answerLabel($answer);
                    break;
                }
            }
        }
        return $labels;
    }

    private function answerLabel(array $answer): string
    {
        $content = (array) ($answer['content'] ?? []);
        return trim((string) ($content['emoji'] ?? '') . ' ' . (string) ($content['text'] ?? ''));
    }

    private function normaliseText(string $value, array $settings = []): string
    {
        $value = trim($value);
        if (empty($settings['text_case_sensitive'])) {
            if (function_exists('mb_strtolower')) {
                $value = mb_strtolower($value, 'UTF-8');
            } else {
                $value = strtolower(strtr($value, [
                    'Α'=>'α','Β'=>'β','Γ'=>'γ','Δ'=>'δ','Ε'=>'ε','Ζ'=>'ζ','Η'=>'η','Θ'=>'θ','Ι'=>'ι','Κ'=>'κ','Λ'=>'λ','Μ'=>'μ',
                    'Ν'=>'ν','Ξ'=>'ξ','Ο'=>'ο','Π'=>'π','Ρ'=>'ρ','Σ'=>'σ','Τ'=>'τ','Υ'=>'υ','Φ'=>'φ','Χ'=>'χ','Ψ'=>'ψ','Ω'=>'ω',
                    'Ά'=>'ά','Έ'=>'έ','Ή'=>'ή','Ί'=>'ί','Ό'=>'ό','Ύ'=>'ύ','Ώ'=>'ώ','Ϊ'=>'ϊ','Ϋ'=>'ϋ',
                ]));
            }
        }
        if (!array_key_exists('text_ignore_accents', $settings) || !empty($settings['text_ignore_accents'])) {
            $value = remove_accents($value);
        }
        if (!array_key_exists('text_ignore_punctuation', $settings) || !empty($settings['text_ignore_punctuation'])) {
            $value = preg_replace('/[^\p{L}\p{N}\s.-]/u', '', $value) ?: $value;
        }
        return preg_replace('/\s+/u', ' ', trim($value)) ?: '';
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}

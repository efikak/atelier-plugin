<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

/** Reads date-filtered overview, funnel, audience, result and question analytics. */
final class AnalyticsRepository
{
    private string $analytics;
    private string $results;
    private string $sessions;
    private string $questions;
    private string $answers;
    private string $quizzes;

    public function __construct()
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'wpqs_';
        $this->analytics = $prefix . 'analytics';
        $this->results = $prefix . 'results';
        $this->sessions = $prefix . 'sessions';
        $this->questions = $prefix . 'questions';
        $this->answers = $prefix . 'answers';
        $this->quizzes = $prefix . 'quizzes';
    }

    /** @param array{from?:string,to?:string,group?:string,quiz_ids?:list<int>} $filters */
    public function dashboard(?int $quizId = null, array $filters = []): array
    {
        global $wpdb;
        $range = $this->normaliseRange($filters);
        $quizIds = array_values(array_filter(array_map('intval', (array) ($filters['quiz_ids'] ?? []))));
        [$eventWhere, $eventParams] = $this->where('created_at', $range['from_sql'], $range['to_sql'], $quizId, $quizIds);
        [$resultWhere, $resultParams] = $this->where('completed_at', $range['from_sql'], $range['to_sql'], $quizId, $quizIds);
        [$sessionWhere, $sessionParams] = $this->where('started_at', $range['from_sql'], $range['to_sql'], $quizId, $quizIds);

        $eventsSql = "SELECT quiz_id,event_type,question_id,session_id,metadata,created_at FROM {$this->analytics} {$eventWhere} ORDER BY created_at ASC";
        $events = $wpdb->get_results($wpdb->prepare($eventsSql, ...$eventParams), ARRAY_A) ?: [];
        $resultsSql = "SELECT quiz_id,session_id,score,payload,completed_at FROM {$this->results} {$resultWhere} ORDER BY completed_at DESC";
        $results = $wpdb->get_results($wpdb->prepare($resultsSql, ...$resultParams), ARRAY_A) ?: [];
        $sessionsSql = "SELECT id,quiz_id,started_at,completed_at,context FROM {$this->sessions} {$sessionWhere} ORDER BY started_at DESC";
        $sessions = $wpdb->get_results($wpdb->prepare($sessionsSql, ...$sessionParams), ARRAY_A) ?: [];

        $counts = ['view' => 0, 'start' => 0, 'question_view' => 0, 'complete' => 0, 'share' => 0, 'restart' => 0, 'answer' => 0];
        $uniqueByEvent = [];
        $contexts = [];
        $eventMetadata = [];
        foreach ($events as $event) {
            $type = (string) $event['event_type'];
            $counts[$type] = (int) ($counts[$type] ?? 0) + 1;
            $sessionId = (string) ($event['session_id'] ?? '');
            if ($sessionId !== '') {
                $uniqueByEvent[$type][$sessionId] = true;
            }
            $metadata = $this->decode((string) ($event['metadata'] ?? ''));
            $eventMetadata[] = [$event, $metadata];
            if ($sessionId !== '' && in_array($type, ['start', 'view'], true)) {
                if (!isset($contexts[$sessionId]) || $type === 'start') {
                    $contexts[$sessionId] = $metadata;
                }
            }
        }

        foreach ($sessions as $session) {
            $sessionId = (string) $session['id'];
            $context = $this->decode((string) ($session['context'] ?? ''));
            if ($sessionId !== '' && !isset($contexts[$sessionId])) {
                $contexts[$sessionId] = $context;
            }
        }

        $views = $counts['view'];
        $starts = $counts['start'];
        $completions = $counts['complete'];
        $shares = $counts['share'];
        $averageScore = $results !== [] ? array_sum(array_map(static fn (array $row): float => (float) $row['score'], $results)) / count($results) : 0.0;
        $durations = [];
        $sessionMap = [];
        foreach ($sessions as $session) {
            $sessionMap[(string) $session['id']] = $session;
            if (!empty($session['completed_at'])) {
                $start = strtotime((string) $session['started_at'] . ' UTC');
                $end = strtotime((string) $session['completed_at'] . ' UTC');
                if ($start !== false && $end !== false && $end >= $start) {
                    $durations[] = $end - $start;
                }
            }
        }
        $averageTime = $durations !== [] ? array_sum($durations) / count($durations) : 0.0;

        $previous = $this->previousOverview($quizId, $range, $quizIds);
        $overview = [
            'views' => $views,
            'starts' => $starts,
            'completions' => $completions,
            'completion_rate' => $starts > 0 ? round(($completions / $starts) * 100, 1) : 0,
            'start_rate' => $views > 0 ? round(($starts / $views) * 100, 1) : 0,
            'share_rate' => $completions > 0 ? round(($shares / $completions) * 100, 1) : 0,
            'average_score' => round($averageScore, 2),
            'average_time' => round($averageTime, 1),
            'abandoned' => max(0, $starts - $completions),
        ];

        $comparison = [];
        foreach (['views', 'starts', 'completions', 'completion_rate', 'average_score'] as $key) {
            $current = (float) ($overview[$key] ?? 0);
            $old = (float) ($previous[$key] ?? 0);
            $comparison[$key] = $old == 0.0 ? ($current > 0 ? 100.0 : 0.0) : round((($current - $old) / abs($old)) * 100, 1);
        }

        $audience = [
            'devices' => $this->distribution($contexts, 'device', 'Άγνωστο'),
            'browsers' => $this->distribution($contexts, 'browser', 'Άλλο'),
            'operating_systems' => $this->distribution($contexts, 'os', 'Άλλο'),
            'countries' => $this->distribution($contexts, 'country', 'Άγνωστη'),
            'cities' => $this->distribution($contexts, 'city', 'Άγνωστη'),
            'referrers' => $this->distribution($contexts, 'referrer_host', 'Direct'),
            'utm_sources' => $this->distribution($contexts, 'utm_source', 'Χωρίς UTM'),
            'utm_mediums' => $this->distribution($contexts, 'utm_medium', 'Χωρίς UTM'),
            'utm_campaigns' => $this->distribution($contexts, 'utm_campaign', 'Χωρίς UTM'),
            'languages' => $this->distribution($contexts, 'language', 'Άγνωστη'),
            'timezones' => $this->distribution($contexts, 'timezone', 'Άγνωστη'),
        ];

        $resultAnalytics = $this->resultAnalytics($results);
        $questionAnalytics = $quizId ? $this->questionAnalytics($quizId, $eventMetadata, $starts) : [];
        $latest = $this->latestCompletions($results, $sessionMap);
        $timeseries = $this->timeseries($events, $range);
        $questionReached = count((array) ($uniqueByEvent['question_view'] ?? []));
        $funnel = [
            ['key' => 'views', 'label' => __('Προβολές', 'wp-quiz-studio'), 'value' => $views, 'rate' => 100],
            ['key' => 'starts', 'label' => __('Εκκινήσεις', 'wp-quiz-studio'), 'value' => $starts, 'rate' => $views > 0 ? round(($starts / $views) * 100, 1) : 0],
            ['key' => 'question_reached', 'label' => __('Έφτασαν σε ερώτηση', 'wp-quiz-studio'), 'value' => $questionReached, 'rate' => $starts > 0 ? round(($questionReached / $starts) * 100, 1) : 0],
            ['key' => 'completions', 'label' => __('Ολοκληρώσεις', 'wp-quiz-studio'), 'value' => $completions, 'rate' => $starts > 0 ? round(($completions / $starts) * 100, 1) : 0],
            ['key' => 'shares', 'label' => __('Κοινοποιήσεις', 'wp-quiz-studio'), 'value' => $shares, 'rate' => $completions > 0 ? round(($shares / $completions) * 100, 1) : 0],
        ];

        return array_merge($overview, [
            'overview' => $overview,
            'comparison' => $comparison,
            'range' => ['from' => $range['from'], 'to' => $range['to'], 'group' => $range['group'], 'days' => $range['days']],
            'daily' => $timeseries,
            'timeseries' => $timeseries,
            'funnel' => $funnel,
            'questions' => $questionAnalytics,
            'audience' => $audience,
            'result_distribution' => $resultAnalytics['results'],
            'score_distribution' => $resultAnalytics['scores'],
            'pass_distribution' => $resultAnalytics['pass'],
            'latest_completions' => $latest,
            'quiz_breakdown' => $quizId ? [] : $this->quizBreakdown($range, $quizIds),
            'data_notes' => [
                'location' => __('Χώρα και πόλη εμφανίζονται μόνο όταν ο server ή το CDN παρέχει GeoIP headers.', 'wp-quiz-studio'),
                'privacy' => __('Οι απαντήσεις ανοιχτού κειμένου δεν εμφανίζονται στα analytics.', 'wp-quiz-studio'),
            ],
        ]);
    }

    /** @return array{from:string,to:string,from_sql:string,to_sql:string,group:string,days:int,previous_from_sql:string,previous_to_sql:string} */
    private function normaliseRange(array $filters): array
    {
        $to = (string) ($filters['to'] ?? '');
        $from = (string) ($filters['from'] ?? '');
        $toTimestamp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? strtotime($to . ' 23:59:59 UTC') : strtotime(gmdate('Y-m-d') . ' 23:59:59 UTC');
        $toTimestamp = $toTimestamp ?: time();
        $fromTimestamp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? strtotime($from . ' 00:00:00 UTC') : $toTimestamp - (29 * DAY_IN_SECONDS) - 86399;
        $fromTimestamp = $fromTimestamp ?: ($toTimestamp - 29 * DAY_IN_SECONDS);
        if ($fromTimestamp > $toTimestamp) {
            [$fromTimestamp, $toTimestamp] = [$toTimestamp - 86399, $fromTimestamp + 86399];
        }
        $days = max(1, (int) floor(($toTimestamp - $fromTimestamp) / DAY_IN_SECONDS) + 1);
        $group = (string) ($filters['group'] ?? 'day');
        if (!in_array($group, ['day', 'week', 'month'], true)) {
            $group = $days > 180 ? 'month' : ($days > 60 ? 'week' : 'day');
        }
        $period = $toTimestamp - $fromTimestamp + 1;
        return [
            'from' => gmdate('Y-m-d', $fromTimestamp),
            'to' => gmdate('Y-m-d', $toTimestamp),
            'from_sql' => gmdate('Y-m-d H:i:s', $fromTimestamp),
            'to_sql' => gmdate('Y-m-d H:i:s', $toTimestamp),
            'group' => $group,
            'days' => $days,
            'previous_from_sql' => gmdate('Y-m-d H:i:s', $fromTimestamp - $period),
            'previous_to_sql' => gmdate('Y-m-d H:i:s', $fromTimestamp - 1),
        ];
    }

    /** @return array{0:string,1:list<mixed>} */
    private function where(string $column, string $from, string $to, ?int $quizId, array $quizIds = []): array
    {
        $where = "WHERE {$column} >= %s AND {$column} <= %s";
        $params = [$from, $to];
        if ($quizId) {
            $where .= ' AND quiz_id = %d';
            $params[] = $quizId;
        } elseif ($quizIds !== []) {
            $placeholders = implode(',', array_fill(0, count($quizIds), '%d'));
            $where .= " AND quiz_id IN ({$placeholders})";
            array_push($params, ...$quizIds);
        } elseif (array_key_exists('quiz_ids', $GLOBALS) && $quizIds === []) {
            // Reserved for explicit empty scopes; regular calls omit quiz_ids entirely.
        }
        return [$where, $params];
    }

    private function previousOverview(?int $quizId, array $range, array $quizIds = []): array
    {
        global $wpdb;
        [$eventWhere, $params] = $this->where('created_at', $range['previous_from_sql'], $range['previous_to_sql'], $quizId, $quizIds);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT event_type,COUNT(*) total FROM {$this->analytics} {$eventWhere} GROUP BY event_type", ...$params), OBJECT_K) ?: [];
        $views = isset($rows['view']) ? (int) $rows['view']->total : 0;
        $starts = isset($rows['start']) ? (int) $rows['start']->total : 0;
        $completions = isset($rows['complete']) ? (int) $rows['complete']->total : 0;
        [$resultWhere, $resultParams] = $this->where('completed_at', $range['previous_from_sql'], $range['previous_to_sql'], $quizId, $quizIds);
        $averageScore = (float) ($wpdb->get_var($wpdb->prepare("SELECT AVG(score) FROM {$this->results} {$resultWhere}", ...$resultParams)) ?: 0);
        return [
            'views' => $views,
            'starts' => $starts,
            'completions' => $completions,
            'completion_rate' => $starts > 0 ? ($completions / $starts) * 100 : 0,
            'average_score' => $averageScore,
        ];
    }

    /** @param list<array<string,mixed>> $events */
    private function timeseries(array $events, array $range): array
    {
        $groups = [];
        foreach ($events as $event) {
            $timestamp = strtotime((string) $event['created_at'] . ' UTC');
            if ($timestamp === false) {
                continue;
            }
            $key = match ($range['group']) {
                'month' => gmdate('Y-m-01', $timestamp),
                'week' => gmdate('o-\WW', $timestamp),
                default => gmdate('Y-m-d', $timestamp),
            };
            $groups[$key] ??= ['day' => $key, 'views' => 0, 'starts' => 0, 'completions' => 0, 'shares' => 0];
            $field = match ((string) $event['event_type']) {
                'view' => 'views', 'start' => 'starts', 'complete' => 'completions', 'share' => 'shares', default => null,
            };
            if ($field) {
                $groups[$key][$field]++;
            }
        }
        ksort($groups);
        return array_values($groups);
    }

    /** @param array<string,array<string,mixed>> $contexts */
    private function distribution(array $contexts, string $key, string $fallback): array
    {
        $values = [];
        foreach ($contexts as $context) {
            $value = trim((string) ($context[$key] ?? ''));
            $value = $value !== '' ? $value : $fallback;
            $values[$value] = (int) ($values[$value] ?? 0) + 1;
        }
        arsort($values);
        $total = max(1, array_sum($values));
        $rows = [];
        foreach (array_slice($values, 0, 20, true) as $label => $count) {
            $rows[] = ['label' => $label, 'value' => $count, 'percent' => round(($count / $total) * 100, 1)];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $results */
    private function resultAnalytics(array $results): array
    {
        $resultCounts = [];
        $scoreBuckets = ['0–20%' => 0, '21–40%' => 0, '41–60%' => 0, '61–80%' => 0, '81–100%' => 0];
        $pass = ['Επιτυχία' => 0, 'Αποτυχία' => 0, 'Χωρίς βάση' => 0];
        foreach ($results as $row) {
            $payload = $this->decode((string) ($row['payload'] ?? ''));
            $response = (array) ($payload['response'] ?? []);
            $result = (array) ($response['result'] ?? []);
            $title = sanitize_text_field((string) ($result['title'] ?? 'Βασικό αποτέλεσμα')) ?: 'Βασικό αποτέλεσμα';
            $resultCounts[$title] = (int) ($resultCounts[$title] ?? 0) + 1;
            $score = (float) ($response['score'] ?? $row['score'] ?? 0);
            $max = (float) ($response['max_score'] ?? $payload['max_score'] ?? 0);
            $percentage = $max > 0 ? max(0, min(100, ($score / $max) * 100)) : 0;
            $bucket = $percentage <= 20 ? '0–20%' : ($percentage <= 40 ? '21–40%' : ($percentage <= 60 ? '41–60%' : ($percentage <= 80 ? '61–80%' : '81–100%')));
            $scoreBuckets[$bucket]++;
            $passValue = $response['pass'] ?? $payload['pass'] ?? null;
            $pass[$passValue === true ? 'Επιτυχία' : ($passValue === false ? 'Αποτυχία' : 'Χωρίς βάση')]++;
        }
        arsort($resultCounts);
        $toRows = static function (array $values): array {
            $total = max(1, array_sum($values));
            $rows = [];
            foreach ($values as $label => $count) {
                $rows[] = ['label' => (string) $label, 'value' => (int) $count, 'percent' => round(((int) $count / $total) * 100, 1)];
            }
            return $rows;
        };
        return ['results' => $toRows($resultCounts), 'scores' => $toRows($scoreBuckets), 'pass' => $toRows($pass)];
    }

    /** @param list<array{0:array<string,mixed>,1:array<string,mixed>}> $eventMetadata */
    private function questionAnalytics(int $quizId, array $eventMetadata, int $starts): array
    {
        global $wpdb;
        $questions = $wpdb->get_results($wpdb->prepare("SELECT id,position,content FROM {$this->questions} WHERE quiz_id=%d ORDER BY position", $quizId), ARRAY_A) ?: [];
        $answerRows = $wpdb->get_results($wpdb->prepare("SELECT question_id,id,content FROM {$this->answers} WHERE question_id IN (SELECT id FROM {$this->questions} WHERE quiz_id=%d) ORDER BY position", $quizId), ARRAY_A) ?: [];
        $labels = [];
        foreach ($answerRows as $answer) {
            $content = $this->decode((string) $answer['content']);
            $labels[(int) $answer['question_id']][(int) $answer['id']] = (string) ($content['text'] ?? $content['match_text'] ?? ('#' . $answer['id']));
        }
        $stats = [];
        foreach ($eventMetadata as [$event, $metadata]) {
            $questionId = (int) ($event['question_id'] ?? 0);
            if (!$questionId) {
                continue;
            }
            $stats[$questionId] ??= ['answers' => 0, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'time' => 0.0, 'reached_sessions' => [], 'answers_distribution' => []];
            if ((string) $event['event_type'] === 'question_view') {
                $session = (string) ($event['session_id'] ?? '');
                if ($session !== '') {
                    $stats[$questionId]['reached_sessions'][$session] = true;
                }
            }
            if ((string) $event['event_type'] !== 'answer') {
                continue;
            }
            $stats[$questionId]['answers']++;
            if (!empty($metadata['skipped'])) {
                $stats[$questionId]['skipped']++;
            } elseif (!empty($metadata['gradable'])) {
                if (!empty($metadata['correct'])) {
                    $stats[$questionId]['correct']++;
                } else {
                    $stats[$questionId]['wrong']++;
                }
            }
            $stats[$questionId]['time'] += (float) ($metadata['time'] ?? 0);
            foreach ((array) ($metadata['selected_answer_ids'] ?? []) as $answerId) {
                $answerId = (int) $answerId;
                $label = $labels[$questionId][$answerId] ?? ('#' . $answerId);
                $stats[$questionId]['answers_distribution'][$label] = (int) ($stats[$questionId]['answers_distribution'][$label] ?? 0) + 1;
            }
            if (isset($metadata['response_value']) && is_numeric($metadata['response_value'])) {
                $label = (string) round((float) $metadata['response_value'], 2);
                $stats[$questionId]['answers_distribution'][$label] = (int) ($stats[$questionId]['answers_distribution'][$label] ?? 0) + 1;
            }
        }
        $rows = [];
        foreach ($questions as $index => $question) {
            $id = (int) $question['id'];
            $content = $this->decode((string) $question['content']);
            $row = $stats[$id] ?? ['answers' => 0, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'time' => 0.0, 'reached_sessions' => [], 'answers_distribution' => []];
            $answers = max(1, (int) $row['answers']);
            $reached = count((array) $row['reached_sessions']);
            $nextReached = 0;
            if (isset($questions[$index + 1])) {
                $nextId = (int) $questions[$index + 1]['id'];
                $nextReached = count((array) (($stats[$nextId]['reached_sessions'] ?? [])));
            }
            arsort($row['answers_distribution']);
            $distribution = [];
            $distTotal = max(1, array_sum($row['answers_distribution']));
            foreach (array_slice($row['answers_distribution'], 0, 10, true) as $label => $count) {
                $distribution[] = ['label' => $label, 'value' => $count, 'percent' => round(($count / $distTotal) * 100, 1)];
            }
            $rows[] = [
                'id' => $id,
                'position' => (int) $question['position'],
                'title' => (string) ($content['title'] ?? 'Ερώτηση'),
                'answers' => (int) $row['answers'],
                'reached' => $reached,
                'reach_rate' => $starts > 0 ? round(($reached / $starts) * 100, 1) : 0,
                'correct_percent' => round(((int) $row['correct'] / $answers) * 100, 1),
                'wrong_percent' => round(((int) $row['wrong'] / $answers) * 100, 1),
                'skipped_percent' => round(((int) $row['skipped'] / $answers) * 100, 1),
                'average_time' => round((float) $row['time'] / $answers, 1),
                'dropoff' => $reached > 0 && isset($questions[$index + 1]) ? max(0, $reached - $nextReached) : 0,
                'dropoff_percent' => $reached > 0 && isset($questions[$index + 1]) ? round((max(0, $reached - $nextReached) / $reached) * 100, 1) : 0,
                'answer_distribution' => $distribution,
            ];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $results @param array<string,array<string,mixed>> $sessionMap */
    private function latestCompletions(array $results, array $sessionMap): array
    {
        $rows = [];
        foreach (array_slice($results, 0, 50) as $result) {
            $payload = $this->decode((string) ($result['payload'] ?? ''));
            $response = (array) ($payload['response'] ?? []);
            $session = $sessionMap[(string) ($result['session_id'] ?? '')] ?? [];
            $duration = null;
            if (!empty($session['started_at']) && !empty($session['completed_at'])) {
                $start = strtotime((string) $session['started_at'] . ' UTC');
                $end = strtotime((string) $session['completed_at'] . ' UTC');
                if ($start !== false && $end !== false) {
                    $duration = max(0, $end - $start);
                }
            }
            $rows[] = [
                'session_id' => (string) ($result['session_id'] ?? ''),
                'score' => (float) ($response['score'] ?? $result['score'] ?? 0),
                'max_score' => (float) ($response['max_score'] ?? $payload['max_score'] ?? 0),
                'correct' => (int) ($response['correct'] ?? $payload['correct'] ?? 0),
                'total' => (int) ($response['total'] ?? $payload['total'] ?? 0),
                'result' => (string) (($response['result']['title'] ?? '') ?: '—'),
                'pass' => $response['pass'] ?? $payload['pass'] ?? null,
                'duration' => $duration,
                'completed_at' => (string) ($result['completed_at'] ?? ''),
            ];
        }
        return $rows;
    }

    private function quizBreakdown(array $range, array $quizIds = []): array
    {
        global $wpdb;
        $scope = '';
        $params = [$range['from_sql'], $range['to_sql']];
        if ($quizIds !== []) {
            $placeholders = implode(',', array_fill(0, count($quizIds), '%d'));
            $scope = " WHERE q.id IN ({$placeholders})";
            array_push($params, ...$quizIds);
        }
        $sql = $wpdb->prepare(
            "SELECT q.id,q.title,q.status,q.quiz_type,
                SUM(CASE WHEN a.event_type='view' THEN 1 ELSE 0 END) views,
                SUM(CASE WHEN a.event_type='start' THEN 1 ELSE 0 END) starts,
                SUM(CASE WHEN a.event_type='complete' THEN 1 ELSE 0 END) completions
             FROM {$this->quizzes} q
             LEFT JOIN {$this->analytics} a ON a.quiz_id=q.id AND a.created_at >= %s AND a.created_at <= %s
             {$scope}
             GROUP BY q.id ORDER BY completions DESC,views DESC LIMIT 100",
            ...$params
        );
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        return array_map(static function (array $row): array {
            $starts = (int) ($row['starts'] ?? 0);
            $completions = (int) ($row['completions'] ?? 0);
            return [
                'id' => (int) $row['id'], 'title' => (string) $row['title'], 'status' => (string) $row['status'], 'quiz_type' => (string) $row['quiz_type'],
                'views' => (int) ($row['views'] ?? 0), 'starts' => $starts, 'completions' => $completions,
                'completion_rate' => $starts > 0 ? round(($completions / $starts) * 100, 1) : 0,
            ];
        }, $rows);
    }

    private function decode(string $value): array
    {
        $decoded = json_decode($value !== '' ? $value : '{}', true);
        return is_array($decoded) ? $decoded : [];
    }
}

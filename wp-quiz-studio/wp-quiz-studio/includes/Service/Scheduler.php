<?php

declare(strict_types=1);

namespace WPQuizStudio\Service;

use WPQuizStudio\Repository\OrganizationRepository;

/** Publishes scheduled quizzes and expires quizzes whose end date has passed. */
final class Scheduler
{
    private const HOOK = 'wpqs_process_quiz_schedule';
    private const LEGACY_HOOK = 'wpqs_publish_scheduled_quizzes';

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action(self::HOOK, [$this, 'process']);
        add_action(self::LEGACY_HOOK, [$this, 'process']);

        if (!wp_next_scheduled(self::HOOK)) {
            $this->schedule();
        }
    }

    public function addSchedule(array $schedules): array
    {
        $schedules['wpqs_five_minutes'] = [
            'interval' => 300,
            'display' => __('Κάθε πέντε λεπτά', 'wp-quiz-studio'),
        ];
        return $schedules;
    }

    public function schedule(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'wpqs_five_minutes', self::HOOK);
        }
        wp_clear_scheduled_hook(self::LEGACY_HOOK);
    }

    public function clear(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::LEGACY_HOOK);
    }

    public function process(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wpqs_quizzes';
        $now = current_time('mysql', true);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'published', workflow_status = 'published', updated_at = %s
             WHERE status = 'scheduled'
               AND scheduled_at IS NOT NULL
               AND scheduled_at <= %s
               AND (expires_at IS NULL OR expires_at > %s)",
            $now,
            $now,
            $now
        ));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'expired', workflow_status = 'archived', archived_at = %s, updated_at = %s
             WHERE status IN ('published', 'scheduled')
               AND expires_at IS NOT NULL
               AND expires_at <= %s",
            $now,
            $now,
            $now
        ));

        $organizations = $wpdb->prefix . 'wpqs_organizations';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$organizations} SET status='expired', updated_at=%s WHERE status='active' AND expires_at IS NOT NULL AND expires_at<=%s",
            $now,
            $now
        ));

        $invitations = $wpdb->prefix . 'wpqs_invitations';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$invitations} SET status='expired' WHERE status='pending' AND expires_at<=%s",
            $now
        ));


        $this->notifications($now);
    }
    /** Sends deduplicated expiration, seat and weekly analytics notifications. */
    private function notifications(string $now): void
    {
        global $wpdb;
        $organizations = new OrganizationRepository();
        $notifier = new NotificationService($organizations);
        $sent = (array) get_option('wpqs_scheduled_notification_log', []);
        $today = gmdate('Y-m-d');

        foreach ($organizations->all() as $organization) {
            $organizationId = (int) $organization['id'];
            $seatKey = 'seats:' . $organizationId . ':' . $today;
            if (empty($sent[$seatKey])) {
                $notifier->seatWarning($organizationId);
                $sent[$seatKey] = time();
            }
            $expiresAt = (string) ($organization['expires_at'] ?? '');
            if ($expiresAt !== '') {
                $days = (int) ceil((strtotime($expiresAt . ' UTC') - time()) / DAY_IN_SECONDS);
                if ($days >= 0 && $days <= 7) {
                    $key = 'organization:' . $organizationId . ':' . $today;
                    if (empty($sent[$key])) {
                        $notifier->organizationExpiry($organizationId, $days);
                        $sent[$key] = time();
                    }
                }
            }
        }

        $quizTable = $wpdb->prefix . 'wpqs_quizzes';
        $expiring = $wpdb->get_results($wpdb->prepare(
            "SELECT id,title,organization_id,author_id,expires_at FROM {$quizTable} WHERE status IN ('published','scheduled') AND expires_at BETWEEN %s AND DATE_ADD(%s, INTERVAL 7 DAY)",
            $now,
            $now
        ), ARRAY_A) ?: [];
        foreach ($expiring as $quiz) {
            $days = max(0, (int) ceil((strtotime((string) $quiz['expires_at'] . ' UTC') - time()) / DAY_IN_SECONDS));
            $key = 'quiz:' . (int) $quiz['id'] . ':' . $today;
            if (empty($sent[$key])) {
                $notifier->quizExpiry($quiz, $days);
                $sent[$key] = time();
            }
        }

        if ((int) gmdate('N') === 1 && (int) gmdate('G') >= 8) {
            $week = gmdate('o-W');
            $analytics = $wpdb->prefix . 'wpqs_analytics';
            foreach ($organizations->all() as $organization) {
                $organizationId = (int) $organization['id'];
                $key = 'weekly:' . $organizationId . ':' . $week;
                if (!empty($sent[$key])) {
                    continue;
                }
                $quizIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$quizTable} WHERE organization_id=%d", $organizationId)) ?: [];
                $metrics = ['views' => 0, 'starts' => 0, 'completions' => 0, 'completion_rate' => 0];
                if ($quizIds !== []) {
                    $ids = implode(',', array_map('intval', $quizIds));
                    $from = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT event_type,COUNT(*) total FROM {$analytics} WHERE quiz_id IN ({$ids}) AND created_at>=%s GROUP BY event_type", $from), ARRAY_A) ?: [];
                    foreach ($rows as $row) {
                        if ($row['event_type'] === 'view') $metrics['views'] = (int) $row['total'];
                        if ($row['event_type'] === 'start') $metrics['starts'] = (int) $row['total'];
                        if ($row['event_type'] === 'complete') $metrics['completions'] = (int) $row['total'];
                    }
                    $metrics['completion_rate'] = $metrics['starts'] > 0 ? round(($metrics['completions'] / $metrics['starts']) * 100, 1) : 0;
                }
                $notifier->weeklySummary($organizationId, $metrics);
                $sent[$key] = time();
            }
        }

        $sent = array_filter($sent, static fn ($timestamp): bool => (int) $timestamp > time() - 45 * DAY_IN_SECONDS);
        update_option('wpqs_scheduled_notification_log', $sent, false);
    }

}

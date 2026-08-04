<?php

declare(strict_types=1);

namespace WPQuizStudio\Service;

/** Builds a read-only diagnostic report for administrators. */
final class SystemHealth
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        global $wpdb;

        $requiredTables = [
            'organizations', 'organization_domains', 'organization_members', 'invitations',
            'categories', 'quizzes', 'questions', 'answers', 'results', 'analytics',
            'sessions', 'themes', 'embeds', 'question_bank', 'revisions', 'templates',
            'activity_log', 'review_comments',
        ];
        $prefix = $wpdb->prefix . 'wpqs_';
        $missingTables = [];
        $nonInnoDbTables = [];

        foreach ($requiredTables as $name) {
            $table = $prefix . $name;
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                $missingTables[] = $table;
                continue;
            }
            $engine = (string) $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if ($engine !== '' && strtoupper($engine) !== 'INNODB') {
                $nonInnoDbTables[] = $table;
            }
        }

        $nextCron = wp_next_scheduled('wpqs_process_quiz_schedule');
        $upload = wp_get_upload_dir();
        $uploadWritable = empty($upload['error']) && is_dir((string) $upload['basedir']) && is_writable((string) $upload['basedir']);
        $permalinks = (string) get_option('permalink_structure', '');
        $dbVersion = (string) get_option('wpqs_db_version', '');
        $memoryLimit = (string) ini_get('memory_limit');

        $checks = [
            $this->check('php', 'PHP 8.2+', version_compare(PHP_VERSION, '8.2', '>='), PHP_VERSION, 'error'),
            $this->check('wordpress', 'WordPress 6.2+', version_compare((string) get_bloginfo('version'), '6.2', '>='), (string) get_bloginfo('version'), 'error'),
            $this->check('database', 'Πίνακες βάσης', $missingTables === [], $missingTables === [] ? count($requiredTables) . ' / ' . count($requiredTables) . ' διαθέσιμοι' : 'Λείπουν: ' . implode(', ', $missingTables), 'error'),
            $this->check('engine', 'Database engine', $nonInnoDbTables === [], $nonInnoDbTables === [] ? 'InnoDB' : 'Μη InnoDB: ' . implode(', ', $nonInnoDbTables), 'warning'),
            $this->check('db_version', 'Database migration', $dbVersion === WPQS_DB_VERSION, $dbVersion !== '' ? $dbVersion : 'Δεν έχει καταγραφεί', 'warning'),
            $this->check('cron', 'WP-Cron προγραμματισμός', $nextCron !== false, $nextCron ? gmdate('Y-m-d H:i:s', (int) $nextCron) . ' UTC' : 'Δεν έχει προγραμματιστεί', 'warning'),
            $this->check('cron_mode', 'WP-Cron λειτουργία', !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON, defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'DISABLE_WP_CRON ενεργό — απαιτείται server cron' : 'WordPress cron ενεργό', 'warning'),
            $this->check('permalinks', 'Μόνιμοι σύνδεσμοι', $permalinks !== '', $permalinks !== '' ? $permalinks : 'Plain permalinks — τα embeds μπορεί να μη λειτουργούν', 'warning'),
            $this->check('uploads', 'WordPress uploads', $uploadWritable, $uploadWritable ? (string) $upload['basedir'] : ((string) ($upload['error'] ?? 'Μη εγγράψιμος φάκελος')), 'warning'),
            $this->check('rest', 'WordPress REST API', function_exists('rest_url'), function_exists('rest_url') ? rest_url('wp-quiz-studio/v1/') : 'Μη διαθέσιμο', 'error'),
            $this->check('mail', 'Email transport', function_exists('wp_mail'), function_exists('wp_mail') ? 'wp_mail διαθέσιμο' : 'wp_mail μη διαθέσιμο', 'warning'),
        ];

        $status = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $status = 'error';
                break;
            }
            if ($check['status'] === 'warning') {
                $status = 'warning';
            }
        }

        return [
            'status' => $status,
            'version' => WPQS_VERSION,
            'db_version' => $dbVersion,
            'generated_at' => current_time('mysql', true),
            'environment' => [
                'site_url' => home_url('/'),
                'php' => PHP_VERSION,
                'wordpress' => (string) get_bloginfo('version'),
                'database' => (string) $wpdb->db_version(),
                'memory_limit' => $memoryLimit,
                'multisite' => is_multisite(),
            ],
            'counts' => [
                'organizations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}organizations"),
                'members' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}organization_members"),
                'quizzes' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}quizzes"),
                'questions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}questions"),
                'results' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}results"),
                'analytics_events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}analytics"),
            ],
            'checks' => $checks,
        ];
    }

    /** @return array<string,string|bool> */
    private function check(string $key, string $label, bool $passed, string $detail, string $failureStatus): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'status' => $passed ? 'ok' : $failureStatus,
            'detail' => $detail,
        ];
    }
}

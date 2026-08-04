<?php

declare(strict_types=1);

namespace WPQuizStudio\Admin;

/** Warns administrators when older copies of WP Quiz Studio remain installed. */
final class LegacyPluginsNotice
{
    public function register(): void
    {
        add_action('admin_notices', [$this, 'notice']);
    }

    public function notice(): void
    {
        if (!current_user_can('activate_plugins') || !function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'plugins') {
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $current = plugin_basename(WPQS_FILE);
        $duplicates = [];
        foreach (get_plugins() as $file => $data) {
            if ($file === $current) {
                continue;
            }
            if (!in_array(strtolower((string) ($data['Name'] ?? '')), ['wp quiz studio','quiz atelier'], true)) {
                continue;
            }
            $duplicates[] = sprintf('%s — v%s', $file, (string) ($data['Version'] ?? '?'));
        }

        if ($duplicates === []) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Εντοπίστηκαν παλιά αντίγραφα του Quiz Atelier.', 'wp-quiz-studio') . '</strong> ';
        echo esc_html__('Έχουν μείνει από προηγούμενα ZIP και βρίσκονται σε ξεχωριστούς φακέλους προσθέτων. Κρατήστε ενεργή τη νεότερη έκδοση και διαγράψτε τα παλαιότερα ανενεργά αντίγραφα.', 'wp-quiz-studio');
        echo '</p><p>';
        foreach ($duplicates as $duplicate) {
            echo '<code>' . esc_html($duplicate) . '</code><br>';
        }
        echo '</p></div>';
    }
}

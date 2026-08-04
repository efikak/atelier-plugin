<?php

declare(strict_types=1);

namespace WPQuizStudio\Security;

/** Adds conservative login throttling, session lifetime and account security notifications. */
final class LoginSecurity
{
    public const OPTION = 'wpqs_security_settings';

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $defaults = [
            'login_rate_limit' => true,
            'max_failed_logins' => 6,
            'lockout_minutes' => 15,
            'session_hours' => 12,
            'new_login_email' => true,
            'anonymous_analytics' => true,
            'allowed_upload_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'mp3', 'mp4'],
        ];
        $stored = get_option(self::OPTION, []);
        $settings = array_merge($defaults, is_array($stored) ? $stored : []);
        $settings['login_rate_limit'] = !empty($settings['login_rate_limit']);
        $settings['new_login_email'] = !empty($settings['new_login_email']);
        $settings['anonymous_analytics'] = !empty($settings['anonymous_analytics']);
        $settings['max_failed_logins'] = max(3, min(30, absint($settings['max_failed_logins'])));
        $settings['lockout_minutes'] = max(5, min(1440, absint($settings['lockout_minutes'])));
        $settings['session_hours'] = max(1, min(168, absint($settings['session_hours'])));
        $settings['allowed_upload_mimes'] = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $settings['allowed_upload_mimes']))));
        return $settings;
    }

    public function register(): void
    {
        add_filter('authenticate', [$this, 'blockLockedAddress'], 5, 3);
        add_action('wp_login_failed', [$this, 'failedLogin']);
        add_action('wp_login', [$this, 'successfulLogin'], 10, 2);
        add_filter('auth_cookie_expiration', [$this, 'sessionExpiration'], 20, 3);
        add_filter('upload_mimes', [$this, 'restrictUploadMimes']);
    }

    public function blockLockedAddress(mixed $user, string $username, string $password): mixed
    {
        if (!$this->settings()['login_rate_limit'] || $username === '') {
            return $user;
        }
        $state = $this->failureState();
        if ((int) ($state['locked_until'] ?? 0) > time()) {
            $minutes = max(1, (int) ceil(((int) $state['locked_until'] - time()) / 60));
            return new \WP_Error(
                'wpqs_login_locked',
                sprintf(__('Πολλές αποτυχημένες προσπάθειες. Δοκιμάστε ξανά σε περίπου %d λεπτά.', 'wp-quiz-studio'), $minutes)
            );
        }
        return $user;
    }

    public function failedLogin(string $username): void
    {
        $settings = $this->settings();
        if (!$settings['login_rate_limit']) {
            return;
        }
        $state = $this->failureState();
        $attempts = (int) ($state['attempts'] ?? 0) + 1;
        $lockedUntil = $attempts >= (int) $settings['max_failed_logins']
            ? time() + ((int) $settings['lockout_minutes'] * MINUTE_IN_SECONDS)
            : 0;
        set_transient($this->failureKey(), [
            'attempts' => $lockedUntil > 0 ? 0 : $attempts,
            'locked_until' => $lockedUntil,
            'last_username' => sanitize_user($username),
        ], max(HOUR_IN_SECONDS, (int) $settings['lockout_minutes'] * MINUTE_IN_SECONDS));
    }

    public function successfulLogin(string $username, \WP_User $user): void
    {
        delete_transient($this->failureKey());
        $previous = (string) get_user_meta((int) $user->ID, 'wpqs_last_login', true);
        update_user_meta((int) $user->ID, 'wpqs_last_login', current_time('mysql', true));
        if (!$this->settings()['new_login_email'] || $previous === '') {
            return;
        }
        $preferences = get_user_meta((int) $user->ID, 'wpqs_email_preferences', true);
        if (is_array($preferences) && array_key_exists('security', $preferences) && empty($preferences['security'])) {
            return;
        }
        $subject = __('Νέα σύνδεση στο Quiz Atelier', 'wp-quiz-studio');
        $message = sprintf(
            __('Έγινε νέα σύνδεση στον λογαριασμό σας στις %1$s από τη διεύθυνση IP %2$s. Αν δεν ήσασταν εσείς, αλλάξτε άμεσα τον κωδικό σας.', 'wp-quiz-studio'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format')),
            $this->maskedIp()
        );
        wp_mail($user->user_email, $subject, $message);
    }

    public function sessionExpiration(int $length, int $userId, bool $remember): int
    {
        if ($userId <= 0) {
            return $length;
        }
        $user = get_user_by('id', $userId);
        if (!$user || !array_intersect((array) $user->roles, ['quiz_creator', 'quiz_creator_admin', 'quiz_viewer', 'quiz_universal_manager'])) {
            return $length;
        }
        return (int) $this->settings()['session_hours'] * HOUR_IN_SECONDS;
    }

    /** @param array<string,string> $mimes @return array<string,string> */
    public function restrictUploadMimes(array $mimes): array
    {
        $user = wp_get_current_user();
        if (!$user->exists() || user_can($user, 'manage_options')) {
            return $mimes;
        }
        if (!array_intersect((array) $user->roles, ['quiz_creator', 'quiz_creator_admin', 'quiz_universal_manager'])) {
            return $mimes;
        }
        $allowed = (array) $this->settings()['allowed_upload_mimes'];
        return array_filter($mimes, static function (string $mime, string $extension) use ($allowed): bool {
            $extensions = explode('|', $extension);
            return array_intersect($extensions, $allowed) !== [];
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function failureState(): array
    {
        $state = get_transient($this->failureKey());
        return is_array($state) ? $state : [];
    }

    private function failureKey(): string
    {
        return 'wpqs_login_' . substr(hash('sha256', $this->ip()), 0, 24);
    }

    private function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = sanitize_text_field((string) ($_SERVER[$key] ?? ''));
            if ($value !== '') {
                return trim(explode(',', $value)[0]);
            }
        }
        return 'unknown';
    }

    private function maskedIp(): string
    {
        $ip = $this->ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = 'x';
            return implode('.', $parts);
        }
        return $ip === 'unknown' ? __('άγνωστη', 'wp-quiz-studio') : preg_replace('/:[^:]+$/', ':xxxx', $ip);
    }
}

<?php

declare(strict_types=1);

namespace WPQuizStudio\Security;

/** Provides lightweight anonymous endpoint throttling without storing raw IP addresses. */
final class RateLimiter
{
    public function allow(string $action, int $limit, int $windowSeconds): bool
    {
        $address = $this->clientAddress();
        $agent = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        $identity = hash_hmac('sha256', $address . '|' . $agent, wp_salt('nonce'));
        $bucket = (int) floor(time() / max(1, $windowSeconds));
        $key = 'wpqs_rl_' . md5($action . '|' . $identity . '|' . $bucket);
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, $windowSeconds + 10);
        return true;
    }

    private function clientAddress(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $address = trim(explode(',', (string) $candidate)[0]);
            if (filter_var($address, FILTER_VALIDATE_IP)) {
                return $address;
            }
        }

        return 'unknown';
    }
}

<?php

declare(strict_types=1);

namespace WPQuizStudio\Security;

/**
 * Resolves global and per-quiz embed domain rules.
 *
 * The policy is enforced for iframe pages. Native WordPress shortcodes are not
 * restricted because they execute on the host site itself.
 */
final class EmbedPolicy
{
    public const OPTION = 'wpqs_embed_policy';

    /** @return array{enabled:bool,allowed_domains:list<string>,allow_subdomains:bool,blocked_title:string,blocked_message:string} */
    public function settings(): array
    {
        $homeHost = $this->normaliseHost((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $defaults = [
            'enabled' => false,
            'allowed_domains' => $homeHost !== '' ? [$homeHost] : [],
            'allow_subdomains' => true,
            'blocked_title' => __('Μη εγκεκριμένο site', 'wp-quiz-studio'),
            'blocked_message' => __('Ωχ! Αυτό το quiz μάλλον πήγε εκδρομή χωρίς άδεια. Το συγκεκριμένο domain δεν βρίσκεται στη λίστα των εγκεκριμένων sites.', 'wp-quiz-studio'),
        ];

        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $settings = array_merge($defaults, $stored);
        $settings['enabled'] = !empty($settings['enabled']);
        $settings['allow_subdomains'] = !empty($settings['allow_subdomains']);
        $settings['allowed_domains'] = $this->normaliseDomains((array) ($settings['allowed_domains'] ?? []));
        $settings['blocked_title'] = sanitize_text_field((string) ($settings['blocked_title'] ?? $defaults['blocked_title']));
        $settings['blocked_message'] = sanitize_textarea_field((string) ($settings['blocked_message'] ?? $defaults['blocked_message']));

        return $settings;
    }

    /** @param array<string,mixed> $input */
    public function sanitize(array $input): array
    {
        $domains = $input['allowed_domains'] ?? [];
        if (is_string($domains)) {
            $domains = preg_split('/[\r\n,;]+/', $domains) ?: [];
        }

        return [
            'enabled' => !empty($input['enabled']),
            'allowed_domains' => $this->normaliseDomains((array) $domains),
            'allow_subdomains' => !empty($input['allow_subdomains']),
            'blocked_title' => sanitize_text_field((string) ($input['blocked_title'] ?? '')),
            'blocked_message' => sanitize_textarea_field((string) ($input['blocked_message'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $quiz
     * @return array{restricted:bool,domains:list<string>,allow_subdomains:bool,title:string,message:string,mode:string}
     */
    public function policyForQuiz(array $quiz): array
    {
        $global = $this->settings();
        $settings = (array) ($quiz['settings'] ?? []);
        $mode = (string) ($settings['embed_mode'] ?? 'inherit');
        if (!in_array($mode, ['inherit', 'public', 'restricted'], true)) {
            $mode = 'inherit';
        }

        $customDomains = $this->normaliseDomains((array) ($settings['embed_domains'] ?? []));
        $workspaceDomains = [];
        $workspaceAllowsEmbeds = true;
        $organizationId = absint($quiz['organization_id'] ?? 0);
        $visibility = sanitize_key((string) ($quiz['visibility_scope'] ?? 'personal'));

        global $wpdb;
        if ($visibility === 'universal') {
            $workspaceDomains = $wpdb->get_col(
                "SELECT DISTINCT d.domain
                 FROM {$wpdb->prefix}wpqs_organization_domains d
                 INNER JOIN {$wpdb->prefix}wpqs_organizations o ON o.id=d.organization_id
                 WHERE d.domain_type IN ('embed','both','custom')
                   AND o.status='active'
                   AND (o.expires_at IS NULL OR o.expires_at>UTC_TIMESTAMP())"
            ) ?: [];
        } elseif ($organizationId > 0) {
            $organization = $wpdb->get_row($wpdb->prepare(
                "SELECT status, expires_at, features FROM {$wpdb->prefix}wpqs_organizations WHERE id=%d",
                $organizationId
            ), ARRAY_A);
            if (!$organization || ($organization['status'] ?? '') !== 'active') {
                $workspaceAllowsEmbeds = false;
            }
            $expiresAt = (string) ($organization['expires_at'] ?? '');
            if ($expiresAt !== '' && strtotime($expiresAt . ' UTC') <= time()) {
                $workspaceAllowsEmbeds = false;
            }
            $features = $organization ? json_decode((string) ($organization['features'] ?? '{}'), true) : [];
            if (is_array($features) && array_key_exists('embeds', $features) && empty($features['embeds'])) {
                $workspaceAllowsEmbeds = false;
            }
            $workspaceDomains = $wpdb->get_col($wpdb->prepare(
                "SELECT domain FROM {$wpdb->prefix}wpqs_organization_domains
                 WHERE organization_id=%d AND domain_type IN ('embed','both','custom')",
                $organizationId
            )) ?: [];
        }

        $workspaceDomains = $this->normaliseDomains($workspaceDomains);
        $legacyDomains = (array) $global['allowed_domains'];
        $domains = $workspaceDomains !== [] ? $workspaceDomains : $legacyDomains;

        // A per-quiz restricted list can only narrow the Workspace whitelist; it
        // can never silently add an unapproved external domain.
        if ($mode === 'restricted') {
            $domains = $workspaceDomains !== []
                ? array_values(array_intersect($workspaceDomains, $customDomains))
                : $customDomains;
        }

        // Workspace membership always enforces its approved domains. The legacy
        // `public` mode only disables an extra per-quiz restriction; it never
        // bypasses the Organization whitelist.
        $restricted = $organizationId > 0 || $visibility === 'universal' || ($mode !== 'public' && !empty($global['enabled']));
        if (!$workspaceAllowsEmbeds) {
            $restricted = true;
            $domains = [];
        }

        $message = sanitize_textarea_field((string) ($settings['embed_block_message'] ?? ''));
        if ($message === '') {
            $message = (string) $global['blocked_message'];
        }

        $homeHost = $this->normaliseHost((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($homeHost !== '' && !in_array($homeHost, $domains, true)) {
            $domains[] = $homeHost;
        }

        return [
            'restricted' => $restricted,
            'domains' => array_values(array_unique($domains)),
            'allow_subdomains' => (bool) $global['allow_subdomains'],
            'title' => (string) $global['blocked_title'],
            'message' => $message,
            'mode' => $mode,
        ];
    }

    /** @param array<string,mixed> $quiz */
    public function requestAllowed(array $quiz): bool
    {
        $policy = $this->policyForQuiz($quiz);
        if (!$policy['restricted']) {
            return true;
        }

        // Direct previews in a top-level tab are allowed. The whitelist applies to frames.
        $destination = strtolower(sanitize_text_field((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
        if ($destination !== '' && $destination !== 'iframe' && $destination !== 'frame') {
            return true;
        }

        $referrer = esc_url_raw((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $host = $this->normaliseHost((string) wp_parse_url($referrer, PHP_URL_HOST));

        // When a host sends a referrer we can show the friendly blocked message
        // immediately. When it suppresses the referrer, the response is allowed to
        // continue and the browser-enforced CSP frame-ancestors policy performs the
        // authoritative origin check. This prevents approved no-referrer sites from
        // being rejected while keeping the whitelist secure.
        if ($host === '') {
            return true;
        }

        return $this->hostAllowed($host, $policy['domains'], $policy['allow_subdomains']);
    }

    /**
     * Builds a browser-enforced CSP frame-ancestors value for the resolved policy.
     * This checks the actual embedding ancestor and therefore does not rely on the
     * optional HTTP Referer header.
     *
     * @param array<string,mixed> $quiz
     */
    public function frameAncestors(array $quiz): string
    {
        $policy = $this->policyForQuiz($quiz);
        if (!$policy['restricted']) {
            return "frame-ancestors *";
        }

        $sources = ["'self'"];
        foreach ((array) $policy['domains'] as $domain) {
            $domain = $this->normaliseHost((string) $domain);
            if ($domain === '') {
                continue;
            }

            $base = ltrim($domain, '*.');
            $sources[] = 'https://' . $base;
            $sources[] = 'http://' . $base;
            if ($policy['allow_subdomains'] || str_starts_with($domain, '*.')) {
                $sources[] = 'https://*.' . $base;
                $sources[] = 'http://*.' . $base;
            }
        }

        $sources = array_values(array_unique($sources));
        return 'frame-ancestors ' . ($sources !== [] ? implode(' ', $sources) : "'none'");
    }

    /** @param list<string> $domains */
    public function hostAllowed(string $host, array $domains, bool $allowSubdomains): bool
    {
        $host = $this->normaliseHost($host);
        foreach ($domains as $allowed) {
            $allowed = $this->normaliseHost($allowed);
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed) {
                return true;
            }
            if (($allowSubdomains || str_starts_with((string) $allowed, '*.')) && str_ends_with($host, '.' . ltrim($allowed, '*.'))) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int|string,mixed> $domains @return list<string> */
    public function normaliseDomains(array $domains): array
    {
        $clean = [];
        foreach ($domains as $domain) {
            $host = $this->normaliseHost((string) $domain);
            if ($host !== '') {
                $clean[] = $host;
            }
        }
        return array_values(array_unique($clean));
    }

    private function normaliseHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '://')) {
            $value = (string) wp_parse_url($value, PHP_URL_HOST);
        } else {
            $value = preg_replace('#^//#', '', $value) ?: $value;
            $value = explode('/', $value)[0];
            $value = explode(':', $value)[0];
        }
        $value = preg_replace('/^www\./', '', $value) ?: $value;
        return preg_match('/^(\*\.)?[a-z0-9.-]+$/', $value) ? $value : '';
    }
}

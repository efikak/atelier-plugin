<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

/**
 * Stores Organizations (workspaces), their domains and memberships.
 *
 * The repository deliberately keeps WordPress roles separate from Organization roles:
 * WordPress capabilities decide whether a user may enter Quiz Atelier, while the
 * membership row decides which tenant data the user may access.
 */
final class OrganizationRepository
{
    private string $organizations;
    private string $domains;
    private string $members;
    private string $quizzes;
    private string $analytics;
    private string $invitations;

    public function __construct()
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'wpqs_';
        $this->organizations = $prefix . 'organizations';
        $this->domains = $prefix . 'organization_domains';
        $this->members = $prefix . 'organization_members';
        $this->quizzes = $prefix . 'quizzes';
        $this->analytics = $prefix . 'analytics';
        $this->invitations = $prefix . 'invitations';
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT o.*,
                (SELECT COUNT(*) FROM {$this->members} m WHERE m.organization_id=o.id AND m.status='active') AS used_seats,
                (SELECT COUNT(*) FROM {$this->members} m WHERE m.organization_id=o.id AND m.status='active' AND m.org_role='creator_admin') AS creator_admins,
                (SELECT COUNT(*) FROM {$this->invitations} i WHERE i.organization_id=o.id AND i.status='pending' AND i.expires_at>UTC_TIMESTAMP()) AS reserved_seats,
                (SELECT COUNT(*) FROM {$this->invitations} i WHERE i.organization_id=o.id AND i.status='pending' AND i.org_role='creator_admin' AND i.expires_at>UTC_TIMESTAMP()) AS reserved_creator_admins,
                (SELECT COUNT(*) FROM {$this->quizzes} q WHERE q.organization_id=o.id) AS quiz_count
             FROM {$this->organizations} o
             ORDER BY o.name ASC",
            ARRAY_A
        ) ?: [];

        return array_map(fn (array $row): array => $this->decode($row), $rows);
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*,
                (SELECT COUNT(*) FROM {$this->members} m WHERE m.organization_id=o.id AND m.status='active') AS used_seats,
                (SELECT COUNT(*) FROM {$this->members} m WHERE m.organization_id=o.id AND m.status='active' AND m.org_role='creator_admin') AS creator_admins,
                (SELECT COUNT(*) FROM {$this->invitations} i WHERE i.organization_id=o.id AND i.status='pending' AND i.expires_at>UTC_TIMESTAMP()) AS reserved_seats,
                (SELECT COUNT(*) FROM {$this->invitations} i WHERE i.organization_id=o.id AND i.status='pending' AND i.org_role='creator_admin' AND i.expires_at>UTC_TIMESTAMP()) AS reserved_creator_admins,
                (SELECT COUNT(*) FROM {$this->quizzes} q WHERE q.organization_id=o.id) AS quiz_count
             FROM {$this->organizations} o WHERE o.id=%d",
            $id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $organization = $this->decode($row);
        $organization['domains'] = $this->domains($id);
        return $organization;
    }

    public function defaultOrganizationId(): int
    {
        global $wpdb;
        $id = (int) get_option('wpqs_default_organization_id', 0);
        if ($id > 0 && $this->find($id)) {
            return $id;
        }

        $id = (int) $wpdb->get_var("SELECT id FROM {$this->organizations} ORDER BY id ASC LIMIT 1");
        if ($id > 0) {
            update_option('wpqs_default_organization_id', $id);
        }
        return $id;
    }

    /**
     * Creates or updates an Organization.
     *
     * @param array<string,mixed> $data
     */
    public function save(array $data, int $actorId): int
    {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $existing = $id ? $this->find($id) : null;
        if ($id && !$existing) {
            throw new \RuntimeException(__('Ο οργανισμός δεν βρέθηκε.', 'wp-quiz-studio'));
        }

        $name = sanitize_text_field((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException(__('Το όνομα οργανισμού είναι υποχρεωτικό.', 'wp-quiz-studio'));
        }

        $seatLimit = max(1, absint($data['seat_limit'] ?? 1));
        $adminLimit = max(1, absint($data['creator_admin_limit'] ?? 1));
        $status = sanitize_key((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            $status = 'active';
        }

        $record = [
            'name' => $name,
            'slug' => $this->uniqueSlug((string) ($data['slug'] ?? $name), $id),
            'seat_limit' => $seatLimit,
            'creator_admin_limit' => min($adminLimit, $seatLimit),
            'expires_at' => $this->dateTime($data['expires_at'] ?? null),
            'features' => wp_json_encode($this->sanitizeFeatures((array) ($data['features'] ?? []))),
            'branding' => wp_json_encode($this->sanitizeBranding((array) ($data['branding'] ?? []))),
            'status' => $status,
            'updated_at' => current_time('mysql', true),
        ];

        $wpdb->query('START TRANSACTION');
        try {
            if ($id) {
                if ($wpdb->update($this->organizations, $record, ['id' => $id]) === false) {
                    throw new \RuntimeException($wpdb->last_error ?: __('Δεν αποθηκεύτηκε ο οργανισμός.', 'wp-quiz-studio'));
                }
            } else {
                $record['created_by'] = $actorId;
                $record['created_at'] = current_time('mysql', true);
                if ($wpdb->insert($this->organizations, $record) === false) {
                    throw new \RuntimeException($wpdb->last_error ?: __('Δεν δημιουργήθηκε ο οργανισμός.', 'wp-quiz-studio'));
                }
                $id = (int) $wpdb->insert_id;
            }

            $this->syncDomains($id, (array) ($data['domains'] ?? []));
            $wpdb->query('COMMIT');
            return $id;
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    /** @return list<array<string,mixed>> */
    public function domains(int $organizationId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->domains} WHERE organization_id=%d ORDER BY is_primary DESC, domain ASC",
            $organizationId
        ), ARRAY_A) ?: [];

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['organization_id'] = (int) $row['organization_id'];
            $row['is_primary'] = (bool) $row['is_primary'];
            return $row;
        }, $rows);
    }

    public function currentForUser(int $userId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, o.name AS organization_name, o.slug AS organization_slug, o.status AS organization_status,
                    o.seat_limit, o.creator_admin_limit, o.expires_at, o.features, o.branding
             FROM {$this->members} m
             INNER JOIN {$this->organizations} o ON o.id=m.organization_id
             WHERE m.user_id=%d AND m.status='active'
             ORDER BY m.id ASC LIMIT 1",
            $userId
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->decodeMembership($row);
    }

    public function membership(int $organizationId, int $userId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, o.name AS organization_name, o.slug AS organization_slug, o.status AS organization_status,
                    o.seat_limit, o.creator_admin_limit, o.expires_at, o.features, o.branding
             FROM {$this->members} m
             INNER JOIN {$this->organizations} o ON o.id=m.organization_id
             WHERE m.organization_id=%d AND m.user_id=%d LIMIT 1",
            $organizationId,
            $userId
        ), ARRAY_A);

        return $row ? $this->decodeMembership($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function members(int $organizationId): array
    {
        global $wpdb;
        $users = $wpdb->users;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.user_login, u.user_email, u.display_name, u.user_registered
             FROM {$this->members} m
             INNER JOIN {$users} u ON u.ID=m.user_id
             WHERE m.organization_id=%d
             ORDER BY FIELD(m.org_role,'creator_admin','creator','viewer'), u.display_name ASC",
            $organizationId
        ), ARRAY_A) ?: [];

        return array_map(static function (array $row): array {
            foreach (['id', 'organization_id', 'user_id'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $user = get_userdata((int) $row['user_id']);
            $row['wordpress_roles'] = $user ? array_values((array) $user->roles) : [];
            $row['is_wordpress_admin'] = $user ? user_can($user, 'manage_options') : false;
            return $row;
        }, $rows);
    }

    /** Returns a membership row by its internal id. */
    public function memberById(int $organizationId, int $memberId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, u.user_login, u.user_email, u.display_name, u.user_registered
             FROM {$this->members} m
             INNER JOIN {$wpdb->users} u ON u.ID=m.user_id
             WHERE m.id=%d AND m.organization_id=%d LIMIT 1",
            $memberId,
            $organizationId
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        foreach (['id', 'organization_id', 'user_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $user = get_userdata((int) $row['user_id']);
        $row['wordpress_roles'] = $user ? array_values((array) $user->roles) : [];
        $row['is_wordpress_admin'] = $user ? user_can($user, 'manage_options') : false;
        return $row;
    }

    /**
     * Lists WordPress users together with their active Quiz Atelier workspace.
     * This is intentionally available only through a Super Admin REST route.
     *
     * @return list<array<string,mixed>>
     */
    public function userWorkspaceAssignments(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT u.ID AS user_id, u.user_login, u.user_email, u.display_name, u.user_registered,
                    m.id AS membership_id, m.organization_id, m.org_role, m.status AS membership_status,
                    o.name AS organization_name, o.slug AS organization_slug
             FROM {$wpdb->users} u
             LEFT JOIN {$this->members} m ON m.id=(
                 SELECT m2.id FROM {$this->members} m2
                 WHERE m2.user_id=u.ID
                 ORDER BY (m2.status='active') DESC, m2.id ASC LIMIT 1
             )
             LEFT JOIN {$this->organizations} o ON o.id=m.organization_id
             ORDER BY u.display_name ASC, u.user_email ASC",
            ARRAY_A
        ) ?: [];

        return array_map(static function (array $row): array {
            foreach (['user_id', 'membership_id', 'organization_id'] as $key) {
                $row[$key] = (int) ($row[$key] ?? 0);
            }
            $user = get_userdata((int) $row['user_id']);
            $row['wordpress_roles'] = $user ? array_values((array) $user->roles) : [];
            $row['is_wordpress_admin'] = $user ? user_can($user, 'manage_options') : false;
            return $row;
        }, $rows);
    }

    /**
     * Moves one WordPress account to a different workspace.
     * Existing quiz ownership remains in the previous workspace unless explicitly moved.
     */
    public function moveUserToOrganization(
        int $userId,
        int $targetOrganizationId,
        string $role = 'creator',
        string $status = 'active',
        bool $moveQuizzes = false
    ): array {
        global $wpdb;
        if (!get_userdata($userId)) {
            throw new \RuntimeException(__('Ο χρήστης δεν βρέθηκε.', 'wp-quiz-studio'));
        }
        if (!$this->find($targetOrganizationId)) {
            throw new \RuntimeException(__('Το Workspace προορισμού δεν βρέθηκε.', 'wp-quiz-studio'));
        }

        $wpdb->query('START TRANSACTION');
        try {
            $oldOrganizationIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT organization_id FROM {$this->members} WHERE user_id=%d",
                $userId
            )) ?: []);

            $targetMembership = $this->membership($targetOrganizationId, $userId);
            if ($targetMembership) {
                $this->updateMember($targetOrganizationId, (int) $targetMembership['id'], [
                    'org_role' => $role,
                    'status' => $status,
                ]);
            } else {
                $this->addMember($targetOrganizationId, $userId, $role, $status);
            }

            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->members} WHERE user_id=%d AND organization_id<>%d",
                $userId,
                $targetOrganizationId
            ));

            if ($moveQuizzes && $oldOrganizationIds !== []) {
                $placeholders = implode(',', array_fill(0, count($oldOrganizationIds), '%d'));
                $params = array_merge([$targetOrganizationId, $userId], $oldOrganizationIds);
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->quizzes} SET organization_id=%d WHERE author_id=%d AND organization_id IN ({$placeholders})",
                    ...$params
                ));
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }

        return $this->membership($targetOrganizationId, $userId) ?: [];
    }

    /** Updates only the embed whitelist that a Creator Admin is allowed to manage. */
    public function updateEmbedDomains(int $organizationId, array $domains): array
    {
        global $wpdb;
        if (!$this->find($organizationId)) {
            throw new \RuntimeException(__('Ο οργανισμός δεν βρέθηκε.', 'wp-quiz-studio'));
        }
        $wpdb->delete($this->domains, ['organization_id' => $organizationId, 'domain_type' => 'embed']);
        $wpdb->delete($this->domains, ['organization_id' => $organizationId, 'domain_type' => 'custom']);
        $seen = [];
        foreach ($domains as $domain) {
            $host = $this->normaliseDomain((string) (is_array($domain) ? ($domain['domain'] ?? '') : $domain));
            if ($host === '' || isset($seen[$host])) {
                continue;
            }
            $seen[$host] = true;
            $wpdb->insert($this->domains, [
                'organization_id' => $organizationId,
                'domain' => $host,
                'domain_type' => 'embed',
                'is_primary' => 0,
                'created_at' => current_time('mysql', true),
            ]);
        }
        return $this->domains($organizationId);
    }

    public function addMember(int $organizationId, int $userId, string $role = 'creator', string $status = 'active'): int
    {
        global $wpdb;
        $organization = $this->find($organizationId);
        if (!$organization) {
            throw new \RuntimeException(__('Ο οργανισμός δεν βρέθηκε.', 'wp-quiz-studio'));
        }

        $role = $this->normaliseRole($role);
        $status = in_array($status, ['active', 'suspended'], true) ? $status : 'active';
        $existing = $this->membership($organizationId, $userId);
        $activeSeats = (int) ($organization['used_seats'] ?? 0);
        if (!$existing && $status === 'active' && $activeSeats >= (int) $organization['seat_limit']) {
            throw new \RuntimeException(__('Δεν υπάρχουν διαθέσιμες θέσεις στον οργανισμό.', 'wp-quiz-studio'));
        }
        if ($role === 'creator_admin' && (!$existing || $existing['org_role'] !== 'creator_admin')) {
            if ((int) ($organization['creator_admins'] ?? 0) >= (int) $organization['creator_admin_limit']) {
                throw new \RuntimeException(__('Έχει συμπληρωθεί το όριο Creator Admins.', 'wp-quiz-studio'));
            }
        }

        $record = [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'org_role' => $role,
            'status' => $status,
            'updated_at' => current_time('mysql', true),
        ];

        if ($existing) {
            if ($wpdb->update($this->members, $record, ['id' => (int) $existing['id']]) === false) {
                throw new \RuntimeException($wpdb->last_error ?: __('Δεν ενημερώθηκε το μέλος.', 'wp-quiz-studio'));
            }
            return (int) $existing['id'];
        }

        $record['joined_at'] = current_time('mysql', true);
        if ($wpdb->insert($this->members, $record) === false) {
            throw new \RuntimeException($wpdb->last_error ?: __('Δεν προστέθηκε το μέλος.', 'wp-quiz-studio'));
        }
        return (int) $wpdb->insert_id;
    }

    public function updateMember(int $organizationId, int $memberId, array $data): array
    {
        global $wpdb;
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->members} WHERE id=%d AND organization_id=%d",
            $memberId,
            $organizationId
        ), ARRAY_A);
        if (!$member) {
            throw new \RuntimeException(__('Το μέλος δεν βρέθηκε.', 'wp-quiz-studio'));
        }

        $role = $this->normaliseRole((string) ($data['org_role'] ?? $member['org_role']));
        $status = (string) ($data['status'] ?? $member['status']);
        if (!in_array($status, ['active', 'suspended'], true)) {
            $status = 'active';
        }

        $organization = $this->find($organizationId);
        if (!$organization) {
            throw new \RuntimeException(__('Ο οργανισμός δεν βρέθηκε.', 'wp-quiz-studio'));
        }
        if ($status === 'active' && $member['status'] !== 'active' && (int) $organization['used_seats'] >= (int) $organization['seat_limit']) {
            throw new \RuntimeException(__('Δεν υπάρχουν διαθέσιμες θέσεις.', 'wp-quiz-studio'));
        }
        if ($role === 'creator_admin' && $member['org_role'] !== 'creator_admin' && (int) $organization['creator_admins'] >= (int) $organization['creator_admin_limit']) {
            throw new \RuntimeException(__('Έχει συμπληρωθεί το όριο Creator Admins.', 'wp-quiz-studio'));
        }

        $wpdb->update($this->members, [
            'org_role' => $role,
            'status' => $status,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $memberId, 'organization_id' => $organizationId]);

        return $this->membership($organizationId, (int) $member['user_id']) ?: [];
    }

    public function removeMember(int $organizationId, int $memberId, int $transferToUserId = 0): void
    {
        global $wpdb;
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->members} WHERE id=%d AND organization_id=%d",
            $memberId,
            $organizationId
        ), ARRAY_A);
        if (!$member) {
            return;
        }

        if ($transferToUserId > 0) {
            $wpdb->update($this->quizzes, ['author_id' => $transferToUserId], [
                'organization_id' => $organizationId,
                'author_id' => (int) $member['user_id'],
            ]);
        }

        if ($wpdb->delete($this->members, ['id' => $memberId, 'organization_id' => $organizationId]) === false) {
            throw new \RuntimeException($wpdb->last_error ?: __('Δεν αφαιρέθηκε το μέλος.', 'wp-quiz-studio'));
        }
    }

    public function emailAllowed(int $organizationId, string $email): bool
    {
        $email = sanitize_email($email);
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        $allowed = array_values(array_filter($this->domains($organizationId), static fn (array $item): bool => in_array($item['domain_type'], ['email', 'both'], true)));
        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $item) {
            $candidate = strtolower((string) $item['domain']);
            if ($domain === $candidate || str_ends_with($domain, '.' . $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    public function dashboard(int $organizationId, int $userId, bool $adminView): array
    {
        global $wpdb;
        $organization = $this->find($organizationId);
        if (!$organization) {
            return [];
        }

        $where = 'organization_id=%d';
        $params = [$organizationId];
        if (!$adminView) {
            $where .= " AND (visibility_scope IN ('organization','universal') OR author_id=%d)";
            $params[] = $userId;
        }

        $quizSql = $wpdb->prepare("SELECT COUNT(*) FROM {$this->quizzes} WHERE {$where}", ...$params);
        $publishedSql = $wpdb->prepare("SELECT COUNT(*) FROM {$this->quizzes} WHERE {$where} AND status='published'", ...$params);
        $draftSql = $wpdb->prepare("SELECT COUNT(*) FROM {$this->quizzes} WHERE {$where} AND workflow_status='draft'", ...$params);
        $reviewSql = $wpdb->prepare("SELECT COUNT(*) FROM {$this->quizzes} WHERE {$where} AND workflow_status='submitted'", ...$params);

        $quizIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->quizzes} WHERE {$where}", ...$params)) ?: [];
        $completionCount = 0;
        $views = 0;
        if ($quizIds !== []) {
            $ids = implode(',', array_map('intval', $quizIds));
            $completionCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->analytics} WHERE quiz_id IN ({$ids}) AND event_type='complete'");
            $views = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->analytics} WHERE quiz_id IN ({$ids}) AND event_type='view'");
        }

        return [
            'organization' => $organization,
            'total_quizzes' => (int) $wpdb->get_var($quizSql),
            'published_quizzes' => (int) $wpdb->get_var($publishedSql),
            'draft_quizzes' => (int) $wpdb->get_var($draftSql),
            'pending_review' => (int) $wpdb->get_var($reviewSql),
            'views' => $views,
            'completions' => $completionCount,
            'completion_rate' => $views > 0 ? round(($completionCount / $views) * 100, 1) : 0,
            'members' => count($this->members($organizationId)),
            'available_seats' => max(0, (int) $organization['seat_limit'] - (int) $organization['used_seats'] - (int) ($organization['reserved_seats'] ?? 0)),
        ];
    }

    private function decode(array $row): array
    {
        foreach (['id', 'seat_limit', 'creator_admin_limit', 'created_by', 'used_seats', 'creator_admins', 'reserved_seats', 'reserved_creator_admins', 'quiz_count'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $row['features'] = $this->json((string) ($row['features'] ?? ''));
        $row['branding'] = $this->json((string) ($row['branding'] ?? ''));
        return $row;
    }

    private function decodeMembership(array $row): array
    {
        foreach (['id', 'organization_id', 'user_id', 'seat_limit', 'creator_admin_limit'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $row['features'] = $this->json((string) ($row['features'] ?? ''));
        $row['branding'] = $this->json((string) ($row['branding'] ?? ''));
        return $row;
    }

    private function syncDomains(int $organizationId, array $domains): void
    {
        global $wpdb;
        $wpdb->delete($this->domains, ['organization_id' => $organizationId]);
        $seen = [];
        foreach ($domains as $index => $domain) {
            if (is_string($domain)) {
                $domain = ['domain' => $domain, 'domain_type' => 'both'];
            }
            if (!is_array($domain)) {
                continue;
            }
            $host = $this->normaliseDomain((string) ($domain['domain'] ?? ''));
            if ($host === '' || isset($seen[$host])) {
                continue;
            }
            $seen[$host] = true;
            $type = sanitize_key((string) ($domain['domain_type'] ?? 'both'));
            if (!in_array($type, ['email', 'embed', 'both', 'custom'], true)) {
                $type = 'both';
            }
            $wpdb->insert($this->domains, [
                'organization_id' => $organizationId,
                'domain' => $host,
                'domain_type' => $type,
                'is_primary' => !empty($domain['is_primary']) || $index === 0 ? 1 : 0,
                'created_at' => current_time('mysql', true),
            ]);
        }
    }

    private function sanitizeFeatures(array $features): array
    {
        $defaults = [
            'analytics' => true,
            'templates' => true,
            'personality' => true,
            'embeds' => true,
            'exports' => true,
            'invitations' => true,
            'white_label' => false,
            'approval_workflow' => true,
        ];
        foreach ($defaults as $key => $default) {
            $defaults[$key] = array_key_exists($key, $features) ? !empty($features[$key]) : $default;
        }
        return $defaults;
    }

    private function sanitizeBranding(array $branding): array
    {
        return [
            'logo_id' => absint($branding['logo_id'] ?? 0),
            'logo_url' => esc_url_raw((string) ($branding['logo_url'] ?? '')),
            'favicon_url' => esc_url_raw((string) ($branding['favicon_url'] ?? '')),
            'accent' => sanitize_hex_color((string) ($branding['accent'] ?? '#d9bd85')) ?: '#d9bd85',
            'accent_secondary' => sanitize_hex_color((string) ($branding['accent_secondary'] ?? '#b9a7ff')) ?: '#b9a7ff',
            'footer_text' => sanitize_text_field((string) ($branding['footer_text'] ?? '')),
            'custom_domain' => $this->normaliseDomain((string) ($branding['custom_domain'] ?? '')),
        ];
    }

    private function uniqueSlug(string $value, int $excludeId): string
    {
        global $wpdb;
        $base = sanitize_title($value) ?: 'organization';
        $candidate = $base;
        $suffix = 2;
        while ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->organizations} WHERE slug=%s AND id!=%d",
            $candidate,
            $excludeId
        )) > 0) {
            $candidate = $base . '-' . $suffix++;
        }
        return $candidate;
    }

    private function normaliseDomain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (!str_contains($value, '://')) {
            $value = 'https://' . $value;
        }
        $host = wp_parse_url($value, PHP_URL_HOST);
        return is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : '';
    }

    private function normaliseRole(string $role): string
    {
        return in_array($role, ['creator_admin', 'creator', 'viewer'], true) ? $role : 'creator';
    }

    private function dateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    private function json(string $value): array
    {
        $decoded = json_decode($value !== '' ? $value : '{}', true);
        return is_array($decoded) ? $decoded : [];
    }
}

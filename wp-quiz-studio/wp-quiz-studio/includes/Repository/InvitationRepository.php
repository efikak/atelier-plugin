<?php

declare(strict_types=1);

namespace WPQuizStudio\Repository;

/** Creates secure, expiring Organization invitations. */
final class InvitationRepository
{
    private string $table;

    public function __construct(private OrganizationRepository $organizations)
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpqs_invitations';
    }

    /** @return array{invitation:array<string,mixed>,token:string} */
    public function create(int $organizationId, string $email, string $role, int $invitedBy, int $days = 7): array
    {
        global $wpdb;
        $email = sanitize_email($email);
        if ($email === '') {
            throw new \RuntimeException(__('Το email δεν είναι έγκυρο.', 'wp-quiz-studio'));
        }
        if (!$this->organizations->emailAllowed($organizationId, $email)) {
            throw new \RuntimeException(__('Το email δεν ανήκει σε εγκεκριμένο domain του οργανισμού.', 'wp-quiz-studio'));
        }
        $role = in_array($role, ['creator_admin', 'creator', 'viewer'], true) ? $role : 'creator';

        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE organization_id=%d AND email=%s AND status='pending' ORDER BY id DESC LIMIT 1",
            $organizationId,
            $email
        ), ARRAY_A);
        $organization = $this->organizations->find($organizationId);
        if (!$organization || ($organization['status'] ?? '') !== 'active') {
            throw new \RuntimeException(__('Ο οργανισμός δεν είναι ενεργός.', 'wp-quiz-studio'));
        }
        $expiresAt = (string) ($organization['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt . ' UTC') <= time()) {
            throw new \RuntimeException(__('Η πρόσβαση του οργανισμού έχει λήξει.', 'wp-quiz-studio'));
        }
        $activeAndReserved = (int) ($organization['used_seats'] ?? 0) + (int) ($organization['reserved_seats'] ?? 0) - ($pending ? 1 : 0);
        if ($activeAndReserved >= (int) ($organization['seat_limit'] ?? 0)) {
            throw new \RuntimeException(__('Δεν υπάρχουν διαθέσιμες θέσεις. Οι εκκρεμείς προσκλήσεις δεσμεύουν θέση.', 'wp-quiz-studio'));
        }
        if ($role === 'creator_admin') {
            $adminCount = (int) ($organization['creator_admins'] ?? 0) + (int) ($organization['reserved_creator_admins'] ?? 0) - ($pending && ($pending['org_role'] ?? '') === 'creator_admin' ? 1 : 0);
            if ($adminCount >= (int) ($organization['creator_admin_limit'] ?? 0)) {
                throw new \RuntimeException(__('Έχει συμπληρωθεί το όριο Creator Admins, μαζί με τις εκκρεμείς προσκλήσεις.', 'wp-quiz-studio'));
            }
        }

        $token = $this->generateToken();
        $record = [
            'organization_id' => $organizationId,
            'email' => $email,
            'org_role' => $role,
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + max(1, $days) * DAY_IN_SECONDS),
            'invited_by' => $invitedBy,
            'created_at' => current_time('mysql', true),
            'accepted_at' => null,
        ];

        if ($pending) {
            if ($wpdb->update($this->table, $record, ['id' => (int) $pending['id']]) === false) {
                throw new \RuntimeException($wpdb->last_error ?: __('Δεν ανανεώθηκε η πρόσκληση.', 'wp-quiz-studio'));
            }
            $id = (int) $pending['id'];
        } else {
            if ($wpdb->insert($this->table, $record) === false) {
                throw new \RuntimeException($wpdb->last_error ?: __('Δεν δημιουργήθηκε η πρόσκληση.', 'wp-quiz-studio'));
            }
            $id = (int) $wpdb->insert_id;
        }

        return ['invitation' => $this->find($id) ?: [], 'token' => $token];
    }


    /**
     * Regenerates the token of an existing invitation without creating a second
     * reserved seat. Accepted invitations cannot be resent.
     *
     * @return array{invitation:array<string,mixed>,token:string}
     */
    public function resend(int $organizationId, int $id, int $invitedBy, int $days = 7): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id=%d AND organization_id=%d LIMIT 1",
            $id,
            $organizationId
        ), ARRAY_A);

        if (!$row) {
            throw new \RuntimeException(__('Η πρόσκληση δεν βρέθηκε.', 'wp-quiz-studio'));
        }
        if (($row['status'] ?? '') === 'accepted') {
            throw new \RuntimeException(__('Η πρόσκληση έχει ήδη γίνει αποδεκτή και δεν μπορεί να σταλεί ξανά.', 'wp-quiz-studio'));
        }

        $organization = $this->organizations->find($organizationId);
        if (!$organization || ($organization['status'] ?? '') !== 'active') {
            throw new \RuntimeException(__('Ο οργανισμός δεν είναι ενεργός.', 'wp-quiz-studio'));
        }
        $organizationExpiresAt = (string) ($organization['expires_at'] ?? '');
        if ($organizationExpiresAt !== '' && strtotime($organizationExpiresAt . ' UTC') <= time()) {
            throw new \RuntimeException(__('Η πρόσβαση του οργανισμού έχει λήξει.', 'wp-quiz-studio'));
        }

        $currentlyReserved = ($row['status'] ?? '') === 'pending'
            && strtotime((string) ($row['expires_at'] ?? '') . ' UTC') > time();
        $activeAndReserved = (int) ($organization['used_seats'] ?? 0)
            + (int) ($organization['reserved_seats'] ?? 0)
            - ($currentlyReserved ? 1 : 0);
        if ($activeAndReserved >= (int) ($organization['seat_limit'] ?? 0)) {
            throw new \RuntimeException(__('Δεν υπάρχουν διαθέσιμες θέσεις για την επαναποστολή της πρόσκλησης.', 'wp-quiz-studio'));
        }

        if (($row['org_role'] ?? '') === 'creator_admin') {
            $adminCount = (int) ($organization['creator_admins'] ?? 0)
                + (int) ($organization['reserved_creator_admins'] ?? 0)
                - ($currentlyReserved ? 1 : 0);
            if ($adminCount >= (int) ($organization['creator_admin_limit'] ?? 0)) {
                throw new \RuntimeException(__('Έχει συμπληρωθεί το όριο Creator Admins.', 'wp-quiz-studio'));
            }
        }

        $token = $this->generateToken();
        $updated = $wpdb->update(
            $this->table,
            [
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => gmdate('Y-m-d H:i:s', time() + max(1, $days) * DAY_IN_SECONDS),
                'invited_by' => $invitedBy,
                'created_at' => current_time('mysql', true),
                'accepted_at' => null,
            ],
            ['id' => $id, 'organization_id' => $organizationId]
        );

        if ($updated === false) {
            throw new \RuntimeException($wpdb->last_error ?: __('Δεν ανανεώθηκε η πρόσκληση.', 'wp-quiz-studio'));
        }

        return ['invitation' => $this->find($id) ?: [], 'token' => $token];
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id), ARRAY_A);
        return $row ? $this->decode($row) : null;
    }

    public function findByToken(string $token): ?array
    {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE token_hash=%s LIMIT 1",
            hash('sha256', $token)
        ), ARRAY_A);
        return $row ? $this->decode($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function all(int $organizationId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE organization_id=%d ORDER BY created_at DESC",
            $organizationId
        ), ARRAY_A) ?: [];
        return array_map(fn (array $row): array => $this->decode($row), $rows);
    }

    public function revoke(int $organizationId, int $id): void
    {
        global $wpdb;
        $wpdb->update($this->table, ['status' => 'revoked'], ['id' => $id, 'organization_id' => $organizationId]);
    }

    public function accept(string $token, int $userId): array
    {
        global $wpdb;
        $invitation = $this->findByToken($token);
        if (!$invitation || $invitation['status'] !== 'pending') {
            throw new \RuntimeException(__('Η πρόσκληση δεν είναι διαθέσιμη.', 'wp-quiz-studio'));
        }
        if (strtotime((string) $invitation['expires_at'] . ' UTC') <= time()) {
            $wpdb->update($this->table, ['status' => 'expired'], ['id' => (int) $invitation['id']]);
            throw new \RuntimeException(__('Η πρόσκληση έχει λήξει.', 'wp-quiz-studio'));
        }

        $user = get_user_by('id', $userId);
        if (!$user || strcasecmp((string) $user->user_email, (string) $invitation['email']) !== 0) {
            throw new \RuntimeException(__('Συνδεθείτε με το email στο οποίο στάλθηκε η πρόσκληση.', 'wp-quiz-studio'));
        }

        $this->organizations->addMember((int) $invitation['organization_id'], $userId, (string) $invitation['org_role']);
        $wpdb->update($this->table, [
            'status' => 'accepted',
            'accepted_at' => current_time('mysql', true),
        ], ['id' => (int) $invitation['id']]);

        return $this->find((int) $invitation['id']) ?: [];
    }

    private function decode(array $row): array
    {
        foreach (['id', 'organization_id', 'invited_by'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        unset($row['token_hash']);
        return $row;
    }

    /** Generates a cryptographically strong invitation token with a safe fallback. */
    private function generateToken(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $exception) {
            // The fallback still produces a 64-character one-time token and prevents
            // an environment-specific entropy failure from crashing WordPress.
            return hash('sha256', wp_generate_password(64, true, true) . microtime(true) . wp_rand());
        }
    }

}

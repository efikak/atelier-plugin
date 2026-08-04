<?php

declare(strict_types=1);

namespace WPQuizStudio\Security;

use WP_User;
use WPQuizStudio\Repository\OrganizationRepository;

/** Tenant-aware access policy for Quiz Atelier. */
final class AccessManager
{
    public const OPTION = 'wpqs_access_settings';

    public function __construct(private ?OrganizationRepository $organizations = null)
    {
        $this->organizations ??= new OrganizationRepository();
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $defaults = [
            'access_roles' => ['editor', 'quiz_creator', 'quiz_creator_admin', 'quiz_universal_manager'],
            'publish_roles' => ['editor', 'quiz_creator_admin', 'quiz_universal_manager'],
            'analytics_roles' => ['editor', 'quiz_creator_admin', 'quiz_universal_manager'],
            'delete_roles' => ['editor', 'quiz_creator_admin', 'quiz_universal_manager'],
            'page_id' => 0,
            'show_login_form' => true,
            'login_message' => __('Συνδεθείτε για να αποκτήσετε πρόσβαση στο Quiz Atelier.', 'wp-quiz-studio'),
            'denied_message' => __('Ο λογαριασμός σας δεν έχει πρόσβαση στο Quiz Atelier.', 'wp-quiz-studio'),
        ];
        $stored = get_option(self::OPTION, []);
        $settings = array_merge($defaults, is_array($stored) ? $stored : []);
        foreach (['access_roles', 'publish_roles', 'analytics_roles', 'delete_roles'] as $key) {
            $settings[$key] = array_values(array_filter(array_map('sanitize_key', (array) $settings[$key])));
        }
        $settings['page_id'] = absint($settings['page_id']);
        $settings['show_login_form'] = !empty($settings['show_login_form']);
        $settings['login_message'] = sanitize_text_field((string) $settings['login_message']);
        $settings['denied_message'] = sanitize_text_field((string) $settings['denied_message']);
        return $settings;
    }

    public function canAccess(?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        if (!$user->exists()) {
            return false;
        }
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        $membership = $this->membershipFor($user);
        if (!$membership || !$this->organizationIsActive($membership)) {
            return false;
        }
        return $this->allowedFor('access_roles', $user) || user_can($user, Capabilities::EDIT);
    }

    public function canPublish(?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        if ($this->isSuperAdmin($user) || $this->isUniversalManager($user)) {
            return true;
        }
        $membership = $this->membershipFor($user);
        return $membership && $membership['org_role'] === 'creator_admin' && $this->organizationIsActive($membership);
    }

    public function canDelete(?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        if ($this->isSuperAdmin($user) || $this->isUniversalManager($user)) {
            return true;
        }
        $membership = $this->membershipFor($user);
        return $membership && $membership['org_role'] === 'creator_admin' && $this->organizationIsActive($membership);
    }

    public function canViewAnalytics(?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        if ($this->isSuperAdmin($user) || $this->isUniversalManager($user)) {
            return true;
        }
        $membership = $this->membershipFor($user);
        if (!$membership || !$this->organizationIsActive($membership)) {
            return false;
        }
        return $membership['org_role'] === 'creator_admin' || !empty($membership['features']['analytics']);
    }

    public function canManageOrganizations(?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        return $this->isSuperAdmin($user) || user_can($user, Capabilities::MANAGE_ORGANIZATIONS);
    }

    public function canManageTeam(?WP_User $user = null, int $organizationId = 0): bool
    {
        $user ??= wp_get_current_user();
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        $membership = $organizationId > 0
            ? $this->organizations->membership($organizationId, (int) $user->ID)
            : $this->membershipFor($user);
        return $membership && $membership['org_role'] === 'creator_admin' && $this->organizationIsActive($membership);
    }

    public function canManageUniversal(?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        return $this->isSuperAdmin($user) || $this->isUniversalManager($user);
    }

    /** Checks whether an Organization feature is enabled for the current tenant. */
    public function featureEnabled(string $feature, ?WP_User $user = null, int $organizationId = 0): bool
    {
        $user ??= wp_get_current_user();
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        $membership = $organizationId > 0
            ? $this->organizations->membership($organizationId, (int) $user->ID)
            : $this->membershipFor($user);
        if (!$membership || !$this->organizationIsActive($membership)) {
            return false;
        }
        $features = (array) ($membership['features'] ?? []);
        return !array_key_exists($feature, $features) || !empty($features[$feature]);
    }

    public function canReview(?WP_User $user = null, int $organizationId = 0): bool
    {
        return $this->canManageTeam($user, $organizationId) || $this->isSuperAdmin($user ?: wp_get_current_user());
    }

    /** @param array<string,mixed> $quiz */
    public function canViewQuiz(array $quiz, ?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        if (($quiz['visibility_scope'] ?? '') === 'universal') {
            return $this->canAccess($user);
        }
        $membership = $this->membershipFor($user);
        if (!$membership || (int) $membership['organization_id'] !== (int) ($quiz['organization_id'] ?? 0)) {
            return false;
        }
        if ($membership['org_role'] === 'creator_admin') {
            return true;
        }
        if (($quiz['visibility_scope'] ?? 'personal') === 'organization') {
            return true;
        }
        return (int) ($quiz['author_id'] ?? 0) === (int) $user->ID;
    }

    /** @param array<string,mixed> $quiz */
    public function canEditQuiz(array $quiz, ?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        if (($quiz['visibility_scope'] ?? '') === 'universal') {
            return $this->isUniversalManager($user);
        }
        $membership = $this->membershipFor($user);
        if (!$membership || (int) $membership['organization_id'] !== (int) ($quiz['organization_id'] ?? 0)) {
            return false;
        }
        if ($membership['org_role'] === 'creator_admin') {
            return true;
        }
        return $membership['org_role'] === 'creator'
            && (int) ($quiz['author_id'] ?? 0) === (int) $user->ID
            && !in_array((string) ($quiz['workflow_status'] ?? 'draft'), ['approved', 'published', 'archived'], true);
    }

    /** @param array<string,mixed> $quiz */
    public function canDeleteQuiz(array $quiz, ?WP_User $user = null): bool
    {
        $user ??= wp_get_current_user();
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        if (($quiz['visibility_scope'] ?? '') === 'universal') {
            return $this->isUniversalManager($user);
        }
        $membership = $this->membershipFor($user);
        return $membership
            && (int) $membership['organization_id'] === (int) ($quiz['organization_id'] ?? 0)
            && ($membership['org_role'] === 'creator_admin'
                || ($membership['org_role'] === 'creator' && (int) ($quiz['author_id'] ?? 0) === (int) $user->ID && ($quiz['workflow_status'] ?? 'draft') === 'draft'));
    }

    /** @return array<string,mixed> */
    public function context(?WP_User $user = null): array
    {
        $user ??= wp_get_current_user();
        $membership = $user->exists() ? $this->membershipFor($user) : null;
        $isSuperAdmin = $this->isSuperAdmin($user);
        $organizationId = (int) ($membership['organization_id'] ?? 0);
        if ($isSuperAdmin && $organizationId <= 0) {
            $organizationId = $this->organizations->defaultOrganizationId();
        }
        return [
            'user_id' => (int) $user->ID,
            'is_super_admin' => $isSuperAdmin,
            'is_universal_manager' => $this->isUniversalManager($user),
            'membership' => $membership,
            'organization_id' => $organizationId,
            'organization_role' => (string) ($membership['org_role'] ?? ''),
            'can_manage_team' => $this->canManageTeam($user),
            'can_manage_organizations' => $this->canManageOrganizations($user),
            'can_manage_universal' => $this->canManageUniversal($user),
            'can_review' => $this->canReview($user),
            'account_status' => $this->accountStatus($user, $membership),
            'is_approved' => $this->accountStatus($user, $membership) === 'approved',
        ];
    }

    public function currentOrganizationId(?WP_User $user = null): int
    {
        $context = $this->context($user);
        if ($context['is_super_admin'] && $context['organization_id'] <= 0) {
            return $this->organizations->defaultOrganizationId();
        }
        return (int) $context['organization_id'];
    }

    private function isSuperAdmin(WP_User $user): bool
    {
        return user_can($user, 'manage_options');
    }

    private function isUniversalManager(WP_User $user): bool
    {
        return user_can($user, Capabilities::UNIVERSAL) || in_array('quiz_universal_manager', (array) $user->roles, true);
    }

    /**
     * Resolve the active Organization membership and repair legacy approved accounts.
     *
     * Quiz Atelier 3.x approves creators through the `qa_approval_status` user meta and
     * the `quiz_creator` / `quiz_creator_admin` roles. Older installations may therefore
     * have a fully approved WordPress user without a row in the Organization members table.
     * This method safely provisions that missing row into the default Organization.
     */
    private function membershipFor(WP_User $user): ?array
    {
        if (!$user->exists()) {
            return null;
        }

        $membership = $this->organizations->currentForUser((int) $user->ID);
        if ($membership) {
            return $membership;
        }

        if (!$this->isApprovedCreator($user)) {
            return null;
        }

        $organizationId = $this->organizations->defaultOrganizationId();
        if ($organizationId <= 0) {
            return null;
        }

        $roles = (array) $user->roles;
        $organizationRole = in_array('quiz_creator_admin', $roles, true)
            ? 'creator_admin'
            : (in_array('quiz_viewer', $roles, true) ? 'viewer' : 'creator');

        try {
            $this->organizations->addMember($organizationId, (int) $user->ID, $organizationRole, 'active');
        } catch (\RuntimeException) {
            // A full or suspended Organization must not be bypassed by automatic repair.
            return null;
        }

        return $this->organizations->currentForUser((int) $user->ID);
    }

    private function isApprovedCreator(WP_User $user): bool
    {
        $roles = (array) $user->roles;
        $approvalStatus = sanitize_key((string) get_user_meta((int) $user->ID, 'qa_approval_status', true));

        if ($approvalStatus === 'rejected' || $approvalStatus === 'pending' || in_array('quiz_member_pending', $roles, true)) {
            return false;
        }

        if ($approvalStatus === 'approved') {
            return true;
        }

        return array_intersect(
            ['quiz_creator', 'quiz_creator_admin', 'quiz_universal_manager', 'editor'],
            $roles
        ) !== [] || user_can($user, Capabilities::EDIT);
    }

    private function accountStatus(WP_User $user, ?array $membership): string
    {
        if ($this->isSuperAdmin($user)) {
            return 'approved';
        }

        $approvalStatus = sanitize_key((string) get_user_meta((int) $user->ID, 'qa_approval_status', true));
        if (in_array($approvalStatus, ['pending', 'rejected'], true)) {
            return $approvalStatus;
        }

        return $membership && $this->organizationIsActive($membership) ? 'approved' : 'unassigned';
    }

    private function organizationIsActive(array $membership): bool
    {
        if (($membership['status'] ?? 'active') !== 'active' || ($membership['organization_status'] ?? 'active') !== 'active') {
            return false;
        }
        $expires = (string) ($membership['expires_at'] ?? '');
        return $expires === '' || strtotime($expires . ' UTC') > time();
    }

    private function allowedFor(string $settingKey, WP_User $user): bool
    {
        $roles = (array) ($this->settings()[$settingKey] ?? []);
        return array_intersect($roles, (array) $user->roles) !== [];
    }
}

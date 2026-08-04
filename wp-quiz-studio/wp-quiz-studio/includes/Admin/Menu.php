<?php

declare(strict_types=1);

namespace WPQuizStudio\Admin;

use WPQuizStudio\Security\AccessManager;
use WPQuizStudio\Security\Capabilities;

/** Registers the Quiz Atelier application shell and tenant-aware navigation. */
final class Menu
{
    public function __construct(private AccessManager $access)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_login', static function (string $login, \WP_User $user): void {
            update_user_meta((int) $user->ID, 'wpqs_last_login', current_time('mysql', true));
        }, 10, 2);
    }

    public function menu(): void
    {
        if (!$this->access->canAccess()) {
            return;
        }

        add_menu_page(
            __('Quiz Atelier', 'wp-quiz-studio'),
            __('Quiz Atelier', 'wp-quiz-studio'),
            Capabilities::EDIT,
            'wp-quiz-studio',
            [$this, 'page'],
            'dashicons-welcome-write-blog',
            26
        );

        add_submenu_page('wp-quiz-studio', __('Dashboard', 'wp-quiz-studio'), __('Dashboard', 'wp-quiz-studio'), Capabilities::EDIT, 'wp-quiz-studio', [$this, 'page']);
        add_submenu_page('wp-quiz-studio', __('Quiz Library', 'wp-quiz-studio'), __('Quiz Library', 'wp-quiz-studio'), Capabilities::EDIT, 'wp-quiz-studio-library', [$this, 'page']);
        add_submenu_page('wp-quiz-studio', __('Templates', 'wp-quiz-studio'), __('Templates', 'wp-quiz-studio'), Capabilities::EDIT, 'wp-quiz-studio-templates', [$this, 'page']);
        add_submenu_page('wp-quiz-studio', __('Κατηγορίες Quiz Atelier', 'wp-quiz-studio'), __('Κατηγορίες', 'wp-quiz-studio'), Capabilities::EDIT, 'wp-quiz-studio-categories', [$this, 'page']);
        add_submenu_page('wp-quiz-studio', __('Τεκμηρίωση Quiz Atelier', 'wp-quiz-studio'), __('Τεκμηρίωση', 'wp-quiz-studio'), Capabilities::EDIT, 'wp-quiz-studio-documentation', [$this, 'page']);

        if ($this->access->canManageTeam()) {
            add_submenu_page('wp-quiz-studio', __('Το Workspace μου', 'wp-quiz-studio'), __('Το Workspace μου', 'wp-quiz-studio'), Capabilities::EDIT, 'wp-quiz-studio-workspace', [$this, 'page']);
            add_submenu_page('wp-quiz-studio', __('Η ομάδα μου', 'wp-quiz-studio'), __('Η ομάδα μου', 'wp-quiz-studio'), Capabilities::EDIT, 'wp-quiz-studio-team', [$this, 'page']);
            add_submenu_page('wp-quiz-studio', __('Activity Log', 'wp-quiz-studio'), __('Activity Log', 'wp-quiz-studio'), Capabilities::EDIT, 'wp-quiz-studio-activity', [$this, 'page']);
        }

        if (current_user_can('manage_options')) {
            add_submenu_page('wp-quiz-studio', __('Χρήστες & Workspaces', 'wp-quiz-studio'), __('Χρήστες & Workspaces', 'wp-quiz-studio'), 'manage_options', 'wp-quiz-studio-user-workspaces', [$this, 'page']);
            add_submenu_page('wp-quiz-studio', __('System Status', 'wp-quiz-studio'), __('System Status', 'wp-quiz-studio'), 'manage_options', 'wp-quiz-studio-system', [$this, 'page']);
        }

        if ($this->access->canManageOrganizations()) {
            add_submenu_page('wp-quiz-studio', __('Organizations', 'wp-quiz-studio'), __('Organizations', 'wp-quiz-studio'), Capabilities::MANAGE_ORGANIZATIONS, 'wp-quiz-studio-organizations', [$this, 'page']);
        }
    }

    public function page(): void
    {
        echo '<div class="wrap wpqs-admin-wrap"><div id="wpqs-app"></div></div>';
    }

    public function assets(string $hook): void
    {
        if (!str_contains($hook, 'wp-quiz-studio')) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('wpqs-studio', WPQS_URL . 'assets/studio.css', [], WPQS_VERSION);
        wp_enqueue_style('wpqs-atelier-theme', WPQS_URL . 'assets/atelier-theme.css', ['wpqs-studio'], WPQS_VERSION);
        wp_enqueue_style('wpqs-atelier-ui-100', WPQS_URL . 'assets/atelier-ui-1.0.0.css', ['wpqs-atelier-theme'], WPQS_VERSION);
        wp_enqueue_script('wpqs-studio', WPQS_URL . 'assets/studio-1.0.0.js', ['media-editor'], WPQS_VERSION, true);
        wp_add_inline_script('wpqs-studio', 'window.WPQS=' . wp_json_encode($this->clientConfig()) . ';', 'before');
    }

    /** @return array<string,mixed> */
    private function clientConfig(): array
    {
        $user = wp_get_current_user();
        $context = $this->access->context($user);
        return [
            'api' => esc_url_raw(rest_url('wp-quiz-studio/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'site' => home_url('/'),
            'version' => WPQS_VERSION,
            'isFront' => false,
            'canPublish' => $this->access->canPublish(),
            'canDelete' => $this->access->canDelete(),
            'canAnalytics' => $this->access->canViewAnalytics(),
            'canManageTeam' => $this->access->canManageTeam(),
            'canManageOrganizations' => $this->access->canManageOrganizations(),
            'canManageUserWorkspaces' => current_user_can('manage_options'),
            'canManageUniversal' => $this->access->canManageUniversal(),
            'canReview' => $this->access->canReview(),
            'context' => $context,
            'initialTab' => $this->initialTab(),
            'userName' => $user->display_name,
            'userPreferences' => UserPreferences::get((int) $user->ID),
        ];
    }

    private function initialTab(): string
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        return match ($page) {
            'wp-quiz-studio-library' => 'library',
            'wp-quiz-studio-categories' => 'categories',
            'wp-quiz-studio-templates' => 'templates',
            'wp-quiz-studio-workspace' => 'workspace',
            'wp-quiz-studio-team' => 'team',
            'wp-quiz-studio-activity' => 'activity',
            'wp-quiz-studio-organizations' => 'organizations',
            'wp-quiz-studio-user-workspaces' => 'user-workspaces',
            'wp-quiz-studio-system' => 'system',
            'wp-quiz-studio-documentation' => 'documentation',
            default => 'dashboard',
        };
    }
}

<?php

declare(strict_types=1);

namespace WPQuizStudio\Front;

use WPQuizStudio\Admin\UserPreferences;
use WPQuizStudio\Security\AccessManager;

/** Renders the protected no-admin editor on any normal WordPress page. */
final class Studio
{
    public function __construct(private AccessManager $access)
    {
    }

    public function register(): void
    {
        add_shortcode('wp_quiz_studio_builder', [$this, 'shortcode']);
        add_shortcode('wp_quiz_studio_portal', [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
    }

    public function registerAssets(): void
    {
        wp_register_style('wpqs-studio-front', WPQS_URL . 'assets/studio.css', [], WPQS_VERSION);
        wp_register_style('wpqs-studio-front-layout', WPQS_URL . 'assets/front-studio.css', ['wpqs-studio-front'], WPQS_VERSION);
        wp_register_style('wpqs-atelier-theme', WPQS_URL . 'assets/atelier-theme.css', ['wpqs-studio-front-layout'], WPQS_VERSION);
        wp_register_style('wpqs-atelier-ui-100', WPQS_URL . 'assets/atelier-ui-1.0.0.css', ['wpqs-atelier-theme'], WPQS_VERSION);
        wp_register_script('wpqs-studio-front', WPQS_URL . 'assets/studio-1.0.0.js', ['media-editor'], WPQS_VERSION, true);

        global $post;
        if (!$post) {
            return;
        }

        $content = (string) $post->post_content;
        if (!has_shortcode($content, 'wp_quiz_studio_builder') && !has_shortcode($content, 'wp_quiz_studio_portal')) {
            return;
        }

        if (is_user_logged_in() && $this->access->canAccess()) {
            $this->enqueueStudio();
        }
    }

    public function shortcode(): string
    {
        $settings = $this->access->settings();

        if (!is_user_logged_in()) {
            $message = '<p>' . esc_html($settings['login_message']) . '</p>';
            $form = '';
            if ($settings['show_login_form']) {
                $form = wp_login_form([
                    'echo' => false,
                    'redirect' => esc_url_raw($this->currentUrl()),
                    'remember' => true,
                ]);
            }
            return '<div class="wpqs-access-gate wpqs-login-gate">' . $message . $form . '</div>';
        }

        if (!$this->access->canAccess()) {
            $context = $this->access->context();
            $message = (string) $settings['denied_message'];
            if (($context['account_status'] ?? '') === 'unassigned') {
                $message = __('Ο λογαριασμός σας είναι εγκεκριμένος, αλλά δεν έχει ακόμη αντιστοιχιστεί σε ενεργό Organization ή δεν υπάρχει διαθέσιμη θέση. Επικοινωνήστε με τον Creator Admin.', 'wp-quiz-studio');
            }
            return '<div class="wpqs-access-gate wpqs-denied-gate"><p>' . esc_html($message) . '</p></div>';
        }

        $this->enqueueStudio();
        return '<div class="wpqs-front-studio" id="wpqs-app"></div>';
    }

    private function enqueueStudio(): void
    {
        wp_enqueue_media();
        wp_enqueue_style('wpqs-atelier-ui-100');
        wp_enqueue_script('wpqs-studio-front');
        $context = $this->access->context();
        wp_add_inline_script('wpqs-studio-front', 'window.WPQS=' . wp_json_encode([
            'api' => esc_url_raw(rest_url('wp-quiz-studio/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'site' => home_url('/'),
            'version' => WPQS_VERSION,
            'isFront' => true,
            'canPublish' => $this->access->canPublish(),
            'canDelete' => $this->access->canDelete(),
            'canAnalytics' => $this->access->canViewAnalytics(),
            'canManageTeam' => $this->access->canManageTeam(),
            'canManageOrganizations' => $this->access->canManageOrganizations(),
            'canManageUserWorkspaces' => current_user_can('manage_options'),
            'canManageUniversal' => $this->access->canManageUniversal(),
            'canReview' => $this->access->canReview(),
            'context' => $context,
            'initialTab' => 'dashboard',
            'userName' => wp_get_current_user()->display_name,
            'userPreferences' => UserPreferences::get(),
        ]) . ';', 'before');
    }

    private function currentUrl(): string
    {
        global $wp;
        if (isset($wp) && isset($wp->request)) {
            return home_url(add_query_arg([], $wp->request));
        }
        return home_url('/');
    }
}

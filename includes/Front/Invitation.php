<?php

declare(strict_types=1);

namespace WPQuizStudio\Front;

use WPQuizStudio\Repository\ActivityLogRepository;
use WPQuizStudio\Repository\InvitationRepository;
use WPQuizStudio\Security\AccessManager;

/** Handles secure invitations, including first-time account creation. */
final class Invitation
{
    public function __construct(
        private InvitationRepository $invitations,
        private ActivityLogRepository $activity,
        private AccessManager $access
    ) {
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'handle']);
    }

    public function handle(): void
    {
        $token = sanitize_text_field((string) ($_GET['wpqs_invitation'] ?? $_POST['wpqs_invitation'] ?? ''));
        if ($token === '') {
            return;
        }

        $invitation = $this->invitations->findByToken($token);
        if (!$invitation || ($invitation['status'] ?? '') !== 'pending') {
            $this->error(__('Η πρόσκληση δεν είναι πλέον διαθέσιμη.', 'wp-quiz-studio'));
        }
        if (strtotime((string) $invitation['expires_at'] . ' UTC') <= time()) {
            $this->error(__('Η πρόσκληση έχει λήξει. Ζητήστε νέα πρόσκληση από τον Creator Admin.', 'wp-quiz-studio'));
        }

        if (!is_user_logged_in()) {
            $existing = get_user_by('email', (string) $invitation['email']);
            if ($existing) {
                wp_safe_redirect(wp_login_url($this->currentUrl()));
                exit;
            }

            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                $this->createAccount($token, $invitation);
            }
            $this->registrationForm($token, $invitation);
        }

        try {
            $accepted = $this->invitations->accept($token, get_current_user_id());
            $this->activity->log('invitation_accepted', 'user', get_current_user_id(), (int) ($accepted['organization_id'] ?? 0));
            $this->redirectAfterAcceptance();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
        }
    }

    /** @param array<string,mixed> $invitation */
    private function createAccount(string $token, array $invitation): never
    {
        if (!wp_verify_nonce(sanitize_text_field((string) ($_POST['_wpnonce'] ?? '')), 'wpqs_accept_invitation_' . (int) $invitation['id'])) {
            $this->error(__('Η φόρμα έληξε. Ανοίξτε ξανά τον σύνδεσμο της πρόσκλησης.', 'wp-quiz-studio'));
        }

        $displayName = sanitize_text_field((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if ($displayName === '') {
            $this->registrationForm($token, $invitation, __('Συμπληρώστε το όνομά σας.', 'wp-quiz-studio'));
        }
        if (strlen($password) < 10) {
            $this->registrationForm($token, $invitation, __('Ο κωδικός πρέπει να έχει τουλάχιστον 10 χαρακτήρες.', 'wp-quiz-studio'));
        }
        if (!hash_equals($password, $passwordConfirm)) {
            $this->registrationForm($token, $invitation, __('Οι δύο κωδικοί δεν ταιριάζουν.', 'wp-quiz-studio'));
        }

        $email = sanitize_email((string) $invitation['email']);
        $base = sanitize_user((string) strstr($email, '@', true), true) ?: 'quiz-user';
        $username = $base;
        $suffix = 2;
        while (username_exists($username)) {
            $username = $base . '-' . $suffix++;
        }
        $userId = wp_create_user($username, $password, $email);
        if (is_wp_error($userId)) {
            $this->registrationForm($token, $invitation, $userId->get_error_message());
        }

        wp_update_user(['ID' => $userId, 'display_name' => $displayName, 'first_name' => $displayName]);
        $role = match ((string) ($invitation['org_role'] ?? 'creator')) {
            'creator_admin' => 'quiz_creator_admin',
            'viewer' => 'quiz_viewer',
            default => 'quiz_creator',
        };
        $user = get_user_by('id', $userId);
        if ($user) {
            $user->set_role($role);
        }
        wp_set_current_user((int) $userId);
        wp_set_auth_cookie((int) $userId, true, is_ssl());

        try {
            $accepted = $this->invitations->accept($token, (int) $userId);
            $this->activity->log('invitation_account_created', 'user', (int) $userId, (int) ($accepted['organization_id'] ?? 0));
            $this->redirectAfterAcceptance();
        } catch (\RuntimeException $exception) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $userId);
            wp_clear_auth_cookie();
            $this->error($exception->getMessage());
        }
    }

    /** @param array<string,mixed> $invitation */
    private function registrationForm(string $token, array $invitation, string $error = ''): never
    {
        status_header(200);
        nocache_headers();
        $email = (string) $invitation['email'];
        $nonce = wp_create_nonce('wpqs_accept_invitation_' . (int) $invitation['id']);
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__('Αποδοχή πρόσκλησης — Quiz Atelier', 'wp-quiz-studio') . '</title><style>';
        echo 'html,body{margin:0;min-height:100%;font-family:Inter,system-ui,sans-serif;background:#08080a;color:#f6f4ef}.qa-invite{min-height:100vh;display:grid;place-items:center;padding:30px;background:radial-gradient(circle at 12% 10%,rgba(217,189,133,.13),transparent 28rem),radial-gradient(circle at 88% 5%,rgba(185,167,255,.13),transparent 28rem),#08080a}.qa-card{box-sizing:border-box;width:min(520px,100%);padding:36px;border:1px solid #34343d;border-radius:24px;background:#15151b;box-shadow:0 30px 90px rgba(0,0,0,.42)}.qa-kicker{font-size:11px;letter-spacing:.14em;color:#d9bd85;font-weight:800}.qa-card h1{font-family:Georgia,serif;font-size:34px;font-weight:400;margin:12px 0}.qa-card p{color:#b8b5be;line-height:1.6}.qa-card label{display:grid;gap:7px;margin:18px 0;font-weight:700}.qa-card input{box-sizing:border-box;width:100%;padding:13px 14px;border:1px solid #4a4852;border-radius:10px;background:#202027;color:#fff;font:inherit}.qa-card input:focus{outline:3px solid rgba(217,189,133,.2);border-color:#d9bd85}.qa-card button{width:100%;padding:14px;border:0;border-radius:11px;background:#d9bd85;color:#111;font-weight:850;cursor:pointer}.qa-error{padding:12px;border:1px solid rgba(255,139,139,.5);border-radius:10px;background:rgba(255,139,139,.08);color:#ffb4b4}.qa-email{padding:11px 13px;border-radius:9px;background:#202027;color:#f2dfb8}</style></head><body><main class="qa-invite"><form class="qa-card" method="post"><span class="qa-kicker">QUIZ ATELIER INVITATION</span><h1>' . esc_html__('Δημιουργία λογαριασμού', 'wp-quiz-studio') . '</h1><p>' . esc_html__('Ο λογαριασμός θα συνδεθεί αυτόματα με τον σωστό Organization και θα καταλάβει μία διαθέσιμη θέση.', 'wp-quiz-studio') . '</p>';
        if ($error !== '') {
            echo '<p class="qa-error" role="alert">' . esc_html($error) . '</p>';
        }
        echo '<div class="qa-email">' . esc_html($email) . '</div><label>' . esc_html__('Ονοματεπώνυμο', 'wp-quiz-studio') . '<input name="display_name" autocomplete="name" required value="' . esc_attr((string) ($_POST['display_name'] ?? '')) . '"></label><label>' . esc_html__('Κωδικός', 'wp-quiz-studio') . '<input type="password" name="password" autocomplete="new-password" minlength="10" required></label><label>' . esc_html__('Επιβεβαίωση κωδικού', 'wp-quiz-studio') . '<input type="password" name="password_confirm" autocomplete="new-password" minlength="10" required></label><input type="hidden" name="wpqs_invitation" value="' . esc_attr($token) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '"><button type="submit">' . esc_html__('Αποδοχή και είσοδος', 'wp-quiz-studio') . '</button></form></main></body></html>';
        exit;
    }

    private function redirectAfterAcceptance(): never
    {
        $settings = $this->access->settings();
        $url = (int) $settings['page_id'] > 0 ? get_permalink((int) $settings['page_id']) : admin_url('admin.php?page=wp-quiz-studio');
        wp_safe_redirect(add_query_arg('wpqs_invitation_accepted', '1', $url ?: home_url('/')));
        exit;
    }

    private function error(string $message): never
    {
        wp_die(
            '<h1>' . esc_html__('Η πρόσκληση δεν ολοκληρώθηκε', 'wp-quiz-studio') . '</h1><p>' . esc_html($message) . '</p>',
            esc_html__('Quiz Atelier', 'wp-quiz-studio'),
            ['response' => 400]
        );
    }

    private function currentUrl(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        return esc_url_raw($scheme . sanitize_text_field((string) ($_SERVER['HTTP_HOST'] ?? '')) . sanitize_text_field((string) ($_SERVER['REQUEST_URI'] ?? '/')));
    }
}

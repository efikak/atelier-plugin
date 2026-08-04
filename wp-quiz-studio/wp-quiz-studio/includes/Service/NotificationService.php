<?php

declare(strict_types=1);

namespace WPQuizStudio\Service;

use WPQuizStudio\Repository\OrganizationRepository;

/** Sends branded transactional emails through WordPress wp_mail(). */
final class NotificationService
{
    public function __construct(private OrganizationRepository $organizations)
    {
    }

    public function invitation(int $organizationId, string $email, string $token): bool
    {
        $organization = $this->organizations->find($organizationId);
        $name = (string) ($organization['name'] ?? __('Quiz Atelier', 'wp-quiz-studio'));
        $url = add_query_arg(['wpqs_invitation' => rawurlencode($token)], home_url('/'));
        $subject = sprintf(__('Πρόσκληση στο %s', 'wp-quiz-studio'), $name);
        $body = $this->template(
            $organization,
            __('Έχετε νέα πρόσκληση', 'wp-quiz-studio'),
            sprintf(__('Έχετε προσκληθεί να συμμετέχετε στον οργανισμό «%s» στο Quiz Atelier.', 'wp-quiz-studio'), $name),
            $url,
            __('Αποδοχή πρόσκλησης', 'wp-quiz-studio')
        );
        return wp_mail($email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    public function workflow(array $quiz, string $action, string $comment = ''): void
    {
        $organizationId = (int) ($quiz['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            return;
        }
        $organization = $this->organizations->find($organizationId);
        if (!$organization) {
            return;
        }

        $members = $this->organizations->members($organizationId);
        $recipients = [];
        foreach ($members as $member) {
            $isAdmin = $member['org_role'] === 'creator_admin';
            $isOwner = (int) $member['user_id'] === (int) ($quiz['author_id'] ?? 0);
            if (($action === 'submitted' && $isAdmin) || ($action !== 'submitted' && $isOwner)) {
                $recipients[] = sanitize_email((string) $member['user_email']);
            }
        }
        $recipients = array_values(array_unique(array_filter($recipients)));
        if ($recipients === []) {
            return;
        }

        $labels = [
            'submitted' => __('υποβλήθηκε για έλεγχο', 'wp-quiz-studio'),
            'changes_requested' => __('χρειάζεται αλλαγές', 'wp-quiz-studio'),
            'approved' => __('εγκρίθηκε', 'wp-quiz-studio'),
            'published' => __('δημοσιεύτηκε', 'wp-quiz-studio'),
        ];
        $label = $labels[$action] ?? $action;
        $subject = sprintf(__('Το quiz «%1$s» %2$s', 'wp-quiz-studio'), (string) $quiz['title'], $label);
        $message = sprintf(__('Το quiz «%1$s» %2$s.', 'wp-quiz-studio'), (string) $quiz['title'], $label);
        if ($comment !== '') {
            $message .= '<br><br><strong>' . esc_html__('Σχόλιο:', 'wp-quiz-studio') . '</strong> ' . esc_html($comment);
        }
        $url = admin_url('admin.php?page=wp-quiz-studio');
        $body = $this->template($organization, $subject, $message, $url, __('Άνοιγμα Quiz Atelier', 'wp-quiz-studio'));
        wp_mail($recipients, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    /** Sends an account status update to an Organization member. */
    public function memberStatus(int $organizationId, string $email, string $status): void
    {
        $organization = $this->organizations->find($organizationId);
        if (!$organization || !is_email($email)) {
            return;
        }
        $active = $status === 'active';
        $subject = $active ? __('Ο λογαριασμός σας ενεργοποιήθηκε', 'wp-quiz-studio') : __('Ο λογαριασμός σας τέθηκε σε αναστολή', 'wp-quiz-studio');
        $message = $active
            ? __('Μπορείτε πλέον να συνδεθείτε και να χρησιμοποιήσετε το Quiz Atelier του οργανισμού σας.', 'wp-quiz-studio')
            : __('Η πρόσβασή σας στο Quiz Atelier έχει τεθεί προσωρινά σε αναστολή. Επικοινωνήστε με τον Creator Admin του οργανισμού σας.', 'wp-quiz-studio');
        wp_mail($email, $subject, $this->template($organization, $subject, $message, wp_login_url(), __('Σύνδεση', 'wp-quiz-studio')), ['Content-Type: text/html; charset=UTF-8']);
    }

    /** Warns Creator Admins before Organization access expires. */
    public function organizationExpiry(int $organizationId, int $days): void
    {
        $organization = $this->organizations->find($organizationId);
        if (!$organization) {
            return;
        }
        $recipients = $this->creatorAdminEmails($organizationId);
        if ($recipients === []) {
            return;
        }
        $subject = __('Η πρόσβαση του οργανισμού λήγει σύντομα', 'wp-quiz-studio');
        $message = sprintf(__('Η πρόσβαση του οργανισμού «%1$s» λήγει σε %2$d ημέρες.', 'wp-quiz-studio'), (string) $organization['name'], $days);
        wp_mail($recipients, $subject, $this->template($organization, $subject, $message, admin_url('admin.php?page=wp-quiz-studio'), __('Άνοιγμα Quiz Atelier', 'wp-quiz-studio')), ['Content-Type: text/html; charset=UTF-8']);
    }

    /** Warns the quiz owner before a quiz expires. */
    public function quizExpiry(array $quiz, int $days): void
    {
        $organizationId = (int) ($quiz['organization_id'] ?? 0);
        $organization = $this->organizations->find($organizationId);
        $owner = get_user_by('id', (int) ($quiz['author_id'] ?? 0));
        if (!$organization || !$owner || !is_email($owner->user_email)) {
            return;
        }
        $subject = __('Ένα quiz λήγει σύντομα', 'wp-quiz-studio');
        $message = sprintf(__('Το quiz «%1$s» λήγει σε %2$d ημέρες.', 'wp-quiz-studio'), (string) ($quiz['title'] ?? ''), $days);
        wp_mail($owner->user_email, $subject, $this->template($organization, $subject, $message, admin_url('admin.php?page=wp-quiz-studio'), __('Επεξεργασία quiz', 'wp-quiz-studio')), ['Content-Type: text/html; charset=UTF-8']);
    }

    /** Sends a compact weekly Organization analytics report. */
    public function weeklySummary(int $organizationId, array $metrics): void
    {
        $organization = $this->organizations->find($organizationId);
        $recipients = $this->creatorAdminEmails($organizationId, true);
        if (!$organization || $recipients === []) {
            return;
        }
        $subject = __('Εβδομαδιαία αναφορά Quiz Atelier', 'wp-quiz-studio');
        $message = '<p>' . esc_html(sprintf(__('Η εβδομαδιαία εικόνα του οργανισμού «%s»:', 'wp-quiz-studio'), (string) $organization['name'])) . '</p>'
            . '<table style="width:100%;border-collapse:collapse"><tr><td style="padding:8px;border-bottom:1px solid #34343d">' . esc_html__('Προβολές', 'wp-quiz-studio') . '</td><td style="padding:8px;border-bottom:1px solid #34343d;text-align:right"><strong>' . absint($metrics['views'] ?? 0) . '</strong></td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #34343d">' . esc_html__('Εκκινήσεις', 'wp-quiz-studio') . '</td><td style="padding:8px;border-bottom:1px solid #34343d;text-align:right"><strong>' . absint($metrics['starts'] ?? 0) . '</strong></td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #34343d">' . esc_html__('Ολοκληρώσεις', 'wp-quiz-studio') . '</td><td style="padding:8px;border-bottom:1px solid #34343d;text-align:right"><strong>' . absint($metrics['completions'] ?? 0) . '</strong></td></tr>'
            . '<tr><td style="padding:8px">' . esc_html__('Completion rate', 'wp-quiz-studio') . '</td><td style="padding:8px;text-align:right"><strong>' . esc_html((string) ($metrics['completion_rate'] ?? 0)) . '%</strong></td></tr></table>';
        wp_mail($recipients, $subject, $this->template($organization, $subject, $message, admin_url('admin.php?page=wp-quiz-studio-analytics'), __('Προβολή analytics', 'wp-quiz-studio')), ['Content-Type: text/html; charset=UTF-8']);
    }

    public function seatWarning(int $organizationId): void
    {
        $organization = $this->organizations->find($organizationId);
        if (!$organization || (int) $organization['seat_limit'] <= 0) {
            return;
        }
        $percent = ((int) $organization['used_seats'] / (int) $organization['seat_limit']) * 100;
        if ($percent < 80) {
            return;
        }
        $recipients = [];
        foreach ($this->organizations->members($organizationId) as $member) {
            if ($member['org_role'] === 'creator_admin') {
                $recipients[] = sanitize_email((string) $member['user_email']);
            }
        }
        if ($recipients === []) {
            return;
        }
        $subject = __('Οι διαθέσιμες θέσεις πλησιάζουν το όριο', 'wp-quiz-studio');
        $message = sprintf(
            __('Ο οργανισμός χρησιμοποιεί %1$d από τις %2$d διαθέσιμες θέσεις.', 'wp-quiz-studio'),
            (int) $organization['used_seats'],
            (int) $organization['seat_limit']
        );
        wp_mail($recipients, $subject, $this->template($organization, $subject, $message, admin_url('admin.php?page=wp-quiz-studio'), __('Διαχείριση ομάδας', 'wp-quiz-studio')), ['Content-Type: text/html; charset=UTF-8']);
    }

    /** @return list<string> */
    private function creatorAdminEmails(int $organizationId, bool $analyticsPreference = false): array
    {
        $recipients = [];
        foreach ($this->organizations->members($organizationId) as $member) {
            if ($member['org_role'] !== 'creator_admin' || $member['status'] !== 'active') {
                continue;
            }
            if ($analyticsPreference) {
                $preferences = (array) get_user_meta((int) $member['user_id'], 'wpqs_email_preferences', true);
                if (array_key_exists('analytics', $preferences) && empty($preferences['analytics'])) {
                    continue;
                }
            }
            $recipients[] = sanitize_email((string) $member['user_email']);
        }
        return array_values(array_unique(array_filter($recipients)));
    }

    private function template(?array $organization, string $title, string $message, string $url, string $button): string
    {
        $branding = (array) ($organization['branding'] ?? []);
        $accent = sanitize_hex_color((string) ($branding['accent'] ?? '#d9bd85')) ?: '#d9bd85';
        $logo = esc_url((string) ($branding['logo_url'] ?? ''));
        $name = esc_html((string) ($organization['name'] ?? 'Quiz Atelier'));
        $logoHtml = $logo !== '' ? '<img src="' . $logo . '" alt="' . $name . '" style="max-width:180px;max-height:54px">' : '<div style="font:700 23px Georgia,serif;color:#f6f4ef">' . $name . '</div>';
        return '<div style="background:#08080a;padding:34px;font-family:Arial,sans-serif;color:#f6f4ef">'
            . '<div style="max-width:620px;margin:auto;background:#15151b;border:1px solid #34343d;border-radius:18px;padding:30px">'
            . $logoHtml
            . '<h1 style="font-size:27px;margin:28px 0 12px;color:#fff">' . esc_html($title) . '</h1>'
            . '<div style="font-size:16px;line-height:1.7;color:#d7d4dc">' . wp_kses_post($message) . '</div>'
            . '<p style="margin:28px 0"><a href="' . esc_url($url) . '" style="display:inline-block;background:' . esc_attr($accent) . ';color:#111;padding:13px 20px;border-radius:10px;text-decoration:none;font-weight:700">' . esc_html($button) . '</a></p>'
            . '<p style="font-size:12px;color:#8d8994;margin-bottom:0">Quiz Atelier · effiek.gr</p>'
            . '</div></div>';
    }
}

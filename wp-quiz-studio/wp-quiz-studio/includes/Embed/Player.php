<?php

declare(strict_types=1);

namespace WPQuizStudio\Embed;

use WPQuizStudio\Repository\QuizRepository;
use WPQuizStudio\Security\EmbedPolicy;

/** Provides WordPress shortcode, iframe page and portable JavaScript embed. */
final class Player
{
    public function __construct(
        private ?QuizRepository $quizzes = null,
        private ?EmbedPolicy $embedPolicy = null
    ) {
    }

    public function register(): void
    {
        add_shortcode('wp_quiz_studio', [$this, 'shortcode']);
        add_action('init', [$this, 'rewrite']);
        add_filter('query_vars', static fn (array $vars): array => [...$vars, 'wpqs_embed', 'wpqs_embed_script']);
        add_action('template_redirect', [$this, 'embedPage']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function rewrite(): void
    {
        add_rewrite_rule('^wpqs-embed/([0-9]+)/?$', 'index.php?wpqs_embed=$matches[1]', 'top');
        add_rewrite_rule('^wpqs-embed.js/?$', 'index.php?wpqs_embed_script=1', 'top');
    }

    public function assets(): void
    {
        wp_register_style('wpqs-player', WPQS_URL . 'assets/player.css', [], WPQS_VERSION);
        wp_register_script('wpqs-player', WPQS_URL . 'assets/player-1.0.0.js', [], WPQS_VERSION, true);
        wp_add_inline_script('wpqs-player', 'window.WPQS_PLAYER=' . wp_json_encode([
            'api' => esc_url_raw(rest_url('wp-quiz-studio/v1/')),
            'locale' => determine_locale(),
            'dateFormat' => get_option('date_format') . ' ' . get_option('time_format'),
            'labels' => [
                'quiz' => __('Quiz', 'wp-quiz-studio'),
                'start' => __('Έναρξη quiz', 'wp-quiz-studio'),
                'question' => __('Ερώτηση', 'wp-quiz-studio'),
                'of' => __('από', 'wp-quiz-studio'),
                'hint' => __('Βοήθεια', 'wp-quiz-studio'),
                'continue' => __('Συνέχεια', 'wp-quiz-studio'),
                'skip' => __('Παράλειψη', 'wp-quiz-studio'),
                'chooseOne' => __('Επιλέξτε μία απάντηση.', 'wp-quiz-studio'),
                'chooseAtLeastOne' => __('Επιλέξτε τουλάχιστον μία απάντηση.', 'wp-quiz-studio'),
                'enterAnswer' => __('Γράψτε την απάντησή σας.', 'wp-quiz-studio'),
                'answerPlaceholder' => __('Πληκτρολογήστε την απάντησή σας', 'wp-quiz-studio'),
                'enterNumber' => __('Γράψτε έναν αριθμό.', 'wp-quiz-studio'),
                'completeMatching' => __('Ολοκληρώστε όλες τις αντιστοιχίσεις.', 'wp-quiz-studio'),
                'noAnswers' => __('Δεν υπάρχουν διαθέσιμες απαντήσεις.', 'wp-quiz-studio'),
                'correct' => __('Σωστά!', 'wp-quiz-studio'),
                'wrong' => __('Λάθος απάντηση', 'wp-quiz-studio'),
                'skipped' => __('Η ερώτηση παραλείφθηκε', 'wp-quiz-studio'),
                'pollRecorded' => __('Η απάντησή σας καταχωρήθηκε.', 'wp-quiz-studio'),
                'correctAnswer' => __('Η σωστή απάντηση είναι:', 'wp-quiz-studio'),
                'correctAnswers' => __('Οι σωστές απαντήσεις είναι:', 'wp-quiz-studio'),
                'explanation' => __('Επεξήγηση', 'wp-quiz-studio'),
                'timeUp' => __('Ο χρόνος τελείωσε.', 'wp-quiz-studio'),
                'calculating' => __('Υπολογισμός αποτελέσματος…', 'wp-quiz-studio'),
                'completed' => __('Ολοκληρώθηκε', 'wp-quiz-studio'),
                'defaultResult' => __('Απαντήσατε σωστά σε %1$d από %2$d ερωτήσεις.', 'wp-quiz-studio'),
                'score' => __('Βαθμολογία', 'wp-quiz-studio'),
                'personalityMatch' => __('Ταίριασμα', 'wp-quiz-studio'),
                'passed' => __('Επιτυχία', 'wp-quiz-studio'),
                'failed' => __('Δεν επιτεύχθηκε η βάση', 'wp-quiz-studio'),
                'share' => __('Κοινοποίηση', 'wp-quiz-studio'),
                'copyLink' => __('Αντιγραφή συνδέσμου', 'wp-quiz-studio'),
                'linkCopied' => __('Ο σύνδεσμος αντιγράφηκε.', 'wp-quiz-studio'),
                'restart' => __('Παίξτε ξανά', 'wp-quiz-studio'),
                'reviewAnswers' => __('Δείτε τις απαντήσεις σας', 'wp-quiz-studio'),
                'hideReview' => __('Απόκρυψη απαντήσεων', 'wp-quiz-studio'),
                'yourAnswer' => __('Η απάντησή σας:', 'wp-quiz-studio'),
                'notAnswered' => __('Δεν απαντήθηκε', 'wp-quiz-studio'),
                'correctOrder' => __('Σωστή σειρά:', 'wp-quiz-studio'),
                'moveUp' => __('Πάνω', 'wp-quiz-studio'),
                'moveDown' => __('Κάτω', 'wp-quiz-studio'),
                'unableSubmit' => __('Δεν ήταν δυνατή η υποβολή', 'wp-quiz-studio'),
                'tryAgain' => __('Δοκιμάστε ξανά αργότερα.', 'wp-quiz-studio'),
                'unavailable' => __('Το quiz δεν είναι διαθέσιμο αυτή τη στιγμή.', 'wp-quiz-studio'),
                'expired' => __('Το quiz έχει λήξει.', 'wp-quiz-studio'),
                'availableUntil' => __('Διαθέσιμο έως %s', 'wp-quiz-studio'),
                'category' => __('Κατηγορία', 'wp-quiz-studio'),
            ],
        ]) . ';', 'before');
    }

    public function shortcode(array $atts): string
    {
        $id = absint($atts['id'] ?? 0);
        if (!$id) {
            return '';
        }

        wp_enqueue_style('wpqs-player');
        wp_enqueue_script('wpqs-player');
        return '<div class="wpqs-player" data-quiz="' . esc_attr((string) $id) . '"></div>';
    }

    public function embedPage(): void
    {
        if (get_query_var('wpqs_embed_script')) {
            $this->embedScript();
        }

        $id = absint(get_query_var('wpqs_embed'));
        if (!$id) {
            return;
        }

        $quizzes = $this->quizzes ?: new QuizRepository();
        $policyService = $this->embedPolicy ?: new EmbedPolicy();
        $quiz = $quizzes->find($id);
        $policy = $quiz ? $policyService->policyForQuiz($quiz) : null;

        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Vary: Referer', false);
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // X-Frame-Options cannot express a Workspace allowlist and can conflict
        // with Content-Security-Policy. The dynamic frame-ancestors header below
        // is the authoritative browser-level whitelist.
        if (function_exists('header_remove')) {
            header_remove('X-Frame-Options');
        }
        if ($quiz) {
            header('Content-Security-Policy: ' . $policyService->frameAncestors($quiz), true);
        }

        if (!$quiz || !$quizzes->isAvailable($quiz)) {
            $this->renderMessage(
                __('Το quiz δεν είναι διαθέσιμο', 'wp-quiz-studio'),
                __('Μάλλον έκανε διάλειμμα ή έχει λήξει. Δοκιμάστε ξανά αργότερα.', 'wp-quiz-studio'),
                'unavailable'
            );
        }

        if (!$policyService->requestAllowed($quiz)) {
            $this->renderMessage((string) $policy['title'], (string) $policy['message'], 'blocked');
        }

        $this->assets();
        wp_enqueue_style('wpqs-player');
        wp_enqueue_script('wpqs-player');

        $guard = wp_json_encode([
            'restricted' => (bool) $policy['restricted'],
            'domains' => (array) $policy['domains'],
            'allowSubdomains' => (bool) $policy['allow_subdomains'],
            'title' => (string) $policy['title'],
            'message' => (string) $policy['message'],
            'home' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
        ]);

        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        wp_print_styles(['wpqs-player']);
        echo '</head><body class="wpqs-embed-body"><div class="wpqs-player" data-quiz="' . esc_attr((string) $id) . '"></div>';
        echo '<script>window.WPQS_EMBED_GUARD=' . $guard . ';</script>';
        wp_print_scripts(['wpqs-player']);
        echo '</body></html>';
        exit;
    }

    private function embedScript(): never
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=300');
        $src = esc_url(home_url('/wpqs-embed/'));
        echo "document.querySelectorAll('[data-quiz],[data-wpqs-quiz]').forEach(function(e){if(e.dataset.wpqsMounted)return;e.dataset.wpqsMounted='1';var id=e.dataset.quiz||e.dataset.wpqsQuiz;if(!id)return;var f=document.createElement('iframe');f.src='" . $src . "'+encodeURIComponent(id)+'/';f.width=e.dataset.width||'100%';f.height=e.dataset.height||'720';f.frameBorder='0';f.loading=e.dataset.loading||'lazy';f.allow='clipboard-write';f.title=e.dataset.title||'Quiz';f.style.maxWidth='100%';f.style.border='0';f.style.borderRadius=(e.dataset.radius||'0')+'px';f.style.background=e.dataset.background||'#08080a';if((e.dataset.width||'100%')==='100%'){f.style.width='100%';}e.appendChild(f);});";
        exit;
    }

    private function renderMessage(string $title, string $message, string $class): never
    {
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;min-height:100%;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#08080a;color:#f6f4ef}.wpqs-embed-message{box-sizing:border-box;min-height:100vh;display:grid;place-items:center;padding:28px;background:radial-gradient(circle at 80% 12%,rgba(185,167,255,.14),transparent 25rem),radial-gradient(circle at 12% 8%,rgba(217,189,133,.11),transparent 24rem),#08080a}.wpqs-embed-message>div{width:min(620px,100%);background:linear-gradient(145deg,rgba(255,255,255,.06),rgba(255,255,255,.018));border:1px solid rgba(217,189,133,.26);border-radius:28px;padding:42px;box-shadow:0 30px 90px rgba(0,0,0,.44);text-align:center}.wpqs-embed-message span{display:inline-grid;place-items:center;width:68px;height:68px;border-radius:50%;border:1px solid rgba(217,189,133,.38);background:rgba(217,189,133,.09);font-size:32px}.wpqs-embed-message h1{font-family:Georgia,"Times New Roman",serif;font-weight:400;font-size:clamp(27px,5vw,39px);margin:20px 0 12px;color:#f2dfb8}.wpqs-embed-message p{font-size:17px;line-height:1.68;color:#aaa7b0;margin:0}</style></head><body><main class="wpqs-embed-message wpqs-' . esc_attr($class) . '"><div><span aria-hidden="true">🛂</span><h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p></div></main></body></html>';
        exit;
    }
}

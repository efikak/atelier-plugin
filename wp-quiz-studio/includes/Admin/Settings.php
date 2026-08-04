<?php

declare(strict_types=1);

namespace WPQuizStudio\Admin;

use WPQuizStudio\Security\AccessManager;
use WPQuizStudio\Security\Capabilities;
use WPQuizStudio\Security\EmbedPolicy;
use WPQuizStudio\Security\LoginSecurity;

/** Registers Studio access settings and an optional front-end Σελίδα Studio creator. */
final class Settings
{
    public function __construct(private AccessManager $access, private EmbedPolicy $embedPolicy)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_wpqs_create_studio_page', [$this, 'createStudioPage']);
    }

    public function assets(string $hook): void
    {
        if (!str_contains($hook, 'wp-quiz-studio-settings')) {
            return;
        }

        wp_enqueue_style('wpqs-studio-settings', WPQS_URL . 'assets/studio.css', [], WPQS_VERSION);
        wp_enqueue_style('wpqs-atelier-theme', WPQS_URL . 'assets/atelier-theme.css', ['wpqs-studio-settings'], WPQS_VERSION);
        wp_enqueue_style('wpqs-atelier-ui-100', WPQS_URL . 'assets/atelier-ui-1.0.0.css', ['wpqs-atelier-theme'], WPQS_VERSION);

        $preferences = UserPreferences::get();
        $variables = [
            '--wpqs-ink' => $preferences['text'],
            '--wpqs-muted' => $preferences['muted'],
            '--wpqs-accent' => $preferences['accent'],
            '--wpqs-accent-light' => $preferences['accent_light'],
            '--wpqs-accent-ink' => $preferences['accent_text'],
            '--wpqs-lilac' => $preferences['lilac'],
            '--wpqs-page' => $preferences['page'],
            '--wpqs-surface' => $preferences['surface'],
            '--wpqs-surface-soft' => $preferences['surface'],
            '--wpqs-surface-raised' => $preferences['surface_raised'],
            '--wpqs-border-solid' => $preferences['border'],
            '--wpqs-ui-radius' => ((int) $preferences['radius']) . 'px',
            '--wpqs-scrollbar' => $preferences['accent'],
        ];
        $css = '.wpqs-settings-page{' . implode('', array_map(
            static fn (string $name, string $value): string => $name . ':' . $value . ';',
            array_keys($variables),
            array_values($variables)
        )) . '}';
        wp_add_inline_style('wpqs-atelier-ui-100', $css);
    }

    public function menu(): void
    {
        add_submenu_page(
            'wp-quiz-studio',
            __('Ρυθμίσεις Quiz Atelier', 'wp-quiz-studio'),
            __('Ρυθμίσεις', 'wp-quiz-studio'),
            Capabilities::SETTINGS,
            'wp-quiz-studio-settings',
            [$this, 'page']
        );
    }

    public function settings(): void
    {
        register_setting('wpqs_access', AccessManager::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => $this->access->settings(),
        ]);
        register_setting('wpqs_access', EmbedPolicy::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeEmbed'],
            'default' => $this->embedPolicy->settings(),
        ]);
        register_setting('wpqs_access', LoginSecurity::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeSecurity'],
            'default' => (new LoginSecurity())->settings(),
        ]);
    }

    /** @param mixed $input */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $validRoles = array_keys(wp_roles()->roles);
        $output = $this->access->settings();

        foreach (['access_roles', 'publish_roles', 'analytics_roles', 'delete_roles'] as $key) {
            $roles = array_map('sanitize_key', (array) ($input[$key] ?? []));
            $output[$key] = array_values(array_intersect($roles, $validRoles));
        }

        $output['page_id'] = absint($input['page_id'] ?? 0);
        $output['show_login_form'] = !empty($input['show_login_form']);
        $output['login_message'] = sanitize_text_field((string) ($input['login_message'] ?? ''));
        $output['denied_message'] = sanitize_text_field((string) ($input['denied_message'] ?? ''));

        return $output;
    }

    /** @param mixed $input */
    public function sanitizeEmbed(mixed $input): array
    {
        return $this->embedPolicy->sanitize(is_array($input) ? $input : []);
    }

    /** @param mixed $input */
    public function sanitizeSecurity(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        return [
            'login_rate_limit' => !empty($input['login_rate_limit']),
            'max_failed_logins' => max(3, min(30, absint($input['max_failed_logins'] ?? 6))),
            'lockout_minutes' => max(5, min(1440, absint($input['lockout_minutes'] ?? 15))),
            'session_hours' => max(1, min(168, absint($input['session_hours'] ?? 12))),
            'new_login_email' => !empty($input['new_login_email']),
            'anonymous_analytics' => !empty($input['anonymous_analytics']),
            'allowed_upload_mimes' => array_values(array_unique(array_filter(array_map(
                'sanitize_key',
                preg_split('/[\s,;]+/', (string) ($input['allowed_upload_mimes'] ?? 'jpg,jpeg,png,webp,gif,pdf,mp3,mp4')) ?: []
            )))),
        ];
    }

    public function page(): void
    {
        if (!current_user_can(Capabilities::SETTINGS)) {
            wp_die(esc_html__('Δεν έχετε δικαίωμα διαχείρισης των ρυθμίσεων Quiz Atelier.', 'wp-quiz-studio'));
        }

        $settings = $this->access->settings();
        $embed = $this->embedPolicy->settings();
        $security = (new LoginSecurity())->settings();
        $roles = wp_roles()->roles;
        $pages = get_pages(['sort_column' => 'post_title', 'post_status' => ['publish', 'draft', 'private']]);
        $created = isset($_GET['wpqs_page_created']);
        $preferences = UserPreferences::get();
        ?>
        <div class="wrap wpqs-admin-wrap wpqs-settings-page" data-wpqs-mode="dark">
            <h1><?php esc_html_e('Ρυθμίσεις Quiz Atelier', 'wp-quiz-studio'); ?></h1>
            <?php if ($created) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Η σελίδα του front-end Quiz Atelier δημιουργήθηκε.', 'wp-quiz-studio'); ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e('Επιλέξτε ποιοι ρόλοι εγγεγραμμένων χρηστών μπορούν να ανοίγουν και να χρησιμοποιούν το front-end Quiz Atelier.', 'wp-quiz-studio'); ?></p>
            <p><strong><?php esc_html_e('Οι Διαχειριστές διατηρούν πάντα πλήρη πρόσβαση.', 'wp-quiz-studio'); ?></strong></p>

            <form method="post" action="options.php">
                <?php settings_fields('wpqs_access'); ?>
                <table class="form-table" role="presentation">
                    <?php $this->roleRow('access_roles', __('Πρόσβαση στο Studio', 'wp-quiz-studio'), __('Ρόλοι που μπορούν να ανοίγουν τη σελίδα shortcode και να δημιουργούν ή να επεξεργάζονται quiz.', 'wp-quiz-studio'), $roles, $settings); ?>
                    <?php $this->roleRow('publish_roles', __('Δημοσίευση quiz', 'wp-quiz-studio'), __('Ρόλοι που μπορούν να δημοσιεύουν, να προγραμματίζουν ή να κάνουν ιδιωτικά quiz.', 'wp-quiz-studio'), $roles, $settings); ?>
                    <?php $this->roleRow('analytics_roles', __('Προβολή στατιστικών', 'wp-quiz-studio'), __('Ρόλοι που μπορούν να βλέπουν τα στατιστικά των quiz.', 'wp-quiz-studio'), $roles, $settings); ?>
                    <?php $this->roleRow('delete_roles', __('Διαγραφή quiz', 'wp-quiz-studio'), __('Ρόλοι που μπορούν να διαγράφουν οριστικά quiz και ερωτήσεις από την τράπεζα.', 'wp-quiz-studio'), $roles, $settings); ?>
                    <tr>
                        <th scope="row"><label for="wpqs-page-id"><?php esc_html_e('Σελίδα Studio', 'wp-quiz-studio'); ?></label></th>
                        <td>
                            <select id="wpqs-page-id" name="<?php echo esc_attr(AccessManager::OPTION); ?>[page_id]">
                                <option value="0"><?php esc_html_e('— Δεν έχει επιλεγεί —', 'wp-quiz-studio'); ?></option>
                                <?php foreach ($pages as $page) : ?>
                                    <option value="<?php echo (int) $page->ID; ?>" <?php selected((int) $settings['page_id'], (int) $page->ID); ?>><?php echo esc_html($page->post_title ?: sprintf(__('Σελίδα #%d', 'wp-quiz-studio'), $page->ID)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Η επιλεγμένη σελίδα πρέπει να περιέχει το shortcode που εμφανίζεται παρακάτω.', 'wp-quiz-studio'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Φόρμα σύνδεσης', 'wp-quiz-studio'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(AccessManager::OPTION); ?>[show_login_form]" value="1" <?php checked($settings['show_login_form']); ?>> <?php esc_html_e('Εμφάνιση της φόρμας σύνδεσης WordPress σε επισκέπτες που δεν έχουν συνδεθεί.', 'wp-quiz-studio'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpqs-login-message"><?php esc_html_e('Μήνυμα σύνδεσης', 'wp-quiz-studio'); ?></label></th>
                        <td><input class="regular-text" id="wpqs-login-message" name="<?php echo esc_attr(AccessManager::OPTION); ?>[login_message]" value="<?php echo esc_attr($settings['login_message']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpqs-denied-message"><?php esc_html_e('Μήνυμα απαγόρευσης πρόσβασης', 'wp-quiz-studio'); ?></label></th>
                        <td><input class="regular-text" id="wpqs-denied-message" name="<?php echo esc_attr(AccessManager::OPTION); ?>[denied_message]" value="<?php echo esc_attr($settings['denied_message']); ?>"></td>
                    </tr>

                    <tr><th colspan="2"><h2><?php esc_html_e('Whitelist domains για embeds', 'wp-quiz-studio'); ?></h2><p><?php esc_html_e('Η whitelist εφαρμόζεται κυρίως από τα εγκεκριμένα domains κάθε Workspace. Η παρακάτω global λίστα χρησιμοποιείται μόνο ως fallback για παλιές εγκαταστάσεις ή quiz χωρίς Workspace. Το κύριο domain του WordPress επιτρέπεται πάντα.', 'wp-quiz-studio'); ?></p></th></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Ενεργοποίηση whitelist', 'wp-quiz-studio'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(EmbedPolicy::OPTION); ?>[enabled]" value="1" <?php checked($embed['enabled']); ?>> <?php esc_html_e('Να επιτρέπονται iframe/JavaScript embeds μόνο στα εγκεκριμένα domains.', 'wp-quiz-studio'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpqs-embed-domains"><?php esc_html_e('Εγκεκριμένα domains', 'wp-quiz-studio'); ?></label></th>
                        <td><textarea class="large-text code" rows="7" id="wpqs-embed-domains" name="<?php echo esc_attr(EmbedPolicy::OPTION); ?>[allowed_domains]" placeholder="example.gr&#10;news.example.gr"><?php echo esc_textarea(implode("\n", (array) $embed['allowed_domains'])); ?></textarea><p class="description"><?php esc_html_e('Ένα domain ανά γραμμή, χωρίς paths. Επιτρέπονται και τιμές όπως *.example.gr.', 'wp-quiz-studio'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Subdomains', 'wp-quiz-studio'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(EmbedPolicy::OPTION); ?>[allow_subdomains]" value="1" <?php checked($embed['allow_subdomains']); ?>> <?php esc_html_e('Να επιτρέπονται αυτόματα και τα subdomains των εγκεκριμένων domains.', 'wp-quiz-studio'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpqs-blocked-title"><?php esc_html_e('Τίτλος απόρριψης', 'wp-quiz-studio'); ?></label></th>
                        <td><input class="regular-text" id="wpqs-blocked-title" name="<?php echo esc_attr(EmbedPolicy::OPTION); ?>[blocked_title]" value="<?php echo esc_attr($embed['blocked_title']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpqs-blocked-message"><?php esc_html_e('Αστείο μήνυμα απόρριψης', 'wp-quiz-studio'); ?></label></th>
                        <td><textarea class="large-text" rows="3" id="wpqs-blocked-message" name="<?php echo esc_attr(EmbedPolicy::OPTION); ?>[blocked_message]"><?php echo esc_textarea($embed['blocked_message']); ?></textarea></td>
                    </tr>

                    <tr><th colspan="2"><h2><?php esc_html_e('Ασφάλεια και ιδιωτικότητα', 'wp-quiz-studio'); ?></h2><p><?php esc_html_e('Οι ρυθμίσεις ισχύουν για τους λογαριασμούς Quiz Atelier και τα public analytics.', 'wp-quiz-studio'); ?></p></th></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Προστασία login', 'wp-quiz-studio'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(LoginSecurity::OPTION); ?>[login_rate_limit]" value="1" <?php checked($security['login_rate_limit']); ?>> <?php esc_html_e('Προσωρινό κλείδωμα μετά από πολλές αποτυχημένες προσπάθειες.', 'wp-quiz-studio'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Όρια login', 'wp-quiz-studio'); ?></th>
                        <td><label><?php esc_html_e('Αποτυχημένες προσπάθειες', 'wp-quiz-studio'); ?> <input type="number" min="3" max="30" name="<?php echo esc_attr(LoginSecurity::OPTION); ?>[max_failed_logins]" value="<?php echo (int) $security['max_failed_logins']; ?>"></label> &nbsp; <label><?php esc_html_e('Κλείδωμα λεπτών', 'wp-quiz-studio'); ?> <input type="number" min="5" max="1440" name="<?php echo esc_attr(LoginSecurity::OPTION); ?>[lockout_minutes]" value="<?php echo (int) $security['lockout_minutes']; ?>"></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Διάρκεια session', 'wp-quiz-studio'); ?></label></th>
                        <td><input type="number" min="1" max="168" name="<?php echo esc_attr(LoginSecurity::OPTION); ?>[session_hours]" value="<?php echo (int) $security['session_hours']; ?>"> <?php esc_html_e('ώρες', 'wp-quiz-studio'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Ειδοποίηση νέου login', 'wp-quiz-studio'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(LoginSecurity::OPTION); ?>[new_login_email]" value="1" <?php checked($security['new_login_email']); ?>> <?php esc_html_e('Αποστολή email στον χρήστη μετά από νέα σύνδεση.', 'wp-quiz-studio'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Anonymous analytics', 'wp-quiz-studio'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(LoginSecurity::OPTION); ?>[anonymous_analytics]" value="1" <?php checked($security['anonymous_analytics']); ?>> <?php esc_html_e('Να μη διατηρούνται πλήρεις IP ή αναγνωριστικά προσωπικών δεδομένων στα analytics.', 'wp-quiz-studio'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpqs-upload-mimes"><?php esc_html_e('Επιτρεπόμενα uploads', 'wp-quiz-studio'); ?></label></th>
                        <td><input class="regular-text code" id="wpqs-upload-mimes" name="<?php echo esc_attr(LoginSecurity::OPTION); ?>[allowed_upload_mimes]" value="<?php echo esc_attr(implode(',', (array) $security['allowed_upload_mimes'])); ?>"><p class="description"><?php esc_html_e('Extensions χωρισμένα με κόμμα, π.χ. jpg,png,webp,pdf,mp3,mp4.', 'wp-quiz-studio'); ?></p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Shortcode για το front-end Studio', 'wp-quiz-studio'); ?></h2>
            <p><code>[wp_quiz_studio_builder]</code></p>
            <p><?php esc_html_e('Μπορείτε επίσης να χρησιμοποιήσετε το εναλλακτικό shortcode:', 'wp-quiz-studio'); ?> <code>[wp_quiz_studio_portal]</code></p>
            <?php if ((int) $settings['page_id'] > 0) : ?>
                <p><a class="button" href="<?php echo esc_url(get_permalink((int) $settings['page_id'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Άνοιγμα επιλεγμένης σελίδας Atelier', 'wp-quiz-studio'); ?></a></p>
            <?php endif; ?>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpqs_create_studio_page'), 'wpqs_create_studio_page')); ?>">
                    <?php esc_html_e('Αυτόματη δημιουργία νέας σελίδας Quiz Atelier', 'wp-quiz-studio'); ?>
                </a>
            </p>

            <hr>
            <h2><?php esc_html_e('Shortcodes δημόσιας προβολής', 'wp-quiz-studio'); ?></h2>
            <p><code>[wp_quiz_studio id="25"]</code> — <?php esc_html_e('Εμφανίζει ένα συγκεκριμένο δημοσιευμένο quiz.', 'wp-quiz-studio'); ?></p>
            <p><code>[wp_quiz_studio_directory]</code> — <?php esc_html_e('Εμφανίζει όλα τα ενεργά quiz με φίλτρα κατηγοριών.', 'wp-quiz-studio'); ?></p>
            <p><code>[wp_quiz_studio_directory category="news" columns="3"]</code> — <?php esc_html_e('Εμφανίζει μόνο μία κατηγορία μέσω του slug της.', 'wp-quiz-studio'); ?></p>
        </div>
        <?php
    }

    public function createStudioPage(): void
    {
        if (!current_user_can(Capabilities::SETTINGS)) {
            wp_die(esc_html__('Δεν έχετε δικαίωμα δημιουργίας της σελίδας Studio.', 'wp-quiz-studio'));
        }
        check_admin_referer('wpqs_create_studio_page');

        $pageId = wp_insert_post([
            'post_title' => __('Quiz Atelier', 'wp-quiz-studio'),
            'post_content' => '[wp_quiz_studio_builder]',
            'post_status' => 'publish',
            'post_type' => 'page',
        ], true);

        if (is_wp_error($pageId)) {
            wp_die(esc_html($pageId->get_error_message()));
        }

        $settings = $this->access->settings();
        $settings['page_id'] = (int) $pageId;
        update_option(AccessManager::OPTION, $settings);

        wp_safe_redirect(add_query_arg('wpqs_page_created', '1', admin_url('admin.php?page=wp-quiz-studio-settings')));
        exit;
    }

    /** @param array<string,array<string,mixed>> $roles */
    private function roleRow(string $key, string $label, string $description, array $roles, array $settings): void
    {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <fieldset>
                    <?php foreach ($roles as $roleKey => $role) : ?>
                        <label style="display:block;margin:0 0 7px;">
                            <input type="checkbox" name="<?php echo esc_attr(AccessManager::OPTION); ?>[<?php echo esc_attr($key); ?>][]" value="<?php echo esc_attr($roleKey); ?>" <?php checked(in_array($roleKey, (array) $settings[$key], true)); ?>>
                            <?php echo esc_html(translate_user_role((string) $role['name'])); ?> <code><?php echo esc_html($roleKey); ?></code>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p class="description"><?php echo esc_html($description); ?></p>
            </td>
        </tr>
        <?php
    }
}

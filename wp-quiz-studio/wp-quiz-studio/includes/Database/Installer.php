<?php

declare(strict_types=1);

namespace WPQuizStudio\Database;

/** Installs and upgrades all Quiz Atelier-owned database tables. */
final class Installer
{
    public function maybeInstall(): void
    {
        if (get_option('wpqs_db_version') !== WPQS_DB_VERSION) {
            $this->install();
        }
    }

    public function install(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'wpqs_';
        $tables = [
            "CREATE TABLE IF NOT EXISTS {$prefix}organizations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                slug varchar(180) NOT NULL,
                seat_limit int unsigned NOT NULL DEFAULT 10,
                creator_admin_limit int unsigned NOT NULL DEFAULT 1,
                expires_at datetime NULL,
                features longtext NULL,
                branding longtext NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug),
                KEY status (status),
                KEY expires_at (expires_at)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}organization_domains (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NOT NULL,
                domain varchar(190) NOT NULL,
                domain_type varchar(20) NOT NULL DEFAULT 'both',
                is_primary tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY organization_domain (organization_id, domain),
                KEY domain (domain)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}organization_members (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                org_role varchar(30) NOT NULL DEFAULT 'creator',
                status varchar(20) NOT NULL DEFAULT 'active',
                joined_at datetime NOT NULL,
                last_seen_at datetime NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY organization_user (organization_id, user_id),
                KEY user_id (user_id),
                KEY organization_role (organization_id, org_role),
                KEY organization_status (organization_id, status)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}invitations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NOT NULL,
                email varchar(190) NOT NULL,
                org_role varchar(30) NOT NULL DEFAULT 'creator',
                token_hash char(64) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                expires_at datetime NOT NULL,
                invited_by bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                accepted_at datetime NULL,
                PRIMARY KEY (id),
                UNIQUE KEY token_hash (token_hash),
                KEY organization_status (organization_id, status),
                KEY email (email)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}categories (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NULL,
                name varchar(160) NOT NULL,
                slug varchar(180) NOT NULL,
                description longtext NULL,
                color varchar(7) NOT NULL DEFAULT '#d9bd85',
                icon varchar(20) NOT NULL DEFAULT 'folder',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY organization_slug (organization_id, slug),
                KEY name (name),
                KEY organization_id (organization_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}quizzes (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NULL,
                title varchar(255) NOT NULL,
                slug varchar(200) NOT NULL,
                description longtext NULL,
                quiz_type varchar(30) NOT NULL DEFAULT 'knowledge',
                status varchar(20) NOT NULL DEFAULT 'draft',
                workflow_status varchar(30) NOT NULL DEFAULT 'draft',
                visibility_scope varchar(20) NOT NULL DEFAULT 'personal',
                scheduled_at datetime NULL,
                expires_at datetime NULL,
                archived_at datetime NULL,
                category_id bigint(20) unsigned NULL,
                template_id bigint(20) unsigned NULL,
                author_id bigint(20) unsigned NOT NULL,
                review_comment longtext NULL,
                settings longtext NULL,
                theme longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug),
                KEY status (status),
                KEY workflow_status (workflow_status),
                KEY visibility_scope (visibility_scope),
                KEY organization_id (organization_id),
                KEY organization_visibility (organization_id, visibility_scope),
                KEY author_id (author_id),
                KEY quiz_type (quiz_type),
                KEY scheduled_at (scheduled_at),
                KEY expires_at (expires_at),
                KEY category_id (category_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}questions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quiz_id bigint(20) unsigned NOT NULL,
                position int unsigned NOT NULL,
                type varchar(40) NOT NULL DEFAULT 'multiple_choice',
                content longtext NOT NULL,
                settings longtext NULL,
                PRIMARY KEY (id),
                KEY quiz_position (quiz_id, position)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}answers (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                question_id bigint(20) unsigned NOT NULL,
                position int unsigned NOT NULL,
                content longtext NOT NULL,
                is_correct tinyint(1) NOT NULL DEFAULT 0,
                score decimal(10,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY question_position (question_id, position)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}results (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quiz_id bigint(20) unsigned NOT NULL,
                session_id char(36) NOT NULL,
                score decimal(10,2) NOT NULL DEFAULT 0,
                payload longtext NULL,
                completed_at datetime NULL,
                PRIMARY KEY (id),
                KEY quiz_id (quiz_id),
                KEY session_id (session_id),
                KEY quiz_completed (quiz_id, completed_at)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}analytics (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quiz_id bigint(20) unsigned NOT NULL,
                event_type varchar(40) NOT NULL,
                question_id bigint(20) unsigned NULL,
                session_id char(36) NULL,
                metadata longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY quiz_event (quiz_id, event_type),
                KEY question_event (question_id, event_type),
                KEY session_event (session_id, event_type),
                KEY quiz_created (quiz_id, created_at),
                KEY created_at (created_at)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}sessions (
                id char(36) NOT NULL,
                quiz_id bigint(20) unsigned NOT NULL,
                started_at datetime NOT NULL,
                completed_at datetime NULL,
                context longtext NULL,
                PRIMARY KEY (id),
                KEY quiz_id (quiz_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}themes (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NULL,
                name varchar(100) NOT NULL,
                config longtext NOT NULL,
                PRIMARY KEY (id),
                KEY organization_id (organization_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}embeds (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quiz_id bigint(20) unsigned NOT NULL,
                token char(36) NOT NULL,
                settings longtext NULL,
                PRIMARY KEY (id),
                UNIQUE KEY token (token),
                KEY quiz_id (quiz_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}question_bank (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NULL,
                visibility_scope varchar(20) NOT NULL DEFAULT 'personal',
                title varchar(255) NOT NULL,
                type varchar(40) NOT NULL DEFAULT 'multiple_choice',
                question longtext NOT NULL,
                author_id bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY type (type),
                KEY author_id (author_id),
                KEY organization_scope (organization_id, visibility_scope)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}revisions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quiz_id bigint(20) unsigned NOT NULL,
                version_number int unsigned NOT NULL,
                snapshot longtext NOT NULL,
                author_id bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY quiz_version (quiz_id, version_number),
                KEY quiz_id (quiz_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}templates (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NULL,
                scope varchar(20) NOT NULL DEFAULT 'organization',
                title varchar(255) NOT NULL,
                slug varchar(180) NOT NULL,
                quiz_type varchar(30) NOT NULL DEFAULT 'knowledge',
                description longtext NULL,
                thumbnail_url text NULL,
                snapshot longtext NOT NULL,
                created_by bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY organization_scope (organization_id, scope),
                KEY quiz_type (quiz_type)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}activity_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                organization_id bigint(20) unsigned NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                action varchar(60) NOT NULL,
                object_type varchar(40) NOT NULL,
                object_id bigint(20) unsigned NULL,
                details longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY organization_created (organization_id, created_at),
                KEY object_lookup (object_type, object_id),
                KEY user_id (user_id)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$prefix}review_comments (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quiz_id bigint(20) unsigned NOT NULL,
                organization_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                action varchar(30) NOT NULL DEFAULT 'comment',
                comment longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY quiz_created (quiz_id, created_at),
                KEY organization_id (organization_id)
            ) ENGINE=InnoDB {$charset}",
        ];

        $successful = true;
        foreach ($tables as $table) {
            if ($wpdb->query($table) === false) {
                $successful = false;
                error_log('Quiz Atelier schema error: ' . $wpdb->last_error);
            }
        }

        // Upgrade earlier installations without removing any existing data.
        $this->ensureColumn($prefix . 'categories', 'organization_id', 'bigint(20) unsigned NULL AFTER id');
        $this->ensureColumn($prefix . 'categories', 'color', "varchar(7) NOT NULL DEFAULT '#d9bd85' AFTER description");
        $this->ensureColumn($prefix . 'categories', 'icon', "varchar(20) NOT NULL DEFAULT 'folder' AFTER color");
        $this->ensureColumn($prefix . 'quizzes', 'organization_id', 'bigint(20) unsigned NULL AFTER id');
        $this->ensureColumn($prefix . 'quizzes', 'quiz_type', "varchar(30) NOT NULL DEFAULT 'knowledge' AFTER description");
        $this->ensureColumn($prefix . 'quizzes', 'workflow_status', "varchar(30) NOT NULL DEFAULT 'draft' AFTER status");
        $this->ensureColumn($prefix . 'quizzes', 'visibility_scope', "varchar(20) NOT NULL DEFAULT 'personal' AFTER workflow_status");
        $this->ensureColumn($prefix . 'quizzes', 'scheduled_at', 'datetime NULL AFTER visibility_scope');
        $this->ensureColumn($prefix . 'quizzes', 'expires_at', 'datetime NULL AFTER scheduled_at');
        $this->ensureColumn($prefix . 'quizzes', 'archived_at', 'datetime NULL AFTER expires_at');
        $this->ensureColumn($prefix . 'quizzes', 'category_id', 'bigint(20) unsigned NULL AFTER archived_at');
        $this->ensureColumn($prefix . 'quizzes', 'template_id', 'bigint(20) unsigned NULL AFTER category_id');
        $this->ensureColumn($prefix . 'quizzes', 'review_comment', 'longtext NULL AFTER author_id');
        $this->ensureColumn($prefix . 'question_bank', 'organization_id', 'bigint(20) unsigned NULL AFTER id');
        $this->ensureColumn($prefix . 'question_bank', 'visibility_scope', "varchar(20) NOT NULL DEFAULT 'personal' AFTER organization_id");
        $this->ensureColumn($prefix . 'themes', 'organization_id', 'bigint(20) unsigned NULL AFTER id');

        $this->ensureIndex($prefix . 'quizzes', 'organization_id', 'KEY organization_id (organization_id)');
        $this->ensureIndex($prefix . 'quizzes', 'organization_visibility', 'KEY organization_visibility (organization_id, visibility_scope)');
        $this->ensureIndex($prefix . 'quizzes', 'workflow_status', 'KEY workflow_status (workflow_status)');
        $this->ensureIndex($prefix . 'quizzes', 'visibility_scope', 'KEY visibility_scope (visibility_scope)');
        $this->ensureIndex($prefix . 'quizzes', 'author_id', 'KEY author_id (author_id)');
        $this->ensureIndex($prefix . 'question_bank', 'organization_scope', 'KEY organization_scope (organization_id, visibility_scope)');
        $this->ensureIndex($prefix . 'categories', 'organization_id', 'KEY organization_id (organization_id)');
        $this->dropLegacyCategorySlugIndex($prefix . 'categories');
        $this->ensureIndex($prefix . 'categories', 'organization_slug', 'UNIQUE KEY organization_slug (organization_id, slug)');

        foreach ([
            'organizations', 'organization_domains', 'organization_members', 'invitations', 'categories', 'quizzes',
            'questions', 'answers', 'results', 'analytics', 'sessions', 'themes', 'embeds', 'question_bank', 'revisions',
            'templates', 'activity_log', 'review_comments',
        ] as $name) {
            $this->ensureInnoDb($prefix . $name);
        }

        $this->migrateDefaultOrganization($prefix);
        $this->seedTemplates($prefix);

        if ($successful) {
            update_option('wpqs_db_version', WPQS_DB_VERSION);
        }
    }

    private function migrateDefaultOrganization(string $prefix): void
    {
        global $wpdb;
        $organizationId = (int) $wpdb->get_var("SELECT id FROM {$prefix}organizations ORDER BY id ASC LIMIT 1");
        if ($organizationId <= 0) {
            $siteName = sanitize_text_field((string) get_bloginfo('name')) ?: 'Main Organization';
            $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            $features = wp_json_encode([
                'analytics' => true, 'templates' => true, 'personality' => true, 'embeds' => true,
                'exports' => true, 'invitations' => true, 'white_label' => true, 'approval_workflow' => true,
            ]);
            $branding = wp_json_encode([
                'logo_id' => 0, 'logo_url' => '', 'favicon_url' => '', 'accent' => '#d9bd85',
                'accent_secondary' => '#b9a7ff', 'footer_text' => '', 'custom_domain' => '',
            ]);
            $wpdb->insert($prefix . 'organizations', [
                'name' => $siteName,
                'slug' => sanitize_title($siteName) ?: 'main-organization',
                'seat_limit' => 100,
                'creator_admin_limit' => 10,
                'expires_at' => null,
                'features' => $features,
                'branding' => $branding,
                'status' => 'active',
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]);
            $organizationId = (int) $wpdb->insert_id;
            if (is_string($host) && $host !== '') {
                $wpdb->insert($prefix . 'organization_domains', [
                    'organization_id' => $organizationId,
                    'domain' => preg_replace('/^www\./', '', strtolower($host)),
                    'domain_type' => 'both',
                    'is_primary' => 1,
                    'created_at' => current_time('mysql', true),
                ]);
            }
        }
        update_option('wpqs_default_organization_id', $organizationId);

        $wpdb->query($wpdb->prepare("UPDATE {$prefix}quizzes SET organization_id=%d WHERE organization_id IS NULL OR organization_id=0", $organizationId));
        $wpdb->query($wpdb->prepare("UPDATE {$prefix}categories SET organization_id=%d WHERE organization_id IS NULL OR organization_id=0", $organizationId));
        $wpdb->query($wpdb->prepare("UPDATE {$prefix}question_bank SET organization_id=%d WHERE organization_id IS NULL OR organization_id=0", $organizationId));
        $wpdb->query("UPDATE {$prefix}quizzes SET workflow_status=CASE WHEN status='published' THEN 'published' ELSE 'draft' END WHERE workflow_status='' OR workflow_status IS NULL");
        $wpdb->query("UPDATE {$prefix}quizzes SET visibility_scope='organization' WHERE visibility_scope='' OR visibility_scope IS NULL");

        $userIds = array_map('intval', $wpdb->get_col("SELECT DISTINCT author_id FROM {$prefix}quizzes WHERE author_id>0") ?: []);
        $eligibleRoleUsers = get_users([
            'role__in' => ['administrator', 'editor', 'quiz_creator', 'quiz_creator_admin', 'quiz_universal_manager'],
            'fields' => 'ID',
        ]);
        $approvedThemeUsers = get_users([
            'meta_key' => 'qa_approval_status',
            'meta_value' => 'approved',
            'fields' => 'ID',
        ]);
        foreach (array_unique(array_merge(
            $userIds,
            array_map('intval', $eligibleRoleUsers),
            array_map('intval', $approvedThemeUsers)
        )) as $userId) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}organization_members WHERE organization_id=%d AND user_id=%d",
                $organizationId,
                $userId
            ));
            if ($exists > 0) {
                continue;
            }
            $user = get_user_by('id', $userId);
            if (!$user || in_array('quiz_member_pending', (array) $user->roles, true) || get_user_meta($userId, 'qa_approval_status', true) === 'rejected') {
                continue;
            }
            $role = (in_array('administrator', (array) $user->roles, true) || in_array('quiz_creator_admin', (array) $user->roles, true))
                ? 'creator_admin'
                : (in_array('quiz_viewer', (array) $user->roles, true) ? 'viewer' : 'creator');
            $wpdb->insert($prefix . 'organization_members', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'org_role' => $role,
                'status' => 'active',
                'joined_at' => current_time('mysql', true),
                'last_seen_at' => null,
                'updated_at' => current_time('mysql', true),
            ]);
        }
    }

    private function seedTemplates(string $prefix): void
    {
        global $wpdb;
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}templates") > 0) {
            return;
        }
        $templates = [
            ['Knowledge Quiz', 'knowledge', 'Quiz γνώσεων με βαθμολογία, σωστές απαντήσεις και explanations.'],
            ['Personality Quiz', 'personality', 'Personality test με προφίλ και weighted answers.'],
            ['Employee Training', 'knowledge', 'Εκπαιδευτική αξιολόγηση με pass score και review answers.'],
            ['Product Recommendation', 'personality', 'Προτείνει προϊόν ή υπηρεσία βάσει των απαντήσεων.'],
            ['Customer Feedback', 'survey', 'Έρευνα ικανοποίησης με rating και open text.'],
            ['Educational Assessment', 'knowledge', 'Αξιολόγηση γνώσεων με timer και random questions.'],
            ['Lead Generation Quiz', 'personality', 'Quiz αποτελέσματος έτοιμο για μελλοντική συλλογή leads.'],
            ['True or False Challenge', 'knowledge', 'Γρήγορο challenge με ερωτήσεις Σωστό / Λάθος.'],
        ];
        foreach ($templates as [$title, $type, $description]) {
            $snapshot = [
                'title' => $title,
                'description' => $description,
                'quiz_type' => $type,
                'status' => 'draft',
                'workflow_status' => 'draft',
                'visibility_scope' => 'personal',
                'settings' => [
                    'intro' => ['title' => $title, 'subtitle' => $description, 'button' => 'Έναρξη quiz', 'image_id' => 0, 'image_url' => ''],
                    'show_progress' => true, 'show_feedback' => true, 'show_correct_answer' => true,
                    'allow_restart' => true, 'review_answers' => true, 'results' => [], 'personality_profiles' => [],
                ],
                'theme' => [
                    'preset' => 'atelier', 'primary' => '#d9bd85', 'secondary' => '#b9a7ff', 'page' => '#08080a',
                    'background' => '#15151b', 'text' => '#f6f4ef', 'muted' => '#b8b5be', 'button' => '#d9bd85',
                    'button_text' => '#111111', 'answer' => '#202027', 'border' => '#4a4852', 'correct' => '#91d7b4',
                    'wrong' => '#ff8b8b', 'radius' => 22, 'font' => 'serif', 'shadow' => 'strong',
                ],
                'questions' => [],
            ];
            $wpdb->insert($prefix . 'templates', [
                'organization_id' => null,
                'scope' => 'universal',
                'title' => $title,
                'slug' => sanitize_title($title),
                'quiz_type' => $type,
                'description' => $description,
                'thumbnail_url' => '',
                'snapshot' => wp_json_encode($snapshot),
                'created_by' => 0,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]);
        }
    }

    /** Removes the pre-Organizations global unique slug index when upgrading. */
    private function dropLegacyCategorySlugIndex(string $table): void
    {
        global $wpdb;
        $index = $wpdb->get_row("SHOW INDEX FROM `{$table}` WHERE Key_name='slug'", ARRAY_A);
        if ($index) {
            $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `slug`");
        }
    }

    private function ensureInnoDb(string $table): void
    {
        global $wpdb;
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
        if ($status && strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
            $wpdb->query("ALTER TABLE `{$table}` ENGINE=InnoDB");
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $wpdb->esc_like($column)));
        if (!$exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function ensureIndex(string $table, string $index, string $definition): void
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name=%s", $index));
        if (!$exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD {$definition}");
        }
    }
}

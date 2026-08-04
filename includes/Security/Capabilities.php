<?php

declare(strict_types=1);

namespace WPQuizStudio\Security;

/** Installs granular capabilities and the Quiz Atelier editorial roles. */
final class Capabilities
{
    public const EDIT = 'wpqs_edit_quizzes';
    public const PUBLISH = 'wpqs_publish_quizzes';
    public const DELETE = 'wpqs_delete_quizzes';
    public const ANALYTICS = 'wpqs_view_analytics';
    public const SETTINGS = 'wpqs_manage_settings';
    public const MANAGE_ORGANIZATIONS = 'wpqs_manage_organizations';
    public const MANAGE_TEAM = 'wpqs_manage_team';
    public const REVIEW = 'wpqs_review_quizzes';
    public const UNIVERSAL = 'wpqs_manage_universal';

    /** @var list<string> */
    private array $allCapabilities = [
        self::EDIT, self::PUBLISH, self::DELETE, self::ANALYTICS, self::SETTINGS,
        self::MANAGE_ORGANIZATIONS, self::MANAGE_TEAM, self::REVIEW, self::UNIVERSAL,
    ];

    public function maybeInstall(): void
    {
        if (get_option('wpqs_capabilities_version') !== WPQS_VERSION) {
            $this->install();
        }
    }

    public function install(): void
    {
        $administrator = get_role('administrator');
        if ($administrator) {
            foreach ($this->allCapabilities as $capability) {
                $administrator->add_cap($capability);
            }
        }

        $editor = get_role('editor');
        if ($editor) {
            foreach ([self::EDIT, self::PUBLISH, self::DELETE, self::ANALYTICS, self::MANAGE_TEAM, self::REVIEW] as $capability) {
                $editor->add_cap($capability);
            }
        }

        add_role('quiz_creator', __('Quiz Creator', 'wp-quiz-studio'), [
            'read' => true,
            'upload_files' => true,
            self::EDIT => true,
        ]);
        add_role('quiz_creator_admin', __('Creator Admin', 'wp-quiz-studio'), [
            'read' => true,
            'upload_files' => true,
            self::EDIT => true,
            self::PUBLISH => true,
            self::DELETE => true,
            self::ANALYTICS => true,
            self::MANAGE_TEAM => true,
            self::REVIEW => true,
        ]);
        add_role('quiz_viewer', __('Quiz Viewer', 'wp-quiz-studio'), [
            'read' => true,
        ]);
        add_role('quiz_universal_manager', __('Universal Quiz Manager', 'wp-quiz-studio'), [
            'read' => true,
            'upload_files' => true,
            self::EDIT => true,
            self::PUBLISH => true,
            self::DELETE => true,
            self::ANALYTICS => true,
            self::UNIVERSAL => true,
        ]);

        // Update existing custom roles when upgrading.
        $roleCaps = [
            'quiz_creator' => [self::EDIT],
            'quiz_creator_admin' => [self::EDIT, self::PUBLISH, self::DELETE, self::ANALYTICS, self::MANAGE_TEAM, self::REVIEW],
            'quiz_universal_manager' => [self::EDIT, self::PUBLISH, self::DELETE, self::ANALYTICS, self::UNIVERSAL],
        ];
        foreach ($roleCaps as $roleName => $caps) {
            $role = get_role($roleName);
            if ($role) {
                $role->add_cap('read');
                foreach ($caps as $cap) {
                    $role->add_cap($cap);
                }
            }
        }

        update_option('wpqs_capabilities_version', WPQS_VERSION);
    }
}

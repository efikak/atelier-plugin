<?php

declare(strict_types=1);

namespace WPQuizStudio\Api;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPQuizStudio\Admin\UserPreferences;
use WPQuizStudio\Database\Installer;
use WPQuizStudio\Embed\Player;
use WPQuizStudio\Repository\ActivityLogRepository;
use WPQuizStudio\Repository\AnalyticsRepository;
use WPQuizStudio\Repository\CategoryRepository;
use WPQuizStudio\Repository\InvitationRepository;
use WPQuizStudio\Repository\OrganizationRepository;
use WPQuizStudio\Repository\QuestionBankRepository;
use WPQuizStudio\Repository\QuizRepository;
use WPQuizStudio\Repository\ReviewRepository;
use WPQuizStudio\Repository\RevisionRepository;
use WPQuizStudio\Repository\TemplateRepository;
use WPQuizStudio\Security\AccessManager;
use WPQuizStudio\Security\Capabilities;
use WPQuizStudio\Security\RateLimiter;
use WPQuizStudio\Service\QuizScorer;
use WPQuizStudio\Service\NotificationService;
use WPQuizStudio\Service\QuestionFeedback;
use WPQuizStudio\Service\Scheduler;
use WPQuizStudio\Service\SystemHealth;

/** REST controller for the studio, analytics, revisions and public player. */
final class Routes
{
    public function __construct(
        private QuizRepository $quizzes,
        private CategoryRepository $categories,
        private AnalyticsRepository $analytics,
        private RevisionRepository $revisions,
        private QuestionBankRepository $questionBank,
        private QuizScorer $scorer,
        private QuestionFeedback $feedback,
        private RateLimiter $rateLimiter,
        private AccessManager $access,
        private OrganizationRepository $organizations,
        private InvitationRepository $invitations,
        private TemplateRepository $templates,
        private ActivityLogRepository $activity,
        private ReviewRepository $reviews,
        private NotificationService $notifications
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('wp-quiz-studio/v1', '/quizzes', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'index'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'save'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/(?P<id>\d+)', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'save'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete'],
                    'permission_callback' => [$this, 'canDelete'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/(?P<id>\d+)/duplicate', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'duplicate'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/(?P<id>\d+)/quick-update', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'quickUpdate'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/analytics', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'analyticsDashboard'],
                'permission_callback' => [$this, 'canViewAnalytics'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/(?P<id>\d+)/analytics', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'quizAnalytics'],
                'permission_callback' => [$this, 'canViewAnalytics'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/(?P<id>\d+)/revisions', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'revisionIndex'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/(?P<id>\d+)/revisions/(?P<revision_id>\d+)/restore', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'restoreRevision'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/question-bank', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'questionBankIndex'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'questionBankCreate'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/question-bank/(?P<id>\d+)', [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'questionBankDelete'],
                'permission_callback' => [$this, 'canDelete'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/categories', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'categoryIndex'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'categorySave'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/me/preferences', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'userPreferences'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'saveUserPreferences'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/categories/(?P<id>\d+)', [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'categorySave'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'categoryDelete'],
                    'permission_callback' => [$this, 'canDelete'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/me', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'me'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/dashboard', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'creatorDashboard'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/organizations', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'organizationIndex'],
                    'permission_callback' => [$this, 'canManageOrganizations'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'organizationSave'],
                    'permission_callback' => [$this, 'canManageOrganizations'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/organizations/(?P<id>\d+)', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'organizationGet'],
                    'permission_callback' => [$this, 'canManageOrganizationRequest'],
                ],
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'organizationSave'],
                    'permission_callback' => [$this, 'canManageOrganizations'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/workspace', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'workspaceGet'],
                    'permission_callback' => [$this, 'canManageCurrentWorkspace'],
                ],
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'workspaceSave'],
                    'permission_callback' => [$this, 'canManageCurrentWorkspace'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/admin/user-workspaces', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'userWorkspaceIndex'],
                'permission_callback' => [$this, 'canSuperAdmin'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/admin/users/(?P<user_id>\d+)/workspace', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'userWorkspaceUpdate'],
                'permission_callback' => [$this, 'canSuperAdmin'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/organizations/(?P<id>\d+)/members', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'memberIndex'],
                    'permission_callback' => [$this, 'canManageOrganizationRequest'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'memberCreate'],
                    'permission_callback' => [$this, 'canManageOrganizationRequest'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/organizations/(?P<id>\d+)/members/(?P<member_id>\d+)', [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'memberUpdate'],
                    'permission_callback' => [$this, 'canManageOrganizationRequest'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'memberDelete'],
                    'permission_callback' => [$this, 'canManageOrganizationRequest'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/organizations/(?P<id>\d+)/invitations', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'invitationIndex'],
                    'permission_callback' => [$this, 'canManageOrganizationRequest'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'invitationCreate'],
                    'permission_callback' => [$this, 'canManageOrganizationRequest'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/organizations/(?P<id>\d+)/invitations/(?P<invitation_id>\d+)/resend', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'invitationResend'],
                'permission_callback' => [$this, 'canManageOrganizationRequest'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/organizations/(?P<id>\d+)/invitations/(?P<invitation_id>\d+)', [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'invitationRevoke'],
                'permission_callback' => [$this, 'canManageOrganizationRequest'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/templates', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'templateIndex'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'templateSave'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/templates/(?P<id>\d+)', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'templateGet'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'templateDelete'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/activity', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'activityIndex'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/(?P<id>\d+)/workflow', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'workflowIndex'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'workflowAction'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/system/health', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'systemHealth'],
                'permission_callback' => [$this, 'canSuperAdmin'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/system/repair', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'systemRepair'],
                'permission_callback' => [$this, 'canSuperAdmin'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/profile', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'profileGet'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'profileSave'],
                    'permission_callback' => [$this, 'canEdit'],
                ],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/(?P<id>\d+)/export', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'exportQuiz'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/quizzes/import', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'importQuiz'],
                'permission_callback' => [$this, 'canEdit'],
            ]);

            register_rest_route('wp-quiz-studio/v1', '/public/quizzes/(?P<id>\d+)', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'publicQuiz'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('wp-quiz-studio/v1', '/public/quizzes/(?P<id>\d+)/events', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'event'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('wp-quiz-studio/v1', '/public/quizzes/(?P<id>\d+)/check', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'checkAnswer'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('wp-quiz-studio/v1', '/public/categories', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'publicCategories'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('wp-quiz-studio/v1', '/public/directory', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'publicDirectory'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('wp-quiz-studio/v1', '/public/quizzes/(?P<id>\d+)/submit', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'submit'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function canEdit(): bool
    {
        return $this->access->canAccess() && current_user_can(Capabilities::EDIT);
    }

    public function canDelete(): bool
    {
        return $this->access->canDelete();
    }

    public function canViewAnalytics(): bool
    {
        return $this->access->canViewAnalytics();
    }

    public function canManageOrganizations(): bool
    {
        return $this->access->canManageOrganizations();
    }

    public function canSuperAdmin(): bool
    {
        return current_user_can('manage_options');
    }

    public function canManageCurrentWorkspace(): bool
    {
        return $this->access->canManageTeam() || current_user_can('manage_options');
    }

    public function canManageOrganizationRequest(WP_REST_Request $request): bool
    {
        return $this->access->canManageTeam(null, (int) $request['id']);
    }

    public function index(): WP_REST_Response
    {
        return new WP_REST_Response($this->quizzes->all());
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->access->canViewQuiz($quiz)) {
            return new WP_REST_Response(['message' => __('Δεν βρέθηκε ή δεν έχετε πρόσβαση.', 'wp-quiz-studio')], 404);
        }
        $quiz['review_history'] = $this->reviews->all((int) $quiz['id']);
        return new WP_REST_Response($quiz);
    }

    public function save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $data = $this->normaliseSingleCorrectAnswers((array) $request->get_json_params());
            $existing = null;
            if ($request['id']) {
                $data['id'] = (int) $request['id'];
                $existing = $this->quizzes->find((int) $request['id']);
                if (!$existing || !$this->access->canEditQuiz($existing)) {
                    return new WP_Error('wpqs_forbidden_quiz', __('Δεν έχετε δικαίωμα επεξεργασίας αυτού του quiz.', 'wp-quiz-studio'), ['status' => 403]);
                }
                $expectedUpdatedAt = sanitize_text_field((string) ($data['_expected_updated_at'] ?? ''));
                $forceOverwrite = !empty($data['_force_overwrite']);
                if (!$forceOverwrite && $expectedUpdatedAt !== '' && $expectedUpdatedAt !== (string) ($existing['updated_at'] ?? '')) {
                    return new WP_Error(
                        'wpqs_edit_conflict',
                        __('Υπάρχει νεότερη έκδοση αυτού του quiz στον server. Φορτώστε τη νεότερη έκδοση ή αποθηκεύστε τις αλλαγές σας ως αντίγραφο.', 'wp-quiz-studio'),
                        ['status' => 409, 'latest' => $existing]
                    );
                }
                unset($data['_expected_updated_at'], $data['_force_overwrite']);
                $data['organization_id'] = (int) $existing['organization_id'];
                $data['author_id'] = (int) $existing['author_id'];
            } else {
                unset($data['_expected_updated_at'], $data['_force_overwrite']);
                $data['organization_id'] = $this->access->currentOrganizationId();
                $data['visibility_scope'] = (string) ($data['visibility_scope'] ?? 'personal');
                $data['workflow_status'] = 'draft';
            }

            $requestedWorkflow = sanitize_key((string) ($data['workflow_status'] ?? 'draft'));
            $allowedWorkflow = ['draft', 'submitted', 'changes_requested', 'approved', 'published', 'archived'];
            if (!in_array($requestedWorkflow, $allowedWorkflow, true)) {
                $requestedWorkflow = 'draft';
            }
            if (!$this->access->canReview(null, (int) ($data['organization_id'] ?? 0))) {
                if (!empty($existing)) {
                    $isOwner = (int) ($existing['author_id'] ?? 0) === get_current_user_id();
                    $currentWorkflow = (string) ($existing['workflow_status'] ?? 'draft');
                    $data['workflow_status'] = ($isOwner && $requestedWorkflow === 'submitted') ? 'submitted' : $currentWorkflow;
                } else {
                    $data['workflow_status'] = 'draft';
                }
            } else {
                $data['workflow_status'] = $requestedWorkflow;
            }

            if (($data['visibility_scope'] ?? '') === 'universal' && !$this->access->canManageUniversal()) {
                return new WP_Error('wpqs_forbidden_universal', __('Μόνο ο Super Admin ή Universal Manager μπορεί να δημιουργήσει Universal quiz.', 'wp-quiz-studio'), ['status' => 403]);
            }

            if (($data['quiz_type'] ?? '') === 'personality' && !$this->access->featureEnabled('personality', null, (int) ($data['organization_id'] ?? 0))) {
                return new WP_Error('wpqs_feature_disabled', __('Τα Personality Tests δεν είναι ενεργοποιημένα για αυτόν τον οργανισμό.', 'wp-quiz-studio'), ['status' => 403]);
            }

            $status = (string) ($data['status'] ?? 'draft');
            if (in_array($status, ['published', 'scheduled', 'private'], true) && !$this->access->canPublish()) {
                return new WP_Error('wpqs_forbidden_status', __('Δεν έχετε δικαίωμα δημοσίευσης ή προγραμματισμού quiz.', 'wp-quiz-studio'), ['status' => 403]);
            }

            $createRevision = empty($data['_autosave']);
            if ($createRevision && in_array($status, ['published', 'scheduled'], true)) {
                $validationError = $this->validateForPublishing($data);
                if ($validationError) {
                    return $validationError;
                }
            }
            unset($data['_autosave']);
            $id = $this->quizzes->save($data, get_current_user_id(), $createRevision);
            $quiz = $this->quizzes->find($id);

            if (!$quiz) {
                return new WP_Error('wpqs_save_failed', __('Το quiz δεν βρέθηκε μετά την αποθήκευση.', 'wp-quiz-studio'), ['status' => 500]);
            }

            $this->activity->log($request['id'] ? 'quiz_updated' : 'quiz_created', 'quiz', $id, (int) ($quiz['organization_id'] ?? 0), get_current_user_id(), ['title' => $quiz['title']]);
            return new WP_REST_Response($quiz, $request['id'] ? 200 : 201);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_save_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->access->canDeleteQuiz($quiz)) {
            return new WP_Error('wpqs_forbidden_delete', __('Δεν έχετε δικαίωμα διαγραφής αυτού του quiz.', 'wp-quiz-studio'), ['status' => 403]);
        }
        try {
            $this->quizzes->delete((int) $request['id']);
            $this->activity->log('quiz_deleted', 'quiz', (int) $request['id'], (int) ($quiz['organization_id'] ?? 0), get_current_user_id(), ['title' => $quiz['title']]);
            return new WP_REST_Response(null, 204);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_delete_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function quickUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $data = (array) $request->get_json_params();
        $status = isset($data['status']) ? (string) $data['status'] : null;
        if ($status !== null && in_array($status, ['published', 'scheduled', 'private', 'expired'], true) && !$this->access->canPublish()) {
            return new WP_Error('wpqs_forbidden_status', __('Δεν έχετε δικαίωμα αλλαγής αυτής της κατάστασης.', 'wp-quiz-studio'), ['status' => 403]);
        }

        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz) {
            return new WP_Error('wpqs_not_found', __('Το quiz δεν βρέθηκε.', 'wp-quiz-studio'), ['status' => 404]);
        }
        if (!$this->access->canEditQuiz($quiz)) {
            return new WP_Error('wpqs_forbidden_quiz', __('Δεν έχετε δικαίωμα επεξεργασίας αυτού του quiz.', 'wp-quiz-studio'), ['status' => 403]);
        }
        if (($data['visibility_scope'] ?? '') === 'universal' && !$this->access->canManageUniversal()) {
            return new WP_Error('wpqs_forbidden_universal', __('Δεν έχετε δικαίωμα Universal ορατότητας.', 'wp-quiz-studio'), ['status' => 403]);
        }

        if (isset($data['workflow_status'])) {
            $workflow = sanitize_key((string) $data['workflow_status']);
            $canReview = $this->access->canReview(null, (int) ($quiz['organization_id'] ?? 0));
            $isOwner = (int) ($quiz['author_id'] ?? 0) === get_current_user_id();
            if (!$canReview && !($isOwner && in_array($workflow, ['draft', 'submitted'], true))) {
                return new WP_Error('wpqs_forbidden_workflow', __('Η αλλαγή αυτής της κατάστασης workflow απαιτεί Creator Admin.', 'wp-quiz-studio'), ['status' => 403]);
            }
            if (!in_array($workflow, ['draft', 'submitted', 'changes_requested', 'approved', 'published', 'archived'], true)) {
                return new WP_Error('wpqs_invalid_workflow', __('Μη έγκυρη κατάσταση workflow.', 'wp-quiz-studio'), ['status' => 422]);
            }
        }

        if ($status !== null && in_array($status, ['published', 'scheduled'], true)) {
            $candidate = $quiz;
            $candidate['status'] = $status;
            $validationError = $this->validateForPublishing($candidate);
            if ($validationError) {
                return $validationError;
            }
        }

        try {
            $updated = $this->quizzes->quickUpdate((int) $request['id'], $data);
            $this->activity->log('quiz_quick_updated', 'quiz', (int) $request['id'], (int) ($quiz['organization_id'] ?? 0), get_current_user_id(), $data);
            return new WP_REST_Response($updated);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_quick_update_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $source = $this->quizzes->find((int) $request['id']);
        if (!$source || !$this->access->canViewQuiz($source)) {
            return new WP_Error('wpqs_forbidden_duplicate', __('Δεν έχετε πρόσβαση σε αυτό το quiz.', 'wp-quiz-studio'), ['status' => 403]);
        }
        try {
            $id = $this->quizzes->duplicate((int) $request['id'], get_current_user_id());
            $quiz = $this->quizzes->find($id);
            $this->activity->log('quiz_duplicated', 'quiz', $id, (int) ($quiz['organization_id'] ?? 0), get_current_user_id(), ['source_id' => (int) $request['id']]);
            return new WP_REST_Response($quiz, 201);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_duplicate_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function analyticsDashboard(WP_REST_Request $request): WP_REST_Response
    {
        $filters = $this->analyticsFilters($request);
        if (!$this->access->context()['is_super_admin']) {
            $ids = array_values(array_map(static fn (array $quiz): int => (int) $quiz['id'], $this->quizzes->all()));
            if ($ids === []) {
                return new WP_REST_Response([
                    'overview' => ['views' => 0, 'starts' => 0, 'completions' => 0, 'completion_rate' => 0, 'start_rate' => 0, 'share_rate' => 0, 'average_score' => 0, 'average_time' => 0, 'abandoned' => 0],
                    'comparison' => [], 'timeseries' => [], 'daily' => [], 'funnel' => [], 'questions' => [], 'audience' => [],
                    'result_distribution' => [], 'score_distribution' => [], 'pass_distribution' => [], 'latest_completions' => [], 'quiz_breakdown' => [],
                ]);
            }
            $filters['quiz_ids'] = $ids;
        }
        return new WP_REST_Response($this->analytics->dashboard(null, $filters));
    }

    public function quizAnalytics(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->access->canViewQuiz($quiz)) {
            return new WP_Error('wpqs_forbidden_analytics', __('Δεν έχετε πρόσβαση στα analytics αυτού του quiz.', 'wp-quiz-studio'), ['status' => 403]);
        }
        return new WP_REST_Response($this->analytics->dashboard((int) $request['id'], $this->analyticsFilters($request)));
    }

    public function revisionIndex(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->access->canEditQuiz($quiz)) {
            return new WP_Error('wpqs_forbidden_revisions', __('Δεν έχετε πρόσβαση στις εκδόσεις αυτού του quiz.', 'wp-quiz-studio'), ['status' => 403]);
        }
        return new WP_REST_Response($this->revisions->all((int) $request['id']));
    }

    public function restoreRevision(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quizId = (int) $request['id'];
        $quiz = $this->quizzes->find($quizId);
        if (!$quiz || !$this->access->canEditQuiz($quiz)) {
            return new WP_Error('wpqs_forbidden_restore', __('Δεν έχετε δικαίωμα επαναφοράς αυτού του quiz.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $snapshot = $this->revisions->find($quizId, (int) $request['revision_id']);
        if (!$snapshot) {
            return new WP_Error('wpqs_revision_not_found', __('Η έκδοση δεν βρέθηκε.', 'wp-quiz-studio'), ['status' => 404]);
        }

        try {
            $snapshot['id'] = $quizId;
            $id = $this->quizzes->save($snapshot, get_current_user_id(), true);
            return new WP_REST_Response($this->quizzes->find($id));
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_restore_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function questionBankIndex(): WP_REST_Response
    {
        return new WP_REST_Response($this->questionBank->all());
    }

    public function questionBankCreate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $data = (array) $request->get_json_params();
        $question = (array) ($data['question'] ?? []);
        if ($question === []) {
            return new WP_Error('wpqs_invalid_question', __('Απαιτείται μία ερώτηση.', 'wp-quiz-studio'), ['status' => 422]);
        }

        try {
            $this->questionBank->create((string) ($data['title'] ?? ''), $question, get_current_user_id(), (string) ($data['visibility_scope'] ?? 'personal'));
            return new WP_REST_Response($this->questionBank->all(), 201);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_question_bank_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function questionBankDelete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $this->questionBank->delete((int) $request['id']);
            return new WP_REST_Response(null, 204);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_question_bank_delete_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function userPreferences(): WP_REST_Response
    {
        return new WP_REST_Response(UserPreferences::get());
    }

    public function saveUserPreferences(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(UserPreferences::save($request->get_json_params()));
    }

    public function categoryIndex(): WP_REST_Response
    {
        return new WP_REST_Response($this->categories->all());
    }

    public function categorySave(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $data = (array) $request->get_json_params();
        if ($request['id']) {
            $data['id'] = (int) $request['id'];
        }

        try {
            $id = $this->categories->save($data);
            return new WP_REST_Response($this->categories->find($id), $request['id'] ? 200 : 201);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_category_save_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function categoryDelete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $this->categories->delete((int) $request['id']);
            return new WP_REST_Response(null, 204);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_category_delete_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function me(): WP_REST_Response
    {
        $user = wp_get_current_user();
        $context = $this->access->context($user);
        $organization = $context['organization_id'] > 0 ? $this->organizations->find((int) $context['organization_id']) : null;
        return new WP_REST_Response([
            'id' => (int) $user->ID,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'website' => $user->user_url,
            'avatar_url' => get_avatar_url((int) $user->ID, ['size' => 160]),
            'roles' => array_values((array) $user->roles),
            'registered_at' => $user->user_registered,
            'last_login' => (string) get_user_meta((int) $user->ID, 'wpqs_last_login', true),
            'context' => $context,
            'organization' => $organization,
            'preferences' => UserPreferences::get((int) $user->ID),
            'language' => (string) (get_user_meta((int) $user->ID, 'wpqs_language', true) ?: 'el'),
            'email_preferences' => array_merge(
                ['workflow' => true, 'analytics' => true, 'security' => true],
                (array) get_user_meta((int) $user->ID, 'wpqs_email_preferences', true)
            ),
        ]);
    }

    public function creatorDashboard(): WP_REST_Response
    {
        $context = $this->access->context();
        $organizationId = (int) $context['organization_id'];
        if ($organizationId <= 0 && $context['is_super_admin']) {
            $organizationId = $this->organizations->defaultOrganizationId();
        }
        $adminView = !empty($context['is_super_admin']) || $context['organization_role'] === 'creator_admin';
        $dashboard = $organizationId > 0
            ? $this->organizations->dashboard($organizationId, get_current_user_id(), $adminView)
            : [];
        $dashboard['recent_activity'] = $adminView ? $this->activity->all($organizationId, 12) : [];
        $dashboard['pending_invitations'] = $adminView && $organizationId > 0
            ? count(array_filter($this->invitations->all($organizationId), static fn (array $item): bool => $item['status'] === 'pending'))
            : 0;
        return new WP_REST_Response($dashboard);
    }

    public function workspaceGet(): WP_REST_Response|WP_Error
    {
        $organizationId = $this->access->currentOrganizationId();
        if ($organizationId <= 0) {
            return new WP_Error('wpqs_workspace_missing', __('Δεν έχει οριστεί Workspace για τον λογαριασμό.', 'wp-quiz-studio'), ['status' => 404]);
        }
        $organization = $this->organizations->find($organizationId);
        if (!$organization) {
            return new WP_Error('wpqs_workspace_missing', __('Το Workspace δεν βρέθηκε.', 'wp-quiz-studio'), ['status' => 404]);
        }
        $organization['members'] = $this->organizations->members($organizationId);
        $organization['invitations'] = $this->invitations->all($organizationId);
        $organization['dashboard'] = $this->organizations->dashboard($organizationId, get_current_user_id(), true);
        return new WP_REST_Response($organization);
    }

    public function workspaceSave(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $organizationId = $this->access->currentOrganizationId();
        if ($organizationId <= 0) {
            return new WP_Error('wpqs_workspace_missing', __('Δεν έχει οριστεί Workspace για τον λογαριασμό.', 'wp-quiz-studio'), ['status' => 404]);
        }
        $data = (array) $request->get_json_params();
        try {
            if (current_user_can('manage_options')) {
                $data['id'] = $organizationId;
                $this->organizations->save($data, get_current_user_id());
            } else {
                // Creator Admins may only manage the Workspace embed whitelist and
                // approved visual branding. Limits and lifecycle fields are strictly
                // controlled by a WordPress Administrator at both UI and API level.
                $protectedFields = [
                    'id', 'name', 'slug', 'seat_limit', 'creator_admin_limit',
                    'expires_at', 'status', 'features', 'domains', 'email_domains',
                ];
                foreach ($protectedFields as $protectedField) {
                    if (array_key_exists($protectedField, $data)) {
                        return new WP_Error(
                            'wpqs_workspace_field_protected',
                            __('Οι θέσεις, τα όρια διαχειριστών, η λήξη, η κατάσταση και τα βασικά στοιχεία του Workspace αλλάζουν μόνο από WordPress Administrator.', 'wp-quiz-studio'),
                            ['status' => 403]
                        );
                    }
                }

                // Creator Admins may manage the embed whitelist and visual branding,
                // but never seats, expiry, account status, email domains or feature flags.
                $domains = $data['embed_domains'] ?? [];
                if (is_string($domains)) {
                    $domains = preg_split('/[\r\n,;]+/', $domains) ?: [];
                }
                $this->organizations->updateEmbedDomains($organizationId, (array) $domains);

                $existing = $this->organizations->find($organizationId) ?: [];
                $safe = [
                    'id' => $organizationId,
                    'name' => (string) ($existing['name'] ?? ''),
                    'slug' => (string) ($existing['slug'] ?? ''),
                    'seat_limit' => (int) ($existing['seat_limit'] ?? 1),
                    'creator_admin_limit' => (int) ($existing['creator_admin_limit'] ?? 1),
                    'expires_at' => (string) ($existing['expires_at'] ?? ''),
                    'features' => (array) ($existing['features'] ?? []),
                    'status' => (string) ($existing['status'] ?? 'active'),
                    'domains' => (array) ($existing['domains'] ?? []),
                    'branding' => array_merge((array) ($existing['branding'] ?? []), array_intersect_key(
                        (array) ($data['branding'] ?? []),
                        array_flip(['logo_url', 'accent', 'accent_secondary', 'footer_text', 'favicon_url'])
                    )),
                ];
                $this->organizations->save($safe, get_current_user_id());
            }
            $this->activity->log('workspace_updated', 'organization', $organizationId, $organizationId, get_current_user_id());
            return $this->workspaceGet();
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_workspace_save_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function userWorkspaceIndex(): WP_REST_Response
    {
        return new WP_REST_Response([
            'users' => $this->organizations->userWorkspaceAssignments(),
            'organizations' => $this->organizations->all(),
        ]);
    }

    public function userWorkspaceUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $userId = (int) $request['user_id'];
        $data = (array) $request->get_json_params();
        $organizationId = absint($data['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            return new WP_Error('wpqs_workspace_required', __('Επιλέξτε Workspace.', 'wp-quiz-studio'), ['status' => 422]);
        }
        try {
            $organizationRole = sanitize_key((string) ($data['org_role'] ?? 'creator'));
            $membership = $this->organizations->moveUserToOrganization(
                $userId,
                $organizationId,
                $organizationRole,
                sanitize_key((string) ($data['status'] ?? 'active')),
                !empty($data['move_quizzes'])
            );

            $user = get_user_by('id', $userId);
            if ($user && !user_can($user, 'manage_options')) {
                foreach (['quiz_creator', 'quiz_creator_admin', 'quiz_viewer', 'quiz_member_pending'] as $quizRole) {
                    $user->remove_role($quizRole);
                }
                $mappedRole = match ($organizationRole) {
                    'creator_admin' => 'quiz_creator_admin',
                    'viewer' => 'quiz_viewer',
                    default => 'quiz_creator',
                };
                $user->add_role($mappedRole);
                update_user_meta($userId, 'qa_approval_status', 'approved');
            }

            $this->activity->log('user_workspace_changed', 'user', $userId, $organizationId, get_current_user_id(), [
                'role' => $membership['org_role'] ?? 'creator',
                'move_quizzes' => !empty($data['move_quizzes']),
            ]);
            return new WP_REST_Response($membership);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_workspace_move_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function systemHealth(): WP_REST_Response
    {
        return new WP_REST_Response((new SystemHealth())->report());
    }

    public function systemRepair(): WP_REST_Response|WP_Error
    {
        try {
            (new Installer())->install();
            (new Scheduler())->schedule();
            (new Player())->rewrite();
            flush_rewrite_rules(false);
            return new WP_REST_Response((new SystemHealth())->report());
        } catch (\Throwable $exception) {
            return new WP_Error('wpqs_system_repair_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function organizationIndex(): WP_REST_Response
    {
        return new WP_REST_Response($this->organizations->all());
    }

    public function organizationGet(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request['id'];
        $organization = $this->organizations->find($id);
        if (!$organization) {
            return new WP_Error('wpqs_org_not_found', __('Ο οργανισμός δεν βρέθηκε.', 'wp-quiz-studio'), ['status' => 404]);
        }
        $organization['members'] = $this->organizations->members($id);
        $organization['invitations'] = $this->invitations->all($id);
        $organization['dashboard'] = $this->organizations->dashboard($id, get_current_user_id(), true);
        return new WP_REST_Response($organization);
    }

    public function organizationSave(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $data = (array) $request->get_json_params();
        if ($request['id']) {
            $data['id'] = (int) $request['id'];
        }
        try {
            $id = $this->organizations->save($data, get_current_user_id());
            $organization = $this->organizations->find($id);
            $this->activity->log($request['id'] ? 'organization_updated' : 'organization_created', 'organization', $id, $id, get_current_user_id(), ['name' => $organization['name'] ?? '']);
            return new WP_REST_Response($organization, $request['id'] ? 200 : 201);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_org_save_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function memberIndex(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'members' => $this->organizations->members((int) $request['id']),
            'invitations' => $this->invitations->all((int) $request['id']),
            'organization' => $this->organizations->find((int) $request['id']),
        ]);
    }

    public function memberCreate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $organizationId = (int) $request['id'];
        $data = (array) $request->get_json_params();
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $role = sanitize_key((string) ($data['org_role'] ?? 'creator'));
        $user = get_user_by('email', $email);
        if (!$user) {
            return new WP_Error('wpqs_user_not_found', __('Δεν υπάρχει WordPress χρήστης με αυτό το email. Χρησιμοποιήστε πρόσκληση.', 'wp-quiz-studio'), ['status' => 404]);
        }
        try {
            $this->organizations->addMember($organizationId, (int) $user->ID, $role);
            $this->activity->log('member_added', 'user', (int) $user->ID, $organizationId, get_current_user_id(), ['email' => $email, 'role' => $role]);
            return new WP_REST_Response($this->organizations->membership($organizationId, (int) $user->ID), 201);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_member_add_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function memberUpdate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $target = $this->organizations->memberById((int) $request['id'], (int) $request['member_id']);
        if ($target && !empty($target['is_wordpress_admin']) && !current_user_can('manage_options')) {
            return new WP_Error('wpqs_protected_administrator', __('Ο WordPress Administrator προστατεύεται και δεν μπορεί να τροποποιηθεί από Creator Admin.', 'wp-quiz-studio'), ['status' => 403]);
        }
        try {
            $member = $this->organizations->updateMember((int) $request['id'], (int) $request['member_id'], (array) $request->get_json_params());
            $this->activity->log('member_updated', 'user', (int) ($member['user_id'] ?? 0), (int) $request['id'], get_current_user_id(), ['role' => $member['org_role'] ?? '', 'status' => $member['status'] ?? '']);
            $user = get_user_by('id', (int) ($member['user_id'] ?? 0));
            if ($user) {
                $this->notifications->memberStatus((int) $request['id'], (string) $user->user_email, (string) ($member['status'] ?? 'active'));
            }
            return new WP_REST_Response($member);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_member_update_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function memberDelete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $target = $this->organizations->memberById((int) $request['id'], (int) $request['member_id']);
        if ($target && !empty($target['is_wordpress_admin']) && !current_user_can('manage_options')) {
            return new WP_Error('wpqs_protected_administrator', __('Ο WordPress Administrator προστατεύεται και δεν μπορεί να αφαιρεθεί από Creator Admin.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $data = (array) $request->get_json_params();
        try {
            $this->organizations->removeMember((int) $request['id'], (int) $request['member_id'], absint($data['transfer_to_user_id'] ?? 0));
            $this->activity->log('member_removed', 'membership', (int) $request['member_id'], (int) $request['id'], get_current_user_id());
            return new WP_REST_Response(null, 204);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_member_delete_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function invitationIndex(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->invitations->all((int) $request['id']));
    }

    public function invitationCreate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $organizationId = (int) $request['id'];
        if (!$this->access->featureEnabled('invitations', null, $organizationId)) {
            return new WP_Error('wpqs_feature_disabled', __('Οι προσκλήσεις χρηστών δεν είναι ενεργοποιημένες για αυτόν τον οργανισμό.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $data = (array) $request->get_json_params();
        $emails = $data['emails'] ?? [$data['email'] ?? ''];
        if (is_string($emails)) {
            $emails = preg_split('/[\r\n,;]+/', $emails) ?: [];
        }
        $created = [];
        $errors = [];
        foreach (array_unique(array_filter(array_map('sanitize_email', (array) $emails))) as $email) {
            try {
                $result = $this->invitations->create($organizationId, $email, (string) ($data['org_role'] ?? 'creator'), get_current_user_id(), absint($data['expires_days'] ?? 7));
                $mailSent = false;
                try {
                    $mailSent = $this->notifications->invitation($organizationId, $email, $result['token']);
                } catch (\Throwable $mailException) {
                    error_log('[Quiz Atelier] Invitation email failed: ' . $mailException->getMessage());
                }
                $invitation = $result['invitation'];
                $invitation['mail_sent'] = $mailSent;
                $created[] = $invitation;
                if (!$mailSent) {
                    $errors[] = ['email' => $email, 'message' => __('Η πρόσκληση αποθηκεύτηκε, αλλά το email δεν στάλθηκε. Ελέγξτε τις ρυθμίσεις SMTP.', 'wp-quiz-studio')];
                }
                $this->activity->log('invitation_sent', 'invitation', (int) ($result['invitation']['id'] ?? 0), $organizationId, get_current_user_id(), ['email' => $email]);
            } catch (\Throwable $exception) {
                $errors[] = ['email' => $email, 'message' => $exception->getMessage() ?: __('Δεν ολοκληρώθηκε η πρόσκληση.', 'wp-quiz-studio')];
            }
        }
        if ($created !== []) {
            try {
                $this->notifications->seatWarning($organizationId);
            } catch (\Throwable $exception) {
                error_log('[Quiz Atelier] Seat warning email failed: ' . $exception->getMessage());
            }
        }
        return new WP_REST_Response(['created' => $created, 'errors' => $errors], $errors === [] ? 201 : 207);
    }

    public function invitationResend(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $organizationId = (int) $request['id'];
        if (!$this->access->featureEnabled('invitations', null, $organizationId)) {
            return new WP_Error('wpqs_feature_disabled', __('Οι προσκλήσεις χρηστών δεν είναι ενεργοποιημένες για αυτόν τον οργανισμό.', 'wp-quiz-studio'), ['status' => 403]);
        }

        $data = (array) $request->get_json_params();
        try {
            $result = $this->invitations->resend(
                $organizationId,
                (int) $request['invitation_id'],
                get_current_user_id(),
                absint($data['expires_days'] ?? 7)
            );

            $email = sanitize_email((string) ($result['invitation']['email'] ?? ''));
            $mailSent = false;
            $warning = '';
            try {
                $mailSent = $this->notifications->invitation($organizationId, $email, $result['token']);
            } catch (\Throwable $mailException) {
                $warning = __('Η πρόσκληση ανανεώθηκε, αλλά το email δεν στάλθηκε. Ελέγξτε τις ρυθμίσεις SMTP.', 'wp-quiz-studio');
                error_log('[Quiz Atelier] Resend invitation email failed: ' . $mailException->getMessage());
            }
            if (!$mailSent && $warning === '') {
                $warning = __('Η πρόσκληση ανανεώθηκε, αλλά το email δεν στάλθηκε. Ελέγξτε τις ρυθμίσεις SMTP.', 'wp-quiz-studio');
            }

            $this->activity->log(
                'invitation_resent',
                'invitation',
                (int) ($result['invitation']['id'] ?? 0),
                $organizationId,
                get_current_user_id(),
                ['email' => $email]
            );

            return new WP_REST_Response([
                'invitation' => $result['invitation'],
                'mail_sent' => $mailSent,
                'warning' => $warning,
            ], 200);
        } catch (\Throwable $exception) {
            return new WP_Error(
                'wpqs_invitation_resend_failed',
                $exception->getMessage() ?: __('Η επαναποστολή της πρόσκλησης απέτυχε.', 'wp-quiz-studio'),
                ['status' => 422]
            );
        }
    }

    public function invitationRevoke(WP_REST_Request $request): WP_REST_Response
    {
        $this->invitations->revoke((int) $request['id'], (int) $request['invitation_id']);
        $this->activity->log('invitation_revoked', 'invitation', (int) $request['invitation_id'], (int) $request['id'], get_current_user_id());
        return new WP_REST_Response(null, 204);
    }

    public function templateIndex(): WP_REST_Response|WP_Error
    {
        if (!$this->access->featureEnabled('templates')) {
            return new WP_Error('wpqs_feature_disabled', __('Τα templates δεν είναι ενεργοποιημένα για αυτόν τον οργανισμό.', 'wp-quiz-studio'), ['status' => 403]);
        }
        return new WP_REST_Response($this->templates->all($this->access->currentOrganizationId(), true));
    }

    public function templateGet(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $template = $this->templates->find((int) $request['id']);
        if (!$template) {
            return new WP_Error('wpqs_template_not_found', __('Το template δεν βρέθηκε.', 'wp-quiz-studio'), ['status' => 404]);
        }
        if ($template['scope'] !== 'universal' && (int) $template['organization_id'] !== $this->access->currentOrganizationId() && !$this->access->canManageOrganizations()) {
            return new WP_Error('wpqs_template_forbidden', __('Δεν έχετε πρόσβαση σε αυτό το template.', 'wp-quiz-studio'), ['status' => 403]);
        }
        return new WP_REST_Response($template);
    }

    public function templateSave(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$this->access->featureEnabled('templates')) {
            return new WP_Error('wpqs_feature_disabled', __('Τα templates δεν είναι ενεργοποιημένα για αυτόν τον οργανισμό.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $data = (array) $request->get_json_params();
        $scope = sanitize_key((string) ($data['scope'] ?? 'organization'));
        if ($scope === 'universal' && !$this->access->canManageUniversal()) {
            return new WP_Error('wpqs_template_forbidden', __('Μόνο ο Super Admin ή Universal Manager μπορεί να δημιουργήσει Universal template.', 'wp-quiz-studio'), ['status' => 403]);
        }
        if ($scope !== 'universal' && !$this->access->canManageTeam() && !$this->access->canManageOrganizations()) {
            return new WP_Error('wpqs_template_forbidden', __('Μόνο Creator Admins μπορούν να δημιουργούν templates οργανισμού.', 'wp-quiz-studio'), ['status' => 403]);
        }
        if (!empty($data['quiz_id'])) {
            $quiz = $this->quizzes->find(absint($data['quiz_id']));
            if (!$quiz || !$this->access->canViewQuiz($quiz)) {
                return new WP_Error('wpqs_template_source_forbidden', __('Δεν έχετε πρόσβαση στο quiz προέλευσης.', 'wp-quiz-studio'), ['status' => 403]);
            }
            $data['snapshot'] = $quiz;
            $data['quiz_type'] = $quiz['quiz_type'];
            $data['thumbnail_url'] = $quiz['settings']['intro']['image_url'] ?? '';
            $data['title'] = $data['title'] ?? $quiz['title'];
        }
        try {
            $id = $this->templates->save($data, $this->access->currentOrganizationId(), get_current_user_id(), $this->access->canManageUniversal());
            $template = $this->templates->find($id);
            $this->activity->log('template_created', 'template', $id, (int) ($template['organization_id'] ?? 0), get_current_user_id(), ['title' => $template['title'] ?? '']);
            return new WP_REST_Response($template, 201);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_template_save_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function templateDelete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $template = $this->templates->find((int) $request['id']);
        if (!$template) {
            return new WP_REST_Response(null, 204);
        }
        $canDelete = $template['scope'] === 'universal'
            ? $this->access->canManageUniversal()
            : $this->access->canManageTeam();
        if (!$canDelete) {
            return new WP_Error('wpqs_template_delete_forbidden', __('Δεν έχετε δικαίωμα διαγραφής αυτού του template.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $this->templates->delete((int) $request['id']);
        $this->activity->log('template_deleted', 'template', (int) $request['id'], (int) ($template['organization_id'] ?? 0), get_current_user_id());
        return new WP_REST_Response(null, 204);
    }

    public function activityIndex(): WP_REST_Response|WP_Error
    {
        $context = $this->access->context();
        if (!$context['is_super_admin'] && !$context['can_manage_team']) {
            return new WP_Error('wpqs_activity_forbidden', __('Μόνο Creator Admins και WordPress Administrators βλέπουν το activity log.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $organizationId = $context['is_super_admin'] ? absint($_GET['organization_id'] ?? 0) : (int) $context['organization_id'];
        return new WP_REST_Response($this->activity->all($organizationId, 200));
    }

    public function workflowIndex(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->access->canViewQuiz($quiz)) {
            return new WP_Error('wpqs_workflow_forbidden', __('Δεν έχετε πρόσβαση στο workflow αυτού του quiz.', 'wp-quiz-studio'), ['status' => 403]);
        }
        return new WP_REST_Response(['quiz' => $quiz, 'history' => $this->reviews->all((int) $quiz['id'])]);
    }

    public function workflowAction(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->access->canViewQuiz($quiz)) {
            return new WP_Error('wpqs_workflow_forbidden', __('Δεν έχετε πρόσβαση σε αυτό το quiz.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $data = (array) $request->get_json_params();
        $action = sanitize_key((string) ($data['action'] ?? 'comment'));
        $comment = sanitize_textarea_field((string) ($data['comment'] ?? ''));
        $isOwner = (int) $quiz['author_id'] === get_current_user_id();
        $canReview = $this->access->canReview(null, (int) $quiz['organization_id']);
        $changes = [];
        if ($action === 'submitted' && $isOwner && $this->access->canEditQuiz($quiz)) {
            $changes = ['workflow_status' => 'submitted'];
        } elseif ($action === 'changes_requested' && $canReview) {
            $changes = ['workflow_status' => 'changes_requested', 'status' => 'draft'];
        } elseif ($action === 'approved' && $canReview) {
            $changes = ['workflow_status' => 'approved'];
        } elseif ($action === 'published' && $canReview && $this->access->canPublish()) {
            $changes = ['workflow_status' => 'published', 'status' => 'published'];
        } elseif ($action !== 'comment') {
            return new WP_Error('wpqs_workflow_action_forbidden', __('Δεν επιτρέπεται αυτή η ενέργεια workflow.', 'wp-quiz-studio'), ['status' => 403]);
        }
        try {
            if ($changes !== []) {
                $quiz = $this->quizzes->quickUpdate((int) $quiz['id'], $changes);
            }
            $this->reviews->add((int) $quiz['id'], (int) $quiz['organization_id'], get_current_user_id(), $action, $comment);
            $this->activity->log('quiz_' . $action, 'quiz', (int) $quiz['id'], (int) $quiz['organization_id'], get_current_user_id(), ['comment' => $comment]);
            if ($action !== 'comment') {
                $this->notifications->workflow($quiz, $action, $comment);
            }
            $quiz['review_history'] = $this->reviews->all((int) $quiz['id']);
            return new WP_REST_Response($quiz);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_workflow_failed', $exception->getMessage(), ['status' => 422]);
        }
    }

    public function profileGet(): WP_REST_Response
    {
        return $this->me();
    }

    public function profileSave(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $data = (array) $request->get_json_params();
        $userId = get_current_user_id();
        $update = ['ID' => $userId];
        if (isset($data['display_name'])) {
            $update['display_name'] = sanitize_text_field((string) $data['display_name']);
        }
        if (isset($data['first_name'])) {
            $update['first_name'] = sanitize_text_field((string) $data['first_name']);
        }
        if (isset($data['last_name'])) {
            $update['last_name'] = sanitize_text_field((string) $data['last_name']);
        }
        if (isset($data['website'])) {
            $update['user_url'] = esc_url_raw((string) $data['website']);
        }
        if (isset($data['email'])) {
            $email = sanitize_email((string) $data['email']);
            if (!is_email($email)) {
                return new WP_Error('wpqs_invalid_email', __('Το email δεν είναι έγκυρο.', 'wp-quiz-studio'), ['status' => 422]);
            }
            $existingEmailUser = get_user_by('email', $email);
            if ($existingEmailUser && (int) $existingEmailUser->ID !== $userId) {
                return new WP_Error('wpqs_email_exists', __('Το email χρησιμοποιείται ήδη από άλλο λογαριασμό.', 'wp-quiz-studio'), ['status' => 422]);
            }
            $update['user_email'] = $email;
        }
        if (!empty($data['password'])) {
            $update['user_pass'] = (string) $data['password'];
        }
        $result = wp_update_user($update);
        if (is_wp_error($result)) {
            return $result;
        }
        update_user_meta($userId, 'wpqs_language', sanitize_key((string) ($data['language'] ?? 'el')));
        update_user_meta($userId, 'wpqs_email_preferences', array_map('boolval', (array) ($data['email_preferences'] ?? [])));
        $this->activity->log('profile_updated', 'user', $userId, $this->access->currentOrganizationId(), $userId);
        return $this->me();
    }

    public function exportQuiz(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$this->access->featureEnabled('exports')) {
            return new WP_Error('wpqs_feature_disabled', __('Οι εξαγωγές δεν είναι ενεργοποιημένες για αυτόν τον οργανισμό.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->access->canViewQuiz($quiz)) {
            return new WP_Error('wpqs_not_found', __('Το quiz δεν βρέθηκε ή δεν έχετε πρόσβαση.', 'wp-quiz-studio'), ['status' => 404]);
        }

        return new WP_REST_Response([
            'format' => 'wp-quiz-studio',
            'version' => WPQS_VERSION,
            'exported_at' => gmdate('c'),
            'quiz' => $quiz,
        ]);
    }

    public function importQuiz(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$this->access->featureEnabled('exports')) {
            return new WP_Error('wpqs_feature_disabled', __('Οι εισαγωγές δεν είναι ενεργοποιημένες για αυτόν τον οργανισμό.', 'wp-quiz-studio'), ['status' => 403]);
        }
        $data = (array) $request->get_json_params();
        $quiz = isset($data['quiz']) && is_array($data['quiz']) ? $data['quiz'] : $data;
        if (!is_array($quiz) || $quiz === []) {
            return new WP_Error('wpqs_invalid_import', __('Το αρχείο JSON δεν περιέχει έγκυρο quiz.', 'wp-quiz-studio'), ['status' => 422]);
        }

        $quiz = $this->stripImportedIds($quiz);
        $quiz['status'] = 'draft';
        $quiz['scheduled_at'] = null;
        $quiz['title'] = sprintf(__('%s (Εισαγωγή)', 'wp-quiz-studio'), sanitize_text_field((string) ($quiz['title'] ?? __('Quiz', 'wp-quiz-studio'))));
        $quiz['slug'] = '';
        $quiz['organization_id'] = $this->access->currentOrganizationId();
        $quiz['visibility_scope'] = 'personal';
        $quiz['workflow_status'] = 'draft';

        try {
            $id = $this->quizzes->save($quiz, get_current_user_id(), false);
            return new WP_REST_Response($this->quizzes->find($id), 201);
        } catch (\RuntimeException $exception) {
            return new WP_Error('wpqs_import_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    public function publicCategories(): WP_REST_Response
    {
        return new WP_REST_Response($this->categories->all(true));
    }

    public function publicDirectory(WP_REST_Request $request): WP_REST_Response
    {
        $categoryId = absint($request->get_param('category_id'));
        $categorySlug = sanitize_title((string) $request->get_param('category'));
        if (!$categoryId && $categorySlug !== '') {
            $category = $this->categories->findBySlug($categorySlug);
            if (!$category) {
                return new WP_REST_Response([]);
            }
            $categoryId = (int) $category['id'];
        }

        return new WP_REST_Response($this->quizzes->publicDirectory($categoryId));
    }

    public function publicQuiz(WP_REST_Request $request): WP_REST_Response
    {
        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz) {
            return new WP_REST_Response(['message' => __('Το quiz δεν είναι διαθέσιμο.', 'wp-quiz-studio')], 404);
        }
        if (!$this->quizzes->isAvailable($quiz)) {
            $expired = !empty($quiz['expires_at']) && strtotime((string) $quiz['expires_at'] . ' UTC') <= time();
            return new WP_REST_Response([
                'message' => $expired ? __('Το quiz έχει λήξει.', 'wp-quiz-studio') : __('Το quiz δεν είναι διαθέσιμο.', 'wp-quiz-studio'),
                'expired' => $expired,
            ], $expired ? 410 : 404);
        }

        $organization = !empty($quiz['organization_id']) ? $this->organizations->find((int) $quiz['organization_id']) : null;
        if ($organization) {
            $branding = is_array($organization['branding'] ?? null) ? $organization['branding'] : [];
            $features = is_array($organization['features'] ?? null) ? $organization['features'] : [];
            $quiz['organization_branding'] = !empty($features['white_label']) ? [
                'organization_name' => sanitize_text_field((string) ($organization['name'] ?? '')),
                'logo_url' => esc_url_raw((string) ($branding['logo_url'] ?? '')),
                'accent' => sanitize_hex_color((string) ($branding['accent'] ?? '')) ?: '#d9bd85',
                'accent_secondary' => sanitize_hex_color((string) ($branding['accent_secondary'] ?? '')) ?: '#b9a7ff',
                'footer_text' => sanitize_text_field((string) ($branding['footer_text'] ?? '')),
            ] : null;
        }

        $allQuestionIds = array_values(array_map(static fn (array $question): int => (int) ($question['id'] ?? 0), (array) $quiz['questions']));
        if (!empty($quiz['settings']['random_questions'])) {
            shuffle($quiz['questions']);
            $limit = absint($quiz['settings']['random_question_limit'] ?? 0);
            if ($limit > 0 && $limit < count($quiz['questions'])) {
                $quiz['questions'] = array_slice($quiz['questions'], 0, $limit);
            }
        }
        $visibleQuestionIds = array_values(array_map(static fn (array $question): int => (int) ($question['id'] ?? 0), (array) $quiz['questions']));
        $quiz['runtime_excluded_questions'] = array_values(array_diff($allQuestionIds, $visibleQuestionIds));

        foreach ($quiz['questions'] as &$question) {
            $questionType = (string) ($question['type'] ?? 'multiple_choice');
            if (in_array($questionType, ['ordering', 'ranking'], true)) {
                // Ordering questions must start in a random order; the repository keeps the canonical correct order.
                shuffle($question['answers']);
            } elseif (!empty($question['settings']['shuffle_answers']) && $questionType !== 'matching') {
                shuffle($question['answers']);
            }

            // Grading rules and explanations are returned only after an answer is checked.
            unset(
                $question['settings']['explanation'],
                $question['settings']['points'],
                $question['settings']['correct_min'],
                $question['settings']['correct_max'],
                $question['settings']['numeric_answer'],
                $question['settings']['numeric_tolerance']
            );

            if ($questionType === 'matching') {
                $matchingOptions = [];
                foreach ($question['answers'] as $answer) {
                    $matchingOptions[] = [
                        'id' => (int) ($answer['id'] ?? 0),
                        'text' => (string) ($answer['content']['match_text'] ?? ''),
                    ];
                }
                shuffle($matchingOptions);
                $question['matching_options'] = $matchingOptions;
            }

            foreach ($question['answers'] as &$answer) {
                unset($answer['is_correct'], $answer['score']);
                if (isset($answer['content']) && is_array($answer['content'])) {
                    unset($answer['content']['personality_weights']);
                    if ($questionType === 'matching') {
                        unset($answer['content']['match_text']);
                    }
                }
            }
            unset($answer);
        }
        unset($question);

        return new WP_REST_Response($quiz);
    }

    public function event(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        if (!$this->rateLimiter->allow('event', 120, 60)) {
            return new WP_Error('wpqs_rate_limited', __('Πάρα πολλά αιτήματα. Δοκιμάστε ξανά σε λίγο.', 'wp-quiz-studio'), ['status' => 429]);
        }

        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->quizzes->isAvailable($quiz)) {
            $expired = $quiz && !empty($quiz['expires_at']) && strtotime((string) $quiz['expires_at'] . ' UTC') <= time();
            return new WP_Error(
                'wpqs_quiz_unavailable',
                $expired ? __('Το quiz έχει λήξει.', 'wp-quiz-studio') : __('Το quiz δεν είναι διαθέσιμο.', 'wp-quiz-studio'),
                ['status' => $expired ? 410 : 404, 'expired' => $expired]
            );
        }

        $data = (array) $request->get_json_params();
        $event = sanitize_key((string) ($data['event'] ?? 'view'));
        if (!in_array($event, ['view', 'start', 'question_view', 'share', 'restart'], true)) {
            $event = 'view';
        }
        $session = $this->normaliseSession((string) ($data['session_id'] ?? ''));
        $metadata = $this->publicMetadata((array) ($data['metadata'] ?? []));

        $inserted = $wpdb->insert($wpdb->prefix . 'wpqs_analytics', [
            'quiz_id' => (int) $request['id'],
            'event_type' => $event,
            'question_id' => $event === 'question_view' ? absint($data['question_id'] ?? 0) ?: null : null,
            'session_id' => $session,
            'metadata' => wp_json_encode($metadata),
            'created_at' => current_time('mysql', true),
        ]);

        if ($inserted === false) {
            return new WP_Error('wpqs_event_failed', __('Δεν ήταν δυνατή η καταγραφή του συμβάντος.', 'wp-quiz-studio'), ['status' => 500]);
        }

        if ($event === 'start' && $session !== '') {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wpqs_sessions WHERE id = %s",
                $session
            ));
            if (!$exists) {
                $wpdb->insert($wpdb->prefix . 'wpqs_sessions', [
                    'id' => $session,
                    'quiz_id' => (int) $request['id'],
                    'started_at' => current_time('mysql', true),
                    'completed_at' => null,
                    'context' => wp_json_encode($metadata),
                ]);
            }
        }

        return new WP_REST_Response(['ok' => true], 201);
    }

    public function checkAnswer(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$this->rateLimiter->allow('check', 120, 60)) {
            return new WP_Error('wpqs_rate_limited', __('Πάρα πολλά αιτήματα. Δοκιμάστε ξανά σε λίγο.', 'wp-quiz-studio'), ['status' => 429]);
        }

        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->quizzes->isAvailable($quiz)) {
            $expired = $quiz && !empty($quiz['expires_at']) && strtotime((string) $quiz['expires_at'] . ' UTC') <= time();
            return new WP_Error(
                'wpqs_quiz_unavailable',
                $expired ? __('Το quiz έχει λήξει.', 'wp-quiz-studio') : __('Το quiz δεν είναι διαθέσιμο.', 'wp-quiz-studio'),
                ['status' => $expired ? 410 : 404, 'expired' => $expired]
            );
        }

        $data = (array) $request->get_json_params();
        $questionId = absint($data['question_id'] ?? 0);
        if (!$questionId) {
            return new WP_Error('wpqs_question_required', __('Δεν βρέθηκε η ερώτηση.', 'wp-quiz-studio'), ['status' => 422]);
        }

        $feedback = $this->feedback->evaluate($quiz, $questionId, $data['answer'] ?? null);
        if (!$feedback) {
            return new WP_Error('wpqs_question_not_found', __('Η ερώτηση δεν βρέθηκε.', 'wp-quiz-studio'), ['status' => 404]);
        }

        if (empty($quiz['settings']['show_correct_answer'])) {
            $feedback['correct_answers'] = [];
        }

        return new WP_REST_Response($feedback);
    }

    /** Scores responses on the server so correct-answer data is never exposed to visitors. */
    public function submit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        if (!$this->rateLimiter->allow('submit', 30, 60)) {
            return new WP_Error('wpqs_rate_limited', __('Πάρα πολλές υποβολές. Δοκιμάστε ξανά σε λίγο.', 'wp-quiz-studio'), ['status' => 429]);
        }

        $quiz = $this->quizzes->find((int) $request['id']);
        if (!$quiz || !$this->quizzes->isAvailable($quiz)) {
            $expired = $quiz && !empty($quiz['expires_at']) && strtotime((string) $quiz['expires_at'] . ' UTC') <= time();
            return new WP_Error(
                'wpqs_quiz_unavailable',
                $expired ? __('Το quiz έχει λήξει.', 'wp-quiz-studio') : __('Το quiz δεν είναι διαθέσιμο.', 'wp-quiz-studio'),
                ['status' => $expired ? 410 : 404, 'expired' => $expired]
            );
        }

        $data = (array) $request->get_json_params();
        $session = $this->normaliseSession((string) ($data['session_id'] ?? '')) ?: wp_generate_uuid4();
        $existingPayload = $wpdb->get_var($wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}wpqs_results WHERE quiz_id = %d AND session_id = %s ORDER BY id DESC LIMIT 1",
            (int) $quiz['id'],
            $session
        ));
        if (is_string($existingPayload) && $existingPayload !== '') {
            $decoded = json_decode($existingPayload, true);
            if (is_array($decoded) && isset($decoded['response'])) {
                return new WP_REST_Response($decoded['response'], 200);
            }
        }

        $responses = $this->sanitizeResponses($quiz, (array) ($data['answers'] ?? []));
        $timings = $this->sanitizeTimings((array) ($data['timings'] ?? []));
        $hiddenQuestions = $this->sanitizeQuestionIds((array) ($data['hidden_questions'] ?? []), $quiz);
        $scored = $this->scorer->score($quiz, $responses, $timings, $hiddenQuestions);
        $response = [
            'score' => $scored['score'],
            'max_score' => $scored['max_score'],
            'correct' => $scored['correct'],
            'total' => $scored['total'],
            'result' => $scored['result'],
            'pass' => $scored['pass'],
            'pass_score' => $scored['pass_score'],
            'personality_scores' => $scored['personality_scores'],
            'review' => !empty($quiz['settings']['review_answers']) ? $scored['review'] : [],
        ];
        $now = current_time('mysql', true);

        $wpdb->query('START TRANSACTION');
        try {
            $resultInserted = $wpdb->insert($wpdb->prefix . 'wpqs_results', [
                'quiz_id' => (int) $quiz['id'],
                'session_id' => $session,
                'score' => $scored['score'],
                'payload' => wp_json_encode([
                    'answers' => $responses,
                    'hidden_questions' => $hiddenQuestions,
                    'correct' => $scored['correct'],
                    'total' => $scored['total'],
                    'max_score' => $scored['max_score'],
                    'pass' => $scored['pass'],
                    'response' => $response,
                ]),
                'completed_at' => $now,
            ]);
            if ($resultInserted === false) {
                throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η αποθήκευση του αποτελέσματος.');
            }

            foreach ($scored['question_results'] as $questionResult) {
                $eventInserted = $wpdb->insert($wpdb->prefix . 'wpqs_analytics', [
                    'quiz_id' => (int) $quiz['id'],
                    'event_type' => 'answer',
                    'question_id' => (int) $questionResult['question_id'],
                    'session_id' => $session,
                    'metadata' => wp_json_encode([
                        'correct' => (bool) $questionResult['correct'],
                        'skipped' => (bool) $questionResult['skipped'],
                        'score' => (float) $questionResult['score'],
                        'gradable' => (bool) $questionResult['gradable'],
                        'time' => (float) $questionResult['time'],
                        'selected_answer_ids' => array_values((array) ($questionResult['selected_answer_ids'] ?? [])),
                        'response_value' => $questionResult['response_value'] ?? null,
                    ]),
                    'created_at' => $now,
                ]);
                if ($eventInserted === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η αποθήκευση των στατιστικών απάντησης.');
                }
            }

            $completeInserted = $wpdb->insert($wpdb->prefix . 'wpqs_analytics', [
                'quiz_id' => (int) $quiz['id'],
                'event_type' => 'complete',
                'question_id' => null,
                'session_id' => $session,
                'metadata' => wp_json_encode([
                    'score' => $scored['score'],
                    'correct' => $scored['correct'],
                    'total' => $scored['total'],
                    'max_score' => $scored['max_score'],
                    'pass' => $scored['pass'],
                    'result_key' => sanitize_key((string) ($scored['result']['key'] ?? '')),
                    'result_title' => sanitize_text_field((string) ($scored['result']['title'] ?? '')),
                ]),
                'created_at' => $now,
            ]);
            if ($completeInserted === false) {
                throw new \RuntimeException($wpdb->last_error ?: 'Δεν ήταν δυνατή η αποθήκευση των στατιστικών ολοκλήρωσης.');
            }

            $sessionExists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wpqs_sessions WHERE id = %s",
                $session
            ));
            if ($sessionExists) {
                $wpdb->update($wpdb->prefix . 'wpqs_sessions', ['completed_at' => $now], ['id' => $session]);
            } else {
                $wpdb->insert($wpdb->prefix . 'wpqs_sessions', [
                    'id' => $session,
                    'quiz_id' => (int) $quiz['id'],
                    'started_at' => $now,
                    'completed_at' => $now,
                    'context' => wp_json_encode([]),
                ]);
            }

            $wpdb->query('COMMIT');
            return new WP_REST_Response($response, 201);
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('wpqs_submit_failed', $exception->getMessage(), ['status' => 500]);
        }
    }



    /**
     * Repairs legacy single-choice payloads that contain multiple correct answers.
     * Radio inputs can visually display only the last checked value while stale data
     * still contains more than one true flag. Keeping the final true value mirrors
     * the value displayed by the browser.
     */
    private function normaliseSingleCorrectAnswers(array $data): array
    {
        $singleTypes = ['multiple_choice', 'true_false', 'image_choice'];
        $questions = array_values((array) ($data['questions'] ?? []));

        foreach ($questions as $questionIndex => $question) {
            $question = (array) $question;
            $type = (string) ($question['type'] ?? 'multiple_choice');
            if (!in_array($type, $singleTypes, true)) {
                continue;
            }

            $answers = array_values((array) ($question['answers'] ?? []));
            $selectedIndex = null;
            foreach ($answers as $answerIndex => $answer) {
                if (!empty(((array) $answer)['is_correct'])) {
                    $selectedIndex = $answerIndex;
                }
            }

            if ($selectedIndex === null) {
                continue;
            }

            foreach ($answers as $answerIndex => $answer) {
                $answer = (array) $answer;
                $answer['is_correct'] = $answerIndex === $selectedIndex;
                $answers[$answerIndex] = $answer;
            }

            $question['answers'] = $answers;
            $questions[$questionIndex] = $question;
        }

        $data['questions'] = $questions;
        return $data;
    }

    private function validateForPublishing(array $data): ?WP_Error
    {
        $errors = [];
        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors[] = __('Προσθέστε τίτλο στο quiz.', 'wp-quiz-studio');
        }

        $questions = (array) ($data['questions'] ?? []);
        if ($questions === []) {
            $errors[] = __('Προσθέστε τουλάχιστον μία ερώτηση.', 'wp-quiz-studio');
        }

        $questionKeyMap = [];
        foreach ($questions as $position => $candidate) {
            $candidate = (array) $candidate;
            $candidateSettings = (array) ($candidate['settings'] ?? []);
            $candidateKey = sanitize_key((string) ($candidateSettings['key'] ?? ''));
            if ($candidateKey !== '') {
                $answerKeys = [];
                foreach ((array) ($candidate['answers'] ?? []) as $candidateAnswer) {
                    $candidateAnswer = (array) $candidateAnswer;
                    $candidateContent = (array) ($candidateAnswer['content'] ?? []);
                    $answerKey = sanitize_key((string) ($candidateContent['key'] ?? ''));
                    if ($answerKey !== '') {
                        $answerKeys[] = $answerKey;
                    }
                }
                $questionKeyMap[$candidateKey] = ['position' => (int) $position, 'type' => (string) ($candidate['type'] ?? 'multiple_choice'), 'answers' => $answerKeys];
            }
        }

        foreach ($questions as $index => $question) {
            $question = (array) $question;
            $number = (int) $index + 1;
            $type = (string) ($question['type'] ?? 'multiple_choice');
            $content = (array) ($question['content'] ?? []);
            $title = trim((string) ($content['title'] ?? ''));
            $answers = (array) ($question['answers'] ?? []);
            if ($title === '') {
                $errors[] = sprintf(__('Η ερώτηση %d χρειάζεται τίτλο.', 'wp-quiz-studio'), $number);
            }

            $minimumAnswers = match ($type) {
                'slider', 'numeric', 'rating' => 0,
                'open_text' => 1,
                default => 2,
            };
            if (count($answers) < $minimumAnswers) {
                $errors[] = sprintf(__('Η ερώτηση %d χρειάζεται τουλάχιστον %d απάντηση/απαντήσεις.', 'wp-quiz-studio'), $number, $minimumAnswers);
            }

            $correct = 0;
            foreach ($answers as $answer) {
                $answer = (array) $answer;
                if (!empty($answer['is_correct'])) {
                    $correct++;
                }
                $answerContent = (array) ($answer['content'] ?? []);
                $text = trim((string) ($answerContent['text'] ?? $answer['text'] ?? ''));
                $image = trim((string) ($answerContent['image_url'] ?? ''));
                if ($text === '' && !($type === 'image_choice' && $image !== '') && !in_array($type, ['slider', 'numeric', 'rating'], true)) {
                    $errors[] = sprintf(__('Η ερώτηση %d περιέχει κενή απάντηση.', 'wp-quiz-studio'), $number);
                    break;
                }
                if ($type === 'matching' && trim((string) ($answerContent['match_text'] ?? '')) === '') {
                    $errors[] = sprintf(__('Η ερώτηση %d περιέχει ζεύγος αντιστοίχισης χωρίς δεξιά τιμή.', 'wp-quiz-studio'), $number);
                    break;
                }
            }

            $quizType = (string) ($data['quiz_type'] ?? 'knowledge');
            $typesRequiringCorrect = ['multiple_choice', 'multiple_answers', 'true_false', 'image_choice', 'open_text'];
            if ($quizType !== 'personality' && in_array($type, $typesRequiringCorrect, true) && $correct < 1) {
                $errors[] = sprintf(__('Η ερώτηση %d χρειάζεται σωστή απάντηση.', 'wp-quiz-studio'), $number);
            }
            if ($quizType !== 'personality' && in_array($type, ['multiple_choice', 'true_false', 'image_choice'], true) && $correct > 1) {
                $errors[] = sprintf(__('Η ερώτηση %d μπορεί να έχει μόνο μία σωστή απάντηση.', 'wp-quiz-studio'), $number);
            }

            $questionSettings = (array) ($question['settings'] ?? []);
            if (in_array($type, ['slider', 'rating'], true)) {
                if ($type === 'rating') {
                    $minimum = 1.0;
                    $maximum = max(2.0, (float) ($questionSettings['rating_max'] ?? 5));
                } else {
                    $minimum = (float) ($questionSettings['slider_min'] ?? 0);
                    $maximum = (float) ($questionSettings['slider_max'] ?? 100);
                }
                $correctMinimum = (float) ($questionSettings['correct_min'] ?? $minimum);
                $correctMaximum = (float) ($questionSettings['correct_max'] ?? $maximum);
                if ($maximum <= $minimum || $correctMinimum > $correctMaximum || $correctMinimum < $minimum || $correctMaximum > $maximum) {
                    $errors[] = sprintf(__('Η ερώτηση %d έχει μη έγκυρα όρια τιμών.', 'wp-quiz-studio'), $number);
                }
            }

            $condition = (array) ($questionSettings['condition'] ?? []);
            if (!empty($condition['enabled'])) {
                $rules = (array) ($condition['rules'] ?? []);
                if ($rules === [] && !empty($condition['question_key'])) {
                    $rules[] = $condition;
                }
                if ($rules === []) {
                    $errors[] = sprintf(__('Η ερώτηση %d χρειάζεται τουλάχιστον έναν κανόνα εμφάνισης.', 'wp-quiz-studio'), $number);
                }
                foreach ($rules as $rule) {
                    $rule = (array) $rule;
                    $sourceKey = sanitize_key((string) ($rule['question_key'] ?? ''));
                    $answerKey = sanitize_key((string) ($rule['answer_key'] ?? ''));
                    $operator = (string) ($rule['operator'] ?? 'equals');
                    $source = $questionKeyMap[$sourceKey] ?? null;
                    if (!$source || (int) $source['position'] >= (int) $index || (string) ($source['type'] ?? '') === 'open_text') {
                        $errors[] = sprintf(__('Η ερώτηση %d έχει μη έγκυρη ερώτηση προέλευσης στους όρους.', 'wp-quiz-studio'), $number);
                        break;
                    }
                    if (in_array($operator, ['equals', 'not_equals'], true) && ($answerKey === '' || !in_array($answerKey, (array) $source['answers'], true))) {
                        $errors[] = sprintf(__('Η ερώτηση %d έχει μη έγκυρη απάντηση στους όρους.', 'wp-quiz-studio'), $number);
                        break;
                    }
                }
            }
        }

        $settings = (array) ($data['settings'] ?? []);
        if (!empty($settings['random_questions'])) {
            foreach ($questions as $question) {
                $questionSettings = (array) (((array) $question)['settings'] ?? []);
                if (!empty(((array) ($questionSettings['condition'] ?? []))['enabled'])) {
                    $errors[] = __('Η τυχαία σειρά ερωτήσεων δεν μπορεί να συνδυαστεί με conditional logic.', 'wp-quiz-studio');
                    break;
                }
            }
        }
        if ((string) ($data['quiz_type'] ?? 'knowledge') === 'personality') {
            $profiles = array_values((array) ($settings['personality_profiles'] ?? []));
            if (count($profiles) < 2) {
                $errors[] = __('Το Personality Test χρειάζεται τουλάχιστον δύο προφίλ αποτελέσματος.', 'wp-quiz-studio');
            }
            $profileKeys = array_values(array_filter(array_map(static fn (mixed $profile): string => sanitize_key((string) (((array) $profile)['key'] ?? '')), $profiles)));
            $hasWeight = false;
            foreach ($questions as $question) {
                foreach ((array) (((array) $question)['answers'] ?? []) as $answer) {
                    foreach ((array) (((array) ($answer['content'] ?? []))['personality_weights'] ?? []) as $profileKey => $weight) {
                        if (in_array(sanitize_key((string) $profileKey), $profileKeys, true) && (float) $weight != 0.0) {
                            $hasWeight = true;
                            break 3;
                        }
                    }
                }
            }
            if (!$hasWeight) {
                $errors[] = __('Προσθέστε βαθμούς προσωπικότητας σε τουλάχιστον μία απάντηση.', 'wp-quiz-studio');
            }
        }

        $ranges = array_map(static fn (mixed $range): array => (array) $range, (array) ($settings['results'] ?? []));
        usort($ranges, static fn (array $left, array $right): int => (float) ($left['min'] ?? 0) <=> (float) ($right['min'] ?? 0));
        $previousMaximum = null;
        foreach ($ranges as $range) {
            $minimum = (float) ($range['min'] ?? 0);
            $maximum = (float) ($range['max'] ?? 0);
            if ($minimum > $maximum) {
                $errors[] = __('Ένα εύρος αποτελέσματος έχει ελάχιστο μεγαλύτερο από το μέγιστο.', 'wp-quiz-studio');
                break;
            }
            if ($previousMaximum !== null && $minimum <= $previousMaximum) {
                $errors[] = __('Τα εύρη βαθμολογίας των αποτελεσμάτων δεν πρέπει να επικαλύπτονται.', 'wp-quiz-studio');
                break;
            }
            $previousMaximum = $maximum;
        }

        if (($data['status'] ?? '') === 'scheduled' && empty($data['scheduled_at'])) {
            $errors[] = __('Επιλέξτε ημερομηνία προγραμματισμένης δημοσίευσης.', 'wp-quiz-studio');
        }

        $expiresAt = trim((string) ($data['expires_at'] ?? ''));
        $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;
        if ($expiresAt !== '' && $expiresTimestamp === false) {
            $errors[] = __('Η ημερομηνία λήξης δεν είναι έγκυρη.', 'wp-quiz-studio');
        }
        if ($expiresTimestamp !== false && in_array((string) ($data['status'] ?? ''), ['published', 'scheduled'], true) && $expiresTimestamp <= time()) {
            $errors[] = __('Η ημερομηνία λήξης πρέπει να είναι στο μέλλον.', 'wp-quiz-studio');
        }

        $scheduledAt = trim((string) ($data['scheduled_at'] ?? ''));
        $scheduledTimestamp = $scheduledAt !== '' ? strtotime($scheduledAt) : false;
        if ($expiresTimestamp !== false && $scheduledTimestamp !== false && $expiresTimestamp <= $scheduledTimestamp) {
            $errors[] = __('Η ημερομηνία λήξης πρέπει να είναι μεταγενέστερη από τη δημοσίευση.', 'wp-quiz-studio');
        }

        if ($errors === []) {
            return null;
        }

        return new WP_Error('wpqs_validation_failed', implode(' ', array_unique($errors)), [
            'status' => 422,
            'errors' => array_values(array_unique($errors)),
        ]);
    }

    private function stripImportedIds(array $quiz): array
    {
        unset($quiz['id'], $quiz['author_id'], $quiz['created_at'], $quiz['updated_at'], $quiz['views'], $quiz['starts'], $quiz['completions']);
        $questions = [];
        foreach (array_values((array) ($quiz['questions'] ?? [])) as $question) {
            $question = (array) $question;
            unset($question['id'], $question['quiz_id'], $question['position']);
            $answers = [];
            foreach (array_values((array) ($question['answers'] ?? [])) as $answer) {
                $answer = (array) $answer;
                unset($answer['id'], $answer['question_id'], $answer['position']);
                $answers[] = $answer;
            }
            $question['answers'] = $answers;
            $questions[] = $question;
        }
        $quiz['questions'] = $questions;
        return $quiz;
    }

    /** @return list<int> */
    private function sanitizeQuestionIds(array $ids, array $quiz): array
    {
        $valid = array_map(static fn (array $question): int => (int) ($question['id'] ?? 0), (array) ($quiz['questions'] ?? []));
        $clean = array_values(array_unique(array_map('absint', $ids)));
        return array_values(array_intersect($clean, $valid));
    }

    private function sanitizeResponses(array $quiz, array $responses): array
    {
        $sanitized = [];
        foreach ((array) ($quiz['questions'] ?? []) as $question) {
            $questionId = (int) ($question['id'] ?? 0);
            $key = (string) $questionId;
            if (!array_key_exists($key, $responses)) {
                continue;
            }

            $type = (string) ($question['type'] ?? 'multiple_choice');
            if (in_array($type, ['multiple_answers', 'ordering', 'ranking'], true)) {
                $ids = array_slice(array_values(array_unique(array_map('absint', (array) $responses[$key]))), 0, 100);
                $sanitized[$key] = $ids;
            } elseif ($type === 'matching') {
                $mapping = [];
                foreach ((array) $responses[$key] as $leftId => $rightId) {
                    $left = absint($leftId);
                    $right = absint($rightId);
                    if ($left && $right) {
                        $mapping[(string) $left] = $right;
                    }
                }
                $sanitized[$key] = array_slice($mapping, 0, 100, true);
            } elseif ($type === 'open_text') {
                $value = sanitize_text_field((string) $responses[$key]);
                $sanitized[$key] = function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);
            } elseif (in_array($type, ['slider', 'numeric', 'rating'], true)) {
                $sanitized[$key] = round((float) $responses[$key], 4);
            } else {
                $sanitized[$key] = absint($responses[$key]);
            }
        }
        return $sanitized;
    }

    private function sanitizeTimings(array $timings): array
    {
        $sanitized = [];
        foreach ($timings as $questionId => $seconds) {
            $id = absint($questionId);
            if (!$id) {
                continue;
            }
            $sanitized[(string) $id] = min(86400, max(0, round((float) $seconds, 1)));
        }
        return $sanitized;
    }

    private function normaliseSession(string $session): string
    {
        $session = sanitize_text_field($session);
        return preg_match('/^[a-zA-Z0-9-]{8,36}$/', $session) ? substr($session, 0, 36) : '';
    }

    private function publicMetadata(array $metadata): array
    {
        $referrer = esc_url_raw((string) ($metadata['referrer'] ?? ''));
        $referrerHost = strtolower((string) wp_parse_url($referrer, PHP_URL_HOST));
        $agent = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $country = sanitize_key((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['GEOIP_COUNTRY_CODE'] ?? ''));
        $city = sanitize_text_field((string) ($_SERVER['HTTP_CF_IPCITY'] ?? $_SERVER['GEOIP_CITY'] ?? ''));

        return [
            'referrer' => $referrer,
            'referrer_host' => preg_replace('/^www\./', '', $referrerHost) ?: '',
            'device' => sanitize_key((string) ($metadata['device'] ?? '')),
            'browser' => $this->detectBrowser($agent),
            'os' => $this->detectOs($agent),
            'country' => strtoupper(substr($country, 0, 3)),
            'city' => function_exists('mb_substr') ? mb_substr($city, 0, 100) : substr($city, 0, 100),
            'language' => sanitize_text_field((string) ($metadata['language'] ?? '')),
            'timezone' => sanitize_text_field((string) ($metadata['timezone'] ?? '')),
            'screen' => sanitize_text_field((string) ($metadata['screen'] ?? '')),
            'utm_source' => sanitize_text_field((string) ($metadata['utm_source'] ?? '')),
            'utm_medium' => sanitize_text_field((string) ($metadata['utm_medium'] ?? '')),
            'utm_campaign' => sanitize_text_field((string) ($metadata['utm_campaign'] ?? '')),
        ];
    }

    /** @return array{from:string,to:string,group:string} */
    private function analyticsFilters(WP_REST_Request $request): array
    {
        $from = sanitize_text_field((string) $request->get_param('from'));
        $to = sanitize_text_field((string) $request->get_param('to'));
        $group = sanitize_key((string) $request->get_param('group'));
        return [
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '',
            'group' => in_array($group, ['day', 'week', 'month'], true) ? $group : 'day',
        ];
    }

    private function detectBrowser(string $agent): string
    {
        return match (true) {
            stripos($agent, 'Edg/') !== false => 'Edge',
            stripos($agent, 'OPR/') !== false || stripos($agent, 'Opera') !== false => 'Opera',
            stripos($agent, 'Chrome/') !== false => 'Chrome',
            stripos($agent, 'Firefox/') !== false => 'Firefox',
            stripos($agent, 'Safari/') !== false => 'Safari',
            default => 'Άλλο',
        };
    }

    private function detectOs(string $agent): string
    {
        return match (true) {
            stripos($agent, 'Android') !== false => 'Android',
            stripos($agent, 'iPhone') !== false || stripos($agent, 'iPad') !== false => 'iOS',
            stripos($agent, 'Windows') !== false => 'Windows',
            stripos($agent, 'Mac OS') !== false || stripos($agent, 'Macintosh') !== false => 'macOS',
            stripos($agent, 'Linux') !== false => 'Linux',
            default => 'Άλλο',
        };
    }
}

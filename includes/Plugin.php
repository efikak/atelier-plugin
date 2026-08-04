<?php

declare(strict_types=1);

namespace WPQuizStudio;

use WPQuizStudio\Admin\LegacyPluginsNotice;
use WPQuizStudio\Admin\Menu;
use WPQuizStudio\Admin\Settings;
use WPQuizStudio\Api\Routes;
use WPQuizStudio\Database\Installer;
use WPQuizStudio\Embed\Player;
use WPQuizStudio\Front\Directory;
use WPQuizStudio\Front\Invitation;
use WPQuizStudio\Front\Studio;
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
use WPQuizStudio\Security\EmbedPolicy;
use WPQuizStudio\Security\LoginSecurity;
use WPQuizStudio\Security\RateLimiter;
use WPQuizStudio\Service\NotificationService;
use WPQuizStudio\Service\QuestionFeedback;
use WPQuizStudio\Service\QuizScorer;
use WPQuizStudio\Service\Scheduler;

/** Coordinates Quiz Atelier services and their dependencies. */
final class Plugin
{
    public static function activate(): void
    {
        (new Installer())->install();
        (new Capabilities())->install();
        (new Scheduler())->schedule();
        (new Player())->rewrite();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        (new Scheduler())->clear();
        flush_rewrite_rules();
    }

    public function boot(): void
    {
        load_plugin_textdomain('wp-quiz-studio', false, dirname(plugin_basename(WPQS_FILE)) . '/languages');

        $installer = new Installer();
        $installer->maybeInstall();
        (new Capabilities())->maybeInstall();

        $organizations = new OrganizationRepository();
        $access = new AccessManager($organizations);
        $activity = new ActivityLogRepository();
        $invitations = new InvitationRepository($organizations);
        $templates = new TemplateRepository();
        $reviews = new ReviewRepository();
        $notifications = new NotificationService($organizations);
        $quizRepository = new QuizRepository($access);
        $categoryRepository = new CategoryRepository($access);
        $analyticsRepository = new AnalyticsRepository();
        $revisionRepository = new RevisionRepository();
        $questionBankRepository = new QuestionBankRepository($access);
        $scheduler = new Scheduler();
        $embedPolicy = new EmbedPolicy();
        (new LoginSecurity())->register();

        (new Menu($access))->register();
        (new Settings($access, $embedPolicy))->register();
        (new LegacyPluginsNotice())->register();
        (new Routes(
            $quizRepository,
            $categoryRepository,
            $analyticsRepository,
            $revisionRepository,
            $questionBankRepository,
            new QuizScorer(),
            new QuestionFeedback(),
            new RateLimiter(),
            $access,
            $organizations,
            $invitations,
            $templates,
            $activity,
            $reviews,
            $notifications
        ))->register();
        (new Player($quizRepository, $embedPolicy))->register();
        (new Studio($access))->register();
        (new Directory($quizRepository, $categoryRepository))->register();
        (new Invitation($invitations, $activity, $access))->register();
        $scheduler->register();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class DashboardFeatureTest extends TestCase
{
    public function testDashboardControllerExposesLatestViewableSentNewsletter(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString("'latest_sent_newsletter' => " . '$latestSentNewsletter', $controller);
        $this->assertStringContainsString("'dead_mail_count' => " . '$deadMailCount', $controller);
        $this->assertStringContainsString('Newsletter::STATUS_SENT', $controller);
        $this->assertStringContainsString('countDeadLetters()', $controller);
        $this->assertStringContainsString("->orderBy('sent_at', 'desc')", $controller);
        $this->assertStringContainsString("->with(['project', 'recipientSources'])", $controller);
    }

    public function testDashboardTemplateContainsStructuredSectionsAndCommunicationAnchors(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);

        $this->assertStringContainsString('class="dashboard-shell"', $template);
        $this->assertStringContainsString('Schnellzugriff', $template);
        $this->assertStringContainsString('Projektkontext', $template);
        $this->assertStringContainsString('Kommunikation', $template);
        $this->assertStringContainsString('dashboard-action-grid', $template);
        $this->assertStringContainsString('dashboard-context-grid', $template);
        $this->assertStringContainsString('dashboard-communication-grid', $template);

        $this->assertStringContainsString('Zuletzt versendeter Newsletter', $template);
        $this->assertStringContainsString('Mail-Queue', $template);
        $this->assertStringContainsString('href="/admin/mail-queue"', $template);
        $this->assertStringContainsString(
            'data-newsletter-modal-url="/newsletters/{{ latest_sent_newsletter.id }}/preview?modal=1"',
            $template
        );
        $this->assertStringContainsString('id="newsletterActionModal"', $template);
        $this->assertStringContainsString('<script src="/js/newsletters.js"></script>', $template);
    }

    public function testDashboardTemplateContainsPermissionAndEmptyStateGuards(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);

        $this->assertStringContainsString('{% if session.can_manage_attendance or session.can_manage_users %}', $template);
        $this->assertStringContainsString(
            '{% set _finance_perm = session.can_read_finances or session.can_manage_finances'
                . ' or session.can_manage_users %}',
            $template
        );
        $this->assertStringContainsString(
            '{% set _finance_panel_visible = settings.modules.finance and _finance_perm %}',
            $template
        );
        $this->assertStringContainsString('{% if _finance_panel_visible %}', $template);
        $this->assertStringContainsString('{% if session.can_manage_users %}', $template);
        $this->assertStringContainsString('{% if session.can_manage_tasks and current_project %}', $template);
        $this->assertStringContainsString('{% if session.can_manage_tasks and upcoming_project %}', $template);
        $this->assertStringContainsString('{% if dead_mail_count is not null %}', $template);
        $this->assertStringContainsString('Keine projektbezogenen Aufgabenbereiche verfügbar.', $template);
        $this->assertStringContainsString('Aktuell stehen für dich keine Kommunikationskarten bereit.', $template);
    }

    public function testDashboardControllerGatesProjectContextQueriesBehindTaskPermission(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString('if ($tasksModuleEnabled && $canManageTasks) {', $controller);
        $this->assertStringContainsString("(bool) (\$this->settings['modules']['tasks'] ?? false)", $controller);
    }

    public function testDashboardControllerOmitsUnusedSessionDataFromViewModel(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringNotContainsString("'can_manage_users' =>", $controller);
        $this->assertStringNotContainsString("'can_manage_attendance' =>", $controller);
        $this->assertStringNotContainsString("'role_level' =>", $controller);
        $this->assertStringNotContainsString("'voice_group_ids' =>", $controller);
    }

    public function testNewsletterScopingUsesPermissionInsteadOfHardcodedRoleName(): void
    {
        $dashboardController = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');
        $newsletterController = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($dashboardController);
        $this->assertIsString($newsletterController);

        $this->assertStringNotContainsString("'Admin'", $dashboardController);
        $this->assertStringNotContainsString("where('name', 'Admin')", $newsletterController);
        $this->assertStringContainsString("\$_SESSION['can_manage_users']", $newsletterController);
    }

    public function testDashboardTemplateShowsEvaluationsCardForAllUsers(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('href="/evaluations"', $template);

        $matched = preg_match(
            '/\{% if session\.can_manage_users %\}(.*?)\{% endif %\}/s',
            $template,
            $matches
        );
        $this->assertSame(1, $matched, 'Admin-only block missing in dashboard template.');
        $this->assertStringNotContainsString('href="/evaluations"', $matches[1]);
    }

    public function testDashboardTemplateUsesNeutralCommunicationEmptyState(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);
        $this->assertStringNotContainsString('Sobald Newsletter-Rechte oder Mail-Queue-Zugriff', $template);
        $this->assertStringNotContainsString('Keine Schnellzugriffe verfügbar', $template);
    }
}

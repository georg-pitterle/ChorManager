<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class MailQueueFeatureTest extends TestCase
{
    public function testMailQueueCoreStructureExists(): void
    {
        $this->assertTrue(class_exists(\App\Models\MailQueue::class));
        $this->assertTrue(class_exists(\App\Services\MailQueueService::class));
        $this->assertTrue(class_exists(\App\Services\MailDeliveryService::class));
        $this->assertTrue(class_exists(\App\Services\MailQueueAdminService::class));
        $this->assertTrue(class_exists(\App\Controllers\MailQueueController::class));
    }

    public function testMailQueueAdminRoutesUseAdminPrefix(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString("'/admin/mail-queue'", $routes);
        $this->assertStringContainsString("'/admin/mail-queue/{id:[0-9]+}'", $routes);
        $this->assertStringContainsString("'/admin/mail-queue/retry-all-dead'", $routes);
    }

    public function testMailQueueTemplatesAreLocalizedInGerman(): void
    {
        $indexTemplate = file_get_contents(dirname(__DIR__) . '/../templates/admin/mail_queue/index.twig');
        $showTemplate = file_get_contents(dirname(__DIR__) . '/../templates/admin/mail_queue/show.twig');

        $this->assertIsString($indexTemplate);
        $this->assertIsString($showTemplate);

        $this->assertStringContainsString('Mailversand-Verwaltung', $indexTemplate);
        $this->assertStringContainsString('In Warteschlange', $indexTemplate);
        $this->assertStringContainsString('Wird gesendet', $indexTemplate);
        $this->assertStringContainsString('Versendet', $indexTemplate);
        $this->assertStringContainsString('Fehlgeschlagen', $indexTemplate);
        $this->assertStringContainsString('Endgültig fehlgeschlagen', $indexTemplate);
        $this->assertStringContainsString('Empfänger', $indexTemplate);
        $this->assertStringContainsString('Nächster Versuch', $indexTemplate);
        $this->assertStringContainsString('Keine Einträge gefunden.', $indexTemplate);
        $this->assertStringNotContainsString('Retry All Dead Entries', $indexTemplate);
        $this->assertStringNotContainsString('Previous', $indexTemplate);
        $this->assertStringNotContainsString('Next', $indexTemplate);

        $this->assertStringContainsString('Mail-Queue-Eintrag #{{ entry.id }}', $showTemplate);
        $this->assertStringContainsString('Fehlerdetails', $showTemplate);
        $this->assertStringContainsString('E-Mail-Inhalt', $showTemplate);
        $this->assertStringContainsString('Zur Übersicht', $showTemplate);
        $this->assertStringNotContainsString('Mail Queue Entry', $showTemplate);
        $this->assertStringNotContainsString('Error Details', $showTemplate);
        $this->assertStringNotContainsString('Back to List', $showTemplate);
    }

    public function testMailQueueTemplatesRenderStatusViaGermanLabelMap(): void
    {
        $indexTemplate = file_get_contents(dirname(__DIR__) . '/../templates/admin/mail_queue/index.twig');
        $showTemplate = file_get_contents(dirname(__DIR__) . '/../templates/admin/mail_queue/show.twig');

        $this->assertIsString($indexTemplate);
        $this->assertIsString($showTemplate);

        $this->assertStringContainsString('status_labels', $indexTemplate);
        $this->assertStringContainsString('status_badges', $indexTemplate);
        $this->assertStringContainsString("status_labels[entry.status]|default(entry.status|default('Unbekannt'))", $indexTemplate);
        $this->assertStringContainsString("status_badges[entry.status]|default('bg-dark')", $indexTemplate);
        $this->assertStringContainsString("entry.recipient_email ?: '—'", $indexTemplate);

        $this->assertStringContainsString('status_labels', $showTemplate);
        $this->assertStringContainsString('status_badges', $showTemplate);
        $this->assertStringContainsString("status_labels[entry.status]|default(entry.status|default('Unbekannt'))", $showTemplate);
    }

    public function testMailQueueIndexUsesTableEngineInsteadOfManualPagination(): void
    {
        $indexTemplate = file_get_contents(dirname(__DIR__) . '/../templates/admin/mail_queue/index.twig');
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/MailQueueController.php');

        $this->assertIsString($indexTemplate);
        $this->assertIsString($controller);
        $this->assertStringContainsString('data-table-engine="true"', $indexTemplate);
        $this->assertStringContainsString('table-shell', $indexTemplate);
        $this->assertStringContainsString('partials/table_toolbar.twig', $indexTemplate);
        $this->assertStringContainsString('table-responsive-cards', $indexTemplate);
        $this->assertStringContainsString('data-table-id="mail-queue.index"', $indexTemplate);
        $this->assertStringNotContainsString('entries.pagination', $indexTemplate);
        $this->assertStringNotContainsString('<ul class="pagination">', $indexTemplate);
        $this->assertStringNotContainsString('perPage: 50', $controller);
    }

    public function testMailQueueIndexUsesTableEngineFilterPlugin(): void
    {
        $indexTemplate = file_get_contents(dirname(__DIR__) . '/../templates/admin/mail_queue/index.twig');
        $pluginScript = file_get_contents(dirname(__DIR__) . '/../public/js/table-plugins/mail-queue-plugin.js');

        $this->assertIsString($indexTemplate);
        $this->assertIsString($pluginScript);
        $this->assertStringContainsString('data-table-plugins="mailQueueFilters"', $indexTemplate);
        $this->assertStringContainsString('<script src="/js/table-plugins/mail-queue-plugin.js"></script>', $indexTemplate);
        $this->assertStringContainsString('registerFilterPlugin(\'mailQueueFilters\'', $pluginScript);
        $this->assertStringContainsString('Status', $pluginScript);
        $this->assertStringContainsString('Typ', $pluginScript);
        $this->assertStringNotContainsString('<form method="get" class="mb-3">', $indexTemplate);
    }

    public function testMailQueueOverviewUsesAlignedStatsLayout(): void
    {
        $indexTemplate = file_get_contents(dirname(__DIR__) . '/../templates/admin/mail_queue/index.twig');
        $styleContent = file_get_contents(dirname(__DIR__) . '/../public/css/style.css');

        $this->assertIsString($indexTemplate);
        $this->assertIsString($styleContent);
        $this->assertStringContainsString('mail-queue-page-header', $indexTemplate);
        $this->assertStringContainsString('mail-queue-overview-grid', $indexTemplate);
        $this->assertStringContainsString('mail-queue-stat-card', $indexTemplate);
        $this->assertStringContainsString('mail-queue-overview-actions', $indexTemplate);
        $this->assertStringContainsString('.mail-queue-overview-grid', $styleContent);
        $this->assertStringContainsString('.mail-queue-stat-card', $styleContent);
        $this->assertStringContainsString('.mail-queue-overview-actions', $styleContent);
    }

    public function testMailQueueAdminServiceDoesNotRequireIlluminatePaginatorPackage(): void
    {
        $service = file_get_contents(dirname(__DIR__) . '/../src/Services/MailQueueAdminService.php');

        $this->assertIsString($service);
        $this->assertStringNotContainsString('->paginate(', $service);
        $this->assertStringContainsString('public function listEntries(array $filters = [])', $service);
        $this->assertStringContainsString('->orderByDesc(\'created_at\')', $service);
        $this->assertStringContainsString('->get();', $service);
    }

    public function testRoleManagementIncludesMailQueuePermission(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/RoleController.php');
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');

        $this->assertIsString($controller);
        $this->assertIsString($template);
        $this->assertStringContainsString("'can_manage_mail_queue'", $controller);
        $this->assertStringContainsString('name="can_manage_mail_queue"', $template);
        $this->assertStringContainsString('Mailversand verwalten', $template);
    }

    public function testMailQueueCommandAndSeedSettingsExist(): void
    {
        $command = file_get_contents(dirname(__DIR__) . '/../src/Commands/ProcessMailQueueCommand.php');
        $runner = file_get_contents(dirname(__DIR__) . '/../bin/process_mail_queue.php');
        $seedService = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($command);
        $this->assertIsString($runner);
        $this->assertIsString($seedService);
        $this->assertStringContainsString('mail:process-queue', $command);
        $this->assertStringContainsString("'mailqueue_batch_size'", $command);
        $this->assertStringNotContainsString('writeln(', $command);
        $this->assertStringContainsString('addCommand', $runner);
        $this->assertStringContainsString('setDefaultCommand', $runner);
        $this->assertStringContainsString("'mailqueue_trigger_mode' => 'hybrid'", $seedService);
        $this->assertStringContainsString("'mailqueue_opportunistic_rate_limit' => '10'", $seedService);
        $this->assertStringContainsString("'mailqueue_batch_size' => '50'", $seedService);
        $this->assertStringContainsString("'mail_queue' => 0", $seedService);
        $this->assertStringContainsString('private function seedMailQueue', $seedService);
    }

    public function testDockerImageStartsMailQueueWorkerEveryTwentySeconds(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__) . '/../Dockerfile');
        $entrypoint = file_get_contents(dirname(__DIR__) . '/../entrypoint.sh');
        $worker = file_get_contents(dirname(__DIR__) . '/../bin/mail-queue-worker.sh');

        $this->assertIsString($dockerfile);
        $this->assertIsString($entrypoint);
        $this->assertIsString($worker);
        $this->assertStringContainsString('mail-queue-worker.sh', $dockerfile);
        $this->assertStringContainsString('MAIL_QUEUE_WORKER_INTERVAL', $entrypoint);
        $this->assertStringContainsString('/usr/local/bin/mail-queue-worker.sh &', $entrypoint);
        $this->assertStringContainsString('mail_queue_worker_pid', $entrypoint);
        $this->assertStringContainsString('MAIL_QUEUE_WORKER_INTERVAL', $worker);
        $this->assertStringContainsString('sleep "${MAIL_QUEUE_WORKER_INTERVAL}"', $worker);
        $this->assertStringContainsString('php bin/process_mail_queue.php', $worker);
    }

    public function testMailDeliveryServiceContainsRetryAndDeadLetterHandling(): void
    {
        $service = file_get_contents(dirname(__DIR__) . '/../src/Services/MailDeliveryService.php');

        $this->assertIsString($service);
        $this->assertStringContainsString('processDueEntries', $service);
        $this->assertStringContainsString('MailQueue::dueSoon()', $service);
        $this->assertStringContainsString("'status' => 'failed'", $service);
        $this->assertStringContainsString("'status' => 'dead'", $service);
        $this->assertStringContainsString('classifyError', $service);
        $this->assertStringContainsString('Carbon::now()->addSeconds', $service);
    }

    public function testMailDeliveryServiceSyncsNewsletterRecipients(): void
    {
        $service = file_get_contents(dirname(__DIR__) . '/../src/Services/MailDeliveryService.php');

        $this->assertIsString($service);
        $this->assertStringContainsString('syncNewsletterRecipient', $service);
        $this->assertStringContainsString('$payload[\'recipient_id\']', $service);
        $this->assertStringContainsString('\\App\\Models\\NewsletterRecipient::where(\'id\', $payload[\'recipient_id\'])', $service);
        $this->assertStringContainsString('->update([\'status\' => $status]);', $service);
    }

    public function testMailQueueControllerUsesTwigInjectionInsteadOfGlobalViewLookup(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/MailQueueController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString('use Slim\\Views\\Twig;', $controller);
        $this->assertStringContainsString('private Twig $view;', $controller);
        $this->assertStringContainsString('public function __construct(Twig $view, MailQueueAdminService $adminService)', $controller);
        $this->assertStringNotContainsString("get('view')", $controller);
        $this->assertStringNotContainsString('global $container', $controller);
    }

    public function testMailQueueIsProcessedOpportunisticallyViaMiddleware(): void
    {
        $middleware = file_get_contents(dirname(__DIR__) . '/../src/Middleware/MailQueueProcessingMiddleware.php');
        $pipeline = file_get_contents(dirname(__DIR__) . '/../src/Middleware.php');

        $this->assertIsString($middleware);
        $this->assertIsString($pipeline);
        $this->assertStringContainsString('mailqueue_trigger_mode', $middleware);
        $this->assertStringContainsString('mailqueue_opportunistic_rate_limit', $middleware);
        $this->assertStringContainsString('mailqueue_batch_size', $middleware);
        $this->assertStringContainsString('mailqueue_last_opportunistic_run_at', $middleware);
        $this->assertStringContainsString('processDueEntries($batchSize)', $middleware);
        $this->assertStringContainsString('$app->add(MailQueueProcessingMiddleware::class);', $pipeline);
    }
}

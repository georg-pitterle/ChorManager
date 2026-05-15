<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;


class NewsletterFeatureTest extends TestCase
{
    /**
     * Test that services are correctly instantiated
     */
    public function testServicesCanBeInstantiated(): void
    {
        $this->assertTrue(class_exists(\App\Services\NewsletterService::class));
        $this->assertTrue(class_exists(\App\Services\NewsletterLockingService::class));
        $this->assertTrue(class_exists(\App\Services\NewsletterRecipientService::class));
    }

    /**
     * Test that models exist
     */
    public function testModelsExist(): void
    {
        $this->assertTrue(class_exists(\App\Models\Newsletter::class));
        $this->assertTrue(class_exists(\App\Models\NewsletterTemplate::class));
        $this->assertTrue(class_exists(\App\Models\NewsletterArchive::class));
        $this->assertTrue(class_exists(\App\Models\NewsletterRecipient::class));
    }

    /**
     * Test that migration exists
     */
    public function testMigrationExists(): void
    {
        $this->assertTrue(
            file_exists(
                dirname(__DIR__) . '/../db/migrations/20260314130000_initial.php'
            )
        );
    }

    /**
     * Test that controller exists
     */
    public function testControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\NewsletterController::class));
    }

    /**
     * Test that middleware exists
     */
    public function testAuthMiddlewareExists(): void
    {
        $this->assertTrue(class_exists(\App\Middleware\NewsletterAuthMiddleware::class));
    }

    /**
     * Test that templates directory exists
     */
    public function testTemplatesDirectoryExists(): void
    {
        $this->assertTrue(is_dir(dirname(__DIR__) . '/../templates/newsletters'));
    }

    /**
     * Test all required template files exist
     */
    public function testAllTemplateFilesExist(): void
    {
        $templates = [
            'index.twig',
            'archive.twig',
            'create.twig',
            'edit.twig',
            'preview.twig',
            'locked.twig',
            'templates_index.twig',
            'templates_edit.twig',
        ];

        foreach ($templates as $template) {
            $path = dirname(__DIR__) . '/../templates/newsletters/' . $template;
            $this->assertTrue(
                file_exists($path),
                "Template file missing: $path"
            );
        }

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/layout_modal.twig'));
    }

    /**
     * Test Newsletter model has required methods
     */
    public function testNewsletterModelHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(\App\Models\Newsletter::class, 'isDraft'));
        $this->assertTrue(method_exists(\App\Models\Newsletter::class, 'isSent'));
        $this->assertTrue(method_exists(\App\Models\Newsletter::class, 'isLocked'));
        $this->assertTrue(method_exists(\App\Models\Newsletter::class, 'project'));
        $this->assertTrue(method_exists(\App\Models\Newsletter::class, 'createdBy'));
        $this->assertSame('draft', \App\Models\Newsletter::STATUS_DRAFT);
        $this->assertSame('sent', \App\Models\Newsletter::STATUS_SENT);
        $this->assertSame(['draft', 'sent'], \App\Models\Newsletter::SUPPORTED_STATUSES);
    }

    /**
     * Test NewsletterLockingService has required methods
     */
    public function testLockingServiceHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(\App\Services\NewsletterLockingService::class, 'acquireLock'));
        $this->assertTrue(method_exists(\App\Services\NewsletterLockingService::class, 'releaseLock'));
        $this->assertTrue(method_exists(\App\Services\NewsletterLockingService::class, 'canEdit'));
        $this->assertTrue(method_exists(\App\Services\NewsletterLockingService::class, 'isLockedBy'));
        $this->assertTrue(method_exists(\App\Services\NewsletterLockingService::class, 'isLockedByOther'));
        $this->assertTrue(method_exists(\App\Services\NewsletterLockingService::class, 'getLockInfo'));
    }

    /**
     * Test NewsletterService has required methods
     */
    public function testNewsletterServiceHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(\App\Services\NewsletterService::class, 'send'));
        $this->assertTrue(method_exists(\App\Services\NewsletterService::class, 'validateForSending'));
    }

    /**
     * Test NewsletterRecipientService has required methods
     */
    public function testRecipientServiceHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(\App\Services\NewsletterRecipientService::class, 'resolveRecipients'));
        $this->assertTrue(method_exists(\App\Services\NewsletterRecipientService::class, 'getProjectMembers'));
        $this->assertTrue(method_exists(\App\Services\NewsletterRecipientService::class, 'getRecipients'));
        $this->assertTrue(method_exists(\App\Services\NewsletterRecipientService::class, 'setRecipients'));
    }

    /**
     * Test NewsletterController has required actions
     */
    public function testControllerHasRequiredActions(): void
    {
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'archive'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'store'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'edit'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'preview'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'send'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'saveAsTemplate'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'getTemplate'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'checkLock'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'deleteDraft'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'listTemplates'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'createTemplate'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'editTemplate'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'updateTemplate'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'cloneTemplate'));
    }

    /**
     * Test newsletter index only exposes supported statuses
     */
    public function testNewsletterArchiveTemplateExistsAndMentionsMeineNewsletter(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/archive.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('Meine Newsletter', $template);
        $this->assertStringContainsString('an dich versendet', $template);
    }

    /**
     * Test newsletter templates use modal actions and robust project filter submit
     */
    public function testNewsletterTemplatesUseModalActionsAndProjectFilterSubmit(): void
    {
        $indexTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/index.twig');
        $archiveTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/archive.twig');
        $createTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/create.twig');
        $editTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/edit.twig');
        $previewTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/preview.twig');
        $lockedTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/locked.twig');
        $scriptContent = file_get_contents(dirname(__DIR__) . '/../public/js/newsletters.js');
        $editScriptContent = file_get_contents(dirname(__DIR__) . '/../public/js/newsletters-edit.js');
        $createScriptContent = file_get_contents(dirname(__DIR__) . '/../public/js/newsletters-create.js');
        $tinymceInitContent = file_get_contents(dirname(__DIR__) . '/../public/js/tinymce-init.js');
        $styleContent = file_get_contents(dirname(__DIR__) . '/../public/css/style.css');

        $this->assertIsString($indexTemplate);
        $this->assertIsString($archiveTemplate);
        $this->assertIsString($createTemplate);
        $this->assertIsString($editTemplate);
        $this->assertIsString($previewTemplate);
        $this->assertIsString($lockedTemplate);
        $this->assertIsString($scriptContent);
        $this->assertIsString($editScriptContent);
        $this->assertIsString($createScriptContent);
        $this->assertIsString($tinymceInitContent);
        $this->assertIsString($styleContent);

        $this->assertStringContainsString('action="/newsletters"', $indexTemplate);
        $this->assertStringContainsString('class="form-select onchange-submit"', $indexTemplate);
        $this->assertStringContainsString('data-newsletter-modal-url="/newsletters/create?project_id={{ project.id }}&modal=1"', $indexTemplate);
        $this->assertStringContainsString('data-newsletter-modal-url="/newsletters/{{ newsletter.id }}/edit?project_id={{ project.id }}&modal=1"', $indexTemplate);
        $this->assertStringContainsString('data-newsletter-modal-url="/newsletters/{{ newsletter.id }}/preview?modal=1"', $indexTemplate);
        $this->assertStringContainsString('dropdown-toggle-split', $indexTemplate);
        $this->assertStringContainsString('action="/newsletters/{{ newsletter.id }}/send"', $indexTemplate);
        $this->assertStringContainsString('action="/newsletters/{{ newsletter.id }}/delete"', $indexTemplate);
        $this->assertStringContainsString('data-confirm="Diesen Newsletter-Entwurf wirklich löschen?"', $indexTemplate);
        $this->assertStringContainsString('{% if success %}', $indexTemplate);
        $this->assertStringContainsString('{% if error %}', $indexTemplate);
        $this->assertStringContainsString('id="newsletterActionModal"', $indexTemplate);
        $this->assertStringContainsString('<script src="/js/newsletters.js"></script>', $indexTemplate);

        $this->assertStringContainsString('data-newsletter-modal-url="/newsletters/{{ newsletter.id }}/preview?modal=1"', $archiveTemplate);
        $this->assertStringContainsString('id="newsletterActionModal"', $archiveTemplate);
        $this->assertStringContainsString('<script src="/js/newsletters.js"></script>', $archiveTemplate);

        $this->assertStringContainsString("{% extends is_modal|default(false) ? 'layout_modal.twig' : 'layout.twig' %}", $createTemplate);
        $this->assertStringContainsString("{% extends is_modal|default(false) ? 'layout_modal.twig' : 'layout.twig' %}", $editTemplate);
        $this->assertStringContainsString("{% extends is_modal|default(false) ? 'layout_modal.twig' : 'layout.twig' %}", $previewTemplate);
        $this->assertStringContainsString("{% extends is_modal|default(false) ? 'layout_modal.twig' : 'layout.twig' %}", $lockedTemplate);
        $this->assertStringContainsString("<script src=\"{{ asset_path('/js/newsletters-create.js') }}\"></script>", $createTemplate);
        $this->assertStringContainsString("<script src=\"{{ asset_path('/js/newsletters-edit.js') }}\"></script>", $editTemplate);
        $this->assertStringContainsString('action="/newsletters/{{ newsletter.id }}/delete"', $editTemplate);
        $this->assertStringContainsString('<script src="/js/newsletters-locked.js"></script>', $lockedTemplate);
        $this->assertStringNotContainsString('onclick=', $lockedTemplate);

        $this->assertStringContainsString('data-newsletter-modal-url', $scriptContent);
        $this->assertStringContainsString('newsletterActionModal', $scriptContent);
        $this->assertStringContainsString('window.location.reload()', $scriptContent);
        $this->assertStringContainsString('cleanupTinymceInModal();', $scriptContent);
        $this->assertStringContainsString("target.id === 'content_html'", $scriptContent);
        $this->assertStringContainsString('tinymce.EditorManager.get', $scriptContent);

        $this->assertStringContainsString('payload.redirect', $editScriptContent);
        $this->assertStringContainsString('window.location.href = payload.redirect;', $editScriptContent);

        $this->assertStringContainsString('const textarea = document.getElementById("content_html")', $createScriptContent);
        $this->assertStringContainsString('textarea.value = data.content_html || "";', $createScriptContent);
        $this->assertStringContainsString('existingEditor.targetElm === textarea', $tinymceInitContent);
        $this->assertStringContainsString('existingEditor.remove();', $tinymceInitContent);
        $this->assertStringContainsString('#newsletterActionModal', $styleContent);
        $this->assertStringContainsString('1200px', $styleContent);
        $this->assertStringContainsString('--newsletter-modal-edge-gap', $styleContent);
    }

    /**
     * Test send flow enqueues mails and marks status as SENT
     */
    public function testSendFlowEnqueuesMails(): void
    {
        $serviceContent = file_get_contents(dirname(__DIR__) . '/../src/Services/NewsletterService.php');

        $this->assertIsString($serviceContent);
        $this->assertStringContainsString('mailQueueService->enqueueNewsletterMail(', $serviceContent);
        $this->assertStringContainsString("'status' => Newsletter::STATUS_SENT", $serviceContent);
        $this->assertStringContainsString("'status' => 'queued'", $serviceContent);
    }

    public function testMigrationAddsQueuedNewsletterRecipientStatusForQueueWorkflow(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260419223000_add_queued_status_to_newsletter_recipients.php';

        $this->assertFileExists($migrationPath);

        $migrationContent = file_get_contents($migrationPath);
        $this->assertIsString($migrationContent);
        $this->assertStringContainsString("enum('pending','queued','sent','failed')", $migrationContent);
        $this->assertStringContainsString('ALTER TABLE newsletter_recipients', $migrationContent);
    }

    /**
     * Test newsletter schema does not expose removed legacy statuses
     */
    public function testNewsletterMigrationOmitsLegacyStatuses(): void
    {
        $migrationContent = file_get_contents(dirname(__DIR__) . '/../db/migrations/20260314130000_initial.php');

        $this->assertIsString($migrationContent);
        $this->assertStringContainsString("status enum('draft','sent')", $migrationContent);
        $this->assertStringNotContainsString("scheduled', 'sent', 'archived", $migrationContent);
    }

    public function testRecipientServiceUsesAttendanceStatusFieldForEventAttendees(): void
    {
        $recipientService = file_get_contents(dirname(__DIR__) . '/../src/Services/NewsletterRecipientService.php');

        $this->assertIsString($recipientService);
        $this->assertStringContainsString("->where('status', 'present')", $recipientService);
        $this->assertStringNotContainsString("->where('attended', 1)", $recipientService);
    }

    public function testRecipientServiceReturnsNewsletterRecipientModelsWithLoadedUserForSending(): void
    {
        $recipientService = file_get_contents(dirname(__DIR__) . '/../src/Services/NewsletterRecipientService.php');

        $this->assertIsString($recipientService);
        $this->assertStringContainsString('NewsletterRecipient::query()', $recipientService);
        $this->assertStringContainsString("->with('user')", $recipientService);
        $this->assertStringContainsString("->where('newsletter_id', \$newsletterId)", $recipientService);
        $this->assertStringNotContainsString("User::query()\n            ->whereHas('newsletterRecipients'", $recipientService);
    }

    public function testGlobalTimezoneConfigurationIsWiredForAppDbAndTwig(): void
    {
        $timezoneUtil = file_get_contents(dirname(__DIR__) . '/../src/Util/Timezone.php');
        $settings = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');
        $bootstrap = file_get_contents(dirname(__DIR__) . '/../public/index.php');
        $dependencies = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');

        $this->assertIsString($timezoneUtil);
        $this->assertIsString($settings);
        $this->assertIsString($bootstrap);
        $this->assertIsString($dependencies);

        $this->assertStringContainsString('class Timezone', $timezoneUtil);
        $this->assertStringContainsString('resolveAppTimezone', $timezoneUtil);
        $this->assertStringContainsString('resolveDatabaseTimezoneOffset', $timezoneUtil);

        $this->assertStringContainsString("'timezone' => \$appTimezone", $settings);
        $this->assertStringContainsString("'timezone' => Timezone::resolveDatabaseTimezoneOffset()", $settings);
        $this->assertStringContainsString('date_default_timezone_set(Timezone::resolveAppTimezone());', $bootstrap);
        $this->assertStringContainsString('setTimezone($appTimezone);', $dependencies);
    }

    /**
     * Test that routes are registered
     */
    public function testRoutesAreRegistered(): void
    {
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../src/Routes.php'));
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertStringContainsString('newsletter', strtolower($routesContent));
        $this->assertStringContainsString('/newsletters/archive', $routesContent);
        $this->assertStringContainsString('/newsletters/template/{id:[0-9]+}', $routesContent);
        $this->assertStringContainsString('/newsletters/{id:[0-9]+}/check-lock', $routesContent);
        $this->assertStringContainsString('/newsletters/{id:[0-9]+}/delete', $routesContent);
    }

    public function testArchiveAndPreviewRoutesAreExposedOutsideNewsletterManagementGroup(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString(
            "\$group->get('/newsletters/archive', [NewsletterController::class, 'archive']);",
            $routesContent
        );
        $this->assertStringContainsString(
            "\$group->get('/newsletters/{id:[0-9]+}/preview', [NewsletterController::class, 'preview']);",
            $routesContent
        );
    }

    public function testPreviewAccessAllowsReceivedNewsletterArchiveEntries(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('private function canAccessReceivedNewsletterById', $controllerContent);
        $this->assertStringContainsString('NewsletterArchive::query()', $controllerContent);
        $this->assertStringContainsString("->where('newsletter_id', \$newsletterId)", $controllerContent);
        $this->assertStringContainsString("->where('user_id', (int) \$userId)", $controllerContent);
        $this->assertStringContainsString('&& !$this->canAccessReceivedNewsletterById($id, $userId)', $controllerContent);
    }

    public function testMailerHasIsMailSendDisabledMethod(): void
    {
        $this->assertTrue(method_exists(\App\Services\Mailer::class, 'isMailSendDisabled'));
    }

    public function testMailerSkipsSendWhenDisabled(): void
    {
        $mailerContent = file_get_contents(dirname(__DIR__) . '/../src/Services/Mailer.php');
        $this->assertIsString($mailerContent);
        $this->assertStringContainsString('isMailSendDisabled', $mailerContent);
        $this->assertStringContainsString('DISABLE_MAIL_SEND', $mailerContent);
        $this->assertStringContainsString("'success' => true", $mailerContent);
        $this->assertStringContainsString("'skipped' => true", $mailerContent);
    }

    public function testNewsletterSendReturnsRecipientCount(): void
    {
        $serviceContent = file_get_contents(dirname(__DIR__) . '/../src/Services/NewsletterService.php');
        $this->assertIsString($serviceContent);
        // Return type must be int
        $this->assertStringContainsString('public function send(Newsletter $newsletter, int $userId): int', $serviceContent);
        $this->assertStringContainsString('return $sentCount;', $serviceContent);
    }

    public function testNewsletterControllerUsesDisabledFlagForFlashMessage(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('DISABLE_MAIL_SEND', $controllerContent);
        $this->assertStringContainsString('EnvHelper', $controllerContent);
        $this->assertStringContainsString('Dev-Modus', $controllerContent);
        $this->assertStringContainsString('$recipientCount', $controllerContent);
        $this->assertStringContainsString("if (\$expectsJson)", $controllerContent);
        $this->assertStringContainsString("'redirect' => \"/newsletters?project_id={\$newsletter->project_id}&status=\" . Newsletter::STATUS_SENT", $controllerContent);
    }

    public function testDeleteDraftCleansRecipientsAndHandlesLockConflicts(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("NewsletterRecipient::where('newsletter_id', \$newsletter->id)->delete();", $controllerContent);
        $this->assertStringContainsString('wird gerade von einer anderen Person bearbeitet', $controllerContent);
    }

    public function testNewsletterCreateAndEditOrderEventsByStartsAt(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("->orderBy('starts_at', 'desc')", $controllerContent);
        $this->assertStringNotContainsString("->orderBy('event_date', 'desc')", $controllerContent);
    }

    public function testSendActionAllowsUnockedDraftAndHandlesListFlow(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("if (!\$newsletter->isLocked()) {", $controllerContent);
        $this->assertStringContainsString("\$this->lockingService->acquireLock(\$newsletter, \$userId);", $controllerContent);
        $this->assertStringContainsString('Newsletter wird gerade von einer anderen Person bearbeitet und kann derzeit nicht versendet werden.', $controllerContent);
    }

    public function testNewsletterIndexIncludesFlashDataInSentStatusRender(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("if (\$status === Newsletter::STATUS_SENT)", $controllerContent);
        $this->assertStringContainsString("'success' => \$success", $controllerContent);
        $this->assertStringContainsString("'error' => \$error", $controllerContent);
    }

    public function testEnvExampleDocumentsDisableMailSendParameter(): void
    {
        $envExample = file_get_contents(dirname(__DIR__) . '/../.env.example');
        $this->assertIsString($envExample);
        $this->assertStringContainsString('DISABLE_MAIL_SEND=true', $envExample);
    }

    public function testNewsletterRecipientSourcesMigrationExistsAndContainsDataBackfill(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260513220000_add_newsletter_recipient_sources.php';
        $this->assertFileExists($migrationPath);

        $migrationContent = file_get_contents($migrationPath);
        $this->assertIsString($migrationContent);
        $this->assertStringContainsString('newsletter_recipient_sources', $migrationContent);
        $this->assertStringContainsString("'project_members'", $migrationContent);
        $this->assertStringContainsString("'event_attendees'", $migrationContent);
        $this->assertStringContainsString('removeColumn(\'event_id\')', $migrationContent);
        $this->assertStringContainsString('INNER JOIN newsletter_recipient_sources', $migrationContent);
    }

    public function testNewsletterModelSupportsRecipientSourcesInsteadOfLegacyEventField(): void
    {
        $modelContent = file_get_contents(dirname(__DIR__) . '/../src/Models/Newsletter.php');

        $this->assertIsString($modelContent);
        $this->assertStringContainsString('recipientSources', $modelContent);
        $this->assertStringNotContainsString("'event_id'", $modelContent);
    }

    public function testNewsletterControllerUsesRecipientSourceValidationAndPreviewEndpoint(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('validateNewsletterSourcesInput', $controllerContent);
        $this->assertStringContainsString('resolveRecipientsPreview', $controllerContent);
        $this->assertStringContainsString('recipientService->setSources', $controllerContent);
        $this->assertStringContainsString("'recipient_type'", $controllerContent);
        $this->assertStringContainsString('accessibleProjectIds', $controllerContent);
        $this->assertStringContainsString("'project_id'", $controllerContent);
        $this->assertStringContainsString('Zugriff verweigert.', $controllerContent);
    }

    public function testNewsletterRoutesExposeRecipientPreviewEndpoint(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString('/newsletters/resolve-recipients-preview', $routesContent);
    }

    public function testNewsletterTemplatesContainRecipientSourcesUi(): void
    {
        $createTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/create.twig');
        $editTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/edit.twig');
        $indexTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/index.twig');

        $this->assertIsString($createTemplate);
        $this->assertIsString($editTemplate);
        $this->assertIsString($indexTemplate);

        $this->assertStringContainsString('id="recipient-sources"', $createTemplate);
        $this->assertStringContainsString('data-source-type="project_members"', $createTemplate);
        $this->assertStringContainsString('data-source-type="event_attendees"', $createTemplate);
        $this->assertStringContainsString('data-source-type="role"', $createTemplate);
        $this->assertStringContainsString('data-source-type="user"', $createTemplate);

        $this->assertStringContainsString('id="recipient-count-badge"', $editTemplate);
        $this->assertStringContainsString('name="recipient_type"', $indexTemplate);
    }

    public function testNewsletterScriptsBuildSourcePayloadAndCallPreviewEndpoint(): void
    {
        $createScriptContent = file_get_contents(dirname(__DIR__) . '/../public/js/newsletters-create.js');
        $editScriptContent = file_get_contents(dirname(__DIR__) . '/../public/js/newsletters-edit.js');

        $this->assertIsString($createScriptContent);
        $this->assertIsString($editScriptContent);

        $this->assertStringContainsString('buildRecipientSourcesPayload', $createScriptContent);
        $this->assertStringContainsString('/newsletters/resolve-recipients-preview', $createScriptContent);
        $this->assertStringContainsString('recipient-count-badge', $createScriptContent);

        $this->assertStringContainsString('buildRecipientSourcesPayload', $editScriptContent);
        $this->assertStringContainsString('/newsletters/resolve-recipients-preview', $editScriptContent);
        $this->assertStringContainsString('recipient-count-badge', $editScriptContent);
    }

    public function testDevSeedCreatesNewsletterRecipientSourcesAndNoLegacyEventFieldUsage(): void
    {
        $seedContent = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seedContent);
        $this->assertStringContainsString('NewsletterRecipientSource::create([', $seedContent);
        $this->assertStringContainsString("'newsletter_recipient_sources'", $seedContent);
        $this->assertStringNotContainsString("'event_id' => null", $seedContent);
    }
}

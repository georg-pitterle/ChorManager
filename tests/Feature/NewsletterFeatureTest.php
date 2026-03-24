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
                dirname(__DIR__) . '/../db/migrations/20260323150000_create_newsletters.php'
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
            'create.twig',
            'edit.twig',
            'preview.twig',
            'archive.twig',
            'locked.twig',
        ];

        foreach ($templates as $template) {
            $path = dirname(__DIR__) . '/../templates/newsletters/' . $template;
            $this->assertTrue(
                file_exists($path),
                "Template file missing: $path"
            );
        }
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
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'store'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'edit'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'preview'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'send'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'archiveIndex'));
    }

    /**
     * Test that routes are registered
     */
    public function testRoutesAreRegistered(): void
    {
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../src/Routes.php'));
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertStringContainsString('newsletter', strtolower($routesContent));
    }
}

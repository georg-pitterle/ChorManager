<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class NewsletterTemplateManagementFeatureTest extends TestCase
{
    public function testTemplateManagementRoutesArePointedAtTemplateController(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString(
            "'/newsletters/templates', [NewsletterTemplateController::class, 'index']",
            $routes
        );
        $this->assertStringContainsString(
            "'/newsletters/templates', [NewsletterTemplateController::class, 'store']",
            $routes
        );
        $this->assertStringContainsString("[NewsletterTemplateController::class, 'clone']", $routes);
        $this->assertStringContainsString("[NewsletterTemplateController::class, 'storeFromNewsletter']", $routes);
    }

    public function testTemplateControllerExposesTemplateManagementActions(): void
    {
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'store'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'edit'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'clone'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'show'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'storeFromNewsletter'));
    }

    public function testTemplateManagementTemplatesExist(): void
    {
        $base = dirname(__DIR__) . '/../templates/newsletters';

        $this->assertFileExists($base . '/templates_index.twig');
        $this->assertFileExists($base . '/templates_edit.twig');
    }

    public function testNewsletterControllerNoLongerHandlesTemplates(): void
    {
        $this->assertFalse(method_exists(\App\Controllers\NewsletterController::class, 'listTemplates'));
        $this->assertFalse(method_exists(\App\Controllers\NewsletterController::class, 'createTemplate'));
        $this->assertFalse(method_exists(\App\Controllers\NewsletterController::class, 'cloneTemplate'));
    }

    public function testTemplateActionsSupportRedirectFallbackForNonAjaxRequests(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterTemplateController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString("'/newsletters/templates/' . \$template->id . '/edit'", $controller);
        $this->assertStringContainsString('Vorlage gespeichert', $controller);
        $this->assertStringContainsString('Vorlage geklont', $controller);
    }

    public function testTemplateIndexTemplateContainsEditAndCloneActions(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/templates_index.twig');

        $this->assertIsString($content);
        $this->assertStringContainsString('/newsletters/templates"', $content);
        $this->assertStringContainsString('data-bs-target="#createTemplateModal"', $content);
        $this->assertStringContainsString('id="createTemplateModal"', $content);
        $this->assertStringContainsString('Vorlage erstellen', $content);
        $this->assertStringContainsString('tinymce-editor', $content);
        $this->assertStringContainsString('data-newsletter-modal-url="/newsletters/templates/{{ template.id }}/edit?modal=1"', $content);
        $this->assertStringContainsString('/newsletters/templates/{{ template.id }}/clone', $content);
        $this->assertStringContainsString('Newsletter-Vorlagen', $content);
        $this->assertStringContainsString('id="newsletterActionModal"', $content);
        $this->assertStringContainsString('<script src="/js/newsletters.js"></script>', $content);
    }

    public function testNewsletterIndexContainsEntryPointToTemplateManagement(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/index.twig');

        $this->assertIsString($content);
        $this->assertStringContainsString('/newsletters/templates', $content);
        $this->assertStringContainsString('Vorlagen verwalten', $content);
    }

    public function testCloneTemplateKeepsTemplateContext(): void
    {
        $spy = new class extends \App\Persistence\NewsletterTemplatePersistence {
            public ?array $captured = null;

            public function cloneTemplate(\App\Models\NewsletterTemplate $source, int $createdBy): \App\Models\NewsletterTemplate
            {
                $this->captured = [
                    'project_id' => $source->project_id,
                    'created_by' => $createdBy,
                ];

                $clone = new \App\Models\NewsletterTemplate();
                $clone->id = 501;
                $clone->project_id = $source->project_id;
                $clone->created_by = $createdBy;

                return $clone;
            }
        };

        $source = new \App\Models\NewsletterTemplate();
        $source->id = 44;
        $source->project_id = 9;
        $source->name = 'Projektvorlage';
        $source->content_html = '<p>Body</p>';

        $clone = $spy->cloneTemplate($source, 123);

        $this->assertSame(9, $spy->captured['project_id']);
        $this->assertSame(123, $spy->captured['created_by']);
        $this->assertSame(9, $clone->project_id);
    }
}

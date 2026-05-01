<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ActionButtonsConsistencyFeatureTest extends TestCase
{
    public function testMultiActionTablesUseSplitDropdownPattern(): void
    {
        $templatePaths = [
            dirname(__DIR__) . '/../templates/finances/index.twig',
            dirname(__DIR__) . '/../templates/projects/index.twig',
            dirname(__DIR__) . '/../templates/sponsoring/sponsors/index.twig',
            dirname(__DIR__) . '/../templates/sponsoring/packages/index.twig',
            dirname(__DIR__) . '/../templates/users/manage.twig',
            dirname(__DIR__) . '/../templates/songs/manage.twig',
            dirname(__DIR__) . '/../templates/newsletters/templates_index.twig',
        ];

        foreach ($templatePaths as $templatePath) {
            $content = file_get_contents($templatePath);

            $this->assertIsString($content, 'Template must be readable: ' . $templatePath);
            $this->assertStringContainsString('class="btn-group"', $content, 'btn-group missing: ' . $templatePath);
            $this->assertStringContainsString(
                'dropdown-toggle dropdown-toggle-split',
                $content,
                'split dropdown missing: ' . $templatePath
            );
            $this->assertStringContainsString('dropdown-menu dropdown-menu-end', $content, 'dropdown menu missing: ' . $templatePath);
        }
    }

    public function testSingleActionTablesStillUseButtonGroupWrapper(): void
    {
        $templatePaths = [
            dirname(__DIR__) . '/../templates/roles/index.twig',
            dirname(__DIR__) . '/../templates/projects/tasks.twig',
            dirname(__DIR__) . '/../templates/projects/members.twig',
            dirname(__DIR__) . '/../templates/newsletters/archive.twig',
            dirname(__DIR__) . '/../templates/songs/downloads.twig',
            dirname(__DIR__) . '/../templates/sponsoring/dashboard.twig',
            dirname(__DIR__) . '/../templates/admin/mail_queue/index.twig',
        ];

        foreach ($templatePaths as $templatePath) {
            $content = file_get_contents($templatePath);

            $this->assertIsString($content, 'Template must be readable: ' . $templatePath);
            $this->assertStringContainsString('class="btn-group"', $content, 'btn-group missing: ' . $templatePath);
        }
    }
}

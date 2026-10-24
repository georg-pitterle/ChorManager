<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use PHPUnit\Framework\TestCase;

/**
 * Die Logo-Fristen im Projektformular gehören zum Sponsoring. Ist das Modul
 * abgeschaltet, haben sie dort nichts verloren - sonst stünden in jedem
 * Projekt zwei Felder, die nirgends etwas bewirken.
 */
final class ProjectLogoDeadlineModuleFlagFeatureTest extends TestCase
{
    use TwigViewStubs;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testTheProjectFormsShowTheLogoDeadlinesWithSponsoring(): void
    {
        $html = $this->render(true);

        $this->assertSame(2, substr_count($html, 'name="logo_deadline_print"'));
        $this->assertSame(2, substr_count($html, 'name="logo_deadline_online"'));
    }

    public function testTheProjectFormsHideTheLogoDeadlinesWithoutSponsoring(): void
    {
        $html = $this->render(false);

        $this->assertStringNotContainsString('logo_deadline', $html);
        $this->assertStringContainsString('name="end_date"', $html);
    }

    private function render(bool $sponsoring): string
    {
        $twig = $this->createAppTwig('/projects');
        $twig->getEnvironment()->addGlobal('settings', ['modules' => ['sponsoring' => $sponsoring]]);

        $project = new Project();
        $project->id = 4711;
        $project->name = 'Flaggenprojekt';

        return $twig->fetch('projects/index.twig', [
            'projects' => [$project],
            'active_nav' => 'projects',
        ]);
    }
}

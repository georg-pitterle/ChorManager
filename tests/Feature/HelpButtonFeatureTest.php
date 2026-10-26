<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Jede Seite, zu der es ein Hilfethema gibt, trägt im Seitenkopf denselben Hilfe-Knopf
 * aus `macros/help.twig` - so sieht er überall gleich aus und führt auf ein Thema, das es
 * auch gibt. Kommt ein neues Hilfethema dazu, gehört seine Seite in diese Liste.
 */
class HelpButtonFeatureTest extends TestCase
{
    /**
     * Seite (Template ohne `.twig`) => Hilfethema (Slug unter `/help/`).
     */
    private const PAGES = [
        'dashboard/index'               => 'navigation',
        'users/manage'                  => 'members',
        'roles/index'                   => 'roles',
        'notifications/index'           => 'notifications',
        'events/index'                  => 'events',
        'attendance/show'               => 'events-attendance',
        'settings/event_types'          => 'events-event-types',
        'registrations/index'           => 'events-registrations',
        'registrations/detail'          => 'events-registrations',
        'evaluations/index'             => 'evaluations',
        'evaluations/project_members'   => 'evaluations-project-members',
        'evaluations/registrations'     => 'evaluations-registrations',
        'files/index'                   => 'files',
        'files/folder'                  => 'files',
        'files/file'                    => 'files',
        'files/search'                  => 'files',
        'files/trash'                   => 'files-trash-versions',
        'finances/index'                => 'finance',
        'finances/journal'              => 'finance',
        'finances/accounts'             => 'finance',
        'finances/report'               => 'finance',
        'finances/import'               => 'finance',
        'budget/index'                  => 'finance-budget',
        'newsletters/index'             => 'newsletter',
        'newsletters/details'           => 'newsletter',
        'newsletters/preview'           => 'newsletter',
        'newsletters/archive'           => 'newsletter',
        'newsletters/create'            => 'newsletter-compose',
        'newsletters/edit'              => 'newsletter-compose',
        'newsletters/templates_index'   => 'newsletter-templates',
        'projects/index'                => 'projects',
        'projects/members'              => 'projects-members',
        'projects/member_projects'      => 'projects-members',
        'projects/tasks'                => 'projects-planning',
        'projects/task_detail'          => 'projects-planning',
        'songs/manage'                  => 'repertoire',
        'songs/create'                  => 'repertoire',
        'songs/detail'                  => 'repertoire-songs',
        'songs/downloads'               => 'repertoire-downloads',
        'sponsoring/dashboard'          => 'sponsoring',
        'sponsoring/attachments/index'  => 'sponsoring',
        'sponsoring/packages/index'     => 'sponsoring-packages',
        'sponsoring/sponsors/index'     => 'sponsoring-sponsors',
        'sponsoring/sponsors/detail'    => 'sponsoring-sponsors',
        'voice_group_lists/index'       => 'voice-group-lists',
        'voice_group_lists/show'        => 'voice-group-lists',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function helpTopicExists(string $slug): bool
    {
        return glob(self::root() . '/help/*/docs/' . $slug . '.md') !== [];
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function pages(): array
    {
        $cases = [];
        foreach (self::PAGES as $template => $slug) {
            $cases[$template] = [$template, $slug];
        }

        return $cases;
    }

    #[DataProvider('pages')]
    public function testPageCarriesTheHelpButtonForItsTopic(string $template, string $slug): void
    {
        $this->assertTrue(self::helpTopicExists($slug), "Hilfethema {$slug} gibt es nicht.");

        $source = (string) file_get_contents(self::root() . "/templates/{$template}.twig");

        $this->assertMatchesRegularExpression(
            '/\{%\s*import\s+"macros\/help\.twig"\s+as\s+help\s*%\}/',
            $source,
            "{$template}.twig importiert macros/help.twig nicht."
        );
        $this->assertMatchesRegularExpression(
            '/\{\{\s*help\.button\("' . preg_quote($slug, '/') . '"/',
            $source,
            "{$template}.twig zeigt den Hilfe-Knopf für {$slug} nicht."
        );
    }

    public function testEveryHelpButtonPointsToAnExistingTopic(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/templates'));
        $found = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }
            preg_match_all('/help\.button\("([a-z0-9\-]+)"/', (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $slug) {
                $found++;
                $this->assertTrue(self::helpTopicExists($slug), $file->getFilename() . " verweist auf fehlendes Thema {$slug}.");
            }
        }

        $this->assertGreaterThanOrEqual(count(self::PAGES), $found);
    }

    public function testNoPageBuildsItsOwnHelpButton(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/templates'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig' || str_ends_with($file->getPathname(), 'help.twig')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/<a\s+href="\/help\/[^"]*"\s+class="btn\b/',
                (string) file_get_contents($file->getPathname()),
                $file->getFilename() . ' baut einen eigenen Hilfe-Knopf statt help.button().'
            );
        }
    }

    public function testMacroRendersLinkWithIconAndLabel(): void
    {
        $twig = new Environment(new FilesystemLoader(self::root() . '/templates'), ['autoescape' => 'html']);
        $html = $twig->createTemplate(
            '{% import "macros/help.twig" as help %}{{ help.button("members", "Mitgliederverwaltung") }}'
        )->render();

        $this->assertStringContainsString('href="/help/members"', $html);
        $this->assertStringContainsString('class="btn btn-outline-secondary help-button"', $html);
        $this->assertStringContainsString('title="Hilfe zu Mitgliederverwaltung"', $html);
        $this->assertStringContainsString('bi-question-circle', $html);
        $this->assertMatchesRegularExpression('/<span class="d-none d-sm-inline">\s*Hilfe<\/span>/', $html);
    }
}

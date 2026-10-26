<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Help\HelpCatalog;
use App\Navigation\NavigationContext;
use PHPUnit\Framework\TestCase;

/**
 * Die Hilfe-Übersicht zeigt nur Anleitungen zu Bereichen, die die Person nutzen kann,
 * und ordnet sie wie die Seitenleiste. Grundlage ist der Katalog in HelpCatalog, der
 * jedem Thema seine Menüpunkte, Module und Rechte zuordnet.
 */
class HelpCatalogFeatureTest extends TestCase
{
    private const ALL_MODULES = [
        'sheet_archive' => true, 'finance' => true, 'budget' => true, 'newsletter' => true,
        'sponsoring' => true, 'tasks' => true, 'registration' => true, 'oidc' => true,
        'files' => true, 'office' => true,
    ];

    /**
     * @param array<string,bool> $permissions
     * @param array<string,bool> $modules
     */
    private function context(array $permissions = [], array $modules = []): NavigationContext
    {
        return new NavigationContext($permissions, $modules, '/help', 'help');
    }

    private function adminContext(): NavigationContext
    {
        $permissions = [];
        foreach ($this->allPermissionFlags() as $flag) {
            $permissions[$flag] = true;
        }

        return $this->context($permissions, self::ALL_MODULES);
    }

    /**
     * Alle Rechte, die NavigationBuilder oder der Katalog abfragen - aus dem Quelltext
     * gelesen, damit ein neues Recht den Admin-Fall nicht stillschweigend verkleinert.
     *
     * @return list<string>
     */
    private function allPermissionFlags(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Navigation/NavigationBuilder.php')
            . (string) file_get_contents(dirname(__DIR__, 2) . '/src/Help/HelpCatalog.php');
        preg_match_all('/can_[a-z_]+/', $source, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @return list<string>
     */
    private function visibleSlugs(NavigationContext $context): array
    {
        $slugs = [];
        foreach ((new HelpCatalog())->groups($context) as $group) {
            foreach ($group['topics'] as $topic) {
                $slugs[] = $topic['slug'];
            }
        }

        return $slugs;
    }

    public function testEveryHelpDocumentHasACatalogEntryAndEveryEntryADocument(): void
    {
        $documents = [];
        foreach (glob(dirname(__DIR__, 2) . '/help/*/docs/*.md') ?: [] as $path) {
            $documents[] = basename($path, '.md');
        }
        sort($documents);

        $catalog = (new HelpCatalog())->slugs();
        sort($catalog);

        $this->assertNotEmpty($documents);
        $this->assertSame(
            $documents,
            $catalog,
            'Neue Hilfethemen brauchen einen Eintrag in HelpCatalog::TOPICS, sonst fehlen sie in der Übersicht.'
        );
    }

    public function testAdminWithEveryModuleSeesEveryTopicExactlyOnce(): void
    {
        $visible = $this->visibleSlugs($this->adminContext());
        $catalog = (new HelpCatalog())->slugs();

        $this->assertCount(count($catalog), $visible);
        $this->assertEqualsCanonicalizing($catalog, $visible);
    }

    public function testPlainMemberWithoutModulesSeesOnlyTopicsForPagesTheyCanOpen(): void
    {
        $visible = $this->visibleSlugs($this->context());

        foreach (['navigation', 'notifications', 'events', 'evaluations', 'repertoire-downloads'] as $slug) {
            $this->assertContains($slug, $visible);
        }

        foreach (['finance', 'members', 'roles', 'sponsoring', 'newsletter', 'sso', 'events-registrations', 'files'] as $slug) {
            $this->assertNotContains($slug, $visible, "{$slug} gehört nicht in die Hilfe eines einfachen Mitglieds.");
        }
    }

    public function testSwitchedOffModuleHidesItsTopicsEvenWithTheRight(): void
    {
        $visible = $this->visibleSlugs($this->context(['can_manage_sponsoring' => true, 'can_manage_finances' => true]));

        $this->assertNotContains('sponsoring', $visible);
        $this->assertNotContains('sponsoring-packages', $visible);
        $this->assertNotContains('finance', $visible);
    }

    public function testMemberWithNewsletterModuleSeesTheArchiveTopicButNotWritingOrTemplates(): void
    {
        $visible = $this->visibleSlugs($this->context([], ['newsletter' => true]));

        $this->assertContains('newsletter', $visible);
        $this->assertNotContains('newsletter-compose', $visible);
        $this->assertNotContains('newsletter-templates', $visible);
    }

    public function testTopicNeedingModuleAndRightStaysHiddenWhenEitherIsMissing(): void
    {
        $songLibrary = ['can_manage_song_library' => true];

        $withoutRight = $this->visibleSlugs($this->context($songLibrary, ['sheet_archive' => true]));
        $withoutModule = $this->visibleSlugs($this->context($songLibrary + ['can_manage_sheet_archive' => true]));
        $withBoth = $this->visibleSlugs(
            $this->context($songLibrary + ['can_manage_sheet_archive' => true], ['sheet_archive' => true])
        );

        $this->assertContains('repertoire', $withoutRight);
        $this->assertNotContains('repertoire-sheet-archive', $withoutRight);
        $this->assertNotContains('repertoire-sheet-archive', $withoutModule);
        $this->assertContains('repertoire-sheet-archive', $withBoth);
    }

    public function testGroupsFollowTheSidebarOrderWithFirstStepsOnTop(): void
    {
        $titles = array_column((new HelpCatalog())->groups($this->adminContext()), 'title');

        $this->assertSame(
            ['Erste Schritte', 'Termine', 'Noten & Dateien', 'Mitglieder & Projekte', 'Finanzen', 'Kommunikation', 'Administration'],
            $titles
        );
    }

    public function testTopicSitsInTheGroupOfItsMenuEntry(): void
    {
        $groups = [];
        foreach ((new HelpCatalog())->groups($this->adminContext()) as $group) {
            $groups[$group['title']] = array_column($group['topics'], 'slug');
        }

        $this->assertSame('navigation', $groups['Erste Schritte'][0]);
        $this->assertContains('events-event-types', $groups['Administration']);
        $this->assertContains('evaluations-project-members', $groups['Mitglieder & Projekte']);
        $this->assertContains('repertoire-downloads', $groups['Noten & Dateien']);
        $this->assertNotContains('events-event-types', $groups['Termine']);
    }

    public function testEmptyGroupsAreLeftOut(): void
    {
        $titles = array_column((new HelpCatalog())->groups($this->context()), 'title');

        $this->assertNotContains('Finanzen', $titles);
        $this->assertNotContains('Administration', $titles);
        $this->assertNotContains('Kommunikation', $titles);
    }

    public function testTopicsCarryTitleAndTheSearchKeywordsOfTheirMenuEntry(): void
    {
        $topics = [];
        foreach ((new HelpCatalog())->groups($this->context([], ['registration' => true])) as $group) {
            foreach ($group['topics'] as $topic) {
                $topics[$topic['slug']] = $topic;
            }
        }

        $this->assertSame('Anmeldungen (Zu-/Absagen)', $topics['events-registrations']['title']);
        $this->assertContains('absage', $topics['events-registrations']['keywords']);
        $this->assertContains('noten', $topics['repertoire-downloads']['keywords']);
    }

    public function testFrequentQuestionsShowAtMostFourAndOnlyVisibleTopics(): void
    {
        $catalog = new HelpCatalog();
        $member = $this->context();
        $visible = $this->visibleSlugs($member);

        $questions = $catalog->frequentQuestions($member);

        $this->assertNotEmpty($questions);
        $this->assertLessThanOrEqual(4, count($questions));
        foreach ($questions as $question) {
            $this->assertContains($question['slug'], $visible);
            $this->assertStringEndsWith('?', $question['question']);
        }
    }

    public function testFrequentQuestionsStartWithRegistrationWhenTheModuleIsOn(): void
    {
        $questions = (new HelpCatalog())->frequentQuestions($this->context([], ['registration' => true]));

        $this->assertSame('events-registrations', $questions[0]['slug']);
    }

    public function testTopicsReturnsAFlatListWithGroupTitleForTheQuickSearch(): void
    {
        $topics = (new HelpCatalog())->topics($this->context());

        $this->assertNotEmpty($topics);
        $first = $topics[0];
        $this->assertSame('navigation', $first['slug']);
        $this->assertSame('Erste Schritte', $first['group']);
        $this->assertSame('Start und Navigation', $first['title']);
    }
}

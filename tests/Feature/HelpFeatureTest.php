<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\HelpController;
use App\Help\HelpCatalog;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\TwigFunction;

class HelpFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private string $docsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $this->docsDir = sys_get_temp_dir() . '/chormanager_help_test_' . bin2hex(random_bytes(4));

        mkdir($this->docsDir . '/sponsoring/docs', 0755, true);
        mkdir($this->docsDir . '/sponsoring/screenshots', 0755, true);
        mkdir($this->docsDir . '/alpha-guide/docs', 0755, true);
        mkdir($this->docsDir . '/no-heading/docs', 0755, true);
        mkdir($this->docsDir . '/modul-a/docs', 0755, true);

        file_put_contents(
            $this->docsDir . '/sponsoring/docs/sponsoring.md',
            "# Sponsoring – Anleitung\n\nEinleitungstext.\n\n![Dashboard](images/sponsoring/01-dashboard.png)\n"
        );
        file_put_contents(
            $this->docsDir . '/alpha-guide/docs/alpha-guide.md',
            "# Alpha Guide\n\nInhalt **fett**.\n"
        );
        file_put_contents(
            $this->docsDir . '/no-heading/docs/no-heading.md',
            "Nur Fliesstext ohne Ueberschrift.\n"
        );
        file_put_contents($this->docsDir . '/modul-a/docs/modul-a.md', "# Modul A\n\nEinleitung.\n");
        file_put_contents($this->docsDir . '/modul-a/docs/modul-a-eins.md', "# Eins\n\nInhalt Eins.\n");
        file_put_contents($this->docsDir . '/modul-a/docs/modul-a-zwei.md', "# Zwei\n\nInhalt Zwei.\n");
        file_put_contents($this->docsDir . '/sponsoring/screenshots/01-dashboard.png', 'fake-png-bytes');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeDirectory($this->docsDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * @param array<string,bool> $modules
     */
    private function controller(array $modules = []): HelpController
    {
        $catalog = new HelpCatalog($this->docsDir, [
            'alpha-guide'  => ['section' => 'start'],
            'no-heading'   => ['section' => 'start'],
            'modul-a'      => ['menu' => ['/events']],
            'modul-a-eins' => ['menu' => ['/events']],
            'modul-a-zwei' => ['menu' => ['/roles']],
            'sponsoring'   => ['menu' => ['/sponsoring']],
        ]);

        return new HelpController($this->createTwig(), $this->docsDir, ['modules' => $modules], $catalog);
    }

    /**
     * layout.twig unconditionally calls asset_path() for its stylesheet/script tags.
     * The real app registers it in Dependencies.php; a bare Twig::create() does not
     * know it, so full-page renders here need the same stub other feature tests use.
     */
    private function createTwig(): Twig
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $twig->getEnvironment()->addFunction(new TwigFunction(
            'asset_path',
            static fn(string $path): string => $path
        ));
        // Twig compiles both branches of the {% if session.user_id %} block in
        // layout.twig, so navigation() must resolve even though $_SESSION is empty
        // here and the branch never executes at runtime.
        $twig->getEnvironment()->addFunction(new TwigFunction(
            'navigation',
            static fn(string $activeNav = ''): array => []
        ));

        return $twig;
    }

    public function testShowLabelsTableCellsWithTheirColumnHeaderForStackedMobileLayout(): void
    {
        file_put_contents(
            $this->docsDir . '/alpha-guide/docs/alpha-guide.md',
            "# Alpha Guide\n\n| Abschnitt | Was du siehst |\n|---|---|\n| **Handlungsbedarf** | Termine & Mails |\n"
        );

        $response = $this->controller()->show(
            $this->makeRequest('GET', '/help/alpha-guide'),
            $this->makeResponse(),
            ['slug' => 'alpha-guide']
        );
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<div class="markdown-table"><table>', $body);
        $this->assertStringContainsString('<td data-label="Abschnitt"><strong>Handlungsbedarf</strong></td>', $body);
        $this->assertStringContainsString('<td data-label="Was du siehst">Termine &amp; Mails</td>', $body);
        $this->assertStringContainsString('Alpha Guide', $body);
    }

    public function testShowListsTheSectionsOfALongTopicAndAnchorsThem(): void
    {
        file_put_contents(
            $this->docsDir . '/alpha-guide/docs/alpha-guide.md',
            "# Alpha Guide\n\n## Wer darf was\n\nText\n\n### Unterpunkt\n\nText\n\n## Logos & Fristen\n\nText\n\n## Wer darf was\n\nText\n"
        );

        $body = $this->renderTopic('alpha-guide');

        $this->assertStringContainsString('Auf dieser Seite', $body);
        $this->assertStringContainsString('<h2 id="wer-darf-was">Wer darf was</h2>', $body);
        $this->assertStringContainsString('<h2 id="logos-fristen">Logos &amp; Fristen</h2>', $body);
        // Gleich lautende Abschnitte brauchen trotzdem je ein eigenes Ziel.
        $this->assertStringContainsString('<h2 id="wer-darf-was-2">Wer darf was</h2>', $body);
        $this->assertStringContainsString('<a href="#logos-fristen">Logos &amp; Fristen</a>', $body);
        // Nur die Abschnitte, nicht jeder Unterpunkt.
        $this->assertStringNotContainsString('href="#unterpunkt"', $body);
    }

    public function testShowHasNoSectionListForAShortTopic(): void
    {
        file_put_contents($this->docsDir . '/alpha-guide/docs/alpha-guide.md', "# Alpha Guide\n\n## Einziger Abschnitt\n\nText\n");

        $this->assertStringNotContainsString('Auf dieser Seite', $this->renderTopic('alpha-guide'));
    }

    public function testShowOpensImagesInFullSize(): void
    {
        // In der Lesespalte ist ein Screenshot kleiner als aufgenommen.
        $body = $this->renderTopic('sponsoring');

        $this->assertMatchesRegularExpression(
            '~<a href="images/sponsoring/01-dashboard\.png"[^>]*target="_blank"[^>]*rel="noopener"[^>]*>\s*<img src="images/sponsoring/01-dashboard\.png"~',
            $body
        );
    }

    public function testShowLeavesDocumentsWithoutTablesUnchanged(): void
    {
        $response = $this->controller()->show(
            $this->makeRequest('GET', '/help/alpha-guide'),
            $this->makeResponse(),
            ['slug' => 'alpha-guide']
        );

        $this->assertStringContainsString('<p>Inhalt <strong>fett</strong>.</p>', (string) $response->getBody());
    }

    public function testIndexListsVisibleTopicsUnderGroupLabelsWithTitleFromH1AndFallback(): void
    {
        $response = $this->controller()->index($this->makeRequest('GET', '/help'), $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Erste Schritte', $body);
        $this->assertStringContainsString('Termine', $body);
        $this->assertStringContainsString('Alpha Guide', $body);
        $this->assertStringContainsString('href="/help/alpha-guide"', $body);
        $this->assertStringContainsString('href="/help/modul-a-eins"', $body);
        // Fallback title derived from slug when the file has no H1 heading.
        $this->assertStringContainsString('No Heading', $body);
    }

    public function testIndexHidesTopicsWhoseModuleIsOffOrWhoseRightIsMissing(): void
    {
        $body = (string) $this->controller()->index($this->makeRequest('GET', '/help'), $this->makeResponse())->getBody();

        $this->assertStringNotContainsString('href="/help/sponsoring"', $body);
        $this->assertStringNotContainsString('href="/help/modul-a-zwei"', $body);
    }

    public function testIndexShowsTopicOnceModuleAndRightAreThere(): void
    {
        $_SESSION = ['can_manage_sponsoring' => true, 'can_manage_roles' => true];

        $body = (string) $this->controller(['sponsoring' => true])
            ->index($this->makeRequest('GET', '/help'), $this->makeResponse())
            ->getBody();

        $this->assertStringContainsString('href="/help/sponsoring"', $body);
        $this->assertStringContainsString('href="/help/modul-a-zwei"', $body);
    }

    public function testIndexRendersOpenListsInsteadOfAccordionAndKeepsTheFlatStyle(): void
    {
        $body = (string) $this->controller()->index($this->makeRequest('GET', '/help'), $this->makeResponse())->getBody();

        $this->assertStringNotContainsString('accordion', $body);
        $this->assertStringNotContainsString('shadow-sm', $body);
        // Der Untertitel nennt weder die Marke (White Label) noch Technikbegriffe.
        $this->assertStringNotContainsString('im Chor-Manager', $body);
        $this->assertStringNotContainsString('die Module', $body);
    }

    public function testIndexOffersAFilterFieldThatOnlyTheScriptReveals(): void
    {
        $body = (string) $this->controller()->index($this->makeRequest('GET', '/help'), $this->makeResponse())->getBody();

        $this->assertMatchesRegularExpression('/<div[^>]*data-help-filter[^>]*hidden/', $body);
        $this->assertStringContainsString('<script src="/js/help-index.js"></script>', $body);
        $this->assertStringContainsString('data-help-topic', $body);
    }

    public function testIndexShowsFrequentQuestionsLinkingToVisibleTopics(): void
    {
        $controller = new HelpController($this->createTwig());

        $body = (string) $controller->index($this->makeRequest('GET', '/help'), $this->makeResponse())->getBody();

        $this->assertStringContainsString('Häufige Fragen', $body);
        $this->assertStringContainsString('href="/help/navigation"', $body);
        $this->assertStringNotContainsString('href="/help/finance"', $body);
    }

    public function testIndexShowsMutedTextWhenNoTopicIsVisible(): void
    {
        $catalog = new HelpCatalog($this->docsDir, ['alpha-guide' => ['section' => 'start', 'modules' => ['finance']]]);
        $controller = new HelpController($this->createTwig(), $this->docsDir, [], $catalog);

        $body = (string) $controller->index($this->makeRequest('GET', '/help'), $this->makeResponse())->getBody();

        $this->assertStringContainsString('Für dich gibt es hier gerade keine Anleitungen.', $body);
        $this->assertStringNotContainsString('class="card', $body);
    }

    public function testRealDocsShowSponsoringFamilyToSponsoringManagers(): void
    {
        $_SESSION = ['can_manage_sponsoring' => true];
        $controller = new HelpController($this->createTwig(), null, ['modules' => ['sponsoring' => true]]);

        $body = (string) $controller->index($this->makeRequest('GET', '/help'), $this->makeResponse())->getBody();

        $this->assertStringContainsString('href="/help/sponsoring-sponsors"', $body);
        $this->assertStringContainsString('href="/help/sponsoring-packages"', $body);
    }

    public function testTopicsJsonListsVisibleTopicsForTheQuickSearch(): void
    {
        $_SESSION = ['can_manage_sponsoring' => true];

        $response = $this->controller(['sponsoring' => true])
            ->topics($this->makeRequest('GET', '/help/topics.json'), $this->makeResponse());
        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $urls = array_column($data, 'url');
        $this->assertContains('/help/sponsoring', $urls);
        $this->assertContains('/help/alpha-guide', $urls);
        $this->assertNotContains('/help/modul-a-zwei', $urls);

        $sponsoring = $data[array_search('/help/sponsoring', $urls, true)];
        $this->assertSame('Sponsoring – Anleitung', $sponsoring['title']);
        $this->assertSame('Finanzen', $sponsoring['group']);
        $this->assertIsArray($sponsoring['keywords']);
    }

    public function testShowRendersMarkdownAsHtmlWithTitleFromH1(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/help/alpha-guide');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => 'alpha-guide']);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        // Der Titel steht einmal im Seitenkopf, nicht noch einmal als erste Zeile im Text.
        $this->assertStringContainsString('<h1 class="h2 mb-1">Alpha Guide</h1>', $body);
        $this->assertStringNotContainsString('<h1>Alpha Guide</h1>', $body);
        $this->assertStringContainsString('<strong>fett</strong>', $body);
    }

    public function testShowKeepsRelativeImagePathSoBrowserResolvesItAgainstHelpImageRoute(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/help/sponsoring');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => 'sponsoring']);
        $body = (string) $response->getBody();

        // Relative to /help/sponsoring, "images/sponsoring/x.png" resolves in the browser to
        // /help/images/sponsoring/x.png, which the image() route handles - no server-side rewriting.
        $this->assertStringContainsString('<img src="images/sponsoring/01-dashboard.png"', $body);
    }

    public function testShowReturns404ForUnknownSlug(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/help/does-not-exist');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => 'does-not-exist']);

        $body = (string) $response->getBody();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Anleitung nicht gefunden', $body);
        $this->assertMatchesRegularExpression('/<a href="\/help" class="btn btn-outline-secondary">/', $body);
    }

    private function renderTopic(string $slug): string
    {
        $response = $this->controller()->show(
            $this->makeRequest('GET', '/help/' . $slug),
            $this->makeResponse(),
            ['slug' => $slug]
        );

        return (string) $response->getBody();
    }

    public function testShowHasBackButtonButNoBreadcrumbAndAHairlineCard(): void
    {
        $response = $this->controller()->show(
            $this->makeRequest('GET', '/help/alpha-guide'),
            $this->makeResponse(),
            ['slug' => 'alpha-guide']
        );
        $body = (string) $response->getBody();

        $this->assertStringNotContainsString('breadcrumb', $body);
        $this->assertStringNotContainsString('shadow-sm', $body);
        $this->assertMatchesRegularExpression('/<a href="\/help" class="btn btn-outline-secondary">/', $body);
    }

    public function testShowRejectsPathTraversalInSlug(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/help/..%2f..%2fcomposer');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => '../../composer']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testImageServesFileFromCategorySubfolderWithCorrectContentType(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/help/images/sponsoring/01-dashboard.png');

        $response = $controller->image($request, $this->makeResponse(), ['file' => 'sponsoring/01-dashboard.png']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertSame('fake-png-bytes', (string) $response->getBody());
    }

    public function testImageReturns404ForMissingFile(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/help/images/sponsoring/does-not-exist.png');

        $response = $controller->image($request, $this->makeResponse(), ['file' => 'sponsoring/does-not-exist.png']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testImageRejectsPathTraversalAttempt(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/help/images/sponsoring/..%2f..%2fcomposer.json');

        $response = $controller->image(
            $request,
            $this->makeResponse(),
            ['file' => 'sponsoring/../../composer.json']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRoutesRegisterHelpEndpointsAccessibleToAllLoggedInUsers(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/help'", $routesContent);
        $this->assertStringContainsString('/help/{slug:', $routesContent);
        $this->assertStringContainsString('/help/images/{file:', $routesContent);
        $this->assertStringContainsString("'/help/topics.json'", $routesContent);
    }

    public function testNavigationBuilderIncludesHelpLink(): void
    {
        $navigationContent = file_get_contents(dirname(__DIR__, 2) . '/src/Navigation/NavigationBuilder.php');

        $this->assertIsString($navigationContent);
        $this->assertStringContainsString("'/help'", $navigationContent);
    }

    public function testComposerRequiresParsedown(): void
    {
        $composerContent = file_get_contents(dirname(__DIR__, 2) . '/composer.json');

        $this->assertIsString($composerContent);
        $this->assertStringContainsString('erusev/parsedown', $composerContent);
    }

    public function testRealDocsDirectoryExposesSponsoringGuideForHelpSponsoringRoute(): void
    {
        // Integration check tying Part 1 (docs/sponsoring.md) to Part 2 (the /help/{slug} route):
        // the real docs/ directory (default constructor path) must serve the sponsoring guide.
        $controller = new HelpController($this->createTwig());
        $request = $this->makeRequest('GET', '/help/sponsoring');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => 'sponsoring']);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('images/sponsoring/01-dashboard.png', $body);
    }
}

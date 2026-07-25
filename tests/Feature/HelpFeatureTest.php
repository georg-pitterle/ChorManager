<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\HelpController;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\TwigFunction;

class HelpFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private string $docsDir;
    private string $imagesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $this->docsDir = sys_get_temp_dir() . '/chormanager_help_test_' . bin2hex(random_bytes(4));
        $this->imagesDir = $this->docsDir . '/images';
        mkdir($this->imagesDir . '/sponsoring', 0755, true);

        file_put_contents(
            $this->docsDir . '/sponsoring.md',
            "# Sponsoring – Anleitung\n\nEinleitungstext.\n\n![Dashboard](images/sponsoring/01-dashboard.png)\n"
        );
        file_put_contents(
            $this->docsDir . '/alpha-guide.md',
            "# Alpha Guide\n\nInhalt **fett**.\n"
        );
        file_put_contents(
            $this->docsDir . '/no-heading.md',
            "Nur Fliesstext ohne Ueberschrift.\n"
        );
        file_put_contents($this->docsDir . '/modul-a.md', "# Modul A\n\nEinleitung.\n");
        file_put_contents($this->docsDir . '/modul-a-eins.md', "# Eins\n\nInhalt Eins.\n");
        file_put_contents($this->docsDir . '/modul-a-zwei.md', "# Zwei\n\nInhalt Zwei.\n");
        file_put_contents($this->imagesDir . '/sponsoring/01-dashboard.png', 'fake-png-bytes');
    }

    protected function tearDown(): void
    {
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

    private function controller(): HelpController
    {
        return new HelpController($this->createTwig(), $this->docsDir, $this->imagesDir);
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

    public function testIndexListsGuidesWithTitleFromH1AndFallbackForMissingHeading(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe');

        $response = $controller->index($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Sponsoring – Anleitung', $body);
        $this->assertStringContainsString('Alpha Guide', $body);
        $this->assertStringContainsString('/hilfe/sponsoring', $body);
        $this->assertStringContainsString('/hilfe/alpha-guide', $body);
        // Fallback title derived from slug when the file has no H1 heading.
        $this->assertStringContainsString('No Heading', $body);
    }

    public function testIndexGroupsGuidesSharingSlugPrefixIntoOneCollapsibleCategory(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe');

        $response = $controller->index($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        // "modul-a.md" is the category root; "modul-a-eins.md" / "modul-a-zwei.md" are its children.
        $this->assertStringContainsString('id="help-category-collapse-modul-a"', $body);
        $this->assertStringContainsString('Modul A', $body);
        $this->assertStringContainsString('/hilfe/modul-a-eins', $body);
        $this->assertStringContainsString('/hilfe/modul-a-zwei', $body);
    }

    public function testIndexRendersGuideWithoutSiblingsAsPlainLinkNotAccordion(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe');

        $response = $controller->index($request, $this->makeResponse());
        $body = (string) $response->getBody();

        // alpha-guide.md has no "alpha-guide-*.md" siblings, so it is a single-page
        // category and must render as a direct link, not a collapsible group.
        $this->assertStringContainsString('/hilfe/alpha-guide', $body);
        $this->assertStringNotContainsString('id="help-category-collapse-alpha-guide"', $body);
    }

    public function testRealDocsSponsoringFamilyGroupsIntoOneCategory(): void
    {
        // Integration check: the naming convention behind docs/sponsoring.md,
        // docs/sponsoring-sponsoren.md and docs/sponsoring-pakete.md must produce a
        // single collapsible "Sponsoring" category (the user's example case).
        $controller = new HelpController($this->createTwig());
        $request = $this->makeRequest('GET', '/hilfe');

        $response = $controller->index($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('id="help-category-collapse-sponsoring"', $body);
        $this->assertStringContainsString('/hilfe/sponsoring-sponsoren', $body);
        $this->assertStringContainsString('/hilfe/sponsoring-pakete', $body);
    }

    public function testShowRendersMarkdownAsHtmlWithTitleFromH1(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe/alpha-guide');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => 'alpha-guide']);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>Alpha Guide</h1>', $body);
        $this->assertStringContainsString('<strong>fett</strong>', $body);
    }

    public function testShowKeepsRelativeImagePathSoBrowserResolvesItAgainstHelpImageRoute(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe/sponsoring');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => 'sponsoring']);
        $body = (string) $response->getBody();

        // Relative to /hilfe/sponsoring, "images/sponsoring/x.png" resolves in the browser to
        // /hilfe/images/sponsoring/x.png, which the image() route handles - no server-side rewriting.
        $this->assertStringContainsString('<img src="images/sponsoring/01-dashboard.png"', $body);
    }

    public function testShowReturns404ForUnknownSlug(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe/does-not-exist');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => 'does-not-exist']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowRejectsPathTraversalInSlug(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe/..%2f..%2fcomposer');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => '../../composer']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testImageServesFileFromCategorySubfolderWithCorrectContentType(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe/images/sponsoring/01-dashboard.png');

        $response = $controller->image($request, $this->makeResponse(), ['file' => 'sponsoring/01-dashboard.png']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertSame('fake-png-bytes', (string) $response->getBody());
    }

    public function testImageReturns404ForMissingFile(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe/images/sponsoring/does-not-exist.png');

        $response = $controller->image($request, $this->makeResponse(), ['file' => 'sponsoring/does-not-exist.png']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testImageRejectsPathTraversalAttempt(): void
    {
        $controller = $this->controller();
        $request = $this->makeRequest('GET', '/hilfe/images/sponsoring/..%2f..%2fcomposer.json');

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
        $this->assertStringContainsString("'/hilfe'", $routesContent);
        $this->assertStringContainsString('/hilfe/{slug:', $routesContent);
        $this->assertStringContainsString('/hilfe/images/{file:', $routesContent);
    }

    public function testNavigationBuilderIncludesHelpLink(): void
    {
        $navigationContent = file_get_contents(dirname(__DIR__, 2) . '/src/Navigation/NavigationBuilder.php');

        $this->assertIsString($navigationContent);
        $this->assertStringContainsString("'/hilfe'", $navigationContent);
    }

    public function testComposerRequiresParsedown(): void
    {
        $composerContent = file_get_contents(dirname(__DIR__, 2) . '/composer.json');

        $this->assertIsString($composerContent);
        $this->assertStringContainsString('erusev/parsedown', $composerContent);
    }

    public function testRealDocsDirectoryExposesSponsoringGuideForHilfeSponsoringRoute(): void
    {
        // Integration check tying Part 1 (docs/sponsoring.md) to Part 2 (the /hilfe/{slug} route):
        // the real docs/ directory (default constructor path) must serve the sponsoring guide.
        $controller = new HelpController($this->createTwig());
        $request = $this->makeRequest('GET', '/hilfe/sponsoring');

        $response = $controller->show($request, $this->makeResponse(), ['slug' => 'sponsoring']);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('images/sponsoring/01-dashboard.png', $body);
    }
}

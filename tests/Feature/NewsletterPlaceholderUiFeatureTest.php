<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterController;
use App\Models\Newsletter;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Der Editor bindet die Platzhalter-Auswahl ohne Inline-JavaScript ein: Die
 * Textarea für den Newsletter-Inhalt trägt das Attribut
 * data-placeholder-source, über das tinymce-init.js den JSON-Endpunkt aus
 * Task 5 abfragt. Geprüft wird das tatsächlich von den Controller-Methoden
 * gerenderte HTML statt des Twig-Quelltexts, damit erfasst ist, was der
 * Browser wirklich bekommt.
 */
final class NewsletterPlaceholderUiFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "placeholder_ui_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    private function createDraft(User $creator): Newsletter
    {
        return Newsletter::create([
            'project_id' => null,
            'title' => 'Entwurf ' . bin2hex(random_bytes(4)),
            'content_html' => '<p>Hallo Chor!</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Baut den Controller mit einer echten Twig-Umgebung auf, analog zu
     * NewsletterProjectDecouplingFeatureTest::newsletterController(): create()
     * und edit() rendern die vollständige Seite samt layout.twig, daher
     * müssen dessen Filter, Funktionen und Globals hier nachgebildet werden.
     */
    private function newsletterController(): NewsletterController
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $environment = $twig->getEnvironment();
        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('current_path', '/newsletters');
        $this->registerMailBadgeStub($environment);
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static fn (string $path): string => $path
        ));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $context = NavigationContext::fromSession($_SESSION, [], '/newsletters', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return new NewsletterController(
            $twig,
            new NewsletterService(
                new NewsletterRecipientService(),
                new Mailer(new NullLogger()),
                new HtmlSanitizer(),
                new MailQueueService(),
                new NullLogger(),
                new NewsletterPlaceholderService(new NameFormatterService())
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            new MailQueueService()
        );
    }

    /**
     * Wert des Attributs data-placeholder-source der Textarea #content_html,
     * ermittelt über das tatsächlich gerenderte DOM statt über eine Suche im
     * Rohtext der Antwort.
     */
    private function contentTextareaPlaceholderSource(string $html): ?string
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $textarea = $xpath->query('//textarea[@id="content_html"]')->item(0);

        if ($textarea === null || !$textarea->hasAttribute('data-placeholder-source')) {
            return null;
        }

        return $textarea->getAttribute('data-placeholder-source');
    }

    public function testCreateFormDeclaresPlaceholderSourceOnContentTextarea(): void
    {
        $manager = $this->createUser();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->create(
            $this->makeRequest('GET', '/newsletters/create'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            '/newsletters/placeholders',
            $this->contentTextareaPlaceholderSource((string) $response->getBody()),
            'Die Inhalts-Textarea von create.twig muss die Platzhalter-Quelle deklarieren.'
        );
    }

    public function testEditFormDeclaresPlaceholderSourceOnContentTextarea(): void
    {
        $manager = $this->createUser();
        $newsletter = $this->createDraft($manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->edit(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/edit')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            '/newsletters/placeholders',
            $this->contentTextareaPlaceholderSource((string) $response->getBody()),
            'Die Inhalts-Textarea von edit.twig muss die Platzhalter-Quelle deklarieren.'
        );
    }

    /**
     * Für tinymce-init.js gibt es keinen serverseitig prüfbaren Effekt – die
     * Datei läuft nur im Browser. Ausnahmsweise wird deshalb der Dateiinhalt
     * geprüft; das eigentliche Verhalten des Menüknopfs (Abruf der
     * Platzhalter, Text-Einfügen ins Editor-DOM) deckt ein späterer
     * Browsertest ab.
     */
    public function testTinymceInitRegistersPlaceholderMenuButton(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/tinymce-init.js');

        $this->assertStringContainsString('addMenuButton', $script);
        $this->assertStringContainsString('placeholderSource', $script);
        $this->assertStringContainsString('insertContent', $script);
    }
}

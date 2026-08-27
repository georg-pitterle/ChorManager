<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterTemplateController;
use App\Models\Event;
use App\Models\NewsletterTemplate;
use App\Models\NewsletterTemplateRecipientSource;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Persistence\NewsletterTemplatePersistence;
use App\Queries\NewsletterTemplateQuery;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Services\NewsletterRecipientService;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Die Newsletter-Einstellungen gehören auch in den Erstellen-Dialog.
 *
 * Der Bearbeiten-Dialog bot Kontext, Titelvorschlag und alle vier Empfängerquellen
 * an, der Erstellen-Dialog nur Kontext und Titelvorschlag. Wer eine Vorlage neu
 * anlegte, musste sie also erst speichern und danach ein zweites Mal öffnen, um die
 * Empfängerquellen zu setzen. `store()` verarbeitete die Felder die ganze Zeit
 * bereits - es fehlte allein das Formular.
 */
final class NewsletterTemplateCreateSettingsFeatureTest extends TestCase
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

    public function testCreateDialogOffersAllRecipientSourceFields(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;

        $html = $this->renderIndex();

        $this->assertStringContainsString('name="source_project_members[]"', $html);
        $this->assertStringContainsString('name="source_event_attendees[]"', $html);
        $this->assertStringContainsString('name="source_role[]"', $html);
        $this->assertStringContainsString('name="source_user[]"', $html);
    }

    public function testCreateDialogListsTheSelectableEntries(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;

        $project = Project::create(['name' => 'Sichtbares Projekt ' . bin2hex(random_bytes(4))]);
        $role = Role::create(['name' => 'Sichtbare Rolle ' . bin2hex(random_bytes(4))]);

        $html = $this->renderIndex();

        // Ohne events/roles/users aus dem Controller blieben die Auswahlfelder leer.
        $this->assertStringContainsString($project->name, $html);
        $this->assertStringContainsString($role->name, $html);
        $this->assertStringContainsString((string) $user->first_name, $html);
    }

    /**
     * Beide Dialoge stehen gleichzeitig im selben Dokument - der Bearbeiten-Dialog
     * wird in #newsletterActionContent derselben Seite nachgeladen. Gleiche id
     * zweimal im Dokument hiesse: Beschriftungen zeigen aufs falsche Feld und
     * TomSelect greift sich das erstbeste Element.
     */
    public function testCreateDialogFieldIdsDoNotCollideWithTheEditForm(): void
    {
        $index = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/newsletters/templates_index.twig'
        );
        $edit = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/newsletters/templates_edit.twig'
        );

        $idsIn = static function (string $html): array {
            preg_match_all('/\sid="([^"]+)"/', $html, $matches);

            return $matches[1];
        };

        $shared = array_intersect($idsIn($index), $idsIn($edit));

        $this->assertSame(
            [],
            array_values($shared),
            'Erstellen- und Bearbeiten-Formular dürfen keine id teilen: ' . implode(', ', $shared)
        );
    }

    public function testIndexLoadsTomSelectForTheNewSelectFields(): void
    {
        $index = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/newsletters/templates_index.twig'
        );

        // Ohne die Bibliothek bleiben die Mehrfachauswahlen rohe <select multiple>-Listen.
        $this->assertStringContainsString('tom-select.bootstrap5.min.css', $index);
        $this->assertStringContainsString('tom-select.complete.min.js', $index);
        $this->assertStringContainsString('/js/tom-select-init.js', $index);
        $this->assertStringContainsString('data-tom-select', $index);
    }

    public function testCreatingATemplateWithSourcesPersistsThem(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;

        $project = Project::create(['name' => 'Quellenprojekt ' . bin2hex(random_bytes(4))]);
        $role = Role::create(['name' => 'Quellenrolle ' . bin2hex(random_bytes(4))]);

        $request = $this->makeRequest('POST', '/newsletters/templates', [
            'name' => 'Vorlage mit Quellen',
            'content_html' => '<p>Inhalt</p>',
            'description' => 'Beschreibung',
            'default_title' => 'Titelvorschlag',
            'project_id' => (string) $project->id,
            'source_project_members' => [(string) $project->id],
            'source_role' => [(string) $role->id],
        ]);

        $response = $this->templateController()->store($request, $this->makeResponse());
        $this->assertSame(302, $response->getStatusCode());

        $template = NewsletterTemplate::query()->where('name', 'Vorlage mit Quellen')->firstOrFail();

        $keys = $template->recipientSources()
            ->get()
            ->map(static fn (NewsletterTemplateRecipientSource $s): string => $s->source_type . ':' . $s->reference_id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS . ':' . $project->id,
            NewsletterTemplateRecipientSource::TYPE_ROLE . ':' . $role->id,
        ], $keys);

        $this->assertSame('Titelvorschlag', $template->default_title);
        $this->assertSame($project->id, $template->project_id);
    }

    private function renderIndex(): string
    {
        $request = $this->makeRequest('GET', '/newsletters/templates');
        $response = $this->templateController()->index($request, $this->makeResponse());

        return (string) $response->getBody();
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "template_create_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Vorlagen',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    private function templateController(): NewsletterTemplateController
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $environment = $twig->getEnvironment();
        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('current_path', '/newsletters/templates');
        $this->registerMailBadgeStub($environment);
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static fn (string $path): string => $path
        ));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $context = NavigationContext::fromSession($_SESSION, [], '/newsletters/templates', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return new NewsletterTemplateController(
            $twig,
            new HtmlSanitizer(),
            new NewsletterTemplateQuery(),
            new NewsletterTemplatePersistence(),
            new NewsletterRecipientService(),
            new NameFormatterService()
        );
    }
}

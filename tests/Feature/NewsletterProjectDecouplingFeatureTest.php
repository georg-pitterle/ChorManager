<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterController;
use App\Controllers\NewsletterTemplateController;
use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use App\Models\NewsletterRecipientSource;
use App\Models\NewsletterTemplate;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use App\Persistence\NewsletterTemplatePersistence;
use App\Queries\NewsletterTemplateQuery;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Newsletter hängen nicht mehr an der Projektmitgliedschaft: das Recht genügt,
 * der Projektbezug ist optional, und versendet wird nur mit Empfängern.
 */
final class NewsletterProjectDecouplingFeatureTest extends TestCase
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

    private function createUser(bool $active = true): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "decoupling_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function createProject(): Project
    {
        return Project::create([
            'name' => 'Entkopplung ' . bin2hex(random_bytes(4)),
        ]);
    }

    private function createDraft(?Project $project, User $creator): Newsletter
    {
        $newsletter = Newsletter::create([
            'project_id' => $project?->id,
            'title' => 'Entwurf ' . bin2hex(random_bytes(4)),
            'content_html' => '<p>Hallo Chor!</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        if ($project !== null) {
            NewsletterRecipientSource::create([
                'newsletter_id' => $newsletter->id,
                'source_type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
                'reference_id' => $project->id,
            ]);
        }

        return $newsletter;
    }

    public function testNewsletterCanBeStoredWithoutProject(): void
    {
        $creator = $this->createUser();

        $newsletter = $this->createDraft(null, $creator);

        $this->assertNull($newsletter->fresh()->project_id);
    }

    /**
     * Baut den Controller mit einer echten Twig-Umgebung auf. Da hier die
     * volle Seite (layout.twig samt Navigation) gerendert wird statt nur über
     * den DI-Container zu laufen, müssen die vom Layout verwendeten Filter,
     * Funktionen und Globals wie in den anderen Feature-Tests nachgebildet
     * werden (siehe TwigViewStubs).
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
                new NullLogger()
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService()
        );
    }

    /**
     * Baut den Vorlagen-Controller mit derselben vollständigen Twig-Umgebung
     * wie newsletterController(), weil edit() die echte Seite
     * templates_edit.twig samt Layout und Navigation rendert.
     */
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
            new NewsletterTemplatePersistence()
        );
    }

    private function createProjectTemplate(Project $project, User $creator): NewsletterTemplate
    {
        return NewsletterTemplate::create([
            'name' => 'Projektvorlage ' . bin2hex(random_bytes(4)),
            'description' => 'Testvorlage',
            'content_html' => '<p>Vorlageninhalt</p>',
            'project_id' => $project->id,
            'created_by' => $creator->id,
        ]);
    }

    private function createGlobalTemplate(User $creator): NewsletterTemplate
    {
        return NewsletterTemplate::create([
            'name' => 'Globale Vorlage ' . bin2hex(random_bytes(4)),
            'description' => 'Testvorlage',
            'content_html' => '<p>Vorlageninhalt</p>',
            'project_id' => null,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Attrappe für die Twig-View, die die an render() übergebenen Daten
     * festhält statt sie tatsächlich zu rendern. So lässt sich die
     * Struktur von template_groups prüfen, auch dort, wo das (bewusst
     * unveränderte) edit.twig-Markup das Feld gar nicht ausgibt.
     */
    private function newCapturingView(): Twig
    {
        return new class extends Twig {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function __construct()
            {
            }

            public function render($response, $template, array $data = []): ResponseInterface
            {
                $this->captured = $data;

                return $response;
            }
        };
    }

    /**
     * Ersetzt die view-Eigenschaft eines bereits gebauten Controllers durch
     * eine Attrappe und gibt diese zurück.
     */
    private function captureRenderedData(object $controller): Twig
    {
        $view = $this->newCapturingView();
        (new ReflectionClass($controller))->getProperty('view')->setValue($controller, $view);

        return $view;
    }

    /**
     * Wert der aktuell ausgewählten Option eines Selects, ermittelt über
     * das tatsächlich gerenderte DOM statt über Text-/Whitespace-Annahmen.
     * Gibt null zurück, wenn keine Option als selected markiert ist.
     */
    private function selectedOptionValue(string $html, string $selectId): ?string
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $option = $xpath->query('//select[@id="' . $selectId . '"]//option[@selected]')->item(0);

        return $option === null ? null : $option->getAttribute('value');
    }

    private function selectHasAttribute(string $html, string $selectId, string $attribute): bool
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $select = $xpath->query('//select[@id="' . $selectId . '"]')->item(0);

        return $select !== null && $select->hasAttribute($attribute);
    }

    public function testForeignProjectTemplateCanBeEditedAndCloned(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $project = $this->createProject();
        $template = $this->createProjectTemplate($project, $owner);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $editResponse = $this->templateController()->edit(
            $this->makeRequest('GET', '/newsletters/templates/' . $template->id . '/edit')
                ->withAttribute('id', (string) $template->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $editResponse->getStatusCode());

        $cloneResponse = $this->templateController()->clone(
            $this->makeRequest('POST', '/newsletters/templates/' . $template->id . '/clone', [], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->withAttribute('id', (string) $template->id),
            $this->makeResponse()
        );

        $this->assertSame(201, $cloneResponse->getStatusCode());
    }

    public function testForeignProjectTemplateCanBeLoadedIntoAnyNewsletter(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $project = $this->createProject();
        $template = $this->createProjectTemplate($project, $owner);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->templateController()->show(
            $this->makeRequest('GET', '/newsletters/template/' . $template->id)
                ->withAttribute('id', (string) $template->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame((int) $template->id, (int) $payload['id']);
    }

    public function testEditIsAllowedForManagerWithoutProjectMembership(): void
    {
        $outsider = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $outsider);

        $_SESSION['user_id'] = (int) $outsider->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->edit(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/edit')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSendIsAllowedForManagerWithoutProjectMembership(): void
    {
        $manager = $this->createUser();
        $member = $this->createUser();
        $project = $this->createProject();
        $project->users()->attach([$member->id]);
        $newsletter = $this->createDraft($project, $manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;
        $_ENV['DISABLE_MAIL_SEND'] = $_SERVER['DISABLE_MAIL_SEND'] = 'true';

        $response = $this->newsletterController()->send(
            $this->makeRequest('POST', '/newsletters/' . $newsletter->id . '/send')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(Newsletter::STATUS_SENT, $newsletter->fresh()->status);
    }

    public function testPreviewIsForbiddenWithoutRightAndWithoutArchiveEntry(): void
    {
        $stranger = $this->createUser();
        $creator = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $creator);

        $_SESSION['user_id'] = (int) $stranger->id;
        $_SESSION['can_manage_newsletters'] = false;

        $response = $this->newsletterController()->preview(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/preview')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPreviewIsAllowedForManagerWithoutArchiveEntry(): void
    {
        $manager = $this->createUser();
        $creator = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $creator);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->preview(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/preview')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPreviewIsAllowedForRecipientWithoutRight(): void
    {
        $recipient = $this->createUser();
        $creator = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $creator);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $response = $this->newsletterController()->preview(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/preview')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexWithoutProjectFilterListsDraftsFromAllProjects(): void
    {
        $manager = $this->createUser();
        $project = $this->createProject();
        $suffix = bin2hex(random_bytes(4));
        $titleWithProject = "Mit Projekt {$suffix}";
        $titleWithoutProject = "Ohne Projekt {$suffix}";
        $withProject = $this->createDraft($project, $manager);
        $withProject->update(['title' => $titleWithProject]);
        $withoutProject = $this->createDraft(null, $manager);
        $withoutProject->update(['title' => $titleWithoutProject]);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->index(
            $this->makeRequest('GET', '/newsletters'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        $this->assertStringContainsString($titleWithProject, $body);
        $this->assertStringContainsString($titleWithoutProject, $body);
    }

    public function testIndexFilterNoneKeepsOnlyProjectlessDrafts(): void
    {
        $manager = $this->createUser();
        $project = $this->createProject();
        $suffix = bin2hex(random_bytes(4));
        $titleWithProject = "Mit Projekt {$suffix}";
        $titleWithoutProject = "Ohne Projekt {$suffix}";
        $withProject = $this->createDraft($project, $manager);
        $withProject->update(['title' => $titleWithProject]);
        $withoutProject = $this->createDraft(null, $manager);
        $withoutProject->update(['title' => $titleWithoutProject]);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->index(
            $this->makeRequest('GET', '/newsletters', [], ['project_id' => 'none']),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        $this->assertStringContainsString($titleWithoutProject, $body);
        $this->assertStringNotContainsString($titleWithProject, $body);
    }

    /**
     * Eine numerische, aber nicht existierende Projekt-Kennung muss wie ein
     * unbekannter Wert behandelt werden – also wie "alle Projekte" – statt
     * die Liste grundlos leer erscheinen zu lassen.
     */
    public function testIndexWithUnknownProjectIdFallsBackToAllProjects(): void
    {
        $manager = $this->createUser();
        $project = $this->createProject();
        $suffix = bin2hex(random_bytes(4));
        $titleWithProject = "Mit Projekt {$suffix}";
        $withProject = $this->createDraft($project, $manager);
        $withProject->update(['title' => $titleWithProject]);

        $unknownProjectId = (int) $project->id + 1000000;
        $this->assertFalse(Project::query()->where('id', $unknownProjectId)->exists());

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->index(
            $this->makeRequest('GET', '/newsletters', [], ['project_id' => (string) $unknownProjectId]),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        $this->assertStringContainsString($titleWithProject, $body);
    }

    public function testDraftWithoutRecipientSourcesCanBeStored(): void
    {
        $manager = $this->createUser();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->store(
            $this->makeRequest(
                'POST',
                '/newsletters',
                [
                    'project_id' => '',
                    'title' => 'Entwurf ohne Empfänger',
                    'content_html' => '<p>Noch offen.</p>',
                ],
                [],
                ['X-Requested-With' => 'XMLHttpRequest']
            ),
            $this->makeResponse()
        );

        $this->assertSame(201, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $stored = Newsletter::find((int) $payload['id']);

        $this->assertNotNull($stored);
        $this->assertNull($stored->project_id);
        $this->assertSame(0, (int) $stored->recipient_count);
    }

    /**
     * Ein abgelehnter Versand darf keine Nebenwirkung haben: Die Prüfung auf
     * aufgelöste Empfänger muss vor setRecipients() laufen, sonst überschreibt
     * der abgelehnte Versand einen zuvor gespeicherten recipient_count mit 0
     * und löscht die bereits gespeicherten Empfängerzeilen.
     */
    public function testSendWithoutRecipientsIsRejectedWith422(): void
    {
        $manager = $this->createUser();
        $newsletter = $this->createDraft(null, $manager);

        // Zuvor gespeicherter Zustand aus einer früheren, inzwischen leer
        // gewordenen Empfängerauflösung – dieser darf nach der Ablehnung
        // unangetastet bleiben.
        $previousRecipient = $this->createUser();
        NewsletterRecipient::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $previousRecipient->id,
            'status' => 'pending',
        ]);
        $newsletter->recipient_count = 1;
        $newsletter->save();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->send(
            $this->makeRequest('POST', '/newsletters/' . $newsletter->id . '/send', [], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Newsletter hat keine Empfänger.', (string) $response->getBody());
        $this->assertSame(Newsletter::STATUS_DRAFT, $newsletter->fresh()->status);
        $this->assertSame(0, MailQueue::query()->where('subject', $newsletter->title)->count());
        $this->assertSame(
            1,
            (int) $newsletter->fresh()->recipient_count,
            'Ein abgelehnter Versand darf den zuvor gespeicherten recipient_count nicht überschreiben.'
        );
        $this->assertSame(
            1,
            NewsletterRecipient::query()->where('newsletter_id', $newsletter->id)->count(),
            'Ein abgelehnter Versand darf die zuvor gespeicherten Empfängerzeilen nicht löschen.'
        );
    }

    /**
     * Prüft den eigentlich interessanten Fall: Nicht das Fehlen einer Quelle
     * führt zur Ablehnung, sondern das Fehlen aufgelöster Personen. Die
     * konfigurierte Rollen-Quelle existiert, verweist aber auf eine Rolle
     * ohne aktive Mitglieder – die Auflösung muss also leer bleiben.
     */
    public function testSendIsRejectedWhenSourcesResolveToNobody(): void
    {
        $manager = $this->createUser();
        $newsletter = $this->createDraft(null, $manager);

        $role = Role::create([
            'name' => 'Leere Rolle ' . bin2hex(random_bytes(4)),
        ]);
        $inactiveMember = $this->createUser(false);
        $inactiveMember->roles()->attach($role->id);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_ROLE,
            'reference_id' => $role->id,
        ]);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->send(
            $this->makeRequest('POST', '/newsletters/' . $newsletter->id . '/send', [], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Newsletter hat keine Empfänger.', (string) $response->getBody());
        $this->assertSame(Newsletter::STATUS_DRAFT, $newsletter->fresh()->status);
    }

    public function testProjectlessNewsletterIsSentAndArchivedForEveryRecipient(): void
    {
        $manager = $this->createUser();
        $recipientA = $this->createUser();
        $recipientB = $this->createUser();
        $newsletter = $this->createDraft(null, $manager);

        foreach ([$recipientA, $recipientB] as $recipient) {
            NewsletterRecipientSource::create([
                'newsletter_id' => $newsletter->id,
                'source_type' => NewsletterRecipientSource::TYPE_USER,
                'reference_id' => $recipient->id,
            ]);
        }

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;
        $_ENV['DISABLE_MAIL_SEND'] = $_SERVER['DISABLE_MAIL_SEND'] = 'true';

        $response = $this->newsletterController()->send(
            $this->makeRequest('POST', '/newsletters/' . $newsletter->id . '/send', [], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $fresh = $newsletter->fresh();
        $this->assertSame(Newsletter::STATUS_SENT, $fresh->status);
        $this->assertNull($fresh->project_id);

        $archivedUserIds = NewsletterArchive::query()
            ->where('newsletter_id', $newsletter->id)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $recipientA->id, $archivedUserIds);
        $this->assertContains((int) $recipientB->id, $archivedUserIds);
    }

    /**
     * Solange das Projektfeld required war, konnte project_id beim Speichern
     * nie leer ankommen. Jetzt, wo das Feld optional ist, muss update() den
     * Newsletter tatsächlich projektlos machen, wenn die Auswahl entfernt
     * wird. update() setzt zusätzlich eine bestehende Bearbeitungssperre der
     * aufrufenden Person voraus.
     */
    public function testUpdateWithEmptyProjectIdMakesNewsletterProjectless(): void
    {
        $manager = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $manager);

        (new NewsletterLockingService())->acquireLock($newsletter, (int) $manager->id);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->update(
            $this->makeRequest(
                'POST',
                '/newsletters/' . $newsletter->id,
                [
                    'project_id' => '',
                    'title' => $newsletter->title,
                    'content_html' => $newsletter->content_html,
                ]
            )->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(
            $newsletter->fresh()->project_id,
            'Ein entferntes Projekt muss den Newsletter tatsächlich projektlos machen.'
        );
    }

    /**
     * Auf einer Installation ganz ohne Projekte darf der Entkopplung
     * entsprechend auch create() nicht mehr mit 403 ablehnen – das Recht
     * can_manage_newsletters ist die einzige Zugangsvoraussetzung. Alle
     * Fremdschlüssel auf projects.id sind CASCADE oder SET NULL, das Löschen
     * aller Projekte verletzt daher keine Fremdschlüsselbeziehung.
     */
    public function testCreateSucceedsWhenNoProjectsExist(): void
    {
        $manager = $this->createUser();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        Project::query()->delete();
        $this->assertSame(0, Project::query()->count());

        $response = $this->newsletterController()->create(
            $this->makeRequest('GET', '/newsletters/create'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Belegt die im Brief geforderte Struktur von template_groups: die erste
     * Gruppe heißt Global und enthält die projektlosen Vorlagen, danach
     * folgen die Projektgruppen alphabetisch sortiert nach Projektname.
     * Geprüft wird über eine capturing View, nicht über den Statuscode.
     */
    public function testCreateGroupsTemplatesGloballyFirstThenByProjectNameAlphabetically(): void
    {
        $creator = $this->createUser();
        $suffix = bin2hex(random_bytes(4));
        $projectA = $this->createProject();
        $projectA->update(['name' => "AAA_{$suffix} Chor"]);
        $projectB = $this->createProject();
        $projectB->update(['name' => "ZZZ_{$suffix} Chor"]);

        $globalTemplate = $this->createGlobalTemplate($creator);
        $templateA = $this->createProjectTemplate($projectA, $creator);
        $templateB = $this->createProjectTemplate($projectB, $creator);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $controller = $this->newsletterController();
        $view = $this->captureRenderedData($controller);

        $controller->create($this->makeRequest('GET', '/newsletters/create'), $this->makeResponse());

        $groups = $view->captured['template_groups'] ?? null;
        $this->assertIsArray($groups, 'create() muss template_groups an die View übergeben.');
        $this->assertSame('Global', $groups[0]['label'], 'Die erste Gruppe muss Global heißen.');
        $this->assertContains(
            $globalTemplate->id,
            $groups[0]['templates']->pluck('id')->all(),
            'Die globale Vorlage muss in der Global-Gruppe enthalten sein.'
        );

        $groupA = $this->findTemplateGroup($groups, $projectA->name);
        $groupB = $this->findTemplateGroup($groups, $projectB->name);

        $this->assertNotNull($groupA, 'Für Projekt A muss eine eigene Gruppe existieren.');
        $this->assertNotNull($groupB, 'Für Projekt B muss eine eigene Gruppe existieren.');
        $this->assertContains($templateA->id, $groupA['group']['templates']->pluck('id')->all());
        $this->assertContains($templateB->id, $groupB['group']['templates']->pluck('id')->all());
        $this->assertLessThan(
            $groupB['index'],
            $groupA['index'],
            'Projektgruppen müssen alphabetisch nach Projektname sortiert sein.'
        );
    }

    /**
     * edit() bekam bisher gar keine Vorlagen übergeben. Der Brief verlangt
     * dieselbe Struktur wie create(); geprüft über dieselbe capturing View.
     */
    public function testEditPassesSameGroupedTemplateStructureAsCreate(): void
    {
        $creator = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $creator);
        $globalTemplate = $this->createGlobalTemplate($creator);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $controller = $this->newsletterController();
        $view = $this->captureRenderedData($controller);

        $controller->edit(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/edit')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $groups = $view->captured['template_groups'] ?? null;
        $this->assertIsArray($groups, 'edit() muss template_groups genau wie create() an die View übergeben.');
        $this->assertSame('Global', $groups[0]['label']);
        $this->assertContains($globalTemplate->id, $groups[0]['templates']->pluck('id')->all());
    }

    /**
     * @param array<int, array{label: string, templates: \Illuminate\Support\Collection}> $groups
     * @return array{group: array{label: string, templates: \Illuminate\Support\Collection}, index: int}|null
     */
    private function findTemplateGroup(array $groups, string $label): ?array
    {
        foreach ($groups as $index => $group) {
            if ($group['label'] === $label) {
                return ['group' => $group, 'index' => $index];
            }
        }

        return null;
    }

    /**
     * Rendert create.twig über den echten Twig-Renderer und prüft am
     * tatsächlichen DOM, dass das Projektfeld optional ist: kein required-
     * Attribut mehr, dafür die Option "kein Projekt" und ohne Vorauswahl.
     */
    public function testCreateFormProjectSelectIsOptionalAndUnselectedByDefault(): void
    {
        $manager = $this->createUser();
        $this->createProject();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->create(
            $this->makeRequest('GET', '/newsletters/create'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('Projekt (optional)', $body);
        $this->assertStringContainsString('— kein Projekt —', $body);
        $this->assertFalse(
            $this->selectHasAttribute($body, 'project_id', 'required'),
            'Das Projektfeld darf nach der Entkopplung nicht mehr required sein.'
        );
        $this->assertNull(
            $this->selectedOptionValue($body, 'project_id'),
            'Ohne Projektvorgabe darf keine Option vorausgewählt sein.'
        );
    }

    /**
     * Wird create.twig mit einem konkreten Projekt aufgerufen, muss genau
     * dieses Projekt im Feld vorausgewählt sein.
     */
    public function testCreateFormPreselectsGivenProject(): void
    {
        $manager = $this->createUser();
        $project = $this->createProject();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->create(
            $this->makeRequest('GET', '/newsletters/create', [], ['project_id' => (string) $project->id]),
            $this->makeResponse()
        );

        $body = (string) $response->getBody();
        $this->assertSame((string) $project->id, $this->selectedOptionValue($body, 'project_id'));
    }

    /**
     * Belegt am gerenderten Markup, dass die Vorlagenauswahl alle Vorlagen
     * gruppiert anbietet – auch Vorlagen aus einem anderen Projekt als dem
     * gerade gewählten – und dass Global vor den Projektgruppen erscheint,
     * die wiederum alphabetisch sortiert sind.
     */
    public function testCreateFormRendersAllTemplatesGroupedIntoOptgroups(): void
    {
        $manager = $this->createUser();
        $suffix = bin2hex(random_bytes(4));
        $projectA = $this->createProject();
        $projectA->update(['name' => "AAA_{$suffix} Chor"]);
        $projectB = $this->createProject();
        $projectB->update(['name' => "ZZZ_{$suffix} Chor"]);

        $globalTemplate = $this->createGlobalTemplate($manager);
        $templateA = $this->createProjectTemplate($projectA, $manager);
        $templateB = $this->createProjectTemplate($projectB, $manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->create(
            $this->makeRequest('GET', '/newsletters/create', [], ['project_id' => (string) $projectA->id]),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('<optgroup label="Global">', $body);
        $this->assertStringContainsString('<optgroup label="' . $projectA->name . '">', $body);
        $this->assertStringContainsString('<optgroup label="' . $projectB->name . '">', $body);
        $this->assertStringContainsString($globalTemplate->name, $body);
        $this->assertStringContainsString($templateA->name, $body);
        $this->assertStringContainsString(
            $templateB->name,
            $body,
            'Die Vorlagenauswahl muss auch Vorlagen aus fremden Projekten anbieten.'
        );

        $globalPos = strpos($body, '<optgroup label="Global">');
        $posA = strpos($body, '<optgroup label="' . $projectA->name . '">');
        $posB = strpos($body, '<optgroup label="' . $projectB->name . '">');
        $this->assertNotFalse($globalPos);
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posA, $globalPos, 'Global muss vor den Projektgruppen stehen.');
        $this->assertLessThan($posB, $posA, 'Projektgruppen müssen alphabetisch sortiert sein.');
    }

    /**
     * edit.twig muss dieselbe optionale Projektauswahl bieten wie
     * create.twig: kein required, und bei einem bestehenden Projekt genau
     * dieses Projekt vorausgewählt.
     */
    public function testEditFormProjectSelectIsOptionalAndPreselectsExistingProject(): void
    {
        $manager = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->edit(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/edit')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('Projekt (optional)', $body);
        $this->assertFalse($this->selectHasAttribute($body, 'project_id', 'required'));
        $this->assertSame((string) $project->id, $this->selectedOptionValue($body, 'project_id'));
    }

    /**
     * Für einen bereits projektlosen Newsletter muss edit.twig die leere
     * Option vorauswählen, "kein Projekt" im Kopfbereich anzeigen und der
     * Zurück-Link darf keinen (nicht vorhandenen) Projektbezug mehr
     * voraussetzen.
     */
    public function testEditFormPreselectsNoProjectAndShowsPlainBackLinkWhenProjectless(): void
    {
        $manager = $this->createUser();
        $newsletter = $this->createDraft(null, $manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->edit(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/edit')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('kein Projekt', $body);
        $this->assertSame(
            '',
            $this->selectedOptionValue($body, 'project_id'),
            'Ohne Projekt muss die leere Option vorausgewählt sein.'
        );
        $this->assertStringContainsString('href="/newsletters?status=draft"', $body);
    }
}

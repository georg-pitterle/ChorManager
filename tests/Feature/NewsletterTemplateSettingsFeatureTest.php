<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterTemplateController;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
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
 * Newsletter-Vorlagen halten nicht nur den Inhalt fest, sondern auch die
 * Newsletter-Einstellungen: Kontext (Projekt), Titel und Empfängerquellen.
 */
final class NewsletterTemplateSettingsFeatureTest extends TestCase
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

    public function testSaveAsTemplateKeepsTitleContextAndRecipientSources(): void
    {
        $creator = $this->createUser();
        $project = $this->createProject();
        $role = $this->createRole();
        $_SESSION['user_id'] = $creator->id;

        $newsletter = Newsletter::create([
            'project_id' => $project->id,
            'title' => 'Probenwoche im Herbst',
            'content_html' => '<p>Hallo Chor!</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => $project->id,
        ]);
        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_ROLE,
            'reference_id' => $role->id,
        ]);

        $request = $this->makeRequest(
            'POST',
            '/newsletters/' . $newsletter->id . '/save-as-template',
            ['template_name' => 'Probenwochen-Vorlage', 'template_description' => 'Für jede Probenwoche'],
            [],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->withAttribute('id', (string) $newsletter->id);

        $response = $this->templateController()->storeFromNewsletter($request, $this->makeResponse());
        $this->assertSame(201, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $template = NewsletterTemplate::findOrFail((int) $payload['template_id']);

        $this->assertSame('Probenwochen-Vorlage', $template->name);
        $this->assertSame('Probenwoche im Herbst', $template->default_title);
        $this->assertSame($project->id, $template->project_id);
        $this->assertEqualsCanonicalizing(
            [
                NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS . ':' . $project->id,
                NewsletterTemplateRecipientSource::TYPE_ROLE . ':' . $role->id,
            ],
            $this->sourceKeys($template)
        );
    }

    public function testTemplateUpdatePersistsSettings(): void
    {
        $creator = $this->createUser();
        $project = $this->createProject();
        $role = $this->createRole();
        $_SESSION['user_id'] = $creator->id;

        $template = $this->createTemplate($creator);

        $request = $this->makeRequest('POST', '/newsletters/templates/' . $template->id, [
            'name' => 'Vorlage neu',
            'description' => 'Beschreibung',
            'default_title' => 'Newsletter im Advent',
            'content_html' => '<p>Inhalt</p>',
            'project_id' => (string) $project->id,
            'source_project_members' => [(string) $project->id],
            'source_role' => [(string) $role->id],
        ])->withAttribute('id', (string) $template->id);

        $response = $this->templateController()->update($request, $this->makeResponse());
        $this->assertSame(302, $response->getStatusCode());

        $template->refresh();
        $this->assertSame('Newsletter im Advent', $template->default_title);
        $this->assertSame($project->id, $template->project_id);
        $this->assertEqualsCanonicalizing(
            [
                NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS . ':' . $project->id,
                NewsletterTemplateRecipientSource::TYPE_ROLE . ':' . $role->id,
            ],
            $this->sourceKeys($template)
        );
    }

    public function testTemplateUpdateDropsRemovedRecipientSources(): void
    {
        $creator = $this->createUser();
        $project = $this->createProject();
        $_SESSION['user_id'] = $creator->id;

        $template = $this->createTemplate($creator);
        NewsletterTemplateRecipientSource::create([
            'template_id' => $template->id,
            'source_type' => NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => $project->id,
        ]);

        $request = $this->makeRequest('POST', '/newsletters/templates/' . $template->id, [
            'name' => $template->name,
            'description' => '',
            'content_html' => '<p>Inhalt</p>',
            'project_id' => '',
        ])->withAttribute('id', (string) $template->id);

        $this->templateController()->update($request, $this->makeResponse());

        $this->assertSame([], $this->sourceKeys($template->fresh()));
    }

    public function testShowEndpointExposesSettings(): void
    {
        $creator = $this->createUser();
        $project = $this->createProject();
        $_SESSION['user_id'] = $creator->id;

        $template = $this->createTemplate($creator);
        $template->update(['default_title' => 'Titel aus Vorlage', 'project_id' => $project->id]);
        NewsletterTemplateRecipientSource::create([
            'template_id' => $template->id,
            'source_type' => NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => $project->id,
        ]);

        $request = $this->makeRequest('GET', '/newsletters/template/' . $template->id)
            ->withAttribute('id', (string) $template->id);

        $response = $this->templateController()->show($request, $this->makeResponse());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('Titel aus Vorlage', $payload['default_title']);
        $this->assertSame($project->id, $payload['project_id']);
        $this->assertSame(
            [['type' => NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS, 'reference_id' => $project->id]],
            $payload['recipient_sources']
        );
    }

    public function testCloneCopiesSettings(): void
    {
        $creator = $this->createUser();
        $project = $this->createProject();
        $_SESSION['user_id'] = $creator->id;

        $template = $this->createTemplate($creator);
        $template->update(['default_title' => 'Titelvorschlag', 'project_id' => $project->id]);
        NewsletterTemplateRecipientSource::create([
            'template_id' => $template->id,
            'source_type' => NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => $project->id,
        ]);

        $request = $this->makeRequest('POST', '/newsletters/templates/' . $template->id . '/clone')
            ->withAttribute('id', (string) $template->id);

        $response = $this->templateController()->clone($request, $this->makeResponse());
        $this->assertSame(302, $response->getStatusCode());

        $clone = NewsletterTemplate::query()
            ->where('name', $template->name . ' (Kopie)')
            ->firstOrFail();

        $this->assertSame('Titelvorschlag', $clone->default_title);
        $this->assertSame($project->id, $clone->project_id);
        $this->assertSame(
            [NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS . ':' . $project->id],
            $this->sourceKeys($clone)
        );
    }

    public function testEditPageRendersSettingsFields(): void
    {
        $creator = $this->createUser();
        $_SESSION['user_id'] = $creator->id;
        $template = $this->createTemplate($creator);

        $request = $this->makeRequest('GET', '/newsletters/templates/' . $template->id . '/edit')
            ->withAttribute('id', (string) $template->id);

        $response = $this->templateController()->edit($request, $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertStringContainsString('name="default_title"', $html);
        $this->assertStringContainsString('name="project_id"', $html);
        $this->assertStringContainsString('name="source_project_members[]"', $html);
        $this->assertStringContainsString('name="source_event_attendees[]"', $html);
        $this->assertStringContainsString('name="source_role[]"', $html);
        $this->assertStringContainsString('name="source_user[]"', $html);
    }

    public function testCreateScriptAppliesTemplateSettings(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/js/newsletters-create.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('recipient_sources', $script);
        $this->assertStringContainsString('default_title', $script);
        $this->assertStringContainsString('applyTemplateSettings', $script);
    }

    /**
     * @return array<int, string>
     */
    private function sourceKeys(NewsletterTemplate $template): array
    {
        return $template->recipientSources()
            ->get()
            ->map(static fn (NewsletterTemplateRecipientSource $source): string => sprintf(
                '%s:%d',
                (string) $source->source_type,
                (int) $source->reference_id
            ))
            ->all();
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "template_settings_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    private function createProject(): Project
    {
        return Project::create(['name' => 'Vorlagenprojekt ' . bin2hex(random_bytes(4))]);
    }

    private function createRole(): Role
    {
        return Role::create(['name' => 'Vorlagenrolle ' . bin2hex(random_bytes(4))]);
    }

    private function createTemplate(User $creator): NewsletterTemplate
    {
        return NewsletterTemplate::create([
            'name' => 'Vorlage ' . bin2hex(random_bytes(4)),
            'description' => 'Testvorlage',
            'content_html' => '<p>Vorlageninhalt</p>',
            'project_id' => null,
            'created_by' => $creator->id,
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

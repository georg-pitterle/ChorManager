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
use App\Persistence\NewsletterTemplatePersistence;
use App\Queries\NewsletterTemplateQuery;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Services\NewsletterRecipientService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Ein versendeter Newsletter lässt sich nicht mehr bearbeiten - nachschlagen, mit welchen
 * Einstellungen er hinausging, muss aber möglich sein, und aus einem gelungenen Versand soll
 * sich eine Vorlage ziehen lassen. Beides leistet die schreibgeschützte Detail-Ansicht.
 */
final class NewsletterSentDetailsFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testDetailsShowSettingsOfSentNewsletter(): void
    {
        $manager = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createSentNewsletter($manager, $recipient);

        $project = Project::create(['name' => 'Adventkonzert ' . bin2hex(random_bytes(4))]);
        $role = Role::create(['name' => 'Notenwart ' . bin2hex(random_bytes(4))]);
        $newsletter->project_id = $project->id;
        $newsletter->save();

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_ROLE,
            'reference_id' => $role->id,
        ]);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->renderDetails((int) $newsletter->id);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Probenplan', $body);
        $this->assertStringContainsString($project->name, $body);
        $this->assertStringContainsString($role->name, $body);
        $this->assertStringContainsString('Georg Test', $body);
        $this->assertStringContainsString('Empfängerquellen', $body);
        $this->assertStringContainsString('12.09.2026', $body);
    }

    public function testDetailsOfferTemplateSavingButNoEditing(): void
    {
        $manager = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createSentNewsletter($manager, $recipient);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $body = (string) $this->renderDetails((int) $newsletter->id)->getBody();

        $this->assertStringContainsString('Als Vorlage speichern', $body);
        $this->assertStringContainsString('/newsletters/' . $newsletter->id . '/save-as-template', $body);
        $this->assertStringNotContainsString('id="content_html"', $body);
        $this->assertStringNotContainsString('/newsletters/' . $newsletter->id . '/send', $body);
        $this->assertStringNotContainsString('/newsletters/' . $newsletter->id . '/edit', $body);
    }

    public function testDetailsRejectAccessWithoutManagementRight(): void
    {
        $manager = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createSentNewsletter($manager, $recipient);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $this->assertSame(403, $this->renderDetails((int) $newsletter->id)->getStatusCode());
    }

    public function testDetailsReportUnknownNewsletter(): void
    {
        $_SESSION['user_id'] = (int) $this->createUser('Anna')->id;
        $_SESSION['can_manage_newsletters'] = true;

        $this->assertSame(404, $this->renderDetails(987654)->getStatusCode());
    }

    public function testSentNewsletterListLinksToDetails(): void
    {
        $manager = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createSentNewsletter($manager, $recipient);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('GET', '/newsletters', [], ['status' => Newsletter::STATUS_SENT]);
        $body = (string) $this->controller()->index($request, $this->makeResponse())->getBody();

        $this->assertStringContainsString('/newsletters/' . $newsletter->id . '/details?modal=1', $body);
    }

    public function testSentNewsletterCanBeStoredAsTemplate(): void
    {
        $manager = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createSentNewsletter($manager, $recipient);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest(
            'POST',
            "/newsletters/{$newsletter->id}/save-as-template",
            ['template_name' => 'Aus Versand', 'template_description' => 'Bewährter Aufbau']
        )->withAttribute('id', (string) $newsletter->id);

        $response = $this->templateController()->storeFromNewsletter($request, $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());

        $template = NewsletterTemplate::query()->where('name', 'Aus Versand')->firstOrFail();
        $this->assertSame('Probenplan', $template->default_title);

        $sources = $template->recipientSources()
            ->get()
            ->map(static fn (NewsletterTemplateRecipientSource $s): string => $s->source_type . ':' . $s->reference_id)
            ->all();

        $this->assertSame(
            [NewsletterTemplateRecipientSource::TYPE_USER . ':' . $recipient->id],
            $sources
        );
    }

    private function renderDetails(int $newsletterId): ResponseInterface
    {
        $request = $this->makeRequest('GET', "/newsletters/{$newsletterId}/details")
            ->withAttribute('id', (string) $newsletterId);

        return $this->controller()->details($request, $this->makeResponse());
    }

    private function createSentNewsletter(User $creator, User $recipient): Newsletter
    {
        $newsletter = $this->createNewsletter($creator, $recipient);
        $newsletter->status = Newsletter::STATUS_SENT;
        $newsletter->recipient_count = 1;
        $newsletter->sent_at = '2026-09-12 18:30:00';
        $newsletter->save();

        return $newsletter;
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
        $environment->addGlobal('current_path', '/newsletters');
        $this->registerMailBadgeStub($environment);
        $environment->addFunction(new TwigFunction('asset_path', static fn (string $path): string => $path));

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

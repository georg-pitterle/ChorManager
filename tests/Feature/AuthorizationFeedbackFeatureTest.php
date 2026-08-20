<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Controllers\TaskController;
use App\Policies\ProjectMemberPolicy;
use App\Policies\TaskPolicy;
use App\Services\FlashMessageService;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Rueckmeldung bei verweigertem Zugriff:
 *  - jede Abweisung landet als authz.denied im Log, auch wenn sie erst in der
 *    Policy hinter der Middleware faellt,
 *  - der Nutzer sieht sofort eine Meldung statt einer leeren 403-Seite bzw.
 *    einer Flash-Meldung, die erst beim uebernaechsten Request auftaucht.
 */
class AuthorizationFeedbackFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Capsule::table('projects')->insert(['id' => 7, 'name' => 'Frisch angelegtes Projekt']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Capsule::schema()->drop('projects');
        parent::tearDown();
    }

    public function testDeniedMemberPageIsLoggedAsAuthzDenied(): void
    {
        $_SESSION['user_id'] = 1;

        [$logger, $handler] = $this->logger();
        $policy = $this->createStub(ProjectMemberPolicy::class);
        $policy->method('canViewMembers')->willReturn(false);

        $controller = new ProjectController(
            $this->createStub(Twig::class),
            $this->createStub(\App\Queries\ProjectQuery::class),
            $this->createStub(\App\Persistence\ProjectPersistence::class),
            $policy,
            $logger
        );

        $controller->showMembers(
            $this->makeRequest('GET', '/projects/7/members'),
            $this->makeResponse(),
            ['id' => '7']
        );

        $record = $this->recordFor($handler, 'authz.denied');

        $this->assertNotNull($record);
        $this->assertSame('can_manage_project_members', $record->context['permission'] ?? null);
        $this->assertSame(7, $record->context['project_id'] ?? null);
    }

    public function testDeniedMemberPageRendersAnExplanationInsteadOfAnEmptyBody(): void
    {
        $_SESSION['user_id'] = 1;

        $policy = $this->createStub(ProjectMemberPolicy::class);
        $policy->method('canViewMembers')->willReturn(false);

        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'errors/403.twig',
                $this->callback(
                    fn(array $data): bool => isset($data['error']) && $data['error'] !== ''
                )
            )
            ->willReturnCallback(
                fn($response): ResponseInterface => $response
            );

        $controller = new ProjectController(
            $twig,
            $this->createStub(\App\Queries\ProjectQuery::class),
            $this->createStub(\App\Persistence\ProjectPersistence::class),
            $policy
        );

        $result = $controller->showMembers(
            $this->makeRequest('GET', '/projects/7/members'),
            $this->makeResponse(),
            ['id' => '7']
        );

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testDeniedPlanningPageIsLoggedAsAuthzDenied(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_tasks'] = false;

        [$logger, $handler] = $this->logger();
        $controller = new TaskController(
            $this->createStub(Twig::class),
            new HtmlSanitizer(),
            new TaskPolicy(),
            new NameFormatterService(),
            $logger
        );

        $controller->index(
            $this->makeRequest('GET', '/projects/7/tasks'),
            $this->makeResponse(),
            ['project_id' => '7']
        );

        $record = $this->recordFor($handler, 'authz.denied');

        $this->assertNotNull($record);
        $this->assertSame('can_manage_tasks', $record->context['permission'] ?? null);
        $this->assertSame(7, $record->context['project_id'] ?? null);
    }

    public function testFlashServiceConsumesMessagesSoTheyCannotResurfaceLater(): void
    {
        $_SESSION['success'] = 'Gespeichert.';
        $_SESSION['error'] = 'Zugriff verweigert.';
        $_SESSION['warning'] = 'Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}';

        $service = new FlashMessageService();
        $messages = $service->consume();

        $this->assertSame('Gespeichert.', $messages['success']);
        $this->assertSame('Zugriff verweigert.', $messages['error']);
        $this->assertSame('Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}', $messages['warning']);
        $this->assertArrayNotHasKey('success', $_SESSION);
        $this->assertArrayNotHasKey('error', $_SESSION);
        $this->assertArrayNotHasKey('warning', $_SESSION);

        $this->assertSame(['success' => null, 'error' => null, 'warning' => null], $service->consume());
    }

    private function renderFlashPartial(bool $withFlashService): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/../templates'));

        if ($withFlashService) {
            $twig->addGlobal('flash', new FlashMessageService());
        }

        return $twig->render('partials/flash.twig');
    }

    public function testFlashPartialRendersTheSessionMessageAndClearsIt(): void
    {
        $_SESSION['error'] = 'Zugriff verweigert.';

        $output = $this->renderFlashPartial(true);

        $this->assertStringContainsString('Zugriff verweigert.', $output);
        $this->assertStringContainsString('alert-danger', $output);
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testFlashPartialStaysSilentWithoutTheFlashService(): void
    {
        $_SESSION['error'] = 'Zugriff verweigert.';

        $output = $this->renderFlashPartial(false);

        $this->assertStringNotContainsString('alert', $output);
        // Ohne Dienst darf die Meldung nicht verloren gehen.
        $this->assertSame('Zugriff verweigert.', $_SESSION['error']);
    }

    public function testFlashPartialRendersTheWarningMessageAndClearsIt(): void
    {
        $_SESSION['warning'] = 'Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}';

        $output = $this->renderFlashPartial(true);

        $this->assertStringContainsString('{{tippfehler}}', $output);
        $this->assertStringContainsString('alert-warning', $output);
        $this->assertArrayNotHasKey('warning', $_SESSION);
    }

    public function testLayoutRendersFlashMessagesForPagesThatDoNotHandleThemThemselves(): void
    {
        $layout = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');
        $partial = file_get_contents(dirname(__DIR__) . '/../templates/partials/flash.twig');
        $dependencies = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');

        $this->assertStringContainsString('partials/flash.twig', $layout);
        $this->assertStringContainsString('flash_service.consume()', $partial);
        // Ohne den |default(null)-Fallback wuerde jedes Template, das ohne den
        // Container gerendert wird, an der fehlenden Global scheitern.
        $this->assertStringContainsString('flash|default(null)', $partial);
        $this->assertStringContainsString("addGlobal('flash'", $dependencies);
    }
}

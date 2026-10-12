<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorshipController;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;
use App\Services\EntityAttachmentService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class SponsoringFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /**
     * Die Sitzung startet leer, statt zu übernehmen, was eine andere Testklasse
     * im selben Prozess hinterlassen hat.
     *
     * Konkret hing daran ein Test, der nur im parallelen Lauf und nur manchmal
     * rot wurde: Ein geerbtes `$_SESSION['user_id']` zeigt auf ein Mitglied, das
     * es in dieser Datenbank nicht (mehr) gibt. Es geht als
     * `created_by_user_id` in `Sponsorship::create()`, der Fremdschlüssel weist
     * die Zeile ab, der `try`-Block schluckt die Ausnahme - und die Anhänge,
     * um die es dem Test geht, werden nie verarbeitet. Die Zusicherung fiel
     * damit über eine Ursache, die zwei Testklassen weiter lag.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testCreateSponsorshipLogsUploadRejectedForOversizedAttachmentWithoutFilename(): void
    {
        Bootstrap::setupTestDatabase();

        $sponsor = Sponsor::create(['name' => 'Upload-Ablehnungs-Test ' . bin2hex(random_bytes(4))]);

        $handlerLog = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handlerLog);
        $_SESSION['can_manage_sponsoring'] = true;
        $controller = new SponsorshipController(new SponsoringPolicy($_SESSION), new EntityAttachmentService($logger));

        $oversizedContent = str_repeat('x', (10 * 1024 * 1024) + 1);
        $stream = (new StreamFactory())->createStream($oversizedContent);
        $uploadedFile = new UploadedFile(
            $stream,
            'geheimer-vertrag.pdf',
            'application/pdf',
            strlen($oversizedContent),
            UPLOAD_ERR_OK
        );

        $request = $this->makeRequest('POST', '/sponsoring/sponsorships', [
            'sponsor_id' => (string) $sponsor->id,
            'amount' => '100',
        ])->withUploadedFiles(['attachments' => [$uploadedFile]]);

        try {
            $controller->create($request, $this->makeResponse());

            $records = $handlerLog->getRecords();
            $match = array_values(array_filter(
                $records,
                static fn ($record): bool => ($record->context['event'] ?? null) === 'security.upload.rejected'
            ));

            $this->assertNotEmpty($match);
            $this->assertSame('size_exceeded', $match[0]->context['reason']);

            foreach ($records as $record) {
                $this->assertStringNotContainsString('geheimer-vertrag', (string) json_encode($record->context));
            }
        } finally {
            Sponsorship::where('sponsor_id', $sponsor->id)->delete();
            $sponsor->delete();
        }
    }

    public function testSponsoringControllersAndMethodsExist(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\SponsoringDashboardController::class));
        $this->assertTrue(class_exists(\App\Controllers\SponsorController::class));
        $this->assertTrue(class_exists(\App\Controllers\SponsorshipController::class));
        $this->assertTrue(class_exists(\App\Controllers\SponsoringContactController::class));
        $this->assertTrue(class_exists(\App\Controllers\SponsorPackageController::class));

        $this->assertTrue(method_exists(\App\Controllers\SponsoringDashboardController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'detail'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'delete'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'delete'));
        // Ausliefern kann nur noch AttachmentController - siehe DownloadFeatureTest.
        $this->assertFalse(method_exists(\App\Controllers\SponsorshipController::class, 'downloadAttachment'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'deleteAttachment'));
        $this->assertTrue(method_exists(\App\Controllers\SponsoringContactController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\SponsoringContactController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\SponsoringContactController::class, 'markDone'));
        $this->assertTrue(method_exists(\App\Controllers\SponsoringContactController::class, 'delete'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorPackageController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorPackageController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorPackageController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorPackageController::class, 'delete'));
    }

    public function testSponsoringRoutesAndTemplatesExist(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/sponsoring'", $routesContent);
        $this->assertStringContainsString("'/sponsors'", $routesContent);
        $this->assertStringContainsString("'/sponsorships'", $routesContent);
        $this->assertStringContainsString("'/contacts'", $routesContent);
        $this->assertStringContainsString("'/contacts/{id:[0-9]+}'", $routesContent);
        $this->assertStringContainsString("SponsoringContactController::class, 'update'", $routesContent);
        $this->assertStringContainsString("'/packages'", $routesContent);

        $this->assertTrue(is_dir(dirname(__DIR__) . '/../templates/sponsoring'));
        $this->assertTrue(is_dir(dirname(__DIR__) . '/../templates/sponsoring/sponsors'));
    }

    public function testSponsorsIndexTemplateUsesResponsiveTableEngine(): void
    {
        $templatePath = dirname(__DIR__) . '/../templates/sponsoring/sponsors/index.twig';
        $templateContent = file_get_contents($templatePath);

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('data-table-engine="true"', $templateContent);
        $this->assertStringContainsString('partials/table_toolbar.twig', $templateContent);
        $this->assertStringContainsString('table-responsive-cards', $templateContent);
        $this->assertStringContainsString('data-label="Name"', $templateContent);
        $this->assertStringContainsString('data-label="Aktionen"', $templateContent);
    }

    /**
     * Das Dashboard trägt kurze Arbeitslisten, keine Datentabellen. Mit der
     * Tabellen-Engine stand dort eine Werkzeugleiste mit "Seite 1 / 1" über sechs
     * Zeilen, und am Telefon wurde jede Zeile zur Karte mit Wertepaaren - die
     * Seite war bei 390 px über 7.000 px hoch.
     */
    public function testSponsoringDashboardShowsCompactWorkLists(): void
    {
        $templatePath = dirname(__DIR__) . '/../templates/sponsoring/dashboard.twig';
        $templateContent = file_get_contents($templatePath);

        $this->assertIsString($templateContent);
        $this->assertStringNotContainsString('data-table-engine', $templateContent);
        $this->assertStringNotContainsString('partials/table_toolbar.twig', $templateContent);
        $this->assertStringContainsString('class="work-list"', $templateContent);
        $this->assertStringContainsString('Keine Wiedervorlagen in den nächsten 7 Tagen vorhanden.', $templateContent);
        $this->assertStringContainsString('sponsoring/partials/benefit_list.twig', $templateContent);
    }

    public function testSponsorDetailTemplateProvidesContactEditControls(): void
    {
        $templatePath = dirname(__DIR__) . '/../templates/sponsoring/sponsors/detail.twig';
        $templateContent = file_get_contents($templatePath);

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('data-bs-target="#editContactModal{{ contact.id }}"', $templateContent);
        $this->assertStringContainsString('id="editContactModal{{ contact.id }}"', $templateContent);
        $this->assertStringContainsString('action="/sponsoring/contacts/{{ contact.id }}"', $templateContent);
    }

    public function testSponsorshipDeleteAlsoRemovesAttachments(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SponsorshipController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('deleteAllForEntities(self::ENTITY_TYPE, [$id])', $controllerContent);

        // Aufgeräumt wird im gemeinsamen Dienst; die Bedingung muss beide
        // Spalten binden, sonst träfe sie fremde Anhänge.
        $serviceContent = file_get_contents(dirname(__DIR__) . '/../src/Services/EntityAttachmentService.php');
        $this->assertIsString($serviceContent);
        $this->assertStringContainsString("where('entity_type', " . '$' . "entityType)", $serviceContent);
        $this->assertStringContainsString("->whereIn('entity_id', " . '$' . "entityIds)", $serviceContent);
    }

    public function testSponsorshipControllerBindsUpdatesAndDeletesToPostedSponsorId(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SponsorshipController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("$" . "providedSponsorId = (int) (" . '$' . "data['sponsor_id'] ?? 0);", $controllerContent);
        $this->assertStringContainsString(
            "if (" . '$' . "providedSponsorId > 0 && " . '$' . "providedSponsorId !== (int) " . '$' . "sponsorship->sponsor_id)",
            $controllerContent
        );
        $serviceContent = file_get_contents(dirname(__DIR__) . '/../src/Services/EntityAttachmentService.php');
        $this->assertIsString($serviceContent);
        $this->assertStringContainsString("'file_size'     => " . '$' . "size", $serviceContent);
    }
}

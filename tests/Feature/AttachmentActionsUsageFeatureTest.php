<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DownloadController;
use App\Controllers\FinanceController;
use App\Controllers\SponsorController;
use App\Controllers\SponsoringAttachmentController;
use App\Controllers\SongLibraryController;
use App\Models\Attachment;
use App\Models\Finance;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Song;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Util\SponsorshipStatus;
use DI\Container;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Regressionsschutz gegen den Zustand vor dem Umbau: sechs Templates, sechs
 * verschiedene Arten, einen Anhang anzubieten. Jede Anzeigestelle wird hier
 * wirklich gerendert - mit dem echten Container, einem darstellbaren und
 * einem nicht darstellbaren Anhang - statt nur im Twig-Quelltext nach dem
 * Include-Namen zu suchen. Eine Textsuche würde grün bleiben, auch wenn ein
 * kaputtes Include nie einen Knopf zeigt; genau das ist in Task 6 schon einmal
 * passiert.
 */
final class AttachmentActionsUsageFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use FinanceAccountFixture;

    private const PREVIEWABLE_MIME = 'application/pdf';
    private const NON_PREVIEWABLE_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private ?Container $container = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function container(): Container
    {
        if ($this->container === null) {
            $containerBuilder = new ContainerBuilder();

            $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
            $settings($containerBuilder);

            $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
            $dependencies($containerBuilder);

            $this->container = $containerBuilder->build();
        }

        return $this->container;
    }

    private function makeAttachment(string $entityType, int $entityId, string $name, string $mimeType): Attachment
    {
        $content = 'Testinhalt ' . $name;

        return Attachment::create([
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'filename'      => bin2hex(random_bytes(8)) . '_' . $name,
            'original_name' => $name,
            'mime_type'     => $mimeType,
            'file_size'     => strlen($content),
            'file_content'  => $content,
        ]);
    }

    /**
     * Gemeinsame Prüfung fürs Buttonpaar: der darstellbare Anhang bekommt
     * beide Knöpfe mit allen vier Datenattributen und dem Download-Ziel der
     * zentralen Route, der nicht darstellbare nur den Download-Knopf.
     */
    private function assertActionsRenderedFor(
        string $html,
        Attachment $previewable,
        Attachment $nonPreviewable
    ): void {
        $this->assertStringContainsString('data-attachment-preview', $html);
        $this->assertStringContainsString('data-attachment-id="' . $previewable->id . '"', $html);
        $this->assertStringContainsString('data-attachment-name="' . $previewable->original_name . '"', $html);
        $this->assertStringContainsString('data-attachment-mime="' . $previewable->mime_type . '"', $html);
        $this->assertStringContainsString('data-attachment-size="' . $previewable->file_size . '"', $html);
        $this->assertStringContainsString('href="/attachments/' . $previewable->id . '/download"', $html);

        $this->assertStringNotContainsString('data-attachment-id="' . $nonPreviewable->id . '"', $html);
        $this->assertStringContainsString('href="/attachments/' . $nonPreviewable->id . '/download"', $html);
    }

    public function testPartialAttachmentsTwigRendersActionsAndKeepsTheDeleteRoute(): void
    {
        $twig = $this->container()->get(Twig::class)->getEnvironment();

        $html = $twig->render('partials/attachments.twig', [
            'attachments' => [
                [
                    'id' => 9101,
                    'original_name' => 'Noten.pdf',
                    'mime_type' => self::PREVIEWABLE_MIME,
                    'file_size' => 2048,
                    'created_at' => '2026-01-15',
                ],
                [
                    'id' => 9102,
                    'original_name' => 'Protokoll.docx',
                    'mime_type' => self::NON_PREVIEWABLE_MIME,
                    'file_size' => 4096,
                    'created_at' => '2026-01-16',
                ],
            ],
            'upload_url' => '/tasks/77/attachments',
            'delete_url' => '/tasks/77/attachments',
        ]);

        $this->assertStringContainsString('data-attachment-preview', $html);
        $this->assertStringContainsString('data-attachment-id="9101"', $html);
        $this->assertStringContainsString('href="/attachments/9101/download"', $html);
        $this->assertStringNotContainsString('data-attachment-id="9102"', $html);
        $this->assertStringContainsString('href="/attachments/9102/download"', $html);

        // Die Lösch-Route der aufrufenden Seite bleibt unverändert erreichbar.
        $this->assertStringContainsString('action="/tasks/77/attachments/9101/delete"', $html);
        $this->assertStringContainsString('action="/tasks/77/attachments/9102/delete"', $html);

        $this->assertStringNotContainsString('/downloads/attachments/', $html);
    }

    public function testFinancesIndexRendersActionsInTheAttachmentDropdown(): void
    {
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.01.']);

        $finance = Finance::create([
            'running_number' => 88001,
            'invoice_date' => '2026-06-01',
            'payment_date' => '2026-06-15',
            'description' => 'Beleg-Test',
            'type' => 'expense',
            'amount' => '42.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->fixtureAccountId(),
        ]);
        $previewable = $this->makeAttachment('finance', (int) $finance->id, 'Beleg.pdf', self::PREVIEWABLE_MIME);
        $nonPreviewable = $this->makeAttachment('finance', (int) $finance->id, 'Vermerk.docx', self::NON_PREVIEWABLE_MIME);

        try {
            $controller = $this->container()->get(FinanceController::class);
            $response = $controller->index(
                $this->makeRequest('GET', '/finances', [], ['year' => '2026']),
                $this->makeResponse()
            );
            $html = (string) $response->getBody();

            $this->assertActionsRenderedFor($html, $previewable, $nonPreviewable);

            // Der frühere direkte Link auf den Anhang kommt in dieser Tabelle
            // nirgends mehr vor - eine Lösch-Route gibt es in dieser Ansicht
            // gar nicht, die Prüfung kann also den ganzen Pfad ausschließen.
            $this->assertStringNotContainsString('/finances/attachments/', $html);
        } finally {
            $previewable->delete();
            $nonPreviewable->delete();
            $finance->delete();
        }
    }

    /**
     * Die Tabelle "Offene Posten" auf derselben Seite zeigt Buchungen ohne
     * Zahldatum. Sie hatte nie eine Anhangsspalte, obwohl der Controller die
     * Anhänge längst mitlädt - ein Beleg an einer offenen Rechnung war damit
     * aus der Übersicht heraus unerreichbar.
     */
    public function testFinancesOpenItemsRenderActionsInTheAttachmentDropdown(): void
    {
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.01.']);

        $finance = Finance::create([
            'running_number' => 88002,
            'invoice_date' => '2026-06-01',
            'payment_date' => null,
            'description' => 'Offener Beleg-Test',
            'type' => 'expense',
            'amount' => '17.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->fixtureAccountId(),
        ]);
        $previewable = $this->makeAttachment('finance', (int) $finance->id, 'Offen.pdf', self::PREVIEWABLE_MIME);
        $nonPreviewable = $this->makeAttachment('finance', (int) $finance->id, 'Offen.docx', self::NON_PREVIEWABLE_MIME);

        try {
            $controller = $this->container()->get(FinanceController::class);
            $response = $controller->index(
                $this->makeRequest('GET', '/finances', [], ['year' => '2026']),
                $this->makeResponse()
            );
            $html = (string) $response->getBody();

            // Ohne Zahldatum steht die Buchung ausschließlich in "Offene
            // Posten" - was hier ankommt, kann also nur von dort stammen.
            $this->assertStringContainsString('Offener Beleg-Test', $html);
            $this->assertActionsRenderedFor($html, $previewable, $nonPreviewable);
            $this->assertStringNotContainsString('/finances/attachments/', $html);
        } finally {
            $previewable->delete();
            $nonPreviewable->delete();
            $finance->delete();
        }
    }

    public function testSponsoringAttachmentsOverviewRendersActionsForBothSources(): void
    {
        $_SESSION['can_manage_sponsoring'] = true;

        $sponsor = Sponsor::create(['name' => 'Übersicht-Test ' . bin2hex(random_bytes(4))]);
        $sponsorship = Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '100.00',
            'status' => SponsorshipStatus::ACCEPTED,
        ]);

        // Ein Anhang je Herkunft: das deckt zugleich beide Mapping-Methoden des
        // Controllers ab und die darstellbare/nicht darstellbare Unterscheidung.
        $previewable = $this->makeAttachment('sponsor', (int) $sponsor->id, 'Logo.pdf', self::PREVIEWABLE_MIME);
        $nonPreviewable = $this->makeAttachment(
            'sponsorship',
            (int) $sponsorship->id,
            'Vertrag.docx',
            self::NON_PREVIEWABLE_MIME
        );

        try {
            $controller = $this->container()->get(SponsoringAttachmentController::class);
            $response = $controller->index($this->makeRequest('GET', '/sponsoring/attachments'), $this->makeResponse());
            $html = (string) $response->getBody();

            $this->assertActionsRenderedFor($html, $previewable, $nonPreviewable);

            // Die Übersicht hat keine Lösch-Formulare - der ganze alte Pfad
            // darf hier komplett verschwunden sein.
            $this->assertStringNotContainsString(
                '/sponsoring/sponsors/' . $sponsor->id . '/attachments/',
                $html
            );
            $this->assertStringNotContainsString(
                '/sponsoring/sponsorships/' . $sponsorship->id . '/attachments/',
                $html
            );
        } finally {
            $previewable->delete();
            $nonPreviewable->delete();
            $sponsorship->delete();
            $sponsor->delete();
        }
    }

    public function testSponsorDetailRendersActionsForSponsorAndAgreementAttachments(): void
    {
        $_SESSION['can_manage_sponsoring'] = true;

        $sponsor = Sponsor::create(['name' => 'Detail-Test ' . bin2hex(random_bytes(4))]);
        $sponsorship = Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '250.00',
            'status' => SponsorshipStatus::ACCEPTED,
        ]);

        $sponsorPreviewable = $this->makeAttachment('sponsor', (int) $sponsor->id, 'Logo.pdf', self::PREVIEWABLE_MIME);
        $sponsorNonPreviewable = $this->makeAttachment(
            'sponsor',
            (int) $sponsor->id,
            'Notiz.docx',
            self::NON_PREVIEWABLE_MIME
        );
        $agreementPreviewable = $this->makeAttachment(
            'sponsorship',
            (int) $sponsorship->id,
            'Vertrag.pdf',
            self::PREVIEWABLE_MIME
        );
        $agreementNonPreviewable = $this->makeAttachment(
            'sponsorship',
            (int) $sponsorship->id,
            'Anhang.docx',
            self::NON_PREVIEWABLE_MIME
        );

        try {
            $controller = $this->container()->get(SponsorController::class);
            $response = $controller->detail(
                $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsor->id),
                $this->makeResponse(),
                ['id' => (string) $sponsor->id]
            );
            $html = (string) $response->getBody();

            $this->assertActionsRenderedFor($html, $sponsorPreviewable, $sponsorNonPreviewable);
            $this->assertActionsRenderedFor($html, $agreementPreviewable, $agreementNonPreviewable);

            // Die alten Namenslinks sind weg, die Lösch-Routen mit demselben
            // Pfadanfang bleiben stehen - genau deshalb wird hier gezielt auf
            // href (Lesen) bzw. action (Löschen) geprüft statt auf den bloßen
            // Pfad.
            foreach ([$sponsorPreviewable, $sponsorNonPreviewable] as $att) {
                $this->assertStringNotContainsString(
                    'href="/sponsoring/sponsors/' . $sponsor->id . '/attachments/' . $att->id . '"',
                    $html
                );
                $this->assertStringContainsString(
                    'action="/sponsoring/sponsors/' . $sponsor->id . '/attachments/' . $att->id . '/delete"',
                    $html
                );
            }
            foreach ([$agreementPreviewable, $agreementNonPreviewable] as $att) {
                $this->assertStringNotContainsString(
                    'href="/sponsoring/sponsorships/' . $sponsorship->id . '/attachments/' . $att->id . '"',
                    $html
                );
                $this->assertStringContainsString(
                    'action="/sponsoring/sponsorships/' . $sponsorship->id . '/attachments/' . $att->id . '/delete"',
                    $html
                );
            }
        } finally {
            $sponsorPreviewable->delete();
            $sponsorNonPreviewable->delete();
            $agreementPreviewable->delete();
            $agreementNonPreviewable->delete();
            $sponsorship->delete();
            $sponsor->delete();
        }
    }

    public function testSongsDetailRendersActionsForSongAttachments(): void
    {
        $song = Song::create(['title' => 'Anzeigestellen-Test ' . bin2hex(random_bytes(4))]);
        $previewable = $this->makeAttachment('song', (int) $song->id, 'Partitur.pdf', self::PREVIEWABLE_MIME);
        $nonPreviewable = $this->makeAttachment('song', (int) $song->id, 'Vermerk.docx', self::NON_PREVIEWABLE_MIME);

        try {
            $controller = $this->container()->get(SongLibraryController::class);
            $response = $controller->show(
                $this->makeRequest('GET', '/song-library/songs/' . $song->id),
                $this->makeResponse(),
                ['id' => (string) $song->id]
            );
            $html = (string) $response->getBody();

            $this->assertActionsRenderedFor($html, $previewable, $nonPreviewable);
            $this->assertStringNotContainsString('/downloads/attachments/', $html);

            // Die Lösch-Route der Seite bleibt unverändert erreichbar.
            $this->assertStringContainsString(
                '/song-library/songs/' . $song->id . '/attachments/' . $previewable->id . '/delete',
                $html
            );
        } finally {
            $previewable->delete();
            $nonPreviewable->delete();
            $song->delete();
        }
    }

    public function testDownloadsPageKeepsItsEmbeddedPlayersAndRendersActions(): void
    {
        $user = User::create([
            'first_name' => 'Downloads',
            'last_name' => 'Test',
            'email' => 'downloads.test.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $project = Project::create(['name' => 'Downloads-Test-Projekt ' . bin2hex(random_bytes(4))]);
        $song = Song::create(['title' => 'Downloads-Test-Lied ' . bin2hex(random_bytes(4))]);

        $project->users()->attach($user->id);
        $project->assignedSongs()->attach($song->id, ['created_at' => date('Y-m-d H:i:s')]);

        $mp3 = $this->makeAttachment('song', (int) $song->id, 'Stimme.mp3', 'audio/mpeg');
        $midi = $this->makeAttachment('song', (int) $song->id, 'Begleitung.mid', 'audio/midi');
        $nonPreviewable = $this->makeAttachment('song', (int) $song->id, 'Noten.docx', self::NON_PREVIEWABLE_MIME);

        $_SESSION['user_id'] = (int) $user->id;

        try {
            $controller = $this->container()->get(DownloadController::class);
            $response = $controller->index($this->makeRequest('GET', '/downloads'), $this->makeResponse());
            $html = (string) $response->getBody();

            $this->assertActionsRenderedFor($html, $mp3, $nonPreviewable);

            // Die eingebetteten Player bleiben und ziehen ihre Quelle künftig
            // von der zentralen Vorschau-Route statt der alten Stream-Route.
            $this->assertStringContainsString('<audio controls', $html);
            $this->assertStringContainsString('<midi-player', $html);
            $this->assertStringContainsString('src="/attachments/' . $mp3->id . '/preview"', $html);
            $this->assertStringContainsString('src="/attachments/' . $midi->id . '/preview"', $html);

            // MIDI ist inline auslieferbar, aber nicht im Modal darstellbar -
            // konsequent bekommt es keinen Vorschau-Knopf, nur den Download.
            $this->assertStringNotContainsString('data-attachment-id="' . $midi->id . '"', $html);
            $this->assertStringContainsString('href="/attachments/' . $midi->id . '/download"', $html);

            $this->assertStringNotContainsString('/downloads/attachments/', $html);

            // Echte Umlaute statt der alten Transliterationen.
            $this->assertStringContainsString('Größe', $html);
            $this->assertStringNotContainsString('Groesse', $html); // naming:ascii Vergleich gegen die frühere Transliteration
            $this->assertStringContainsString('Dein Browser unterstützt kein Audio-Playback.', $html);
        } finally {
            $mp3->delete();
            $midi->delete();
            $nonPreviewable->delete();
            $project->assignedSongs()->detach($song->id);
            $project->users()->detach($user->id);
            $song->delete();
            $project->delete();
            $user->delete();
        }
    }

    /**
     * Die Übersicht baut ihre Zeilen aus zwei privaten Zuordnungsmethoden.
     * Eine Reflexion auf das echte Ergebnis prüft strenger als eine Textsuche
     * im Quelltext: sie fiele nicht herein, wenn der Schlüssel unter anderem
     * Namen weiterlebte.
     */
    public function testSponsoringAttachmentControllerNoLongerBuildsADownloadUrl(): void
    {
        $_SESSION['can_manage_sponsoring'] = true;

        $sponsor = Sponsor::create(['name' => 'Reflexions-Test ' . bin2hex(random_bytes(4))]);
        $sponsorship = Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '10.00',
            'status' => SponsorshipStatus::ACCEPTED,
        ]);
        $sponsorAttachment = $this->makeAttachment('sponsor', (int) $sponsor->id, 'a.pdf', self::PREVIEWABLE_MIME);
        $sponsorshipAttachment = $this->makeAttachment(
            'sponsorship',
            (int) $sponsorship->id,
            'b.pdf',
            self::PREVIEWABLE_MIME
        );

        try {
            $controller = new SponsoringAttachmentController($this->createStub(Twig::class), new SponsoringPolicy());

            $mapSponsorAttachment = new ReflectionMethod(SponsoringAttachmentController::class, 'mapSponsorAttachment');
            $sponsorRow = $mapSponsorAttachment->invoke($controller, $sponsorAttachment, $sponsor);

            $mapSponsorshipAttachment = new ReflectionMethod(
                SponsoringAttachmentController::class,
                'mapSponsorshipAttachment'
            );
            $sponsorshipRow = $mapSponsorshipAttachment->invoke($controller, $sponsorshipAttachment, $sponsorship);

            $this->assertIsArray($sponsorRow);
            $this->assertArrayNotHasKey('download_url', $sponsorRow);
            $this->assertIsArray($sponsorshipRow);
            $this->assertArrayNotHasKey('download_url', $sponsorshipRow);
        } finally {
            $sponsorAttachment->delete();
            $sponsorshipAttachment->delete();
            $sponsorship->delete();
            $sponsor->delete();
        }
    }
}

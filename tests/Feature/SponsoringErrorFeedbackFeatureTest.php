<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorPackageController;
use App\Controllers\SponsorshipController;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\ArrayLoader;

/**
 * Antwort auf Punkt 5 aus dem Review-Lauf 25.
 *
 * Sechs catch-Blöcke in SponsorshipController und SponsorPackageController
 * fingen die Ausnahme, verwarfen sie und setzten "Fehler beim Anlegen: " - mit
 * Doppelpunkt und nichts dahinter. Der Rumpf hinter dem Doppelpunkt war
 * irgendwann entfernt worden, weil dort der rohe SQLSTATE-Text des Treibers
 * stand (siehe ReviewAnswersRun21FeatureTest); der Doppelpunkt blieb.
 *
 * Beide Controller kannten keinen Logger. Ein echter Schreibfehler hinterließ
 * damit nirgends eine Spur, entgegen instructions/logging.md. Genau dieser
 * blinde Fleck ließ den Reihenfolgen-Fehler in SponsoringFeatureTest so lange
 * unerklärlich aussehen: Die Ausnahme des Fremdschlüssels verschwand
 * spurlos.
 *
 * Dieselbe Aufteilung wie in EventTypeController und VoiceGroupController
 * (MasterDataDeleteFeedbackFeatureTest): Eine fehlende Zeile ist der Wettlauf
 * zweier offener Seiten und benennt sich selbst, ohne Protokolleintrag. Alles
 * andere ist ein Betriebsfehler und wird geloggt - der Grund gehört ins
 * Protokoll, nicht vor die Augen des Sponsoring-Teams.
 */
class SponsoringErrorFeedbackFeatureTest extends TestCase
{
    use TestHttpHelpers;

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

    private function emptyTwig(): Twig
    {
        // Die geprüften Methoden leiten nur weiter und rendern nichts.
        return new Twig(new ArrayLoader([]));
    }

    /**
     * Ein Schreibfehler, den kein Formular abfangen kann: Die Sitzung zeigt auf
     * ein Mitglied, das es nicht (mehr) gibt. Die Kennung geht als
     * `created_by_user_id` in die Zeile, der Fremdschlüssel weist sie ab.
     */
    public function testFehlgeschlagenesAnlegenWirdProtokolliertUndMeldetSichVerstaendlich(): void
    {
        $sponsor = Sponsor::create(['name' => 'Protokoll-Test ' . bin2hex(random_bytes(4))]);

        try {
            $_SESSION['can_manage_sponsoring'] = true;
            $_SESSION['user_id'] = 999999999;

            [$logger, $handler] = $this->logger();
            $controller = new SponsorshipController(
                new SponsoringPolicy(),
                $this->attachmentService(),
                $logger
            );

            $controller->create(
                $this->makeRequest('POST', '/sponsoring/sponsorships', [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '100',
                ]),
                $this->makeResponse()
            );

            $message = (string) ($_SESSION['error'] ?? '');

            $this->assertNotSame('', $message, 'Der Fehlschlag muss sich melden.');
            $this->assertStringEndsNotWith(': ', $message, 'Kein Doppelpunkt ohne Fortsetzung.');
            $this->assertStringNotContainsString(
                'SQLSTATE',
                $message,
                'Der Treibertext gehört ins Protokoll, nicht in die Oberfläche.'
            );

            $record = $this->recordFor($handler, 'sponsorship.create.failed');
            $this->assertNotNull($record, 'Ein echter Schreibfehler gehört ins Protokoll.');
            $this->assertArrayHasKey(
                'exception',
                $record->context,
                'instructions/logging.md verlangt die Ausnahme unter dem Schlüssel "exception".'
            );
        } finally {
            Sponsorship::where('sponsor_id', $sponsor->id)->delete();
            $sponsor->delete();
        }
    }

    /**
     * Die häufigste Ursache: Die Seite lag offen, während jemand anderes
     * dasselbe Paket entfernt hat. Kein Betriebsfehler, also kein Protokoll.
     */
    public function testUnbekanntesPaketMeldetSichBenennendOhneProtokolleintrag(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new SponsorPackageController($this->emptyTwig(), new SponsoringPolicy(), $logger);

        $result = $controller->update(
            $this->makeRequest('POST', '/sponsoring/packages/0/update', [
                'name' => 'Irgendein Paket',
                'min_amount' => '10',
            ]),
            $this->makeResponse(),
            ['id' => '0']
        );

        $message = (string) ($_SESSION['error'] ?? '');

        $this->assertRedirect($result, '/sponsoring/packages');
        $this->assertNotSame('', $message);
        $this->assertStringEndsNotWith(': ', $message);
        $this->assertStringContainsString('bereits gelöscht', $message);
        $this->assertFalse($this->hasEvent($handler, 'sponsor_package.update.failed'));
    }

    public function testUnbekanntePaketLoeschungMeldetSichBenennendOhneProtokolleintrag(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new SponsorPackageController($this->emptyTwig(), new SponsoringPolicy(), $logger);

        $result = $controller->delete(
            $this->makeRequest('POST', '/sponsoring/packages/0/delete'),
            $this->makeResponse(),
            ['id' => '0']
        );

        $message = (string) ($_SESSION['error'] ?? '');

        $this->assertRedirect($result, '/sponsoring/packages');
        $this->assertStringEndsNotWith(': ', $message);
        $this->assertStringContainsString('bereits gelöscht', $message);
        $this->assertFalse($this->hasEvent($handler, 'sponsor_package.delete.failed'));
    }

    /**
     * Keine der sechs Meldungen endet noch auf einen Doppelpunkt ohne
     * Fortsetzung. Der Quelltext ist hier die ehrlichere Quelle als sechs
     * nachgestellte Fehlschläge: Drei davon bräuchten einen Treiberfehler,
     * den kein Test verlässlich herbeiführt.
     */
    public function testKeineSponsoringMeldungEndetAufEinemBlankenDoppelpunkt(): void
    {
        foreach (['SponsorshipController', 'SponsorPackageController'] as $class) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/' . $class . '.php');

            $this->assertSame(
                0,
                preg_match_all("/'[^']*: '\s*;/", $source),
                $class . ' trägt noch eine Meldung, die auf ": " endet.'
            );
        }
    }
}

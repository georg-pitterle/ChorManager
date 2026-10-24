<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Controllers\SponsoringDashboardController;
use App\Controllers\SponsorPackageController;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\SponsorPackage;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use App\Util\SponsorEngagementState;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Sponsoring-Seiten nach der Kritik vom Oktober 2026: Dashboard als
 * Arbeitsliste, Sponsor-Detail als Lesefolge statt Formular hinter Tabs,
 * lesbare Abzeichen und eine gemeinsame Unternavigation.
 */
final class SponsoringPagesFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private User $manager;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        // Andere Tests hinterlassen keine Sponsoring-Zeilen, die Summen hier
        // sollen aber nur die eigenen Daten zählen.
        Sponsorship::query()->delete();
        Sponsor::query()->delete();
        SponsoringContact::query()->delete();

        $this->manager = $this->createUser();
        $this->reader = $this->createUser();
        $this->loginAsManager();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    // ---- Sponsor-Detail ----------------------------------------------------

    public function testTheDetailPageReadsTopToBottomWithoutTabs(): void
    {
        $sponsor = $this->makeSponsor();
        $this->makeSponsorship($sponsor, SponsorshipStatus::ACCEPTED);

        $html = $this->renderDetail($sponsor);

        $this->assertStringNotContainsString('nav-tabs', $html);
        $this->assertStringNotContainsString('tab-pane', $html);
        // Die Vereinbarungen stehen vor den Stammdaten - sie sind der Grund des Besuchs.
        $this->assertLessThan(
            strpos($html, 'id="sponsor-attachments-title"'),
            strpos($html, 'id="sponsor-agreements-title"')
        );
    }

    /**
     * Wer fremde Stammdaten nicht ändern darf, bekam bisher ein offenes Formular
     * ohne Speichern-Knopf und merkte das erst nach dem Tippen.
     */
    public function testAReaderSeesTheMasterDataAsTextNotAsAForm(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsor->update(['contact_person' => 'Erika Muster', 'phone' => '+43 512 123456']);

        $this->loginAsReader();
        $html = $this->renderDetail($sponsor);

        $this->assertStringContainsString('Erika Muster', $html);
        $this->assertStringContainsString('href="tel:+43512123456"', $html);
        $this->assertStringNotContainsString('name="contact_person"', $html);
        $this->assertStringNotContainsString('id="editSponsorModal"', $html);
    }

    public function testTheEditorEditsTheMasterDataInADialog(): void
    {
        $sponsor = $this->makeSponsor();

        $html = $this->renderDetail($sponsor);

        $this->assertStringContainsString('id="editSponsorModal"', $html);
        $this->assertStringContainsString('name="contact_person"', $html);
    }

    public function testAnAgreementShowsItsBenefitProgressAndTickButtons(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, SponsorshipStatus::ACCEPTED);
        $done = $sponsorship->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0]);
        $done->update(['delivered_at' => '2026-09-01']);
        $open = $sponsorship->benefits()->create(['description' => '2 Freikarten', 'sort_order' => 1]);

        $html = $this->renderDetail($sponsor);

        $this->assertStringContainsString('1 von 2 erbracht', $html);
        $this->assertStringContainsString('action="/sponsoring/sponsorship-benefits/' . $open->id . '/toggle"', $html);

        $this->loginAsReader();
        $readerHtml = $this->renderDetail($sponsor);
        $this->assertStringContainsString('2 Freikarten', $readerHtml);
        $this->assertStringNotContainsString('/sponsorship-benefits/' . $open->id . '/toggle', $readerHtml);
    }

    /**
     * Twig fragt bei einer leeren Relation die gleichnamige Methode ab und bekommt
     * ein Relationsobjekt, das als wahr gilt. Mit `sp.project ? ...` blieb der
     * Titel einer Vereinbarung ohne Projekt deshalb leer.
     */
    public function testAnAgreementWithoutProjectIsTitledAsSuch(): void
    {
        $sponsor = $this->makeSponsor();
        $this->makeSponsorship($sponsor, SponsorshipStatus::REQUESTED);

        $this->assertStringContainsString(
            '<h3 class="agreement-title">Ohne Projekt</h3>',
            $this->renderDetail($sponsor)
        );
    }

    public function testAnAcceptedButUnpaidAgreementSaysSo(): void
    {
        $sponsor = $this->makeSponsor();
        $this->makeSponsorship($sponsor, SponsorshipStatus::ACCEPTED);

        $this->assertStringContainsString('Zahlung offen', $this->renderDetail($sponsor));
    }

    // ---- Sponsorenliste -----------------------------------------------------

    /**
     * Die Liste zeigte Telefon und E-Mail, aber weder Paket noch Frist noch
     * Betrag - wer viele Sponsoren betreut, musste jeden einzeln öffnen.
     */
    public function testTheSponsorListShowsPackageNextDeadlineAndCommitment(): void
    {
        $package = SponsorPackage::create(['name' => 'Gold Liste', 'min_amount' => '1000', 'color' => 'warning']);
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, SponsorshipStatus::ACCEPTED);
        $sponsorship->update(['package_id' => $package->id]);
        $this->makeFollowUp($sponsor, '+2 days');

        $row = $this->sponsorIndexData()['sponsor_rows'][$sponsor->id];

        $this->assertSame('Gold Liste', $row['package_name']);
        $this->assertSame('text-bg-warning', $row['package_badge']);
        $this->assertSame(date('d.m.Y', strtotime('+2 days')), $row['next_follow_up_display']);
        $this->assertSame(1200.0, $row['accepted_amount']);

        $this->loginAsReader();
        $this->assertNull($this->sponsorIndexData()['sponsor_rows'][$sponsor->id]['accepted_amount']);
    }

    // ---- Abzeichen und Pakete ----------------------------------------------

    /**
     * Weiß auf Gelb ("Anfrage läuft") kam auf 1,63:1. text-bg-* wählt die
     * Schriftfarbe zum Hintergrund.
     */
    public function testStateBadgesCarryAReadableTextColor(): void
    {
        foreach (SponsorEngagementState::badgeClasses() as $state => $classes) {
            $this->assertStringStartsWith('text-bg-', $classes, $state);
        }
    }

    public function testThePackagePageNamesColorsInPlainGerman(): void
    {
        SponsorPackage::create(['name' => 'Gold Test', 'min_amount' => '2500', 'color' => 'warning']);

        $response = (new SponsorPackageController(
            $this->createAppTwig('/sponsoring/packages'),
            new SponsoringPolicy($_SESSION)
        ))->index($this->makeRequest('GET', '/sponsoring/packages'), $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertStringContainsString('text-bg-warning', $html);
        $this->assertStringContainsString('>Gold</option>', $html);
        $this->assertStringNotContainsString('>warning<', $html);
        $this->assertStringNotContainsString('class="badge bg-', $html);
    }

    /**
     * Jede Teilseite verlinkte andere Geschwister; jetzt tragen alle dieselbe
     * Unternavigation.
     */
    public function testEverySponsoringPageCarriesTheSharedSubNavigation(): void
    {
        $templates = [
            'dashboard.twig',
            'sponsors/index.twig',
            'packages/index.twig',
            'attachments/index.twig',
        ];

        foreach ($templates as $template) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/templates/sponsoring/' . $template);
            $this->assertIsString($source);
            $this->assertStringContainsString('sponsoring/partials/subnav.twig', $source, $template);
        }
    }

    /**
     * Auf der Seite eines einzelnen Sponsors wirkten die Reiter des Bereichs
     * (Übersicht, Sponsoren, Pakete, Anhänge) wie Reiter dieses Sponsors -
     * "Anhänge" schien zu seinen Anhängen zu führen, die weit unten stehen.
     * Dort führt jetzt die Brotkrumenleiste zurück, und eine Sprungleiste
     * erreicht die eigenen Abschnitte.
     */
    public function testTheSponsorPageNavigatesItsOwnSectionsInsteadOfTheAreaTabs(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/sponsoring/sponsors/detail.twig');

        $this->assertStringNotContainsString('sponsoring/partials/subnav.twig', $source);
        // Zurück wie auf der Termin-Detailseite: ein Knopf "Zur Liste", keine
        // Brotkrumen - die Seite liegt nur eine Ebene unter der Liste.
        $this->assertStringNotContainsString('class="breadcrumb', $source);
        $this->assertMatchesRegularExpression(
            '#<a href="/sponsoring/sponsors"\s+class="btn btn-outline-secondary"[^>]*>\s*<i class="bi bi-arrow-left[^"]*"[^>]*></i>\s*Zur Liste\s*</a>#',
            $source
        );
        $this->assertStringContainsString('class="sponsor-section-nav"', $source);
        foreach (['sponsor-agreements-title', 'sponsor-contacts-title', 'sponsor-logo-title', 'sponsor-attachments-title'] as $anchor) {
            $this->assertStringContainsString('anchor: "' . $anchor . '"', $source, $anchor);
            $this->assertStringContainsString('id="' . $anchor . '"', $source, $anchor);
        }
    }

    // ---- Dashboard ---------------------------------------------------------

    public function testTheDashboardListsOpenBenefitsOfAcceptedAgreementsOnly(): void
    {
        $sponsor = $this->makeSponsor();
        $accepted = $this->makeSponsorship($sponsor, SponsorshipStatus::ACCEPTED);
        $accepted->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0])
            ->update(['delivered_at' => '2026-09-01']);
        $accepted->benefits()->create(['description' => 'Banner im Saal', 'sort_order' => 1]);
        $requested = $this->makeSponsorship($sponsor, SponsorshipStatus::REQUESTED);
        $requested->benefits()->create(['description' => 'Nur angefragt', 'sort_order' => 0]);

        $data = $this->dashboardData();

        $this->assertCount(1, $data['open_benefits']);
        $this->assertSame(['Banner im Saal'], array_column($data['open_benefits'][0]['benefits'], 'description'));
        $this->assertTrue($data['open_benefits'][0]['benefits'][0]['may_tick']);
    }

    public function testUnpaidCommitmentsAreListedForTheSponsoringTeamOnly(): void
    {
        $sponsor = $this->makeSponsor();
        $this->makeSponsorship($sponsor, SponsorshipStatus::ACCEPTED);
        $paid = $this->makeSponsorship($sponsor, SponsorshipStatus::ACCEPTED);
        $paid->update(['paid_at' => '2026-09-01']);

        // Eine reine Sachleistung (0 €) wartet auf kein Geld.
        $this->makeSponsorship($sponsor, SponsorshipStatus::ACCEPTED)->update(['amount' => '0.00']);

        $this->assertCount(1, $this->dashboardData()['unpaid']);

        $this->loginAsReader();
        $this->assertSame([], $this->dashboardData()['unpaid']);
    }

    public function testFollowUpsSayHowDueTheyAre(): void
    {
        $sponsor = $this->makeSponsor();
        $this->makeFollowUp($sponsor, '-2 days');
        $this->makeFollowUp($sponsor, 'today');
        $this->makeFollowUp($sponsor, '+3 days');

        $labels = array_column($this->dashboardData()['upcoming_follow_ups'], 'due_label');

        $this->assertSame(['seit 2 Tagen überfällig', 'heute fällig', 'in 3 Tagen'], $labels);
    }

    public function testTheDashboardSpeaksPlainGerman(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/sponsoring/dashboard.twig');
        $this->assertIsString($source);

        $this->assertStringNotContainsString('Pipeline', $source);
        $this->assertStringNotContainsString(' EUR', $source);
    }

    // ---- Helfer ------------------------------------------------------------

    private function renderDetail(Sponsor $sponsor): string
    {
        $response = (new SponsorController(
            $this->createAppTwig('/sponsoring/sponsors'),
            new SponsoringPolicy($_SESSION),
            $this->attachmentService()
        ))->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsor->id),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** @return array<string, mixed> */
    private function sponsorIndexData(): array
    {
        $captured = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;
                return $response;
            }
        );

        (new SponsorController($twig, new SponsoringPolicy($_SESSION), $this->attachmentService()))
            ->index($this->makeRequest('GET', '/sponsoring/sponsors'), $this->makeResponse());

        return $captured;
    }

    /** @return array<string, mixed> */
    private function dashboardData(): array
    {
        $captured = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;
                return $response;
            }
        );

        (new SponsoringDashboardController($twig, new NameFormatterService(), new SponsoringPolicy($_SESSION)))
            ->index($this->makeRequest('GET', '/sponsoring'), $this->makeResponse());

        return $captured;
    }

    private function makeFollowUp(Sponsor $sponsor, string $when): void
    {
        SponsoringContact::create([
            'sponsor_id' => $sponsor->id,
            'user_id' => $this->manager->id,
            'contact_date' => date('Y-m-d'),
            'type' => 'call',
            'summary' => 'Nachfassen ' . $when,
            'follow_up_date' => date('Y-m-d', strtotime($when)),
            'follow_up_done' => 0,
        ]);
    }

    private function loginAsManager(): void
    {
        $_SESSION = [
            'user_id' => (int) $this->manager->id,
            'can_manage_sponsoring' => true,
            'can_create_own_sponsorships' => false,
        ];
    }

    private function loginAsReader(): void
    {
        $_SESSION = [
            'user_id' => (int) $this->reader->id,
            'can_manage_sponsoring' => false,
            'can_create_own_sponsorships' => true,
        ];
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Seiten',
            'last_name' => 'Testperson',
            'email' => 'sponsoring-pages-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }

    private function makeSponsor(): Sponsor
    {
        return Sponsor::create([
            'name' => 'Seitentest ' . bin2hex(random_bytes(4)),
            'created_by_user_id' => $this->manager->id,
        ]);
    }

    private function makeSponsorship(Sponsor $sponsor, string $status): Sponsorship
    {
        return Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '1200.00',
            'status' => $status,
            'created_by_user_id' => $this->manager->id,
        ]);
    }
}

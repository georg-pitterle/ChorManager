<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Controllers\SponsorPackageController;
use App\Controllers\SponsorshipController;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\SponsorPackageBenefit;
use App\Models\Sponsorship;
use App\Models\SponsorshipBenefit;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Gegenleistungen und Zahlungsstand: Was hat der Verein dem Sponsor versprochen,
 * was davon ist erbracht, und ist das Geld da?
 *
 * Vorher stand das höchstens in den Notizen einer Vereinbarung, und die zeigte
 * nur das Bearbeiten-Fenster - wer kein Verwaltungsrecht hatte, fand sie gar
 * nicht. Die Frage vor jedem Konzert ("Welche Logos müssen ins Programmheft?")
 * ließ sich mit der Oberfläche nicht beantworten.
 */
final class SponsoringBenefitsFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private User $manager;
    private User $contributor;
    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->manager = $this->createUser();
        $this->contributor = $this->createUser();
        $this->assignee = $this->createUser();
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

    public function testAPackageStoresItsBenefitsOnePerLineInOrder(): void
    {
        $response = $this->packageController()->create($this->makeRequest('POST', '/sponsoring/packages', [
            'name' => 'Gold Test',
            'min_amount' => '2500',
            'color' => 'warning',
            'benefits' => "Logo im Programmheft\n\n  2 Freikarten  \r\nNennung bei der Ansage",
        ]), $this->makeResponse());

        $this->assertRedirect($response, '/sponsoring/packages');
        $package = SponsorPackage::where('name', 'Gold Test')->firstOrFail();

        $this->assertSame(
            ['Logo im Programmheft', '2 Freikarten', 'Nennung bei der Ansage'],
            $package->benefits()->pluck('description')->all()
        );
    }

    public function testUpdatingAPackageReplacesItsBenefits(): void
    {
        $package = $this->makePackage(['Logo im Programmheft', 'Banner im Saal']);

        $this->packageController()->update($this->makeRequest('POST', '/sponsoring/packages/' . $package->id, [
            'name' => $package->name,
            'min_amount' => '100',
            'color' => 'info',
            'benefits' => "Banner im Saal\nFreikarten",
        ]), $this->makeResponse(), ['id' => (string) $package->id]);

        $this->assertSame(['Banner im Saal', 'Freikarten'], $package->benefits()->pluck('description')->all());
    }

    public function testANewAgreementTakesOverThePackageBenefits(): void
    {
        $package = $this->makePackage(['Logo im Programmheft', '2 Freikarten']);
        $sponsor = $this->makeSponsor();

        $this->sponsorshipController()->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
            'sponsor_id' => (string) $sponsor->id,
            'package_id' => (string) $package->id,
            'amount' => '500',
            'status' => SponsorshipStatus::ACCEPTED,
        ]), $this->makeResponse());

        $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
        $this->assertSame(
            ['Logo im Programmheft', '2 Freikarten'],
            $sponsorship->benefits()->pluck('description')->all()
        );
    }

    public function testExplicitBenefitsWinOverThePackageDefaults(): void
    {
        $package = $this->makePackage(['Logo im Programmheft']);
        $sponsor = $this->makeSponsor();

        $this->sponsorshipController()->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
            'sponsor_id' => (string) $sponsor->id,
            'package_id' => (string) $package->id,
            'amount' => '500',
            'status' => SponsorshipStatus::ACCEPTED,
            'benefits' => "Zimmerkontingent\nLogo auf der Website",
        ]), $this->makeResponse());

        $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
        $this->assertSame(
            ['Zimmerkontingent', 'Logo auf der Website'],
            $sponsorship->benefits()->pluck('description')->all()
        );
    }

    /**
     * Wer eine Leistung nachträgt, darf damit nicht den Haken an den übrigen
     * verlieren - sonst müsste nach jeder Korrektur alles neu abgehakt werden.
     */
    public function testEditingTheBenefitsKeepsTheDeliveredStateOfUnchangedLines(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id);
        $logo = $sponsorship->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0]);
        $logo->update(['delivered_at' => '2026-09-01']);
        $sponsorship->benefits()->create(['description' => 'Freikarten', 'sort_order' => 1]);

        $this->updateSponsorship($sponsorship, ['benefits' => "Logo im Programmheft\nBanner im Saal"]);

        $benefits = $sponsorship->benefits()->get();
        $this->assertSame(['Logo im Programmheft', 'Banner im Saal'], $benefits->pluck('description')->all());
        $this->assertSame('2026-09-01', $benefits[0]->delivered_at?->format('Y-m-d'));
        $this->assertNull($benefits[1]->delivered_at);
    }

    public function testTheEditorTicksABenefitOffAndOnAgain(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id);
        $benefit = $sponsorship->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0]);

        $response = $this->toggle($benefit, '1');
        $this->assertRedirect($response, '/sponsoring/sponsors/' . $sponsor->id);
        $this->assertNotNull($benefit->fresh()->delivered_at);

        $this->toggle($benefit, '0');
        $this->assertNull($benefit->fresh()->delivered_at);
    }

    /**
     * Das Formular schickt den Zielzustand, nicht "umdrehen": Ein Doppelklick
     * oder eine veraltete Seite nimmt einen Haken sonst still zurück.
     */
    public function testTickingTwiceKeepsTheBenefitDelivered(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id);
        $benefit = $sponsorship->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0]);

        $this->toggle($benefit, '1');
        $this->toggle($benefit, '1');

        $this->assertNotNull($benefit->fresh()->delivered_at);
    }

    /**
     * Wer die vorgeschlagene Liste bewusst leert - etwa bei reiner Kulanz -,
     * bekommt nicht doch die Paket-Vorgabe. Fehlt das Feld ganz, gilt sie.
     */
    public function testAnEmptiedListStaysEmptyDespiteThePackage(): void
    {
        $package = $this->makePackage(['Logo im Programmheft']);
        $sponsor = $this->makeSponsor();

        $this->sponsorshipController()->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
            'sponsor_id' => (string) $sponsor->id,
            'package_id' => (string) $package->id,
            'amount' => '500',
            'status' => SponsorshipStatus::ACCEPTED,
            'benefits' => '',
        ]), $this->makeResponse());

        $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
        $this->assertSame(0, $sponsorship->benefits()->count());
    }

    public function testAListThatIsNotTextIsRejectedInsteadOfClearingTheBenefits(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id);
        $sponsorship->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0]);

        $this->sponsorshipController()->update(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $sponsorship->id, [
                'sponsor_id' => (string) $sponsor->id,
                'amount' => '100',
                'status' => SponsorshipStatus::ACCEPTED,
                'benefits' => ['Logo im Programmheft'],
            ]),
            $this->makeResponse(),
            ['id' => (string) $sponsorship->id]
        );

        $this->assertSame(1, $sponsorship->benefits()->count());
        $this->assertNotNull($_SESSION['error'] ?? null);
    }

    /**
     * Ein Link im Steckbrief darf nur ins Web führen. FILTER_VALIDATE_URL nahm
     * auch "javascript://x/%0Aalert(1)" an.
     */
    public function testAWebsiteMustBeAnHttpAddress(): void
    {
        (new SponsorController($this->createStub(Twig::class), new SponsoringPolicy($_SESSION), $this->attachmentService()))
            ->create($this->makeRequest('POST', '/sponsoring/sponsors', [
                'name' => 'Skript GmbH',
                'website' => 'javascript://x/%0Aalert(1)',
            ]), $this->makeResponse());

        $this->assertSame(0, Sponsor::where('name', 'Skript GmbH')->count());
        $this->assertSame('Bitte eine gültige Website-URL angeben.', $_SESSION['error'] ?? null);
    }

    /**
     * Abhaken bleibt dem Recht "Sponsoring verwalten" vorbehalten. Die
     * zuständige Person sieht die Liste, setzt den Haken aber nicht selbst.
     */
    public function testTheAssignedPersonMayNotTickOffABenefit(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id, (int) $this->assignee->id);
        $benefit = $sponsorship->benefits()->create(['description' => 'Banner im Saal', 'sort_order' => 0]);

        $this->loginAsContributor($this->assignee);
        $response = $this->toggle($benefit, '1');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($benefit->fresh()->delivered_at);
    }

    public function testTheCreatorMayNotTickOffABenefitEither(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->contributor->id, (int) $this->assignee->id);
        $benefit = $sponsorship->benefits()->create(['description' => 'Banner im Saal', 'sort_order' => 0]);

        $this->loginAsContributor($this->contributor);
        $response = $this->toggle($benefit, '1');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($benefit->fresh()->delivered_at);
    }

    public function testContributorsCannotUntickABenefit(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->contributor->id, (int) $this->contributor->id);
        $benefit = $sponsorship->benefits()->create(['description' => 'Banner im Saal', 'sort_order' => 0]);
        $benefit->update(['delivered_at' => '2026-09-01']);

        $this->loginAsContributor($this->contributor);
        $response = $this->toggle($benefit, '0');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($benefit->fresh()->delivered_at);
    }

    public function testTheCreatorSeesTheListWithoutTickButton(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->contributor->id, (int) $this->assignee->id);
        $sponsorship->benefits()->create(['description' => 'Banner im Saal', 'sort_order' => 0]);

        $this->loginAsContributor($this->contributor);
        $html = $this->renderSponsorDetail($sponsor);

        $this->assertStringContainsString('Banner im Saal', $html);
        $this->assertStringNotContainsString('benefit-toggle', $html);
    }

    public function testTheAssignedPersonGetsTheTickButtonForDeliverablesOnly(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id, (int) $this->assignee->id);
        $sponsorship->benefits()->create(['description' => 'Banner im Saal', 'sort_order' => 0]);
        $sponsorship->deliverables()->create(['description' => 'Logo als Datei', 'sort_order' => 0]);

        $this->loginAsContributor($this->assignee);
        $html = $this->renderSponsorDetail($sponsor);

        $this->assertStringContainsString('action="/sponsoring/sponsorship-deliverables/', $html);
        $this->assertStringNotContainsString('action="/sponsoring/sponsorship-benefits/', $html);
    }

    public function testTheManagerSeesTheTickButton(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->contributor->id, (int) $this->assignee->id);
        $sponsorship->benefits()->create(['description' => 'Banner im Saal', 'sort_order' => 0]);

        $this->assertStringContainsString('benefit-toggle', $this->renderSponsorDetail($sponsor));
    }

    public function testAnUninvolvedContributorMayNotTickOffABenefit(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id, (int) $this->assignee->id);
        $benefit = $sponsorship->benefits()->create(['description' => 'Banner im Saal', 'sort_order' => 0]);

        $this->loginAsContributor($this->contributor);
        $response = $this->toggle($benefit, '1');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($benefit->fresh()->delivered_at);
    }

    public function testThePaymentDateIsStoredAndValidated(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id);

        $this->updateSponsorship($sponsorship, ['paid_at' => '2026-09-15']);
        $this->assertSame('2026-09-15', $sponsorship->fresh()->paid_at?->format('Y-m-d'));

        $this->updateSponsorship($sponsorship, ['paid_at' => '2026-02-30']);
        $this->assertSame(SponsorshipController::PAID_AT_ERROR, $_SESSION['error'] ?? null);
        $this->assertSame('2026-09-15', $sponsorship->fresh()->paid_at?->format('Y-m-d'));
    }

    /**
     * Leistungen sind der Überblick, den jedes Mitglied mit Sponsoring-Zugang
     * braucht; der Zahlungsstand gehört zum Betrag und bleibt dort, wo auch der
     * Betrag sichtbar ist.
     */
    public function testAReaderSeesTheBenefitsButNotThePaymentOfAForeignAgreement(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id);
        $sponsorship->update(['paid_at' => '2026-09-15']);
        $sponsorship->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0]);

        $this->loginAsContributor($this->contributor);
        $html = $this->renderSponsorDetail($sponsor);

        $this->assertStringContainsString('Logo im Programmheft', $html);
        $this->assertStringNotContainsString('15.09.2026', $html);
    }

    public function testTheManagerSeesThePaymentDate(): void
    {
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->manager->id);
        $sponsorship->update(['paid_at' => '2026-09-15']);

        $html = $this->renderSponsorDetail($sponsor);

        $this->assertStringContainsString('Bezahlt am 15.09.2026', $html);
    }

    private function toggle(SponsorshipBenefit $benefit, string $delivered = '1'): ResponseInterface
    {
        return $this->sponsorshipController()->toggleBenefit(
            $this->makeRequest(
                'POST',
                '/sponsoring/sponsorship-benefits/' . $benefit->id . '/toggle',
                ['delivered' => $delivered]
            ),
            $this->makeResponse(),
            ['id' => (string) $benefit->id]
        );
    }

    /** @param array<string, string> $overrides */
    private function updateSponsorship(Sponsorship $sponsorship, array $overrides): void
    {
        $this->sponsorshipController()->update(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $sponsorship->id, $overrides + [
                'sponsor_id' => (string) $sponsorship->sponsor_id,
                'amount' => '100',
                'status' => (string) $sponsorship->status,
            ]),
            $this->makeResponse(),
            ['id' => (string) $sponsorship->id]
        );
    }

    private function renderSponsorDetail(Sponsor $sponsor): string
    {
        $response = $this->sponsorController($this->createAppTwig('/sponsoring/sponsors'))->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsor->id),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function sponsorshipController(): SponsorshipController
    {
        return new SponsorshipController(new SponsoringPolicy($_SESSION), $this->attachmentService());
    }

    private function sponsorController(Twig $twig): SponsorController
    {
        return new SponsorController($twig, new SponsoringPolicy($_SESSION), $this->attachmentService());
    }

    private function packageController(): SponsorPackageController
    {
        return new SponsorPackageController($this->createStub(Twig::class), new SponsoringPolicy($_SESSION));
    }

    private function loginAsManager(): void
    {
        $_SESSION = [
            'user_id' => (int) $this->manager->id,
            'can_manage_sponsoring' => true,
            'can_create_own_sponsorships' => false,
        ];
    }

    private function loginAsContributor(User $user): void
    {
        $_SESSION = [
            'user_id' => (int) $user->id,
            'can_manage_sponsoring' => false,
            'can_create_own_sponsorships' => true,
        ];
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Leistung',
            'last_name' => 'Testperson',
            'email' => 'benefits-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }

    /** @param list<string> $benefits */
    private function makePackage(array $benefits): SponsorPackage
    {
        $package = SponsorPackage::create([
            'name' => 'Paket ' . bin2hex(random_bytes(4)),
            'min_amount' => '100.00',
            'color' => 'info',
        ]);
        foreach ($benefits as $index => $description) {
            SponsorPackageBenefit::create([
                'package_id' => $package->id,
                'description' => $description,
                'sort_order' => $index,
            ]);
        }

        return $package;
    }

    private function makeSponsor(): Sponsor
    {
        return Sponsor::create(['name' => 'Leistungstest ' . bin2hex(random_bytes(4))]);
    }

    private function makeSponsorship(Sponsor $sponsor, ?int $createdBy, ?int $assignedTo = null): Sponsorship
    {
        return Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '100.00',
            'status' => SponsorshipStatus::ACCEPTED,
            'created_by_user_id' => $createdBy,
            'assigned_user_id' => $assignedTo,
        ]);
    }
}

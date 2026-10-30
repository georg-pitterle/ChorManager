<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsoringDashboardController;
use App\Controllers\SponsorPackageController;
use App\Controllers\SponsorshipController;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\SponsorPackageDeliverable;
use App\Models\Sponsorship;
use App\Models\SponsorshipDeliverable;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use App\Util\BenefitList;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Leistungen des Sponsors: was der Sponsor liefert - Banner oder Beachflag,
 * den unterschriebenen Vertrag, eine Sachleistung.
 *
 * In der Excel-Liste standen dafür einzelne Ja/Nein-Spalten ("Banner
 * benötigt?", "Übergabe Banner", "Vertrag da?"). Hier ist es eine Liste wie die
 * Gegenleistungen: Vorlage am Paket, Kopie an der Vereinbarung, je Punkt
 * abhakbar.
 */
final class SponsoringDeliverablesFeatureTest extends TestCase
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
        $this->loginAs($this->manager, true);
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

    public function testAPackageStoresItsDeliverablesOnePerLine(): void
    {
        $response = $this->packageController()->create($this->makeRequest('POST', '/sponsoring/packages', [
            'name' => 'Lieferpaket Test',
            'min_amount' => '200',
            'color' => 'info',
            'benefits' => 'Logo auf dem Plakat',
            'deliverables' => "Banner/Beachflag übergeben\n\n  Vertrag unterschrieben zurück  ",
        ]), $this->makeResponse());

        $this->assertRedirect($response, '/sponsoring/packages');
        $package = SponsorPackage::where('name', 'Lieferpaket Test')->firstOrFail();
        $this->assertSame(
            ['Banner/Beachflag übergeben', 'Vertrag unterschrieben zurück'],
            $package->deliverables()->pluck('description')->all()
        );
    }

    public function testANewAgreementTakesOverThePackageDeliverables(): void
    {
        $package = $this->makePackage(['Banner/Beachflag übergeben']);
        $sponsor = $this->makeSponsor();

        $this->sponsorshipController()->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
            'sponsor_id' => (string) $sponsor->id,
            'package_id' => (string) $package->id,
            'amount' => '200',
            'status' => SponsorshipStatus::ACCEPTED,
        ]), $this->makeResponse());

        $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
        $this->assertSame(['Banner/Beachflag übergeben'], $sponsorship->deliverables()->pluck('description')->all());
    }

    public function testEditingKeepsTheReceivedStateOfUnchangedLines(): void
    {
        $sponsorship = $this->makeSponsorship($this->makeSponsor(), (int) $this->manager->id);
        $kept = SponsorshipDeliverable::create([
            'sponsorship_id' => $sponsorship->id,
            'description' => 'Vertrag unterschrieben zurück',
            'sort_order' => 0,
            'received_at' => '2026-10-01',
            'received_by_user_id' => $this->manager->id,
        ]);

        $this->updateSponsorship($sponsorship, ['deliverables' => "Banner übergeben\nVertrag unterschrieben zurück"]);

        $this->assertSame('2026-10-01', $kept->fresh()->received_at?->format('Y-m-d'));
        $this->assertSame(
            ['Banner übergeben', 'Vertrag unterschrieben zurück'],
            $sponsorship->deliverables()->pluck('description')->all()
        );
    }

    public function testAListThatIsNotTextIsRejectedInsteadOfClearingTheDeliverables(): void
    {
        $sponsorship = $this->makeSponsorship($this->makeSponsor(), (int) $this->manager->id);
        SponsorshipDeliverable::create([
            'sponsorship_id' => $sponsorship->id,
            'description' => 'Banner übergeben',
            'sort_order' => 0,
        ]);

        $this->updateSponsorship($sponsorship, ['deliverables' => ['Banner übergeben']]);

        $this->assertSame('Die Leistungen des Sponsors bitte als Text eintragen, eine pro Zeile.', $_SESSION['error'] ?? null);
        $this->assertSame(1, $sponsorship->deliverables()->count());
    }

    public function testTheManagerTicksOffADeliverableAndIsRecorded(): void
    {
        $sponsorship = $this->makeSponsorship($this->makeSponsor(), (int) $this->assignee->id, (int) $this->assignee->id);
        $deliverable = $this->makeDeliverable($sponsorship);

        $this->toggle($deliverable);

        $fresh = $deliverable->fresh();
        $this->assertNotNull($fresh->received_at);
        $this->assertSame((int) $this->manager->id, (int) $fresh->received_by_user_id);
    }

    /**
     * Dass der Sponsor sein Material bringt, verantwortet das Mitglied, das ihn
     * betreut - anders als die Gegenleistungen, die das Sponsoring-Team erbringt.
     */
    public function testTheAssignedPersonMayTickOffADeliverable(): void
    {
        $sponsorship = $this->makeSponsorship($this->makeSponsor(), (int) $this->manager->id, (int) $this->assignee->id);
        $deliverable = $this->makeDeliverable($sponsorship);
        $this->loginAs($this->assignee, false);

        $this->toggle($deliverable);

        $fresh = $deliverable->fresh();
        $this->assertNotNull($fresh->received_at);
        $this->assertSame((int) $this->assignee->id, (int) $fresh->received_by_user_id);
    }

    public function testTheCreatorMayTickOffADeliverable(): void
    {
        $sponsorship = $this->makeSponsorship($this->makeSponsor(), (int) $this->contributor->id, (int) $this->assignee->id);
        $deliverable = $this->makeDeliverable($sponsorship);
        $this->loginAs($this->contributor, false);

        $this->toggle($deliverable);

        $this->assertNotNull($deliverable->fresh()->received_at);
    }

    public function testAnUninvolvedContributorMayNotTickOffADeliverable(): void
    {
        $sponsorship = $this->makeSponsorship($this->makeSponsor(), (int) $this->manager->id, (int) $this->assignee->id);
        $deliverable = $this->makeDeliverable($sponsorship);
        $this->loginAs($this->contributor, false);

        $response = $this->toggle($deliverable);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($deliverable->fresh()->received_at);
    }

    public function testTickingOffAndOnAgainClearsTheReceivedState(): void
    {
        $deliverable = $this->makeDeliverable($this->makeSponsorship($this->makeSponsor(), (int) $this->manager->id));

        $this->toggle($deliverable, '1');
        $this->toggle($deliverable, '0');

        $fresh = $deliverable->fresh();
        $this->assertNull($fresh->received_at);
        $this->assertNull($fresh->received_by_user_id);
    }

    public function testTheDashboardListsOpenDeliverablesOfAcceptedAgreementsOnly(): void
    {
        $accepted = $this->makeSponsorship($this->makeSponsor('Lieferzusage'), (int) $this->manager->id);
        $this->makeDeliverable($accepted, 'Beachflag Lieferzusage');
        $requested = $this->makeSponsorship($this->makeSponsor('Lieferanfrage'), (int) $this->manager->id);
        $requested->update(['status' => SponsorshipStatus::REQUESTED]);
        $this->makeDeliverable($requested, 'Beachflag Lieferanfrage');

        $response = (new SponsoringDashboardController(
            $this->createAppTwig('/sponsoring'),
            new NameFormatterService(),
            new SponsoringPolicy($_SESSION)
        ))->index($this->makeRequest('GET', '/sponsoring'), $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertStringContainsString('Offene Leistungen des Sponsors', $html);
        $this->assertStringContainsString('Beachflag Lieferzusage', $html);
        $this->assertStringNotContainsString('Beachflag Lieferanfrage', $html);
        $this->assertStringContainsString('name="received"', $html);
    }

    public function testTheBenefitMessagesStayUnchanged(): void
    {
        $this->assertSame(BenefitList::LENGTH_ERROR, BenefitList::parse(str_repeat('x', 256))['error']);
        $this->assertSame(BenefitList::FORMAT_ERROR, BenefitList::parse(['x'])['error']);
        $this->assertSame(
            'Eine Leistung des Sponsors darf höchstens 255 Zeichen lang sein.',
            BenefitList::parse(str_repeat('x', 256), BenefitList::DELIVERABLE_LABELS)['error']
        );
    }

    private function toggle(SponsorshipDeliverable $deliverable, string $received = '1'): ResponseInterface
    {
        return $this->sponsorshipController()->toggleDeliverable(
            $this->makeRequest(
                'POST',
                '/sponsoring/sponsorship-deliverables/' . $deliverable->id . '/toggle',
                ['received' => $received]
            ),
            $this->makeResponse(),
            ['id' => (string) $deliverable->id]
        );
    }

    /** @param array<string, mixed> $overrides */
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

    private function sponsorshipController(): SponsorshipController
    {
        return new SponsorshipController(new SponsoringPolicy($_SESSION), $this->attachmentService());
    }

    private function packageController(): SponsorPackageController
    {
        return new SponsorPackageController($this->createStub(Twig::class), new SponsoringPolicy($_SESSION));
    }

    private function loginAs(User $user, bool $manager): void
    {
        $_SESSION = [
            'user_id' => (int) $user->id,
            'can_manage_sponsoring' => $manager,
            'can_create_own_sponsorships' => !$manager,
        ];
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Lieferung',
            'last_name' => 'Testperson',
            'email' => 'deliverables-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }

    /** @param list<string> $deliverables */
    private function makePackage(array $deliverables): SponsorPackage
    {
        $package = SponsorPackage::create([
            'name' => 'Paket ' . bin2hex(random_bytes(4)),
            'min_amount' => '100.00',
            'color' => 'info',
        ]);
        foreach ($deliverables as $index => $description) {
            SponsorPackageDeliverable::create([
                'package_id' => $package->id,
                'description' => $description,
                'sort_order' => $index,
            ]);
        }

        return $package;
    }

    private function makeSponsor(string $prefix = 'Lieferungstest'): Sponsor
    {
        return Sponsor::create(['name' => $prefix . ' ' . bin2hex(random_bytes(4))]);
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

    private function makeDeliverable(Sponsorship $sponsorship, string $description = 'Banner übergeben'): SponsorshipDeliverable
    {
        return SponsorshipDeliverable::create([
            'sponsorship_id' => $sponsorship->id,
            'description' => $description,
            'sort_order' => 0,
        ]);
    }
}

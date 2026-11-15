<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsoringContactController;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein Kontakt kann den Status seiner Anfrage gleich mit ändern, und ein Kontakt
 * ohne Anfrage wird als solcher gemeldet.
 *
 * Im ersten Praxistest (November 2026) wurde eine Absage nur als Kontakt
 * notiert - die Anfrage stand weiter auf "Angefragt" - und ein Kontakt ganz ohne
 * Anfrage protokolliert, der Sponsor also weiter als "Keine Anfrage" geführt.
 */
final class SponsoringContactStatusFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = ['can_manage_sponsoring' => true];
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

    public function testAContactSetsTheStatusOfItsRequest(): void
    {
        [$sponsor, $sponsorship] = $this->sponsorWithRequest(null);

        $this->logContact($sponsor, [
            'sponsorship_id' => (string) $sponsorship->id,
            'sponsorship_status' => SponsorshipStatus::DECLINED,
        ]);

        $this->assertSame(SponsorshipStatus::DECLINED, $sponsorship->fresh()->status);
        $this->assertSame(1, SponsoringContact::where('sponsorship_id', $sponsorship->id)->count());
    }

    public function testWithoutAChosenStatusTheRequestStaysAsItIs(): void
    {
        [$sponsor, $sponsorship] = $this->sponsorWithRequest(null);

        $this->logContact($sponsor, ['sponsorship_id' => (string) $sponsorship->id, 'sponsorship_status' => '']);

        $this->assertSame(SponsorshipStatus::REQUESTED, $sponsorship->fresh()->status);
    }

    public function testAnUnknownStatusStoresNeitherContactNorStatus(): void
    {
        [$sponsor, $sponsorship] = $this->sponsorWithRequest(null);

        $this->logContact($sponsor, [
            'sponsorship_id' => (string) $sponsorship->id,
            'sponsorship_status' => 'negotiating',
        ]);

        $this->assertSame(SponsoringContactController::STATUS_ERROR, $_SESSION['error'] ?? null);
        $this->assertSame(SponsorshipStatus::REQUESTED, $sponsorship->fresh()->status);
        $this->assertSame(0, SponsoringContact::where('sponsor_id', $sponsor->id)->count());
    }

    /**
     * Den Status ändert nur, wer die Anfrage ändern darf. Der Kontakt selbst
     * bleibt erlaubt - protokollieren darf jedes beitragende Mitglied.
     */
    public function testAContributorDoesNotChangeTheStatusOfSomeoneElsesRequest(): void
    {
        $author = $this->person();
        $contributor = $this->person();
        [$sponsor, $sponsorship] = $this->sponsorWithRequest((int) $author->id);
        $_SESSION = ['user_id' => (int) $contributor->id, 'can_create_own_sponsorships' => true];

        $this->logContact($sponsor, [
            'sponsorship_id' => (string) $sponsorship->id,
            'sponsorship_status' => SponsorshipStatus::ACCEPTED,
        ]);

        $this->assertSame(SponsorshipStatus::REQUESTED, $sponsorship->fresh()->status);
        $this->assertSame(1, SponsoringContact::where('sponsorship_id', $sponsorship->id)->count());
    }

    public function testAStatusWithoutPackageDropsThePackage(): void
    {
        [$sponsor, $sponsorship] = $this->sponsorWithRequest(null);
        $package = \App\Models\SponsorPackage::create([
            'name' => 'Kontaktpaket ' . bin2hex(random_bytes(4)),
            'min_amount' => '100.00',
            'color' => 'info',
        ]);
        $sponsorship->update(['status' => SponsorshipStatus::ACCEPTED, 'package_id' => $package->id]);

        $this->logContact($sponsor, [
            'sponsorship_id' => (string) $sponsorship->id,
            'sponsorship_status' => SponsorshipStatus::DECLINED,
        ]);

        $this->assertNull($sponsorship->fresh()->package_id);
    }

    public function testAContactWithoutRequestIsReportedAsSuch(): void
    {
        [$sponsor] = $this->sponsorWithRequest(null);

        $this->logContact($sponsor, []);

        $this->assertSame(SponsoringContactController::NO_REQUEST_NOTICE, $_SESSION['success'] ?? null);
    }

    public function testAContactOnARequestIsReportedPlainly(): void
    {
        [$sponsor, $sponsorship] = $this->sponsorWithRequest(null);

        $this->logContact($sponsor, ['sponsorship_id' => (string) $sponsorship->id]);

        $this->assertSame('Kontakt erfolgreich protokolliert.', $_SESSION['success'] ?? null);
    }

    /**
     * @param array<string, string> $extra
     */
    private function logContact(Sponsor $sponsor, array $extra): void
    {
        $controller = new SponsoringContactController(
            $this->createStub(Twig::class),
            new SponsoringPolicy($_SESSION)
        );

        $controller->create($this->makeRequest('POST', '/sponsoring/contacts', $extra + [
            'sponsor_id' => (string) $sponsor->id,
            'contact_date' => date('Y-m-d'),
            'type' => 'call',
            'summary' => 'Rückruf vom Sponsor.',
        ]), $this->makeResponse());
    }

    /**
     * @return array{Sponsor, Sponsorship}
     */
    private function sponsorWithRequest(?int $createdBy): array
    {
        $sponsor = Sponsor::create(['name' => 'Kontaktstatus ' . bin2hex(random_bytes(4))]);
        $sponsorship = Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '0.00',
            'status' => SponsorshipStatus::REQUESTED,
            'created_by_user_id' => $createdBy,
        ]);

        return [$sponsor, $sponsorship];
    }

    private function person(): User
    {
        return User::create([
            'first_name' => 'Kontakt',
            'last_name' => 'Testperson',
            'email' => 'contact-status-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }
}

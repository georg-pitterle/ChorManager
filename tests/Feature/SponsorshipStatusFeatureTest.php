<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsoringDashboardController;
use App\Controllers\SponsorshipController;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Util\SponsorEngagementState;
use App\Util\SponsorshipStatus;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class SponsorshipStatusFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_SESSION['can_manage_sponsoring'] = true;
        Bootstrap::setupTestDatabase();
    }

    public function testStatusSetIsReducedToTheFiveWorkingStates(): void
    {
        $this->assertSame(
            ['requested', 'reminded', 'accepted', 'declined', 'closed'],
            SponsorshipStatus::all()
        );
        $this->assertSame(['requested', 'reminded'], SponsorshipStatus::OPEN);
        $this->assertSame('Angefragt', SponsorshipStatus::label('requested'));
        $this->assertSame('Absage', SponsorshipStatus::label('declined'));
    }

    public function testCreateRejectsAnUnknownStatusInsteadOfRunningIntoTheColumnEnum(): void
    {
        $sponsor = $this->makeSponsor();
        $controller = new SponsorshipController($this->createStub(Twig::class), $this->logger()[0], new SponsoringPolicy());

        try {
            $response = $controller->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
                'sponsor_id' => (string) $sponsor->id,
                'amount' => '100',
                'status' => 'negotiating',
            ]), $this->makeResponse());

            $this->assertRedirect($response, '/sponsoring/sponsors/' . $sponsor->id);
            $this->assertSame(SponsorshipController::STATUS_ERROR, $_SESSION['error'] ?? null);
            $this->assertSame(0, Sponsorship::where('sponsor_id', $sponsor->id)->count());
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testCreateFallsBackToRequestedWhenNoStatusIsSubmitted(): void
    {
        $sponsor = $this->makeSponsor();
        $controller = new SponsorshipController($this->createStub(Twig::class), $this->logger()[0], new SponsoringPolicy());

        try {
            $controller->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
                'sponsor_id' => (string) $sponsor->id,
                'amount' => '250',
            ]), $this->makeResponse());

            $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
            $this->assertSame(SponsorshipStatus::REQUESTED, $sponsorship->status);
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testSponsorStateIsDerivedFromItsAgreements(): void
    {
        $sponsor = $this->makeSponsor();

        try {
            $this->assertSame(SponsorEngagementState::NONE, SponsorEngagementState::forSponsor($sponsor->fresh()));

            Sponsorship::create([
                'sponsor_id' => $sponsor->id,
                'amount' => '100.00',
                'status' => SponsorshipStatus::REQUESTED,
            ]);
            $this->assertSame(SponsorEngagementState::OPEN, SponsorEngagementState::forSponsor($sponsor->fresh()));

            Sponsorship::create([
                'sponsor_id' => $sponsor->id,
                'amount' => '900.00',
                'status' => SponsorshipStatus::ACCEPTED,
            ]);
            $this->assertSame(SponsorEngagementState::ACCEPTED, SponsorEngagementState::forSponsor($sponsor->fresh()));

            // Die Generalabsage am Sponsor schlaegt jede Vereinbarung.
            $sponsor->update(['requests_blocked' => true]);
            $this->assertSame(SponsorEngagementState::BLOCKED, SponsorEngagementState::forSponsor($sponsor->fresh()));
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testDashboardCountsSponsorsWithAnAcceptedAgreementInsteadOfASponsorColumn(): void
    {
        $sponsor = $this->makeSponsor();

        try {
            Sponsorship::create([
                'sponsor_id' => $sponsor->id,
                'amount' => '1200.00',
                'status' => SponsorshipStatus::ACCEPTED,
            ]);
            Sponsorship::create([
                'sponsor_id' => $sponsor->id,
                'amount' => '300.00',
                'status' => SponsorshipStatus::REMINDED,
            ]);

            $captured = [];
            $twig = $this->createStub(Twig::class);
            $twig->method('render')->willReturnCallback(
                function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                    $captured = $data;
                    return $response;
                }
            );

            $controller = new SponsoringDashboardController($twig, new NameFormatterService());
            $controller->index($this->makeRequest('GET', '/sponsoring'), $this->makeResponse());

            // Der Sponsor selbst traegt keinen Status mehr - die Kennzahl kommt
            // aus seiner zugesagten Vereinbarung.
            $this->assertGreaterThanOrEqual(1, $captured['total_active']);
            $this->assertGreaterThanOrEqual(1200.0, $captured['total_amount']);
            $this->assertGreaterThanOrEqual(300.0, $captured['pipeline']);
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    private function makeSponsor(): Sponsor
    {
        return Sponsor::create(['name' => 'Statustest ' . bin2hex(random_bytes(4))]);
    }

    private function cleanUp(Sponsor $sponsor): void
    {
        Sponsorship::where('sponsor_id', $sponsor->id)->delete();
        $sponsor->delete();
    }
}

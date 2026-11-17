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
    use BuildsControllers;

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
        $controller = $this->sponsorshipController(['attachments' => $this->attachmentService()]);

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
        $controller = $this->sponsorshipController(['attachments' => $this->attachmentService()]);

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

    /**
     * Ein Paket gehört erst zu einer Zusage: Vorher steht nicht fest, welches
     * der Sponsor wählt, und ein Paket an einer offenen Anfrage las sich wie
     * eine Zusage, deren Status niemand umgestellt hat.
     *
     * @return array<string, array{string, bool}>
     */
    public static function packageByStatus(): array
    {
        return [
            'angefragt' => [SponsorshipStatus::REQUESTED, false],
            'nachgefragt' => [SponsorshipStatus::REMINDED, false],
            'Absage' => [SponsorshipStatus::DECLINED, false],
            'Zusage' => [SponsorshipStatus::ACCEPTED, true],
            'abgeschlossen' => [SponsorshipStatus::CLOSED, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('packageByStatus')]
    public function testAPackageIsOnlyStoredOnceTheSponsorHasAccepted(string $status, bool $kept): void
    {
        $sponsor = $this->makeSponsor();
        $package = \App\Models\SponsorPackage::create([
            'name' => 'Statuspaket ' . bin2hex(random_bytes(4)),
            'min_amount' => '100.00',
            'color' => 'info',
        ]);
        $controller = $this->sponsorshipController(['attachments' => $this->attachmentService()]);

        try {
            $controller->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
                'sponsor_id' => (string) $sponsor->id,
                'package_id' => (string) $package->id,
                'status' => $status,
            ]), $this->makeResponse());

            $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
            $this->assertSame($kept ? (int) $package->id : null, $sponsorship->package_id);

            // Derselbe Weg beim Ändern: Altbestand mit Paket verliert es beim
            // nächsten Speichern, solange keine Zusage vorliegt.
            $sponsorship->update(['package_id' => $package->id]);
            $controller->update($this->makeRequest('POST', '/sponsoring/sponsorships/' . $sponsorship->id, [
                'sponsor_id' => (string) $sponsor->id,
                'package_id' => (string) $package->id,
                'status' => $status,
            ]), $this->makeResponse(), ['id' => (string) $sponsorship->id]);

            $this->assertSame($kept ? (int) $package->id : null, $sponsorship->fresh()->package_id);
        } finally {
            $this->cleanUp($sponsor);
            $package->delete();
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

            // Eine laufende Anfrage hat Vorrang vor einer Zusage: wer vor der
            // eigenen Anfrage nachsieht, will wissen, ob schon jemand dran ist.
            Sponsorship::create([
                'sponsor_id' => $sponsor->id,
                'amount' => '900.00',
                'status' => SponsorshipStatus::ACCEPTED,
            ]);
            $this->assertSame(SponsorEngagementState::OPEN, SponsorEngagementState::forSponsor($sponsor->fresh()));

            // Ohne offene Anfrage bleibt die Zusage der Zustand.
            Sponsorship::where('sponsor_id', $sponsor->id)
                ->where('status', SponsorshipStatus::REQUESTED)
                ->update(['status' => SponsorshipStatus::CLOSED]);
            $this->assertSame(SponsorEngagementState::ACCEPTED, SponsorEngagementState::forSponsor($sponsor->fresh()));

            // Die Generalabsage am Sponsor schlägt jede Vereinbarung.
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

            $controller = new SponsoringDashboardController($twig, new NameFormatterService(), new SponsoringPolicy($_SESSION));
            $controller->index($this->makeRequest('GET', '/sponsoring'), $this->makeResponse());

            // Der Sponsor selbst traegt keinen Status mehr - die Kennzahl kommt
            // aus seiner zugesagten Vereinbarung.
            $this->assertGreaterThanOrEqual(1, $captured['total_active']);
            $this->assertGreaterThanOrEqual(1200.0, $captured['total_amount']);
            $this->assertGreaterThanOrEqual(300.0, $captured['requested_amount']);
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testKeyFigureCountsCommitmentsAndSurvivesABlockedSponsor(): void
    {
        $sponsor = $this->makeSponsor();

        try {
            $before = $this->dashboardData();

            Sponsorship::create([
                'sponsor_id' => $sponsor->id,
                'amount' => '4200.00',
                'status' => SponsorshipStatus::ACCEPTED,
            ]);

            $withAgreement = $this->dashboardData();
            $this->assertSame($before['total_active'] + 1, $withAgreement['total_active']);
            $this->assertEqualsWithDelta($before['total_amount'] + 4200.0, $withAgreement['total_amount'], 0.001);

            // Die Generalabsage betrifft künftige Anfragen, nicht eine bereits
            // erteilte Zusage: das zugesagte Geld bleibt zugesagt.
            $sponsor->update(['requests_blocked' => true]);
            $afterBlock = $this->dashboardData();

            $this->assertSame($withAgreement['total_active'], $afterBlock['total_active']);
            $this->assertEqualsWithDelta($withAgreement['total_amount'], $afterBlock['total_amount'], 0.001);
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testKeyFigureCountsAgreementsNotSponsors(): void
    {
        $sponsor = $this->makeSponsor();

        try {
            $before = $this->dashboardData();

            // Zwei Zusagen desselben Sponsors zählen zweimal - die Kachel misst
            // Verpflichtungen, nicht Sponsoren, und ist deshalb nicht mit dem
            // Zustandsfilter der Sponsorenliste zu verwechseln.
            foreach (['1000.00', '2000.00'] as $amount) {
                Sponsorship::create([
                    'sponsor_id' => $sponsor->id,
                    'amount' => $amount,
                    'status' => SponsorshipStatus::ACCEPTED,
                ]);
            }

            $after = $this->dashboardData();

            $this->assertSame($before['total_active'] + 2, $after['total_active']);
            $this->assertEqualsWithDelta($before['total_amount'] + 3000.0, $after['total_amount'], 0.001);
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testBlockNoteSurvivesTemporarilyLiftingTheBlock(): void
    {
        $_SESSION['user_id'] = 0;
        $sponsor = $this->makeSponsor();
        $sponsor->update([
            'requests_blocked' => true,
            'requests_blocked_note' => 'Kontakt nur über die Geschäftsführung.',
        ]);

        $controller = $this->sponsorController([
            'view' => $this->createStub(Twig::class),
            'policy' => new \App\Policies\SponsoringPolicy($_SESSION),
        ]);

        try {
            // Sperre aufheben, Begründung steht weiterhin im Formular.
            $controller->update(
                $this->makeRequest('POST', '/sponsoring/sponsors/' . $sponsor->id, [
                    'name' => $sponsor->name,
                    'requests_blocked_note' => 'Kontakt nur über die Geschäftsführung.',
                ]),
                $this->makeResponse(),
                ['id' => (string) $sponsor->id]
            );

            $reloaded = $sponsor->fresh();
            $this->assertFalse((bool) $reloaded->requests_blocked);
            $this->assertSame('Kontakt nur über die Geschäftsführung.', $reloaded->requests_blocked_note);
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    /**
     * @return array<string, mixed>
     */
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

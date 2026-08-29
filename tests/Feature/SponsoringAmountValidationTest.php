<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorPackageController;
use App\Controllers\SponsorshipController;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\Sponsorship;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use App\Policies\SponsoringPolicy;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Sponsoring-Beträge werden wie im Finanzmodul normalisiert und geprüft. Ohne
 * Prüfung wurde "1.500,00" zu 1,50, "abc" zu 0,00 - jeweils mit Erfolgsmeldung.
 */
final class SponsoringAmountValidationTest extends TestCase
{
    use TestHttpHelpers;

    private SponsorshipController $sponsorships;
    private SponsorPackageController $packages;
    private Sponsor $sponsor;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $_SESSION['can_manage_sponsoring'] = true;
        $this->sponsorships = new SponsorshipController(
            $this->createStub(Twig::class),
            new NullLogger(),
            new SponsoringPolicy()
        );
        $this->packages = new SponsorPackageController($this->createStub(Twig::class), new SponsoringPolicy());
        $this->sponsor = Sponsor::create(['name' => 'Betragsprüfung ' . bin2hex(random_bytes(4))]);

        $_SESSION = [];
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

    private function createSponsorship(string $amount): void
    {
        $this->sponsorships->create(
            $this->makeRequest('POST', '/sponsoring/sponsorships', [
                'sponsor_id' => (string) $this->sponsor->id,
                'amount' => $amount,
            ]),
            $this->makeResponse()
        );
    }

    private function createPackage(string $minAmount): void
    {
        $this->packages->create(
            $this->makeRequest('POST', '/sponsoring/packages', [
                'name' => 'Paket ' . bin2hex(random_bytes(4)),
                'min_amount' => $minAmount,
            ]),
            $this->makeResponse()
        );
    }

    public function testNormalizesGermanThousandsSeparators(): void
    {
        $this->createSponsorship('1.500,00');

        $sponsorship = Sponsorship::where('sponsor_id', $this->sponsor->id)->firstOrFail();
        $this->assertSame('1500.00', number_format((float) $sponsorship->amount, 2, '.', ''));
    }

    public function testRejectsNonNumericAmounts(): void
    {
        $this->createSponsorship('abc');

        $this->assertSame(0, Sponsorship::where('sponsor_id', $this->sponsor->id)->count());
        $this->assertStringContainsString('Betrag', (string) $_SESSION['error']);
        $this->assertArrayNotHasKey('success', $_SESSION);
    }

    public function testRejectsNegativeAmounts(): void
    {
        $this->createSponsorship('-100');

        $this->assertSame(0, Sponsorship::where('sponsor_id', $this->sponsor->id)->count());
        $this->assertStringContainsString('Betrag', (string) $_SESSION['error']);
    }

    public function testRejectsNegativeAmountsOnUpdate(): void
    {
        $this->createSponsorship('250,00');
        $sponsorship = Sponsorship::where('sponsor_id', $this->sponsor->id)->firstOrFail();
        $_SESSION = [];

        $this->sponsorships->update(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $sponsorship->id, [
                'sponsor_id' => (string) $this->sponsor->id,
                'amount' => '-1',
            ]),
            $this->makeResponse(),
            ['id' => (string) $sponsorship->id]
        );

        $sponsorship->refresh();
        $this->assertSame('250.00', number_format((float) $sponsorship->amount, 2, '.', ''));
        $this->assertStringContainsString('Betrag', (string) $_SESSION['error']);
    }

    public function testPackageMinAmountIsNormalizedAndValidated(): void
    {
        $this->createPackage('1.500,00');
        $package = SponsorPackage::orderBy('id', 'desc')->firstOrFail();
        $this->assertSame('1500.00', number_format((float) $package->min_amount, 2, '.', ''));

        $countBefore = SponsorPackage::count();
        $_SESSION = [];
        $this->createPackage('abc');

        $this->assertSame($countBefore, SponsorPackage::count());
        $this->assertStringContainsString('Mindestbetrag', (string) $_SESSION['error']);
    }

    public function testEmptyPackageMinAmountStaysOptional(): void
    {
        $this->createPackage('');

        $package = SponsorPackage::orderBy('id', 'desc')->firstOrFail();
        $this->assertSame(0.0, (float) $package->min_amount);
        $this->assertSame('Paket erfolgreich angelegt.', $_SESSION['success']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorPackageController;
use App\Models\SponsorPackage;
use App\Policies\SponsoringPolicy;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Farbe eines Sponsoring-Pakets landet in der Vorlage als `bg-{{ pkg.color }}`.
 *
 * Bootstrap kennt dafür genau sieben Klassen, und genau die bietet das Formular
 * an. Ungeprüft gespeichert ergab ein selbstgebauter Beitrag `bg-neongruen`, und
 * das Abzeichen des Pakets blieb dauerhaft ungefärbt - dieselbe Begründung, aus
 * der EventTypeController seine Farbe seit jeher normalisiert.
 */
final class SponsorPackageColorValidationFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_SESSION['can_manage_sponsoring'] = true;
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
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

    public function testAnUnknownColorFallsBackToTheDefaultOnCreate(): void
    {
        $name = 'Farbtest ' . bin2hex(random_bytes(4));

        $response = $this->controller()->create(
            $this->makeRequest('POST', '/sponsoring/packages', [
                'name' => $name,
                'min_amount' => '250',
                'color' => 'neongruen',
            ]),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/sponsoring/packages');
        $this->assertSame('info', (string) SponsorPackage::where('name', $name)->firstOrFail()->color);
    }

    public function testAnOfferedColorIsStoredAsItIs(): void
    {
        $name = 'Farbtest ' . bin2hex(random_bytes(4));

        $this->controller()->create(
            $this->makeRequest('POST', '/sponsoring/packages', [
                'name' => $name,
                'min_amount' => '250',
                'color' => 'success',
            ]),
            $this->makeResponse()
        );

        $this->assertSame('success', (string) SponsorPackage::where('name', $name)->firstOrFail()->color);
    }

    public function testAnUnknownColorFallsBackToTheDefaultOnUpdate(): void
    {
        $package = SponsorPackage::create([
            'name' => 'Farbtest ' . bin2hex(random_bytes(4)),
            'min_amount' => '100.00',
            'color' => 'success',
        ]);

        $this->controller()->update(
            $this->makeRequest('POST', '/sponsoring/packages/' . $package->id, [
                'name' => (string) $package->name,
                'min_amount' => '100',
                'color' => 'javascript:alert(1)',
            ]),
            $this->makeResponse(),
            ['id' => (string) $package->id]
        );

        $this->assertSame('info', (string) $package->fresh()->color);
    }

    /**
     * Ein Feld, das als Feld-Array hereinkommt (`color[]=...`), stammt nicht aus
     * der Oberfläche. Es darf in der Vorgabe landen, nicht in einem TypeError.
     */
    public function testAnArrayValueDoesNotBreakTheRequest(): void
    {
        $name = 'Farbtest ' . bin2hex(random_bytes(4));

        $response = $this->controller()->create(
            $this->makeRequest('POST', '/sponsoring/packages', [
                'name' => $name,
                'min_amount' => '0',
                'color' => ['danger'],
            ]),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/sponsoring/packages');
        $this->assertSame('info', (string) SponsorPackage::where('name', $name)->firstOrFail()->color);
    }

    private function controller(): SponsorPackageController
    {
        return new SponsorPackageController($this->createStub(Twig::class), new SponsoringPolicy());
    }
}

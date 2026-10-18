<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcClient;
use App\Models\OidcSigningKey;
use App\Models\User;
use App\Services\DevSeedService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Unit\Bootstrap;

/**
 * `dev_seed.php --mode=append` läuft auf einem schon befüllten Bestand. Der
 * OIDC-Teil legte den Client `dev-nextcloud` dort jedes Mal neu an und brach
 * am eindeutigen Schlüssel `client_id` ab - und mit ihm der ganze Seed-Lauf,
 * weil er in einer Transaktion steckt.
 */
final class DevSeedOidcRerunFeatureTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'first_name' => 'Wieder',
            'last_name' => 'Holung',
            'email' => 'seed.rerun.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('Correct-Horse-8'),
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASecondRunKeepsTheOneDevClientAndCountsItOnce(): void
    {
        $seed = $this->seedService();

        $this->seedOidc($seed);
        $this->seedOidc($seed);

        $this->assertSame(1, OidcClient::query()->where('client_id', 'dev-nextcloud')->count());
        $this->assertSame(1, $this->report($seed)['counts']['oidc_clients']);
        $this->assertSame('dev-secret-nextcloud', $this->report($seed)['oidc']['client_secret']);
    }

    public function testASecondRunKeepsTheActiveSigningKey(): void
    {
        $seed = $this->seedService();

        $this->seedOidc($seed);
        $firstKeys = OidcSigningKey::query()->count();
        $this->seedOidc($seed);

        // Ohne Schlüsselgeheimnis entsteht gar keiner; dann prüft der Test nur,
        // dass auch der zweite Lauf keinen anlegt.
        $this->assertSame($firstKeys, OidcSigningKey::query()->count());
    }

    private function seedService(): DevSeedService
    {
        $seed = (new ReflectionClass(DevSeedService::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(DevSeedService::class, 'report');
        $property->setValue($seed, [
            'warnings' => [],
            'oidc' => [],
            'counts' => [
                'oidc_clients' => 0,
                'oidc_signing_keys' => 0,
                'oidc_auth_codes' => 0,
                'oidc_access_tokens' => 0,
            ],
        ]);

        return $seed;
    }

    private function seedOidc(DevSeedService $seed): void
    {
        $method = new \ReflectionMethod(DevSeedService::class, 'seedOidcClients');
        $method->invoke($seed, [$this->user]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(DevSeedService $seed): array
    {
        return (new \ReflectionProperty(DevSeedService::class, 'report'))->getValue($seed);
    }
}

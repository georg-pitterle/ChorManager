<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcAuthCode;
use App\Models\OidcClient;
use App\Models\User;
use App\Services\Oidc\AccessTokenService;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\OidcClientService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Was ein abgeschalteter Client noch darf.
 *
 * `is_active = 0` sperrte bisher nur den Weg hinein: `findActiveClient()`
 * weist ihn bei der Anmeldung und am Token-Endpunkt ab. Bereits ausgestellte
 * Zugriffstoken prüfte `resolve()` dagegen allein gegen Ablauf und Widerruf -
 * ein abgeschalteter Client kam damit bis zu fünf Minuten lang
 * (AccessTokenService::TOKEN_TTL_SECONDS) weiter an /oidc/userinfo durch.
 *
 * Das Abschalten ist der Notaus für eine angeschlossene Anwendung. Es muss
 * sofort wirken, sonst ist es keiner.
 */
final class OidcInactiveClientFeatureTest extends TestCase
{
    private const REDIRECT_URI = 'https://cloud.example.org/apps/user_oidc/code';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            // Adresse bleibt ASCII, wie jede E-Mail-Adresse. naming:ascii
            'email' => 'inactive-client-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Ina',
            'last_name' => 'Abgeschaltet',
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

    public function testATokenOfAnActiveClientResolves(): void
    {
        [$token] = $this->grant();

        $this->assertNotNull((new AccessTokenService())->resolve($token));
    }

    public function testDeactivatingAClientStopsItsOutstandingTokensAtOnce(): void
    {
        [$token, $client] = $this->grant();

        $client->is_active = false;
        $client->save();

        $this->assertNull(
            (new AccessTokenService())->resolve($token),
            'Ein abgeschalteter Client darf mit einem alten Token nicht weiterkommen.'
        );
    }

    public function testATokenWhoseClientIsGoneResolvesToNothing(): void
    {
        [$token, $client] = $this->grant();

        $client->delete();

        $this->assertNull(
            (new AccessTokenService())->resolve($token),
            'Ohne Client hinter der Kennung gibt es nichts mehr aufzulösen.'
        );
    }

    /**
     * @return array{0: string, 1: OidcClient}
     */
    private function grant(): array
    {
        $client = (new OidcClientService())->createClient('Nextcloud', [self::REDIRECT_URI])['client'];
        $codeService = new AuthorizationCodeService();

        $code = $codeService->issue($client, (int) $this->user->id, self::REDIRECT_URI, 'openid', null, 'egal');

        $stored = OidcAuthCode::query()
            ->where('code_hash', AuthorizationCodeService::hashCode($code))
            ->firstOrFail();

        return [(new AccessTokenService())->issueForCode($stored), $client];
    }
}

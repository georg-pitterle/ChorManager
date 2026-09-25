<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcAccessToken;
use App\Models\OidcAuthCode;
use App\Persistence\UserPersistence;
use App\Models\User;
use App\Services\Oidc\AccessTokenService;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\OidcClientService;
use App\Services\SessionInvalidationService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Was mit ausgestellten Anmeldungen geschieht, wenn jemand gesperrt wird.
 *
 * Ein Zugriffstoken hängt an keiner Sitzung. Ohne ausdrücklichen Widerruf
 * überlebte es sowohl das Archivieren eines Mitglieds als auch das globale
 * Abmelden - und wäre damit ein eigenständiger Weg hinein.
 */
final class OidcRevocationFeatureTest extends TestCase
{
    private const REDIRECT_URI = 'https://cloud.example.org/apps/user_oidc/code';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'email' => 'revoke-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Rosa',
            'last_name' => 'Widerruf',
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

    public function testArchivingAMemberRevokesItsCodesAndTokens(): void
    {
        $token = $this->grantSomething();

        $this->user->is_active = 0;
        (new UserPersistence(new NullLogger()))->save($this->user);

        $this->assertNull((new AccessTokenService())->resolve($token));
        $this->assertSame(0, OidcAuthCode::query()->whereNull('used_at')->count());
    }

    public function testSavingAnActiveMemberLeavesItsGrantsAlone(): void
    {
        $token = $this->grantSomething();

        $this->user->first_name = 'Rosalinde';
        (new UserPersistence(new NullLogger()))->save($this->user);

        $this->assertNotNull((new AccessTokenService())->resolve($token));
    }

    public function testGlobalLogoutRevokesEveryOutstandingGrant(): void
    {
        $token = $this->grantSomething();

        (new SessionInvalidationService())->invalidateAllLogins();

        $this->assertNull((new AccessTokenService())->resolve($token));
        $this->assertSame(0, OidcAccessToken::query()->whereNull('revoked_at')->count());
        $this->assertSame(0, OidcAuthCode::query()->whereNull('used_at')->count());
    }

    /**
     * Stellt einen offenen Code und ein gültiges Zugriffstoken aus.
     */
    private function grantSomething(): string
    {
        $client = (new OidcClientService())->createClient('Nextcloud', [self::REDIRECT_URI])['client'];
        $codeService = new AuthorizationCodeService();

        $codeService->issue($client, (int) $this->user->id, self::REDIRECT_URI, 'openid', null, 'egal');
        $used = $codeService->issue($client, (int) $this->user->id, self::REDIRECT_URI, 'openid', null, 'egal');

        $stored = OidcAuthCode::query()
            ->where('code_hash', AuthorizationCodeService::hashCode($used))
            ->firstOrFail();

        return (new AccessTokenService())->issueForCode($stored);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\MailBadgeRefreshMiddleware;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use App\Services\MailCredentialCryptoService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

final class MailBadgeRefreshMiddlewareTest extends TestCase
{
    use TestHttpHelpers;

    private const ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ?User $user = null;
    private ?string $originalEnvValue = null;
    private bool $hadEnvValue = false;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->hadEnvValue = array_key_exists(self::ENV_KEY, $_ENV);
        $this->originalEnvValue = $_ENV[self::ENV_KEY] ?? null;

        $generatedKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::ENV_KEY] = $generatedKey;
        $_SERVER[self::ENV_KEY] = $generatedKey;
        putenv(self::ENV_KEY . '=' . $generatedKey);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->user !== null) {
            UserMailAccount::query()->where('user_id', $this->user->id)->delete();
            $this->user->delete();
            $this->user = null;
        }

        if ($this->hadEnvValue) {
            $_ENV[self::ENV_KEY] = $this->originalEnvValue;
            $_SERVER[self::ENV_KEY] = $this->originalEnvValue;
            putenv(self::ENV_KEY . '=' . $this->originalEnvValue);
        } else {
            unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
            putenv(self::ENV_KEY);
        }

        parent::tearDown();
    }

    private function makeHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };
    }

    private function makeBadgeService(): MailBadgeService
    {
        return new MailBadgeService(new MailCredentialCryptoService(), new NullLogger(), 1);
    }

    private function createUser(): User
    {
        $this->user = User::create([
            'first_name' => 'Badge',
            'last_name' => 'Tester',
            'email' => 'badge.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        return $this->user;
    }

    public function testNoSessionUserIdPassesThroughWithoutTouchingAnyAccount(): void
    {
        $user = $this->createUser();
        $crypto = new MailCredentialCryptoService();

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 3,
            'mail_last_uid_seen' => '42',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
        ]);

        unset($_SESSION['user_id']);

        $middleware = new MailBadgeRefreshMiddleware($this->makeBadgeService(), new NullLogger());
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(200, $response->getStatusCode());

        $account = UserMailAccount::where('user_id', $user->id)->first();
        $this->assertSame(3, $account->mail_last_unseen_count);
        $this->assertSame('42', $account->mail_last_uid_seen);
    }

    public function testUserWithoutMailAccountPassesThroughCleanly(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;

        $middleware = new MailBadgeRefreshMiddleware($this->makeBadgeService(), new NullLogger());
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(UserMailAccount::where('user_id', $user->id)->first());
    }

    public function testFreshlyCheckedAccountInsideStalenessWindowIsNotRefreshed(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        $checkedAt = Carbon::now()->subMinutes(2);

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => $checkedAt,
        ]);

        $middleware = new MailBadgeRefreshMiddleware($this->makeBadgeService(), new NullLogger());
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(200, $response->getStatusCode());

        $account = UserMailAccount::where('user_id', $user->id)->first();
        $this->assertSame(9, $account->mail_last_unseen_count);
        $this->assertSame('77', $account->mail_last_uid_seen);
        $this->assertSame(
            $checkedAt->format('Y-m-d H:i:s'),
            Carbon::parse($account->mail_last_checked_at)->format('Y-m-d H:i:s')
        );
    }

    public function testStaleAccountTriggersRefreshAttemptAndRequestStillCompletes(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        $staleCheckedAt = Carbon::now()->subMinutes(10);

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => $staleCheckedAt,
        ]);

        $middleware = new MailBadgeRefreshMiddleware($this->makeBadgeService(), new NullLogger());
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(200, $response->getStatusCode());

        // Refresh attempted against an unreachable host fails gracefully:
        // cached columns remain exactly as they were (refresh() contract).
        $account = UserMailAccount::where('user_id', $user->id)->first();
        $this->assertSame(9, $account->mail_last_unseen_count);
        $this->assertSame('77', $account->mail_last_uid_seen);
    }
}

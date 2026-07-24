<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\RotateMailCredentialKeyCommand;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailCredentialCryptoService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\Bootstrap;

final class RotateMailCredentialKeyCommandFeatureTest extends TestCase
{
    private const ENV_KEY = 'MAIL_CREDENTIAL_KEY';
    private const ENV_PREVIOUS_KEY = 'MAIL_CREDENTIAL_KEY_PREVIOUS';

    private User $user;
    private ?string $originalKey = null;
    private ?string $originalPreviousKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->originalKey = $_ENV[self::ENV_KEY] ?? null;
        $this->originalPreviousKey = $_ENV[self::ENV_PREVIOUS_KEY] ?? null;

        // The rotate command sweeps the whole user_mail_accounts table. Feature
        // tests share the dev database, which already holds seeded accounts
        // sealed with the real MAIL_CREDENTIAL_KEY — under this test's random
        // keys those would count as failures and break the exit-code
        // assertions. Run each test inside a transaction and clear the table so
        // the sweep sees only this test's controlled rows; the rollback in
        // tearDown restores everything, even when an assertion fails.
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        UserMailAccount::query()->delete();

        $this->user = User::create([
            'first_name' => 'Rotation',
            'last_name' => 'Tester',
            'email' => 'rotation.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $this->setEnv(self::ENV_KEY, $this->originalKey);
        $this->setEnv(self::ENV_PREVIOUS_KEY, $this->originalPreviousKey);

        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    private static function randomKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private function createAccount(MailCredentialCryptoService $crypto, string $password): UserMailAccount
    {
        return UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'rotation@example.org',
            'imap_password_enc' => $crypto->encrypt($password),
        ]);
    }

    /**
     * @return array{0:MailCredentialCryptoService,1:string} rotated service and the old key
     */
    private function rotateKeys(): array
    {
        $oldKey = self::randomKey();
        $this->setEnv(self::ENV_KEY, $oldKey);
        $this->setEnv(self::ENV_PREVIOUS_KEY, null);
        $oldService = new MailCredentialCryptoService();

        return [$oldService, $oldKey];
    }

    private function activateNewKey(string $oldKey): MailCredentialCryptoService
    {
        $this->setEnv(self::ENV_KEY, self::randomKey());
        $this->setEnv(self::ENV_PREVIOUS_KEY, $oldKey);

        return new MailCredentialCryptoService();
    }

    private function makeTester(MailCredentialCryptoService $crypto): CommandTester
    {
        return new CommandTester(new RotateMailCredentialKeyCommand($crypto, new NullLogger()));
    }

    public function testRewrapsAccountsEncryptedWithThePreviousKey(): void
    {
        [$oldService, $oldKey] = $this->rotateKeys();
        $account = $this->createAccount($oldService, 'imap-secret-1');
        $storedBefore = (string) $account->imap_password_enc;

        $newService = $this->activateNewKey($oldKey);
        $exitCode = $this->makeTester($newService)->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $reloaded = UserMailAccount::query()->findOrFail($account->id);
        $this->assertNotSame($storedBefore, (string) $reloaded->imap_password_enc);
        $this->assertFalse($newService->needsRewrap((string) $reloaded->imap_password_enc));
        $this->assertSame('imap-secret-1', $newService->decrypt((string) $reloaded->imap_password_enc));
    }

    public function testIsIdempotentAndSkipsAlreadyCurrentAccounts(): void
    {
        [$oldService, $oldKey] = $this->rotateKeys();
        $account = $this->createAccount($oldService, 'imap-secret-2');

        $newService = $this->activateNewKey($oldKey);
        $this->makeTester($newService)->execute([]);

        $storedAfterFirstRun = (string) UserMailAccount::query()->findOrFail($account->id)->imap_password_enc;

        $tester = $this->makeTester($newService);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('übersprungen: 1', $tester->getDisplay());

        $storedAfterSecondRun = (string) UserMailAccount::query()->findOrFail($account->id)->imap_password_enc;
        $this->assertSame($storedAfterFirstRun, $storedAfterSecondRun);
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        [$oldService, $oldKey] = $this->rotateKeys();
        $account = $this->createAccount($oldService, 'imap-secret-3');
        $storedBefore = (string) $account->imap_password_enc;

        $newService = $this->activateNewKey($oldKey);
        $tester = $this->makeTester($newService);
        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Probelauf', $tester->getDisplay());

        $reloaded = UserMailAccount::query()->findOrFail($account->id);
        $this->assertSame($storedBefore, (string) $reloaded->imap_password_enc);
    }

    public function testReportsFailureWhenAccountCannotBeDecrypted(): void
    {
        [$oldService] = $this->rotateKeys();
        $this->createAccount($oldService, 'imap-secret-4');

        // Neuer Key ohne Übergangs-Key: der alte Ciphertext ist nicht mehr lesbar.
        $this->setEnv(self::ENV_KEY, self::randomKey());
        $this->setEnv(self::ENV_PREVIOUS_KEY, null);
        $orphanedService = new MailCredentialCryptoService();

        $tester = $this->makeTester($orphanedService);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('fehlgeschlagen: 1', $tester->getDisplay());
    }
}

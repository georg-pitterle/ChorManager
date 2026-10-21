<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\RotateMailCredentialKeyCommand;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailCredentialCryptoService;
use App\Util\PasswordHasher;
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
            'password' => PasswordHasher::hash('test123'),
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

    /**
     * Der Formatwechsel v2 -> v3 kommt ohne Schlüsseltausch aus.
     *
     * Die neue Schlüssel-Kennung leitet sich anders ab als die alte, ein
     * v2-Wert am selben Schlüssel gilt deshalb als neu zu verpacken. Genau
     * darauf stützt sich der einmalige Rotationslauf nach der Umstellung:
     * dieselbe MAIL_CREDENTIAL_KEY, kein MAIL_CREDENTIAL_KEY_PREVIOUS, und
     * hinterher steht alles im neuen Format - ohne dass ein Passwort
     * verlorengeht.
     */
    public function testRewrapsVersionTwoValuesWithoutChangingTheKey(): void
    {
        $key = self::randomKey();
        $this->setEnv(self::ENV_KEY, $key);
        $this->setEnv(self::ENV_PREVIOUS_KEY, null);
        $service = new MailCredentialCryptoService();

        // Ein Wert, wie ihn die Fassung vor der Umstellung geschrieben hätte.
        $rawKey = (string) base64_decode($key, true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = base64_encode($nonce . sodium_crypto_secretbox('imap-secret-v2', $nonce, $rawKey));
        $stored = 'v2:' . substr(hash('sha256', $rawKey), 0, 8) . ':' . $payload;

        $account = $this->createAccount($service, 'wird-gleich-ersetzt');
        $account->imap_password_enc = $stored;
        $account->save();

        $exitCode = $this->makeTester($service)->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $reloaded = UserMailAccount::query()->findOrFail($account->id);
        $after = (string) $reloaded->imap_password_enc;
        $this->assertStringStartsWith('v3:' . $service->keyId() . ':', $after);
        $this->assertFalse($service->needsRewrap($after));
        $this->assertSame('imap-secret-v2', $service->decrypt($after));
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

    /**
     * Ein Konto ohne hinterlegtes IMAP-Passwort hat nichts zu entschluesseln. Zaehlte es als
     * Fehler, endete jeder Lauf mit Exit-Code 1 und der Rotationslauf waere dauerhaft rot.
     */
    public function testSkipsAccountsWithoutStoredPassword(): void
    {
        [, $oldKey] = $this->rotateKeys();
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'rotation@example.org',
            'imap_password_enc' => '',
        ]);

        $newService = $this->activateNewKey($oldKey);
        $tester = $this->makeTester($newService);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('übersprungen: 1', $tester->getDisplay());
        $this->assertStringContainsString('fehlgeschlagen: 0', $tester->getDisplay());
    }
}

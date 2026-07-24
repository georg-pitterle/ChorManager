# MAIL_CREDENTIAL_KEY Rotation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Den `MAIL_CREDENTIAL_KEY` austauschbar machen, ohne dass gespeicherte IMAP-Passwörter verloren gehen — inklusive CLI-Kommando für die Re-Verschlüsselung.

**Architecture:** Der Ciphertext bekommt ein versioniertes Format `v2:<keyId>:<base64(nonce.ciphertext)>`, wobei `keyId` die ersten 8 Hex-Zeichen von `sha256(rawKey)` sind. `MailCredentialCryptoService` liest zusätzlich einen optionalen `MAIL_CREDENTIAL_KEY_PREVIOUS` und wählt beim Entschlüsseln den Schlüssel anhand der `keyId`; verschlüsselt wird immer mit dem aktuellen Schlüssel. Ein neues Symfony-Console-Kommando `mail:rotate-key` liest alle `user_mail_accounts`, entschlüsselt mit dem alten und verschlüsselt mit dem neuen Schlüssel. Backup-Metadaten führen die `mail_key_id` mit, damit beim Restore erkennbar ist, welcher Schlüssel dazugehört.

**Tech Stack:** PHP 8.2+, libsodium (`sodium_crypto_secretbox`), Eloquent (`illuminate/database`), Symfony Console, PHP-DI, PHPUnit.

## Global Constraints

- PSR-12, 4 Spaces, Zeilenlänge soft 120 / hart 130.
- Alle neuen/geänderten Textdateien mit LF-Zeilenenden (`.ps1`/`.bat`/`.cmd` ausgenommen).
- Deutsche Texte mit echten Umlauten (`ä`, `ö`, `ü`, `ß`) — niemals `ae`/`oe`/`ue`/`ss`.
- Logging ausschließlich über `Psr\Log\LoggerInterface`, strukturiert, mit stabilem `event`-Key im Context; Exceptions unter dem Key `exception`.
- Kein `error_log()` in `src/`.
- Fail-closed bleibt bestehen: fehlender oder fehlerhafter `MAIL_CREDENTIAL_KEY` wirft im Konstruktor. Neu ist nur, dass `MAIL_CREDENTIAL_KEY_PREVIOUS` **optional** ist — gesetzt aber ungültig wirft ebenfalls.
- Kein `git push` — nach dem letzten Commit stoppen und den Benutzer informieren.
- Tests: `ddev composer test`. Einzelne Datei: `ddev php vendor/bin/phpunit tests/<Pfad>`.
- Style-Gate nach substanziellen PHP-Änderungen: `ddev composer phpcs`, bei Bedarf `ddev composer phpcbf`.

**Nicht Teil dieses Plans (bewusst):** Das Ändern der IMAP-Passwörter beim Mailserver. Bei einer echten Kompromittierung sind die Passwörter selbst kompromittiert; Key-Rotation ersetzt das nicht. Das wird in Task 4 dokumentiert.

**Keine Schema-Änderung:** `user_mail_accounts.imap_password_enc` ist bereits `text NOT NULL` (`db/migrations/20260624204635_create_user_mail_accounts.php:18`). Das neue Format ist länger, passt aber problemlos. Keine Phinx-Migration nötig.

**Seed-Daten:** `DevSeedService::seedUserMailAccounts()` (`src/Services/DevSeedService.php:1555`) befüllt `imap_password_enc` bereits über `MailCredentialCryptoService::encrypt()`. Da keine neue Tabelle und keine neue Relation entsteht, sind keine neuen Seed-Methoden nötig — die vorhandenen Datensätze sind exakt das Testmaterial für `mail:rotate-key`. Task 3 verifiziert das mit einem echten Seed- und Rotationslauf.

---

## File Structure

| Datei | Verantwortung | Status |
|---|---|---|
| `src/Services/MailCredentialCryptoService.php` | Ver-/Entschlüsselung, Key-Auswahl per `keyId`, Rewrap-Bedarf melden | Ändern |
| `src/Commands/RotateMailCredentialKeyCommand.php` | Batch-Re-Verschlüsselung aller `user_mail_accounts` | Neu |
| `bin/rotate_mail_key.php` | CLI-Einstiegspunkt (Container-Bootstrap wie `bin/create_backup.php`) | Neu |
| `src/Dependencies.php` | DI-Wiring für das neue Kommando, `mail_key_id` in `BackupService` | Ändern |
| `src/Services/BackupService.php` | `mail_key_id` in die Backup-Metadaten schreiben | Ändern |
| `tests/Unit/Services/MailCredentialCryptoServiceTest.php` | Unit-Tests Formatwechsel + Fallback | Ändern |
| `tests/Feature/RotateMailCredentialKeyCommandFeatureTest.php` | Feature-Tests des Rotationslaufs gegen die DB | Neu |
| `tests/Unit/Services/BackupServiceTest.php` | Test für `mail_key_id` in den Metadaten | Ändern |
| `.env.example` | `MAIL_CREDENTIAL_KEY_PREVIOUS` dokumentieren | Ändern |
| `README.md` | Abschnitt „Secret Rotation" ersetzen | Ändern |

---

## Task 1: Versioniertes Ciphertext-Format und Zweitschlüssel

**Files:**
- Modify: `src/Services/MailCredentialCryptoService.php` (vollständiger Ersatz, siehe Step 5)
- Test: `tests/Unit/Services/MailCredentialCryptoServiceTest.php`

**Interfaces:**
- Consumes: `App\Util\EnvHelper::read(string $key, string $default = ''): string`
- Produces:
  - `MailCredentialCryptoService::encrypt(string $plaintext): string` — liefert ab jetzt `v2:<keyId>:<base64>`
  - `MailCredentialCryptoService::decrypt(string $encoded): string` — akzeptiert altes (präfixloses) **und** neues Format
  - `MailCredentialCryptoService::keyId(): string` — 8 Hex-Zeichen, identifiziert den aktuellen Schlüssel
  - `MailCredentialCryptoService::needsRewrap(string $encoded): bool` — `true`, wenn der Wert nicht mit dem aktuellen Schlüssel verschlüsselt ist
  - `MailCredentialCryptoService::hasPreviousKey(): bool`
  - Neue Env-Variable: `MAIL_CREDENTIAL_KEY_PREVIOUS` (optional, Base64, 32 Byte)

### Steps

- [ ] **Step 1: Test-Helper auf zwei Env-Variablen umstellen**

Die bestehende Testklasse verwaltet nur `MAIL_CREDENTIAL_KEY` über Instanzfelder. Ersetze den Kopf der Datei `tests/Unit/Services/MailCredentialCryptoServiceTest.php` — von `class MailCredentialCryptoServiceTest extends TestCase` bis einschließlich der Methode `setEnv()` (Zeilen 11–58) — durch:

```php
class MailCredentialCryptoServiceTest extends TestCase
{
    private const ENV_KEY = 'MAIL_CREDENTIAL_KEY';
    private const ENV_PREVIOUS_KEY = 'MAIL_CREDENTIAL_KEY_PREVIOUS';

    /** @var array<string,array{present:bool,env:?string,server:?string}> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach ([self::ENV_KEY, self::ENV_PREVIOUS_KEY] as $key) {
            $this->originalEnv[$key] = [
                'present' => array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER),
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        $this->setEnv(self::ENV_KEY, self::randomKey());
        $this->setEnv(self::ENV_PREVIOUS_KEY, null);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $original) {
            if ($original['present']) {
                $this->setEnv($key, $original['env'] ?? $original['server']);
                continue;
            }

            $this->setEnv($key, null);
        }
    }

    private static function randomKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
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
```

Passe anschließend die vier bestehenden Konstruktor-Tests an, die noch `$this->setEnv(...)` mit einem Argument aufrufen (`testConstructorThrowsWhenKeyIsMissing`, `...IsEmpty`, `...IsWrongLength`, `...IsNotValidBase64`): jeweils `$this->setEnv(self::ENV_KEY, <bisheriger Wert>);`. Für `...IsMissing` ist der Wert `null`, für `...IsEmpty` ist er `''`.

- [ ] **Step 2: Neue Tests schreiben (fehlschlagend)**

Füge diese Testmethoden am Ende der Klasse ein:

```php
    public function testEncryptProducesVersionedFormatWithKeyId(): void
    {
        $service = new MailCredentialCryptoService();

        $encrypted = $service->encrypt('imap-password');

        $this->assertStringStartsWith('v2:' . $service->keyId() . ':', $encrypted);
    }

    public function testKeyIdIsStableAndEightHexCharacters(): void
    {
        $service = new MailCredentialCryptoService();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $service->keyId());
        $this->assertSame($service->keyId(), (new MailCredentialCryptoService())->keyId());
    }

    public function testDecryptReadsLegacyUnversionedCiphertextWithCurrentKey(): void
    {
        $service = new MailCredentialCryptoService();
        $plaintext = 'legacy-imap-password';

        // Format vor der Rotation: base64(nonce . ciphertext) ohne Präfix.
        $rawKey = base64_decode((string) getenv(self::ENV_KEY), true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $legacy = base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, (string) $rawKey));

        $this->assertSame($plaintext, $service->decrypt($legacy));
    }

    public function testDecryptFallsBackToPreviousKeyForOldCiphertext(): void
    {
        $oldService = new MailCredentialCryptoService();
        $oldKey = (string) getenv(self::ENV_KEY);
        $encryptedWithOldKey = $oldService->encrypt('rotate-me');

        $this->setEnv(self::ENV_KEY, self::randomKey());
        $this->setEnv(self::ENV_PREVIOUS_KEY, $oldKey);
        $newService = new MailCredentialCryptoService();

        $this->assertTrue($newService->hasPreviousKey());
        $this->assertSame('rotate-me', $newService->decrypt($encryptedWithOldKey));
    }

    public function testNeedsRewrapIsTrueForForeignKeyIdAndFalseForCurrent(): void
    {
        $oldService = new MailCredentialCryptoService();
        $oldKey = (string) getenv(self::ENV_KEY);
        $encryptedWithOldKey = $oldService->encrypt('rotate-me');

        $this->setEnv(self::ENV_KEY, self::randomKey());
        $this->setEnv(self::ENV_PREVIOUS_KEY, $oldKey);
        $newService = new MailCredentialCryptoService();

        $this->assertTrue($newService->needsRewrap($encryptedWithOldKey));
        $this->assertFalse($newService->needsRewrap($newService->encrypt('rotate-me')));
    }

    public function testDecryptThrowsWhenPreviousKeyIsMissing(): void
    {
        $oldService = new MailCredentialCryptoService();
        $encryptedWithOldKey = $oldService->encrypt('unreachable');

        $this->setEnv(self::ENV_KEY, self::randomKey());
        $newService = new MailCredentialCryptoService();

        $this->expectException(RuntimeException::class);
        $newService->decrypt($encryptedWithOldKey);
    }

    public function testConstructorThrowsWhenPreviousKeyIsMalformed(): void
    {
        $this->setEnv(self::ENV_PREVIOUS_KEY, base64_encode(random_bytes(16)));

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }
```

- [ ] **Step 3: Tests laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit tests/Unit/Services/MailCredentialCryptoServiceTest.php`
Expected: FAIL — `Error: Call to undefined method App\Services\MailCredentialCryptoService::keyId()`

- [ ] **Step 4: Commit der fehlschlagenden Tests**

```bash
git add tests/Unit/Services/MailCredentialCryptoServiceTest.php
git commit -m "test: cover versioned mail credential ciphertext and previous-key fallback"
```

- [ ] **Step 5: Crypto-Service implementieren**

Ersetze den Inhalt von `src/Services/MailCredentialCryptoService.php` vollständig durch:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Util\EnvHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Symmetric authenticated encryption for IMAP credentials at rest.
 *
 * Uses libsodium's secretbox (XSalsa20-Poly1305). Ciphertexts carry the format
 * marker and a short key id ("v2:<keyId>:<base64(nonce.ciphertext)>") so that a
 * key rotation can tell which stored value still uses the outgoing key.
 *
 * MAIL_CREDENTIAL_KEY holds the active key and is mandatory — a missing or
 * malformed value throws rather than allowing plaintext storage.
 * MAIL_CREDENTIAL_KEY_PREVIOUS is optional and only read during a rotation
 * window; encryption always uses the active key.
 */
final class MailCredentialCryptoService
{
    private const KEY_ENV = 'MAIL_CREDENTIAL_KEY';
    private const PREVIOUS_KEY_ENV = 'MAIL_CREDENTIAL_KEY_PREVIOUS';
    private const FORMAT_PREFIX = 'v2';

    private string $key;
    private ?string $previousKey;
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->key = (string) $this->loadKey(self::KEY_ENV, true);
        $this->previousKey = $this->loadKey(self::PREVIOUS_KEY_ENV, false);
    }

    /**
     * Short, non-reversible identifier of the active key.
     */
    public function keyId(): string
    {
        return self::deriveKeyId($this->key);
    }

    public function hasPreviousKey(): bool
    {
        return $this->previousKey !== null;
    }

    /**
     * Encrypt plaintext with the active key.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        $payload = base64_encode($nonce . $ciphertext);

        return self::FORMAT_PREFIX . ':' . $this->keyId() . ':' . $payload;
    }

    /**
     * True when the stored value is not (provably) encrypted with the active key.
     *
     * Legacy values without a key id always report true so a rotation run
     * upgrades them to the versioned format.
     */
    public function needsRewrap(string $encoded): bool
    {
        return $this->splitEncoded($encoded)[0] !== $this->keyId();
    }

    /**
     * Decrypt a value previously produced by encrypt(), including values written
     * before the versioned format was introduced.
     *
     * @throws RuntimeException when the input is malformed, tampered with,
     *     or cannot be decrypted with any configured key.
     */
    public function decrypt(string $encoded): string
    {
        [$keyId, $payload] = $this->splitEncoded($encoded);

        foreach ($this->candidateKeys($keyId) as $candidate) {
            $plaintext = $this->open($payload, $candidate);
            if ($plaintext !== null) {
                return $plaintext;
            }
        }

        $this->logDecryptFailure($keyId);

        throw new RuntimeException('Unable to decrypt mail credential');
    }

    /**
     * @return list<string>
     */
    private function candidateKeys(?string $keyId): array
    {
        if ($keyId === null) {
            // Legacy ciphertext carries no key id: try active first, then previous.
            return $this->previousKey === null ? [$this->key] : [$this->key, $this->previousKey];
        }

        if ($keyId === $this->keyId()) {
            return [$this->key];
        }

        if ($this->previousKey !== null && $keyId === self::deriveKeyId($this->previousKey)) {
            return [$this->previousKey];
        }

        return [];
    }

    private function open(string $payload, string $key): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * @return array{0:?string,1:string} key id (null for legacy values) and payload
     */
    private function splitEncoded(string $encoded): array
    {
        $parts = explode(':', $encoded, 3);
        if (count($parts) === 3 && $parts[0] === self::FORMAT_PREFIX) {
            return [$parts[1], $parts[2]];
        }

        return [null, $encoded];
    }

    private static function deriveKeyId(string $rawKey): string
    {
        return substr(hash('sha256', $rawKey), 0, 8);
    }

    private function logDecryptFailure(?string $keyId): void
    {
        $this->logger->error('Mail credential decryption failed.', [
            'event' => 'mail_credential.decrypt.failed',
            'key_id' => $keyId,
            'has_previous_key' => $this->previousKey !== null,
        ]);
    }

    private function loadKey(string $env, bool $required): ?string
    {
        $configured = EnvHelper::read($env, '');
        if ($configured === '') {
            if ($required) {
                throw new RuntimeException($env . ' is not configured correctly');
            }

            return null;
        }

        $decoded = base64_decode($configured, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException($env . ' is not configured correctly');
        }

        return $decoded;
    }
}
```

- [ ] **Step 6: LF-Zeilenenden normalisieren**

```powershell
$f = "d:\Proggen\ChorManager\src\Services\MailCredentialCryptoService.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\tests\Unit\Services\MailCredentialCryptoServiceTest.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

- [ ] **Step 7: Unit-Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit tests/Unit/Services/MailCredentialCryptoServiceTest.php`
Expected: PASS (alle Tests, inklusive der vier unveränderten Round-Trip-/Tamper-Tests)

- [ ] **Step 8: Bestandstests der Konsumenten laufen lassen**

Diese Tests schreiben und lesen `imap_password_enc` und müssen das neue Format transparent überstehen:

Run: `ddev php vendor/bin/phpunit tests/Feature/ProfileMailboxFeatureTest.php tests/Feature/MailBadgeRefreshMiddlewareTest.php tests/Unit/Services/MailBadgeServiceTest.php tests/Feature/WebmailControllerFeatureTest.php`
Expected: PASS

- [ ] **Step 9: Style-Gate**

Run: `ddev composer phpcs`
Expected: keine Fehler in `src/Services/MailCredentialCryptoService.php`. Bei Verstößen `ddev composer phpcbf` ausführen und `phpcs` wiederholen.

- [ ] **Step 10: Commit**

```bash
git add src/Services/MailCredentialCryptoService.php tests/Unit/Services/MailCredentialCryptoServiceTest.php
git commit -m "feat: version mail credential ciphertext and support a previous key"
```

---

## Task 2: Rotations-Kommando `mail:rotate-key`

**Files:**
- Create: `src/Commands/RotateMailCredentialKeyCommand.php`
- Create: `bin/rotate_mail_key.php`
- Create: `tests/Feature/RotateMailCredentialKeyCommandFeatureTest.php`
- Modify: `src/Dependencies.php` (Import-Block und Definitions-Array, siehe Step 4)

**Interfaces:**
- Consumes: `MailCredentialCryptoService::keyId()`, `::needsRewrap()`, `::decrypt()`, `::encrypt()`, `::hasPreviousKey()` aus Task 1; `App\Models\UserMailAccount` mit Fillable-Feld `imap_password_enc`
- Produces:
  - `App\Commands\RotateMailCredentialKeyCommand` mit Konstruktor `__construct(MailCredentialCryptoService $crypto, LoggerInterface $logger)`
  - Kommandoname `mail:rotate-key`, Option `--dry-run` (VALUE_NONE)
  - Exit-Codes: `Command::SUCCESS` (0) wenn kein Datensatz fehlschlug, `Command::FAILURE` (1) sonst
  - Log-Events: `mail_credential.rotate.started`, `mail_credential.rotate.failed`, `mail_credential.rotate.completed`

### Steps

- [ ] **Step 1: Feature-Test schreiben (fehlschlagend)**

Create: `tests/Feature/RotateMailCredentialKeyCommandFeatureTest.php`

```php
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
        UserMailAccount::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();

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
        [$oldService, $oldKey] = $this->rotateKeys();
        $this->createAccount($oldService, 'imap-secret-4');

        // Neuer Key ohne Übergangs-Key: der alte Ciphertext ist nicht mehr lesbar.
        $this->setEnv(self::ENV_KEY, self::randomKey());
        $this->setEnv(self::ENV_PREVIOUS_KEY, null);
        $orphanedService = new MailCredentialCryptoService();

        $tester = $this->makeTester($orphanedService);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('fehlgeschlagen: 1', $tester->getDisplay());
        unset($oldKey);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit tests/Feature/RotateMailCredentialKeyCommandFeatureTest.php`
Expected: FAIL — `Error: Class "App\Commands\RotateMailCredentialKeyCommand" not found`

- [ ] **Step 3: Kommando implementieren**

Create: `src/Commands/RotateMailCredentialKeyCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\UserMailAccount;
use App\Services\MailCredentialCryptoService;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-encrypts stored IMAP passwords with the active MAIL_CREDENTIAL_KEY.
 *
 * Intended to be run once per key rotation while MAIL_CREDENTIAL_KEY holds the
 * new key and MAIL_CREDENTIAL_KEY_PREVIOUS still holds the outgoing one. The
 * run is idempotent: values already sealed with the active key are skipped.
 */
class RotateMailCredentialKeyCommand extends Command
{
    protected static string $defaultName = 'mail:rotate-key';

    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly MailCredentialCryptoService $crypto,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail:rotate-key');
        $this->setDescription('Verschlüsselt gespeicherte IMAP-Passwörter mit dem aktuellen MAIL_CREDENTIAL_KEY neu.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur berichten, nichts schreiben.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $keyId = $this->crypto->keyId();

        if ($dryRun) {
            $output->writeln('<comment>Probelauf — es wird nichts geschrieben.</comment>');
        }

        if (!$this->crypto->hasPreviousKey()) {
            $output->writeln(
                '<comment>MAIL_CREDENTIAL_KEY_PREVIOUS ist nicht gesetzt. '
                . 'Datensätze mit einem älteren Schlüssel können nicht gelesen werden.</comment>'
            );
        }

        $this->logger->info('Mail credential key rotation started.', [
            'event' => 'mail_credential.rotate.started',
            'key_id' => $keyId,
            'dry_run' => $dryRun,
        ]);

        $rewrapped = 0;
        $skipped = 0;
        $failed = 0;

        UserMailAccount::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $accounts) use (
                $dryRun,
                &$rewrapped,
                &$skipped,
                &$failed
            ): void {
                foreach ($accounts as $account) {
                    $result = $this->rewrapAccount($account, $dryRun);

                    if ($result === 'rewrapped') {
                        $rewrapped++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                }
            });

        $output->writeln(sprintf(
            '<info>Schlüssel %s — neu verschlüsselt: %d, übersprungen: %d, fehlgeschlagen: %d</info>',
            $keyId,
            $rewrapped,
            $skipped,
            $failed
        ));

        $this->logger->info('Mail credential key rotation completed.', [
            'event' => 'mail_credential.rotate.completed',
            'key_id' => $keyId,
            'dry_run' => $dryRun,
            'rewrapped' => $rewrapped,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return 'rewrapped'|'skipped'|'failed'
     */
    private function rewrapAccount(UserMailAccount $account, bool $dryRun): string
    {
        $stored = (string) $account->imap_password_enc;

        if (!$this->crypto->needsRewrap($stored)) {
            return 'skipped';
        }

        try {
            $plaintext = $this->crypto->decrypt($stored);
        } catch (\Throwable $exception) {
            $this->logger->error('Mail credential rewrap failed.', [
                'event' => 'mail_credential.rotate.failed',
                'user_mail_account_id' => $account->id,
                'exception' => $exception,
            ]);

            return 'failed';
        }

        if (!$dryRun) {
            $account->imap_password_enc = $this->crypto->encrypt($plaintext);
            $account->save();
        }

        sodium_memzero($plaintext);

        return 'rewrapped';
    }
}
```

- [ ] **Step 4: DI-Wiring ergänzen**

In `src/Dependencies.php` den Import-Block um das Kommando erweitern (alphabetisch bei den anderen `App\Commands`-Imports einsortieren; falls noch keiner existiert, direkt vor `use App\Services\...`):

```php
use App\Commands\RotateMailCredentialKeyCommand;
```

Und im Definitions-Array direkt nach der Zeile `MailCredentialCryptoService::class => \DI\autowire(),` (aktuell `src/Dependencies.php:130`) einfügen:

```php
        RotateMailCredentialKeyCommand::class => \DI\autowire(),
```

- [ ] **Step 5: CLI-Einstiegspunkt anlegen**

Create: `bin/rotate_mail_key.php`

```php
<?php

declare(strict_types=1);

use App\Commands\RotateMailCredentialKeyCommand;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../src/Settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/../src/Dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();
$container->get(Capsule::class);

$application = new Application('ChorManager Mail Key Rotation');
$application->addCommand($container->get(RotateMailCredentialKeyCommand::class));
$application->setDefaultCommand('mail:rotate-key', true);

$application->run();
```

- [ ] **Step 6: LF-Zeilenenden normalisieren**

```powershell
foreach ($f in @(
    "d:\Proggen\ChorManager\src\Commands\RotateMailCredentialKeyCommand.php",
    "d:\Proggen\ChorManager\bin\rotate_mail_key.php",
    "d:\Proggen\ChorManager\src\Dependencies.php",
    "d:\Proggen\ChorManager\tests\Feature\RotateMailCredentialKeyCommandFeatureTest.php"
)) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

- [ ] **Step 7: Feature-Test laufen lassen**

Run: `ddev php vendor/bin/phpunit tests/Feature/RotateMailCredentialKeyCommandFeatureTest.php`
Expected: PASS (4 Tests)

- [ ] **Step 8: Style-Gate**

Run: `ddev composer phpcs`
Expected: keine Fehler. Bei Verstößen `ddev composer phpcbf` ausführen und wiederholen.

- [ ] **Step 9: Commit**

```bash
git add src/Commands/RotateMailCredentialKeyCommand.php bin/rotate_mail_key.php src/Dependencies.php tests/Feature/RotateMailCredentialKeyCommandFeatureTest.php
git commit -m "feat: add mail:rotate-key command to re-encrypt IMAP credentials"
```

---

## Task 3: Rotationslauf gegen echte Seed-Daten verifizieren

**Files:**
- Keine Codeänderung. Verifikationsschritt gegen `src/Services/DevSeedService.php:1555` (`seedUserMailAccounts()`).

**Interfaces:**
- Consumes: `mail:rotate-key` aus Task 2; `bin/dev_seed.php`

### Steps

- [ ] **Step 1: Aktuellen Key sichern und Seed laufen lassen**

Notiere den Wert von `MAIL_CREDENTIAL_KEY` aus `.env` — er wird in Step 2 zu `MAIL_CREDENTIAL_KEY_PREVIOUS`.

Run: `ddev composer seed:dev`
Expected: Seed-Report mit einer Zeile für die Mail-Accounts, Anzahl > 0.

- [ ] **Step 2: Neuen Schlüssel erzeugen und Übergangsfenster öffnen**

Run: `ddev php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;"`

Trage in `.env` ein: den ausgegebenen Wert als `MAIL_CREDENTIAL_KEY`, den bisherigen Wert als `MAIL_CREDENTIAL_KEY_PREVIOUS`.

- [ ] **Step 3: Probelauf**

Run: `ddev php bin/rotate_mail_key.php --dry-run`
Expected: `Probelauf — es wird nichts geschrieben.` und `neu verschlüsselt: <n>, übersprungen: 0, fehlgeschlagen: 0`, wobei `<n>` der Seed-Anzahl aus Step 1 entspricht.

- [ ] **Step 4: Echter Lauf**

Run: `ddev php bin/rotate_mail_key.php`
Expected: `neu verschlüsselt: <n>, übersprungen: 0, fehlgeschlagen: 0`, Exit-Code 0.

- [ ] **Step 5: Idempotenz prüfen**

Run: `ddev php bin/rotate_mail_key.php`
Expected: `neu verschlüsselt: 0, übersprungen: <n>, fehlgeschlagen: 0`

- [ ] **Step 6: Übergangs-Key entfernen und Webmail-Pfad prüfen**

Entferne `MAIL_CREDENTIAL_KEY_PREVIOUS` aus `.env` (oder leere den Wert).

Run: `ddev php bin/rotate_mail_key.php --dry-run`
Expected: Hinweis `MAIL_CREDENTIAL_KEY_PREVIOUS ist nicht gesetzt.` und `neu verschlüsselt: 0, übersprungen: <n>, fehlgeschlagen: 0` — die Daten sind vollständig auf den neuen Schlüssel migriert.

- [ ] **Step 7: Ergebnis berichten**

Halte in der Antwort an den Benutzer fest: Seed-Anzahl, die drei Laufergebnisse und dass keine `mail_credential.rotate.failed`-Events aufgetreten sind.

---

## Task 4: `mail_key_id` in Backup-Metadaten

**Files:**
- Modify: `src/Services/BackupService.php` (Konstruktor `:18-31`, Metadaten-Array `:110-120`)
- Modify: `src/Dependencies.php` (BackupService-Factory `:111-124`)
- Test: `tests/Unit/Services/BackupServiceTest.php`

**Interfaces:**
- Consumes: `MailCredentialCryptoService::keyId()` aus Task 1
- Produces: `BackupService::__construct(..., string $appVersion, ?string $mailKeyId = null)` — der neue Parameter steht **am Ende** und ist optional, damit bestehende Konstruktionsstellen in `tests/Feature/BackupControllerHttpTest.php:30` und `tests/Feature/CreateBackupCommandFeatureTest.php:45` unverändert bleiben. Die Metadaten-JSON jedes Backups enthält zusätzlich den Schlüssel `mail_key_id` (`string|null`).

### Steps

- [ ] **Step 1: Test schreiben (fehlschlagend)**

In `tests/Unit/Services/BackupServiceTest.php` die Factory-Methode um einen optionalen Parameter erweitern. Ersetze den `return new BackupService(` Aufruf (ab Zeile 43) so, dass die Methode einen `?string $mailKeyId = null` entgegennimmt und ihn als letztes Argument durchreicht. Konkret erhält die umschließende Methode die Signatur `private function makeService(?string $mailKeyId = null): BackupService` und der Konstruktoraufruf ein zusätzliches letztes Argument `$mailKeyId`.

Danach diese Tests am Ende der Klasse ergänzen:

```php
    public function testMetadataContainsMailKeyIdWhenConfigured(): void
    {
        $service = $this->makeService('a1b2c3d4');

        $metadata = $service->create(BackupService::TYPE_MANUAL, null);

        $this->assertSame('a1b2c3d4', $metadata['mail_key_id']);
    }

    public function testMetadataMailKeyIdIsNullWhenUnavailable(): void
    {
        $service = $this->makeService();

        $metadata = $service->create(BackupService::TYPE_MANUAL, null);

        $this->assertArrayHasKey('mail_key_id', $metadata);
        $this->assertNull($metadata['mail_key_id']);
    }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit tests/Unit/Services/BackupServiceTest.php`
Expected: FAIL — `Undefined array key "mail_key_id"`

- [ ] **Step 3: BackupService erweitern**

In `src/Services/BackupService.php` den Konstruktor um einen letzten Parameter ergänzen — die Zeile `private readonly string $appVersion` wird zu:

```php
        private readonly string $appVersion,
        private readonly ?string $mailKeyId = null
```

Und im Metadaten-Array (`create()`) nach `'db_name' => $this->dbDatabase,` einfügen:

```php
            'mail_key_id' => $this->mailKeyId,
```

- [ ] **Step 4: DI-Factory erweitern**

In `src/Dependencies.php` die `BackupService`-Factory ersetzen durch:

```php
        BackupService::class => function (ContainerInterface $c) {
            $backupSettings = $c->get('settings')['backup'];

            // Die Key-Id ist reine Metainformation für den Restore-Fall. Ein
            // fehlender oder ungültiger MAIL_CREDENTIAL_KEY darf das Backup
            // nicht blockieren, deshalb hier bewusst fail-open.
            $mailKeyId = null;
            try {
                $mailKeyId = $c->get(MailCredentialCryptoService::class)->keyId();
            } catch (\Throwable) {
                $mailKeyId = null;
            }

            return new BackupService(
                $c->get(DumpRunnerInterface::class),
                $c->get(LoggerInterface::class),
                $backupSettings['dir'],
                $backupSettings['max_manual'],
                $backupSettings['max_auto'],
                $backupSettings['gzip'],
                EnvHelper::read('DB_DATABASE', 'db'),
                $backupSettings['app_version'],
                $mailKeyId
            );
        },
```

- [ ] **Step 5: LF-Zeilenenden normalisieren**

```powershell
foreach ($f in @(
    "d:\Proggen\ChorManager\src\Services\BackupService.php",
    "d:\Proggen\ChorManager\src\Dependencies.php",
    "d:\Proggen\ChorManager\tests\Unit\Services\BackupServiceTest.php"
)) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

- [ ] **Step 6: Backup-Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit tests/Unit/Services/BackupServiceTest.php tests/Feature/BackupControllerHttpTest.php tests/Feature/CreateBackupCommandFeatureTest.php tests/Feature/BackupDependencyWiringFeatureTest.php`
Expected: PASS

- [ ] **Step 7: Style-Gate und Commit**

Run: `ddev composer phpcs`
Expected: keine Fehler.

```bash
git add src/Services/BackupService.php src/Dependencies.php tests/Unit/Services/BackupServiceTest.php
git commit -m "feat: record mail key id in backup metadata"
```

---

## Task 5: Dokumentation

**Files:**
- Modify: `.env.example` (nach Zeile 147, `MAIL_CREDENTIAL_KEY=`)
- Modify: `README.md` (Abschnitt „Secret Rotation", `:138-142`; Event-Tabelle ab `:148`)

**Interfaces:**
- Consumes: alles aus Task 1–4. Keine neuen Symbole.

### Steps

- [ ] **Step 1: `.env.example` ergänzen**

Direkt nach der Zeile `MAIL_CREDENTIAL_KEY=` einfügen:

```
# Optionaler Übergangs-Schlüssel für eine Key-Rotation (gleiches Format).
# Nur setzen, solange noch Datensätze mit dem alten Schlüssel existieren;
# nach erfolgreichem "php bin/rotate_mail_key.php" wieder entfernen.
MAIL_CREDENTIAL_KEY_PREVIOUS=
```

- [ ] **Step 2: README-Abschnitt „Secret Rotation" ersetzen**

Ersetze den `MAIL_CREDENTIAL_KEY`-Absatz (`README.md:140`) durch:

````markdown
**`MAIL_CREDENTIAL_KEY`**: Der Schlüssel lässt sich ohne Datenverlust tauschen. Gespeicherte Werte tragen die Kennung des Schlüssels, mit dem sie verschlüsselt wurden (`v2:<keyId>:<base64>`), sodass ein Rotationslauf sie gezielt neu verschlüsseln kann.

**Wichtig bei Kompromittierung:** Wer den Schlüssel *und* einen Datenbank-Dump besitzt, kennt die IMAP-Passwörter bereits im Klartext. Ein Schlüsseltausch schützt rückwirkend nichts. Reihenfolge im Ernstfall:

1. **IMAP-Passwörter beim Mailserver ändern** — das sind die kompromittierten Geheimnisse.
2. Schlüssel rotieren (siehe unten).
3. Alte Backups bewerten: Jeder Dump, der vor der Rotation gezogen wurde, ist mit dem alten Schlüssel lesbar. Die Metadatei jedes Backups nennt unter `mail_key_id`, welcher Schlüssel dazugehört.

Ablauf der Rotation:

```bash
# 1. Neuen Schlüssel erzeugen
ddev php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;"

# 2. In .env: neuen Wert als MAIL_CREDENTIAL_KEY, bisherigen als MAIL_CREDENTIAL_KEY_PREVIOUS

# 3. Probelauf (schreibt nichts)
ddev php bin/rotate_mail_key.php --dry-run

# 4. Echter Lauf
ddev php bin/rotate_mail_key.php

# 5. MAIL_CREDENTIAL_KEY_PREVIOUS wieder aus .env entfernen
```

Der Lauf ist idempotent — bereits migrierte Datensätze werden übersprungen. Meldet er `fehlgeschlagen: n > 0`, sind `n` Datensätze mit keinem der beiden Schlüssel lesbar; die betroffenen Benutzer müssen ihr IMAP-Passwort im Profil (`/profile`) neu speichern. Details stehen in den `mail_credential.rotate.failed`-Log-Events.

**Restore eines alten Backups:** Enthält die Metadatei eine `mail_key_id`, die nicht zum aktuellen Schlüssel passt, muss der damalige Schlüssel als `MAIL_CREDENTIAL_KEY_PREVIOUS` gesetzt und nach dem Restore einmal `bin/rotate_mail_key.php` ausgeführt werden.
````

- [ ] **Step 3: Event-Tabelle im README ergänzen**

Füge in der Tabelle unter „Monitoring / Log-Events" direkt nach der Zeile zu `mail_credential.decrypt.failed` ein:

```markdown
| `mail_credential.rotate.started` | `RotateMailCredentialKeyCommand` | Rotationslauf gestartet (Context: `key_id`, `dry_run`). |
| `mail_credential.rotate.failed` | `RotateMailCredentialKeyCommand` | Einzelner Datensatz konnte nicht entschlüsselt und damit nicht neu verschlüsselt werden (Context: `user_mail_account_id`). |
| `mail_credential.rotate.completed` | `RotateMailCredentialKeyCommand` | Rotationslauf beendet (Context: `rewrapped`, `skipped`, `failed`). |
```

Passe außerdem die Beschreibung von `mail_credential.decrypt.failed` an — der Satz „Erwartet gelegentlich nach Key-Rotation." wird zu „Nach einer Rotation ein Hinweis auf einen fehlenden `MAIL_CREDENTIAL_KEY_PREVIOUS`."

- [ ] **Step 4: LF-Zeilenenden normalisieren**

```powershell
foreach ($f in @(
    "d:\Proggen\ChorManager\.env.example",
    "d:\Proggen\ChorManager\README.md"
)) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

- [ ] **Step 5: Vollständige Testsuite laufen lassen**

Run: `ddev composer test`
Expected: PASS, inklusive `eol:check` ohne Beanstandung.

- [ ] **Step 6: Commit**

```bash
git add .env.example README.md
git commit -m "docs: describe mail credential key rotation procedure"
```

- [ ] **Step 7: Abschluss melden**

Kein `git push` — die Commits bleiben lokal. Berichte dem Benutzer: geänderte Dateien, ausgeführte Kommandos, Testergebnis, Ergebnis des Seed-Rotationslaufs aus Task 3.

<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MailCredentialCryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testRoundTripEncryptDecryptReturnsOriginalPlaintext(): void
    {
        $service = new MailCredentialCryptoService();
        $plaintext = 'S3cr3t!Pässw0rd #with $pecial &chars;';

        $encrypted = $service->encrypt($plaintext);
        $decrypted = $service->decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptUsesRandomNonceProducingDifferentCiphertext(): void
    {
        $service = new MailCredentialCryptoService();
        $plaintext = 'imap-password-1234';

        $first = $service->encrypt($plaintext);
        $second = $service->encrypt($plaintext);

        $this->assertNotSame($first, $second);
    }

    public function testDecryptThrowsOnTamperedCiphertext(): void
    {
        $service = new MailCredentialCryptoService();
        $encrypted = $service->encrypt('another-imap-password');

        $prefix = 'v3:' . $service->keyId() . ':';
        $raw = base64_decode(substr($encrypted, strlen($prefix)), true);
        $this->assertIsString($raw);
        $tamperedByteIndex = strlen($raw) - 1;
        $raw[$tamperedByteIndex] = chr((ord($raw[$tamperedByteIndex]) + 1) % 256);
        $tampered = $prefix . base64_encode($raw);

        $this->expectException(RuntimeException::class);
        $service->decrypt($tampered);
    }

    public function testDecryptThrowsOnTruncatedCiphertext(): void
    {
        $service = new MailCredentialCryptoService();
        $encrypted = $service->encrypt('yet-another-imap-password');

        $prefix = 'v3:' . $service->keyId() . ':';
        $raw = base64_decode(substr($encrypted, strlen($prefix)), true);
        $this->assertIsString($raw);
        $truncated = $prefix . base64_encode(substr($raw, 0, 5));

        $this->expectException(RuntimeException::class);
        $service->decrypt($truncated);
    }

    public function testConstructorThrowsWhenKeyIsMissing(): void
    {
        $this->setEnv(self::ENV_KEY, null);

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }

    public function testConstructorThrowsWhenKeyIsEmpty(): void
    {
        $this->setEnv(self::ENV_KEY, '');

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }

    public function testConstructorThrowsWhenKeyIsWrongLength(): void
    {
        $this->setEnv(self::ENV_KEY, base64_encode(random_bytes(16)));

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }

    public function testConstructorThrowsWhenKeyIsNotValidBase64(): void
    {
        $this->setEnv(self::ENV_KEY, 'not-valid-base64-!!!');

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }

    public function testEncryptProducesVersionedFormatWithKeyId(): void
    {
        $service = new MailCredentialCryptoService();

        $encrypted = $service->encrypt('imap-password');

        $this->assertStringStartsWith('v3:' . $service->keyId() . ':', $encrypted);
    }

    /**
     * Die Schlüssel-Kennung ist abgeleitet, nicht der blanke Hash des
     * Schlüssels.
     *
     * Im Format v2 stand "die ersten 8 Hex-Zeichen von sha256(Schlüssel)" in
     * jedem gespeicherten Wert - ein kleiner, ungesalzener Abdruck des
     * Geheimnisses selbst, mit dem sich ein geratener Schlüssel ohne jeden
     * Geheimtext bestätigen lässt. Bei 256 Bit Zufall ist das kein praktischer
     * Angriff, aber eine Ableitung mit festem Zweck kostet nichts.
     */
    public function testTheKeyIdIsNotThePlainHashOfTheKey(): void
    {
        $service = new MailCredentialCryptoService();
        $rawKey = (string) base64_decode((string) getenv(self::ENV_KEY), true);

        $this->assertNotSame(substr(hash('sha256', $rawKey), 0, 8), $service->keyId());
    }

    /**
     * Werte aus der Zeit des Formats v2 bleiben lesbar - mit derselben
     * Kennung, die sie damals bekommen haben. Ohne diese Rückwärtsfähigkeit
     * wäre die Umstellung kein Formatwechsel, sondern ein Datenverlust: Jedes
     * gespeicherte IMAP-Passwort und jeder OIDC-Signierschlüssel wäre nicht
     * mehr zu öffnen.
     */
    public function testDecryptStillReadsVersionTwoCiphertext(): void
    {
        $service = new MailCredentialCryptoService();
        $plaintext = 'v2-imap-password';

        $rawKey = (string) base64_decode((string) getenv(self::ENV_KEY), true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $rawKey));
        $legacyKeyId = substr(hash('sha256', $rawKey), 0, 8);

        $this->assertSame($plaintext, $service->decrypt('v2:' . $legacyKeyId . ':' . $payload));
    }

    /**
     * Und sie gelten als neu zu verpacken, damit der Rotationslauf
     * (`mail:rotate-key`) sie einsammelt, statt sie für aktuell zu halten.
     */
    public function testVersionTwoCiphertextNeedsRewrap(): void
    {
        $service = new MailCredentialCryptoService();

        $rawKey = (string) base64_decode((string) getenv(self::ENV_KEY), true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = base64_encode($nonce . sodium_crypto_secretbox('v2-imap-password', $nonce, $rawKey));
        $legacyKeyId = substr(hash('sha256', $rawKey), 0, 8);

        $this->assertTrue($service->needsRewrap('v2:' . $legacyKeyId . ':' . $payload));
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

    /**
     * Ein Backup merkt sich die Kennung des Schlüssels, mit dem es entstand. Von vor
     * der Umstellung auf v3 trägt es die v2-Kennung desselben Schlüssels - die darf
     * nicht als fremder Schlüssel gelten.
     */
    public function testKnownKeyIdsCoverCurrentAndLegacyFormatOfTheActiveKey(): void
    {
        $service = new MailCredentialCryptoService();
        $rawKey = (string) base64_decode((string) getenv(self::ENV_KEY), true);

        $known = $service->knownKeyIds();

        $this->assertContains($service->keyId(), $known);
        $this->assertContains(substr(hash('sha256', $rawKey), 0, 8), $known);
    }

    public function testKnownKeyIdsIncludePreviousKeyAndExcludeForeignKeys(): void
    {
        $oldService = new MailCredentialCryptoService();
        $oldKey = (string) getenv(self::ENV_KEY);
        $this->setEnv(self::ENV_KEY, self::randomKey());
        $this->setEnv(self::ENV_PREVIOUS_KEY, $oldKey);

        $known = (new MailCredentialCryptoService())->knownKeyIds();

        $this->assertContains($oldService->keyId(), $known);
        $this->setEnv(self::ENV_PREVIOUS_KEY, null);
        $this->setEnv(self::ENV_KEY, self::randomKey());
        $this->assertNotContains((new MailCredentialCryptoService())->keyId(), $known);
    }
}

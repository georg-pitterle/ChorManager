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

        $prefix = 'v2:' . $service->keyId() . ':';
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

        $prefix = 'v2:' . $service->keyId() . ':';
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
}

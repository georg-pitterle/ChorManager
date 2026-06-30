<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MailCredentialCryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MailCredentialCryptoServiceTest extends TestCase
{
    private const ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ?string $originalEnvValue = null;
    private ?string $originalServerValue = null;
    private bool $hadEnvValue = false;
    private bool $hadServerValue = false;

    protected function setUp(): void
    {
        $this->hadEnvValue = array_key_exists(self::ENV_KEY, $_ENV);
        $this->originalEnvValue = $_ENV[self::ENV_KEY] ?? null;
        $this->hadServerValue = array_key_exists(self::ENV_KEY, $_SERVER);
        $this->originalServerValue = $_SERVER[self::ENV_KEY] ?? null;

        $this->setEnv(base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    protected function tearDown(): void
    {
        if ($this->hadEnvValue) {
            $_ENV[self::ENV_KEY] = $this->originalEnvValue;
        } else {
            unset($_ENV[self::ENV_KEY]);
        }

        if ($this->hadServerValue) {
            $_SERVER[self::ENV_KEY] = $this->originalServerValue;
        } else {
            unset($_SERVER[self::ENV_KEY]);
        }

        putenv(self::ENV_KEY);
    }

    private function setEnv(?string $value): void
    {
        if ($value === null) {
            unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
            putenv(self::ENV_KEY);
            return;
        }

        $_ENV[self::ENV_KEY] = $value;
        $_SERVER[self::ENV_KEY] = $value;
        putenv(self::ENV_KEY . '=' . $value);
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

        $raw = base64_decode($encrypted, true);
        $this->assertIsString($raw);
        $tamperedByteIndex = strlen($raw) - 1;
        $raw[$tamperedByteIndex] = chr((ord($raw[$tamperedByteIndex]) + 1) % 256);
        $tampered = base64_encode($raw);

        $this->expectException(RuntimeException::class);
        $service->decrypt($tampered);
    }

    public function testDecryptThrowsOnTruncatedCiphertext(): void
    {
        $service = new MailCredentialCryptoService();
        $encrypted = $service->encrypt('yet-another-imap-password');

        $raw = base64_decode($encrypted, true);
        $this->assertIsString($raw);
        $truncated = base64_encode(substr($raw, 0, 5));

        $this->expectException(RuntimeException::class);
        $service->decrypt($truncated);
    }

    public function testConstructorThrowsWhenKeyIsMissing(): void
    {
        $this->setEnv(null);

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }

    public function testConstructorThrowsWhenKeyIsEmpty(): void
    {
        $this->setEnv('');

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }

    public function testConstructorThrowsWhenKeyIsWrongLength(): void
    {
        $this->setEnv(base64_encode(random_bytes(16)));

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }

    public function testConstructorThrowsWhenKeyIsNotValidBase64(): void
    {
        $this->setEnv('not-valid-base64-!!!');

        $this->expectException(RuntimeException::class);
        new MailCredentialCryptoService();
    }
}

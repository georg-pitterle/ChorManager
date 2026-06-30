<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SnappymailSsoTokenService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SnappymailSsoTokenServiceTest extends TestCase
{
    private const ENV_KEY = 'SNAPPYMAIL_SSO_SECRET';

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

    public function testCreateTokenProducesDifferentCiphertextForIdenticalPayload(): void
    {
        $service = new SnappymailSsoTokenService();
        $payload = [
            'email' => 'singer@example.org',
            'imap_user' => 'singer@example.org',
            'smtp_user' => 'singer@example.org',
            'password' => 'S3cr3t!Pässw0rd',
            'exp' => time() + 45,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $first = $service->createToken($payload);
        $second = $service->createToken($payload);

        $this->assertNotSame($first, $second);
    }

    public function testCreateTokenRoundTripsPayloadUsingSamePrimitiveAndKey(): void
    {
        $service = new SnappymailSsoTokenService();
        $key = base64_decode((string) $_ENV[self::ENV_KEY], true);
        $this->assertIsString($key);

        $payload = [
            'email' => 'singer@example.org',
            'imap_user' => 'singer@example.org',
            'smtp_user' => 'singer@example.org',
            'password' => 'S3cr3t!Pässw0rd',
            'exp' => time() + 45,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $token = $service->createToken($payload);

        $decryptedJson = $this->decryptWithKey($token, $key);
        $decodedPayload = json_decode($decryptedJson, true);

        $this->assertIsArray($decodedPayload);
        $this->assertSame($payload, $decodedPayload);
    }

    public function testConstructorThrowsWhenSecretIsMissing(): void
    {
        $this->setEnv(null);

        $this->expectException(RuntimeException::class);
        new SnappymailSsoTokenService();
    }

    public function testConstructorThrowsWhenSecretIsEmpty(): void
    {
        $this->setEnv('');

        $this->expectException(RuntimeException::class);
        new SnappymailSsoTokenService();
    }

    public function testConstructorThrowsWhenSecretIsWrongLength(): void
    {
        $this->setEnv(base64_encode(random_bytes(16)));

        $this->expectException(RuntimeException::class);
        new SnappymailSsoTokenService();
    }

    public function testConstructorThrowsWhenSecretIsNotValidBase64(): void
    {
        $this->setEnv('not-valid-base64-!!!');

        $this->expectException(RuntimeException::class);
        new SnappymailSsoTokenService();
    }

    private function decryptWithKey(string $encoded, string $key): string
    {
        $raw = base64_decode($encoded, true);
        $this->assertIsString($raw);

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        $this->assertIsString($plaintext);

        return $plaintext;
    }
}

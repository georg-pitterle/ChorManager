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
 * Uses libsodium's secretbox (XSalsa20-Poly1305) with a key read from the
 * MAIL_CREDENTIAL_KEY environment variable. Fails closed: if the key is
 * missing or malformed, the constructor throws rather than allowing
 * plaintext storage.
 */
final class MailCredentialCryptoService
{
    private const KEY_ENV = 'MAIL_CREDENTIAL_KEY';

    private string $key;
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->key = $this->loadKey();
    }

    /**
     * Encrypt plaintext, returning base64(nonce . ciphertext).
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a value previously produced by encrypt().
     *
     * @throws RuntimeException when the input is malformed, tampered with,
     *     or cannot be decrypted with the configured key.
     */
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            $this->logDecryptFailure();
            throw new RuntimeException('Unable to decrypt mail credential: malformed input');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            $this->logDecryptFailure();
            throw new RuntimeException('Unable to decrypt mail credential: authentication failed');
        }

        return $plaintext;
    }

    private function logDecryptFailure(): void
    {
        $this->logger->error('Mail credential decryption failed.', [
            'event' => 'mail_credential.decrypt.failed',
        ]);
    }

    private function loadKey(): string
    {
        $configured = EnvHelper::read(self::KEY_ENV, '');
        if ($configured === '') {
            throw new RuntimeException('MAIL_CREDENTIAL_KEY is not configured correctly');
        }

        $decoded = base64_decode($configured, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('MAIL_CREDENTIAL_KEY is not configured correctly');
        }

        return $decoded;
    }
}

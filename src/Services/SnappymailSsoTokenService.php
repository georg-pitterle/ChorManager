<?php

declare(strict_types=1);

namespace App\Services;

use App\Util\EnvHelper;
use RuntimeException;

/**
 * One-directional encryption of short-lived SnappyMail SSO payloads.
 *
 * Uses libsodium's secretbox (XSalsa20-Poly1305) with a key read from the
 * SNAPPYMAIL_SSO_SECRET environment variable. This key protects the trust
 * boundary between ChorManager and the SnappyMail plugin; it is distinct
 * from MAIL_CREDENTIAL_KEY (which protects stored IMAP credentials at
 * rest). Fails closed: if the key is missing or malformed, the constructor
 * throws rather than allowing a token to be created with a default key.
 *
 * ChorManager only ever encrypts. Decryption happens inside the SnappyMail
 * plugin (a different runtime), so no decode method is provided here.
 */
final class SnappymailSsoTokenService
{
    private const KEY_ENV = 'SNAPPYMAIL_SSO_SECRET';

    private string $key;

    public function __construct()
    {
        $this->key = $this->loadKey();
    }

    /**
     * JSON-encode and encrypt the payload, returning base64(nonce . ciphertext).
     *
     * The caller is responsible for rawurlencode()-ing the result when
     * embedding it in a URL query string.
     *
     * @param array<string, mixed> $payload
     */
    public function createToken(array $payload): string
    {
        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    private function loadKey(): string
    {
        $configured = EnvHelper::read(self::KEY_ENV, '');
        if ($configured === '') {
            throw new RuntimeException('SNAPPYMAIL_SSO_SECRET is not configured correctly');
        }

        $decoded = base64_decode($configured, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('SNAPPYMAIL_SSO_SECRET is not configured correctly');
        }

        return $decoded;
    }
}

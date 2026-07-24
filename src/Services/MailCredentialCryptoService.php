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

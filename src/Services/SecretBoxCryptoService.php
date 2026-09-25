<?php

declare(strict_types=1);

namespace App\Services;

use App\Util\EnvHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Symmetrische, authentifizierte Verschlüsselung für ruhende Geheimnisse.
 *
 * Verwendet libsodiums Secretbox (XSalsa20-Poly1305). Der Geheimtext trägt die
 * Formatkennung und eine kurze Schlüssel-Kennung ("v2:<keyId>:<base64(nonce.ciphertext)>"),
 * damit eine Rotation erkennt, welcher gespeicherte Wert noch am alten Schlüssel hängt.
 *
 * Die aktive Schlüssel-Umgebungsvariable ist Pflicht - fehlt oder missfällt sie,
 * wirft der Konstruktor, statt Klartext abzulegen. Die Variable für den vorherigen
 * Schlüssel ist freiwillig und wird nur während einer Rotation gelesen;
 * verschlüsselt wird immer mit dem aktiven Schlüssel.
 *
 * Die Klasse kam aus MailCredentialCryptoService, als der OIDC-Provider dieselbe
 * Mechanik für seinen privaten Signierschlüssel brauchte. Parametrisiert ist sie
 * nur über die Namen der Umgebungsvariablen - das Verhalten ist unverändert.
 */
class SecretBoxCryptoService
{
    private const FORMAT_PREFIX = 'v2';

    private string $keyEnv;
    private ?string $previousKeyEnv;
    private string $key;
    private ?string $previousKey;
    /** Geschützt, nicht privat: Die Unterklasse ist der Name nach außen, und
     *  DependenciesContainerWiringTest liest die Eigenschaft dort per Reflexion. */
    protected LoggerInterface $logger;
    private string $failureEvent;

    public function __construct(
        string $keyEnv,
        ?string $previousKeyEnv = null,
        ?LoggerInterface $logger = null,
        string $failureEvent = 'secret.decrypt.failed'
    ) {
        $this->keyEnv = $keyEnv;
        $this->previousKeyEnv = $previousKeyEnv;
        $this->logger = $logger ?? new NullLogger();
        $this->failureEvent = $failureEvent;
        $this->key = (string) $this->loadKey($this->keyEnv, true);
        $this->previousKey = $this->previousKeyEnv === null
            ? null
            : $this->loadKey($this->previousKeyEnv, false);
    }

    /**
     * Kurze, nicht umkehrbare Kennung des aktiven Schlüssels.
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
     * Verschlüsselt Klartext mit dem aktiven Schlüssel.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        $payload = base64_encode($nonce . $ciphertext);

        return self::FORMAT_PREFIX . ':' . $this->keyId() . ':' . $payload;
    }

    /**
     * Wahr, wenn der gespeicherte Wert nicht (nachweislich) am aktiven Schlüssel hängt.
     *
     * Altwerte ohne Schlüssel-Kennung melden immer wahr, damit ein Rotationslauf
     * sie in das versionierte Format überführt.
     */
    public function needsRewrap(string $encoded): bool
    {
        return $this->splitEncoded($encoded)[0] !== $this->keyId();
    }

    /**
     * Entschlüsselt einen zuvor von encrypt() erzeugten Wert, einschließlich
     * solcher aus der Zeit vor dem versionierten Format.
     *
     * @throws RuntimeException wenn der Wert missgebildet oder verfälscht ist
     *     oder sich mit keinem konfigurierten Schlüssel öffnen lässt.
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

        throw new RuntimeException($this->decryptFailureMessage());
    }

    protected function decryptFailureMessage(): string
    {
        return 'Unable to decrypt value protected by ' . $this->keyEnv;
    }

    /**
     * @return list<string>
     */
    private function candidateKeys(?string $keyId): array
    {
        if ($keyId === null) {
            // Altbestand ohne Schlüssel-Kennung: erst der aktive, dann der vorherige.
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
     * @return array{0:?string,1:string} Schlüssel-Kennung (null bei Altwerten) und Nutzlast
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
        $this->logger->error('Secret decryption failed.', [
            'event' => $this->failureEvent,
            'key_env' => $this->keyEnv,
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

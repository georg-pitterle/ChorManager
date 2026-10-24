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
 * Formatkennung und eine kurze Schlüssel-Kennung ("v3:<keyId>:<base64(nonce.ciphertext)>"),
 * damit eine Rotation erkennt, welcher gespeicherte Wert noch am alten Schlüssel hängt.
 *
 * Die Kennung ist aus dem Schlüssel abgeleitet, nicht sein blanker Hash. Im
 * Format v2 waren es die ersten 8 Hex-Zeichen von sha256(Schlüssel); damit
 * stand in jeder gespeicherten Zeile ein ungesalzener Abdruck des Geheimnisses,
 * an dem sich ein geratener Schlüssel ohne jeden Geheimtext bestätigen ließ.
 * Bei 256 Bit Zufall war das kein praktischer Angriff, eine Ableitung mit
 * festem Zweck kostet aber nichts. Geschrieben wird deshalb nur noch v3;
 * gelesen werden v3, v2 und der Altbestand ganz ohne Präfix, jeweils mit der
 * Kennung, die zu ihrem Format gehört.
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
    /** Das Format, in dem geschrieben wird. */
    private const FORMAT_PREFIX = 'v3';

    /** Das Format davor - nur noch zum Lesen, mit der alten Kennung. */
    private const LEGACY_FORMAT_PREFIX = 'v2';

    /** @var list<string> Formate, deren Präfix erkannt wird. */
    private const KNOWN_FORMATS = [self::FORMAT_PREFIX, self::LEGACY_FORMAT_PREFIX];

    /**
     * Zweckbindung der Ableitung. Fest und dokumentiert, weil ein geänderter
     * Text jede bestehende Kennung ungültig machen würde - und damit jeden
     * gespeicherten Wert als "neu zu verpacken" erscheinen ließe.
     */
    private const KEY_ID_PURPOSE = 'chormanager.secretbox.key-id';

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
     * Kurze, nicht umkehrbare Kennung des aktiven Schlüssels im aktuellen Format.
     */
    public function keyId(): string
    {
        return self::deriveKeyId($this->key, self::FORMAT_PREFIX);
    }

    /**
     * Alle Kennungen, unter denen Werte mit den geladenen Schlüsseln stehen können:
     * aktiver und vorheriger Schlüssel, je in jedem lesbaren Format. Derselbe
     * Schlüssel hatte im Format v2 eine andere Kennung als heute.
     *
     * @return list<string>
     */
    public function knownKeyIds(): array
    {
        $ids = [];
        foreach ([$this->key, $this->previousKey] as $key) {
            if ($key === null) {
                continue;
            }
            foreach (self::KNOWN_FORMATS as $format) {
                $ids[] = self::deriveKeyId($key, $format);
            }
        }

        return array_values(array_unique($ids));
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
     * Wahr, wenn der gespeicherte Wert nicht (nachweislich) im aktuellen Format
     * am aktiven Schlüssel hängt.
     *
     * Altwerte ohne Schlüssel-Kennung melden immer wahr, und ein v2-Wert
     * ebenfalls - auch wenn er am selben Schlüssel hängt. Das ist Absicht: Der
     * Rotationslauf ist die Stelle, an der der Altbestand in das neue Format
     * wandert, und er findet ihn nur, wenn er hier gemeldet wird.
     */
    public function needsRewrap(string $encoded): bool
    {
        [$format, $keyId] = $this->splitEncoded($encoded);

        return $format !== self::FORMAT_PREFIX || $keyId !== $this->keyId();
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
        [$format, $keyId, $payload] = $this->splitEncoded($encoded);

        foreach ($this->candidateKeys($format, $keyId) as $candidate) {
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
     * Die Schlüssel, mit denen dieser Wert aufgehen kann.
     *
     * Verglichen wird die Kennung immer in dem Format, in dem der Wert
     * geschrieben wurde - sonst fiele der ganze v2-Bestand hier heraus und
     * ließe sich nicht mehr öffnen.
     *
     * @return list<string>
     */
    private function candidateKeys(?string $format, ?string $keyId): array
    {
        if ($format === null || $keyId === null) {
            // Altbestand ohne Schlüssel-Kennung: erst der aktive, dann der vorherige.
            return $this->previousKey === null ? [$this->key] : [$this->key, $this->previousKey];
        }

        if ($keyId === self::deriveKeyId($this->key, $format)) {
            return [$this->key];
        }

        if ($this->previousKey !== null && $keyId === self::deriveKeyId($this->previousKey, $format)) {
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
     * @return array{0:?string,1:?string,2:string} Format und Schlüssel-Kennung
     *     (beide null bei Altwerten ohne Präfix) und Nutzlast
     */
    private function splitEncoded(string $encoded): array
    {
        $parts = explode(':', $encoded, 3);
        if (count($parts) === 3 && in_array($parts[0], self::KNOWN_FORMATS, true)) {
            return [$parts[0], $parts[1], $parts[2]];
        }

        return [null, null, $encoded];
    }

    /**
     * Die Kennung eines Schlüssels in einem bestimmten Format.
     *
     * v3 leitet über HMAC mit fester Zweckbindung ab, v2 war der blanke
     * sha256 des Schlüssels. Der alte Weg bleibt hier stehen, weil die
     * gespeicherten v2-Werte ihn tragen und sonst keinem Schlüssel mehr
     * zuzuordnen wären.
     */
    private static function deriveKeyId(string $rawKey, string $format): string
    {
        if ($format === self::LEGACY_FORMAT_PREFIX) {
            return substr(hash('sha256', $rawKey), 0, 8);
        }

        return substr(hash_hmac('sha256', self::KEY_ID_PURPOSE, $rawKey), 0, 8);
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

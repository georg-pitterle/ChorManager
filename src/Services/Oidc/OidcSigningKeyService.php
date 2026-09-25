<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcSigningKey;
use App\Services\SecretBoxCryptoService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Der Schlüsselbund des OIDC-Providers.
 *
 * Erzeugt RSA-2048-Paare, legt den privaten Teil verschlüsselt ab
 * (libsodium-Secretbox, Schlüssel aus OIDC_SIGNING_KEY_SECRET) und liefert den
 * öffentlichen Teil in der Form, die JWKS braucht.
 *
 * Rotation heißt hier: Ein neues Paar wird das aktive, das bisherige verliert
 * `is_active`, bleibt aber in der Tabelle - und damit in JWKS. Ohne diese
 * Karenzzeit bräche im Augenblick der Rotation jedes Zeugnis, das ein Client
 * noch gegen seinen zwischengespeicherten Schlüsselsatz prüft.
 */
class OidcSigningKeyService
{
    public const KEY_ENV = 'OIDC_SIGNING_KEY_SECRET';

    private const KEY_BITS = 2048;

    public function __construct(
        private readonly SecretBoxCryptoService $crypto,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Erzeugt ein Schlüsselpaar und macht es zum aktiven.
     */
    public function generateKey(): OidcSigningKey
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Das RSA-Schlüsselpaar ließ sich nicht erzeugen.');
        }

        $privatePem = '';
        if (openssl_pkey_export($resource, $privatePem) === false) {
            throw new RuntimeException('Der private Schlüssel ließ sich nicht ausgeben.');
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new RuntimeException('Der öffentliche Schlüssel ließ sich nicht ablesen.');
        }

        // Die Kennung leitet sich aus dem öffentlichen Teil ab und ist damit
        // stabil und nicht erratbar zurückrechenbar - sie steht im JWT-Header
        // und in JWKS, ein Zufallswert täte es auch, wäre aber nicht prüfbar.
        $kid = substr(hash('sha256', (string) $details['key']), 0, 32);

        OidcSigningKey::query()->update(['is_active' => false]);

        $key = OidcSigningKey::create([
            'kid' => $kid,
            'public_key' => (string) $details['key'],
            'private_key_encrypted' => $this->crypto->encrypt($privatePem),
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('OIDC signing key generated.', [
            'event' => 'oidc.key.generated',
            'kid' => $kid,
        ]);

        return $key;
    }

    public function activeKey(): ?OidcSigningKey
    {
        return OidcSigningKey::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Alle Schlüssel, aktive wie abgelöste - genau das, was in JWKS gehört.
     *
     * @return list<OidcSigningKey>
     */
    public function allKeys(): array
    {
        return array_values(
            OidcSigningKey::query()->orderByDesc('is_active')->orderByDesc('id')->get()->all()
        );
    }

    /**
     * Der entschlüsselte private Schlüssel des aktiven Paares.
     *
     * @throws RuntimeException wenn kein aktiver Schlüssel existiert oder sich
     *     der gespeicherte nicht öffnen lässt.
     */
    public function activePrivateKey(): string
    {
        $key = $this->activeKey();
        if ($key === null) {
            throw new RuntimeException('Es ist kein aktiver OIDC-Signierschlüssel hinterlegt.');
        }

        return $this->crypto->decrypt((string) $key->private_key_encrypted);
    }

    /**
     * Entfernt abgelöste Schlüssel, die älter als die Karenzzeit sind.
     *
     * Der aktive bleibt immer stehen, auch wenn er älter ist - sonst stünde die
     * Anwendung nach langer Ruhe ohne Signierschlüssel da.
     */
    public function pruneRetiredKeys(int $graceDays = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($graceDays * 86400));

        $removed = (int) OidcSigningKey::query()
            ->where('is_active', false)
            ->where('created_at', '<', $cutoff)
            ->delete();

        if ($removed > 0) {
            $this->logger->info('Retired OIDC signing keys removed.', [
                'event' => 'oidc.key.pruned',
                'removed' => $removed,
            ]);
        }

        return $removed;
    }
}

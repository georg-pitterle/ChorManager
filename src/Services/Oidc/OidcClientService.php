<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcClient;
use App\Util\PasswordHasher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Verwaltet die angeschlossenen Anwendungen und prüft ihren Ausweis.
 *
 * Das Client-Secret wird wie ein Benutzerpasswort gehasht und mit
 * password_verify() verglichen. Es verlässt diese Klasse genau einmal, beim
 * Anlegen - danach steht in der Datenbank nur der Hash, und verloren heißt neu
 * ausstellen.
 *
 * Die Rücksprungadresse wird immer vollständig und genau verglichen, nie per
 * Präfix. Ein Präfixvergleich ließe einen angehängten Pfad oder Query-Teil
 * durch, und der frisch ausgestellte Code ginge an einen fremden Endpunkt.
 */
class OidcClientService
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * @param list<string> $redirectUris
     * @param list<string> $postLogoutRedirectUris
     * @return array{client: OidcClient, secret: string}
     */
    public function createClient(
        string $name,
        array $redirectUris,
        array $postLogoutRedirectUris = [],
        bool $isTrusted = true
    ): array {
        $clientId = bin2hex(random_bytes(16));
        $secret = self::generateSecret();

        $client = OidcClient::create([
            'client_id' => $clientId,
            'client_secret_hash' => PasswordHasher::hash($secret),
            'name' => $name,
            'redirect_uris' => OidcClient::encodeUriList($redirectUris),
            'post_logout_redirect_uris' => OidcClient::encodeUriList($postLogoutRedirectUris),
            'is_trusted' => $isTrusted,
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('OIDC client registered.', [
            'event' => 'oidc.client.created',
            'client_id' => $clientId,
            'name' => $name,
        ]);

        return ['client' => $client, 'secret' => $secret];
    }

    public function findActiveClient(string $clientId): ?OidcClient
    {
        if ($clientId === '') {
            return null;
        }

        return OidcClient::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Prüft Client-Kennung und Secret. Ein Fehlschlag sagt nach außen nie,
     * welcher der beiden Teile nicht stimmte.
     */
    public function authenticate(string $clientId, string $secret): ?OidcClient
    {
        $client = $this->findActiveClient($clientId);
        if ($client === null || $secret === '') {
            // Auch ohne Treffer einmal vergleichen, damit die Antwortzeit nicht
            // verrät, ob es die Kennung überhaupt gibt - dieselbe Vorsichtsmaßnahme
            // wie beim Anmeldeformular.
            password_verify($secret, PasswordHasher::dummyHash());

            return null;
        }

        if (!password_verify($secret, (string) $client->client_secret_hash)) {
            $this->logger->warning('OIDC client authentication failed.', [
                'event' => 'oidc.client.rejected',
                'client_id' => $clientId,
            ]);

            return null;
        }

        return $client;
    }

    /**
     * Es gibt keine Einwilligungsseite - der einzige Client ist die eigene
     * Instanz. Ein nicht als vertrauenswürdig gekennzeichneter Client wird
     * deshalb abgewiesen, statt stillschweigend ohne Einwilligung zu laufen.
     */
    public function isUsableForLogin(?OidcClient $client): bool
    {
        return $client !== null && (bool) $client->is_active && (bool) $client->is_trusted;
    }

    public function isRedirectUriAllowed(OidcClient $client, string $candidate): bool
    {
        return self::matchesExactly($client->redirectUris(), $candidate);
    }

    public function isPostLogoutRedirectAllowed(OidcClient $client, string $candidate): bool
    {
        return self::matchesExactly($client->postLogoutRedirectUris(), $candidate);
    }

    /**
     * Was sich überhaupt eintragen lässt: absolute https-Adressen, ohne
     * Fragment. http nur gegen die Schleife auf dem eigenen Rechner, sonst
     * liefe der Autorisierungscode im Klartext über das Netz.
     */
    public function isRegistrableRedirectUri(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 512) {
            return false;
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * @param list<string> $registered
     */
    private static function matchesExactly(array $registered, string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        foreach ($registered as $uri) {
            if (hash_equals($uri, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

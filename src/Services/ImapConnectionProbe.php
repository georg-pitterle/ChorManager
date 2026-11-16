<?php

declare(strict_types=1);

namespace App\Services;

use App\Util\BlockedHostException;
use App\Util\OutboundConnectionGuard;
use Closure;

/**
 * Prüft, ob unter Host und Port ein IMAP-Server antwortet.
 *
 * Den Socket öffnete der ProfileController selbst, mitten in der Methode. Der Dienst
 * nimmt Auflösung und Verbindung als Closures entgegen, damit sich der Test ohne
 * Netz eigene hereinreichen kann; die Vorgaben sind der Code von früher.
 */
class ImapConnectionProbe
{
    /** Der Server hat sich mit `* ` gemeldet. */
    public const OK = 'ok';

    /** Der Host löst nicht auf eine öffentliche Adresse auf; es wurde nicht verbunden. */
    public const BLOCKED = 'blocked';

    /** Keine Verbindung zustande gekommen. */
    public const UNREACHABLE = 'unreachable';

    /** Verbunden, aber keine IMAP-Begrüßung. */
    public const NO_GREETING = 'no_greeting';

    private Closure $resolveIp;
    private Closure $connect;

    /**
     * @param ?Closure(string): string $resolveIp wirft BlockedHostException;
     *     Vorgabe OutboundConnectionGuard::resolvePublicIp()
     * @param ?Closure(string, string): ?string $connect bekommt die Zieladresse
     *     (z. B. "ssl://203.0.113.5:993") und den Hostnamen für die Zertifikatsprüfung;
     *     liefert die erste Zeile der Gegenstelle, '' wenn keine kam, null ohne Verbindung
     */
    public function __construct(?Closure $resolveIp = null, ?Closure $connect = null)
    {
        $this->resolveIp = $resolveIp
            ?? static fn (string $host): string => OutboundConnectionGuard::resolvePublicIp($host);
        $this->connect = $connect
            ?? static fn (string $remote, string $peerName): ?string => self::greetingFrom($remote, $peerName);
    }

    public function probe(string $host, int $port, string $encryption): string
    {
        try {
            $validatedIp = ($this->resolveIp)($host);
        } catch (BlockedHostException) {
            return self::BLOCKED;
        }

        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        // Connect to the validated IP (pinned), but keep TLS peer verification
        // bound to the original hostname so a rebind cannot redirect us.
        $ipForUrl = str_contains($validatedIp, ':') ? '[' . $validatedIp . ']' : $validatedIp;
        $greeting = ($this->connect)($scheme . '://' . $ipForUrl . ':' . $port, $host);

        if ($greeting === null) {
            return self::UNREACHABLE;
        }

        return str_starts_with($greeting, '* ') ? self::OK : self::NO_GREETING;
    }

    private static function greetingFrom(string $remote, string $peerName): ?string
    {
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $peerName,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            // Deliberately generic: $errstr wird nicht weitergereicht, sonst entstünde
            // ein Orakel für offen/geschlossen/gefiltert beim angefragten host:port.
            return null;
        }

        stream_set_timeout($socket, 5);
        $greeting = fgets($socket, 512);
        fclose($socket);

        return $greeting === false ? '' : $greeting;
    }
}

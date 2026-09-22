<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\WebdavAccessToken;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Der Zugang zum schreibgeschützten Noten-Ordner.
 *
 * Das Token ist das Passwort, das in einer Noten-App auf einem Tablet stehen
 * bleibt - nicht das Kontopasswort. Genau darin liegt der Sinn: Es lässt sich
 * einzeln erneuern, ohne die Anmeldung anzutasten, und es taugt zu nichts
 * anderem als zum Lesen der eigenen Noten.
 *
 * Gespeichert wird nur der SHA-256-Hash, wie beim Kalenderabo
 * (CalendarSubscriptionService). Kein Salt und keine Streckung: Der Wert hat
 * 256 Bit Entropie, ist kein Passwort und damit nicht durchprobierbar - und die
 * Anmeldung muss die Zeile über einen Index finden.
 */
class WebdavAccessService
{
    /** Ein Token ist ein 32-Byte-Zufallswert in Hex-Schreibweise. */
    public const TOKEN_PATTERN = '/^[a-f0-9]{64}\z/';

    /**
     * Ein eingehängter Ordner fragt für eine einzige Ansicht ein Dutzend Mal an.
     * Würde jeder Zugriff `last_used_at` schreiben, wäre der lesende Ordner die
     * schreibfreudigste Stelle der Anwendung. Die Spalte beantwortet nur die
     * Frage "hängt hier noch ein Gerät dran" - eine Stunde Auflösung genügt.
     */
    private const LAST_USED_REFRESH_SECONDS = 3600;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger = new NullLogger())
    {
        $this->logger = $logger;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Erzeugt ein neues Token und verwirft ein vorhandenes. Der zurückgegebene
     * Klartext ist die einzige Gelegenheit, ihn anzuzeigen; danach steht in der
     * Datenbank nur noch der Hash. Ein erneuertes Token sperrt damit jedes
     * Gerät aus, auf dem noch das alte steht - das ist der Weg, ein verlorenes
     * Tablet loszuwerden.
     */
    public function rotateTokenForUser(int $userId): string
    {
        $token = bin2hex(random_bytes(32));

        WebdavAccessToken::where('user_id', $userId)->delete();

        WebdavAccessToken::create([
            'user_id' => $userId,
            'token_hash' => self::hashToken($token),
            'created_at' => date('Y-m-d H:i:s'),
            'last_used_at' => null,
        ]);

        return $token;
    }

    public function hasTokenForUser(int $userId): bool
    {
        return WebdavAccessToken::where('user_id', $userId)->exists();
    }

    public function revokeTokenForUser(int $userId): void
    {
        WebdavAccessToken::where('user_id', $userId)->delete();
    }

    /**
     * Prüft die Zugangsdaten eines Basic-Auth-Kopfes.
     *
     * Die E-Mail wird mitgeprüft, obwohl das Token allein schon eindeutig ist:
     * Ein Klient, in dem versehentlich das Token einer anderen Person steht,
     * soll scheitern und nicht stillschweigend deren Noten laden.
     *
     * Archivierte Mitglieder kommen nicht durch - dieselbe Grenze wie beim
     * Kalender-Feed (EventController::exportCalendar). Sonst wäre der
     * Noten-Ordner der einzige Zugang, der ein Ausscheiden überdauert.
     */
    public function authenticate(string $email, string $token): ?User
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $row = WebdavAccessToken::where('token_hash', self::hashToken($token))->first();
        if (!$row instanceof WebdavAccessToken) {
            $this->logger->info('WebDAV-Zugriff mit unbekanntem Token abgewiesen', [
                'event' => 'webdav.auth_failed',
                'reason' => 'unknown_token',
            ]);

            return null;
        }

        $user = User::find((int) $row->user_id);
        if (!$user instanceof User || !(bool) $user->is_active) {
            $this->logger->info('WebDAV-Zugriff eines gesperrten Zugangs abgewiesen', [
                'event' => 'webdav.auth_failed',
                'reason' => 'inactive_user',
                'user_id' => (int) $row->user_id,
            ]);

            return null;
        }

        if (strcasecmp(trim($email), trim((string) $user->email)) !== 0) {
            $this->logger->info('WebDAV-Zugriff mit nicht passender Kennung abgewiesen', [
                'event' => 'webdav.auth_failed',
                'reason' => 'email_mismatch',
                'user_id' => (int) $user->id,
            ]);

            return null;
        }

        $this->touch($row);

        return $user;
    }

    private function touch(WebdavAccessToken $row): void
    {
        $lastUsed = $row->last_used_at;
        $now = time();

        if ($lastUsed !== null && ($now - $lastUsed->getTimestamp()) < self::LAST_USED_REFRESH_SECONDS) {
            return;
        }

        $row->last_used_at = date('Y-m-d H:i:s', $now);
        $row->save();
    }
}

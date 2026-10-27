<?php

declare(strict_types=1);

namespace App\Util;

use Closure;

/**
 * Wechselt einen als root gestarteten Konsolenbefehl auf den Benutzer von PHP-FPM.
 *
 * `docker exec` läuft ohne `-u` als root. Was ein Befehl dann in den Backup-Ordner
 * schreibt, gehört root (Verzeichnisse 0750, Dateien 0640) und ist für die Web-App
 * nicht mehr lesbar, bis der Container neu startet und der Entrypoint die Rechte
 * zurücksetzt. Wer den Befehl richtig mit `-u www-data` aufruft, merkt davon nichts.
 */
final class RootPrivilegeDrop
{
    public const WEB_USER = 'www-data';

    /**
     * @param Closure(): int $effectiveUid
     * @param Closure(string): (array{uid: int, gid: int}|false) $lookupUser
     * @param Closure(string, int, int): bool $switchTo
     */
    public function __construct(
        private readonly Closure $effectiveUid,
        private readonly Closure $lookupUser,
        private readonly Closure $switchTo
    ) {
    }

    public static function fromPosix(): self
    {
        return new self(
            static fn (): int => posix_geteuid(),
            static fn (string $name): array|false => posix_getpwnam($name),
            // Gruppe vor Benutzer: Nach setuid() fehlt das Recht, die Gruppe zu ändern.
            static fn (string $name, int $uid, int $gid): bool => posix_setgid($gid)
                && posix_initgroups($name, $gid)
                && posix_setuid($uid)
        );
    }

    /**
     * Für Konsolenskripte, die in Backup-Ordner oder Dateiablage schreiben: vor allem
     * anderen aufrufen. Lässt sich root nicht ablegen, endet das Skript, statt als root
     * weiterzulaufen und wieder unlesbare Dateien zu hinterlassen.
     */
    public static function enforceForCli(): void
    {
        try {
            self::fromPosix()->dropTo(self::WEB_USER);
        } catch (\RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    /**
     * @return bool true, wenn gewechselt wurde; false, wenn der Prozess nicht als root lief
     * @throws \RuntimeException wenn root nicht abgelegt werden konnte
     */
    public function dropTo(string $user): bool
    {
        if (($this->effectiveUid)() !== 0) {
            return false;
        }

        $entry = ($this->lookupUser)($user);
        if (!is_array($entry)) {
            throw new \RuntimeException('Running as root, but user "' . $user . '" does not exist to switch to.');
        }

        if (!($this->switchTo)($user, (int) $entry['uid'], (int) $entry['gid'])) {
            throw new \RuntimeException('Running as root, but switching to user "' . $user . '" failed.');
        }

        return true;
    }
}

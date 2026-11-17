<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Controller sind die oberste Schicht: Nichts darunter kennt sie, und sie
 * dienen einander nicht als Bibliothek.
 *
 * Anlass war die Schichtprüfung vom 16.11.2026: `MailBranding` holte die
 * Vereinsfarbe aus dem `AppSettingController`, womit jeder Mailversand - auch
 * der aus der Kommandozeile - an einem HTTP-Controller hing, und vier
 * Controller riefen statische Methoden eines anderen.
 *
 * Die Listen unten sind eine Sperrklinke. Sie nennen die beim Anlegen des Tests
 * bekannten Verstöße; verglichen wird auf Gleichheit. Ein neuer Verstoß macht
 * den Test rot, ein behobener ebenfalls - bis sein Eintrag gestrichen ist.
 * Gelesen wird die Quelle ohne Kommentare, weil Verweise im Fließtext erlaubt
 * bleiben.
 */
final class ControllerLayeringTest extends TestCase
{
    /** @var list<string> Pfade relativ zu src/ */
    private const KNOWN_OUTSIDE_IMPORTS = [];

    /** @var list<string> "Aufrufer -> Aufgerufener" */
    private const KNOWN_CROSS_CONTROLLER_CALLS = [];

    /**
     * Controller, die noch selbst Transaktionen öffnen. Offene Posten aus der
     * Schichtprüfung vom 16.11.2026: Ihre Schreibwege sind atomar, stehen aber am
     * falschen Ort. Wer einen davon anfasst, zieht den Block nach dem Muster von
     * FinanceBookingService in einen Dienst und streicht den Eintrag.
     *
     * Der FinanceAccountController ist so gegangen (Antwort auf Review-Lauf 77):
     * FinanceAccountWriteService hält jetzt Transaktion und Regeln.
     *
     * @var list<string>
     */
    private const KNOWN_DATABASE_ACCESS = [
        'PollController',
        'SponsorPackageController',
        'SponsoringContactController',
        'SponsorshipController',
    ];

    public function testNothingBelowTheControllersImportsAController(): void
    {
        $src = dirname(__DIR__, 3) . '/src';
        $found = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
            $isWiring = in_array($relative, ['Routes.php', 'Dependencies.php'], true);
            if ($file->getExtension() !== 'php' || $isWiring || str_starts_with($relative, 'Controllers/')) {
                continue;
            }

            if (preg_match('/\bApp\\\\Controllers\\\\/', self::codeWithoutComments($file->getPathname())) === 1) {
                $found[] = $relative;
            }
        }

        sort($found);
        self::assertSame(self::KNOWN_OUTSIDE_IMPORTS, $found);
    }

    public function testNoControllerReachesIntoAnotherController(): void
    {
        $found = [];

        foreach (self::controllerFiles() as $file) {
            $own = basename($file, '.php');
            preg_match_all('/\b([A-Z]\w*Controller)::/', self::codeWithoutComments($file), $matches);
            foreach (array_unique($matches[1]) as $other) {
                if ($other !== $own) {
                    $found[] = $own . ' -> ' . $other;
                }
            }
        }

        sort($found);
        self::assertSame(self::KNOWN_CROSS_CONTROLLER_CALLS, $found);
    }

    public function testControllersLeaveTheDatabaseConnectionToTheLayersBelow(): void
    {
        $found = [];

        foreach (self::controllerFiles() as $file) {
            $usesCapsule = preg_match(
                '/Illuminate\\\\Database\\\\Capsule\\\\Manager/',
                self::codeWithoutComments($file)
            ) === 1;
            if ($usesCapsule) {
                $found[] = basename($file, '.php');
            }
        }

        sort($found);
        self::assertSame(self::KNOWN_DATABASE_ACCESS, $found);
    }

    /**
     * Dienste kommen aus dem Konstruktor, nicht aus dem Methodenrumpf.
     *
     * Ein `new EventAudienceService()` mitten in einer Methode umgeht den Container:
     * Der Dienst lässt sich weder ersetzen noch im Test vorgeben, und derselbe Dienst
     * entsteht je Aufruf neu - im EventController fünfzehnmal in fünf Controllern.
     * Zwei Ausnahmen: `ModalFormService` ist ein Wertobjekt mit Formular-Kennung, und
     * ein Dienst, der die Sitzung als erstes Argument bekommt
     * (`new AttendanceScopeService($_SESSION)`), muss sie zum Bauzeitpunkt lesen.
     */
    public function testControllersBuildServicesOnlyInTheirConstructor(): void
    {
        $found = [];

        foreach (self::controllerFiles() as $file) {
            $code = self::codeWithoutComments($file);
            preg_match_all('/function\s+(\w+)\s*\(/', $code, $functions, PREG_OFFSET_CAPTURE);
            preg_match_all('/new\s+\\\\?[\w\\\\]*Service\s*\(\s*([^,)]*)/', $code, $news, PREG_OFFSET_CAPTURE);

            foreach ($news[0] as $index => [$expression, $offset]) {
                $method = '';
                foreach ($functions[1] as [$name, $functionOffset]) {
                    if ($functionOffset < $offset) {
                        $method = $name;
                    }
                }

                $sessionBound = trim($news[1][$index][0]) === '$_SESSION';
                if ($method === '__construct' || $sessionBound || str_contains($expression, 'ModalFormService')) {
                    continue;
                }

                $found[] = basename($file, '.php') . '::' . $method;
            }
        }

        $found = array_values(array_unique($found));
        sort($found);
        self::assertSame([], $found, "Dienste per new im Methodenrumpf:\n" . implode("\n", $found));
    }

    /** @return list<string> */
    public static function controllerFiles(): array
    {
        $base = dirname(__DIR__, 3) . '/src/Controllers';
        $files = array_merge(
            glob($base . '/*.php') ?: [],
            glob($base . '/Oidc/*.php') ?: [],
            glob($base . '/Concerns/*.php') ?: []
        );
        self::assertGreaterThan(40, count($files), 'Es wurden kaum Controller gefunden.');

        return $files;
    }

    public static function codeWithoutComments(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}

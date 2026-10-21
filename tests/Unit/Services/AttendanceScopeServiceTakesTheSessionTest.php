<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AttendanceScopeService;
use PHPUnit\Framework\TestCase;

/**
 * Der Anwesenheits-Umfang bekommt die Sitzung von außen, im Konstruktor.
 *
 * Er entscheidet, für wen jemand Anwesenheit und Anmeldungen eintragen darf -
 * eine Rechtefrage, genau wie in `src/Policies`. Dort kommt die Sitzung seit
 * Lauf 45 im Konstruktor, und die Dateiverwaltung reicht sie über `FileActor`
 * herein; dieser Dienst las sie als letzter rechterelevanter Dienst an sechs
 * Stellen selbst aus dem Superglobal.
 *
 * Zwei Dinge hingen daran. Die Antwort konnte sich mitten im Vorgang ändern,
 * weil jeder Aufruf neu nachsah statt einmal beim Bauen - und wer den Dienst
 * prüfen wollte, musste ein Superglobal setzen statt ein Argument zu
 * übergeben. Beides hält diese Prüfung fest: ein einzelnes `$_SESSION[...]`
 * in einer neuen Methode fiele sonst niemandem auf.
 */
class AttendanceScopeServiceTakesTheSessionTest extends TestCase
{
    public function testTheServiceDoesNotReadTheSessionSuperglobal(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Services/AttendanceScopeService.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);

        $offenders = [];
        foreach (explode("\n", $source) as $index => $line) {
            if (str_contains($line, '$_SESSION')) {
                $offenders[] = ($index + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Der Dienst holt sich die Sitzung selbst, statt sie übergeben zu bekommen.'
        );
    }

    /**
     * Das Superglobal wird kurz beiseitegelegt und danach zurückgestellt: Ein
     * Prozess führt mehrere Testklassen aus, und eine verschwundene Sitzung
     * wäre für die nächste ein Rätsel ohne Fundort.
     */
    public function testTheServiceWorksWithoutTheSuperglobal(): void
    {
        $previous = $_SESSION ?? null;
        $_SESSION = [];

        try {
            $all = new AttendanceScopeService(['can_manage_attendance_all' => true]);
            $none = new AttendanceScopeService([]);

            $this->assertTrue($all->canManageOthers());
            $this->assertFalse($none->canManageOthers());
            $this->assertSame([], $none->getManageableUserIds());
        } finally {
            if ($previous === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $previous;
            }
        }
    }

    /**
     * Die übergebene Sitzung gilt, auch wenn im Superglobal etwas anderes
     * steht. Ohne diese Zusicherung bliebe offen, welche von beiden gewinnt -
     * und eine Prüfung, die für jemand anderen antwortet, ist die eine Sache,
     * die der Umbau ausschließen soll.
     */
    public function testThePassedSessionBeatsTheSuperglobal(): void
    {
        $previous = $_SESSION ?? null;
        $_SESSION = ['can_manage_attendance_all' => true];

        try {
            $this->assertFalse((new AttendanceScopeService([]))->canManageOthers());
        } finally {
            if ($previous === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $previous;
            }
        }
    }
}

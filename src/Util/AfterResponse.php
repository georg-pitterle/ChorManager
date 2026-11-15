<?php

declare(strict_types=1);

namespace App\Util;

use Closure;

/**
 * Arbeit, die eine Anfrage nebenbei erledigt, aber erst nachdem die Antwort
 * beim Browser ist.
 *
 * Entstanden am Mailversand im Anfrageweg: Er lief vor der Antwort und mit
 * gesperrter Sitzung. Antwortete der Mailserver nicht, hing die Seite, und
 * jeder weitere Klick derselben Person wartete auf die Sitzungssperre und
 * belegte dabei einen der fünf PHP-FPM-Prozesse - bis die Anwendung für alle
 * stand.
 *
 * Vor der ersten Aufgabe wird die Sitzung geschrieben und freigegeben und die
 * Verbindung zum Browser geschlossen (`fastcgi_finish_request`). Die Arbeit
 * belegt danach nur noch ihren eigenen Prozess.
 *
 * Auf der Kommandozeile wird nichts beim Prozessende eingehängt: Dort gibt es
 * keine Antwort, hinter die man warten könnte, und ein PHPUnit-Lauf würde die
 * gesammelte Arbeit sonst bei seinem eigenen Ende ausführen.
 */
final class AfterResponse
{
    /** @var list<Closure(): void> */
    private array $pending = [];
    private bool $shutdownRegistered = false;

    /**
     * @param Closure(): void $releaseRequest gibt Sitzung und Verbindung frei
     */
    public function __construct(
        private readonly Closure $releaseRequest,
        private readonly bool $runAtShutdown
    ) {
    }

    public static function forCurrentRequest(): self
    {
        return new self(
            static function (): void {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                // Der Vermerk gehört an das Schließen der Sitzung, nicht an das
                // Schließen der Verbindung. Er stand neben
                // `fastcgi_finish_request()`, und die Funktion gibt es nur unter
                // FastCGI: Unter jeder anderen Betriebsart - eingebauter
                // Entwicklungsserver, mod_php - war die Sitzung danach zu, ohne
                // dass es jemand vermerkte. Ein `ensureStarted()` aus einer
                // nachgelagerten Aufgabe hätte sie dann wieder geöffnet und dabei
                // die Sitzungssperre erneut genommen - genau die Sperre, um die
                // herum diese Klasse gebaut ist. Heute startet keine dieser
                // Aufgaben eine Sitzung; der Vermerk nimmt der nächsten die Falle
                // weg.
                SessionConfig::markReleased();

                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
            },
            !in_array(PHP_SAPI, ['cli', 'phpdbg'], true)
        );
    }

    /**
     * Die Aufgabe fängt ihre Fehler selbst ab: Nach der Antwort gibt es keine
     * Fehlerseite mehr, und eine Ausnahme hier ließe die übrigen Aufgaben aus.
     *
     * @param Closure(): void $task
     */
    public function defer(Closure $task): void
    {
        $this->pending[] = $task;

        if ($this->runAtShutdown && !$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(fn () => $this->runPending());
        }
    }

    public function runPending(): void
    {
        if ($this->pending === []) {
            return;
        }

        ($this->releaseRequest)();

        while ($this->pending !== []) {
            $task = array_shift($this->pending);
            $task();
        }
    }
}

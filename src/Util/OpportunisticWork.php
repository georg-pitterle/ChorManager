<?php

declare(strict_types=1);

namespace App\Util;

use Closure;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wie der Fehlschlag einer nebenbei erledigten Arbeit ins Protokoll geht.
 *
 * Drei Stellen taten das Wort für Wort gleich - Mailwarteschlange,
 * Erinnerungen, Mail-Badge: `QueryException` als `warning` mit einem
 * `.skipped`-Ereignis, alles andere als `error` mit einem `.failed`-Ereignis.
 * Verschieden waren nur der Name der Arbeit und der Stamm der beiden
 * Ereignis-Schlüssel. Dass die Regel an einer der drei Stellen fehlte, fiel
 * erst in Review-Lauf 68 auf, und genau so wirkt eine Abschrift: Sie sieht
 * aus wie die anderen, bis man sie Zeile für Zeile daneben legt.
 *
 * Die Unterscheidung selbst ist der Kern: Startet der db-Container beim
 * Ausrollen neu, ist die Datenbank für ein paar Sekunden weg. Die nebenbei
 * erledigte Arbeit ist Kür, der nächste Aufruf holt sie nach - erwartbares
 * Rauschen, kein Fehler. Als Fehlerzeile müsste die tägliche Log-Analyse
 * jede davon anschauen, und sie zeigt auf nichts, was zu beheben wäre.
 */
final class OpportunisticWork
{
    /**
     * Führt die Arbeit aus und behält ihren Fehlschlag bei sich.
     *
     * Nach der Antwort gibt es keine Fehlerseite mehr, und eine Ausnahme von
     * hier ließe die übrigen Aufgaben derselben Anfrage aus.
     *
     * @param string          $label       Der Name der Arbeit, aus dem beide
     *                                     Meldungen entstehen: "<label> skipped
     *                                     (database unavailable)." und
     *                                     "<label> failed.".
     * @param string          $eventPrefix Der gemeinsame Stamm der beiden
     *                                     `event`-Schlüssel, etwa
     *                                     "mail_queue.opportunistic" für
     *                                     "mail_queue.opportunistic.skipped"
     *                                     und ".failed". Der Schlüssel ist laut
     *                                     instructions/logging.md der stabile
     *                                     Bezeichner einer Logzeile - er bleibt
     *                                     deshalb genau der, der vorher dort
     *                                     stand.
     * @param Closure(): void $task
     */
    public static function run(
        LoggerInterface $logger,
        string $label,
        string $eventPrefix,
        Closure $task
    ): void {
        try {
            $task();
        } catch (QueryException $exception) {
            $logger->warning($label . ' skipped (database unavailable).', [
                'event' => $eventPrefix . '.skipped',
                'exception' => $exception,
            ]);
        } catch (Throwable $exception) {
            $logger->error($label . ' failed.', [
                'event' => $eventPrefix . '.failed',
                'exception' => $exception,
            ]);
        }
    }
}

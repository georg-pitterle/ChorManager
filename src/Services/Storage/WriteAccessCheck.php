<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Prüft die Ablagen, in die die Anwendung schreiben muss, und meldet die, bei
 * denen das nicht geht.
 *
 * Anlass ist die Anmeldebegrenzung: Sie zählt die Versuche in Dateien unter
 * `var/rate-limits`. Lässt sich dort nicht schreiben - ein falscher Besitzer
 * nach dem Ausrollen genügt -, lässt sie jeden Versuch durch
 * (`RateLimiterService::allowUnchecked()`). Das ist gewollt, damit ein
 * Dateisystemproblem niemanden aussperrt, heißt aber: Der Schutz gegen
 * Passwort-Durchprobieren ist vollständig aus, und zwar bei jedem
 * Anmeldeversuch. Bemerkt wurde das bisher nur über eine Warnung im Protokoll
 * (`auth.rate_limit.unavailable`) - also erst in der Log-Analyse, nicht beim
 * Blick in die Verwaltung.
 *
 * Die Liste der Ablagen kommt von außen: Was zu prüfen ist und was der Ausfall
 * bedeutet, weiß die Zusammenstellung im Container, nicht diese Klasse.
 */
final class WriteAccessCheck
{
    /**
     * @param list<array{label: string, path: string, consequence: string}> $stores
     * @param (\Closure(string): bool)|null $isWritable auswechselbare Schreibprobe;
     *     Vorgabe ist `is_writable()`. Auswechselbar, weil der Testlauf je nach
     *     Umgebung als root läuft und `is_writable()` dann auch ein Verzeichnis
     *     ohne Schreibrecht bejaht.
     */
    public function __construct(
        private readonly array $stores,
        private readonly ?\Closure $isWritable = null
    ) {
    }

    /**
     * Die Ablagen, in die nicht geschrieben werden kann - leer heißt: alles gut.
     *
     * @return list<array{label: string, path: string, consequence: string}>
     */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->stores,
            fn (array $store): bool => !$this->canWriteIn($store['path'])
        ));
    }

    /**
     * Ein noch nicht angelegtes Verzeichnis ist kein Fehler: Die Dienste legen
     * ihre Ablage beim ersten Gebrauch selbst an. Entscheidend ist dann, ob sich
     * das überhaupt noch machen lässt - also das übergeordnete Verzeichnis.
     */
    private function canWriteIn(string $path): bool
    {
        $probe = $this->isWritable ?? static fn (string $candidate): bool => is_writable($candidate);
        if (is_dir($path)) {
            return $probe($path);
        }

        $parent = dirname($path);

        return is_dir($parent) && $probe($parent);
    }
}

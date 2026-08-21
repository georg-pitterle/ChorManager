<?php

declare(strict_types=1);

namespace App\Services;

use Closure;

/**
 * Gleitendes Zeitfenster: Gezählt werden die Zeitstempel der letzten Versuche,
 * nicht ein Zähler je festem Fenster. Ein festes Fenster ließ an seiner Grenze
 * bis zu doppelt so viele Versuche durch, weil der Zähler auf null sprang,
 * obwohl die vorherigen Versuche noch keine Fensterlänge zurücklagen.
 */
class RateLimiterService
{
    /**
     * Höchstens einmal pro Stunde wird aufgeräumt: Der Kehraus liest jede Datei
     * im Verzeichnis, das lohnt nicht bei jedem Login-Versuch.
     */
    private const GC_INTERVAL_SECONDS = 3600;

    /**
     * Kein Fenster dieser Anwendung ist länger als ein Tag. Was so lange nicht
     * mehr angefasst wurde, kann keine Sperre mehr auslösen.
     */
    private const GC_MAX_AGE_SECONDS = 86400;

    // Bewusst ohne führenden Punkt und ohne .json-Endung: Der Marker soll beim
    // Aufräumen des Verzeichnisses mit erfasst werden, aber nicht selbst als
    // Zählerdatei gelten.
    private const GC_MARKER = 'gc-state.txt';

    private string $storeDir;
    private Closure $clock;

    public function __construct(?string $storeDir = null, ?Closure $clock = null)
    {
        $this->storeDir = $storeDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chormanager_rate_limits');
        if (!is_dir($this->storeDir)) {
            @mkdir($this->storeDir, 0755, true);
        }

        // Die Uhr ist auswechselbar, damit das Verhalten an der Fenstergrenze
        // prüfbar bleibt, ohne im Test echte Minuten zu warten.
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @return array{allowed:bool,retry_after:int,remaining:int}
     */
    public function hit(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $normalizedKey = $this->normalizeKey($key);
        $now = ($this->clock)();

        $path = $this->getPathForKey($normalizedKey);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            // Fail-open to avoid locking out users if filesystem is unavailable.
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
            }

            $raw = stream_get_contents($handle);
            $hits = $this->decodeHits($raw, $now, $windowSeconds);
            $hits[] = $now;

            $attempts = count($hits);
            $allowed = $attempts <= $maxAttempts;
            $remaining = max(0, $maxAttempts - $attempts);

            // Frei wird der nächste Versuch, sobald so viele der ältesten
            // Versuche aus dem Fenster gefallen sind, dass wieder Platz ist.
            $retryAfter = 0;
            if (!$allowed) {
                $blockingHit = $hits[$attempts - $maxAttempts];
                $retryAfter = max(1, ($blockingHit + $windowSeconds) - $now);
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['hits' => $this->prune($hits, $maxAttempts)]));
            fflush($handle);

            $result = [
                'allowed' => $allowed,
                'retry_after' => $retryAfter,
                'remaining' => $remaining,
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        // Aufgeräumt wird erst nach dem Freigeben der Sperre, damit der Kehraus
        // keinen Login-Versuch aufhält.
        $this->collectGarbage($now);

        return $result;
    }

    /**
     * Entfernt Zählerdateien, deren jüngster Versuch aus jedem Fenster gefallen
     * ist. Jeder Schlüssel legt eine eigene Datei an - eine je Mailadresse und je
     * IP-Adresse - und ohne Kehraus bleiben sie für immer im Temp-Verzeichnis
     * liegen, obwohl ihr Inhalt nach Minuten wertlos ist.
     */
    private function collectGarbage(int $now): void
    {
        if (!$this->claimGarbageCollection($now)) {
            return;
        }

        foreach ((array) glob($this->storeDir . DIRECTORY_SEPARATOR . '*.json') as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }

            if ($this->lastHitIn($file) > $now - self::GC_MAX_AGE_SECONDS) {
                continue;
            }

            @unlink($file);
        }
    }

    /**
     * Ist der nächste Kehraus fällig? Der Zeitpunkt des letzten steht in einer
     * eigenen Datei, damit die Entscheidung prozessübergreifend gilt und nicht
     * an der Lebensdauer eines einzelnen Requests hängt.
     */
    private function claimGarbageCollection(int $now): bool
    {
        $marker = $this->storeDir . DIRECTORY_SEPARATOR . self::GC_MARKER;
        $last = is_file($marker) ? (int) @file_get_contents($marker) : null;

        if ($last !== null && $now - $last < self::GC_INTERVAL_SECONDS) {
            return false;
        }

        @file_put_contents($marker, (string) $now, LOCK_EX);

        // Beim allerersten Aufruf gibt es noch nichts aufzuräumen; der Marker
        // ist damit gesetzt und der erste echte Kehraus kommt eine Stunde später.
        return $last !== null;
    }

    /**
     * Zeitstempel des jüngsten gespeicherten Versuchs. Eine unlesbare oder
     * inhaltslose Datei gilt als abgelaufen.
     */
    private function lastHitIn(string $file): int
    {
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return PHP_INT_MIN;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['hits'] ?? null)) {
            return PHP_INT_MIN;
        }

        $timestamps = array_map('intval', array_filter($decoded['hits'], 'is_numeric'));

        return $timestamps === [] ? PHP_INT_MIN : max($timestamps);
    }

    public function reset(string $key): void
    {
        $path = $this->getPathForKey($this->normalizeKey($key));
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function getPathForKey(string $key): string
    {
        return $this->storeDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    private function normalizeKey(string $key): string
    {
        return trim(strtolower($key));
    }

    /**
     * Zeitstempel der Versuche, die noch im Fenster liegen.
     *
     * @return list<int>
     */
    private function decodeHits(string|false $raw, int $now, int $windowSeconds): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        // Zustände aus der Zeit des festen Fensters (Zähler statt Zeitstempel)
        // tragen kein `hits` und beginnen damit einmalig neu.
        if (!is_array($decoded) || !is_array($decoded['hits'] ?? null)) {
            return [];
        }

        $hits = [];
        foreach ($decoded['hits'] as $hit) {
            if (!is_int($hit) && !is_numeric($hit)) {
                continue;
            }

            $timestamp = (int) $hit;
            if ($timestamp > $now - $windowSeconds && $timestamp <= $now) {
                $hits[] = $timestamp;
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * Die Datei darf nicht unbegrenzt wachsen: Für die Entscheidung und für
     * `retry_after` werden nie mehr als `maxAttempts + 1` Zeitstempel gebraucht.
     *
     * @param list<int> $hits
     * @return list<int>
     */
    private function prune(array $hits, int $maxAttempts): array
    {
        $keep = $maxAttempts + 1;
        if (count($hits) <= $keep) {
            return $hits;
        }

        return array_values(array_slice($hits, -$keep));
    }
}

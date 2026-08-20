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

            return [
                'allowed' => $allowed,
                'retry_after' => $retryAfter,
                'remaining' => $remaining,
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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

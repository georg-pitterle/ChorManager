<?php

declare(strict_types=1);

namespace App\Services;

class RateLimiterService
{
    private string $storeDir;

    public function __construct(?string $storeDir = null)
    {
        $this->storeDir = $storeDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chormanager_rate_limits');
        if (!is_dir($this->storeDir)) {
            @mkdir($this->storeDir, 0755, true);
        }
    }

    /**
     * @return array{allowed:bool,retry_after:int,remaining:int}
     */
    public function hit(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $normalizedKey = $this->normalizeKey($key);
        $now = time();

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
            $state = $this->decodeState($raw);

            if ($state['window_started_at'] + $windowSeconds <= $now) {
                $state = [
                    'window_started_at' => $now,
                    'attempts' => 0,
                ];
            }

            $state['attempts']++;
            $allowed = $state['attempts'] <= $maxAttempts;
            $retryAfter = max(0, ($state['window_started_at'] + $windowSeconds) - $now);
            $remaining = max(0, $maxAttempts - $state['attempts']);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);

            return [
                'allowed' => $allowed,
                'retry_after' => $allowed ? 0 : $retryAfter,
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
     * @return array{window_started_at:int,attempts:int}
     */
    private function decodeState(string|false $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'window_started_at' => time(),
                'attempts' => 0,
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'window_started_at' => time(),
                'attempts' => 0,
            ];
        }

        return [
            'window_started_at' => (int) ($decoded['window_started_at'] ?? time()),
            'attempts' => max(0, (int) ($decoded['attempts'] ?? 0)),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Office;

use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Liest die WOPI-Discovery von Collabora: welche Endung sich mit welcher
 * Editor-Adresse bearbeiten oder ansehen lässt.
 *
 * Das Ergebnis liegt in einer Cache-Datei - eine Stunde, ein Fehlschlag nur eine
 * Minute. Sonst wartete bei ausgefallenem Server jede Ordnerseite auf den Timeout.
 * Die Editor-Adresse bekommt den Ursprung aus OFFICE_SERVER_URL: Collabora kennt
 * sich selbst oft nur unter seinem internen Namen.
 */
final class OfficeDiscovery
{
    public const CACHE_SECONDS = 3600;
    public const FAILURE_CACHE_SECONDS = 60;

    /** @var array<string, array{url: string, edit: bool}>|null */
    private ?array $actions = null;

    /**
     * @param \Closure(string): ?string $fetch liefert den Rumpf oder null bei Fehlern
     */
    public function __construct(
        private readonly OfficeSettings $settings,
        private readonly \Closure $fetch,
        private readonly string $cacheFile,
        private readonly LoggerInterface $logger
    ) {
    }

    /** HTTP-Abruf mit kurzem Timeout - im Betrieb der Standard. */
    public static function httpFetcher(): \Closure
    {
        return static function (string $url): ?string {
            $context = stream_context_create(['http' => ['timeout' => 3]]);
            $body = @file_get_contents($url, false, $context);

            return is_string($body) && $body !== '' ? $body : null;
        };
    }

    public function actionFor(string $fileName): ?OfficeAction
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $entry = $extension === '' ? null : ($this->actions()[$extension] ?? null);

        return $entry === null ? null : new OfficeAction($entry['url'], $entry['edit']);
    }

    public function isAvailable(): bool
    {
        return $this->actions() !== [];
    }

    /**
     * @return array<string, array{url: string, edit: bool}>|null null bei unlesbarem XML
     */
    public static function parse(string $xml, string $serverUrl): ?array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document === false) {
            return null;
        }

        $actions = [];
        foreach ($document->xpath('//action') ?: [] as $action) {
            $extension = strtolower((string) $action['ext']);
            $name = (string) $action['name'];
            $urlSrc = (string) $action['urlsrc'];
            if ($extension === '' || $urlSrc === '' || !in_array($name, ['edit', 'view'], true)) {
                continue;
            }
            $isEdit = $name === 'edit';
            if (!isset($actions[$extension]) || ($isEdit && !$actions[$extension]['edit'])) {
                $actions[$extension] = ['url' => self::rebase($urlSrc, $serverUrl), 'edit' => $isEdit];
            }
        }

        return $actions;
    }

    /**
     * Pfad und Query aus der Discovery, Ursprung aus OFFICE_SERVER_URL. Platzhalter
     * wie `<ui=UI_LLCC&>` fallen weg; das Ergebnis endet auf `?` oder `&`, damit
     * `WOPISrc=...` direkt angehängt werden kann.
     */
    private static function rebase(string $urlSrc, string $serverUrl): string
    {
        $parts = parse_url($urlSrc);
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) preg_replace('/<[^>]*>/', '', (string) ($parts['query'] ?? ''));
        $suffix = $query === '' || str_ends_with($query, '&') ? $query : $query . '&';

        return $serverUrl . $path . '?' . $suffix;
    }

    /**
     * @return array<string, array{url: string, edit: bool}>
     */
    private function actions(): array
    {
        if ($this->actions !== null) {
            return $this->actions;
        }

        $cached = $this->readCache();
        $now = Carbon::now()->getTimestamp();
        if ($cached !== null) {
            $ttl = $cached['actions'] === [] ? self::FAILURE_CACHE_SECONDS : self::CACHE_SECONDS;
            if ($now - $cached['fetched_at'] < $ttl) {
                return $this->actions = $cached['actions'];
            }
        }

        $xml = ($this->fetch)($this->settings->internalUrl . '/hosting/discovery');
        $actions = $xml === null ? null : self::parse($xml, $this->settings->serverUrl);
        if ($actions !== null && $actions !== []) {
            $this->writeCache($actions, $now);

            return $this->actions = $actions;
        }

        $this->logger->warning('Office discovery failed.', [
            'event' => 'office.discovery_failed',
            'reason' => $xml === null ? 'unreachable' : 'unreadable',
            'stale_used' => ($cached['actions'] ?? []) !== [],
        ]);

        // Ein abgelaufener, aber erfolgreicher Stand gilt weiter: Sonst schlüge jedes
        // Speichern einer laufenden Sitzung fehl. Er wird so zurückgeschrieben, dass
        // der nächste Versuch nach FAILURE_CACHE_SECONDS kommt.
        $stale = $cached['actions'] ?? [];
        $retryAt = $stale === [] ? $now : $now - self::CACHE_SECONDS + self::FAILURE_CACHE_SECONDS;
        $this->writeCache($stale, $retryAt);

        return $this->actions = $stale;
    }

    /**
     * @return array{actions: array<string, array{url: string, edit: bool}>, fetched_at: int}|null
     */
    private function readCache(): ?array
    {
        $raw = is_file($this->cacheFile) ? @file_get_contents($this->cacheFile) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $usable = is_array($data) && is_array($data['actions'] ?? null)
            && ($data['server'] ?? '') === $this->settings->serverUrl;
        if (!$usable) {
            return null;
        }

        return ['actions' => $data['actions'], 'fetched_at' => (int) ($data['fetched_at'] ?? 0)];
    }

    /**
     * @param array<string, array{url: string, edit: bool}> $actions
     */
    private function writeCache(array $actions, int $fetchedAt): void
    {
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($this->cacheFile, (string) json_encode([
            'server' => $this->settings->serverUrl,
            'fetched_at' => $fetchedAt,
            'actions' => $actions,
        ]), LOCK_EX);
    }
}

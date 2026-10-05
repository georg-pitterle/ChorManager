<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Gemeinsame Bausteine der Office-Tests: feste Adressen, eine Discovery aus der
 * Fixture statt aus einem echten Collabora und eine eigene Cache-Datei je Test.
 */
trait OfficeFixtures
{
    protected string $discoveryCacheFile = '';

    /** @var list<string> */
    protected array $extraDiscoveryCacheFiles = [];

    protected function officeSettings(): OfficeSettings
    {
        return new OfficeSettings(
            'https://office.example.test',
            'http://collabora:9980',
            'http://web',
            'https://chor.example.test'
        );
    }

    protected function discoveryXml(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/office/discovery.xml');
    }

    /**
     * Alle Instanzen eines Tests teilen sich eine Cache-Datei - so wie im Betrieb.
     * `$separateCache` gibt einer Instanz eine eigene, etwa für einen ausgefallenen
     * Server neben einem erreichbaren.
     *
     * @param (\Closure(string): ?string)|null $fetch
     */
    protected function discovery(
        ?\Closure $fetch = null,
        ?LoggerInterface $logger = null,
        bool $separateCache = false
    ): OfficeDiscovery {
        if ($this->discoveryCacheFile === '') {
            $this->discoveryCacheFile = self::newDiscoveryCachePath();
        }
        $cacheFile = $this->discoveryCacheFile;
        if ($separateCache) {
            $cacheFile = self::newDiscoveryCachePath();
            $this->extraDiscoveryCacheFiles[] = $cacheFile;
        }

        return new OfficeDiscovery(
            $this->officeSettings(),
            $fetch ?? fn (string $url): ?string => $this->discoveryXml(),
            $cacheFile,
            $logger ?? new NullLogger()
        );
    }

    protected function tearDownOfficeFixtures(): void
    {
        foreach ([$this->discoveryCacheFile, ...$this->extraDiscoveryCacheFiles] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }
        $this->discoveryCacheFile = '';
        $this->extraDiscoveryCacheFiles = [];
        Carbon::setTestNow();
    }

    private static function newDiscoveryCachePath(): string
    {
        return sys_get_temp_dir() . '/office-discovery-' . bin2hex(random_bytes(6)) . '.json';
    }
}

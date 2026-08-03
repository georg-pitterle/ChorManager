<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;

/**
 * Liest das Log-Level und den SQL-Schalter aus den Anwendungseinstellungen.
 *
 * Der Lesezugriff steckt bewusst in einer Closure, die erst beim ersten Bedarf
 * aufgerufen wird: Der Logger wird dadurch nicht von der Datenbank abhaengig und
 * bleibt funktionsfaehig, wenn diese gerade nicht erreichbar ist.
 */
final class LogLevelResolver
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    /**
     * @param \Closure(): array<string, string> $reader
     */
    public function __construct(
        private readonly \Closure $reader,
        private readonly string $fallbackLevel = 'INFO'
    ) {
    }

    public function level(): Level
    {
        $name = $this->settings()['log_level'] ?? $this->fallbackLevel;

        return self::toLevel($name) ?? self::toLevel($this->fallbackLevel) ?? Level::Info;
    }

    public function isDbWriteLoggingEnabled(): bool
    {
        return ($this->settings()['log_db_writes'] ?? '0') === '1';
    }

    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = ($this->reader)();
            } catch (\Throwable) {
                // Ohne Einstellungen greift der Rueckfallwert. Ein Fehler beim Lesen
                // darf das Logging nie zum Erliegen bringen.
                $this->cache = [];
            }
        }

        return $this->cache;
    }

    private static function toLevel(string $name): ?Level
    {
        try {
            return Level::fromName(strtoupper(trim($name)));
        } catch (\Throwable) {
            return null;
        }
    }
}

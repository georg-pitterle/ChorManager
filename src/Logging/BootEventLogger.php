<?php

declare(strict_types=1);

namespace App\Logging;

use App\Util\AppEnvironment;
use App\Util\EnvHelper;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Schreibt die Lebenszyklus-Ereignisse des Container-Starts in denselben
 * JSON-Stream wie die Anwendung.
 *
 * Der Container-Entrypoint laeuft, bevor die Datenbank erreichbar ist, und kann
 * deshalb weder den DI-Container noch den DB-gestuetzten LogLevelResolver
 * benutzen. Diese Klasse baut den Logger direkt aus der Umgebung und bleibt
 * bewusst frei von Datenbankzugriffen.
 *
 * Die Ereignisse sind das Signal der Betriebs-Alarmierung: eine haeufende
 * Folge von "app.boot.started" bedeutet Crash-Loop, "app.boot.migration_failed"
 * und "app.boot.db_wait_timeout" benennen die Ursache direkt.
 */
final class BootEventLogger
{
    /**
     * Ereignis => [Log-Level, Meldung]. Die Reihenfolge entspricht dem Ablauf im
     * Entrypoint und ist Teil der oeffentlichen Schnittstelle: die Alarmregeln in
     * dist/grafana/chormanager-alerts.yaml filtern auf genau diese Namen.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const EVENTS = [
        'app.boot.started' => [
            LogLevel::INFO,
            'Container-Start begonnen, warte auf die Datenbank.',
        ],
        'app.boot.db_wait_timeout' => [
            LogLevel::CRITICAL,
            'Datenbank war nicht rechtzeitig erreichbar, Start abgebrochen.',
        ],
        'app.boot.migration_failed' => [
            LogLevel::CRITICAL,
            'Datenbank-Migration fehlgeschlagen, Start abgebrochen.',
        ],
        'app.boot.completed' => [
            LogLevel::INFO,
            'Container-Start abgeschlossen, PHP-FPM nimmt Anfragen an.',
        ],
    ];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function create(): self
    {
        return new self(AppLoggerFactory::create([
            'channel' => 'chormanager',
            'service' => 'chormanager',
            'environment' => AppEnvironment::current(),
            'stream' => EnvHelper::read('APP_LOG_STREAM', 'php://stderr'),
            // Absichtlich fest auf DEBUG statt auf APP_LOG_LEVEL: die Boot-Events
            // traegt die Alarmierung. Ein restriktiv gesetztes Log-Level wuerde
            // sonst unbemerkt die Ueberwachung des Starts abschalten.
            'level' => 'DEBUG',
        ]));
    }

    /**
     * @return list<string>
     */
    public static function events(): array
    {
        return array_keys(self::EVENTS);
    }

    public function log(string $event, ?int $exitCode = null): void
    {
        if (!array_key_exists($event, self::EVENTS)) {
            throw new InvalidArgumentException(sprintf('Unbekanntes Boot-Ereignis: %s', $event));
        }

        [$level, $message] = self::EVENTS[$event];

        $context = ['event' => $event];
        if ($exitCode !== null) {
            $context['exit_code'] = $exitCode;
        }

        $this->logger->log($level, $message, $context);
    }
}

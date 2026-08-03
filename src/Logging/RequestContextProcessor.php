<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Haengt die Kenndaten des Requests an jeden Record.
 *
 * Damit lassen sich alle Zeilen eines Aufrufs ueber die request_id zusammenfuehren,
 * was bei Fehlermeldungen aus der Testphase mehr wert ist als jedes Einzelevent.
 */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $data = $this->context->all();

        if ($data === []) {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, $data));
    }
}

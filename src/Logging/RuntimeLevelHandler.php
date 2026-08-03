<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;

/**
 * Reicht Records nur durch, wenn der Resolver das aktuelle Level zulaesst.
 *
 * Der umschlossene Handler wird auf der niedrigsten Stufe gebaut; die
 * Entscheidung faellt hier bei jedem Record neu. Dadurch wirkt eine Aenderung der
 * Einstellung sofort, ohne den Logger neu zu bauen.
 */
final class RuntimeLevelHandler implements HandlerInterface
{
    public function __construct(
        private readonly HandlerInterface $inner,
        private readonly LogLevelResolver $resolver
    ) {
    }

    public function isHandling(LogRecord $record): bool
    {
        if ($record->level->value < $this->resolver->level()->value) {
            return false;
        }

        return $this->inner->isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        return $this->inner->handle($record);
    }

    /**
     * @param array<int, LogRecord> $records
     */
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
        $this->inner->close();
    }
}

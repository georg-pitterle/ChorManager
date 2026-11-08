<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\OpportunisticWork;
use Illuminate\Database\QueryException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpportunisticWorkTest extends TestCase
{
    public function testWorkThatSucceedsLogsNothing(): void
    {
        $logHandler = new TestHandler();
        $calls = 0;

        OpportunisticWork::run(
            new Logger('test', [$logHandler]),
            'Opportunistic test work',
            'test.opportunistic',
            static function () use (&$calls): void {
                $calls++;
            }
        );

        $this->assertSame(1, $calls);
        $this->assertSame([], $logHandler->getRecords());
    }

    /**
     * Eine kurze Datenbank-Störung - der db-Container startet beim Ausrollen neu -
     * ist erwartbares Rauschen: Die Arbeit ist Kür, der nächste Aufruf holt sie
     * nach. Als Fehlerzeile müsste die tägliche Log-Analyse jede davon anschauen.
     */
    public function testATransientDatabaseOutageBecomesAWarningWithTheSkippedEvent(): void
    {
        $logHandler = new TestHandler();

        OpportunisticWork::run(
            new Logger('test', [$logHandler]),
            'Opportunistic test work',
            'test.opportunistic',
            static function (): never {
                throw new QueryException(
                    'mysql',
                    'select 1',
                    [],
                    new \PDOException('SQLSTATE[HY000] [2002] Connection refused')
                );
            }
        );

        $records = $logHandler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('warning', strtolower($records[0]->level->getName()));
        $this->assertSame('Opportunistic test work skipped (database unavailable).', $records[0]->message);
        $this->assertSame('test.opportunistic.skipped', $records[0]->context['event']);
        $this->assertArrayHasKey('exception', $records[0]->context);
    }

    public function testAnyOtherFailureBecomesAnErrorWithTheFailedEvent(): void
    {
        $logHandler = new TestHandler();

        OpportunisticWork::run(
            new Logger('test', [$logHandler]),
            'Opportunistic test work',
            'test.opportunistic',
            static function (): never {
                throw new RuntimeException('kaputt');
            }
        );

        $records = $logHandler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('error', strtolower($records[0]->level->getName()));
        $this->assertSame('Opportunistic test work failed.', $records[0]->message);
        $this->assertSame('test.opportunistic.failed', $records[0]->context['event']);
        $this->assertArrayHasKey('exception', $records[0]->context);
    }

    /**
     * Der Fehlschlag bleibt drinnen: Die Arbeit läuft nach der Antwort, eine
     * Ausnahme hier ließe die übrigen Aufgaben aus und erreichte niemanden.
     */
    public function testTheFailureNeverReachesTheCaller(): void
    {
        OpportunisticWork::run(
            new Logger('test', [new TestHandler()]),
            'Opportunistic test work',
            'test.opportunistic',
            static function (): never {
                throw new RuntimeException('kaputt');
            }
        );

        $this->assertTrue(true, 'Der Aufruf kehrt zurück, statt die Ausnahme weiterzugeben.');
    }
}

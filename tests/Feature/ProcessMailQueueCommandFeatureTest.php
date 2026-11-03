<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\ProcessMailQueueCommand;
use App\Services\MailDeliveryService;
use Illuminate\Database\QueryException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\Bootstrap;

/**
 * Ein kurzer Datenbankausfall (z. B. Neustart des db-Containers beim Deploy) darf keinen ERROR
 * auslösen, weil darauf das Grafana-Alerting feuert. Erst ein anhaltender Ausfall ist ein Fehler.
 */
final class ProcessMailQueueCommandFeatureTest extends TestCase
{
    private string $markerPath;
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $this->markerPath = sys_get_temp_dir() . '/chormanager_mail_queue_outage_test_' . bin2hex(random_bytes(4));
        $this->logHandler = new TestHandler();
    }

    protected function tearDown(): void
    {
        if (is_file($this->markerPath)) {
            unlink($this->markerPath);
        }

        parent::tearDown();
    }

    private function runWith(MailDeliveryService $service): int
    {
        $command = new ProcessMailQueueCommand(
            $service,
            new Logger('test', [$this->logHandler]),
            $this->markerPath
        );

        return (new CommandTester($command))->execute([]);
    }

    private function failingService(\Throwable $exception): MailDeliveryService
    {
        $service = $this->createStub(MailDeliveryService::class);
        $service->method('repairStaleSendingEntries')->willThrowException($exception);

        return $service;
    }

    private function unresolvableHost(): QueryException
    {
        return new QueryException(
            'default',
            'select 1',
            [],
            new \PDOException(
                'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db failed: Name does not resolve',
                2002
            )
        );
    }

    public function testShortDatabaseOutageIsLoggedAsWarningOnly(): void
    {
        $exitCode = $this->runWith($this->failingService($this->unresolvableHost()));

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertFalse($this->logHandler->hasErrorRecords());
        $this->assertTrue($this->logHandler->hasWarningThatPasses(
            fn ($record) => $record->context['event'] === 'mail_queue.process.db_unavailable'
        ));
        $this->assertFileExists($this->markerPath);
    }

    public function testOutageStartIsKeptAcrossRuns(): void
    {
        $startedAt = time() - 60;
        file_put_contents($this->markerPath, (string) $startedAt);

        $this->runWith($this->failingService($this->unresolvableHost()));

        $this->assertSame((string) $startedAt, file_get_contents($this->markerPath));
        $this->assertFalse($this->logHandler->hasErrorRecords());
    }

    public function testLongDatabaseOutageEscalatesToError(): void
    {
        file_put_contents($this->markerPath, (string) (time() - 301));

        $this->runWith($this->failingService($this->unresolvableHost()));

        $this->assertTrue($this->logHandler->hasErrorThatPasses(
            fn ($record) => $record->context['event'] === 'mail_queue.process.failed'
                && $record->context['outage_seconds'] >= 301
        ));
    }

    public function testOtherFailuresStayErrorsImmediately(): void
    {
        $this->runWith($this->failingService(new \RuntimeException('SMTP kaputt')));

        $this->assertTrue($this->logHandler->hasErrorThatPasses(
            fn ($record) => $record->context['event'] === 'mail_queue.process.failed'
        ));
        $this->assertFileDoesNotExist($this->markerPath);
    }

    public function testSuccessfulRunEndsTheOutage(): void
    {
        file_put_contents($this->markerPath, (string) (time() - 120));
        $service = $this->createStub(MailDeliveryService::class);
        $service->method('repairStaleSendingEntries')->willReturn(0);
        $service->method('processDueEntries')->willReturn(['sent' => 0]);

        $exitCode = $this->runWith($service);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileDoesNotExist($this->markerPath);
        $this->assertFalse($this->logHandler->hasRecords(Level::Error));
    }
}

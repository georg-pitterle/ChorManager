<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\SendRegistrationRemindersCommand;
use App\Services\RegistrationReminderService;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SendRegistrationRemindersCommandFeatureTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['FEATURE_REGISTRATION', 'APP_URL'] as $key) {
            $this->originalEnv[$key] = getenv($key);
        }

        $this->setEnv('FEATURE_REGISTRATION', 'true');
        $this->setEnv('APP_URL', 'https://chor.example.org');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $this->setEnv($key, $value);
            }
        }
    }

    public function testSuccessIsLoggedAsDebugJsonAndNotPrintedAtNormalVerbosity(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $command = new SendRegistrationRemindersCommand($this->fakeService(4), $logger);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('eingereiht', $tester->getDisplay());

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $record = $records[0];
        $this->assertSame(Level::Debug, $record->level);
        $this->assertSame('registration_reminder.enqueued', $record->context['event']);
        $this->assertSame(4, $record->context['count']);
    }

    public function testSuccessIsPrintedWhenVerbose(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $command = new SendRegistrationRemindersCommand($this->fakeService(2), $logger);
        $tester = new CommandTester($command);

        $tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertStringContainsString('2 Erinnerungsmails eingereiht.', $tester->getDisplay());
    }

    public function testDisabledFeatureIsLoggedAsDebugAndNotPrintedAtNormalVerbosity(): void
    {
        $this->setEnv('FEATURE_REGISTRATION', 'false');

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $command = new SendRegistrationRemindersCommand($this->fakeService(0), $logger);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('deaktiviert', $tester->getDisplay());

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Debug, $records[0]->level);
        $this->assertSame('registration_reminder.skipped_feature_disabled', $records[0]->context['event']);
    }

    public function testMissingBaseUrlIsLoggedAsErrorAndFails(): void
    {
        $this->clearEnv('APP_URL');

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $command = new SendRegistrationRemindersCommand($this->fakeService(0), $logger);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Error, $records[0]->level);
        $this->assertSame('registration_reminder.missing_base_url', $records[0]->context['event']);
    }

    private function fakeService(int $count): RegistrationReminderService
    {
        return new class ($count) extends RegistrationReminderService {
            public function __construct(private readonly int $count)
            {
            }

            public function processDue(string $baseUrl): int
            {
                return $this->count;
            }
        };
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clearEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

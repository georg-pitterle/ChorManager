<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PhinxMigrationRunner;
use PHPUnit\Framework\TestCase;

final class PhinxMigrationRunnerTest extends TestCase
{
    public function testSuccessfulCommandPasses(): void
    {
        $runner = new PhinxMigrationRunner([PHP_BINARY, '-r', 'echo "All Done";'], sys_get_temp_dir());

        $runner->migrate();

        $this->addToAssertionCount(1);
    }

    /**
     * php-fpm leert die Umgebung; ohne Weitergabe liefe phinx in Produktion über TCP,
     * während die Anwendung den Socket nutzt.
     */
    public function testDatabaseSocketIsPassedToPhinx(): void
    {
        $previous = $_ENV['DB_SOCKET'] ?? null;
        $_ENV['DB_SOCKET'] = '/var/run/mysqld/mysqld.sock';
        putenv('DB_SOCKET');
        $script = 'if (getenv("DB_SOCKET") !== "/var/run/mysqld/mysqld.sock") {'
            . ' fwrite(STDOUT, "DB_SOCKET fehlt\n"); exit(1); }';

        try {
            (new PhinxMigrationRunner([PHP_BINARY, '-r', $script], sys_get_temp_dir()))->migrate();
            $this->addToAssertionCount(1);
        } finally {
            if ($previous === null) {
                unset($_ENV['DB_SOCKET']);
            } else {
                $_ENV['DB_SOCKET'] = $previous;
            }
        }
    }

    public function testFailingCommandThrowsWithTheFirstErrorLine(): void
    {
        $script = 'fwrite(STDOUT, "using config\n"); fwrite(STDOUT, "PDOException: Table x already exists\n"); exit(1);';
        $runner = new PhinxMigrationRunner([PHP_BINARY, '-r', $script], sys_get_temp_dir());

        try {
            $runner->migrate();
            $this->fail('Ein Exit-Code ungleich 0 muss eine Ausnahme auslösen.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('PDOException: Table x already exists', $exception->getMessage());
            $this->assertStringContainsString('exit code 1', $exception->getMessage());
        }
    }
}

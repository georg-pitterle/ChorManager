<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DumpRunnerInterface;
use App\Services\MysqldumpRunner;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;

/**
 * DB_SOCKET schaltet jede Verbindung der Anwendung auf den Unix-Socket: Eloquent,
 * Phinx und das Backup. Blieb eine davon auf TCP, meldete sie sich in Produktion
 * anders an als der Rest - und fiele erst beim ersten Backup oder Deploy auf.
 */
final class DatabaseSocketSettingsTest extends TestCase
{
    private const SOCKET = '/var/run/mysqld/mysqld.sock';

    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = $_ENV['DB_SOCKET'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($_ENV['DB_SOCKET']);
        } else {
            $_ENV['DB_SOCKET'] = $this->previous;
        }
        putenv('DB_SOCKET');
    }

    /**
     * @return array<string, mixed>
     */
    private function dbSettings(): array
    {
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);

        return $builder->build()->get('settings')['db'];
    }

    public function testEloquentUsesTheSocketWhenConfigured(): void
    {
        $_ENV['DB_SOCKET'] = self::SOCKET;

        $this->assertSame(self::SOCKET, $this->dbSettings()['unix_socket']);
    }

    public function testEloquentStaysOnTcpWithoutSocket(): void
    {
        $_ENV['DB_SOCKET'] = '';

        $this->assertSame('', $this->dbSettings()['unix_socket']);
    }

    public function testPhinxUsesTheSocketWhenConfigured(): void
    {
        putenv('DB_SOCKET=' . self::SOCKET);

        $config = require dirname(__DIR__, 2) . '/phinx.php';

        $this->assertSame(self::SOCKET, $config['environments']['development']['unix_socket']);
    }

    public function testBackupRunnerUsesTheSocketWhenConfigured(): void
    {
        $_ENV['DB_SOCKET'] = self::SOCKET;

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $runner = $builder->build()->get(DumpRunnerInterface::class);

        $this->assertInstanceOf(MysqldumpRunner::class, $runner);
        $this->assertContains('--socket=' . self::SOCKET, $runner->clientConnectionArguments());
    }
}

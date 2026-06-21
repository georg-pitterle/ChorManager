<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MysqldumpRunner;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

final class MysqldumpRunnerFeatureTest extends TestCase
{
    private static ?Capsule $capsule = null;
    private string $tmpFile;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Capsule::connection()->statement(
            'CREATE TABLE IF NOT EXISTS backup_runner_probe (id INT PRIMARY KEY, marker VARCHAR(64))'
        );
        Capsule::connection()->table('backup_runner_probe')->truncate();
        Capsule::connection()->table('backup_runner_probe')->insert([
            'id' => 1,
            'marker' => 'probe-before-restore',
        ]);

        $this->tmpFile = sys_get_temp_dir() . '/chormanager_mysqldump_test_' . bin2hex(random_bytes(4)) . '.sql.gz';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        Capsule::connection()->statement('DROP TABLE IF EXISTS backup_runner_probe');

        parent::tearDown();
    }

    private function makeRunner(): MysqldumpRunner
    {
        return new MysqldumpRunner(
            (string) ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db'),
            (string) ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? '3306'),
            (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db'),
            (string) ($_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db'),
            (string) ($_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db')
        );
    }

    public function testDumpAndRestoreRoundTripsProbeTable(): void
    {
        $runner = $this->makeRunner();

        $runner->dump($this->tmpFile, true);

        $this->assertFileExists($this->tmpFile);
        $this->assertGreaterThan(0, filesize($this->tmpFile));

        Capsule::connection()->table('backup_runner_probe')->update(['marker' => 'probe-overwritten']);

        $runner->restore($this->tmpFile, true);

        $row = Capsule::connection()->table('backup_runner_probe')->where('id', 1)->first();
        $this->assertSame('probe-before-restore', $row->marker);
    }
}

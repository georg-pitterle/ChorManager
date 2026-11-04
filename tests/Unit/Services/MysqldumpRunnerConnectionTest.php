<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MysqldumpRunner;
use PHPUnit\Framework\TestCase;

/**
 * In Produktion erreicht das Backup MySQL über den Unix-Socket aus einem gemeinsamen
 * Volume. Über TCP verlangte caching_sha2_password bei der vollen Anmeldung TLS, und
 * der MariaDB-Client im App-Image lehnte das selbstsignierte Zertifikat von MySQL ab.
 * Über den Socket gilt die Verbindung als sicher; ein Zertifikat braucht es nicht.
 */
final class MysqldumpRunnerConnectionTest extends TestCase
{
    public function testSocketReplacesHostAndPort(): void
    {
        $runner = new MysqldumpRunner('db', '3306', 'app', 'user', 'secret', '/var/run/mysqld/mysqld.sock');

        $this->assertSame(
            ['--socket=/var/run/mysqld/mysqld.sock', '--user=user'],
            $runner->clientConnectionArguments()
        );
        $this->assertSame(
            'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=app;charset=utf8mb4',
            $runner->pdoDsn()
        );
    }

    /**
     * ddev hat keinen gemeinsamen Socket; dort und überall ohne DB_SOCKET bleibt es bei TCP.
     */
    public function testWithoutSocketTheConnectionUsesHostAndPort(): void
    {
        $runner = new MysqldumpRunner('db', '3306', 'app', 'user', 'secret');

        $this->assertSame(['--host=db', '--port=3306', '--user=user'], $runner->clientConnectionArguments());
        $this->assertSame('mysql:host=db;port=3306;dbname=app;charset=utf8mb4', $runner->pdoDsn());
    }

    public function testThePasswordNeverAppearsInTheArguments(): void
    {
        $runner = new MysqldumpRunner('db', '3306', 'app', 'user', 'secret', '/run/m.sock');

        $this->assertNotContains('secret', $runner->clientConnectionArguments());
        $this->assertStringNotContainsString('secret', implode(' ', $runner->clientConnectionArguments()));
    }
}

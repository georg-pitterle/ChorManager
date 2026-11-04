<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Entwicklung und Produktion laufen auf demselben Datenbanksystem in derselben
 * Version. Weichen sie auseinander, testet die Suite gegen ein anderes Verhalten
 * als das, auf das die Migrationen in Produktion treffen - Fremdschlüssel-Regeln,
 * NULL in UNIQUE-Indizes, Verengen von Spalten. Genau so lief ddev bis 2026-11 auf
 * MariaDB 11.8 neben MySQL 8.4 in Produktion.
 *
 * Dazu die zwei Stellen, ohne die das App-Backup gegen MySQL 9.7 nicht anmeldet
 * bzw. nicht wieder einspielt.
 */
final class DatabaseEngineDefinitionsTest extends TestCase
{
    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path, $relativePath . ' existiert nicht.');

        $content = file_get_contents($path);
        $this->assertIsString($content, $relativePath . ' ist nicht lesbar.');

        return $content;
    }

    public function testDdevAndProductionUseTheSameMysqlVersion(): void
    {
        $ddev = $this->read('.ddev/config.yaml');
        $compose = $this->read('dist/docker-compose.prod.yml');

        $this->assertSame(
            1,
            preg_match('/^database:\s*\n\s+type:\s*mysql\s*\n\s+version:\s*"([0-9.]+)"/m', $ddev, $ddevMatch),
            'ddev muss MySQL nutzen, wie die Produktion.'
        );
        $this->assertSame(
            1,
            preg_match('/^\s+image:\s*mysql:([0-9.]+)\s*$/m', $compose, $prodMatch),
            'Die Produktion muss ein festes mysql-Image nutzen.'
        );
        $this->assertSame(
            $prodMatch[1],
            $ddevMatch[1],
            'ddev und Produktion müssen dieselbe MySQL-Version fahren.'
        );
    }

    /**
     * MySQL 9 hat mysql_native_password entfernt. Bleiben die Schalter dafür stehen,
     * startet der Server nicht ("unknown variable").
     */
    public function testProductionDoesNotPinTheRemovedNativePasswordPlugin(): void
    {
        $compose = $this->read('dist/docker-compose.prod.yml');

        $this->assertStringNotContainsString('--mysql-native-password', $compose);
        $this->assertStringNotContainsString('--authentication-policy=mysql_native_password', $compose);
    }

    /**
     * App und Datenbank teilen das Socket-Verzeichnis über ein Volume, und die
     * Anwendung wird darauf gestellt. Fehlt eines davon, fällt sie auf TCP zurück,
     * wo der Backup-Client am selbstsignierten Zertifikat von MySQL scheitert.
     */
    public function testAppReachesTheDatabaseThroughTheSharedSocket(): void
    {
        $compose = $this->read('dist/docker-compose.prod.yml');

        $this->assertMatchesRegularExpression(
            '/^x-app-environment:[^\n]*\n(?:  [^\n]*\n)*  DB_SOCKET: \/var\/run\/mysqld\/mysqld\.sock\n/m',
            $compose
        );
        foreach (['app', 'db'] as $service) {
            $this->assertMatchesRegularExpression(
                '/^  ' . $service . ':\n(?:(?:    [^\n]*|\s*)\n)*?      - db_socket:\/var\/run\/mysqld\n/m',
                $compose,
                $service . ' muss das Socket-Volume unter /var/run/mysqld einhängen.'
            );
        }
        $this->assertMatchesRegularExpression('/^volumes:\n(?:  [^\n]*\n)*  db_socket:/m', $compose);
    }

    /**
     * Ohne mariadb-connector-c fehlt dem MariaDB-Client im Alpine-Image das Plugin
     * caching_sha2_password - jedes Backup scheitert mit Fehler 1045.
     */
    public function testAppImageShipsTheCachingSha2ClientPlugin(): void
    {
        $dockerfile = $this->read('Dockerfile');

        $this->assertMatchesRegularExpression('/^\s+mariadb-connector-c\s*\\\\$/m', $dockerfile);
    }

    /**
     * Der Oracle-mysqldump in ddev schriebe bei gtid_mode=ON GTID_PURGED in den
     * Dump und bräuchte dafür das Recht RELOAD; beides bräche das App-Backup.
     */
    public function testDdevMysqldumpSkipsGtidPurged(): void
    {
        $webBuild = $this->read('.ddev/web-build/Dockerfile.mysqldump');

        $this->assertStringContainsString('set-gtid-purged=OFF', $webBuild);
    }
}

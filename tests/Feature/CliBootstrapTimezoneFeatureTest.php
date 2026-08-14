<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\Timezone;
use PHPUnit\Framework\TestCase;

/**
 * Der Web-Einstieg (public/index.php) setzt die App-Zeitzone, die CLI-Skripte liefen dagegen in
 * der Zeitzone aus der php.ini. Dadurch verglich der Mail-Worker faellige Warteschlangen-Eintraege
 * gegen eine um den Zonenversatz zurueckliegende Uhrzeit und liess ueber die Oberflaeche
 * eingereihte Mails bis zu zwei Stunden liegen.
 */
final class CliBootstrapTimezoneFeatureTest extends TestCase
{
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Fuehrt PHP in einem eigenen Prozess aus, damit die Zeitzone dieses Testlaufs das Ergebnis
     * nicht verfaelscht.
     */
    private function runInSeparateProcess(string $code): string
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1';
        $output = shell_exec($command);

        return trim((string) $output);
    }

    public function testCliBootstrapSetsTheApplicationTimezone(): void
    {
        $bootstrap = $this->repositoryRoot() . '/bin/bootstrap_cli.php';
        $this->assertFileExists($bootstrap);

        $timezone = $this->runInSeparateProcess(
            'ini_set("date.timezone", "UTC");'
            . 'require ' . var_export($bootstrap, true) . ';'
            . 'echo date_default_timezone_get();'
        );

        $this->assertSame(
            Timezone::resolveAppTimezone(),
            $timezone,
            'bin/bootstrap_cli.php muss die App-Zeitzone setzen, sonst laufen CLI-Skripte in UTC.'
        );
    }

    public function testMailQueueWorkerUsesTheSharedCliBootstrap(): void
    {
        $worker = file_get_contents($this->repositoryRoot() . '/bin/process_mail_queue.php');

        $this->assertIsString($worker);
        $this->assertStringContainsString(
            "require __DIR__ . '/bootstrap_cli.php';",
            $worker,
            'Der Mail-Worker muss ueber das gemeinsame CLI-Bootstrap starten (Zeitzone).'
        );
        $this->assertStringContainsString(
            'CliBootstrap::container()',
            $worker,
            'Der Mail-Worker muss den Container ueber CliBootstrap beziehen, damit die .env geladen wird.'
        );
        $this->assertStringNotContainsString(
            'new ContainerBuilder()',
            $worker,
            'Ein eigener ContainerBuilder umgeht das Einlesen der .env.'
        );
    }
}

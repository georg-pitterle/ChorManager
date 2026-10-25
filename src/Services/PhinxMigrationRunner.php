<?php

declare(strict_types=1);

namespace App\Services;

use App\Util\EnvHelper;

final class PhinxMigrationRunner implements MigrationRunnerInterface
{
    private const DATABASE_VARIABLES = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    /**
     * @param list<string>|null $command Vorgabe ist `phinx migrate`; ein anderer Befehl dient dem Test.
     */
    public function __construct(
        private readonly ?array $command = null,
        private readonly ?string $workingDirectory = null
    ) {
    }

    public function migrate(): void
    {
        $command = $this->command ?? [PHP_BINARY, 'vendor/bin/phinx', 'migrate'];
        $directory = $this->workingDirectory ?? dirname(__DIR__, 2);

        // php-fpm leert die Umgebung seiner Arbeitsprozesse; phinx.php liest die
        // Zugangsdaten aber über getenv(). Sie werden deshalb ausdrücklich mitgegeben.
        $environment = getenv();
        foreach (self::DATABASE_VARIABLES as $name) {
            $value = EnvHelper::read($name);
            if ($value !== '') {
                $environment[$name] = $value;
            }
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'phinx_out_');
        if ($outputPath === false) {
            throw new \RuntimeException('Failed to create temporary file for phinx output.');
        }

        try {
            $process = proc_open(
                $command,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputPath, 'w'], 2 => ['file', $outputPath, 'a']],
                $pipes,
                $directory,
                $environment
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Failed to start phinx migrate.');
            }

            $exitCode = proc_close($process);
            $output = (string) file_get_contents($outputPath);
        } finally {
            @unlink($outputPath);
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                'phinx migrate failed with exit code %d: %s',
                $exitCode,
                self::firstErrorLine($output)
            ));
        }
    }

    private static function firstErrorLine(string $output): string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/(Exception|Error):/', $line) === 1) {
                return mb_substr(trim($line), 0, 500);
            }
        }

        return mb_substr(trim($output), -500);
    }
}

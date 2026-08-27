<?php

declare(strict_types=1);

namespace App\Services;

final class MysqldumpRunner implements DumpRunnerInterface
{
    public function __construct(
        private readonly string $host,
        private readonly string $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password
    ) {
    }

    public function dump(string $destinationPath, bool $gzip): void
    {
        $stderrTmpPath = tempnam(sys_get_temp_dir(), 'mysqldump_err_');
        if ($stderrTmpPath === false) {
            throw new \RuntimeException('Failed to create temporary file for mysqldump stderr capture.');
        }

        $process = proc_open(
            [
                'mysqldump',
                '--host=' . $this->host,
                '--port=' . $this->port,
                '--user=' . $this->username,
                '--single-transaction',
                '--routines',
                '--triggers',
                $this->database,
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['file', $stderrTmpPath, 'w'],
            ],
            $pipes,
            null,
            ['MYSQL_PWD' => $this->password]
        );

        if (!is_resource($process)) {
            unlink($stderrTmpPath);
            throw new \RuntimeException('Failed to start mysqldump process.');
        }

        $out = $gzip ? gzopen($destinationPath, 'wb9') : fopen($destinationPath, 'wb');
        if ($out === false) {
            fclose($pipes[1]);
            proc_close($process);
            unlink($stderrTmpPath);
            throw new \RuntimeException('Failed to open backup destination file: ' . $destinationPath);
        }

        // Ein abgebrochener Schreibvorgang - typischerweise ein voller Datenträger -
        // darf nicht als fertiges Backup durchgehen. Die Prüfsumme entsteht erst
        // danach und wiese die abgeschnittene Datei als unversehrt aus; beim
        // Einspielen käme nur ein Teil der Datenbank zurück, ohne dass irgendwo
        // ein Fehler stünde. Deshalb wird jeder Schreibvorgang geprüft.
        $writeError = null;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            // Unterdrückt, weil der Rückgabewert selbst geprüft wird: Die
            // Begründung von PHP wandert unten in die Ausnahme, statt als lose
            // Meldung im Fehlerprotokoll zu landen.
            $written = $gzip ? @gzwrite($out, $chunk) : @fwrite($out, $chunk);
            if ($written === false || $written < strlen($chunk)) {
                $writeError = 'Failed to write the full dump to ' . $destinationPath
                    . ': ' . self::lastErrorMessage();
                break;
            }
        }

        fclose($pipes[1]);
        $gzip ? gzclose($out) : fclose($out);

        $exitCode = proc_close($process);
        $errorOutput = (string) file_get_contents($stderrTmpPath);
        unlink($stderrTmpPath);

        if ($exitCode !== 0 || $writeError !== null) {
            // Nur eine reguläre Datei wegräumen: Zeigt das Ziel auf ein Gerät,
            // gehört es nicht dieser Klasse und darf nicht gelöscht werden.
            if (is_file($destinationPath)) {
                unlink($destinationPath);
            }

            if ($writeError !== null) {
                throw new \RuntimeException($writeError);
            }

            throw new \RuntimeException('mysqldump failed with exit code ' . $exitCode . ': ' . $errorOutput);
        }
    }

    public function restore(string $sourcePath, bool $gzip): void
    {
        $stderrTmpPath = tempnam(sys_get_temp_dir(), 'mysqlrestore_err_');
        if ($stderrTmpPath === false) {
            throw new \RuntimeException('Failed to create temporary file for mysql restore stderr capture.');
        }

        $process = proc_open(
            [
                'mysql',
                '--host=' . $this->host,
                '--port=' . $this->port,
                '--user=' . $this->username,
                $this->database,
            ],
            [
                0 => ['pipe', 'r'],
                2 => ['file', $stderrTmpPath, 'w'],
            ],
            $pipes,
            null,
            ['MYSQL_PWD' => $this->password]
        );

        if (!is_resource($process)) {
            unlink($stderrTmpPath);
            throw new \RuntimeException('Failed to start mysql restore process.');
        }

        $in = $gzip ? gzopen($sourcePath, 'rb') : fopen($sourcePath, 'rb');
        if ($in === false) {
            fclose($pipes[0]);
            proc_close($process);
            unlink($stderrTmpPath);
            throw new \RuntimeException('Failed to open backup source file: ' . $sourcePath);
        }

        // Wie beim Dump: Bleibt ein Teil der Anweisungen im Rohr stecken, spielt der
        // Lauf nur einen Ausschnitt ein. `mysql` kann dabei durchaus mit 0 enden.
        $writeError = null;
        while (!($gzip ? gzeof($in) : feof($in))) {
            $chunk = $gzip ? gzread($in, 8192) : fread($in, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            $written = @fwrite($pipes[0], $chunk);
            if ($written === false || $written < strlen($chunk)) {
                $writeError = 'Failed to stream the full backup into mysql, the restore is incomplete: '
                    . self::lastErrorMessage();
                break;
            }
        }

        $gzip ? gzclose($in) : fclose($in);
        fclose($pipes[0]);

        $exitCode = proc_close($process);
        $errorOutput = (string) file_get_contents($stderrTmpPath);
        unlink($stderrTmpPath);

        if ($writeError !== null) {
            throw new \RuntimeException($writeError);
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException('mysql restore failed with exit code ' . $exitCode . ': ' . $errorOutput);
        }
    }

    /**
     * Begründung des zuletzt unterdrückten Schreibfehlers, etwa
     * "No space left on device". Ohne sie stünde in der Ausnahme nur, dass
     * geschrieben werden sollte - nicht, woran es lag.
     */
    private static function lastErrorMessage(): string
    {
        $message = trim((string) (error_get_last()['message'] ?? ''));

        return $message === '' ? 'destination out of space or not writable' : $message;
    }
}

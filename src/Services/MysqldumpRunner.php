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

        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            $gzip ? gzwrite($out, $chunk) : fwrite($out, $chunk);
        }

        fclose($pipes[1]);
        $gzip ? gzclose($out) : fclose($out);

        $exitCode = proc_close($process);
        $errorOutput = (string) file_get_contents($stderrTmpPath);
        unlink($stderrTmpPath);

        if ($exitCode !== 0) {
            if (file_exists($destinationPath)) {
                unlink($destinationPath);
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

        while (!($gzip ? gzeof($in) : feof($in))) {
            $chunk = $gzip ? gzread($in, 8192) : fread($in, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            fwrite($pipes[0], $chunk);
        }

        $gzip ? gzclose($in) : fclose($in);
        fclose($pipes[0]);

        $exitCode = proc_close($process);
        $errorOutput = (string) file_get_contents($stderrTmpPath);
        unlink($stderrTmpPath);

        if ($exitCode !== 0) {
            throw new \RuntimeException('mysql restore failed with exit code ' . $exitCode . ': ' . $errorOutput);
        }
    }
}

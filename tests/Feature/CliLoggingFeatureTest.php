<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CliLoggingFeatureTest extends TestCase
{
    public function testPhpBinScriptsAvoidDirectCliOutputFunctions(): void
    {
        $scripts = [
            'bin/bootstrap_cli.php',
            'bin/check_lf.php',
            'bin/check_lf_repo.php',
            'bin/check_timezone_runtime.php',
            'bin/copy-assets.php',
            'bin/dev_seed.php',
            'bin/normalize_lf_staged.php',
            'bin/process_mail_queue.php',
        ];

        foreach ($scripts as $script) {
            $content = file_get_contents(dirname(__DIR__) . '/../' . $script);

            $this->assertIsString($content, $script);
            $this->assertStringNotContainsString('echo ', $content, $script);
            $this->assertStringNotContainsString('fwrite(STDERR', $content, $script);
            $this->assertStringNotContainsString('fwrite(STDOUT', $content, $script);
            $this->assertStringNotContainsString('error_log(', $content, $script);
            $this->assertStringNotContainsString('writeln(', $content, $script);
        }
    }

    public function testPhpBinScriptsUseSharedCliLoggerBootstrap(): void
    {
        $scripts = [
            'bin/check_lf.php',
            'bin/check_lf_repo.php',
            'bin/check_timezone_runtime.php',
            'bin/copy-assets.php',
            'bin/dev_seed.php',
            'bin/normalize_lf_staged.php',
        ];

        foreach ($scripts as $script) {
            $content = file_get_contents(dirname(__DIR__) . '/../' . $script);

            $this->assertIsString($content, $script);
            $this->assertStringContainsString("require __DIR__ . '/bootstrap_cli.php';", $content, $script);
            $this->assertStringContainsString('CliBootstrap::', $content, $script);
        }
    }
}

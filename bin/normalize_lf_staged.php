<?php

declare(strict_types=1);

use App\Util\CliBootstrap;

require __DIR__ . '/bootstrap_cli.php';

$logger = CliBootstrap::logger();

$root = dirname(__DIR__);
$allowCrLfExtensions = ['bat', 'cmd', 'ps1'];

$output = [];
$exitCode = 0;
exec('git -C ' . escapeshellarg($root) . ' diff --cached --name-only --diff-filter=ACMR', $output, $exitCode);

if ($exitCode !== 0) {
    $logger->error(
        'Could not list staged files via git.',
        [
            'event' => 'lf_normalize.git_list_failed',
            'root' => str_replace('\\', '/', $root),
            'exit_code' => $exitCode,
        ]
    );
    exit(2);
}

$normalized = [];

foreach ($output as $relativePath) {
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        continue;
    }

    $normalizedPath = str_replace('\\', '/', $relativePath);
    $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);

    if (!is_file($absolutePath)) {
        continue;
    }

    $extension = strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION));
    if (in_array($extension, $allowCrLfExtensions, true)) {
        continue;
    }

    $content = file_get_contents($absolutePath);
    if ($content === false) {
        $logger->error(
            'Could not read staged file during LF normalization.',
            [
                'event' => 'lf_normalize.file_read_failed',
                'path' => $normalizedPath,
            ]
        );
        exit(2);
    }

    // Skip binary files.
    if (str_contains($content, "\0")) {
        continue;
    }

    if (!str_contains($content, "\r")) {
        continue;
    }

    $lfContent = str_replace(["\r\n", "\r"], "\n", $content);
    if ($lfContent === $content) {
        continue;
    }

    $writeOk = file_put_contents($absolutePath, $lfContent);
    if ($writeOk === false) {
        $logger->error(
            'Could not write staged file during LF normalization.',
            [
                'event' => 'lf_normalize.file_write_failed',
                'path' => $normalizedPath,
            ]
        );
        exit(2);
    }

    $normalized[] = $normalizedPath;
}

if ($normalized !== []) {
    $escaped = array_map(static fn(string $path): string => escapeshellarg($path), $normalized);
    $command = 'git -C ' . escapeshellarg($root) . ' add -- ' . implode(' ', $escaped);

    $reAddOutput = [];
    $reAddExitCode = 0;
    exec($command, $reAddOutput, $reAddExitCode);
    if ($reAddExitCode !== 0) {
        $logger->error(
            'Could not re-stage normalized files.',
            [
                'event' => 'lf_normalize.git_add_failed',
                'paths' => $normalized,
                'exit_code' => $reAddExitCode,
            ]
        );
        exit(2);
    }

    $logger->info(
        'Normalized staged files to LF and re-staged them.',
        [
            'event' => 'lf_normalize.completed',
            'paths' => $normalized,
        ]
    );
    exit(0);
}

$logger->info(
    'No staged text files required LF normalization.',
    [
        'event' => 'lf_normalize.no_changes',
    ]
);
exit(0);

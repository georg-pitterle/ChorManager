<?php

declare(strict_types=1);

use App\Util\CliBootstrap;

require __DIR__ . '/bootstrap_cli.php';

$logger = CliBootstrap::logger();

$root = dirname(__DIR__);
$allowCrLfExtensions = ['bat', 'cmd', 'ps1'];

$output = [];
$exitCode = 0;
exec('git -C ' . escapeshellarg($root) . ' ls-files', $output, $exitCode);

if ($exitCode !== 0) {
    $logger->error(
        'Could not list tracked files via git.',
        [
            'event' => 'lf_repo_check.git_list_failed',
            'root' => str_replace('\\', '/', $root),
            'exit_code' => $exitCode,
        ]
    );
    exit(2);
}

$violations = [];

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
            'Could not read tracked file during LF verification.',
            [
                'event' => 'lf_repo_check.file_read_failed',
                'path' => $normalizedPath,
            ]
        );
        exit(2);
    }

    // Skip binary files.
    if (str_contains($content, "\0")) {
        continue;
    }

    if (str_contains($content, "\r\n")) {
        $violations[] = $normalizedPath;
    }
}

if ($violations !== []) {
    $logger->error(
        'CRLF detected in tracked files that must use LF.',
        [
            'event' => 'lf_repo_check.crlf_detected',
            'paths' => $violations,
        ]
    );
    exit(1);
}

$logger->info(
    'LF endings verified for tracked text files.',
    [
        'event' => 'lf_repo_check.completed',
        'excluded_extensions' => $allowCrLfExtensions,
    ]
);
exit(0);

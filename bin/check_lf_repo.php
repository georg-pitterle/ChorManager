<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$allowCrLfExtensions = ['bat', 'cmd', 'ps1'];

$output = [];
$exitCode = 0;
exec('git -C ' . escapeshellarg($root) . ' ls-files', $output, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "Could not list tracked files via git.\n");
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
        fwrite(STDERR, "Could not read file: {$normalizedPath}\n");
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
    fwrite(STDERR, "CRLF detected in tracked files that must be LF:\n");
    foreach ($violations as $path) {
        fwrite(STDERR, " - {$path}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: LF endings verified for all tracked text files (except .bat/.cmd/.ps1).\n");
exit(0);

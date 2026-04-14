<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$allowCrLfExtensions = ['bat', 'cmd', 'ps1'];

$output = [];
$exitCode = 0;
exec('git -C ' . escapeshellarg($root) . ' diff --cached --name-only --diff-filter=ACMR', $output, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "Could not list staged files via git.\n");
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
        fwrite(STDERR, "Could not read file: {$normalizedPath}\n");
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
        fwrite(STDERR, "Could not write file: {$normalizedPath}\n");
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
        fwrite(STDERR, "Could not re-stage normalized files.\n");
        exit(2);
    }

    fwrite(STDOUT, "Normalized to LF and re-staged:\n");
    foreach ($normalized as $path) {
        fwrite(STDOUT, " - {$path}\n");
    }
    exit(0);
}

fwrite(STDOUT, "No staged text files required LF normalization.\n");
exit(0);

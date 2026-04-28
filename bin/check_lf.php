<?php

declare(strict_types=1);

use App\Util\CliBootstrap;

require __DIR__ . '/bootstrap_cli.php';

$logger = CliBootstrap::logger();

if ($argc < 3) {
    $logger->error(
        'LF check requires directory and extension arguments.',
        [
            'event' => 'lf_check.invalid_arguments',
            'argument_count' => $argc,
        ]
    );
    exit(2);
}

$directory = $argv[1];
$extension = ltrim($argv[2], '.');

if (!is_dir($directory)) {
    $logger->error(
        'LF check directory not found.',
        [
            'event' => 'lf_check.directory_not_found',
            'directory' => $directory,
        ]
    );
    exit(2);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
);

$violations = [];

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    if (strtolower($file->getExtension()) !== strtolower($extension)) {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        $logger->error(
            'LF check could not read file.',
            [
                'event' => 'lf_check.file_read_failed',
                'path' => str_replace('\\', '/', $file->getPathname()),
            ]
        );
        exit(2);
    }

    if (str_contains($content, "\r\n")) {
        $violations[] = str_replace('\\', '/', $file->getPathname());
    }
}

if ($violations !== []) {
    $logger->error(
        'CRLF detected in files that must use LF.',
        [
            'event' => 'lf_check.crlf_detected',
            'directory' => str_replace('\\', '/', $directory),
            'extension' => $extension,
            'paths' => $violations,
        ]
    );
    exit(1);
}

$logger->info(
    'LF endings verified successfully.',
    [
        'event' => 'lf_check.completed',
        'directory' => str_replace('\\', '/', $directory),
        'extension' => $extension,
    ]
);
exit(0);

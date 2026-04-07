<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/check_lf.php <directory> <extension>\n");
    exit(2);
}

$directory = $argv[1];
$extension = ltrim($argv[2], '.');

if (!is_dir($directory)) {
    fwrite(STDERR, "Directory not found: {$directory}\n");
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
        fwrite(STDERR, "Could not read file: {$file->getPathname()}\n");
        exit(2);
    }

    if (str_contains($content, "\r\n")) {
        $violations[] = str_replace('\\', '/', $file->getPathname());
    }
}

if ($violations !== []) {
    fwrite(STDERR, "CRLF detected in files that must use LF:\n");
    foreach ($violations as $path) {
        fwrite(STDERR, " - {$path}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: LF endings verified for *.{$extension} in {$directory}\n");
exit(0);
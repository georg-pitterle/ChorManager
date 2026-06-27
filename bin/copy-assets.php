<?php

declare(strict_types=1);

use App\Util\CliBootstrap;

require __DIR__ . '/bootstrap_cli.php';

function copyRecursive(string $source, string $destination): void
{
    if (is_dir($source)) {
        @mkdir($destination, 0755, true);
        $entries = scandir($source);
        if ($entries === false) {
            throw new RuntimeException("Failed to read directory $source");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            copyRecursive($source . '/' . $entry, $destination . '/' . $entry);
        }
        return;
    }

    @mkdir(dirname($destination), 0755, true);
    if (!copy($source, $destination)) {
        throw new RuntimeException("Failed to copy $source to $destination");
    }
}

function copyAssets(): void
{
    $source = 'node_modules/bootstrap/dist/css/bootstrap.min.css';
    $dest = 'public/vendor/bootstrap/dist/css/bootstrap.min.css';

    @mkdir(dirname($dest), 0755, true);
    if (!copy($source, $dest)) {
        throw new RuntimeException("Failed to copy $source to $dest");
    }

    $source = 'node_modules/bootstrap/dist/js/bootstrap.bundle.min.js';
    $dest = 'public/vendor/bootstrap/dist/js/bootstrap.bundle.min.js';

    @mkdir(dirname($dest), 0755, true);
    if (!copy($source, $dest)) {
        throw new RuntimeException("Failed to copy $source to $dest");
    }

    $srcDir = 'node_modules/bootstrap-icons/font';
    $destDir = 'public/vendor/bootstrap-icons/font';
    copyRecursive($srcDir, $destDir);

    $srcDir = 'node_modules/tinymce';
    $destDir = 'public/vendor/tinymce/tinymce';
    copyRecursive($srcDir, $destDir);

    $source = 'node_modules/tinymce-i18n/langs7/de.js';
    $dest = 'public/vendor/tinymce/langs/de.js';

    @mkdir(dirname($dest), 0755, true);
    if (!copy($source, $dest)) {
        throw new RuntimeException("Failed to copy $source to $dest");
    }

    $source = 'node_modules/html-midi-player/dist/midi-player.min.js';
    $dest = 'public/vendor/html-midi-player/dist/midi-player.min.js';

    @mkdir(dirname($dest), 0755, true);
    if (!copy($source, $dest)) {
        throw new RuntimeException("Failed to copy $source to $dest");
    }

    foreach (['core', 'daygrid', 'timegrid', 'interaction', 'list'] as $pkg) {
        $source = "node_modules/@fullcalendar/{$pkg}/index.global.min.js";
        $dest   = "public/vendor/fullcalendar/{$pkg}/index.global.min.js";
        @mkdir(dirname($dest), 0755, true);
        if (!copy($source, $dest)) {
            throw new RuntimeException("Failed to copy $source to $dest");
        }
    }

    $source = 'node_modules/@fullcalendar/core/locales/de.global.min.js';
    $dest   = 'public/vendor/fullcalendar/core/locales/de.global.min.js';
    @mkdir(dirname($dest), 0755, true);
    if (!copy($source, $dest)) {
        throw new RuntimeException("Failed to copy $source to $dest");
    }
}

if (!is_dir('node_modules')) {
    CliBootstrap::logger()->info(
        'Asset copy skipped: node_modules not found, run npm ci first.',
        [
            'event' => 'assets.copy.skipped',
        ]
    );
    exit(0);
}

try {
    copyAssets();
    CliBootstrap::logger()->info(
        'Asset copy completed.',
        [
            'event' => 'assets.copy.completed',
        ]
    );
} catch (Throwable $e) {
    CliBootstrap::logger()->error(
        'Asset copy failed.',
        [
            'event' => 'assets.copy.failed',
            'exception' => $e,
        ]
    );
    exit(1);
}

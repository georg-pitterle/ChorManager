<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Dateien auf der lokalen Platte unter `ab/cd/<32 Hex>`.
 *
 * Der Pfad besteht ausschließlich aus Zufall; kein Datei- oder Personenname
 * gelangt hinein. Jeder Pfad wird gegen genau dieses Schema geprüft, bevor er
 * mit dem Basisverzeichnis verbunden wird - damit gibt es keinen Weg hinaus,
 * auch nicht über einen manipulierten Datenbankeintrag.
 */
final class LocalFileStorage implements FileStorage
{
    public const NAME = 'local';

    private const PATH_PATTERN = '#^[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}$#';

    public function __construct(private readonly string $baseDir)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function put(string $sourcePath): string
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Upload source is missing.');
        }

        $id = bin2hex(random_bytes(16));
        $storagePath = substr($id, 0, 2) . '/' . substr($id, 2, 2) . '/' . $id;
        $target = $this->absolute($storagePath);
        $this->ensureDirectory(dirname($target));

        if (!copy($sourcePath, $target)) {
            throw new \RuntimeException('Could not write file to storage.');
        }
        chmod($target, 0640);

        return $storagePath;
    }

    public function writeAt(string $storagePath, $stream): void
    {
        $target = $this->absolute($storagePath);
        $this->ensureDirectory(dirname($target));

        // Erst in eine Nachbardatei, dann umbenennen: Ein Abbruch mittendrin
        // hinterlässt so nie eine halbe Datei unter dem gültigen Pfad.
        $temp = $target . '.part';
        $out = fopen($temp, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not open file in storage for writing.');
        }
        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new \RuntimeException('Could not write file to storage.');
            }
        } finally {
            fclose($out);
        }
        chmod($temp, 0640);
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException('Could not move file into place.');
        }
    }

    public function read(string $storagePath): string
    {
        $content = file_get_contents($this->existing($storagePath));
        if ($content === false) {
            throw new \RuntimeException('Could not read file from storage.');
        }

        return $content;
    }

    public function readStream(string $storagePath)
    {
        $handle = fopen($this->existing($storagePath), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open file from storage.');
        }

        return $handle;
    }

    public function readRange(string $storagePath, int $start, int $length): string
    {
        $handle = $this->readStream($storagePath);
        try {
            fseek($handle, max(0, $start));
            $content = $length > 0 ? fread($handle, $length) : '';
        } finally {
            fclose($handle);
        }

        return $content === false ? '' : $content;
    }

    public function size(string $storagePath): int
    {
        $size = filesize($this->existing($storagePath));

        return $size === false ? 0 : $size;
    }

    public function localPath(string $storagePath): ?string
    {
        return $this->existing($storagePath);
    }

    public function exists(string $storagePath): bool
    {
        return is_file($this->absolute($storagePath));
    }

    public function delete(string $storagePath): void
    {
        $path = $this->absolute($storagePath);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Alle gespeicherten Pfade - für die Suche nach Dateien ohne Datenbankeintrag.
     *
     * @return \Generator<int, string>
     */
    public function allPaths(): \Generator
    {
        if (!is_dir($this->baseDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS)
        );
        $baseLength = strlen(rtrim($this->baseDir, '/')) + 1;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), $baseLength));
            if (preg_match(self::PATH_PATTERN, $relative) === 1) {
                yield $relative;
            }
        }
    }

    public static function isValidPath(string $storagePath): bool
    {
        return preg_match(self::PATH_PATTERN, $storagePath) === 1;
    }

    private function absolute(string $storagePath): string
    {
        if (!self::isValidPath($storagePath)) {
            throw new \InvalidArgumentException('Invalid storage path.');
        }

        return rtrim($this->baseDir, '/') . '/' . $storagePath;
    }

    private function existing(string $storagePath): string
    {
        $path = $this->absolute($storagePath);
        if (!is_file($path)) {
            throw new \RuntimeException('File is missing in storage.');
        }

        return $path;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create storage directory.');
        }
    }
}

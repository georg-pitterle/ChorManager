<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileVersion;
use App\Services\AttachmentResponseFactory;
use App\Util\DownloadFileName;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Stream;

/**
 * HTTP-Antworten für Dateien der Dateiverwaltung.
 *
 * Anders als bei Anhängen liegt der Inhalt nicht in der Datenbank, sondern wird
 * aus der Ablage gestreamt: Eine 90-MB-Datei darf nicht erst ganz in den
 * Speicher. Bereichsanfragen liefern höchstens `rangeChunkBytes`; ein Player,
 * der "bytes=0-" anfordert, holt sich den Rest mit Folgeanfragen.
 *
 * Ob inline ausgeliefert werden darf, entscheidet der Aufrufer über
 * AttachmentPreview - dieselbe Liste wie bei Anhängen.
 */
final class FileResponseFactory
{
    public function __construct(
        private readonly FileStorageRegistry $storages,
        private readonly int $rangeChunkBytes = 8 * 1024 * 1024
    ) {
    }

    public function download(Response $response, FileVersion $version, string $name): Response
    {
        return $this->full($response, $version, $name, 'attachment');
    }

    public function inline(Response $response, FileVersion $version, string $name, string $rangeHeader): Response
    {
        $rangeHeader = trim($rangeHeader);
        if ($rangeHeader === '') {
            return $this->full($response, $version, $name, 'inline')->withHeader('Accept-Ranges', 'bytes');
        }

        $storage = $this->storages->for((string) $version->storage_driver);
        $size = $storage->size((string) $version->storage_path);
        $range = AttachmentResponseFactory::parseRangeHeader($rangeHeader, $size);
        if ($range === null) {
            return $response->withStatus(416)->withHeader('Content-Range', 'bytes */' . $size);
        }

        [$start, $end] = $range;
        $end = min($end, $start + $this->rangeChunkBytes - 1);
        $length = $end - $start + 1;

        $response->getBody()->write($storage->readRange((string) $version->storage_path, $start, $length));

        return $response
            ->withStatus(206)
            ->withHeader('Content-Type', $this->contentType($version))
            ->withHeader('Content-Length', (string) $length)
            ->withHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition', self::disposition('inline', $name));
    }

    /**
     * Eine lokale Datei (etwa ein ZIP) ausliefern. Gelöscht wird sie danach vom
     * Aufrufer, sobald die Antwort geschrieben ist.
     */
    public function localFile(Response $response, string $path, string $name, string $contentType): Response
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open file for download.');
        }

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', self::disposition('attachment', $name));
    }

    public static function disposition(string $type, string $name): string
    {
        $safe = DownloadFileName::sanitize($name);

        return $type . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($safe);
    }

    private function full(Response $response, FileVersion $version, string $name, string $type): Response
    {
        $storage = $this->storages->for((string) $version->storage_driver);
        $stream = $storage->readStream((string) $version->storage_path);
        $size = $storage->size((string) $version->storage_path);

        return $response
            ->withBody(new Stream($stream))
            ->withHeader('Content-Type', $this->contentType($version))
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', self::disposition($type, $name));
    }

    private function contentType(FileVersion $version): string
    {
        $mimeType = trim((string) $version->mime_type);

        return $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }
}

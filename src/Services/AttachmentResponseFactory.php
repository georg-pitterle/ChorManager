<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Util\DownloadFileName;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Baut die HTTP-Antwort für einen Anhang - einmal als Download, einmal zur
 * Anzeige im Browser.
 *
 * Vorher gab es drei handgebaute Fassungen des `Content-Disposition`-Headers
 * (EntityAttachmentService, FinanceController, DownloadController), von denen
 * zwei kein `Content-Length` setzten und eine `Accept-Ranges` nur für Audio
 * kannte.
 */
class AttachmentResponseFactory
{
    public function download(Response $response, Attachment $attachment): Response
    {
        $content = (string) $attachment->file_content;

        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', $this->contentType($attachment))
            ->withHeader('Content-Length', (string) strlen($content))
            ->withHeader('Content-Disposition', $this->disposition('attachment', $attachment));
    }

    /**
     * Ohne `Range`-Kopfzeile die ganze Datei, sonst der angeforderte Ausschnitt.
     * `Accept-Ranges` steht auch in der vollständigen Antwort - erst dadurch
     * weiß ein Player, dass er überhaupt springen darf.
     */
    public function inline(Response $response, Attachment $attachment, string $rangeHeader): Response
    {
        $content = (string) $attachment->file_content;
        $fileSize = strlen($content);
        $rangeHeader = trim($rangeHeader);

        if ($rangeHeader === '') {
            $response->getBody()->write($content);

            return $response
                ->withHeader('Content-Type', $this->contentType($attachment))
                ->withHeader('Content-Length', (string) $fileSize)
                ->withHeader('Accept-Ranges', 'bytes')
                ->withHeader('Content-Disposition', $this->disposition('inline', $attachment));
        }

        $range = self::parseRangeHeader($rangeHeader, $fileSize);
        if ($range === null) {
            return $response
                ->withStatus(416)
                ->withHeader('Content-Range', 'bytes */' . $fileSize);
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        $response->getBody()->write(substr($content, $start, $length));

        return $response
            ->withStatus(206)
            ->withHeader('Content-Type', $this->contentType($attachment))
            ->withHeader('Content-Length', (string) $length)
            ->withHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $fileSize)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition', $this->disposition('inline', $attachment));
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public static function parseRangeHeader(string $rangeHeader, int $fileSize): ?array
    {
        if ($fileSize <= 0) {
            return null;
        }

        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches)) {
            return null;
        }

        $rawStart = $matches[1];
        $rawEnd = $matches[2];

        // RFC 7233 suffix-byte-range-spec: "bytes=-500" meint die letzten 500 Bytes.
        if ($rawStart === '' && $rawEnd !== '') {
            $suffixLength = (int) $rawEnd;
            if ($suffixLength <= 0) {
                return null;
            }

            return [max(0, $fileSize - $suffixLength), $fileSize - 1];
        }

        $start = $rawStart === '' ? 0 : (int) $rawStart;
        $end = $rawEnd === '' ? $fileSize - 1 : (int) $rawEnd;

        if ($start < 0 || $end < $start || $start >= $fileSize) {
            return null;
        }

        // RFC 7233, Abschnitt 2.1: Ein Ende jenseits der Datei ist kein Fehler,
        // es meint den Rest der Datei. Audio-Player fordern feste Blöcke an
        // ("bytes=0-1048575"); als 416 beantwortet, begann die Wiedergabe einer
        // kürzeren Datei gar nicht erst.
        $end = min($end, $fileSize - 1);

        return [$start, $end];
    }

    private function contentType(Attachment $attachment): string
    {
        $mimeType = trim((string) $attachment->mime_type);

        return $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }

    private function disposition(string $type, Attachment $attachment): string
    {
        $name = DownloadFileName::sanitize((string) $attachment->original_name);

        return $type . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}

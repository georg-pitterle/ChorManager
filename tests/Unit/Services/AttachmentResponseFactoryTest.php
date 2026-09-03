<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attachment;
use App\Services\AttachmentResponseFactory;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Baut die Antworten für Download und Vorschau. Der Range-Parser lag vorher
 * als statische Methode im DownloadController und war damit nur über die
 * Song-Downloads erreichbar.
 */
final class AttachmentResponseFactoryTest extends TestCase
{
    private function attachment(string $mime, string $content, string $name): Attachment
    {
        $attachment = new Attachment();
        $attachment->mime_type = $mime;
        $attachment->original_name = $name;
        $attachment->file_size = strlen($content);
        $attachment->file_content = $content;

        return $attachment;
    }

    public function testParseRangeHeaderAcceptsValidRanges(): void
    {
        $this->assertSame([10, 20], AttachmentResponseFactory::parseRangeHeader('bytes=10-20', 100));
        $this->assertSame([25, 99], AttachmentResponseFactory::parseRangeHeader('bytes=25-', 100));
        $this->assertSame([90, 99], AttachmentResponseFactory::parseRangeHeader('bytes=-10', 100));
        $this->assertSame([0, 99], AttachmentResponseFactory::parseRangeHeader('bytes=-200', 100));
    }

    /**
     * RFC 7233, Abschnitt 2.1: Reicht das Ende über die Datei hinaus, gilt der
     * Rest der Datei als angefordert. Abgewiesen wird nur, was gar nicht mehr
     * in ihr liegt - das prüft testParseRangeHeaderRejectsInvalidRanges().
     *
     * Wichtig für die Wiedergabe im Browser: Audio-Player fordern feste Blöcke
     * an ("bytes=0-1048575"), die bei einer kürzeren Datei über deren Ende
     * hinausgehen. Als 416 beantwortet, begann die Wiedergabe gar nicht erst.
     *
     * Der Fall kam mit dem Kalender-/Audio-Lauf auf main dazu, während dieser
     * Zweig entstand. Er zieht hier mit um, weil die Bereichslogik seither in
     * dieser Klasse liegt.
     */
    public function testParseRangeHeaderClampsAnEndBeyondTheFile(): void
    {
        $this->assertSame([0, 99], AttachmentResponseFactory::parseRangeHeader('bytes=0-1048575', 100));
        $this->assertSame([50, 99], AttachmentResponseFactory::parseRangeHeader('bytes=50-500', 100));
        $this->assertSame([99, 99], AttachmentResponseFactory::parseRangeHeader('bytes=99-100', 100));
    }

    public function testParseRangeHeaderRejectsInvalidRanges(): void
    {
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('bytes=20-10', 100));
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('bytes=100-101', 100));
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('bytes=-0', 100));
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('invalid', 100));
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('bytes=0-0', 0));
    }

    public function testDownloadSetsAttachmentDisposition(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('application/pdf', 'PDF-Inhalt', 'vertrag rechnung.pdf');

        $response = $factory->download(new Response(), $attachment);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('attachment; ', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('filename="vertrag rechnung.pdf"', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString("filename*=UTF-8''", $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('10', $response->getHeaderLine('Content-Length'));

        $response->getBody()->rewind();
        $this->assertSame('PDF-Inhalt', $response->getBody()->getContents());
    }

    public function testDownloadFallsBackToOctetStreamWhenMimeMissing(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('', 'irgendwas', 'ohne-typ.bin');

        $response = $factory->download(new Response(), $attachment);

        $this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
    }

    public function testInlineWithoutRangeReturnsWholeFile(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('text/plain', 'Zeile eins', 'beleg.txt');

        $response = $factory->inline(new Response(), $attachment, '');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('inline; ', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertSame('10', $response->getHeaderLine('Content-Length'));
    }

    public function testInlineWithRangeReturnsPartialContent(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('audio/mpeg', '0123456789', 'probe.mp3');

        $response = $factory->inline(new Response(), $attachment, 'bytes=2-5');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 2-5/10', $response->getHeaderLine('Content-Range'));
        $this->assertSame('4', $response->getHeaderLine('Content-Length'));

        $response->getBody()->rewind();
        $this->assertSame('2345', $response->getBody()->getContents());
    }

    public function testInlineWithUnsatisfiableRangeReturns416(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('audio/mpeg', '0123456789', 'probe.mp3');

        $response = $factory->inline(new Response(), $attachment, 'bytes=50-60');

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */10', $response->getHeaderLine('Content-Range'));
    }
}

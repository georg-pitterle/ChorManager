<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Files;

use App\Models\FileVersion;
use App\Services\Files\FileResponseFactory;
use App\Services\Files\FileStorageRegistry;
use App\Services\Files\LocalFileStorage;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class FileResponseFactoryTest extends TestCase
{
    private string $dir;
    private LocalFileStorage $storage;
    private FileResponseFactory $factory;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/files-response-' . bin2hex(random_bytes(6));
        $this->storage = new LocalFileStorage($this->dir);
        $this->factory = new FileResponseFactory(new FileStorageRegistry($this->storage), 4);
    }

    protected function tearDown(): void
    {
        foreach ($this->storage->allPaths() as $path) {
            $this->storage->delete($path);
        }
    }

    private function version(string $content, string $mime = 'audio/mpeg'): FileVersion
    {
        $source = tempnam(sys_get_temp_dir(), 'rsp');
        file_put_contents($source, $content);
        $path = $this->storage->put($source);
        unlink($source);

        $version = new FileVersion();
        $version->storage_driver = 'local';
        $version->storage_path = $path;
        $version->size = strlen($content);
        $version->mime_type = $mime;

        return $version;
    }

    public function testDownloadStreamsWholeFileAsAttachmentWithSafeName(): void
    {
        $response = $this->factory->download(new Response(), $this->version('0123456789'), "Böse\"\nName.mp3");

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('0123456789', (string) $response->getBody());
        $this->assertSame('10', $response->getHeaderLine('Content-Length'));
        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename="Böse__Name.mp3"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''B%C3%B6se__Name.mp3", $disposition);
    }

    public function testInlineRangeReturnsPartialContentCappedAtChunkSize(): void
    {
        $version = $this->version('0123456789');

        $response = $this->factory->inline(new Response(), $version, 'a.mp3', 'bytes=2-');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('2345', (string) $response->getBody(), 'Offenes Ende wird auf die Blockgröße begrenzt.');
        $this->assertSame('bytes 2-5/10', $response->getHeaderLine('Content-Range'));
    }

    public function testInvalidRangeIs416(): void
    {
        $response = $this->factory->inline(new Response(), $this->version('0123'), 'a.mp3', 'bytes=9-12');

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */4', $response->getHeaderLine('Content-Range'));
    }

    public function testInlineWithoutRangeAnnouncesRanges(): void
    {
        $response = $this->factory->inline(new Response(), $this->version('abc', 'application/pdf'), 'a.pdf', '');

        $this->assertSame('abc', (string) $response->getBody());
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('inline;', $response->getHeaderLine('Content-Disposition'));
    }
}

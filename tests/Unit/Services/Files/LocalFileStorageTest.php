<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Files;

use App\Services\Files\LocalFileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocalFileStorageTest extends TestCase
{
    private string $baseDir;
    private LocalFileStorage $storage;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/files-storage-test-' . bin2hex(random_bytes(6));
        $this->storage = new LocalFileStorage($this->baseDir);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->baseDir);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function sourceFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($path, $content);

        return $path;
    }

    public function testPutStoresCopyUnderRandomShardedPath(): void
    {
        $source = $this->sourceFile('Hallo Chor');

        $path = $this->storage->put($source);

        $this->assertMatchesRegularExpression('#^[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}$#', $path);
        $this->assertSame('Hallo Chor', $this->storage->read($path));
        $this->assertFileExists($source, 'Die Quelle bleibt liegen; aufräumen ist Sache des Aufrufers.');
        unlink($source);
    }

    public function testTwoPutsOfSameContentGetDistinctPaths(): void
    {
        $source = $this->sourceFile('gleich');

        $this->assertNotSame($this->storage->put($source), $this->storage->put($source));
        unlink($source);
    }

    public function testReadRangeReturnsOnlyRequestedBytes(): void
    {
        $source = $this->sourceFile('0123456789');
        $path = $this->storage->put($source);
        unlink($source);

        $this->assertSame('345', $this->storage->readRange($path, 3, 3));
        $this->assertSame(10, $this->storage->size($path));
    }

    public function testReadStreamDeliversContent(): void
    {
        $source = $this->sourceFile('Stream');
        $path = $this->storage->put($source);
        unlink($source);

        $stream = $this->storage->readStream($path);
        $this->assertSame('Stream', stream_get_contents($stream));
        fclose($stream);
    }

    public function testDeleteRemovesFileAndIsIdempotent(): void
    {
        $source = $this->sourceFile('weg');
        $path = $this->storage->put($source);
        unlink($source);

        $this->storage->delete($path);
        $this->storage->delete($path);

        $this->assertFalse($this->storage->exists($path));
    }

    public function testWriteAtRestoresFileUnderGivenPath(): void
    {
        $path = 'ab/cd/' . str_repeat('e', 32);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'zurück');
        rewind($stream);

        $this->storage->writeAt($path, $stream);

        $this->assertSame('zurück', $this->storage->read($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPaths(): array
    {
        return [
            'Traversal' => ['../../etc/passwd'],
            'absolut' => ['/etc/passwd'],
            'Großbuchstaben' => ['AB/cd/' . str_repeat('e', 32)],
            'zu kurz' => ['ab/cd/eee'],
            'leer' => [''],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsPathsOutsideTheScheme(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->read($path);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->storage->read('ab/cd/' . str_repeat('f', 32));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\DirectorySize;
use PHPUnit\Framework\TestCase;

final class DirectorySizeTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dirsize-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/a/b', 0777, true);
        file_put_contents($this->dir . '/top.txt', str_repeat('x', 10));
        file_put_contents($this->dir . '/a/one.txt', str_repeat('x', 20));
        file_put_contents($this->dir . '/a/b/two.txt', str_repeat('x', 30));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testMeasuresAllFilesRecursively(): void
    {
        $this->assertSame(['bytes' => 60, 'count' => 3], DirectorySize::measure($this->dir));
    }

    public function testMissingPathIsEmpty(): void
    {
        $this->assertSame(['bytes' => 0, 'count' => 0], DirectorySize::measure($this->dir . '/fehlt'));
    }

    public function testExcludedSubdirectoryIsSkippedAtAnyDepth(): void
    {
        $excluded = (string) realpath($this->dir . '/a/b');

        $this->assertSame(['bytes' => 30, 'count' => 2], DirectorySize::measure($this->dir, [$excluded]));
    }

    public function testExcludedRootIsEmpty(): void
    {
        $this->assertSame(
            ['bytes' => 0, 'count' => 0],
            DirectorySize::measure($this->dir, [(string) realpath($this->dir)])
        );
    }

    public function testSymlinksAreNotFollowed(): void
    {
        $target = sys_get_temp_dir() . '/dirsize-target-' . bin2hex(random_bytes(6));
        mkdir($target);
        file_put_contents($target . '/big.bin', str_repeat('x', 1000));
        if (!@symlink($target, $this->dir . '/link')) {
            exec('rm -rf ' . escapeshellarg($target));
            $this->markTestSkipped('Symlinks werden hier nicht unterstützt.');
        }

        try {
            $this->assertSame(['bytes' => 60, 'count' => 3], DirectorySize::measure($this->dir));
        } finally {
            exec('rm -rf ' . escapeshellarg($target));
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\VarDirectoryUsageProvider;
use PHPUnit\Framework\TestCase;

final class VarDirectoryUsageProviderTest extends TestCase
{
    private string $var = '';

    protected function setUp(): void
    {
        $this->var = sys_get_temp_dir() . '/var-' . bin2hex(random_bytes(6));
        foreach (['cache', 'import', 'files', 'backups', 'data/files', 'neu'] as $dir) {
            mkdir($this->var . '/' . $dir, 0777, true);
        }
        file_put_contents($this->var . '/cache/a', str_repeat('x', 100));
        file_put_contents($this->var . '/import/b', str_repeat('x', 20));
        file_put_contents($this->var . '/files/c', str_repeat('x', 5000));
        file_put_contents($this->var . '/backups/d', str_repeat('x', 6000));
        file_put_contents($this->var . '/data/files/e', str_repeat('x', 7000));
        file_put_contents($this->var . '/data/note', str_repeat('x', 3));
        file_put_contents($this->var . '/neu/f', str_repeat('x', 1));
        file_put_contents($this->var . '/lose.log', str_repeat('x', 9));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->var));
    }

    public function testMeasuresSubdirectoriesWithoutTheOtherAreas(): void
    {
        $provider = new VarDirectoryUsageProvider($this->var, [$this->var . '/files', $this->var . '/backups']);

        $node = $provider->usage();

        $this->assertNull($node->child('var.files'));
        $this->assertNull($node->child('var.backups'));
        $this->assertSame(100, $node->child('var.cache')?->bytes);
        $this->assertSame('Zwischenspeicher', $node->child('var.cache')?->label);
        $this->assertSame('neu', $node->child('var.neu')?->label);
        $this->assertSame(9, $node->child('var.loose')?->bytes);
        $this->assertSame(100 + 20 + 7003 + 1 + 9, $node->bytes);
    }

    /**
     * Liegt die Dateiablage in einem tieferen Unterordner von var/, fällt nur dieser
     * Unterordner heraus, nicht sein ganzer Elternordner.
     */
    public function testNestedAreaDirectoryIsExcludedInsideItsParent(): void
    {
        $provider = new VarDirectoryUsageProvider($this->var, [$this->var . '/data/files', $this->var . '/backups']);

        $node = $provider->usage();

        $this->assertSame(3, $node->child('var.data')?->bytes);
        $this->assertSame(5000, $node->child('var.files')?->bytes, 'var/files ist hier nicht die Ablage.');
    }

    public function testMissingExcludedPathAndMissingVarAreHarmless(): void
    {
        $node = (new VarDirectoryUsageProvider($this->var . '/fehlt', [$this->var . '/gibtsnicht']))->usage();

        $this->assertSame(0, $node->bytes);
        $this->assertSame([], $node->children);
    }
}

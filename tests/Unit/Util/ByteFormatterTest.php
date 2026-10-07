<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\ByteFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Formatierung folgt der bisherigen Anzeige der Sponsoring-Anhänge: KB ganzzahlig,
 * ab MB eine Nachkommastelle, deutsches Komma und Tausenderpunkt.
 */
final class ByteFormatterTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function cases(): array
    {
        return [
            'null Byte' => [0, '0 B'],
            'negativ wird null' => [-5, '0 B'],
            'knapp unter KB' => [1023, '1023 B'],
            'genau 1 KB' => [1024, '1 KB'],
            'KB gerundet' => [1536, '2 KB'],
            'KB mit Tausenderpunkt' => [1023 * 1024, '1.023 KB'],
            'genau 1 MB' => [1048576, '1,0 MB'],
            'MB mit Komma' => [(int) (12.4 * 1048576), '12,4 MB'],
            'GB' => [3 * 1073741824 + 536870912, '3,5 GB'],
            'TB' => [2 * 1099511627776, '2,0 TB'],
            'über TB bleibt TB' => [2048 * 1099511627776, '2.048,0 TB'],
        ];
    }

    #[DataProvider('cases')]
    public function testFormat(int $bytes, string $expected): void
    {
        $this->assertSame($expected, ByteFormatter::format($bytes));
    }
}

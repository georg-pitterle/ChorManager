<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\YoutubeVideoId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aus der Einstellung wird nur eine YouTube-Kennung übernommen - elf Zeichen
 * aus dem Alphabet der Kennungen. Der Wert landet in der Adresse eines
 * eingebetteten Rahmens; alles andere gilt als "kein Video".
 */
final class YoutubeVideoIdTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function inputs(): array
    {
        return [
            'nackte Kennung' => ['aB3_dE-6gH9', 'aB3_dE-6gH9'],
            'mit Leerraum' => ["  aB3_dE-6gH9\n", 'aB3_dE-6gH9'],
            'Kurzlink' => ['https://youtu.be/aB3_dE-6gH9?si=abc', 'aB3_dE-6gH9'],
            'Adresse der Videoseite' => ['https://www.youtube.com/watch?v=aB3_dE-6gH9&t=10s', 'aB3_dE-6gH9'],
            'Einbettungsadresse' => ['https://www.youtube-nocookie.com/embed/aB3_dE-6gH9', 'aB3_dE-6gH9'],
            'fremde Seite' => ['https://example.test/watch?v=aB3_dE-6gH9', ''],
            'zu kurz' => ['aB3_dE', ''],
            'zu lang' => ['aB3_dE-6gH9x', ''],
            'eingeschleustes Markup' => ['"><script>x', ''],
            'leer' => ['', ''],
            'Feld als Liste' => [['aB3_dE-6gH9'], ''],
        ];
    }

    #[DataProvider('inputs')]
    public function testOnlyAWellFormedIdIsAccepted(mixed $input, string $expected): void
    {
        $this->assertSame($expected, YoutubeVideoId::normalize($input));
    }
}

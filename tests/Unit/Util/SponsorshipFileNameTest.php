<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\SponsorshipFileName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ein Vertrag heißt nach Firma, Projekt und Paket - nicht "SCAN0126232026.pdf".
 *
 * Im ersten Praxistest (November 2026) stand in der Anhangliste genau so ein
 * Scannername; welcher Vertrag das war, ließ sich nur durch Öffnen klären.
 */
final class SponsorshipFileNameTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string, ?string, string, list<string>, string}>
     */
    public static function names(): array
    {
        return [
            'Firma, Projekt, Paket' => [
                'Vivatex', 'Durch die Jahrzehnte', '100€', 'SCAN01.pdf', [],
                'Vivatex_Durch-die-Jahrzehnte_100€.pdf',
            ],
            'ohne Projekt und Paket' => ['Vivatex', null, null, 'scan.PDF', [], 'Vivatex.pdf'],
            'Sonderzeichen fallen weg' => [
                'Sanova (Voicla) / Tirol', 'A:B', null, 'x.pdf', [],
                'Sanova-(Voicla)-Tirol_A-B.pdf',
            ],
            'Gedankenstrich im Projektnamen wird ein Bindestrich' => [
                'Café Fermate', 'HS 2026 - Lametta und Legato', 'Gold', 'x.pdf', [],
                'Café-Fermate_HS-2026-Lametta-und-Legato_Gold.pdf',
            ],
            'Umlaute bleiben' => ['Bäckerei Müßig', null, 'Gold', 'x.jpg', [], 'Bäckerei-Müßig_Gold.jpg'],
            'vergebener Name zählt hoch' => [
                'Vivatex', null, null, 'x.pdf', ['Vivatex.pdf', 'vivatex_2.pdf'],
                'Vivatex_3.pdf',
            ],
            'ohne Endung' => ['Vivatex', null, null, 'Vertrag', [], 'Vivatex'],
            'leerer Firmenname' => ['///', null, null, 'x.pdf', [], 'Sponsor.pdf'],
        ];
    }

    /**
     * @param list<string> $taken
     */
    #[DataProvider('names')]
    public function testTheNameIsBuiltFromSponsorProjectAndPackage(
        string $sponsor,
        ?string $project,
        ?string $package,
        string $uploaded,
        array $taken,
        string $expected
    ): void {
        $this->assertSame($expected, SponsorshipFileName::build($sponsor, $project, $package, $uploaded, $taken));
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function renames(): array
    {
        return [
            'Endung bleibt' => ['Vertrag 2026', 'alt.pdf', 'Vertrag 2026.pdf'],
            'mitgetippte Endung wird nicht verdoppelt' => ['Vertrag.pdf', 'alt.pdf', 'Vertrag.pdf'],
            'fremde Endung wird Teil des Namens' => ['Vertrag.exe', 'alt.pdf', 'Vertrag.exe.pdf'],
            'Pfadzeichen fallen weg' => ['../geheim/Vertrag', 'alt.pdf', '..-geheim-Vertrag.pdf'],
            'leer ist kein Name' => ['   ', 'alt.pdf', null],
        ];
    }

    #[DataProvider('renames')]
    public function testRenamingKeepsTheExtension(string $wanted, string $current, ?string $expected): void
    {
        $this->assertSame($expected, SponsorshipFileName::rename($wanted, $current));
    }
}

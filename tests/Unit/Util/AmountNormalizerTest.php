<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\AmountNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ein Betrag ab 0 wird wie im Finanzmodul normalisiert und geprüft.
 *
 * Die Regel stand als `SponsorshipController::validateAmount()` im Controller,
 * und der SponsorPackageController rief sie von dort. Ohne die Prüfung wird
 * "abc" still zu 0,00 und ein negativer Betrag kommentarlos gespeichert; ein
 * leeres Feld ist dagegen erlaubt und gilt als 0,00.
 */
final class AmountNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function nonNegativeAmounts(): array
    {
        return [
            'leer gilt als null' => ['', '0.00'],
            'Tausenderpunkt und Dezimalkomma' => ['1.500,00', '1500.00'],
            'schlichte Zahl' => ['25', '25'],
            'Text' => ['abc', null],
            'negativ' => ['-5', null],
        ];
    }

    #[DataProvider('nonNegativeAmounts')]
    public function testNormalizeNonNegative(string $input, ?string $expected): void
    {
        self::assertSame($expected, AmountNormalizer::normalizeNonNegative($input));
    }
}

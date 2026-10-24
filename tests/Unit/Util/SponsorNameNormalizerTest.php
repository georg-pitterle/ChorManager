<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\SponsorNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Vergleichsschlüssel der Dublettenwarnung: gleich ist, was sich nur in
 * Schreibweise, Satzzeichen oder Rechtsform unterscheidet.
 */
final class SponsorNameNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        // Der Schlüssel enthält keine Leerzeichen: "Sport-Müller", "Sport Müller"
        // und "SportMüller" tippen zwei Leute unabhängig voneinander.
        return [
            'Rechtsform am Ende' => ['Bäckerei Huber GmbH', 'bäckereihuber'],
            'Satzzeichen und doppelter Leerraum' => ['bäckerei  huber.', 'bäckereihuber'],
            'zusammengesetzte Rechtsform mit &' => ['Huber GmbH & Co. KG', 'huber'],
            'Bindestrich und e.U.' => ['Sport-Müller e.U.', 'sportmüller'],
            'Rechtsform mit Leerzeichen' => ['Sport Müller e. U.', 'sportmüller'],
            'Komma vor der Rechtsform' => ['Huber,KG', 'huber'],
            'Ges.m.b.H.' => ['Huber Ges.m.b.H.', 'huber'],
            'Verein' => ['Musikfreunde Tirol e.V.', 'musikfreundetirol'],
            'nur eine Rechtsform' => ['GmbH', ''],
            'Rechtsform mitten im Namen bleibt' => ['AG Kultur Innsbruck', 'agkulturinnsbruck'],
        ];
    }

    public function testHyphenSpaceAndNoSeparatorAreTheSameName(): void
    {
        $this->assertSame(
            SponsorNameNormalizer::normalize('Sport Müller'),
            SponsorNameNormalizer::normalize('Sport-Müller')
        );
        $this->assertSame(
            SponsorNameNormalizer::normalize('SportMüller'),
            SponsorNameNormalizer::normalize('Sport-Müller')
        );
    }

    #[DataProvider('names')]
    public function testNormalizesTheName(string $input, string $expected): void
    {
        $this->assertSame($expected, SponsorNameNormalizer::normalize($input));
    }

    public function testASimilarButDifferentNameStaysDifferent(): void
    {
        $this->assertNotSame(
            SponsorNameNormalizer::normalize('Huber'),
            SponsorNameNormalizer::normalize('Hubert')
        );
    }
}

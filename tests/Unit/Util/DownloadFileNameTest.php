<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\DownloadFileName;
use PHPUnit\Framework\TestCase;

/**
 * Der Name eines Anhangs stammt aus einem Upload und landet im
 * `Content-Disposition`-Kopf. Wagenrücklauf und Zeilenvorschub waren abgedeckt, die
 * übrigen Steuerzeichen nicht — ein Name mit `\x01` darin ergab einen Kopf, den kein
 * Browser mehr richtig liest. Deshalb fiel der Strich durch alle Steuerzeichen.
 */
final class DownloadFileNameTest extends TestCase
{
    public function testControlCharactersAreReplaced(): void
    {
        $safe = DownloadFileName::sanitize("Kyrie\x01Gloria\x1F.pdf");

        $this->assertSame('Kyrie_Gloria_.pdf', $safe);
    }

    public function testDeleteCharacterIsReplaced(): void
    {
        $this->assertSame('Noten_.pdf', DownloadFileName::sanitize("Noten\x7F.pdf"));
    }

    public function testLineBreaksQuotesAndSeparatorsStayReplaced(): void
    {
        $safe = DownloadFileName::sanitize("Tuba\r\nmirum\"/\\.pdf");

        $this->assertSame('Tuba__mirum___.pdf', $safe);
    }

    public function testUmlautsAndSpacesSurvive(): void
    {
        $this->assertSame('Grüß Gott (Satz 2).pdf', DownloadFileName::sanitize('Grüß Gott (Satz 2).pdf'));
    }

    /**
     * Ersetzt, nicht entfernt - ein Name soll erkennbar bleiben, auch wenn von ihm nur
     * Platzhalter übrig sind. Nur ein danach leerer Name greift auf den Ersatznamen zurück.
     */
    public function testControlCharactersBecomePlaceholdersAndOnlyAnEmptyNameFallsBack(): void
    {
        $this->assertSame('___', DownloadFileName::sanitize("\x01\x02\x03"));
        $this->assertSame('download', DownloadFileName::sanitize(''));
        $this->assertSame('download', DownloadFileName::sanitize('   '));
    }

    public function testTabIsReplacedToo(): void
    {
        $this->assertSame('Satz_1.pdf', DownloadFileName::sanitize("Satz\t1.pdf"));
    }
}

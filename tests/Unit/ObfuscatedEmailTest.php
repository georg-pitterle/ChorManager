<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Newsletter\ObfuscatedEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cloudflare ersetzt Mail-Adressen im ausgelieferten HTML durch einen Platzhalter und
 * entschlüsselt ihn erst per Skript im Browser. Gelangt dieser Platzhalter über den Editor
 * zurück in einen Newsletter, steht die Adresse für immer als "[email protected]" darin.
 */
final class ObfuscatedEmailTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function obfuscatedSamples(): array
    {
        return [
            'Platzhalter mit geschütztem Leerzeichen' => ["<p>Schreib an [email\u{00A0}protected]</p>"],
            'Platzhalter mit Entität' => ['<p>Schreib an [email&#160;protected]</p>'],
            'Platzhalter mit benannter Entität' => ['<p>Schreib an [email&nbsp;protected]</p>'],
            'Platzhalter mit normalem Leerzeichen' => ['<p>Schreib an [email protected]</p>'],
            'Klasse des Entschlüsslers' => ['<span class="__cf_email__" data-cfemail="a1b2">x</span>'],
            'Verweis auf den Entschlüssler' => ['<a href="/cdn-cgi/l/email-protection#a1b2c3">Mail</a>'],
        ];
    }

    #[DataProvider('obfuscatedSamples')]
    public function testDetectsCloudflareObfuscation(string $html): void
    {
        $this->assertTrue(ObfuscatedEmail::isPresentIn($html));
    }

    public function testPlainAddressesAreNotFlagged(): void
    {
        $html = '<p>Schreib an kassa@chor.example oder '
            . '<a href="mailto:info@chor.example">info@chor.example</a>, '
            . 'Stichwort: email protected.</p>';

        $this->assertFalse(ObfuscatedEmail::isPresentIn($html));
    }
}

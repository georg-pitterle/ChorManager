<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Hält die Flächenregeln fest: eine Rundung wie das Menü, Akzentlinien in der Vereinsfarbe,
 * ein Rahmen pro Ebene und flache Karten im Dialog.
 */
class UiSurfaceFeatureTest extends TestCase
{
    private static function css(string $file = 'style.css'): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/public/css/' . $file);
        self::assertIsString($content);

        return $content;
    }

    public function testNoSurfaceUsesItsOwnLargeRadius(): void
    {
        foreach (['style.css', 'table-engine.css'] as $file) {
            preg_match_all('/border(?:-[a-z]+)*-radius:\s*([0-9.]+rem)/', self::css($file), $matches);
            $large = array_filter($matches[1], static fn (string $value): bool => (float) $value > 0.375);

            $this->assertSame([], array_values($large), $file . ': feste Rundung statt var(--app-radius).');
        }
    }

    public function testRadiusTokensMatchTheMenu(): void
    {
        $css = self::css();

        $this->assertStringContainsString('--app-radius: 0.375rem;', $css);
        $this->assertStringContainsString('--bs-border-radius-xl: var(--app-radius-lg);', $css);
    }

    public function testAccentLinesUseTheClubColour(): void
    {
        $css = self::css();

        $this->assertStringContainsString('--surface-accent: 3px solid var(--theme-primary', $css);
        foreach (['#2f5e9e', '#425776', '#8a3240'] as $fixedColour) {
            $this->assertStringNotContainsString($fixedColour, $css, 'Feste Akzentfarbe ' . $fixedColour . ' statt Vereinsfarbe.');
        }
    }

    public function testSectionWithFramedContentDropsItsOwnFrame(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.dashboard-section:has\([^)]*\.surface-card[^)]*\)\s*\{[^}]*border:\s*0;[^}]*box-shadow:\s*none;/s',
            self::css()
        );
    }

    public function testCardsInsideDialogsAreFlat(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.modal-body \.surface-card,[^{]*\{[^}]*border:\s*0;[^}]*box-shadow:\s*none;/s',
            self::css()
        );
    }

    /**
     * Die Linkfarbe wird gegen #EEF2F7 auf 4,5:1 gerechnet. Wird der Seitenhintergrund
     * dunkler, reicht der Kontrast nicht mehr - beide müssen zusammen geändert werden.
     */
    public function testPageBackgroundIsTheContrastReference(): void
    {
        $this->assertStringContainsString('--page-bg: #eef2f7;', self::css());
    }

    /**
     * Bootstraps helle Primärtöne (aufgeklapptes Akkordeon, ungelesene Benachrichtigung)
     * wären sonst Bootstrap-Blau statt Vereinsfarbe.
     */
    public function testSubtlePrimaryTonesFollowTheClubColour(): void
    {
        $css = self::css();

        $this->assertMatchesRegularExpression('/--bs-primary-bg-subtle:[^;]*var\(--theme-primary\)/', $css);
        $this->assertMatchesRegularExpression('/--bs-primary-text-emphasis:[^;]*var\(--theme-primary-strong\)/', $css);
    }

    /**
     * Am Telefon sitzt die Glocke nicht am rechten Rand; rechtsbündig an ihr ausgerichtet,
     * ragte das Benachrichtigungsmenü links aus dem Bildschirm.
     */
    public function testNotificationMenuFitsThePhoneScreen(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 575\.98px\) \{\s*\.notification-bell-menu\.show \{[^}]*position: fixed !important;[^}]*inset:[^}]*0\.5rem !important;/s',
            self::css()
        );
    }

    /**
     * Seitenkopf am Telefon: Aktionen als Kacheln (nebeneinander, umbrechend, Zeile gefüllt) -
     * auf jeder Seite gleich, unabhängig von den Hilfsklassen der Seite.
     */
    public function testPageHeaderActionsAreTilesOnPhones(): void
    {
        $css = self::css();

        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 575\.98px\) \{\s*\.page-header \.page-actions \{[^}]*flex-wrap: wrap !important;/s',
            $css
        );
        $this->assertMatchesRegularExpression('/\.page-header \.page-actions > \* \{[^}]*flex: 1 1 auto;/s', $css);
    }
}

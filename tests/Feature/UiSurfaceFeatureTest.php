<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
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

    /**
     * Jede Kanban-Spalte trägt oben die Farbe ihres Zustands. Die Regeln fragten noch die
     * deutschen Statuswerte ab ("In Bearbeitung") und griffen nach der Umstellung auf
     * englische Bezeichner ins Leere - ohne dass ein Test es bemerkte.
     */
    public function testEveryKanbanStatusHasItsColumnColour(): void
    {
        $css = self::css();

        foreach (Task::STATUSES as $status) {
            $this->assertMatchesRegularExpression(
                '/\.kanban-column\[data-status="' . preg_quote($status, '/') . '"\]\s*\{[^}]*border-top:\s*3px solid var\(--/s',
                $css,
                'Kanban-Spalte "' . $status . '" ohne Statusfarbe aus einem Token.'
            );
        }
    }

    /**
     * Am Telefon stehen die Kanban-Spalten untereinander. Mit 500px Mindesthöhe kostete
     * eine Spalte mit einer Karte fast eine ganze Bildschirmhöhe; die Höhe gilt deshalb
     * erst, wenn die Spalten nebeneinander stehen (col-md-6).
     */
    public function testKanbanColumnsOnlyReserveHeightSideBySide(): void
    {
        $css = self::css();

        $this->assertDoesNotMatchRegularExpression('/^\.kanban-column \{[^}]*min-height/m', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\) \{\s*\.kanban-column \{[^}]*min-height: 500px;/s',
            $css
        );
    }

    /**
     * Sechs Kennzahl-Kacheln der Mail-Queue: auto-fit ergab bei Inhaltsbreite fünf
     * nebeneinander und eine allein darunter. Feste Spaltenzahlen teilen sechs glatt
     * (2 / 3 / 6).
     */
    public function testMailQueueStatsSplitSixCardsEvenly(): void
    {
        $css = self::css();

        $this->assertMatchesRegularExpression(
            '/\.mail-queue-overview-grid \{[^}]*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.mail-queue-overview-grid \{[^}]*grid-template-columns: repeat\(3, minmax\(0, 1fr\)\);/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.mail-queue-overview-grid \{[^}]*grid-template-columns: repeat\(6, minmax\(0, 1fr\)\);/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression('/\.mail-queue-overview-grid \{[^}]*auto-fit/s', $css);
    }

    /** DESIGN.md: keine Verläufe auf Flächen - auch nicht auf der Warnkachel der Mail-Queue. */
    public function testSurfacesCarryNoGradient(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.mail-queue-stat-card[^{]*\{[^}]*linear-gradient/s',
            self::css()
        );
    }

    /**
     * Kleine Schriftgrößen kommen aus der Skala in :root (Gruppenlabel, Nebeninfo, klein,
     * Einleitung, Kartentitel). Vorher standen 17 Werte nebeneinander, darunter 0,72 / 0,8 /
     * 0,82 / 0,84 / 0,85 rem für dieselbe Rolle. Ausnahme: der Zähler im Glocken-Kreis.
     */
    public function testSmallFontSizesComeFromTheScale(): void
    {
        preg_match_all('/(?<![-\w])font-size:\s*([0-9.]+)rem/', self::css(), $matches);

        $drift = array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $value): bool => ((float) $value < 1.0 && $value !== '0.6875')
                || in_array($value, ['1.05', '1.0625', '1.1', '1.15'], true)
        )));

        $this->assertSame([], $drift, 'Feste Schriftgröße statt var(--font-size-…).');
    }
}

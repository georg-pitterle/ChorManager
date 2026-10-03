<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die Hilfe beschreibt Klickpfade durch das Menü. Nach dem Umbau auf die Seitenleiste
 * gibt es die Gruppen "Bereiche", "Verwaltung" und "Auswertungen" nicht mehr - ein
 * Pfad dorthin schickt Leserinnen und Leser ins Leere.
 */
class HelpNavigationPathsFeatureTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function helpDocs(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/help/*/docs/*.md');
        $this->assertNotEmpty($files);

        return $files;
    }

    public function testNoHelpDocNamesARemovedMenuGroup(): void
    {
        foreach ($this->helpDocs() as $file) {
            $content = (string) file_get_contents($file);
            // "Verwaltung → OpenID Connect" in sso.md ist Nextclouds eigenes Menü, nicht unseres.
            $content = str_replace('**Verwaltung → OpenID Connect**', '', $content);

            $this->assertDoesNotMatchRegularExpression(
                '/\*\*(Bereiche|Verwaltung|Auswertungen) →/u',
                $content,
                basename($file) . ' nennt eine entfernte Menügruppe.'
            );
        }
    }

    public function testNoHelpDocUsesARenamedMenuEntry(): void
    {
        // Nur Klickpfade: "Projektmitglieder" ist zugleich der Name einer Empfängerquelle,
        // "Backup-Verwaltung" der eines Rechts - beide bleiben als Fachbegriff richtig.
        $renamed = [
            '→ Mitgliederverwaltung**', '→ Meine Newsletter**', '→ Meine Projekte**', '→ Projektmitglieder**',
            '→ Backup-Verwaltung**', '→ Downloads**', '→ Rollen**', 'Termine → Anwesenheit**',
        ];

        foreach ($this->helpDocs() as $file) {
            $content = (string) file_get_contents($file);
            foreach ($renamed as $needle) {
                $this->assertStringNotContainsString($needle, $content, basename($file) . " nennt noch {$needle}");
            }
        }
    }
}

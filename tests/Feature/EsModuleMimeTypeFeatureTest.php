<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Produktivserver muss `.mjs` als JavaScript ausliefern.
 *
 * Anlass: Die PDF-Vorschau lud pdf.js über einen dynamischen Modul-Import von
 * `/vendor/pdfjs/pdf.min.mjs`. Im Betrieb blieb sie stumm, und im Browser stand:
 *
 *   Laden des Moduls von ".../pdf.min.mjs" wurde auf Grund eines nicht
 *   freigegebenen MIME-Typs ("application/octet-stream") blockiert.
 *
 * Die Datei war da und wurde mit 200 ausgeliefert. Nur der MIME-Typ stimmte nicht:
 * `/etc/nginx/mime.types` im Image `nginx:1.28-alpine` kennt `js`, aber kein `mjs`,
 * und damit greift `default_type application/octet-stream`. Browser prüfen den
 * MIME-Typ eines ES-Moduls streng und verweigern die Ausführung - anders als bei
 * einem klassischen `<script src>`, das sie durchgewinkt hätten.
 *
 * Lokal fiel das nie auf: der nginx in DDEV bringt die Abbildung mit. Der Fehler
 * konnte also nur im Betrieb auftreten, und genau dort sieht niemand die Konsole.
 *
 * Der Test liest die Konfiguration, statt einen Server zu starten - er soll in
 * derselben Sekunde mitlaufen wie der Rest der Suite.
 */
final class EsModuleMimeTypeFeatureTest extends TestCase
{
    private function nginxConfiguration(): string
    {
        $path = dirname(__DIR__, 2) . '/nginx.conf';

        $this->assertFileExists($path, 'Die Konfiguration des Produktivservers fehlt.');

        return (string) file_get_contents($path);
    }

    public function testTheProductionServerSendsAJavaScriptMimeTypeForEsModules(): void
    {
        $configuration = $this->nginxConfiguration();

        // Zwei zulässige Schreibweisen: ein eigener types-Eintrag oder ein
        // default_type in einer Regel, die nur auf .mjs greift.
        $viaTypesBlock = preg_match(
            '/types\s*\{[^}]*\b[a-z-]+\/[a-z-]*javascript\s+[^;}]*\bmjs\b[^;}]*;/s',
            $configuration
        ) === 1;

        // Ein Fenster statt "bis zur nächsten schließenden Klammer": die Regel enthält
        // selbst einen if-Block, an dessen Klammer eine solche Suche abbricht.
        $viaLocation = preg_match(
            '/location[^{]*\\\\\.mjs.{0,400}?default_type\s+[a-z-]+\/[a-z-]*javascript\s*;/s',
            $configuration
        ) === 1;

        $this->assertTrue(
            $viaTypesBlock || $viaLocation,
            'nginx.conf liefert .mjs nicht als JavaScript aus. Ohne diese Angabe fällt der'
                . ' Typ auf default_type application/octet-stream zurück, und der Browser'
                . ' verweigert jedes ES-Modul - im Betrieb, wo es niemand sieht.'
        );
    }

    /**
     * Die Gegenprobe zur Regel oben: Sie ist nur so lange nötig, wie die Anwendung
     * überhaupt ein ES-Modul nachlädt. Findet dieser Test keines mehr, gehört die
     * Frage gestellt, ob die nginx-Zeile noch gebraucht wird - statt sie ungeprüft
     * mitzuschleppen.
     */
    public function testTheApplicationStillLoadsAnEsModule(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/attachment-preview.js');

        $this->assertStringContainsString(
            '.mjs',
            $script,
            'Kein ES-Modul mehr in attachment-preview.js - die MIME-Regel in nginx.conf prüfen.'
        );
    }
}

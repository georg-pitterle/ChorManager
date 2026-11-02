<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Provider muss sich in Produktion auch einschalten lassen.
 *
 * Die Compose-Datei reicht nur Variablen in den Container, die dort ausdrücklich
 * stehen. Fehlen sie, bleibt ein in Portainer gesetzter Wert wirkungslos: Das
 * Modul bleibt aus, und `bin/oidc_admin.php key:generate` scheitert am fehlenden
 * Schlüssel, obwohl die Einstellung scheinbar vorhanden ist.
 */
final class OidcProdComposeFeatureTest extends TestCase
{
    public function testProdComposePassesOidcSettingsIntoTheContainer(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2) . '/dist/docker-compose.prod.yml');

        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_OIDC: ${FEATURE_OIDC:-false}', $compose);
        $this->assertStringContainsString('OIDC_SIGNING_KEY_SECRET: ${OIDC_SIGNING_KEY_SECRET:-}', $compose);
    }

    public function testProdEnvExampleDocumentsOidcSettings(): void
    {
        $env = file_get_contents(dirname(__DIR__, 2) . '/dist/.env.example');

        $this->assertIsString($env);
        $this->assertStringContainsString('FEATURE_OIDC=false', $env);
        $this->assertStringContainsString('OIDC_SIGNING_KEY_SECRET=', $env);
    }
}

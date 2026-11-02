<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die Office-Bearbeitung muss sich in Produktion auch einschalten lassen.
 *
 * Die Compose-Datei reicht nur Variablen in den Container, die dort ausdrücklich
 * stehen. Fehlt eine davon, bleibt ein in Portainer gesetzter Wert wirkungslos:
 * Das Modul bleibt aus oder holt die Discovery von der falschen Adresse, obwohl
 * dist/.env.example die Einstellung nennt.
 */
final class OfficeProdComposeFeatureTest extends TestCase
{
    public function testProdComposePassesOfficeSettingsIntoTheContainer(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2) . '/dist/docker-compose.prod.yml');

        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_OFFICE: ${FEATURE_OFFICE:-false}', $compose);
        $this->assertStringContainsString('OFFICE_SERVER_URL: ${OFFICE_SERVER_URL:-}', $compose);
        $this->assertStringContainsString('OFFICE_SERVER_INTERNAL_URL: ${OFFICE_SERVER_INTERNAL_URL:-}', $compose);
        $this->assertStringContainsString('OFFICE_WOPI_BASE_URL: ${OFFICE_WOPI_BASE_URL:-}', $compose);
    }
}

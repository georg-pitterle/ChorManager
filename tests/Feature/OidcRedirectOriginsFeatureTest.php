<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcClient;
use App\Services\Oidc\OidcClientService;
use App\Services\Oidc\OidcRedirectOrigins;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Wohin das Login-Formular nach der Anmeldung weiterleiten darf.
 *
 * Die Ursprünge landen in der form-action-Richtlinie der Login-Seite. Nur
 * Anwendungen, an die tatsächlich angemeldet wird, gehören hinein: aktiv und
 * vertrauenswürdig, wie OidcClientService::isUsableForLogin() es verlangt.
 */
final class OidcRedirectOriginsFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        OidcClient::query()->delete();
    }

    protected function tearDown(): void
    {
        OidcClient::query()->delete();
        parent::tearDown();
    }

    public function testUsableClientsContributeTheOriginOfEachRedirectUri(): void
    {
        $service = new OidcClientService();
        $service->createClient('Nextcloud', [
            'https://cloud.example.org/apps/user_oidc/code',
            'https://cloud.example.org/index.php/apps/user_oidc/code',
            'https://Cloud.Example.org:8443/apps/user_oidc/code',
        ], [], true);
        $service->createClient('Wiki', ['https://wiki.example.org/oidc/callback'], [], true);

        $this->assertSame(
            ['https://cloud.example.org', 'https://cloud.example.org:8443', 'https://wiki.example.org'],
            (new OidcRedirectOrigins())->forLoginForm()
        );
    }

    public function testInactiveAndUntrustedClientsAreLeftOut(): void
    {
        $service = new OidcClientService();
        $inactive = $service->createClient('Alt', ['https://old.example.org/code'], [], true)['client'];
        $inactive->is_active = false;
        $inactive->save();
        $service->createClient('Fremd', ['https://untrusted.example.org/code'], [], false);

        $this->assertSame([], (new OidcRedirectOrigins())->forLoginForm());
    }
}

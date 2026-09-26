<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcSigningKey;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\SecretBoxCryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Bootstrap;

/**
 * Eine abgebrochene Schlüsselrotation darf den Schlüsselbund nicht leeren.
 *
 * `generateKey()` nahm zuerst allen Schlüsseln das `is_active` und legte den
 * neuen erst danach an. Scheitert der zweite Schritt - und er kann scheitern, er
 * verschlüsselt den privaten Teil und schreibt in die Datenbank -, stand die
 * Anwendung ohne aktiven Schlüssel da. Damit signiert der Anbieter kein
 * Anmeldezeugnis mehr: `IdTokenSigner::sign()` wirft, und jede Anmeldung über
 * ChorManager bricht ab, bis jemand von Hand einen neuen Schlüssel erzeugt.
 *
 * Nachgestellt wird der Abbruch über den Verschlüsseler, den der Dienst im
 * Konstruktor bekommt: Er wirft an genau der Stelle zwischen den beiden
 * Schritten.
 */
final class OidcSigningKeyRotationAtomicityFeatureTest extends TestCase
{
    use OidcTestEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $this->enableOidcSigningSecret();
        OidcSigningKey::query()->delete();
    }

    protected function tearDown(): void
    {
        OidcSigningKey::query()->delete();
        $this->restoreOidcSigningSecret();

        parent::tearDown();
    }

    public function testAFailedRotationLeavesThePreviousKeyActive(): void
    {
        $service = new OidcSigningKeyService($this->crypto());
        $established = $service->generateKey();

        $failing = new OidcSigningKeyService($this->failingCrypto());

        try {
            $failing->generateKey();
            $this->fail('Die Rotation hätte scheitern müssen.');
        } catch (RuntimeException) {
            // So gewollt - entscheidend ist der Zustand danach.
        }

        $active = $service->activeKey();

        $this->assertNotNull($active, 'Ohne aktiven Schlüssel signiert der Anbieter nichts mehr.');
        $this->assertSame((string) $established->kid, (string) $active->kid);
        $this->assertSame(
            1,
            OidcSigningKey::query()->where('is_active', true)->count(),
            'Genau ein Schlüssel ist der aktive.'
        );
    }

    public function testASuccessfulRotationStillRetiresThePreviousKey(): void
    {
        $service = new OidcSigningKeyService($this->crypto());

        $first = $service->generateKey();
        $second = $service->generateKey();

        $this->assertSame((string) $second->kid, (string) $service->activeKey()->kid);
        $this->assertSame(1, OidcSigningKey::query()->where('is_active', true)->count());
        // Der abgelöste bleibt für die Karenzzeit in JWKS stehen.
        $this->assertSame(2, OidcSigningKey::query()->count());
        $this->assertNotSame((string) $first->kid, (string) $second->kid);
    }

    private function crypto(): SecretBoxCryptoService
    {
        return new SecretBoxCryptoService(OidcSigningKeyService::KEY_ENV);
    }

    /**
     * Ein Verschlüsseler, der beim Wegschließen des privaten Teils aufgibt.
     */
    private function failingCrypto(): SecretBoxCryptoService
    {
        return new class (OidcSigningKeyService::KEY_ENV) extends SecretBoxCryptoService {
            public function encrypt(string $plaintext): string
            {
                throw new RuntimeException('Der private Schlüssel ließ sich nicht wegschließen.');
            }
        };
    }
}

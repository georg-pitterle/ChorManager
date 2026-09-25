<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcSigningKey;
use App\Services\Oidc\IdTokenSigner;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\SecretBoxCryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Bootstrap;

/**
 * Die Signatur der Anmeldezeugnisse.
 *
 * Geprüft wird das, was der Client tatsächlich tut: Er holt sich den
 * öffentlichen Schlüssel aus JWKS und prüft damit die Signatur. Geht das auf,
 * stimmen Schlüsselablage, Verschlüsselung des privaten Teils, Kodierung und
 * Header-Angaben zusammen.
 */
final class OidcIdTokenSignerFeatureTest extends TestCase
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

    public function testSignedTokenVerifiesAgainstThePublishedJwks(): void
    {
        $keyService = $this->keyService();
        $keyService->generateKey();
        $signer = new IdTokenSigner($keyService);

        $jwt = $signer->sign(['iss' => 'https://chor.example.org', 'sub' => 'cm-1']);

        [$headerPart, $payloadPart, $signaturePart] = explode('.', $jwt);
        $header = json_decode(self::base64UrlDecode($headerPart), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
        $this->assertNotSame('', (string) ($header['kid'] ?? ''));

        $jwks = $signer->jwks();
        $matching = null;
        foreach ($jwks['keys'] as $key) {
            if ($key['kid'] === $header['kid']) {
                $matching = $key;
            }
        }

        $this->assertNotNull($matching, 'Der signierende Schlüssel muss in JWKS stehen.');
        $this->assertSame('RSA', $matching['kty']);
        $this->assertSame('sig', $matching['use']);
        $this->assertSame('RS256', $matching['alg']);
        $this->assertArrayNotHasKey('d', $matching, 'JWKS darf nie den privaten Exponenten tragen.');

        $publicKey = self::publicKeyFromJwk($matching);
        $verified = openssl_verify(
            $headerPart . '.' . $payloadPart,
            self::base64UrlDecode($signaturePart),
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        $this->assertSame(1, $verified, 'Die Signatur muss sich mit dem Schlüssel aus JWKS prüfen lassen.');

        $payload = json_decode(self::base64UrlDecode($payloadPart), true);
        $this->assertSame('cm-1', $payload['sub']);
    }

    public function testTamperedPayloadFailsVerification(): void
    {
        $keyService = $this->keyService();
        $keyService->generateKey();
        $signer = new IdTokenSigner($keyService);

        $jwt = $signer->sign(['sub' => 'cm-1']);
        [$headerPart, , $signaturePart] = explode('.', $jwt);
        $forged = rtrim(strtr(base64_encode('{"sub":"cm-2"}'), '+/', '-_'), '=');

        $matching = $signer->jwks()['keys'][0];
        $verified = openssl_verify(
            $headerPart . '.' . $forged,
            self::base64UrlDecode($signaturePart),
            self::publicKeyFromJwk($matching),
            OPENSSL_ALGO_SHA256
        );

        $this->assertNotSame(1, $verified);
    }

    public function testRotationKeepsTheRetiredKeyInJwksButSignsWithTheNewOne(): void
    {
        $keyService = $this->keyService();
        $firstKid = $keyService->generateKey()->kid;
        $secondKid = $keyService->generateKey()->kid;

        $signer = new IdTokenSigner($keyService);
        $header = json_decode(self::base64UrlDecode(explode('.', $signer->sign(['sub' => 'cm-1']))[0]), true);

        $this->assertSame($secondKid, $header['kid'], 'Signiert wird mit dem zuletzt erzeugten Schlüssel.');

        $publishedKids = array_column($signer->jwks()['keys'], 'kid');
        $this->assertContains($firstKid, $publishedKids, 'Der abgelöste Schlüssel bleibt eine Karenzzeit stehen.');
        $this->assertContains($secondKid, $publishedKids);
    }

    public function testSigningWithoutAnyKeyFailsClosed(): void
    {
        $signer = new IdTokenSigner($this->keyService());

        $this->expectException(RuntimeException::class);
        $signer->sign(['sub' => 'cm-1']);
    }

    public function testPrivateKeyIsNotReadableFromTheDatabaseAlone(): void
    {
        $key = $this->keyService()->generateKey();

        $stored = (string) OidcSigningKey::query()->where('kid', $key->kid)->value('private_key_encrypted');

        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertStringStartsWith('v2:', $stored);
    }

    private function keyService(): OidcSigningKeyService
    {
        return new OidcSigningKeyService(new SecretBoxCryptoService('OIDC_SIGNING_KEY_SECRET'));
    }

    /**
     * @param array<string, mixed> $jwk
     * @return \OpenSSLAsymmetricKey
     */
    private static function publicKeyFromJwk(array $jwk): \OpenSSLAsymmetricKey
    {
        $modulus = self::base64UrlDecode((string) $jwk['n']);
        $exponent = self::base64UrlDecode((string) $jwk['e']);

        $pem = self::rsaPublicKeyPem($modulus, $exponent);
        $key = openssl_pkey_get_public($pem);
        self::assertNotFalse($key, 'JWKS-Eintrag ließ sich nicht in einen Schlüssel überführen.');

        return $key;
    }

    /**
     * Baut aus Modulus und Exponent einen DER/PEM-kodierten öffentlichen Schlüssel.
     * Nur Testwerkzeug - im Betrieb macht das der Client.
     */
    private static function rsaPublicKeyPem(string $modulus, string $exponent): string
    {
        $der = self::derSequence(
            self::derSequence(
                self::derObjectIdentifier("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01")
                    . "\x05\x00"
            )
            . self::derBitString(
                self::derSequence(self::derInteger($modulus) . self::derInteger($exponent))
            )
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derInteger(string $raw): string
    {
        $raw = ltrim($raw, "\x00");
        if ($raw === '' || (ord($raw[0]) & 0x80) !== 0) {
            $raw = "\x00" . $raw;
        }

        return "\x02" . self::derLength(strlen($raw)) . $raw;
    }

    private static function derBitString(string $content): string
    {
        return "\x03" . self::derLength(strlen($content) + 1) . "\x00" . $content;
    }

    private static function derObjectIdentifier(string $raw): string
    {
        return "\x06" . self::derLength(strlen($raw)) . $raw;
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode($padded, true);
    }
}

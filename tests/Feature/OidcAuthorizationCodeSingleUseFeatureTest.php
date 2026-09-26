<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcAuthCode;
use App\Models\User;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\OidcClientService;
use App\Services\Oidc\OidcProtocolException;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Einmaligkeit des Autorisierungscodes - unter gleichzeitigem Zugriff.
 *
 * `redeem()` las den Code, prüfte `used_at` und schrieb die Markierung erst
 * danach. Zwischen Lesen und Schreiben passt ein zweiter Lauf: Beide sahen einen
 * offenen Code, beide kamen durch, und beide bekamen ein Zugriffstoken. Genau der
 * Fall, gegen den die Markierung gedacht ist - ein mitgelesener Code -, blieb
 * damit unbemerkt, denn die Wiedereinlösung, die die Token verbrennt, trat nie ein.
 *
 * Nachgestellt wird die Verschränkung über einen Abfrage-Beobachter: Er markiert
 * den Code in dem Augenblick als eingelöst, in dem `redeem()` ihn gerade gelesen
 * hat. Der Aufruf arbeitet danach mit einem veralteten Stand weiter - und muss
 * trotzdem scheitern.
 */
final class OidcAuthorizationCodeSingleUseFeatureTest extends TestCase
{
    private const REDIRECT_URI = 'https://cloud.example.org/apps/user_oidc/code';
    private const VERIFIER = 'urlaubsfoto-kaffeetasse-notenpult-42';

    private User $user;
    private ?\App\Models\OidcClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        // Ohne Ereignisverteiler ist Connection::listen() eine leere Anweisung -
        // der Beobachter unten liefe nie, und der Test wäre grün, ohne die
        // Verschränkung je hergestellt zu haben.
        Capsule::connection()->setEventDispatcher(new Dispatcher());

        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'email' => 'single-use-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Rudi',
            'last_name' => 'Einmalig',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        $connection->unsetEventDispatcher();

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASecondRedemptionThatStartedBeforeTheFirstOneFinishedIsRejected(): void
    {
        $code = $this->issueCode();
        $service = new AuthorizationCodeService();

        // Der zweite Lauf: Sobald redeem() den Code gelesen hat, ist er weg.
        $this->markUsedOnceAfterTheCodeWasRead();

        $this->expectException(OidcProtocolException::class);

        try {
            $service->redeem($code, $this->clientId(), self::REDIRECT_URI, self::VERIFIER);
        } finally {
            // Und zwar genau einmal eingelöst, nicht zweimal.
            $this->assertSame(
                1,
                OidcAuthCode::query()->whereNotNull('used_at')->count(),
                'Der Code darf nur ein einziges Mal als eingelöst gelten.'
            );
        }
    }

    public function testAnUndisturbedRedemptionStillSucceeds(): void
    {
        $code = $this->issueCode();

        $stored = (new AuthorizationCodeService())
            ->redeem($code, $this->clientId(), self::REDIRECT_URI, self::VERIFIER);

        $this->assertNotNull($stored->used_at);
        $this->assertSame(
            1,
            OidcAuthCode::query()->whereNotNull('used_at')->count(),
            'Die Markierung gehört in die Datenbank, nicht nur an das Modell.'
        );
    }

    /**
     * Markiert den Code als eingelöst, sobald die Abfrage nach seinem Hash
     * gelaufen ist - einmal, damit die Markierung selbst den Beobachter nicht
     * erneut auslöst.
     */
    private function markUsedOnceAfterTheCodeWasRead(): void
    {
        $alreadyMarked = false;

        Capsule::connection()->listen(function ($query) use (&$alreadyMarked): void {
            if ($alreadyMarked || !str_contains($query->sql, 'oidc_auth_codes')) {
                return;
            }
            if (!str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }

            $alreadyMarked = true;
            Capsule::connection()->table('oidc_auth_codes')
                ->whereNull('used_at')
                ->update(['used_at' => date('Y-m-d H:i:s')]);
        });
    }

    private function clientId(): string
    {
        return (string) $this->client()->client_id;
    }

    private function client(): \App\Models\OidcClient
    {
        return $this->client ??= (new OidcClientService())
            ->createClient('Nextcloud', [self::REDIRECT_URI])['client'];
    }

    private function issueCode(): string
    {
        return (new AuthorizationCodeService())->issue(
            $this->client(),
            (int) $this->user->id,
            self::REDIRECT_URI,
            'openid',
            null,
            self::challenge()
        );
    }

    private static function challenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');
    }
}

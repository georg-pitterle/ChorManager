<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarSubscriptionToken;
use App\Models\User;
use App\Services\CalendarSubscriptionService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Kalender-Abos nach der Umstellung auf gehashte Token (Migration 20260825120300).
 *
 * Zwei Dinge müssen gleichzeitig gelten: Neue Abos dürfen aus der Datenbank
 * heraus nicht mehr nutzbar sein, und bereits verteilte Abos aus der Klartext-Zeit
 * müssen weiterlaufen, bis das Mitglied sie erneuert.
 */
final class CalendarSubscriptionFeatureTest extends TestCase
{
    private CalendarSubscriptionService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->service = new CalendarSubscriptionService();
        $this->user = User::create([
            'email' => 'kalender-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => 'Kalender',
            'last_name' => 'Abonnentin',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testNewTokenIsStoredOnlyAsHash(): void
    {
        $token = $this->service->rotateTokenForUser((int) $this->user->id);

        $stored = CalendarSubscriptionToken::where('user_id', $this->user->id)->firstOrFail();

        $this->assertNull($stored->token, 'Der Klartext darf nach dem Erzeugen nicht in der Tabelle stehen.');
        $this->assertSame(CalendarSubscriptionService::hashToken($token), $stored->token_hash);
    }

    public function testNewTokenResolvesBackToItsSubscription(): void
    {
        $token = $this->service->rotateTokenForUser((int) $this->user->id);

        $found = $this->service->findByToken($token);

        $this->assertNotNull($found);
        $this->assertSame((int) $this->user->id, (int) $found->user_id);
    }

    public function testRotatingReplacesTheOldSubscription(): void
    {
        $first = $this->service->rotateTokenForUser((int) $this->user->id);
        $second = $this->service->rotateTokenForUser((int) $this->user->id);

        $this->assertNotSame($first, $second);
        $this->assertNull($this->service->findByToken($first), 'Die alte Adresse muss sofort ungültig sein.');
        $this->assertNotNull($this->service->findByToken($second));
        $this->assertSame(
            1,
            CalendarSubscriptionToken::where('user_id', $this->user->id)->count(),
            'Pro Mitglied darf nur ein Abo bestehen.'
        );
    }

    public function testLegacyPlaintextSubscriptionKeepsWorking(): void
    {
        $legacyToken = bin2hex(random_bytes(32));
        CalendarSubscriptionToken::create([
            'user_id' => (int) $this->user->id,
            'token' => $legacyToken,
            'token_hash' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $found = $this->service->findByToken($legacyToken);

        $this->assertNotNull($found, 'Vor der Umstellung verteilte Abos dürfen nicht brechen.');
        $this->assertSame((int) $this->user->id, (int) $found->user_id);
        $this->assertSame($legacyToken, $this->service->findLegacyTokenForUser((int) $this->user->id));
    }

    public function testHashedSubscriptionHasNoDisplayableAddress(): void
    {
        $this->service->rotateTokenForUser((int) $this->user->id);

        $this->assertTrue($this->service->hasTokenForUser((int) $this->user->id));
        $this->assertNull(
            $this->service->findLegacyTokenForUser((int) $this->user->id),
            'Nach dem Hashen darf die Oberfläche keine Adresse mehr rekonstruieren können.'
        );
    }

    public function testMalformedTokensAreRejectedBeforeHittingTheDatabase(): void
    {
        $this->service->rotateTokenForUser((int) $this->user->id);

        $this->assertNull($this->service->findByToken(''));
        $this->assertNull($this->service->findByToken(str_repeat('a', 63)));
        $this->assertNull($this->service->findByToken(str_repeat('A', 64)));
    }

    public function testDeletingTheUserRemovesTheSubscription(): void
    {
        $this->service->rotateTokenForUser((int) $this->user->id);
        $userId = (int) $this->user->id;

        $this->user->delete();

        $this->assertSame(
            0,
            CalendarSubscriptionToken::where('user_id', $userId)->count(),
            'Der Fremdschlüssel aus 20260825120000 muss das Abo mitnehmen.'
        );
    }
}

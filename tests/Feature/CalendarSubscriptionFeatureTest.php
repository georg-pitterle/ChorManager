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
 * Kalender-Abos nach der Umstellung auf gehashte Token (Migrationen 20260825120300
 * und 20260901122000).
 *
 * Ein Auszug der Tabelle darf keine benutzbare Abo-Adresse mehr hergeben: Der
 * Klartext existiert nirgends, auch nicht für die vor der Umstellung verteilten
 * Abos - die sind in ihren Hash überführt worden und laufen darüber weiter.
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

    /**
     * Seit 20260901122000 gibt es die Klartext-Spalte nicht mehr. Ein Auszug der
     * Tabelle darf deshalb keine benutzbare Abo-Adresse mehr hergeben.
     */
    public function testSubscriptionIsStoredOnlyAsHash(): void
    {
        $token = $this->service->rotateTokenForUser((int) $this->user->id);

        $this->assertFalse(
            Capsule::schema()->hasColumn('calendar_subscription_tokens', 'token'),
            'Die Klartext-Spalte darf nicht wieder auftauchen.'
        );

        $stored = CalendarSubscriptionToken::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame(CalendarSubscriptionService::hashToken($token), $stored->token_hash);
        $this->assertStringNotContainsString(
            $token,
            json_encode($stored->getAttributes(), JSON_THROW_ON_ERROR),
            'In keiner Spalte darf der Klartext stehen.'
        );
    }

    /**
     * Der Abo-Zustand meldet nur noch "vorhanden" - die Adresse selbst ist nach
     * dem Anzeigen nicht mehr rekonstruierbar.
     */
    public function testHashedSubscriptionHasNoDisplayableAddress(): void
    {
        $this->service->rotateTokenForUser((int) $this->user->id);

        $this->assertTrue($this->service->hasTokenForUser((int) $this->user->id));
        $this->assertFalse(
            method_exists($this->service, 'findLegacyTokenForUser'),
            'Der Klartext-Rückfallweg ist entfallen und darf nicht zurückkehren.'
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

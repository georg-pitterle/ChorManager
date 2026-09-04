<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserNotificationSetting;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * `user_notification_settings` hat keine `id`-Spalte, sondern den
 * zusammengesetzten Schlüssel aus `user_id` und `notification_type`
 * (Migration 20260830140000).
 *
 * Eloquent kann damit von sich aus nichts anfangen. Ohne die Überschreibungen im
 * Modell baut es jede Schreibabfrage als `where id = ...` und läuft in
 * "Unknown column 'id' in 'WHERE'". Anlegen und Lesen bleiben davon unberührt -
 * deshalb prüfen die Tests hier gezielt Ändern, Löschen und Neuladen.
 */
final class UserNotificationSettingTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->user = User::create([
            'first_name' => 'Nora',
            'last_name' => 'Wiesinger',
            // naming:ascii - E-Mail-Adressen bleiben technisch ASCII.
            'email' => 'nora.wiesinger.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        UserNotificationSetting::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();

        parent::tearDown();
    }

    public function testEineAndereEntscheidungWirdGespeichert(): void
    {
        UserNotificationSetting::create([
            'user_id' => $this->user->id,
            'notification_type' => 'task_assigned',
            'enabled' => false,
        ]);

        UserNotificationSetting::updateOrCreate(
            ['user_id' => $this->user->id, 'notification_type' => 'task_assigned'],
            ['enabled' => true]
        );

        $stored = UserNotificationSetting::query()
            ->where('user_id', $this->user->id)
            ->where('notification_type', 'task_assigned')
            ->first();

        self::assertNotNull($stored);
        self::assertTrue($stored->enabled, 'Die geänderte Entscheidung muss in der Zeile stehen.');
    }

    public function testDieModellinstanzEntferntNurDieEigeneZeile(): void
    {
        UserNotificationSetting::create([
            'user_id' => $this->user->id,
            'notification_type' => 'task_assigned',
            'enabled' => false,
        ]);
        UserNotificationSetting::create([
            'user_id' => $this->user->id,
            'notification_type' => 'event_reminder',
            'enabled' => false,
        ]);

        $row = UserNotificationSetting::query()
            ->where('user_id', $this->user->id)
            ->where('notification_type', 'task_assigned')
            ->firstOrFail();

        $row->delete();

        $remaining = UserNotificationSetting::query()
            ->where('user_id', $this->user->id)
            ->pluck('notification_type')
            ->all();

        self::assertSame(['event_reminder'], $remaining);
    }

    public function testNeuladenFindetDieZeileWieder(): void
    {
        $row = UserNotificationSetting::create([
            'user_id' => $this->user->id,
            'notification_type' => 'task_assigned',
            'enabled' => false,
        ]);

        $row->refresh();

        self::assertFalse($row->enabled);
    }
}

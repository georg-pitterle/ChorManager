<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die Glocke braucht Seed-Daten: eigene Einträge, ihre Tabelle im Bericht und
 * in der Liste der vor dem Seeden geleerten Tabellen, und Einstellungen auch für
 * den Kanal der Glocke.
 *
 * Wie DevSeedBackupCoverageFeatureTest eine Prüfung am Quelltext - ein echter
 * Seed-Lauf ist für die Suite zu schwer und wird vor dem Abschluss von Hand
 * ausgeführt.
 */
final class DevSeedNotificationCoverageFeatureTest extends TestCase
{
    public function testTheSeedCoversTheBell(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertIsString($content);
        $this->assertStringContainsString("'user_notifications' => 0,", $content);
        $this->assertStringContainsString('function seedUserNotifications', $content);
        $this->assertStringContainsString('$this->seedUserNotifications(', $content);
        $this->assertStringContainsString('NotificationChannel::IN_APP', $content);

        // Geleert wird vor `users`, sonst hielte der Fremdschlüssel das Löschen auf.
        $resetPosition = strpos($content, "            'user_notifications',");
        $usersPosition = strpos($content, "            'users',");
        $this->assertNotFalse($resetPosition, 'user_notifications fehlt in der Liste der geleerten Tabellen.');
        $this->assertNotFalse($usersPosition);
        $this->assertLessThan($usersPosition, $resetPosition);
    }
}

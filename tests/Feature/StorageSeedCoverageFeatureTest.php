<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Jede Kategorie der Speicherübersicht soll auf einer frisch geseedeten
 * Installation einen Wert größer 0 haben. Geprüft wird die Quelle der Seeds,
 * damit eine gelöschte Fixture auffällt, bevor die Übersicht leer aussieht.
 */
final class StorageSeedCoverageFeatureTest extends TestCase
{
    public function testSeedsFeedEveryStorageCategory(): void
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertStringContainsString("'can_manage_storage' => 1", $seed);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($seed, "'Ave verum corpus - Partitur.pdf'"),
            'ältere Versionen'
        );
        $this->assertStringContainsString('$this->fileService->trashFile(', $seed, 'gelöschte Datei');
        $this->assertStringContainsString('$this->fileFolderService->trash(', $seed, 'gelöschter Ordner');
        foreach (['event', 'finance', 'song', 'sponsor', 'sponsorship', 'task'] as $type) {
            $this->assertStringContainsString("'entity_type' => '{$type}'", $seed, "Anhänge {$type}");
        }
        $this->assertStringContainsString('NewsletterAttachmentService::ENTITY_TYPE', $seed);
        $this->assertStringContainsString('$this->seedMailQueue(', $seed);
        $this->assertStringContainsString('$this->seedUserNotifications(', $seed);
    }
}

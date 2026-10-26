<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Dev-Seed legt Stimmgruppen-Listen an: mit und ohne Projekt, mit Häkchen-
 * und Notizspalte, teilweise ausgefüllt, dazu eine archivierte Liste. Geprüft
 * wird die Quelle, wie bei StorageSeedCoverageFeatureTest - ein echter Seed-Lauf
 * gehört nicht in die Suite.
 */
final class VoiceGroupListSeedFeatureTest extends TestCase
{
    public function testTheSeedCreatesListsColumnsAndEntries(): void
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertStringContainsString('$this->seedVoiceGroupLists(', $seed);
        $this->assertStringContainsString("'Beitrag bezahlt'", $seed);
        $this->assertStringContainsString("'Kleidergröße'", $seed);
        $this->assertStringContainsString("'Notenausgabe'", $seed);
        $this->assertStringContainsString("'archived_at'", $seed);
        foreach (['voice_group_lists', 'voice_group_list_columns', 'voice_group_list_entries'] as $counter) {
            $this->assertStringContainsString("'" . $counter . "' => 0", $seed, $counter);
        }
    }
}

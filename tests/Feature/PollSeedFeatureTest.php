<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DevSeedService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Der Dev-Seed legt vier Umfragen an - offen namentlich, offen anonym mit
 * Mehrfachauswahl, beendet mit Sieger, beendet mit Gleichstand - samt Stimmen
 * und Glocken-Eintrag. Geprüft wird die Quelle; ein echter Seed-Lauf gehört
 * nicht in die Suite.
 */
final class PollSeedFeatureTest extends TestCase
{
    public function testTheSeedCreatesPollsVotesAndABellEntry(): void
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertStringContainsString('$this->seedPolls(', $seed);
        $this->assertStringContainsString('NotificationType::POLL_CREATED', $seed);
        foreach (['polls', 'poll_options', 'poll_participants', 'poll_votes', 'poll_text_answers'] as $counter) {
            $this->assertStringContainsString("'" . $counter . "' => 0", $seed, $counter);
        }
        // Offene Fragen: eine namentliche, laufende und eine anonyme, beendete.
        $this->assertStringContainsString("'title' => 'Vorschläge für den nächsten Konzerttitel'", $seed);
        $this->assertSame(2, substr_count($seed, "'kind' => Poll::KIND_TEXT"));
        $this->assertStringContainsString('PollTextAnswer::create(', $seed);
    }

    public function testOnlyAdminBoardAndConductorManagePolls(): void
    {
        $method = (new ReflectionClass(DevSeedService::class))->getMethod('roleDefinitions');
        $service = (new ReflectionClass(DevSeedService::class))->newInstanceWithoutConstructor();

        $holders = [];
        foreach ($method->invoke($service) as $definition) {
            $this->assertArrayHasKey('can_manage_polls', $definition, $definition['name']);
            if ($definition['can_manage_polls'] === 1) {
                $holders[] = $definition['name'];
            }
        }

        $this->assertSame(['Admin', 'Vorstand', 'Chorleitung'], $holders);
    }
}

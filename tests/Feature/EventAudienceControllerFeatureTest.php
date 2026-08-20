<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Services\EventAudienceService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein Termin ohne Zielgruppen-Quellen gilt für alle Mitglieder. Beim Speichern
 * darf eine angegebene Zielgruppe deshalb nicht stillschweigend verworfen
 * werden, nur weil ihre Quelle inzwischen gelöscht wurde.
 */
final class EventAudienceControllerFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use EventScopeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();

        $_SESSION = ['can_manage_events' => true, 'user_id' => 1];
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();
        $_SESSION = [];

        parent::tearDown();
    }

    private function controller(): EventController
    {
        return new EventController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new NameFormatterService()
        );
    }

    private function createEventWithProjectAudience(Project $project): Event
    {
        $event = Event::create([
            'title' => 'Zielgruppen-Termin ' . bin2hex(random_bytes(4)),
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
        ]);

        (new EventAudienceService())->setSources($event, [
            ['type' => EventAudienceSource::TYPE_PROJECT_MEMBERS, 'reference_id' => (int) $project->id],
        ]);

        return $event;
    }

    /** @param array<string, mixed> $overrides */
    private function updateBody(Event $event, array $overrides = []): array
    {
        return array_merge([
            'title' => $event->title,
            'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
            'start_time' => Carbon::parse($event->starts_at)->format('H:i'),
            'end_time' => Carbon::parse($event->ends_at)->format('H:i'),
            'type' => 'Probe',
        ], $overrides);
    }

    public function testUpdateWithOnlyDeletedAudienceSourcesKeepsThePreviousAudience(): void
    {
        $project = Project::create(['name' => 'Zielgruppen-Projekt ' . bin2hex(random_bytes(4))]);
        $event = $this->createEventWithProjectAudience($project);
        $audienceService = new EventAudienceService();

        $deletedProjectId = (int) $project->id + 999000;

        $response = $this->controller()->update(
            $this->makeRequest('POST', '/events/' . $event->id, $this->updateBody($event, [
                'title' => 'Umbenannt trotz kaputter Zielgruppe',
                'sources_json' => json_encode([
                    ['type' => 'project_members', 'reference_id' => $deletedProjectId],
                ]),
            ])),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );

        $this->assertSame(302, $response->getStatusCode());

        $this->assertSame(
            [['type' => 'project_members', 'reference_id' => (int) $project->id]],
            $audienceService->getSources($event->fresh()),
            'Die bisherige Zielgruppe muss erhalten bleiben.'
        );

        $this->assertNotEmpty($_SESSION['error'] ?? null);
        $this->assertStringContainsString('Zielgruppe', (string) $_SESSION['error']);

        $this->assertSame(
            $event->title,
            (string) Event::findOrFail($event->id)->title,
            'Ohne gültige Zielgruppe darf der Termin gar nicht gespeichert werden.'
        );
    }

    public function testUpdateWithAValidAudienceStillSaves(): void
    {
        $project = Project::create(['name' => 'Zielgruppen-Projekt ' . bin2hex(random_bytes(4))]);
        $other = Project::create(['name' => 'Zweitprojekt ' . bin2hex(random_bytes(4))]);
        $event = $this->createEventWithProjectAudience($project);

        $this->controller()->update(
            $this->makeRequest('POST', '/events/' . $event->id, $this->updateBody($event, [
                'title' => 'Neue Zielgruppe',
                'sources_json' => json_encode([
                    ['type' => 'project_members', 'reference_id' => (int) $other->id],
                ]),
            ])),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );

        $this->assertSame(
            [['type' => 'project_members', 'reference_id' => (int) $other->id]],
            (new EventAudienceService())->getSources($event->fresh())
        );
        $this->assertSame('Neue Zielgruppe', (string) Event::findOrFail($event->id)->title);
    }

    public function testUpdateWithoutAnyAudienceStillMeansEveryone(): void
    {
        $project = Project::create(['name' => 'Zielgruppen-Projekt ' . bin2hex(random_bytes(4))]);
        $event = $this->createEventWithProjectAudience($project);

        $this->controller()->update(
            $this->makeRequest('POST', '/events/' . $event->id, $this->updateBody($event, [
                'sources_json' => json_encode([]),
            ])),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );

        $this->assertSame(
            [],
            (new EventAudienceService())->getSources($event->fresh()),
            'Eine bewusst leere Zielgruppe bleibt erlaubt.'
        );
    }
}

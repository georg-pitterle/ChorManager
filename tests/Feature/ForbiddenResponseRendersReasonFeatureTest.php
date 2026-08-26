<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Controllers\EventController;
use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\ProjectQuery;
use App\Services\AttendanceScopeService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Eine 403-Antwort muss eine lesbare Seite sein.
 *
 * Vorher setzten diese Pfade einen Location-Header neben den Status 403 oder
 * gaben gar keinen Körper zurück. Browser folgen einer Weiterleitung nur bei
 * 3xx - der Nutzer landete also auf einer weissen Seite, und die in die Sitzung
 * gelegte Begründung bekam nie eine Seite, auf der sie erscheinen konnte.
 */
class ForbiddenResponseRendersReasonFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private Project $project;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Bootstrap::setupTestDatabase();

        $this->project = Project::create([
            'name' => 'Verbotene Sicht ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt, zu dem die handelnde Person nicht gehört',
        ]);
        $this->outsider = User::create([
            'first_name' => 'Aussen',
            'last_name' => 'Stehend',
            'email' => 'forbidden.' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $_SESSION = ['user_id' => (int) $this->outsider->id];
    }

    protected function tearDown(): void
    {
        Event::where('title', 'like', 'Verbotener Termin%')->delete();
        $this->project->delete();
        $this->outsider->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    public function testEventIndexRejectsAForeignProjectWithAReadablePage(): void
    {
        $controller = new EventController(
            $this->createAppTwig('/events'),
            new NameFormatterService(),
            new NullLogger()
        );

        $response = $controller->index(
            $this->makeRequest('GET', '/events', [], ['project_id' => (string) $this->project->id]),
            $this->makeResponse()
        );

        $this->assertForbiddenPage($response);
    }

    public function testEventDetailRejectsAnEventOutsideTheAudienceWithAReadablePage(): void
    {
        $event = $this->createScopedEvent();

        $controller = new EventController(
            $this->createAppTwig('/events'),
            new NameFormatterService(),
            new NullLogger()
        );

        $response = $controller->detail(
            $this->makeRequest('GET', '/events/' . $event->id),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );

        $this->assertForbiddenPage($response);
    }

    public function testRegistrationDetailRejectsAnEventOutsideTheAudienceWithAReadablePage(): void
    {
        $event = $this->createScopedEvent(['registration_enabled' => true]);

        $controller = new RegistrationController(
            $this->createAppTwig('/registrations'),
            new AttendanceScopeService(),
            new NullLogger(),
            new NameFormatterService()
        );

        $response = $controller->detail(
            $this->makeRequest('GET', '/registrations/' . $event->id),
            $this->makeResponse(),
            ['event_id' => (string) $event->id]
        );

        $this->assertForbiddenPage($response);
    }

    public function testEvaluationRejectsAForeignProjectWithAReadablePage(): void
    {
        $controller = new EvaluationController(
            $this->createAppTwig('/evaluations'),
            new ProjectQuery(new NameFormatterService()),
            new NameFormatterService()
        );

        $response = $controller->index(
            $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) $this->project->id]),
            $this->makeResponse()
        );

        $this->assertForbiddenPage($response);
    }

    /**
     * Der Kern der Sache: Status 403, kein Location-Header und ein Körper, in dem
     * eine Begründung steht.
     */
    private function assertForbiddenPage(ResponseInterface $response): void
    {
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            '',
            $response->getHeaderLine('Location'),
            'Ein 403 mit Location-Header führt im Browser zu einer leeren Seite'
        );

        $body = (string) $response->getBody();
        $this->assertNotSame('', $body, 'Ein 403 ohne Körper ist eine weisse Seite');
        $this->assertStringContainsString('Zugriff verweigert', $body);
    }

    /**
     * Termin mit einer Zielgruppe, zu der die handelnde Person nicht gehört.
     *
     * @param array<string, mixed> $attributes
     */
    private function createScopedEvent(array $attributes = []): Event
    {
        $event = Event::create(array_merge([
            'title' => 'Verbotener Termin ' . bin2hex(random_bytes(4)),
            'starts_at' => Carbon::now()->addDays(3)->format('Y-m-d') . ' 19:00:00',
            'ends_at' => Carbon::now()->addDays(3)->format('Y-m-d') . ' 21:00:00',
            'type' => 'Probe',
        ], $attributes));

        $group = VoiceGroup::create(['name' => 'Fremdgruppe ' . bin2hex(random_bytes(4))]);
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
            'reference_id' => (int) $group->id,
        ]);

        return $event;
    }
}

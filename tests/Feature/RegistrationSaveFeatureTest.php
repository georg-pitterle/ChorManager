<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\EventRegistration;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class RegistrationSaveFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Event $event;
    private User $user;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];

        $this->user = User::where('is_active', 1)->firstOrFail();
        $this->event = Event::create([
            'title' => 'Konzert Anmeldetest',
            'starts_at' => Carbon::now()->addDays(10)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(10)->setTime(22, 0),
            'type' => 'Konzert',
            'registration_enabled' => true,
        ]);

        $_SESSION['user_id'] = (int) $this->user->id;
    }

    protected function tearDown(): void
    {
        EventRegistration::where('event_id', $this->event->id)->delete();
        $this->event->delete();
        $_SESSION = [];
    }

    private function controller(): RegistrationController
    {
        return new RegistrationController(
            Twig::create(dirname(__DIR__) . '/../templates'),
            new AttendanceScopeService(),
            new NullLogger(),
            new \App\Services\NameFormatterService()
        );
    }

    public function testSelfRegistrationCreateAndUpdate(): void
    {
        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $row = EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $this->user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('yes', $row->status);
        $this->assertSame((int) $this->user->id, (int) $row->updated_by);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'no',
            'note' => 'Bin im Urlaub',
        ]);
        $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $row->refresh();
        $this->assertSame('no', $row->status);
        $this->assertSame('Bin im Urlaub', $row->note);
        $this->assertSame(1, EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $this->user->id)->count());
    }

    public function testAjaxSaveReturnsJsonWithOwnStatusAndCountsWithoutRedirect(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/registrations/' . $this->event->id,
            ['status' => 'yes'],
            [],
            ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
        );
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', $response->getHeaderLine('Location'), 'AJAX save must not redirect');

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('yes', $payload['own_status']);
        $this->assertArrayHasKey('yes', $payload['counts']);
        $this->assertArrayHasKey('no', $payload['counts']);
        $this->assertArrayHasKey('maybe', $payload['counts']);
        $this->assertArrayHasKey('open', $payload['counts']);
        $this->assertGreaterThanOrEqual(1, $payload['counts']['yes']);

        $row = EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $this->user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('yes', $row->status);
    }

    public function testAjaxSaveClosedDeadlineReturnsJsonError(): void
    {
        $this->event->update(['registration_deadline' => Carbon::now()->subHour()]);

        $request = $this->makeRequest(
            'POST',
            '/registrations/' . $this->event->id,
            ['status' => 'yes'],
            [],
            ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
        );
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $payload);
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testSelfRegistrationRejectedWhenUserOutsideEventScope(): void
    {
        // Scope the event to a voice group the acting user is not a member of.
        $group = VoiceGroup::create(['name' => 'Selbstanmeldung-Fremd']);
        EventAudienceSource::create([
            'event_id' => $this->event->id,
            'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
            'reference_id' => (int) $group->id,
        ]);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            0,
            EventRegistration::where('event_id', $this->event->id)->count(),
            'a user outside the event scope must not be able to self-register'
        );

        $group->delete();
    }

    public function testInvalidStatusRejected(): void
    {
        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'present',
        ]);
        $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testClosedDeadlineRejectedWith403(): void
    {
        $this->event->update(['registration_deadline' => Carbon::now()->subHour()]);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testPastEventRejectedWith403(): void
    {
        $this->event->update([
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->subDay()->addHours(2),
        ]);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDisabledRegistrationRejected(): void
    {
        $this->event->update(['registration_enabled' => false]);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }
}

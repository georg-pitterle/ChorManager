<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\EventSeries;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class EventRegistrationSettingsFeatureTest extends TestCase
{
    use TestHttpHelpers;

    public function testEventControllerHandlesRegistrationFields(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EventController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString("'registration_enabled'", $controller);
        $this->assertStringContainsString("'registration_deadline'", $controller);
        $this->assertStringContainsString("'attendance_required'", $controller);
    }

    public function testEditTemplateOffersRegistrationAndAttendanceToggles(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/events/edit.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('name="attendance_required"', $template);
        $this->assertStringContainsString('{% if settings.modules.registration %}', $template);
        $this->assertStringContainsString('name="registration_enabled"', $template);
        $this->assertStringContainsString('name="registration_deadline"', $template);
    }

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function controller(): EventController
    {
        return new EventController(Twig::create(dirname(__DIR__) . '/../templates'));
    }

    /**
     * Option A (the scenario the hidden fields in edit.twig actually produce): when the
     * registration feature flag is off, the template renders the CURRENT persisted
     * registration_enabled/registration_deadline values as hidden inputs, so the submitted
     * request body still carries them. This test proves the controller round-trips those
     * values correctly on an otherwise unrelated edit (title change only).
     */
    public function testUpdateRoundTripsRegistrationFieldsCarriedAsHiddenValues(): void
    {
        $deadline = Carbon::now()->addDays(2)->setTime(18, 30, 0);
        $event = Event::create([
            'title' => 'Konzertprobe',
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
            'registration_deadline' => $deadline,
            'attendance_required' => false,
        ]);

        try {
            // Simulates: registration flag OFF, template renders hidden inputs sourced from
            // event.registration_enabled / event.registration_deadline, and the only thing
            // the user actually changed is the title.
            $request = $this->makeRequest('POST', '/events/' . $event->id, [
                'title' => 'Konzertprobe (umbenannt)',
                'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
                'start_time' => Carbon::parse($event->starts_at)->format('H:i'),
                'end_time' => Carbon::parse($event->ends_at)->format('H:i'),
                'registration_enabled' => '1',
                'registration_deadline' => $deadline->format('Y-m-d\TH:i'),
                'attendance_required' => '',
            ]);

            $response = $this->controller()->update($request, $this->makeResponse(), [
                'id' => (string) $event->id,
            ]);

            $this->assertSame(302, $response->getStatusCode());

            $fresh = Event::find($event->id);
            $this->assertSame('Konzertprobe (umbenannt)', $fresh->title);
            $this->assertTrue((bool) $fresh->registration_enabled);
            $this->assertNotNull($fresh->registration_deadline);
            $this->assertSame(
                $deadline->format('Y-m-d H:i'),
                Carbon::parse($fresh->registration_deadline)->format('Y-m-d H:i')
            );
        } finally {
            Event::where('title', 'like', 'Konzertprobe%')->delete();
        }
    }

    /**
     * Option B: documents what currently happens if registration_enabled / registration_deadline
     * / attendance_required are entirely ABSENT from the submitted body (which is exactly what
     * would happen if a future template regression removed the hidden-field rendering while the
     * feature flag is off). The controller has NO fallback of its own to preserve the event's
     * existing values in that case -- it treats missing keys the same as "unchecked" / "empty".
     * This means the data-loss protection for this bug is template-only; a template regression
     * would reintroduce data loss even though the controller itself is unchanged. This test locks
     * in the controller's current (unprotected) contract so any change to that contract is visible.
     */
    public function testUpdateWithoutRegistrationKeysClearsRegistrationSettingsControllerAlone(): void
    {
        $deadline = Carbon::now()->addDays(2)->setTime(18, 30, 0);
        $event = Event::create([
            'title' => 'Konzertprobe Absicherung',
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
            'registration_deadline' => $deadline,
            'attendance_required' => true,
        ]);

        try {
            $request = $this->makeRequest('POST', '/events/' . $event->id, [
                'title' => 'Konzertprobe Absicherung (umbenannt)',
                'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
                'start_time' => Carbon::parse($event->starts_at)->format('H:i'),
                'end_time' => Carbon::parse($event->ends_at)->format('H:i'),
                // registration_enabled / registration_deadline / attendance_required intentionally absent
            ]);

            $response = $this->controller()->update($request, $this->makeResponse(), [
                'id' => (string) $event->id,
            ]);

            $this->assertSame(302, $response->getStatusCode());

            $fresh = Event::find($event->id);
            $this->assertFalse((bool) $fresh->registration_enabled);
            $this->assertNull($fresh->registration_deadline);
            $this->assertFalse((bool) $fresh->attendance_required);
        } finally {
            Event::where('title', 'like', 'Konzertprobe Absicherung%')->delete();
        }
    }

    /**
     * Series propagation: registration_enabled/attendance_required must propagate to all
     * (current and future) events of the series, but registration_deadline is per-event and
     * must never be touched by a series-wide update -- neither for other members nor for the
     * event being edited directly.
     */
    public function testSeriesUpdatePropagatesFlagsButNeverRegistrationDeadline(): void
    {
        $series = EventSeries::create([
            'frequency' => 'weekly',
            'recurrence_interval' => 1,
            'weekdays' => '1',
            'end_date' => Carbon::now()->addMonths(2)->format('Y-m-d'),
        ]);
        $seriesId = (int) $series->id;

        $past = Event::create([
            'title' => 'Serie Probe (vergangen)',
            'starts_at' => Carbon::now()->subDays(10)->setTime(19, 0),
            'ends_at' => Carbon::now()->subDays(10)->setTime(21, 0),
            'type' => 'Probe',
            'series_id' => $seriesId,
            'registration_enabled' => false,
            'registration_deadline' => Carbon::now()->subDays(11),
            'attendance_required' => false,
        ]);

        $current = Event::create([
            'title' => 'Serie Probe (aktuell)',
            'starts_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(3)->setTime(21, 0),
            'type' => 'Probe',
            'series_id' => $seriesId,
            'registration_enabled' => false,
            'registration_deadline' => Carbon::now()->addDays(2),
            'attendance_required' => false,
        ]);

        $future = Event::create([
            'title' => 'Serie Probe (zukuenftig)',
            'starts_at' => Carbon::now()->addDays(10)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(10)->setTime(21, 0),
            'type' => 'Probe',
            'series_id' => $seriesId,
            'registration_enabled' => false,
            'registration_deadline' => Carbon::now()->addDays(9),
            'attendance_required' => false,
        ]);

        $pastDeadline = Carbon::parse($past->registration_deadline)->format('Y-m-d H:i');
        $currentDeadline = Carbon::parse($current->registration_deadline)->format('Y-m-d H:i');
        $futureDeadline = Carbon::parse($future->registration_deadline)->format('Y-m-d H:i');

        try {
            $request = $this->makeRequest('POST', '/events/' . $current->id, [
                'title' => $current->title,
                'starts_at' => Carbon::parse($current->starts_at)->format('Y-m-d'),
                'start_time' => Carbon::parse($current->starts_at)->format('H:i'),
                'end_time' => Carbon::parse($current->ends_at)->format('H:i'),
                'update_series' => '1',
                'registration_enabled' => '1',
                'attendance_required' => '1',
                'registration_deadline' => Carbon::now()->addDays(1)->format('Y-m-d\TH:i'),
            ]);

            $response = $this->controller()->update($request, $this->makeResponse(), [
                'id' => (string) $current->id,
            ]);

            $this->assertSame(302, $response->getStatusCode());

            $freshPast = Event::find($past->id);
            $freshCurrent = Event::find($current->id);
            $freshFuture = Event::find($future->id);

            // Past event is outside the series-update scope (starts_at < edited event) entirely.
            $this->assertFalse((bool) $freshPast->registration_enabled);
            $this->assertFalse((bool) $freshPast->attendance_required);
            $this->assertSame($pastDeadline, Carbon::parse($freshPast->registration_deadline)->format('Y-m-d H:i'));

            // Current (edited) and future series members get the propagated flags...
            $this->assertTrue((bool) $freshCurrent->registration_enabled);
            $this->assertTrue((bool) $freshCurrent->attendance_required);
            $this->assertTrue((bool) $freshFuture->registration_enabled);
            $this->assertTrue((bool) $freshFuture->attendance_required);

            // ...but registration_deadline is untouched for every series member, including the
            // event being edited directly -- it is intentionally per-event, not series-wide.
            $this->assertSame(
                $currentDeadline,
                Carbon::parse($freshCurrent->registration_deadline)->format('Y-m-d H:i')
            );
            $this->assertSame(
                $futureDeadline,
                Carbon::parse($freshFuture->registration_deadline)->format('Y-m-d H:i')
            );
        } finally {
            Event::where('series_id', $seriesId)->delete();
            $series->delete();
        }
    }
}

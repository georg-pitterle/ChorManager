<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Queries\ProjectQuery;
use App\Services\AttendanceScopeService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Anmeldeübersicht und Anmelde-Auswertung stellten je Termin eigene Abfragen -
 * drei in der Übersicht, bis zu vier in der Auswertung. Bei einer Probenserie
 * mit 40 Terminen sind das über hundert Rundreisen für eine einzige Seite.
 *
 * Gemessen wird deshalb nicht eine absolute Zahl, sondern die Steigung: Kommen
 * Termine mit derselben Zielgruppe hinzu, darf die Abfragezahl nicht mitwachsen.
 * Eine absolute Schranke müsste bei jeder harmlosen Änderung nachgezogen werden
 * und würde nichts über das eigentliche Problem aussagen.
 */
class RegistrationQueryCountFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    /** @var list<int> */
    private array $eventIds = [];
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Bootstrap::setupTestDatabase();

        $this->user = User::create([
            'first_name' => 'Zaehl',
            'last_name' => 'Person',
            'email' => 'querycount.' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $_SESSION = [
            'user_id' => (int) $this->user->id,
            'can_manage_attendance_all' => true,
        ];
    }

    protected function tearDown(): void
    {
        EventRegistration::whereIn('event_id', $this->eventIds)->delete();
        Event::whereIn('id', $this->eventIds)->delete();
        $this->user->delete();
        $this->eventIds = [];
        $_SESSION = [];

        parent::tearDown();
    }

    public function testRegistrationOverviewDoesNotQueryPerEvent(): void
    {
        $withTwo = $this->countQueries(fn() => $this->renderOverview(), 2);
        $withEight = $this->countQueries(fn() => $this->renderOverview(), 8);

        $this->assertSame(
            $withTwo,
            $withEight,
            'Sechs weitere Termine derselben Zielgruppe dürfen keine einzige Abfrage kosten'
        );
    }

    public function testRegistrationEvaluationDoesNotQueryPerEvent(): void
    {
        $withTwo = $this->countQueries(fn() => $this->renderEvaluation(), 2);
        $withEight = $this->countQueries(fn() => $this->renderEvaluation(), 8);

        $this->assertSame(
            $withTwo,
            $withEight,
            'Sechs weitere Termine derselben Zielgruppe dürfen keine einzige Abfrage kosten'
        );
    }

    /**
     * Legt genau $eventCount Termine an, misst die Abfragen des Aufrufs und
     * räumt danach wieder ab.
     */
    private function countQueries(callable $render, int $eventCount): int
    {
        $this->makeEvents($eventCount);

        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $render();
        } finally {
            $count = count($connection->getQueryLog());
            $connection->disableQueryLog();
            $connection->flushQueryLog();
            $this->dropEvents();
        }

        return $count;
    }

    private function makeEvents(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            // Ohne Zielgruppen-Quelle gilt der Termin für alle aktiven Mitglieder -
            // alle Termine teilen sich also dieselbe Zielgruppe.
            $event = Event::create([
                'title' => 'Zaehltermin ' . $i,
                'starts_at' => Carbon::now()->addDays($i + 2)->format('Y-m-d') . ' 19:00:00',
                'ends_at' => Carbon::now()->addDays($i + 2)->format('Y-m-d') . ' 21:00:00',
                'type' => 'Probe',
                'registration_enabled' => true,
            ]);

            $this->eventIds[] = (int) $event->id;

            EventRegistration::create([
                'event_id' => $event->id,
                'user_id' => $this->user->id,
                'status' => 'yes',
                'updated_by' => $this->user->id,
            ]);
        }
    }

    private function dropEvents(): void
    {
        EventRegistration::whereIn('event_id', $this->eventIds)->delete();
        Event::whereIn('id', $this->eventIds)->delete();
        $this->eventIds = [];
    }

    private function renderOverview(): void
    {
        $controller = new RegistrationController(
            $this->createAppTwig('/registrations'),
            new AttendanceScopeService(),
            new NullLogger(),
            new NameFormatterService()
        );

        $controller->index($this->makeRequest('GET', '/registrations'), $this->makeResponse());
    }

    private function renderEvaluation(): void
    {
        $controller = new EvaluationController(
            $this->createAppTwig('/evaluations'),
            new ProjectQuery(new NameFormatterService()),
            new NameFormatterService()
        );

        $controller->registrations(
            $this->makeRequest('GET', '/evaluations/registrations'),
            $this->makeResponse()
        );
    }
}

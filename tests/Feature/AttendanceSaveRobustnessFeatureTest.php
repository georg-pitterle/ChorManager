<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AttendanceController;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\AttendanceScopeService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Verhalten von AttendanceController::save() jenseits der Rechteprüfung:
 * Zurücksetzen auf "offen", Umgang mit überlangen Notizen, Erkennen
 * gleichzeitiger Änderungen und sichtbare Abweisungen.
 */
final class AttendanceSaveRobustnessFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private Event $event;
    /** @var array<int, User> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->event = Event::create([
            'title' => 'Probe für Speicherverhalten',
            'starts_at' => Carbon::now()->subDay()->setTime(19, 0),
            'ends_at' => Carbon::now()->subDay()->setTime(21, 0),
            'type' => 'Probe',
            'attendance_required' => true,
        ]);

        $suffix = uniqid();
        $this->members = [];
        foreach (['Eins', 'Zwei', 'Drei'] as $index => $name) {
            $this->members[$index] = User::create([
                'first_name' => 'Anwesenheit',
                'last_name' => 'Mitglied ' . $name . ' ' . $suffix,
                'email' => 'attendance-robust-' . $index . '-' . $suffix . '@example.test',
                'password' => password_hash('test123', PASSWORD_DEFAULT),
                'is_active' => true,
            ]);
        }

        // Ohne Zielgruppen-Quelle gilt der Termin für alle aktiven Mitglieder.
        $_SESSION = ['user_id' => (int) $this->members[0]->id, 'can_manage_attendance_all' => true];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function createTwig(): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addFilter(new \Twig\TwigFilter(
            'person_name',
            static fn(mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/attendance');
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('settings', ['modules' => ['registration' => false]]);
        $environment->addGlobal('csrf_token', 'test-token');
        $this->registerMailBadgeStub($environment);
        $environment->addFunction(new TwigFunction('asset_path', static fn(string $path): string => $path));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $context = NavigationContext::fromSession(
                    $_SESSION,
                    ['modules' => ['registration' => false]],
                    '/attendance',
                    $activeNav
                );

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }

    private function controller(?\Psr\Log\LoggerInterface $logger = null): AttendanceController
    {
        return new AttendanceController(
            $this->createTwig(),
            new AttendanceScopeService(),
            $logger ?? new NullLogger(),
            new NameFormatterService()
        );
    }

    /**
     * @param array<int|string, string> $attendance
     * @param array<int|string, string> $notes
     */
    private function save(array $attendance, array $notes = [], ?string $stateHash = null): \Psr\Http\Message\ResponseInterface
    {
        $body = ['attendance' => $attendance, 'note' => $notes];
        if ($stateHash !== null) {
            $body['state_hash'] = $stateHash;
        }

        return $this->controller()->save(
            $this->makeRequest('POST', '/attendance/' . $this->event->id, $body),
            $this->makeResponse(),
            ['event_id' => (string) $this->event->id]
        );
    }

    /** Liest den Zustands-Hash so aus, wie ihn das Formular mitschicken würde. */
    private function currentStateHash(): string
    {
        $response = $this->controller()->show(
            $this->makeRequest('GET', '/attendance/' . $this->event->id),
            $this->makeResponse(),
            ['event_id' => (string) $this->event->id]
        );

        preg_match('#name="state_hash" value="([a-f0-9]*)"#', (string) $response->getBody(), $matches);
        $this->assertNotEmpty($matches, 'Das Formular muss einen Zustands-Hash mitschicken.');

        return $matches[1];
    }

    private function attendanceOf(User $member): ?Attendance
    {
        return Attendance::where('event_id', $this->event->id)
            ->where('user_id', $member->id)
            ->first();
    }

    public function testStatusCanBeResetToOpenAgain(): void
    {
        Attendance::create([
            'event_id' => $this->event->id,
            'user_id' => $this->members[0]->id,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        $this->save([(string) $this->members[0]->id => Attendance::STATUS_UNKNOWN]);

        $this->assertNull(
            $this->attendanceOf($this->members[0]),
            'Ein einmal gesetzter Status muss wieder auf "offen" zurücknehmbar sein.'
        );
    }

    public function testNoteSurvivesWhenTheStatusStaysOpen(): void
    {
        $this->save(
            [(string) $this->members[0]->id => Attendance::STATUS_UNKNOWN],
            [(string) $this->members[0]->id => 'Rückmeldung steht noch aus'],
        );

        $attendance = $this->attendanceOf($this->members[0]);
        $this->assertNotNull($attendance, 'Eine Notiz ohne Status darf nicht kommentarlos verschwinden.');
        $this->assertSame(Attendance::STATUS_UNKNOWN, $attendance->status);
        $this->assertSame('Rückmeldung steht noch aus', $attendance->note);
    }

    public function testOpenEntriesDoNotCountAsRecordedAttendance(): void
    {
        $this->save(
            [(string) $this->members[0]->id => Attendance::STATUS_UNKNOWN],
            [(string) $this->members[0]->id => 'Nur eine Notiz'],
        );

        $this->assertNotContains(
            Attendance::STATUS_UNKNOWN,
            Attendance::RECORDED_STATUSES,
            'Ein offener Eintrag darf in keiner Anwesenheitsstatistik mitzählen.'
        );
    }

    public function testOverlongNoteIsTruncatedInsteadOfDiscardingTheWholeForm(): void
    {
        $longNote = str_repeat('ä', 400);

        $this->save(
            [
                (string) $this->members[0]->id => Attendance::STATUS_PRESENT,
                (string) $this->members[1]->id => Attendance::STATUS_EXCUSED,
            ],
            [(string) $this->members[0]->id => $longNote],
        );

        $first = $this->attendanceOf($this->members[0]);
        $this->assertNotNull($first);
        $this->assertSame(255, mb_strlen((string) $first->note));

        // Der eigentliche Schaden lag darin, dass ein einziger zu langer Text
        // das gesamte Formular zurückgerollt hat.
        $second = $this->attendanceOf($this->members[1]);
        $this->assertNotNull($second, 'Die übrigen Anwesenheiten müssen trotzdem gespeichert werden.');
        $this->assertSame(Attendance::STATUS_EXCUSED, $second->status);
    }

    public function testSaveWithAnUnchangedStateHashIsAccepted(): void
    {
        // Bewusst mit vorhandenem Eintrag: sonst wäre der Hash der eines leeren
        // Zeilensatzes und der Test bestünde auch ohne echte Prüfung.
        Attendance::create([
            'event_id' => $this->event->id,
            'user_id' => $this->members[0]->id,
            'status' => Attendance::STATUS_EXCUSED,
            'note' => 'Alter Stand',
        ]);

        $hash = $this->currentStateHash();
        $this->assertNotSame(hash('sha256', ''), $hash, 'Der Hash muss den vorhandenen Eintrag abbilden.');

        $this->save([(string) $this->members[0]->id => Attendance::STATUS_PRESENT], [], $hash);

        $this->assertSame(Attendance::STATUS_PRESENT, $this->attendanceOf($this->members[0])?->status);
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testConcurrentChangeIsDetectedAndNothingIsOverwritten(): void
    {
        $hash = $this->currentStateHash();

        // Zweiter Bearbeiter speichert, während das erste Formular offen ist.
        Attendance::create([
            'event_id' => $this->event->id,
            'user_id' => $this->members[0]->id,
            'status' => Attendance::STATUS_EXCUSED,
            'note' => 'Krank gemeldet',
        ]);

        $response = $this->save(
            [(string) $this->members[0]->id => Attendance::STATUS_PRESENT],
            [],
            $hash
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('zwischenzeitlich', (string) $_SESSION['error']);

        $attendance = $this->attendanceOf($this->members[0]);
        $this->assertSame(
            Attendance::STATUS_EXCUSED,
            $attendance?->status,
            'Der fremde Eintrag darf nicht überschrieben werden.'
        );
        $this->assertSame('Krank gemeldet', $attendance?->note);
    }

    public function testAChangeToOtherMembersDoesNotBlockTheOwnSave(): void
    {
        $hashForFirst = $this->stateHashFor([(int) $this->members[0]->id]);

        // Jemand anderes trägt ein Mitglied ein, das im eigenen Formular gar nicht steht.
        Attendance::create([
            'event_id' => $this->event->id,
            'user_id' => $this->members[2]->id,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        $this->save([(string) $this->members[0]->id => Attendance::STATUS_PRESENT], [], $hashForFirst);

        $this->assertSame(
            Attendance::STATUS_PRESENT,
            $this->attendanceOf($this->members[0])?->status,
            'Getrennte Stimmgruppen dürfen sich nicht gegenseitig blockieren.'
        );
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testDeniedSaveShowsAReadableReasonInsteadOfABlankPage(): void
    {
        $noListEvent = Event::create([
            'title' => 'Fest ohne Anwesenheitsliste',
            'starts_at' => Carbon::now()->subDays(2)->setTime(18, 0),
            'ends_at' => Carbon::now()->subDays(2)->setTime(22, 0),
            'type' => 'Sonstiges',
            'attendance_required' => false,
        ]);

        $response = $this->controller()->save(
            $this->makeRequest('POST', '/attendance/' . $noListEvent->id, [
                'attendance' => [(string) $this->members[0]->id => Attendance::STATUS_PRESENT],
            ]),
            $this->makeResponse(),
            ['event_id' => (string) $noListEvent->id]
        );

        $body = (string) $response->getBody();

        $this->assertSame(403, $response->getStatusCode());
        // Ein 403 mit Location-Header zeigte im Browser nur eine leere Seite.
        $this->assertSame([], $response->getHeader('Location'));
        $this->assertStringContainsString('keine Anwesenheitsliste', $body);
    }

    public function testDenialIsLogged(): void
    {
        $logger = new class extends NullLogger {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $context;
            }
        };

        $noListEvent = Event::create([
            'title' => 'Fest ohne Liste für Logging',
            'starts_at' => Carbon::now()->subDays(2)->setTime(18, 0),
            'ends_at' => Carbon::now()->subDays(2)->setTime(22, 0),
            'type' => 'Sonstiges',
            'attendance_required' => false,
        ]);

        $this->controller($logger)->save(
            $this->makeRequest('POST', '/attendance/' . $noListEvent->id, [
                'attendance' => [(string) $this->members[0]->id => Attendance::STATUS_PRESENT],
            ]),
            $this->makeResponse(),
            ['event_id' => (string) $noListEvent->id]
        );

        $events = array_column($logger->records, 'event');
        $this->assertContains('authz.denied', $events, 'Jede Abweisung muss eine Log-Spur hinterlassen.');
    }

    /**
     * @param list<int> $userIds
     */
    private function stateHashFor(array $userIds): string
    {
        $method = new \ReflectionMethod(AttendanceController::class, 'attendanceStateHash');

        return (string) $method->invoke($this->controller(), (int) $this->event->id, $userIds);
    }
}

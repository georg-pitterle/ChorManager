<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\User;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;

/**
 * Serienanlage: Takt und Intervall kommen aus dem Formular und steuern die
 * Abbruchbedingung der Erzeugungsschleife. Ohne serverseitige Prüfung dreht
 * sich die Schleife bei einem unbekannten Takt endlos (kein Zweig rückt das
 * Datum vor), und ein Intervall von 0 oder weniger legt 501 Termine auf
 * denselben Tag - beides nur über ein manipuliertes Formular erreichbar,
 * beides ohne Zutun des Servers nicht abzufangen.
 */
class EventSeriesRecurrenceValidationFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private static ?Capsule $capsule = null;
    private User $sessionUser;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$capsule?->connection()->beginTransaction();

        $this->sessionUser = User::create([
            'email' => 'series_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Sara',
            'last_name' => 'Serienstein',
            'is_active' => 1,
        ]);

        $_SESSION = [
            'user_id' => (int) $this->sessionUser->id,
            'can_manage_events' => true,
        ];
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];

        parent::tearDown();
    }

    public function testUnknownFrequencyIsRejectedInsteadOfLoopingForever(): void
    {
        $response = $this->postSeries('Takt kaputt', ['frequency' => 'hourly']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'Ungültige Wiederholung. Bitte Takt und Intervall prüfen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(0, Event::where('title', 'Takt kaputt')->count());
        $this->assertSame(0, EventSeries::where('frequency', 'hourly')->count());
    }

    public function testEmptyFrequencyIsRejected(): void
    {
        $response = $this->postSeries('Takt leer', ['frequency' => '']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'Ungültige Wiederholung. Bitte Takt und Intervall prüfen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(0, Event::where('title', 'Takt leer')->count());
    }

    public function testZeroRecurrenceIntervalIsRejected(): void
    {
        $response = $this->postSeries('Intervall null', [
            'frequency' => 'daily',
            'recurrence_interval' => '0',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'Ungültige Wiederholung. Bitte Takt und Intervall prüfen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(0, Event::where('title', 'Intervall null')->count());
    }

    public function testNegativeRecurrenceIntervalIsRejected(): void
    {
        $response = $this->postSeries('Intervall negativ', [
            'frequency' => 'weekly',
            'recurrence_interval' => '-3',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'Ungültige Wiederholung. Bitte Takt und Intervall prüfen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(0, Event::where('title', 'Intervall negativ')->count());
    }

    public function testNonNumericRecurrenceIntervalIsRejected(): void
    {
        $this->postSeries('Intervall Text', [
            'frequency' => 'daily',
            'recurrence_interval' => 'viele',
        ]);

        $this->assertSame(
            'Ungültige Wiederholung. Bitte Takt und Intervall prüfen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(0, Event::where('title', 'Intervall Text')->count());
    }

    public function testOnlyInvalidWeekdaysAreRejectedInsteadOfSilentlyBecomingADailySeries(): void
    {
        $this->postSeries('Wochentag ungültig', [
            'frequency' => 'weekly',
            'weekdays' => ['9', '0'],
        ]);

        $this->assertSame(
            'Ungültige Wiederholung. Bitte Takt und Intervall prüfen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(0, Event::where('title', 'Wochentag ungültig')->count());
    }

    public function testValidWeeklySeriesStillCreatesEvents(): void
    {
        $start = Carbon::now()->addDays(3)->startOfDay();
        $this->postSeries('Gültige Serie', [
            'frequency' => 'weekly',
            'recurrence_interval' => '1',
            'weekdays' => [(string) $start->dayOfWeekIso],
        ], $start);

        $created = Event::where('title', 'Gültige Serie')->get();

        $this->assertGreaterThan(1, $created->count());
        $this->assertNotNull($created->first()->series_id);
        $this->assertNull($_SESSION['error'] ?? null);
    }

    public function testMissingRecurrenceIntervalFallsBackToOne(): void
    {
        $start = Carbon::now()->addDays(2)->startOfDay();
        $this->postSeries('Intervall fehlt', [
            'frequency' => 'daily',
        ], $start);

        $series = EventSeries::orderBy('id', 'desc')->first();

        $this->assertNotNull($series);
        $this->assertSame(1, (int) $series->recurrence_interval);
        $this->assertGreaterThan(1, Event::where('title', 'Intervall fehlt')->count());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function postSeries(string $title, array $overrides, ?Carbon $start = null): \Psr\Http\Message\ResponseInterface
    {
        unset($_SESSION['error']);

        $start ??= Carbon::now()->addDays(3)->startOfDay();

        $payload = array_merge([
            'title' => $title,
            'starts_at' => $start->format('Y-m-d'),
            'start_time' => '19:00',
            'end_time' => '21:00',
            'repeat' => '1',
            'series_end_date' => $start->copy()->addDays(21)->format('Y-m-d'),
        ], $overrides);

        $controller = new EventController($this->createTwig(), new NameFormatterService(), new NullLogger());

        return $controller->create(
            $this->makeRequest('POST', '/events', $payload),
            $this->makeResponse()
        );
    }

    private function createTwig(): Twig
    {
        return new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AttendanceController;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\AttendanceScopeService;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Der Name der Terminart steht zweimal: in `event_types.name` und zusätzlich als
 * Zeichenkette in `events.type`. Geschrieben wird die Spalte beim Anlegen und
 * Bearbeiten eines Termins - beim Umbenennen der Terminart dagegen nicht, und
 * das tut niemand nach. Wer die Spalte liest, zeigt deshalb nach einer
 * Umbenennung den alten Namen weiter an.
 *
 * Genau so stand es auf der Anwesenheitsseite: Terminauswahl und Kopfzeile
 * lasen `events.type`, während Terminliste und Kalender längst die Beziehung
 * nahmen. Dieselbe Probe hieß dort "Gesamtprobe" und hier noch "Probe".
 *
 * Maßgeblich ist die Beziehung. Die Regel steht am Modell (`Event::typeName()`),
 * damit sie nicht in jeder Ansicht erneut aufgeschrieben wird; die Spalte bleibt
 * nur der Rückfall für Termine ohne `event_type_id`.
 */
class EventTypeNameFollowsRenameFeatureTest extends TestCase
{
    use AudienceFixtures;
    use TestHttpHelpers;
    use TwigViewStubs;

    private static ?Capsule $capsule = null;

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
        $_SESSION = [];

        self::$capsule?->connection()->beginTransaction();
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

    /**
     * Die Beziehung entscheidet, nicht die mitgeschriebene Spalte.
     */
    public function testDerTerminartnameKommtAusDerBeziehung(): void
    {
        $type = EventType::create(['name' => 'Gesamtprobe', 'color' => 'info', 'is_highlighted' => false]);
        $event = Event::create([
            'title' => 'Probe mit umbenannter Terminart',
            'starts_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(2)->setTime(21, 0),
            'event_type_id' => $type->id,
            'type' => 'Probe',
            'attendance_required' => true,
        ]);

        self::assertSame('Gesamtprobe', $event->typeName());
    }

    /**
     * Ohne Terminart bleibt die Spalte der Rückfall - sonst stünde in der
     * Kopfzeile nichts, wo vorher ein Name stand.
     */
    public function testOhneTerminartBleibtDieSpalteDerRueckfall(): void
    {
        $event = Event::create([
            'title' => 'Termin ohne Terminart',
            'starts_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(2)->setTime(21, 0),
            'type' => 'Sonderprobe',
            'attendance_required' => true,
        ]);

        self::assertSame('Sonderprobe', $event->typeName());
    }

    /**
     * Der Weg, auf dem der veraltete Name sichtbar war: die Anwesenheitsseite.
     */
    public function testDieAnwesenheitsseiteZeigtDenUmbenanntenNamen(): void
    {
        $type = EventType::create(['name' => 'Probe', 'color' => 'info', 'is_highlighted' => false]);
        $event = Event::create([
            'title' => 'Anwesenheit nach Umbenennung',
            'starts_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(3)->setTime(21, 0),
            'event_type_id' => $type->id,
            'type' => 'Probe',
            'attendance_required' => true,
        ]);
        $this->openToEveryone($event);

        // Die Terminart wird umbenannt - `events.type` bleibt dabei stehen.
        $type->update(['name' => 'Gesamtprobe']);

        $body = $this->renderAttendancePage($event);

        self::assertStringContainsString('Gesamtprobe', $body);
        self::assertStringNotContainsString('(Probe)', $body);
        self::assertStringNotContainsString('Typ: Probe', $body);
    }

    private function renderAttendancePage(Event $event): string
    {
        $member = User::create([
            'first_name' => 'Rita',
            'last_name' => 'Terminart-' . uniqid(),
            'email' => 'event-type-rename-' . uniqid() . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => true,
        ]);

        $_SESSION['user_id'] = $member->id;
        $_SESSION['can_manage_attendance_all'] = true;

        $controller = new AttendanceController(
            $this->createAttendanceTwig(),
            new AttendanceScopeService($_SESSION),
            new NullLogger(),
            new NameFormatterService()
        );

        $request = $this->makeRequest('GET', '/attendance/' . $event->id);
        $response = $controller->show($request, $this->makeResponse(), [
            'event_id' => (string) $event->id,
        ]);

        return (string) $response->getBody();
    }

    private function createAttendanceTwig(): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $this->registerMailBadgeStub($environment);
        $environment->addGlobal('current_path', '/attendance');
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('settings', ['modules' => ['registration' => false]]);
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static fn (string $path): string => $path
        ));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $context = NavigationContext::fromSession($_SESSION, [], '/attendance', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }
}

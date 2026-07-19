<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AttendanceController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Task 10: the attendance-taking page must show a read-only registration-status
 * hint (Zusage/Absage/Vielleicht/Offen) per member, sourced from the real
 * EventRegistration row for the current event — gated on the registration
 * feature flag AND the current event's registration_enabled flag. It must
 * never be an editable input and must never appear when either gate is off.
 */
class AttendanceRegistrationHintFeatureTest extends TestCase
{
    use TestHttpHelpers;

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

    public function testRegistrationBadgeShowsYesWhenFlagAndEventEnabled(): void
    {
        $body = $this->renderAttendancePage(
            registrationFeatureEnabled: true,
            eventRegistrationEnabled: true,
            registrationStatus: EventRegistration::STATUS_YES,
            registrationNote: 'Bin dabei!'
        );

        $this->assertStringContainsString('registration-badge-yes', $body);
        $this->assertStringContainsString('Zusage', $body);
        $this->assertStringContainsString('Bin dabei!', $body);
        $this->assertStringNotContainsString('registration-badge-no', $body);
        $this->assertStringNotContainsString('registration-badge-maybe', $body);
    }

    public function testRegistrationBadgeShowsNoStatus(): void
    {
        $body = $this->renderAttendancePage(
            registrationFeatureEnabled: true,
            eventRegistrationEnabled: true,
            registrationStatus: EventRegistration::STATUS_NO,
            registrationNote: null
        );

        $this->assertStringContainsString('registration-badge-no', $body);
        $this->assertStringContainsString('Absage', $body);
    }

    public function testRegistrationBadgeShowsMaybeStatus(): void
    {
        $body = $this->renderAttendancePage(
            registrationFeatureEnabled: true,
            eventRegistrationEnabled: true,
            registrationStatus: EventRegistration::STATUS_MAYBE,
            registrationNote: null
        );

        $this->assertStringContainsString('registration-badge-maybe', $body);
        $this->assertStringContainsString('Vielleicht', $body);
    }

    public function testRegistrationBadgeShowsOpenWhenNoRegistrationRowExists(): void
    {
        $body = $this->renderAttendancePage(
            registrationFeatureEnabled: true,
            eventRegistrationEnabled: true,
            registrationStatus: null,
            registrationNote: null
        );

        $this->assertStringContainsString('registration-badge-open', $body);
        $this->assertStringContainsString('Offen', $body);
    }

    public function testRegistrationBadgeHiddenWhenFeatureFlagDisabled(): void
    {
        $body = $this->renderAttendancePage(
            registrationFeatureEnabled: false,
            eventRegistrationEnabled: true,
            registrationStatus: EventRegistration::STATUS_YES,
            registrationNote: null
        );

        $this->assertStringNotContainsString('registration-badge-yes', $body);
        $this->assertStringNotContainsString('registration-badge-open', $body);
    }

    public function testRegistrationBadgeHiddenWhenEventRegistrationDisabled(): void
    {
        $body = $this->renderAttendancePage(
            registrationFeatureEnabled: true,
            eventRegistrationEnabled: false,
            registrationStatus: EventRegistration::STATUS_YES,
            registrationNote: null
        );

        $this->assertStringNotContainsString('registration-badge-yes', $body);
        $this->assertStringNotContainsString('registration-badge-open', $body);
    }

    public function testRegistrationBadgeIsNotAFormInput(): void
    {
        $body = $this->renderAttendancePage(
            registrationFeatureEnabled: true,
            eventRegistrationEnabled: true,
            registrationStatus: EventRegistration::STATUS_YES,
            registrationNote: null
        );

        $this->assertStringContainsString('registration-badge-yes', $body);
        $this->assertStringNotContainsString('name="registration', $body);
        $this->assertMatchesRegularExpression(
            '/<span class="badge registration-badge-yes"[^>]*>Zusage<\/span>/',
            $body,
            'the registration hint must render as a plain read-only span, not an input/select'
        );
    }

    private function renderAttendancePage(
        bool $registrationFeatureEnabled,
        bool $eventRegistrationEnabled,
        ?string $registrationStatus,
        ?string $registrationNote
    ): string {
        $event = Event::create([
            'title' => 'Registrierungs-Hinweis-Test-Termin',
            'starts_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(3)->setTime(21, 0),
            'type' => 'Probe',
            'attendance_required' => true,
            'registration_enabled' => $eventRegistrationEnabled,
        ]);

        $member = User::create([
            'first_name' => 'Regina',
            'last_name' => 'Testmitglied-Task10-' . uniqid(),
            'email' => 'registration-hint-' . uniqid() . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => true,
        ]);

        if ($registrationStatus !== null) {
            EventRegistration::create([
                'event_id' => $event->id,
                'user_id' => $member->id,
                'status' => $registrationStatus,
                'note' => $registrationNote,
            ]);
        }

        $_SESSION['user_id'] = $member->id;
        $_SESSION['can_manage_users'] = true;

        $controller = new AttendanceController(
            $this->createTwig($registrationFeatureEnabled),
            new AttendanceScopeService()
        );

        $request = $this->makeRequest('GET', '/attendance/' . $event->id);
        $response = $controller->show($request, $this->makeResponse(), [
            'event_id' => (string) $event->id,
        ]);

        return (string) $response->getBody();
    }

    private function createTwig(bool $registrationFeatureEnabled): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/attendance');
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('settings', [
            'modules' => [
                'registration' => $registrationFeatureEnabled,
            ],
        ]);
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static function (string $path): string {
                return $path;
            }
        ));
        $environment->addFunction(new TwigFunction(
            'nav_active',
            static function (
                string $path,
                ?string $activeNav = null,
                array $pathPrefixes = [],
                array $navKeys = [],
                array $excludePrefixes = []
            ): bool {
                return false;
            }
        ));

        return $twig;
    }
}

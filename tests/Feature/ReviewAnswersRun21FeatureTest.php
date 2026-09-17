<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Controllers\EventController;
use App\Logging\RequestContext;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\ArrayLoader;

/**
 * Antworten auf die Rückfragen aus dem Review-Lauf 21.
 *
 * 1) Der Termin meldete einen fehlgeschlagenen Schreibvorgang mit dem rohen
 *    Ausnahmetext ("Fehler beim Anlegen: SQLSTATE[22001] ..."). Der Grund
 *    gehört ins Protokoll, nicht vor die Augen der Terminverwaltung. Was
 *    bisher als Ausnahme geworfen und im selben Zweig angezeigt wurde - das
 *    fehlende Enddatum einer Serie - ist dagegen eine Eingabeprüfung und
 *    braucht seine eigene, benennende Meldung.
 *
 * 3) Bei der Anmeldung lief password_verify() nur, wenn es das Konto gibt.
 *    Eine unbekannte Adresse antwortete dadurch messbar schneller als eine
 *    bekannte mit falschem Passwort.
 */
class ReviewAnswersRun21FeatureTest extends TestCase
{
    use TestHttpHelpers;

    private string $rateLimiterStoreDir;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->rateLimiterStoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'chormanager_run21_answers_' . bin2hex(random_bytes(4));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['can_manage_events'] = true;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        if (is_dir($this->rateLimiterStoreDir)) {
            foreach (glob($this->rateLimiterStoreDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->rateLimiterStoreDir);
        }

        parent::tearDown();
    }

    private function eventController(?NullLogger $logger = null): EventController
    {
        // create() leitet in jedem geprüften Fall weiter und rendert nichts.
        return new EventController(
            new Twig(new ArrayLoader([])),
            new NameFormatterService(),
            $logger ?? new NullLogger()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function eventForm(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Antwort-Testtermin ' . bin2hex(random_bytes(4)),
            'starts_at' => '2027-03-01',
            'start_time' => '19:00',
            'end_time' => '21:00',
        ];
    }

    private function createError(array $form): string
    {
        unset($_SESSION['event_create_message'], $_SESSION['error']);

        $this->eventController()->create(
            $this->makeRequest('POST', '/events', $form),
            $this->makeResponse()
        );

        return (string) ($_SESSION['event_create_message'] ?? $_SESSION['error'] ?? '');
    }

    public function testMissingSeriesEndDateNamesTheFieldInsteadOfReadingLikeACrash(): void
    {
        $message = $this->createError($this->eventForm([
            'repeat' => '1',
            'frequency' => 'weekly',
            'recurrence_interval' => '1',
            'weekdays' => ['1'],
            'series_end_date' => '',
        ]));

        $this->assertStringContainsString('Enddatum', $message);
        $this->assertStringNotContainsString('Fehler beim Anlegen:', $message);
        $this->assertSame(0, Event::where('title', 'like', 'Antwort-Testtermin%')->count());
    }

    public function testUnreadableSeriesEndDateNamesTheFieldInsteadOfFailingGenerically(): void
    {
        $message = $this->createError($this->eventForm([
            'repeat' => '1',
            'frequency' => 'weekly',
            'recurrence_interval' => '1',
            'weekdays' => ['1'],
            'series_end_date' => '31.02.2027 irgendwas',
        ]));

        $this->assertStringContainsString('Enddatum', $message);
        $this->assertSame(0, Event::where('title', 'like', 'Antwort-Testtermin%')->count());
    }

    /**
     * Ein echter Schreibfehler, nicht nachgestellt: `events.title` ist
     * varchar(255) und die Datenbank läuft mit STRICT_TRANS_TABLES.
     */
    public function testAFailedEventWriteKeepsTheDriverTextOutOfTheForm(): void
    {
        [$logger, $handler] = $this->logger();

        unset($_SESSION['event_create_message'], $_SESSION['error']);

        $controller = new EventController(
            new Twig(new ArrayLoader([])),
            new NameFormatterService(),
            $logger
        );
        $controller->create(
            $this->makeRequest('POST', '/events', $this->eventForm(['title' => str_repeat('x', 300)])),
            $this->makeResponse()
        );

        $message = (string) ($_SESSION['event_create_message'] ?? $_SESSION['error'] ?? '');

        $this->assertNotSame('', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('Data too long', $message);
        $this->assertNotNull($this->recordFor($handler, 'event.create.failed'));
    }

    public function testPasswordHasherOffersAReusableDummyHashAtTheCurrentCost(): void
    {
        $dummy = PasswordHasher::dummyHash();

        $this->assertNotSame('', $dummy);
        $this->assertFalse(password_verify('', $dummy));
        // Derselbe Aufwand wie ein echter Hash, sonst verrät die Dauer den Unterschied.
        $this->assertSame(
            password_get_info(PasswordHasher::hash('irgendwas'))['options']['cost'] ?? null,
            password_get_info($dummy)['options']['cost'] ?? null
        );
        // Zweimal derselbe Wert: Der Hash wird einmal gebaut und wiederverwendet.
        $this->assertSame($dummy, PasswordHasher::dummyHash());
    }

    public function testUnknownAccountStillPaysForAPasswordCheck(): void
    {
        $controller = new AuthController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new RememberLoginService(),
            new SessionAuthService(new NameFormatterService(), new RequestContext()),
            new RateLimiterService($this->rateLimiterStoreDir),
            new PasswordPolicyService(),
            new NullLogger()
        );

        $role = Role::create([
            'name' => 'Run21 Timing Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $user = User::create([
            'first_name' => 'Timing',
            'last_name' => 'Tester',
            'email' => 'timing.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('Correct-Horse-1'),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);

        $known = $this->measure($controller, (string) $user->email);
        $unknown = $this->measure($controller, 'kennt.niemand.' . bin2hex(random_bytes(4)) . '@example.test');

        $user->delete();
        $role->delete();

        // Keine Messung auf Mikrosekunden - die Aussage ist, dass der unbekannte
        // Weg nicht mehr um Größenordnungen billiger ist als der bekannte.
        $this->assertGreaterThan(
            $known * 0.25,
            $unknown,
            sprintf('Unbekannte Adresse antwortete %.4fs, bekannte %.4fs.', $unknown, $known)
        );
    }

    private function measure(AuthController $controller, string $email): float
    {
        $request = $this->makeRequest('POST', '/login', [
            'email' => $email,
            'password' => 'Falsches-Passwort-1',
        ]);

        $start = microtime(true);
        $controller->processLogin($request, new SlimResponse());

        return microtime(true) - $start;
    }
}

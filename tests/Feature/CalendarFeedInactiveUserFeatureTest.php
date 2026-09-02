<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Controllers\TaskController;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Services\CalendarSubscriptionService;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Kalender-Abo eines archivierten Mitglieds.
 *
 * Der Abo-Token ist ein Bearer-Token ohne Anmeldung: Wer ihn hat, liest die
 * Terminliste und die Aufgaben des Mitglieds. Wird jemand archiviert, endet
 * damit jeder andere Zugang - die Anmeldung über UserQuery::findByEmail(), die
 * Angemeldet-bleiben-Kennung über AuthController. Der Feed lief bisher als
 * einziger weiter und hätte einem ausgeschiedenen Mitglied auf unbestimmte
 * Zeit den vollständigen Chorkalender geliefert.
 */
final class CalendarFeedInactiveUserFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'email' => 'archiviert-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => 'Archivierte',
            'last_name' => 'Sängerin',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEventFeedStaysOpenWhileTheMemberIsActive(): void
    {
        $token = (new CalendarSubscriptionService())->rotateTokenForUser((int) $this->user->id);

        $this->assertSame(200, $this->exportEvents($token)->getStatusCode());
    }

    public function testEventFeedIsClosedForAnArchivedMember(): void
    {
        $token = (new CalendarSubscriptionService())->rotateTokenForUser((int) $this->user->id);

        $this->user->is_active = 0;
        $this->user->save();

        $this->assertSame(404, $this->exportEvents($token)->getStatusCode());
    }

    public function testTaskFeedStaysOpenWhileTheMemberIsActive(): void
    {
        $token = (new CalendarSubscriptionService())->rotateTokenForUser((int) $this->user->id);

        $this->assertSame(200, $this->exportTasks($token)->getStatusCode());
    }

    public function testTaskFeedIsClosedForAnArchivedMember(): void
    {
        $token = (new CalendarSubscriptionService())->rotateTokenForUser((int) $this->user->id);

        $this->user->is_active = 0;
        $this->user->save();

        $this->assertSame(404, $this->exportTasks($token)->getStatusCode());
    }

    private function exportEvents(string $token): ResponseInterface
    {
        $controller = new EventController(
            $this->createStub(Twig::class),
            new NameFormatterService(),
            new NullLogger()
        );

        return $controller->exportCalendar(
            $this->makeRequest('GET', '/events/export/' . $token . '.ics'),
            $this->makeResponse(),
            ['token' => $token]
        );
    }

    private function exportTasks(string $token): ResponseInterface
    {
        $controller = new TaskController(
            $this->createStub(Twig::class),
            new HtmlSanitizer(),
            new TaskPolicy(),
            new NameFormatterService(),
            new NullLogger()
        );

        return $controller->exportCalendar(
            $this->makeRequest('GET', '/tasks/export/' . $token . '.ics'),
            $this->makeResponse(),
            ['token' => $token]
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserNotificationController;
use App\Middleware\CsrfMiddleware;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\InAppNotificationStore;
use App\Services\Notifications\NotificationBadgeViewService;
use App\Util\Csrf;
use App\Util\NotificationType;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Endpunkte der Glocke - und vor allem ihre Grenze: Niemand sieht oder
 * ändert die Einträge einer anderen Person.
 */
final class UserNotificationControllerFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $anna;
    private User $bernd;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
        $_SESSION = ['user_id' => (int) $this->anna->id];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testBadgeReturnsTheOwnUnreadCountUncached(): void
    {
        $this->entry($this->anna);
        $this->entry($this->anna, readAt: Carbon::now());
        $this->entry($this->bernd);

        $response = $this->controller()->badge($this->makeRequest('GET', '/notifications/badge'), $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(['unread_count' => 1], json_decode((string) $response->getBody(), true));
    }

    public function testRecentListsOwnEntriesWithOpenLinks(): void
    {
        $own = $this->entry($this->anna, title: 'Neuer Kommentar: Saal');
        $this->entry($this->bernd, title: 'fremd');

        $response = $this->controller()->recent(
            $this->makeRequest('GET', '/notifications/recent'),
            $this->makeResponse()
        );
        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(1, $data['unread_count']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('Neuer Kommentar: Saal', $data['items'][0]['title']);
        $this->assertSame('/notifications/' . $own->id . '/open', $data['items'][0]['url']);
        $this->assertSame('tasks', $data['items'][0]['group']);
        $this->assertTrue($data['items'][0]['unread']);
        $this->assertSame('gerade eben', $data['items'][0]['relative_time']);
    }

    public function testTheListPageRendersOnlyOwnEntries(): void
    {
        $this->entry($this->anna, title: 'Eigener Eintrag');
        $this->entry($this->bernd, title: 'Fremder Eintrag');

        $captured = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;

                return $response;
            }
        );

        (new UserNotificationController($twig, new InAppNotificationStore()))->index(
            $this->makeRequest('GET', '/notifications', [], ['unread' => '1']),
            $this->makeResponse()
        );

        $this->assertTrue($captured['only_unread']);
        $this->assertSame(['Eigener Eintrag'], array_column($captured['notifications'], 'title'));
        $this->assertSame(1, $captured['unread_count']);
    }

    public function testOpeningMarksReadAndRedirectsToTheTarget(): void
    {
        $entry = $this->entry($this->anna, link: '/tasks/7');

        $response = $this->controller()->open(
            $this->makeRequest('GET', '/notifications/' . $entry->id . '/open'),
            $this->makeResponse(),
            ['id' => (string) $entry->id]
        );

        $this->assertRedirect($response, '/tasks/7');
        $this->assertNotNull($entry->fresh()->read_at);
    }

    public function testOpeningAForeignEntryIsNotFoundAndChangesNothing(): void
    {
        $foreign = $this->entry($this->bernd);

        try {
            $this->controller()->open(
                $this->makeRequest('GET', '/notifications/' . $foreign->id . '/open'),
                $this->makeResponse(),
                ['id' => (string) $foreign->id]
            );
            $this->fail('Ein fremder Eintrag muss 404 ergeben.');
        } catch (HttpNotFoundException) {
            $this->assertNull($foreign->fresh()->read_at);
        }
    }

    public function testReadAllOnlyTouchesTheOwnEntries(): void
    {
        $own = $this->entry($this->anna);
        $foreign = $this->entry($this->bernd);

        $response = $this->controller()->readAll(
            $this->makeRequest('POST', '/notifications/read-all'),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/notifications');
        $this->assertNotNull($own->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function testReadAllAnswersJsonWhenAskedForIt(): void
    {
        $this->entry($this->anna);

        $response = $this->controller()->readAll(
            $this->makeRequest('POST', '/notifications/read-all', [], [], ['Accept' => 'application/json']),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['unread_count' => 0], json_decode((string) $response->getBody(), true));
    }

    /**
     * „Alle als gelesen“ ändert Daten - ohne Token weist die CSRF-Middleware
     * die Anfrage ab, bevor der Controller sie sieht.
     */
    public function testReadAllWithoutCsrfTokenIsRejected(): void
    {
        // Vorher starten: Das session_start() der Middleware ersetzte sonst
        // $_SESSION durch den leeren gespeicherten Stand.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ['user_id' => (int) $this->anna->id, Csrf::SESSION_KEY => bin2hex(random_bytes(32))];
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $blocked = (new CsrfMiddleware())->process($this->makeRequest('POST', '/notifications/read-all'), $handler);

        $this->assertSame(403, $blocked->getStatusCode());
    }

    public function testTheBadgeViewCountsOnlyForASignedInPerson(): void
    {
        $this->entry($this->anna);
        $this->entry($this->bernd);
        $badge = new NotificationBadgeViewService(new InAppNotificationStore(), new NullLogger());

        $this->assertSame(1, $badge->forCurrentUser());

        $_SESSION = [];
        $this->assertNull($badge->forCurrentUser());
    }

    public function testTheRelativeTimeReadsNaturally(): void
    {
        $now = Carbon::parse('2026-10-05 12:00:00');

        $this->assertSame('gerade eben', UserNotificationController::relativeTime($now->copy()->subSeconds(30), $now));
        $this->assertSame('vor 5 Min.', UserNotificationController::relativeTime($now->copy()->subMinutes(5), $now));
        $this->assertSame('vor 3 Std.', UserNotificationController::relativeTime($now->copy()->subHours(3), $now));
        $this->assertSame('gestern', UserNotificationController::relativeTime($now->copy()->subDay(), $now));
        $this->assertSame('vor 4 Tagen', UserNotificationController::relativeTime($now->copy()->subDays(4), $now));
        $this->assertSame('20.08.2026', UserNotificationController::relativeTime($now->copy()->subDays(46), $now));
    }

    private function controller(): UserNotificationController
    {
        return new UserNotificationController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new InAppNotificationStore()
        );
    }

    private function entry(
        User $user,
        string $title = 'Eintrag',
        string $link = '/tasks/1',
        ?Carbon $readAt = null
    ): UserNotification {
        return UserNotification::create([
            'user_id' => $user->id,
            'notification_type' => NotificationType::TASK_COMMENT,
            'title' => $title,
            'link' => $link,
            'read_at' => $readAt,
            'created_at' => Carbon::now(),
        ]);
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'bellctl.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }
}

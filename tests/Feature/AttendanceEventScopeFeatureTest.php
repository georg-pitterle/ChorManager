<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Middleware\RoleMiddleware;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\EventRegistration;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Policies\TaskPolicy;
use App\Services\AttendanceScopeService;
use App\Services\EventAudienceService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Anwesenheit und Anmeldung zeigen personenbezogene Daten: sichtbar sind sie nur fuer
 * Mitglieder der Zielgruppe eines Termins und fuer die Verwalter der betroffenen Personen.
 */
final class AttendanceEventScopeFeatureTest extends TestCase
{
    use EventScopeFixtures;
    use TestHttpHelpers;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();
        $_SESSION = [];
    }

    private function controller(): RegistrationController
    {
        return new RegistrationController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new AttendanceScopeService(),
            new NullLogger(),
            new NameFormatterService()
        );
    }

    private function createUser(string $prefix = 'scope'): User
    {
        return User::create([
            'first_name' => 'Scope',
            'last_name' => 'Testperson',
            'email' => $prefix . '-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }

    private function createEventForUser(User $audienceUser): Event
    {
        $event = Event::create([
            'title' => 'Zielgruppen-Termin ' . bin2hex(random_bytes(4)),
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
            'attendance_required' => true,
        ]);

        (new EventAudienceService())->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $audienceUser->id],
        ]);

        return $event->fresh();
    }

    public function testMemberOutsideAudienceCannotOpenRegistrationDetail(): void
    {
        $audienceUser = $this->createUser('inside');
        $outsider = $this->createUser('outside');
        $event = $this->createEventForUser($audienceUser);

        $_SESSION['user_id'] = (int) $outsider->id;

        $response = $this->controller()->detail(
            $this->makeRequest('GET', '/registrations/' . $event->id),
            $this->makeResponse(),
            ['event_id' => (string) $event->id]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMemberInsideAudienceMayAccessEvent(): void
    {
        $audienceUser = $this->createUser('inside');
        $event = $this->createEventForUser($audienceUser);

        $_SESSION['user_id'] = (int) $audienceUser->id;

        $this->assertTrue((new AttendanceScopeService())->canAccessEvent($event));
    }

    public function testVoiceGroupManagerSeesEventOfManagedMemberOnly(): void
    {
        $voiceGroup = VoiceGroup::query()->orderBy('id')->firstOrFail();

        $managedMember = $this->createUser('managed');
        $managedMember->voiceGroups()->attach($voiceGroup->id, ['sub_voice_id' => null]);

        $foreignMember = $this->createUser('foreign');

        $managerSession = [
            'user_id' => (int) $this->createUser('manager')->id,
            'can_manage_attendance' => true,
            'can_manage_attendance_all' => false,
            'voice_group_ids' => [(int) $voiceGroup->id],
        ];

        $_SESSION = $managerSession;
        $this->assertTrue(
            (new AttendanceScopeService())->canAccessEvent($this->createEventForUser($managedMember)),
            'Termine betreuter Mitglieder muessen sichtbar sein.'
        );

        $_SESSION = $managerSession;
        $this->assertFalse(
            (new AttendanceScopeService())->canAccessEvent($this->createEventForUser($foreignMember)),
            'Termine ohne betreutes Mitglied duerfen nicht sichtbar sein.'
        );
    }

    public function testAttendanceAllSeesEveryEvent(): void
    {
        $event = $this->createEventForUser($this->createUser('inside'));

        $_SESSION['user_id'] = (int) $this->createUser('allmanager')->id;
        $_SESSION['can_manage_attendance_all'] = true;

        $this->assertTrue((new AttendanceScopeService())->canAccessEvent($event));
    }

    public function testProxyRegistrationIsRejectedForMembersOutsideTheAudience(): void
    {
        $audienceUser = $this->createUser('inside');
        $outsider = $this->createUser('outside');
        $event = $this->createEventForUser($audienceUser);

        $_SESSION['user_id'] = (int) $this->createUser('allmanager')->id;
        $_SESSION['can_manage_attendance_all'] = true;

        $request = $this->makeRequest('POST', '/registrations/' . $event->id . '/proxy', [
            'registration' => [(string) $outsider->id => 'yes'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $event->id)->count());
    }

    public function testOwnVoiceGroupAttendanceRightAllowsProxyEntries(): void
    {
        $_SESSION['can_manage_attendance'] = true;
        $_SESSION['can_manage_attendance_all'] = false;
        $_SESSION['can_manage_own_voice_group'] = false;

        $this->assertTrue((new AttendanceScopeService())->canManageOthers());
    }

    /**
     * Die Anwesenheitsliste bleibt an den beiden Anwesenheitsrechten haengen -
     * can_manage_own_voice_group deckt Mitgliederpflege und Vertretungs-Anmeldungen ab.
     */
    public function testOwnVoiceGroupRightAloneDoesNotOpenAttendanceList(): void
    {
        $_SESSION = [
            'user_id' => 7,
            'can_manage_attendance' => false,
            'can_manage_attendance_all' => false,
            'can_manage_own_voice_group' => true,
        ];

        $middleware = new RoleMiddleware(requiresAttendanceManagement: true);
        $status = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/attendance'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        )->getStatusCode();

        $this->assertSame(403, $status);
    }

    public function testVoiceGroupRepsFlagNoLongerSkipsOtherGates(): void
    {
        $_SESSION = [
            'user_id' => 7,
            'can_manage_own_voice_group' => true,
            'can_manage_users' => false,
        ];

        $middleware = new RoleMiddleware(requiresUserManagement: true, allowVoiceGroupReps: true);
        $status = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/users'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        )->getStatusCode();

        $this->assertSame(403, $status);
    }

    public function testUserManagementDoesNotGrantTaskAccess(): void
    {
        $_SESSION = [
            'user_id' => 7,
            'can_manage_users' => true,
            'can_manage_tasks' => false,
        ];

        $this->assertFalse((new TaskPolicy())->canManageTasks(1));
    }
}

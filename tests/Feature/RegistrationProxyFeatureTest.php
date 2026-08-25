<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class RegistrationProxyFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Event $event;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $this->event = Event::create([
            'title' => 'Probe Vertretungstest',
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
    }

    private function createUser(bool $active = true): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "proxy_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function createVoiceGroup(): VoiceGroup
    {
        return VoiceGroup::create(['name' => 'Testgruppe ' . bin2hex(random_bytes(4))]);
    }

    private function attachToVoiceGroup(User $user, VoiceGroup $voiceGroup): void
    {
        $user->voiceGroups()->attach($voiceGroup->id);
    }

    private function controller(): RegistrationController
    {
        return new RegistrationController(
            Twig::create(dirname(__DIR__) . '/../templates'),
            new AttendanceScopeService(),
            new NullLogger(),
            new \App\Services\NameFormatterService()
        );
    }

    public function testAdminCanRegisterForOthersAndUpdatedByIsSet(): void
    {
        $admin = $this->createUser();
        $member = $this->createUser();

        $_SESSION['user_id'] = (int) $admin->id;
        $_SESSION['can_manage_attendance_all'] = true;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $member->id => 'maybe'],
            'note' => [(string) $member->id => 'Kommt eventuell später'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $row = EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $member->id)->firstOrFail();
        $this->assertSame('maybe', $row->status);
        $this->assertSame('Kommt eventuell später', $row->note);
        $this->assertSame((int) $admin->id, (int) $row->updated_by);
    }

    public function testForeignVoiceGroupRejectedWith403(): void
    {
        $ownGroup = $this->createVoiceGroup();
        $foreignGroup = $this->createVoiceGroup();

        $rep = $this->createUser();
        $this->attachToVoiceGroup($rep, $ownGroup);
        $peer = $this->createUser();
        $this->attachToVoiceGroup($peer, $ownGroup);
        $outsider = $this->createUser();
        $this->attachToVoiceGroup($outsider, $foreignGroup);

        $repGroupIds = [(int) $ownGroup->id];

        $_SESSION['user_id'] = (int) $rep->id;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 50;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['voice_group_ids'] = $repGroupIds;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $outsider->id => 'yes'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testPlainMemberCannotProxyAtAll(): void
    {
        $member = $this->createUser();
        $other = $this->createUser();

        $_SESSION['user_id'] = (int) $member->id;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [];

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $other->id => 'yes'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testClosedDeadlineRejectedWith403(): void
    {
        $admin = $this->createUser();
        $member = $this->createUser();

        $this->event->update(['registration_deadline' => Carbon::now()->subHour()]);

        $_SESSION['user_id'] = (int) $admin->id;
        $_SESSION['can_manage_users'] = true;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $member->id => 'yes'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testPastEventRejectedWith403(): void
    {
        $admin = $this->createUser();
        $member = $this->createUser();

        $this->event->update([
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->subDay()->addHours(2),
        ]);

        $_SESSION['user_id'] = (int) $admin->id;
        $_SESSION['can_manage_users'] = true;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $member->id => 'yes'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPartialUnauthorizedBatchRejectsEntireRequestAtomically(): void
    {
        $ownGroup = $this->createVoiceGroup();
        $foreignGroup = $this->createVoiceGroup();

        $rep = $this->createUser();
        $this->attachToVoiceGroup($rep, $ownGroup);
        $allowedMember = $this->createUser();
        $this->attachToVoiceGroup($allowedMember, $ownGroup);
        $outsider = $this->createUser();
        $this->attachToVoiceGroup($outsider, $foreignGroup);

        $repGroupIds = [(int) $ownGroup->id];

        $_SESSION['user_id'] = (int) $rep->id;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 50;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['voice_group_ids'] = $repGroupIds;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [
                (string) $allowedMember->id => 'yes',
                (string) $outsider->id => 'no',
            ],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testUnauthorizedTargetWithEmptyStatusStillRejectsEntireBatch(): void
    {
        $ownGroup = $this->createVoiceGroup();
        $foreignGroup = $this->createVoiceGroup();

        $rep = $this->createUser();
        $this->attachToVoiceGroup($rep, $ownGroup);
        $allowedMember = $this->createUser();
        $this->attachToVoiceGroup($allowedMember, $ownGroup);
        $outsider = $this->createUser();
        $this->attachToVoiceGroup($outsider, $foreignGroup);

        $repGroupIds = [(int) $ownGroup->id];

        $_SESSION['user_id'] = (int) $rep->id;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 50;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['voice_group_ids'] = $repGroupIds;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [
                (string) $allowedMember->id => 'yes',
                (string) $outsider->id => '',
            ],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }
}

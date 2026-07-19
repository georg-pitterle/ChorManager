<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\MailQueue;
use App\Models\Project;
use App\Models\User;
use App\Services\MailQueueService;
use App\Services\RegistrationReminderService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class RegistrationReminderServiceFeatureTest extends TestCase
{
    private Event $event;
    private ?Project $extraProject = null;
    private array $extraUserIds = [];

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();

        AppSetting::updateOrCreate(
            ['setting_key' => 'registration_reminder_days_before'],
            ['setting_value' => '3', 'binary_content' => '', 'mime_type' => 'text/plain']
        );

        $this->event = Event::create([
            'title' => 'Probe Erinnerungstest',
            'starts_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(2)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        MailQueue::where('mail_type', 'registration_reminder')->delete();
        EventRegistration::where('event_id', $this->event->id)->delete();
        $this->event->delete();
        AppSetting::where('setting_key', 'registration_reminder_days_before')->delete();

        if ($this->extraProject !== null) {
            $this->extraProject->users()->detach();
            $this->extraProject->delete();
        }
        if ($this->extraUserIds !== []) {
            User::whereIn('id', $this->extraUserIds)->delete();
        }
    }

    private function service(): RegistrationReminderService
    {
        return new RegistrationReminderService(
            new MailQueueService(),
            Twig::create(dirname(__DIR__) . '/../templates'),
            new NullLogger()
        );
    }

    public function testRemindsOnlyUnregisteredUsersAndMarksEvent(): void
    {
        $registered = User::where('is_active', 1)->whereNotNull('email')->firstOrFail();
        EventRegistration::create([
            'event_id' => $this->event->id,
            'user_id' => $registered->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $registered->id,
        ]);

        $count = $this->service()->processDue('https://chor.example');

        $this->assertGreaterThan(0, $count);

        // Scoped to this test's event so pre-existing dev-seed registration_reminder
        // rows for the same recipient (from unrelated events) cannot mask a failure.
        $reminderMailsForEvent = MailQueue::where('mail_type', 'registration_reminder')
            ->get()
            ->filter(fn (MailQueue $mail) => ($mail->payload_json['event_id'] ?? null) === $this->event->id);

        $this->assertSame(0, $reminderMailsForEvent
            ->filter(fn (MailQueue $mail) => $mail->recipient_email === $registered->email)
            ->count());

        $mail = $reminderMailsForEvent->first();
        $this->assertNotNull($mail);
        $this->assertStringContainsString(
            'https://chor.example/registrations/' . $this->event->id,
            $mail->body_html
        );

        $this->event->refresh();
        $this->assertNotNull($this->event->registration_reminder_sent_at);
    }

    public function testProjectBoundEventOnlyRemindsProjectMembers(): void
    {
        $this->extraProject = Project::create([
            'name' => 'Erinnerungstest-Projekt',
            'description' => 'Fixture project for RegistrationReminderService test',
        ]);

        $member = User::create([
            'first_name' => 'Reminder',
            'last_name' => 'Projektmitglied',
            'email' => 'reminder-member@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => true,
        ]);
        $nonMember = User::create([
            'first_name' => 'Reminder',
            'last_name' => 'Nichtmitglied',
            'email' => 'reminder-nonmember@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => true,
        ]);
        $this->extraUserIds = [$member->id, $nonMember->id];

        $this->extraProject->users()->attach($member->id);
        // $nonMember is deliberately NOT attached to the project: only
        // project members may be reminded for a project-bound event.

        $this->event->update(['project_id' => $this->extraProject->id]);

        $this->service()->processDue('https://chor.example');

        $reminderMailsForEvent = MailQueue::where('mail_type', 'registration_reminder')
            ->get()
            ->filter(fn (MailQueue $mail) => ($mail->payload_json['event_id'] ?? null) === $this->event->id);

        $this->assertSame(
            1,
            $reminderMailsForEvent->filter(fn (MailQueue $mail) => $mail->recipient_email === $member->email)->count(),
            'Project member should receive the registration reminder.'
        );
        $this->assertSame(
            0,
            $reminderMailsForEvent->filter(fn (MailQueue $mail) => $mail->recipient_email === $nonMember->email)->count(),
            'Non-member must not receive the registration reminder for a project-bound event.'
        );
    }

    public function testSecondRunSendsNothing(): void
    {
        $this->service()->processDue('https://chor.example');
        $firstCount = MailQueue::where('mail_type', 'registration_reminder')->count();

        $second = $this->service()->processDue('https://chor.example');

        $this->assertSame(0, $second);
        $this->assertSame($firstCount, MailQueue::where('mail_type', 'registration_reminder')->count());
    }

    public function testEventOutsideWindowIsSkipped(): void
    {
        $this->event->update([
            'starts_at' => Carbon::now()->addDays(30),
            'ends_at' => Carbon::now()->addDays(30)->addHours(2),
        ]);

        $count = $this->service()->processDue('https://chor.example');

        $this->assertSame(0, $count);
        $this->event->refresh();
        $this->assertNull($this->event->registration_reminder_sent_at);
    }

    public function testDisabledSettingSendsNothing(): void
    {
        AppSetting::where('setting_key', 'registration_reminder_days_before')
            ->update(['setting_value' => '0']);

        $this->assertSame(0, $this->service()->processDue('https://chor.example'));
    }

    public function testTriggerWiringExists(): void
    {
        $middlewarePipeline = file_get_contents(dirname(__DIR__) . '/../src/Middleware.php');
        $this->assertIsString($middlewarePipeline);
        $this->assertStringContainsString('RegistrationReminderMiddleware', $middlewarePipeline);

        $this->assertFileExists(dirname(__DIR__) . '/../bin/send_registration_reminders.php');
        $this->assertFileExists(dirname(__DIR__) . '/../src/Commands/SendRegistrationRemindersCommand.php');

        $appSettings = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AppSettingController.php');
        $this->assertIsString($appSettings);
        $this->assertStringContainsString('registration_reminder_days_before', $appSettings);
    }
}

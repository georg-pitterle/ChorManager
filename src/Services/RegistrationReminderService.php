<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

class RegistrationReminderService
{
    public function __construct(
        private readonly MailQueueService $mailQueueService,
        private readonly Twig $view,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Enqueue reminder mails for events whose registration deadline is
     * within the configured window. Returns the number of enqueued mails.
     */
    public function processDue(string $baseUrl): int
    {
        $daysBefore = (int) (AppSetting::query()
            ->where('setting_key', 'registration_reminder_days_before')
            ->value('setting_value') ?? 0);

        if ($daysBefore <= 0) {
            return 0;
        }

        $now = Carbon::now();
        $windowEnd = $now->copy()->addDays($daysBefore);

        $events = Event::where('registration_enabled', true)
            ->whereNull('registration_reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->get()
            ->filter(function (Event $event) use ($now, $windowEnd) {
                $deadline = $event->registrationDeadlineAt();
                return $deadline->greaterThan($now) && $deadline->lessThanOrEqualTo($windowEnd);
            });

        $appName = (string) (AppSetting::query()
            ->where('setting_key', 'app_name')
            ->value('setting_value') ?? 'Chor-Manager');

        $enqueued = 0;

        foreach ($events as $event) {
            $recipients = $this->unregisteredEligibleUsers($event);

            foreach ($recipients as $user) {
                try {
                    $link = rtrim($baseUrl, '/') . '/registrations/' . $event->id;
                    $bodyHtml = $this->view->fetch('emails/registration_reminder.twig', [
                        'user' => $user,
                        'event' => $event,
                        'deadline' => $event->registrationDeadlineAt()->format('d.m.Y H:i'),
                        'link' => $link,
                        'app_name' => $appName,
                    ]);

                    $this->mailQueueService->enqueueRegistrationReminderMail(
                        (string) $user->email,
                        'Erinnerung: Anmeldung zu "' . $event->title . '"',
                        $bodyHtml,
                        (int) $user->id,
                        (int) $event->id
                    );
                    $enqueued++;
                } catch (\Exception $e) {
                    $this->logger->error('Enqueueing registration reminder failed.', [
                        'event' => 'registration_reminder.enqueue_failed',
                        'event_id' => (int) $event->id,
                        'user_id' => (int) $user->id,
                        'exception' => $e,
                    ]);
                }
            }

            $event->update(['registration_reminder_sent_at' => Carbon::now()]);

            $this->logger->info('Registration reminder round completed.', [
                'event' => 'registration_reminder.sent',
                'event_id' => (int) $event->id,
                'recipient_count' => count($recipients),
            ]);
        }

        return $enqueued;
    }

    /**
     * @return array<int, User>
     */
    private function unregisteredEligibleUsers(Event $event): array
    {
        $query = $event->eligibleUsersQuery()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereDoesntHave('eventRegistrations', function ($q) use ($event) {
                $q->where('event_id', (int) $event->id);
            });

        return $query->get()->all();
    }
}

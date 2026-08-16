<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Event;
use App\Models\User;
use App\Util\MailBranding;
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

        $branding = MailBranding::resolve();

        $enqueued = 0;

        foreach ($events as $event) {
            $claimedAt = Carbon::now();

            // Bedingtes Update als Lock: laeuft ein zweiter Worker parallel, gewinnt genau einer
            // das Event. Ohne diesen Claim bekaeme jeder Empfaenger die Erinnerung mehrfach.
            $claimed = Event::query()
                ->whereKey((int) $event->id)
                ->whereNull('registration_reminder_sent_at')
                ->update(['registration_reminder_sent_at' => $claimedAt]);

            if ($claimed === 0) {
                continue;
            }

            $event->registration_reminder_sent_at = $claimedAt;
            $event->syncOriginal();

            $recipients = $this->unregisteredEligibleUsers($event);
            $failed = 0;
            $sent = 0;

            foreach ($recipients as $user) {
                try {
                    $link = rtrim($baseUrl, '/') . '/registrations/' . $event->id;
                    $bodyHtml = $this->view->fetch('emails/registration_reminder.twig', array_merge($branding, [
                        'user' => $user,
                        'event' => $event,
                        'deadline' => $event->registrationDeadlineAt()->format('d.m.Y H:i'),
                        'link' => $link,
                    ]));

                    $this->mailQueueService->enqueueRegistrationReminderMail(
                        (string) $user->email,
                        'Erinnerung: Anmeldung zu "' . $event->title . '"',
                        $bodyHtml,
                        (int) $user->id,
                        (int) $event->id
                    );
                    $enqueued++;
                    $sent++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->logger->error('Enqueueing registration reminder failed.', [
                        'event' => 'registration_reminder.enqueue_failed',
                        'event_id' => (int) $event->id,
                        'user_id' => (int) $user->id,
                        'exception' => $e,
                    ]);
                }
            }

            // Ging keine einzige Mail raus, wird der Claim wieder freigegeben, damit ein spaeterer
            // Lauf es erneut versucht. Bei Teilerfolg bleibt die Markierung stehen: sonst bekaemen
            // die bereits erreichten Empfaenger die Erinnerung im naechsten Lauf ein zweites Mal.
            if ($sent === 0 && $failed > 0) {
                Event::query()
                    ->whereKey((int) $event->id)
                    ->where('registration_reminder_sent_at', $claimedAt)
                    ->update(['registration_reminder_sent_at' => null]);

                $event->registration_reminder_sent_at = null;
                $event->syncOriginal();

                $this->logger->warning('Registration reminder round released for retry.', [
                    'event' => 'registration_reminder.retry_scheduled',
                    'event_id' => (int) $event->id,
                    'recipient_count' => count($recipients),
                    'failed_count' => $failed,
                ]);

                continue;
            }

            $this->logger->info('Registration reminder round completed.', [
                'event' => 'registration_reminder.sent',
                'event_id' => (int) $event->id,
                'recipient_count' => count($recipients),
                'failed_count' => $failed,
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

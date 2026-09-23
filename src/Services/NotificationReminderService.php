<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\NotificationDispatchLog;
use App\Models\SponsoringContact;
use App\Models\Task;
use App\Util\NotificationType;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Die zwei Benachrichtigungen, die nicht von einer Handlung ausgelöst werden,
 * sondern vom Kalender: eine Aufgabe wird fällig, eine Wiedervorlage steht an.
 *
 * Gegen doppelte Mails steht der eindeutige Index auf `notification_dispatch_log`
 * (Migration 20260830141000). Die Anmelde-Erinnerung löst dasselbe Problem mit
 * einem bedingten Update auf einer Spalte des Termins; hier hängt die Sperre am
 * Paar aus Objekt und Empfänger, weil zwei Zugewiesene derselben Aufgabe
 * unabhängig voneinander erinnert werden.
 *
 * Der Merkzettel wird **vor** dem Einreihen geschrieben. Andersherum könnten
 * zwei gleichzeitige Läufe beide die Mail erzeugen und erst danach merken, dass
 * einer zu viel war - die zweite Mail wäre dann schon unterwegs.
 */
class NotificationReminderService
{
    private const TASK_COMPLETED_STATUS = 'Abgeschlossen';

    /**
     * Wie lange ein Merkzettel aufgehoben wird.
     *
     * Zwölf Monate sind um ein Vielfaches mehr als das größte Fälligkeitsfenster,
     * das sich einstellen lässt. Ein älterer Eintrag kann keine zweite Mail mehr
     * verhindern - er sagt nur noch, dass vor über einem Jahr einmal eine raus
     * ist, und dafür gibt es das Anwendungslog.
     */
    private const DISPATCH_LOG_RETENTION_MONTHS = 12;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Reiht alle fälligen Erinnerungen ein und gibt zurück, wie viele es waren.
     */
    public function processDue(string $baseUrl): int
    {
        $enqueued = $this->processDueTasks($baseUrl) + $this->processDueFollowUps($baseUrl);

        // Nach dem Verschicken, nicht davor: Ein Aufräumlauf, der scheitert,
        // darf die Erinnerungen dieses Laufs nicht aufhalten.
        $this->pruneExpiredDispatchLog();

        return $enqueued;
    }

    /**
     * Entfernt Merkzettel, die älter sind als die Aufbewahrungsfrist, und gibt
     * zurück, wie viele es waren.
     *
     * Die Tabelle wurde bisher nie aufgeräumt und wuchs mit jeder verschickten
     * Erinnerung weiter. Bei Chorgröße dauert es Jahre, bis das auffällt -
     * irgendwann fällt es auf.
     */
    public function pruneExpiredDispatchLog(): int
    {
        $cutoff = Carbon::now()->subMonths(self::DISPATCH_LOG_RETENTION_MONTHS);

        try {
            $removed = NotificationDispatchLog::query()
                ->where('created_at', '<', $cutoff->format('Y-m-d H:i:s'))
                ->delete();
        } catch (QueryException $e) {
            // Das Aufräumen ist Pflege, kein Zweck des Laufs. Scheitert es,
            // bleibt der Merkzettel eben stehen; die Erinnerungen sind raus.
            $this->logger->error('Notification dispatch log prune failed.', [
                'event' => 'notification_reminder.prune_failed',
                'exception' => $e,
            ]);

            return 0;
        }

        if ($removed > 0) {
            $this->logger->debug('Notification dispatch log pruned.', [
                'event' => 'notification_reminder.pruned',
                'removed' => $removed,
                'retention_months' => self::DISPATCH_LOG_RETENTION_MONTHS,
            ]);
        }

        return $removed;
    }

    private function processDueTasks(string $baseUrl): int
    {
        $daysBefore = $this->daysBefore('notification_task_due_days_before', 3);
        if ($daysBefore === 0 || !$this->notificationService->isAvailable(NotificationType::TASK_DUE_SOON)) {
            return 0;
        }

        $today = Carbon::today();
        $windowEnd = $today->copy()->addDays($daysBefore);

        $tasks = Task::query()
            ->whereNotNull('end_date')
            ->where('status', '!=', self::TASK_COMPLETED_STATUS)
            // Bereits überfällige Aufgaben bleiben außen vor: Die Erinnerung
            // soll vorher kommen. Wer den Termin verpasst hat, bekäme sonst
            // täglich dieselbe Mahnung, bis er die Aufgabe schließt.
            ->whereBetween('end_date', [$today->format('Y-m-d'), $windowEnd->format('Y-m-d')])
            ->with(['assignees', 'project'])
            ->get();

        $enqueued = 0;

        foreach ($tasks as $task) {
            $dueDate = Carbon::parse((string) $task->end_date)->format('Y-m-d');

            foreach ($task->assignees as $assignee) {
                if (!$this->claim(NotificationType::TASK_DUE_SOON, 'task', (int) $task->id, (int) $assignee->id, $dueDate)) {
                    continue;
                }

                $enqueued += $this->notificationService->notify(
                    NotificationType::TASK_DUE_SOON,
                    [$assignee],
                    'Bald fällig: ' . $task->name,
                    'emails/notification_task_due_soon.twig',
                    [
                        'task' => $task,
                        'link' => rtrim($baseUrl, '/') . '/tasks/' . $task->id,
                        'profile_url' => rtrim($baseUrl, '/') . '/profile',
                    ]
                );
            }
        }

        return $enqueued;
    }

    private function processDueFollowUps(string $baseUrl): int
    {
        $daysBefore = $this->daysBefore('notification_sponsoring_follow_up_days_before', 1);
        if ($daysBefore === 0 || !$this->notificationService->isAvailable(NotificationType::SPONSORING_FOLLOW_UP_DUE)) {
            return 0;
        }

        $today = Carbon::today();
        $windowEnd = $today->copy()->addDays($daysBefore);

        $contacts = SponsoringContact::query()
            ->where('follow_up_done', 0)
            ->whereNotNull('follow_up_date')
            ->whereNotNull('user_id')
            ->whereBetween('follow_up_date', [$today->format('Y-m-d'), $windowEnd->format('Y-m-d')])
            ->with(['sponsor', 'user'])
            ->get();

        $enqueued = 0;

        foreach ($contacts as $contact) {
            $owner = $contact->user;
            if ($owner === null) {
                continue;
            }

            $followUpDate = Carbon::parse((string) $contact->follow_up_date)->format('Y-m-d');
            $claimed = $this->claim(
                NotificationType::SPONSORING_FOLLOW_UP_DUE,
                'sponsoring_contact',
                (int) $contact->id,
                (int) $owner->id,
                $followUpDate
            );

            if (!$claimed) {
                continue;
            }

            $enqueued += $this->notificationService->notify(
                NotificationType::SPONSORING_FOLLOW_UP_DUE,
                [$owner],
                'Wiedervorlage: ' . ($contact->sponsor->name ?? 'Sponsor'),
                'emails/notification_sponsoring_follow_up_due.twig',
                [
                    'contact' => $contact,
                    'link' => rtrim($baseUrl, '/') . '/sponsoring/sponsors/' . $contact->sponsor_id,
                    'profile_url' => rtrim($baseUrl, '/') . '/profile',
                ]
            );
        }

        return $enqueued;
    }

    /**
     * Trägt den Merkzettel ein. `false` heißt: Diese Erinnerung ist schon raus.
     *
     * Der eindeutige Index entscheidet, nicht eine vorherige Abfrage - zwischen
     * "gibt es noch nicht" und "jetzt eintragen" passt sonst ein zweiter Lauf.
     */
    private function claim(string $type, string $entityType, int $entityId, int $userId, string $dispatchKey): bool
    {
        try {
            NotificationDispatchLog::create([
                'notification_type' => $type,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'user_id' => $userId,
                'dispatch_key' => $dispatchKey,
                'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (QueryException $e) {
            // 23000 ist die Verletzung des eindeutigen Index - der Normalfall
            // beim zweiten Lauf und kein Grund für einen Fehlereintrag.
            if ((string) $e->getCode() === '23000') {
                return false;
            }

            $this->logger->error('Notification dispatch claim failed.', [
                'event' => 'notification_reminder.claim_failed',
                'notification_type' => $type,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'user_id' => $userId,
                'exception' => $e,
            ]);

            return false;
        }
    }

    private function daysBefore(string $settingKey, int $fallback): int
    {
        $value = AppSetting::query()
            ->where('setting_key', $settingKey)
            ->value('setting_value');

        if ($value === null || $value === '') {
            return $fallback;
        }

        return max(0, (int) $value);
    }
}

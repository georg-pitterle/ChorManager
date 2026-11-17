<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\Notifications\InAppMessage;
use App\Util\NotificationType;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Meldet der Zielgruppe eines oder mehrerer Termine, was mit ihnen geschehen ist.
 *
 * Die vier Methoden standen im EventController. Mit dem eigenen
 * Serien-Controller (Antwort auf Review-Lauf 77, Frage 2b) brauchen sie einen
 * zweiten Aufrufer; abgeschrieben wären sie zwei Wege, auf denen dieselbe Mail
 * entsteht.
 *
 * Nichts hier kennt die Sitzung oder die Anfrage: Basis-Adresse und handelnde
 * Person kommen als Parameter. Ohne `NotificationService` - etwa im
 * Minimal-Aufbau der Tests - geschieht schlicht nichts.
 */
class EventAudienceNotifier
{
    public function __construct(
        private readonly EventAudienceService $audience,
        private readonly UserQuery $userQuery,
        private readonly ?NotificationService $notifications = null
    ) {
    }

    /**
     * Die Mitglieder, die zu mindestens einem dieser Termine gehören.
     *
     * Die Auflösung steht hier und nicht an zwei Stellen: Die Absage braucht sie
     * vor dem Löschen, alles andere danach - das Einsammeln der Kennungen und die
     * Abfrage dahinter sind aber dieselben.
     *
     * @param list<Event> $events
     * @return Collection<int, User>
     */
    public function recipientsFor(array $events): Collection
    {
        if ($events === []) {
            return new Collection();
        }

        $idsByEvent = $this->audience->eligibleUserIdsForEvents($events);

        $userIds = [];
        foreach ($idsByEvent as $ids) {
            foreach ($ids as $id) {
                $userIds[(int) $id] = true;
            }
        }

        if ($userIds === []) {
            return new Collection();
        }

        return $this->userQuery->getUsersByIds(array_keys($userIds));
    }

    /**
     * Wann - für die Zeile in der Glocke. Eine Serie nennt Anzahl und Beginn,
     * sonst stünde dort nur der erste von vierzig Terminen.
     *
     * @param list<Event> $events
     */
    public function describeWhen(array $events): string
    {
        $first = $events[0] ?? null;
        if ($first === null || $first->starts_at === null) {
            return '';
        }

        $startsAt = Carbon::parse($first->starts_at);
        if (count($events) > 1) {
            return count($events) . ' Termine ab ' . $startsAt->format('d.m.Y');
        }

        return $startsAt->format('d.m.Y, H:i') . ' Uhr';
    }

    /**
     * Meldet der Zielgruppe einen oder mehrere Termine.
     *
     * Die Termine kommen als Liste, auch wenn es nur einer ist: Eine Serie
     * ergibt so **eine** Mail statt vierzig - wer ein Halbjahr Montagsproben
     * anlegt, füllt sonst jedem das Postfach.
     *
     * @param list<Event> $events
     * @param array<string, mixed> $extraContext
     */
    public function notifyAudience(
        string $type,
        array $events,
        string $subject,
        string $template,
        string $inAppBody,
        string $baseUrl,
        ?int $actorId,
        array $extraContext = [],
        ?int $inAppCommentId = null
    ): void {
        if ($this->notifications === null || $events === []) {
            return;
        }

        $recipients = $this->recipientsFor($events);
        if ($recipients->isEmpty()) {
            return;
        }

        $first = $events[0];

        $this->notifications->notify(
            $type,
            $recipients,
            $subject,
            $template,
            new InAppMessage(
                $subject,
                $inAppBody,
                '/events/' . $first->id,
                'event',
                (int) $first->id,
                $inAppCommentId
            ),
            array_merge([
                'events' => $events,
                'event' => $first,
                'link' => $baseUrl . '/events/' . $first->id,
                'profile_url' => $baseUrl . '/profile',
            ], $extraContext),
            $actorId
        );
    }

    /**
     * Versand an eine bereits aufgelöste Empfängerliste - für die Absage, deren
     * Zielgruppe zum Zeitpunkt des Versands nicht mehr ermittelbar ist.
     *
     * @param Collection<int, User> $recipients
     * @param list<Event> $events
     * @param array<string, mixed> $extraContext
     */
    public function notifyResolvedAudience(
        string $type,
        Collection $recipients,
        array $events,
        string $subject,
        string $template,
        string $inAppBody,
        string $baseUrl,
        ?int $actorId,
        array $extraContext = []
    ): void {
        if ($this->notifications === null || $recipients->isEmpty() || $events === []) {
            return;
        }

        $this->notifications->notify(
            $type,
            $recipients,
            $subject,
            $template,
            // Der Termin ist gelöscht - der Eintrag führt in die Übersicht.
            new InAppMessage($subject, $inAppBody, '/events'),
            array_merge([
                'events' => $events,
                'event' => $events[0],
                'link' => $baseUrl . '/events',
                'profile_url' => $baseUrl . '/profile',
            ], $extraContext),
            $actorId
        );
    }

    /**
     * Die Absage einer Reihe von Terminen, deren Empfänger vor dem Löschen
     * aufgelöst wurden. Fasst zusammen, was Einzel- und Serienlöschung gleich
     * tun.
     *
     * @param Collection<int, User> $recipients
     * @param list<Event> $cancelled Kopien der gelöschten Termine
     */
    public function notifyCancellation(
        Collection $recipients,
        array $cancelled,
        string $title,
        string $baseUrl,
        ?int $actorId
    ): void {
        $this->notifyResolvedAudience(
            NotificationType::EVENT_CANCELLED,
            $recipients,
            $cancelled,
            'Abgesagt: ' . $title,
            'emails/notification_event_cancelled.twig',
            $this->describeWhen($cancelled),
            $baseUrl,
            $actorId
        );
    }
}

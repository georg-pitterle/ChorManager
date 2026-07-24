<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use Carbon\Carbon;

/**
 * Builds the current user's pending-registration summary for the dashboard:
 * how many open registration events (within the user's audience scope) still
 * await a response, plus the yes/no tally.
 */
class PendingRegistrationSummaryService
{
    public function __construct(private ?EventAudienceService $audienceService = null)
    {
        $this->audienceService = $audienceService ?? new EventAudienceService();
    }

    /**
     * @return array{total:int, pending:int, yes:int, no:int, maybe:int}|null
     *     Null when there are no relevant open registration events for the user.
     */
    public function forUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $events = $this->audienceService->visibleEventsQuery($userId)
            ->where('registration_enabled', true)
            ->where('starts_at', '>', Carbon::now())
            ->with(['registrations' => static function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            ->get()
            ->filter(static fn(Event $event): bool => $event->isRegistrationOpen());

        if ($events->isEmpty()) {
            return null;
        }

        $total = 0;
        $pending = 0;
        $yes = 0;
        $no = 0;
        $maybe = 0;

        foreach ($events as $event) {
            $total++;
            $status = optional($event->registrations->first())->status;

            if ($status === null) {
                $pending++;
            } elseif ($status === EventRegistration::STATUS_YES) {
                $yes++;
            } elseif ($status === EventRegistration::STATUS_NO) {
                $no++;
            } elseif ($status === EventRegistration::STATUS_MAYBE) {
                $maybe++;
            }
        }

        return [
            'total' => $total,
            'pending' => $pending,
            'yes' => $yes,
            'no' => $no,
            'maybe' => $maybe,
        ];
    }
}

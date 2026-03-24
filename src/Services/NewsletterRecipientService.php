<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Newsletter;
use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;

class NewsletterRecipientService
{
    /**
     * Resolve recipients for a newsletter based on project and optional event
     *
     * @param int $projectId
     * @param int $eventId Optional event ID
     * @return Collection<int, User>
     */
    public function resolveRecipients(int $projectId, int $eventId = 0): Collection
    {
        if ($eventId > 0) {
            return $this->getEventAttendees($eventId);
        }

        return $this->getProjectMembers($projectId);
    }

    /**
     * Get all active members of a project
     *
     * @param int $projectId
     * @return Collection<int, User>
     */
    public function getProjectMembers(int $projectId): Collection
    {
        return User::query()
            ->whereHas('projects', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            })
            ->where('is_active', 1)
            ->get();
    }

    /**
     * Get attendees for an event
     *
     * @param int $eventId
     * @return Collection<int, User>
     */
    public function getEventAttendees(int $eventId): Collection
    {
        $event = Event::find($eventId);
        if (!$event) {
            return new Collection();
        }

        return User::query()
            ->whereHas('attendances', function ($query) use ($eventId) {
                $query->where('event_id', $eventId)
                    ->where('attended', 1);
            })
            ->where('is_active', 1)
            ->get();
    }

    /**
     * Get stored recipients for a newsletter
     *
     * @param int $newsletterId
     * @return Collection<int, User>
     */
    public function getRecipients(int $newsletterId): Collection
    {
        return User::query()
            ->whereHas('newsletterRecipients', function ($query) use ($newsletterId) {
                $query->where('newsletter_id', $newsletterId);
            })
            ->get();
    }

    /**
     * Store or update recipients for a newsletter
     *
     * @param Newsletter $newsletter
     * @param array<int> $userIds User IDs
     * @return void
     */
    public function setRecipients(Newsletter $newsletter, array $userIds): void
    {
        $newsletter->recipients()->delete();

        foreach (array_values(array_unique($userIds)) as $userId) {
            $newsletter->recipients()->create([
                'user_id' => $userId,
                'status' => 'pending',
            ]);
        }

        $newsletter->recipient_count = count($userIds);
        $newsletter->save();
    }
}

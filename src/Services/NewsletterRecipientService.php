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
     * @return Collection|array
     */
    public function resolveRecipients(int $projectId, int $eventId = 0): array
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
     * @return User[]
     */
    public function getProjectMembers(int $projectId): array
    {
        $members = User::query()
            ->whereHas('projects', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            })
            ->where('is_active', 1)
            ->get();

        return $members->toArray();
    }

    /**
     * Get attendees for an event
     *
     * @param int $eventId
     * @return User[]
     */
    public function getEventAttendees(int $eventId): array
    {
        $event = Event::find($eventId);
        if (!$event) {
            return [];
        }

        $attendees = User::query()
            ->whereHas('attendance', function ($query) use ($eventId) {
                $query->where('event_id', $eventId)
                    ->where('attended', 1);
            })
            ->where('is_active', 1)
            ->get();

        return $attendees->toArray();
    }

    /**
     * Get stored recipients for a newsletter
     *
     * @param int $newsletterId
     * @return User[]
     */
    public function getRecipients(int $newsletterId): array
    {
        $recipients = User::query()
            ->whereHas('newsletterRecipients', function ($query) use ($newsletterId) {
                $query->where('newsletter_id', $newsletterId);
            })
            ->get();

        return $recipients->toArray();
    }

    /**
     * Store or update recipients for a newsletter
     *
     * @param Newsletter $newsletter
     * @param array $userIds User IDs
     * @return void
     */
    public function setRecipients(Newsletter $newsletter, array $userIds): void
    {
        $newsletter->recipients()->delete();

        foreach ($userIds as $userId) {
            $newsletter->recipients()->create([
                'user_id' => $userId,
                'status' => 'pending',
            ]);
        }

        $newsletter->recipient_count = count($userIds);
        $newsletter->save();
    }
}

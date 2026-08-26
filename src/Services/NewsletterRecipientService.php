<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\NewsletterRecipient;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class NewsletterRecipientService
{
    /**
     * Resolve recipients for a newsletter based on configured sources.
     *
     * @return Collection<int, User>
     */
    public function resolveRecipients(Newsletter $newsletter): Collection
    {
        $sources = $newsletter->relationLoaded('recipientSources')
            ? $newsletter->recipientSources
            : $newsletter->recipientSources()->get();
        if ($sources->isEmpty()) {
            return new Collection();
        }

        $userIds = [];

        foreach ($sources as $source) {
            $referenceId = (int) $source->reference_id;
            if ($referenceId <= 0) {
                continue;
            }

            if ($source->source_type === NewsletterRecipientSource::TYPE_PROJECT_MEMBERS) {
                $userIds = array_merge($userIds, $this->getProjectMembers($referenceId)->pluck('id')->all());
                continue;
            }

            if ($source->source_type === NewsletterRecipientSource::TYPE_EVENT_ATTENDEES) {
                $userIds = array_merge($userIds, $this->getEventAttendees($referenceId)->pluck('id')->all());
                continue;
            }

            if ($source->source_type === NewsletterRecipientSource::TYPE_ROLE) {
                $userIds = array_merge($userIds, $this->getUsersByRole($referenceId)->pluck('id')->all());
                continue;
            }

            if ($source->source_type === NewsletterRecipientSource::TYPE_USER) {
                $userIds = array_merge($userIds, $this->getActiveUser($referenceId)->pluck('id')->all());
            }
        }

        $uniqueIds = array_values(array_unique(array_map(static fn($id) => (int) $id, $userIds)));
        if ($uniqueIds === []) {
            return new Collection();
        }

        return User::query()
            ->whereIn('id', $uniqueIds)
            ->where('is_active', 1)
            ->get();
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
                    ->where('status', 'present');
            })
            ->where('is_active', 1)
            ->get();
    }

    /**
     * Get all active users assigned to a role.
     *
     * @param int $roleId
     * @return Collection<int, User>
     */
    public function getUsersByRole(int $roleId): Collection
    {
        return User::query()
            ->whereHas('roles', function ($query) use ($roleId) {
                $query->where('role_id', $roleId);
            })
            ->where('is_active', 1)
            ->get();
    }

    /**
     * Get one active user by id.
     *
     * @param int $userId
     * @return Collection<int, User>
     */
    public function getActiveUser(int $userId): Collection
    {
        return User::query()
            ->where('id', $userId)
            ->where('is_active', 1)
            ->get();
    }

    /**
     * Get stored recipients for a newsletter
     *
     * @param int $newsletterId
     * @return Collection<int, NewsletterRecipient>
     */
    public function getRecipients(int $newsletterId): Collection
    {
        return NewsletterRecipient::query()
            ->with(['user.voiceGroups', 'user.subVoices'])
            ->where('newsletter_id', $newsletterId)
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

    /**
     * Prüft eine rohe Quellenauswahl gegen die erlaubten Typen und die tatsächlich
     * vorhandenen Datensätze. Unbekannte Typen, gelöschte Bezüge und Doppelungen
     * fallen heraus. Newsletter und Vorlage teilen sich diese Prüfung, damit beide
     * dieselbe Auswahl akzeptieren.
     *
     * @param mixed $sources
     * @return array<int, array{type:string, reference_id:int}>
     */
    public function normalizeSources(mixed $sources): array
    {
        if (!is_array($sources)) {
            return [];
        }

        $allowedTypes = [
            NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
            NewsletterRecipientSource::TYPE_EVENT_ATTENDEES,
            NewsletterRecipientSource::TYPE_ROLE,
            NewsletterRecipientSource::TYPE_USER,
        ];

        $normalized = [];
        $seen = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $type = trim((string) ($source['type'] ?? ''));
            $referenceId = (int) ($source['reference_id'] ?? 0);

            if (!in_array($type, $allowedTypes, true) || $referenceId <= 0) {
                continue;
            }

            if (!$this->referenceExists($type, $referenceId)) {
                continue;
            }

            $dedupeKey = $type . ':' . $referenceId;
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $normalized[] = [
                'type' => $type,
                'reference_id' => $referenceId,
            ];
        }

        return $normalized;
    }

    private function referenceExists(string $type, int $referenceId): bool
    {
        if ($type === NewsletterRecipientSource::TYPE_PROJECT_MEMBERS) {
            return Project::query()->where('id', $referenceId)->exists();
        }

        if ($type === NewsletterRecipientSource::TYPE_EVENT_ATTENDEES) {
            return Event::query()->where('id', $referenceId)->exists();
        }

        if ($type === NewsletterRecipientSource::TYPE_ROLE) {
            return Role::query()->where('id', $referenceId)->exists();
        }

        return User::query()->where('id', $referenceId)->where('is_active', 1)->exists();
    }

    /**
     * Replace recipient sources and refresh resolved recipients.
     *
     * @param Newsletter $newsletter
     * @param array<int, array{type:string, reference_id:int}> $sources
     * @return void
     */
    public function setSources(Newsletter $newsletter, array $sources): void
    {
        $newsletter->recipientSources()->delete();

        foreach ($sources as $source) {
            $type = (string) ($source['type'] ?? '');
            $referenceId = (int) ($source['reference_id'] ?? 0);
            if ($type === '' || $referenceId <= 0) {
                continue;
            }

            $newsletter->recipientSources()->create([
                'source_type' => $type,
                'reference_id' => $referenceId,
            ]);
        }

        $resolved = $this->resolveRecipients($newsletter)
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();

        $this->setRecipients($newsletter, $resolved);
    }

    /**
     * Return configured sources in normalized array format.
     *
     * @param Newsletter $newsletter
     * @return array<int, array{type:string, reference_id:int}>
     */
    public function getSources(Newsletter $newsletter): array
    {
        return $newsletter->recipientSources()
            ->orderBy('id')
            ->get()
            ->map(static function ($source): array {
                return [
                    'type' => (string) $source->source_type,
                    'reference_id' => (int) $source->reference_id,
                ];
            })
            ->all();
    }
}

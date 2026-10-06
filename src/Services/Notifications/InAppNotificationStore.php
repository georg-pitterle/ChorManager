<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\UserNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Alles, was die Glocke in der Datenbank tut.
 *
 * Jede Abfrage beginnt bei der `user_id` - einen Eintrag ohne diese Schranke zu
 * lesen oder zu ändern, ist hier gar nicht vorgesehen.
 */
class InAppNotificationStore
{
    public const READ_RETENTION_DAYS = 30;
    public const UNREAD_RETENTION_DAYS = 180;

    /**
     * Ein INSERT für alle Empfänger: Ein Termin erreicht schnell hundert Mitglieder.
     *
     * @param list<int> $userIds
     */
    public function createMany(string $type, InAppMessage $message, array $userIds, ?int $actorUserId): int
    {
        if ($userIds === []) {
            return 0;
        }

        $now = Carbon::now();
        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = [
                'user_id' => $userId,
                'notification_type' => $type,
                'actor_user_id' => $actorUserId,
                'title' => $message->title,
                'body' => $message->body,
                'link' => $message->link,
                'entity_type' => $message->entityType,
                'entity_id' => $message->entityId,
                'comment_id' => $message->commentId,
                'read_at' => null,
                'created_at' => $now,
            ];
        }

        UserNotification::query()->insert($rows);

        return count($rows);
    }

    public function unreadCount(int $userId): int
    {
        return $this->forUser($userId)->whereNull('read_at')->count();
    }

    /**
     * @return Collection<int, UserNotification>
     */
    public function recent(int $userId, int $limit = 10): Collection
    {
        return $this->forUser($userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->toBase();
    }

    /**
     * @return array{items: Collection<int, UserNotification>, total: int, page: int, pages: int}
     */
    public function paginate(int $userId, int $page, bool $onlyUnread, int $perPage = 25): array
    {
        $query = $this->forUser($userId);
        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        $items = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->toBase();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function findForUser(int $userId, int $id): ?UserNotification
    {
        return $this->forUser($userId)->where('id', $id)->first();
    }

    public function markRead(UserNotification $notification): void
    {
        if ($notification->read_at !== null) {
            return;
        }

        $notification->read_at = Carbon::now();
        $notification->save();
    }

    public function markAllRead(int $userId): int
    {
        return $this->forUser($userId)->whereNull('read_at')->update(['read_at' => Carbon::now()]);
    }

    public function markEntityRead(int $userId, string $entityType, int $entityId): int
    {
        return $this->forUser($userId)
            ->whereNull('read_at')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->update(['read_at' => Carbon::now()]);
    }

    /**
     * Ein gelöschter Kommentar verschwindet auch aus der Glocke aller anderen.
     */
    public function deleteForComment(int $commentId): int
    {
        return (int) UserNotification::query()->where('comment_id', $commentId)->delete();
    }

    /**
     * Ein geänderter Kommentar ersetzt seinen alten Text in der Glocke - der
     * Lesestatus bleibt, wie er war.
     */
    public function rewriteForComment(int $commentId, string $body): int
    {
        return UserNotification::query()
            ->where('comment_id', $commentId)
            ->update(['body' => InAppMessage::plainBody($body)]);
    }

    /**
     * Für die Detailseiten: Wer das Objekt öffnet, hat seine Einträge gesehen.
     * Scheitert das, bleibt die Seite trotzdem erreichbar - der Fehler steht
     * im Log.
     */
    public function markEntityReadQuietly(
        int $userId,
        string $entityType,
        int $entityId,
        LoggerInterface $logger
    ): void {
        if ($userId <= 0) {
            return;
        }

        try {
            $this->markEntityRead($userId, $entityType, $entityId);
        } catch (\Throwable $e) {
            $logger->warning('Marking notifications read failed.', [
                'event' => 'notification.mark_entity_read_failed',
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Gelesene Einträge zählen ab dem Lesen, ungelesene ab dem Anlegen.
     */
    public function prune(?CarbonInterface $now = null): int
    {
        $now = $now ?? Carbon::now();

        $read = UserNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $now->copy()->subDays(self::READ_RETENTION_DAYS))
            ->delete();

        $unread = UserNotification::query()
            ->whereNull('read_at')
            ->where('created_at', '<', $now->copy()->subDays(self::UNREAD_RETENTION_DAYS))
            ->delete();

        return (int) $read + (int) $unread;
    }

    /**
     * @return Builder<UserNotification>
     */
    private function forUser(int $userId): Builder
    {
        return UserNotification::query()->where('user_id', $userId);
    }
}

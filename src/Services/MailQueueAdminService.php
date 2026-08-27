<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailQueue;
use Carbon\Carbon;
use Exception;

class MailQueueAdminService
{
    /** Zeilen je Seite, wenn der Aufrufer nichts anderes angibt. */
    public const DEFAULT_PER_PAGE = 50;

    /** Obergrenze, damit `per_page` aus der Adresszeile die Seite nicht sprengt. */
    private const MAX_PER_PAGE = 200;

    /**
     * List queue entries with filters.
     *
     * Seitenweise: Nach einem grossen Newsletter-Versand liegen entsprechend
     * viele Zeilen in der Warteschlange, und die Verwaltungsseite lud sie
     * vorher alle auf einmal.
     *
     * @param array $filters ['status' => '...', 'mail_type' => '...', 'search' => '...',
     *     'from_date' => '...', 'to_date' => '...', 'page' => 1, 'per_page' => 50]
     * @return \Illuminate\Support\Collection<int, MailQueue>
     */
    public function listEntries(array $filters = [])
    {
        $perPage = self::normalizePerPage($filters['per_page'] ?? null);
        $page = self::normalizePage($filters['page'] ?? null);

        return $this->buildQuery($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();
    }

    /**
     * Zahl der Zeilen, die zu den Filtern passen - Grundlage für die Blätterleiste.
     */
    public function countEntries(array $filters = []): int
    {
        return $this->buildQuery($filters)->count();
    }

    /**
     * Zahl der Seiten, die sich mit diesen Filtern ergeben. Mindestens eine,
     * damit die Leiste auch bei leerer Warteschlange eine gültige Seite kennt.
     */
    public function pageCount(array $filters = []): int
    {
        $perPage = self::normalizePerPage($filters['per_page'] ?? null);

        return max(1, (int) ceil($this->countEntries($filters) / $perPage));
    }

    public static function normalizePerPage(mixed $value): int
    {
        $perPage = is_numeric($value) ? (int) $value : self::DEFAULT_PER_PAGE;

        return max(1, min(self::MAX_PER_PAGE, $perPage));
    }

    public static function normalizePage(mixed $value): int
    {
        return max(1, is_numeric($value) ? (int) $value : 1);
    }

    /**
     * Gemeinsamer Unterbau von listEntries() und countEntries(): Beide müssen
     * dieselben Filter anlegen, sonst zählt die Blätterleiste etwas anderes,
     * als die Liste zeigt.
     *
     * @return \Illuminate\Database\Eloquent\Builder<MailQueue>
     */
    private function buildQuery(array $filters)
    {
        $query = MailQueue::query();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['mail_type'])) {
            $query->where('mail_type', $filters['mail_type']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('recipient_email', 'like', $search)
                    ->orWhere('subject', 'like', $search)
                    ->orWhere('error_message', 'like', $search);
            });
        }

        $fromDate = self::parseFilterDate($filters['from_date'] ?? null);
        if ($fromDate !== null) {
            $query->where('created_at', '>=', $fromDate);
        }

        $toDate = self::parseFilterDate($filters['to_date'] ?? null);
        if ($toDate !== null) {
            $query->where('created_at', '<=', $toDate->endOfDay());
        }

        return $query;
    }

    /**
     * Die Filterwerte stammen aus der Adresszeile und sind beliebiger Text.
     * Ein unlesbares Datum überspringt den Filter, statt die Seite abzubrechen.
     */
    private static function parseFilterDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get a single entry by ID.
     *
     * @param int $id
     * @return MailQueue|null
     */
    public function getEntry(int $id): ?MailQueue
    {
        return MailQueue::find($id);
    }

    /**
     * Retry a single dead-letter entry.
     *
     * @param int $entryId
     * @return bool
     * @throws Exception
     */
    public function retrySingle(int $entryId): bool
    {
        $entry = MailQueue::find($entryId);

        if (!$entry) {
            throw new Exception("Entry not found: {$entryId}");
        }

        if ($entry->status !== 'dead') {
            throw new Exception("Only dead entries can be retried. Current status: {$entry->status}");
        }

        $entry->update([
            'status' => 'queued',
            'next_attempt_at' => Carbon::now(),
            'attempts' => 0,
            'error_code' => null,
            'error_message' => null,
            'is_retryable' => false,
        ]);

        return true;
    }

    /**
     * Retry all dead-letter entries.
     *
     * @return int Number of entries retried
     */
    public function retryAllDead(): int
    {
        return MailQueue::dead()->update([
            'status' => 'queued',
            'next_attempt_at' => Carbon::now(),
            'attempts' => 0,
            'error_code' => null,
            'error_message' => null,
            'is_retryable' => false,
        ]);
    }

    /**
     * Get queue statistics.
     *
     * @return array ['queued' => int, 'sending' => int, 'sent' => int, 'skipped' => int,
     *     'failed' => int, 'dead' => int, 'total' => int]
     */
    public function getStats(): array
    {
        $stats = MailQueue::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'queued' => $stats['queued'] ?? 0,
            'sending' => $stats['sending'] ?? 0,
            'sent' => $stats['sent'] ?? 0,
            'skipped' => $stats['skipped'] ?? 0,
            'failed' => $stats['failed'] ?? 0,
            'dead' => $stats['dead'] ?? 0,
            'total' => array_sum($stats),
        ];
    }

    /**
     * Count dead-letter entries (for dashboard).
     *
     * @return int
     */
    public function countDeadLetters(): int
    {
        return MailQueue::dead()->count();
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailQueue;
use Carbon\Carbon;
use Exception;

class MailQueueAdminService
{
    /**
     * List queue entries with filters.
     *
     * @param array $filters ['status' => '...', 'mail_type' => '...', 'search' => '...',
     *     'from_date' => '...', 'to_date' => '...']
     * @return \Illuminate\Support\Collection<int, MailQueue>
     */
    public function listEntries(array $filters = [])
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

        return $query
            ->orderByDesc('created_at')
            ->get();
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

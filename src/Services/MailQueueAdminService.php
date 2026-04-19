<?php

namespace ChorManager\Services;

use ChorManager\Models\MailQueue;
use Carbon\Carbon;
use Exception;

class MailQueueAdminService
{
    /**
     * List queue entries with filters.
     *
     * @param array $filters ['status' => '...', 'mail_type' => '...', 'search' => '...', 'from_date' => '...', 'to_date' => '...']
     * @param int $perPage
     * @param int $page
     * @return \Illuminate\Pagination\Paginator
     */
    public function listEntries(array $filters = [], int $perPage = 50, int $page = 1)
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
        
        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from_date']));
        }
        
        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to_date'])->endOfDay());
        }
        
        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
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
            'next_attempt_at' => now(),
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
            'next_attempt_at' => now(),
            'attempts' => 0,
            'error_code' => null,
            'error_message' => null,
            'is_retryable' => false,
        ]);
    }
    
    /**
     * Get queue statistics.
     *
     * @return array ['queued' => int, 'sending' => int, 'sent' => int, 'failed' => int, 'dead' => int, 'total' => int]
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

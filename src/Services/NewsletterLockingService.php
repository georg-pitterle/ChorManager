<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use Carbon\Carbon;
use DateTime;

class NewsletterLockingService
{
    private const LOCK_TIMEOUT_MINUTES = 30;

    /**
     * Acquire exclusive lock for editing
     *
     * @param Newsletter $newsletter
     * @param int $userId
     * @return bool
     */
    public function acquireLock(Newsletter $newsletter, int $userId): bool
    {
        // Check if lock is still valid and by someone else
        if ($this->isLockedByOther($newsletter, $userId)) {
            return false;
        }

        // Release expired lock
        if ($newsletter->isLocked() && $this->isLockExpired($newsletter)) {
            $this->releaseLock($newsletter);
        }

        // Acquire or renew lock
        $newsletter->update([
            'locked_by' => $userId,
            'locked_at' => now(),
        ]);

        return true;
    }

    /**
     * Release lock
     *
     * @param Newsletter $newsletter
     * @return void
     */
    public function releaseLock(Newsletter $newsletter): void
    {
        $newsletter->update([
            'locked_by' => null,
            'locked_at' => null,
        ]);
    }

    /**
     * Check if user can edit (has lock or no lock exists)
     *
     * @param Newsletter $newsletter
     * @param int|null $userId
     * @return bool
     */
    public function canEdit(Newsletter $newsletter, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        if (!$newsletter->isLocked()) {
            return true;
        }

        if ($this->isLockExpired($newsletter)) {
            return true;
        }

        return $newsletter->locked_by === $userId;
    }

    /**
     * Check if newsletter is locked by specific user
     *
     * @param Newsletter $newsletter
     * @param int|null $userId
     * @return bool
     */
    public function isLockedBy(Newsletter $newsletter, ?int $userId): bool
    {
        if ($userId === null || !$newsletter->isLocked()) {
            return false;
        }

        if ($this->isLockExpired($newsletter)) {
            $this->releaseLock($newsletter);
            return false;
        }

        return $newsletter->locked_by === $userId;
    }

    /**
     * Check if locked by different user
     *
     * @param Newsletter $newsletter
     * @param int|null $userId
     * @return bool
     */
    public function isLockedByOther(Newsletter $newsletter, ?int $userId): bool
    {
        if (!$newsletter->isLocked() || $userId === null) {
            return false;
        }

        if ($this->isLockExpired($newsletter)) {
            $this->releaseLock($newsletter);
            return false;
        }

        return $newsletter->locked_by !== $userId;
    }

    /**
     * Check if lock has expired
     *
     * @param Newsletter $newsletter
     * @return bool
     */
    private function isLockExpired(Newsletter $newsletter): bool
    {
        if ($newsletter->locked_at === null) {
            return true;
        }

        $lockTime = $newsletter->locked_at;
        $expiryTime = $lockTime->addMinutes(self::LOCK_TIMEOUT_MINUTES);

        return now()->gt($expiryTime);
    }

    /**
     * Get lock info for display
     *
     * @param Newsletter $newsletter
     * @return array|null
     */
    public function getLockInfo(Newsletter $newsletter): ?array
    {
        if (!$newsletter->isLocked() || $this->isLockExpired($newsletter)) {
            return null;
        }

        return [
            'locked_by_user_id' => $newsletter->locked_by,
            'locked_at' => $newsletter->locked_at,
            'expires_at' => $newsletter->locked_at->addMinutes(self::LOCK_TIMEOUT_MINUTES),
        ];
    }
}

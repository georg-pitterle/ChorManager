<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailQueue;
use Carbon\Carbon;
use Exception;

class MailDeliveryService
{
    private Mailer $mailer;

    public function __construct(Mailer $mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * Process all due mail queue entries.
     *
     * @param int $batchSize
     * @return array ['sent' => int, 'skipped' => int, 'failed' => int, 'dead' => int]
     */
    public function processDueEntries(int $batchSize = 50): array
    {
        $entries = MailQueue::dueSoon()
            ->limit($batchSize)
            ->get();

        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'dead' => 0];

        foreach ($entries as $entry) {
            try {
                $this->sendEntry($entry);
            } catch (Exception $e) {
                $stats['failed']++;
                continue;
            }

            $entry->refresh();

            if ($entry->status === 'sent') {
                if ($entry->delivery_status === 'skipped') {
                    $stats['skipped']++;
                    continue;
                }

                $stats['sent']++;
                continue;
            }

            if ($entry->status === 'skipped') {
                $stats['skipped']++;
                continue;
            }

            if ($entry->status === 'dead') {
                $stats['dead']++;
                continue;
            }

            if ($entry->status === 'failed') {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Repair entries that are stuck in sending state.
     */
    public function repairStaleSendingEntries(int $minutes = 15): int
    {
        $now = Carbon::now();
        $threshold = $now->copy()->subMinutes($minutes);

        $staleEntries = MailQueue::query()
            ->where('status', 'sending')
            ->where('updated_at', '<=', $threshold)
            ->get();

        $repaired = 0;

        foreach ($staleEntries as $entry) {
            $newAttempts = (int) $entry->attempts + 1;
            $maxAttempts = (int) $entry->max_attempts;
            $isDead = $newAttempts >= $maxAttempts;

            $affected = MailQueue::query()
                ->where('id', $entry->id)
                ->where('status', 'sending')
                ->where('updated_at', '<=', $threshold)
                ->update([
                    'status' => $isDead ? 'dead' : 'failed',
                    'is_retryable' => !$isDead,
                    'next_attempt_at' => $isDead ? null : $now,
                    'last_attempt_at' => $now,
                    'attempts' => $newAttempts,
                    'error_code' => 'stale_sending_timeout',
                    'error_message' => sprintf('Watchdog recovered stale sending entry after %d minutes.', $minutes),
                ]);

            if ($affected !== 1) {
                continue;
            }

            if ($isDead && $entry->mail_type === 'newsletter') {
                $this->syncNewsletterRecipient($entry, 'failed');
            }

            $repaired++;
        }

        return $repaired;
    }

    /**
     * Send a single queue entry.
     *
     * @param MailQueue $entry
     * @throws Exception
     */
    public function sendEntry(MailQueue $entry): void
    {
        // Prevent double-send: set to 'sending' atomically
        $claimTimestamp = Carbon::now();
        $updated = MailQueue::where('id', $entry->id)
            ->where('status', $entry->status)
            ->update([
                'status' => 'sending',
                'updated_at' => $claimTimestamp,
            ]);

        if (!$updated) {
            throw new Exception("Entry already being processed or status changed");
        }

        // Reload after status change
        $entry = MailQueue::find($entry->id);

        try {
            // Attempt to send via Mailer
            $result = $this->mailer->sendHtmlMailDetailed(
                $entry->recipient_email,
                $entry->subject,
                $entry->body_html
            );

            $success = (bool) ($result['success'] ?? false);
            $isSkipped = (bool) ($result['skipped'] ?? false);

            if ($success && $isSkipped) {
                $entry->update([
                    'status' => 'skipped',
                    'delivery_status' => 'skipped',
                    'provider_name' => (string) ($result['provider_name'] ?? 'disabled'),
                    'provider_message_id' => null,
                    'accepted_at' => null,
                    'last_attempt_at' => Carbon::now(),
                    'attempts' => $entry->attempts + 1,
                    'error_code' => null,
                    'error_message' => null,
                ]);

                if ($entry->mail_type === 'newsletter') {
                    $this->syncNewsletterRecipient($entry, 'sent');
                }

                return;
            }

            if ($success) {
                $now = Carbon::now();

                $entry->update([
                    'status' => 'sent',
                    'delivery_status' => 'accepted',
                    'provider_name' => (string) (
                        $result['provider_name']
                        ?? ($this->mailer->isUsingSmtp() ? 'smtp' : 'sendmail')
                    ),
                    'provider_message_id' => $result['provider_message_id'] ?? null,
                    'sent_at' => $now,
                    'accepted_at' => $now,
                    'last_attempt_at' => $now,
                    'attempts' => $entry->attempts + 1,
                    'error_code' => null,
                    'error_message' => null,
                ]);

                // Sync to NewsletterRecipient if applicable
                if ($entry->mail_type === 'newsletter') {
                    $this->syncNewsletterRecipient($entry, 'sent');
                }
            } else {
                // Soft failure: might be retryable
                $this->handleFailure($entry, 'send_failed', $this->mailer->getLastError() ?? 'Unknown error');
            }
        } catch (Exception $e) {
            $this->handleFailure($entry, 'exception', $e->getMessage());
        }
    }

    /**
     * Handle mail send failure with retry logic.
     *
     * @param MailQueue $entry
     * @param string $errorCode
     * @param string $errorMessage
     */
    private function handleFailure(MailQueue $entry, string $errorCode, string $errorMessage): void
    {
        $entry->update([
            'last_attempt_at' => Carbon::now(),
            'attempts' => $entry->attempts + 1,
            'error_code' => $errorCode,
            'error_message' => substr($errorMessage, 0, 500),
        ]);

        $isRetryable = $this->classifyError($errorCode, $errorMessage);

        if ($isRetryable && $entry->attempts < $entry->max_attempts) {
            // Schedule next retry with exponential backoff
            $backoffSeconds = 60 * pow(2, $entry->attempts - 1); // 60, 120, 240...
            $nextAttemptAt = Carbon::now()->addSeconds($backoffSeconds);

            $entry->update([
                'status' => 'failed',
                'is_retryable' => true,
                'next_attempt_at' => $nextAttemptAt,
            ]);
        } else {
            // Dead letter: no more retries
            $entry->update([
                'status' => 'dead',
                'is_retryable' => false,
            ]);

            // Sync to NewsletterRecipient if applicable
            if ($entry->mail_type === 'newsletter') {
                $this->syncNewsletterRecipient($entry, 'failed');
            }
        }
    }

    /**
     * Classify error as retryable or permanent.
     *
     * @param string $errorCode
     * @param string $errorMessage
     * @return bool
     */
    private function classifyError(string $errorCode, string $errorMessage): bool
    {
        // Permanent errors (no retry)
        $permanentPatterns = [
            'invalid_email',
            'smtp_5[0-9]{2}',  // 500-599 permanent SMTP errors
            'invalid_config',
        ];

        foreach ($permanentPatterns as $pattern) {
            if (
                preg_match('/' . $pattern . '/i', $errorCode) ||
                preg_match('/' . $pattern . '/i', $errorMessage)
            ) {
                return false;
            }
        }

        // Temporary SMTP errors are retryable (4xx)
        if (preg_match('/smtp_4[0-9]{2}/i', $errorCode)) {
            return true;
        }

        // Default: assume retryable for transient issues
        return true;
    }

    /**
     * Sync mail queue result to NewsletterRecipient.
     *
     * @param MailQueue $entry
     * @param string $status 'sent' or 'failed'
     */
    private function syncNewsletterRecipient(MailQueue $entry, string $status): void
    {
        if ($entry->mail_type !== 'newsletter') {
            return;
        }

        $payload = $entry->payload_json ?? [];
        if (!isset($payload['recipient_id'])) {
            return;
        }

        // Find and update corresponding NewsletterRecipient
        \App\Models\NewsletterRecipient::where('id', $payload['recipient_id'])
            ->update(['status' => $status]);
    }
}

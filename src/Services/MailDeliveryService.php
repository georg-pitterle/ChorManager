<?php

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
     * @return array ['sent' => int, 'failed' => int, 'dead' => int]
     */
    public function processDueEntries(int $batchSize = 50): array
    {
        $entries = MailQueue::dueSoon()
            ->limit($batchSize)
            ->get();

        $stats = ['sent' => 0, 'failed' => 0, 'dead' => 0];

        foreach ($entries as $entry) {
            try {
                $this->sendEntry($entry);
                $stats['sent']++;
            } catch (Exception $e) {
                $stats['failed']++;
            }
        }

        return $stats;
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
        $updated = MailQueue::where('id', $entry->id)
            ->where('status', $entry->status)
            ->update(['status' => 'sending']);

        if (!$updated) {
            throw new Exception("Entry already being processed or status changed");
        }

        // Reload after status change
        $entry = MailQueue::find($entry->id);

        try {
            // Attempt to send via Mailer
            $success = $this->mailer->sendHtmlMail(
                $entry->recipient_email,
                $entry->subject,
                $entry->body_html
            );

            if ($success) {
                $entry->update([
                    'status' => 'sent',
                    'sent_at' => Carbon::now(),
                    'last_attempt_at' => Carbon::now(),
                    'attempts' => $entry->attempts + 1,
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

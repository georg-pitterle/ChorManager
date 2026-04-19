<?php

namespace App\Services;

use App\Models\MailQueue;
use Carbon\Carbon;
use Exception;

class MailQueueService
{
    /**
     * Enqueue a newsletter mail.
     *
     * @param string $recipientEmail
     * @param string $subject
     * @param string $bodyHtml
     * @param int $newsletterId
     * @param int $recipientId
     * @return MailQueue
     * @throws Exception
     */
    public function enqueueNewsletterMail(
        string $recipientEmail,
        string $subject,
        string $bodyHtml,
        int $newsletterId,
        int $recipientId
    ): MailQueue {
        return $this->enqueueGenericMail(
            mailType: 'newsletter',
            recipientEmail: $recipientEmail,
            subject: $subject,
            bodyHtml: $bodyHtml,
            payload: [
                'newsletter_id' => $newsletterId,
                'recipient_id' => $recipientId,
            ]
        );
    }

    /**
     * Enqueue an invitation mail.
     *
     * @param string $recipientEmail
     * @param string $subject
     * @param string $bodyHtml
     * @param int $userId
     * @param string $invitationToken
     * @return MailQueue
     * @throws Exception
     */
    public function enqueueInvitationMail(
        string $recipientEmail,
        string $subject,
        string $bodyHtml,
        int $userId,
        string $invitationToken
    ): MailQueue {
        return $this->enqueueGenericMail(
            mailType: 'invitation',
            recipientEmail: $recipientEmail,
            subject: $subject,
            bodyHtml: $bodyHtml,
            payload: [
                'user_id' => $userId,
                'invitation_token' => $invitationToken,
            ]
        );
    }

    /**
     * Enqueue a password reset mail.
     *
     * @param string $recipientEmail
     * @param string $subject
     * @param string $bodyHtml
     * @param int $userId
     * @param string $resetToken
     * @return MailQueue
     * @throws Exception
     */
    public function enqueuePasswordResetMail(
        string $recipientEmail,
        string $subject,
        string $bodyHtml,
        int $userId,
        string $resetToken
    ): MailQueue {
        return $this->enqueueGenericMail(
            mailType: 'password_reset',
            recipientEmail: $recipientEmail,
            subject: $subject,
            bodyHtml: $bodyHtml,
            payload: [
                'user_id' => $userId,
                'reset_token' => $resetToken,
            ]
        );
    }

    /**
     * Generic enqueue logic.
     *
     * @param string $mailType
     * @param string $recipientEmail
     * @param string $subject
     * @param string $bodyHtml
     * @param array $payload
     * @return MailQueue
     * @throws Exception
     */
    private function enqueueGenericMail(
        string $mailType,
        string $recipientEmail,
        string $subject,
        string $bodyHtml,
        array $payload
    ): MailQueue {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address: {$recipientEmail}");
        }

        $entry = MailQueue::create([
            'mail_type' => $mailType,
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'payload_json' => $payload,
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => 3,
            'is_retryable' => false,
            'next_attempt_at' => Carbon::now(),
        ]);

        return $entry;
    }
}

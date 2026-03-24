<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use App\Models\User;
use Exception;

class NewsletterService
{
    private NewsletterRecipientService $recipientService;

    public function __construct(NewsletterRecipientService $recipientService)
    {
        $this->recipientService = $recipientService;
    }

    /**
     * Send a newsletter to all recipients
     *
     * @param Newsletter $newsletter
     * @param int $userId User ID who triggered the send
     * @return void
     * @throws Exception
     */
    public function send(Newsletter $newsletter, int $userId): void
    {
        if ($newsletter->status !== 'draft') {
            throw new Exception('Nur Entwürfe können versendet werden');
        }

        if (empty($newsletter->content_html)) {
            throw new Exception('Newsletter-Inhalt ist leer');
        }

        $recipients = $this->recipientService->getRecipients($newsletter->id);

        if (count($recipients) === 0) {
            throw new Exception('Keine Empfänger definiert');
        }

        $sentCount = 0;
        $failedCount = 0;

        foreach ($recipients as $user) {
            try {
                $this->sendToUser($newsletter, $user);
                $sentCount++;

                NewsletterRecipient::where('newsletter_id', $newsletter->id)
                    ->where('user_id', $user->id)
                    ->update(['status' => 'sent']);

                NewsletterArchive::create([
                    'newsletter_id' => $newsletter->id,
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'sent_at' => now(),
                ]);
            } catch (Exception $e) {
                $failedCount++;
                NewsletterRecipient::where('newsletter_id', $newsletter->id)
                    ->where('user_id', $user->id)
                    ->update(['status' => 'failed']);
            }
        }

        $newsletter->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Send email to individual user
     * (Integration mit E-Mail-Service würde hier erfolgen)
     *
     * @param Newsletter $newsletter
     * @param User $user
     * @return void
     * @throws Exception
     */
    private function sendToUser(Newsletter $newsletter, User $user): void
    {
        // TODO: Implement actual email sending via PHPMailer or similar
        // For now, this is a placeholder - emails would be queued/sent here

        $emailSubject = $newsletter->title;
        $emailContent = $newsletter->content_html;
        $toEmail = $user->email;

        // Placeholder for email sending
        // $emailService->send($toEmail, $emailSubject, $emailContent);
    }

    /**
     * Check if newsletter can be sent (validation)
     *
     * @param Newsletter $newsletter
     * @return array Validation errors
     */
    public function validateForSending(Newsletter $newsletter): array
    {
        $errors = [];

        if ($newsletter->status !== 'draft') {
            $errors[] = 'Newsletter-Status erlaubt keinen Versand';
        }

        if (empty($newsletter->title)) {
            $errors[] = 'Titel erforderlich';
        }

        if (empty($newsletter->content_html)) {
            $errors[] = 'Inhalt erforderlich';
        }

        if ($newsletter->recipient_count === 0) {
            $errors[] = 'Keine Empfänger definiert';
        }

        return $errors;
    }
}

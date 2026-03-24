<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use App\Models\User;
use Carbon\Carbon;
use Exception;

class NewsletterService
{
    private NewsletterRecipientService $recipientService;
    private Mailer $mailer;

    public function __construct(NewsletterRecipientService $recipientService, Mailer $mailer)
    {
        $this->recipientService = $recipientService;
        $this->mailer = $mailer;
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

        if ($recipients->count() === 0) {
            $resolvedRecipients = $this->recipientService->resolveRecipients(
                (int) $newsletter->project_id,
                (int) ($newsletter->event_id ?? 0)
            );

            $this->recipientService->setRecipients($newsletter, $resolvedRecipients->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all());

            $recipients = $this->recipientService->getRecipients($newsletter->id);
        }

        if ($recipients->count() === 0) {
            throw new Exception('Keine Empfänger definiert');
        }

        $sentCount = 0;
        $failedCount = 0;
        $firstFailureMessage = null;

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
                    'sent_at' => Carbon::now(),
                ]);
            } catch (Exception $e) {
                $failedCount++;
                if ($firstFailureMessage === null) {
                    $firstFailureMessage = $e->getMessage();
                }

                NewsletterRecipient::where('newsletter_id', $newsletter->id)
                    ->where('user_id', $user->id)
                    ->update(['status' => 'failed']);
            }
        }

        if ($sentCount === 0 && $failedCount > 0) {
            throw new Exception(
                'Newsletter konnte nicht versendet werden: '
                    . ($firstFailureMessage ?? 'alle Zustellungen sind fehlgeschlagen')
            );
        }

        $newsletter->update([
            'status' => 'sent',
            'sent_at' => Carbon::now(),
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
        $toEmail = trim((string) $user->email);
        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new Exception('Ungültige Empfänger-E-Mail-Adresse');
        }

        $emailSubject = $newsletter->title;
        $emailContent = $newsletter->content_html;

        $sent = $this->mailer->sendHtmlMail($toEmail, $emailSubject, $emailContent);
        if (!$sent) {
            throw new Exception($this->mailer->getLastError() ?? 'Unbekannter Fehler beim E-Mail-Versand');
        }
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

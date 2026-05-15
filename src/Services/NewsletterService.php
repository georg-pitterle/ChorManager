<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use App\Models\User;
use App\Services\HtmlSanitizer;
use Carbon\Carbon;
use Exception;
use Psr\Log\LoggerInterface;

class NewsletterService
{
    private NewsletterRecipientService $recipientService;
    private Mailer $mailer;
    private HtmlSanitizer $htmlSanitizer;
    private MailQueueService $mailQueueService;
    private LoggerInterface $logger;

    public function __construct(
        NewsletterRecipientService $recipientService,
        Mailer $mailer,
        HtmlSanitizer $htmlSanitizer,
        MailQueueService $mailQueueService,
        LoggerInterface $logger
    ) {
        $this->recipientService = $recipientService;
        $this->mailer = $mailer;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->mailQueueService = $mailQueueService;
        $this->logger = $logger;
    }

    /**
     * Send a newsletter to all recipients
     *
     * @param Newsletter $newsletter
     * @param int $userId User ID who triggered the send
     * @return int Number of recipients actually sent to (or that would have been sent to when disabled)
     * @throws Exception
     */
    public function send(Newsletter $newsletter, int $userId): int
    {
        if (!$newsletter->isDraft()) {
            throw new Exception('Nur Entwürfe können versendet werden');
        }

        if (empty($newsletter->content_html)) {
            throw new Exception('Newsletter-Inhalt ist leer');
        }

        $recipients = $this->recipientService->getRecipients($newsletter->id);

        if ($recipients->count() === 0) {
            $resolvedRecipients = $this->recipientService->resolveRecipients($newsletter);

            $this->recipientService->setRecipients($newsletter, $resolvedRecipients->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all());

            $recipients = $this->recipientService->getRecipients($newsletter->id);
        }

        if ($recipients->count() === 0) {
            throw new Exception('Keine Empfänger definiert');
        }

        $sentCount = 0;
        $emailContent = $this->htmlSanitizer->sanitizeNewsletterHtml((string) $newsletter->content_html);

        // Enqueue newsletter for each recipient
        foreach ($recipients as $recipient) {
            $toEmail = trim((string) $recipient->user->email);
            if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            try {
                $this->mailQueueService->enqueueNewsletterMail(
                    recipientEmail: $toEmail,
                    subject: $newsletter->title,
                    bodyHtml: $emailContent,
                    newsletterId: (int) $newsletter->id,
                    recipientId: (int) $recipient->id
                );

                // Mark as queued initially
                NewsletterRecipient::where('newsletter_id', $newsletter->id)
                    ->where('user_id', $recipient->user->id)
                    ->update(['status' => 'queued']);

                $sentCount++;
            } catch (Exception $e) {
                $this->logger->error(
                    'Failed to enqueue newsletter recipient.',
                    [
                        'event' => 'newsletter.enqueue.failed',
                        'newsletter_id' => (int) $newsletter->id,
                        'recipient_id' => (int) $recipient->id,
                        'recipient_email' => $toEmail,
                        'exception' => $e,
                    ]
                );
                NewsletterRecipient::where('newsletter_id', $newsletter->id)
                    ->where('user_id', $recipient->user->id)
                    ->update(['status' => 'failed']);
            }
        }

        if ($sentCount === 0) {
            throw new Exception('Newsletter konnte nicht in Queue eingereiht werden');
        }

        $newsletter->update([
            'status' => Newsletter::STATUS_SENT,
            'sent_at' => Carbon::now(),
        ]);

        return $sentCount;
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

        if (!$newsletter->isDraft()) {
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

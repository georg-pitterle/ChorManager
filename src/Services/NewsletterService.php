<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NewsletterWithoutRecipientsException;
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
    private NewsletterPlaceholderService $placeholderService;
    private NewsletterMailRenderer $mailRenderer;

    public function __construct(
        NewsletterRecipientService $recipientService,
        Mailer $mailer,
        HtmlSanitizer $htmlSanitizer,
        MailQueueService $mailQueueService,
        LoggerInterface $logger,
        NewsletterPlaceholderService $placeholderService,
        NewsletterMailRenderer $mailRenderer
    ) {
        $this->recipientService = $recipientService;
        $this->mailer = $mailer;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->mailQueueService = $mailQueueService;
        $this->logger = $logger;
        $this->placeholderService = $placeholderService;
        $this->mailRenderer = $mailRenderer;
    }

    /**
     * Send a newsletter to all recipients
     *
     * @param Newsletter $newsletter
     * @param int $userId User ID who triggered the send
     * @param string $baseUrl Basisadresse für Links in Platzhaltern
     * @return int Number of recipients actually sent to (or that would have been sent to when disabled)
     * @throws Exception
     */
    public function send(Newsletter $newsletter, int $userId, string $baseUrl): int
    {
        if (!$newsletter->isDraft()) {
            throw new Exception('Nur Entwürfe können versendet werden');
        }

        if (empty($newsletter->content_html)) {
            throw new Exception('Newsletter-Inhalt ist leer');
        }

        // Resolve recipients fresh at send time so the audience reflects the
        // current project membership, role assignments and active state instead
        // of a stale snapshot taken when the draft was last saved.
        $resolvedRecipients = $this->recipientService->resolveRecipients($newsletter);

        // Erst prüfen, dann schreiben: setRecipients() ersetzt die
        // gespeicherten Empfängerzeilen und recipient_count unwiderruflich.
        // Ein abgelehnter Versand darf den zuvor gespeicherten Datensatz
        // nicht verändern.
        if ($resolvedRecipients->count() === 0) {
            throw new NewsletterWithoutRecipientsException();
        }

        $sentAt = Carbon::now();

        // Bedingtes Update als Claim: Der Übergang Entwurf -> Versendet gehört
        // genau einem Versandlauf. Ohne diesen Claim würden ein Doppelklick oder
        // zwei gleichzeitig sendende Personen denselben Newsletter zweimal
        // einreihen und die Empfängerzuordnung überschreiben.
        $claimed = Newsletter::query()
            ->whereKey((int) $newsletter->id)
            ->where('status', Newsletter::STATUS_DRAFT)
            ->update([
                'status' => Newsletter::STATUS_SENT,
                'sent_at' => $sentAt,
            ]);

        if ($claimed === 0) {
            throw new Exception('Nur Entwürfe können versendet werden');
        }

        $newsletter->status = Newsletter::STATUS_SENT;
        $newsletter->sent_at = $sentAt;
        $newsletter->syncOriginal();

        try {
            return $this->deliver($newsletter, $resolvedRecipients, $sentAt, $baseUrl);
        } catch (Exception $e) {
            // Der Claim gilt nur für einen tatsächlich angelaufenen Versand.
            // Bricht er ab, bevor eine Mail in der Queue liegt, bliebe der
            // Entwurf sonst dauerhaft als "versendet" blockiert.
            $this->releaseClaim($newsletter);
            throw $e;
        }
    }

    /**
     * Reiht den Newsletter für alle aufgelösten Empfänger in die Mail-Queue ein.
     *
     * @param \Illuminate\Support\Collection<int, User> $resolvedRecipients
     * @param string $baseUrl Basisadresse für Links in Platzhaltern
     * @return int Number of recipients actually enqueued
     * @throws Exception
     */
    private function deliver(Newsletter $newsletter, $resolvedRecipients, Carbon $sentAt, string $baseUrl): int
    {
        $this->recipientService->setRecipients(
            $newsletter,
            $resolvedRecipients->pluck('id')->map(static function ($id): int {
                return (int) $id;
            })->all()
        );

        $recipients = $this->recipientService->getRecipients($newsletter->id);

        $sentCount = 0;
        $emailContent = $this->htmlSanitizer->sanitizeNewsletterHtml((string) $newsletter->content_html);

        // Empfänger-unabhängige Werte einmal auflösen, nicht je Empfänger.
        $renderContext = $this->placeholderService->contextFor($newsletter, $baseUrl);

        // Enqueue newsletter for each recipient
        foreach ($recipients as $recipient) {
            $toEmail = trim((string) $recipient->user->email);
            if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            try {
                $subject = $this->placeholderService->renderSubject(
                    (string) $newsletter->title,
                    $renderContext,
                    $recipient->user
                );
                $personalizedContent = $this->placeholderService->renderHtml(
                    $emailContent,
                    $renderContext,
                    $recipient->user
                );

                $this->mailQueueService->enqueueNewsletterMail(
                    recipientEmail: $toEmail,
                    subject: $subject,
                    bodyHtml: $this->mailRenderer->renderHtml($newsletter, $subject, $personalizedContent, $baseUrl),
                    newsletterId: (int) $newsletter->id,
                    recipientId: (int) $recipient->id
                );

                // Mark as queued initially
                NewsletterRecipient::where('newsletter_id', $newsletter->id)
                    ->where('user_id', $recipient->user->id)
                    ->update(['status' => 'queued']);

                // Record a per-recipient archive entry so the newsletter shows up
                // in the recipient's personal archive and can be previewed in-app,
                // independent of their current project membership.
                NewsletterArchive::updateOrCreate(
                    [
                        'newsletter_id' => (int) $newsletter->id,
                        'user_id' => (int) $recipient->user->id,
                    ],
                    [
                        'email' => $toEmail,
                        'sent_at' => $sentAt,
                    ]
                );

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

        return $sentCount;
    }

    /**
     * Gibt einen Entwurf nach einem gescheiterten Versand wieder frei.
     */
    private function releaseClaim(Newsletter $newsletter): void
    {
        Newsletter::query()
            ->whereKey((int) $newsletter->id)
            ->update([
                'status' => Newsletter::STATUS_DRAFT,
                'sent_at' => null,
            ]);

        $newsletter->status = Newsletter::STATUS_DRAFT;
        $newsletter->sent_at = null;
        $newsletter->syncOriginal();
    }
}

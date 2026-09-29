<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Exceptions\NewsletterAttachmentsTooLargeException;
use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Services\MailDeliveryService;
use App\Services\Mailer;
use App\Services\NewsletterAttachmentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Eine verlinkte Datei muss in der Mail auftauchen - sonst liegt sie im
 * ChorManager und niemand erfährt davon. Eine angehängte muss an der Mail
 * hängen, und die nächste Mail darf sie nicht erben.
 */
final class NewsletterAttachmentDeliveryFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testLinkedFileAppearsInTheRenderedMail(): void
    {
        $newsletter = $this->draft();
        $attachment = $this->attachment((int) $newsletter->id, 'programm.pdf', 'link');

        $html = $this->mailRenderer()->renderHtml(
            $newsletter,
            'Probenplan',
            '<p>Inhalt</p>',
            'https://chor.example',
            true,
            [[
                'name' => 'programm.pdf',
                'size' => (int) $attachment->file_size,
                'url' => 'https://chor.example/attachments/' . $attachment->id . '/download',
            ]]
        );

        $this->assertStringContainsString('programm.pdf', $html);
        $this->assertStringContainsString('/attachments/' . $attachment->id . '/download', $html);
    }

    /**
     * Der Vorschau-Rahmen ist streng sandboxed. Ein Link ohne eigenes Ziel
     * würde darin navigieren - Firefox lehnt das mit "darf diese eingebettete
     * Seite nicht öffnen" ab, und im sandboxed Rahmen fehlte der Sitzung
     * ohnehin das Cookie für den Download.
     */
    public function testLinkedFilesOpenInANewTab(): void
    {
        $newsletter = $this->draft();
        $attachment = $this->attachment((int) $newsletter->id, 'programm.pdf', 'link');

        $html = $this->mailRenderer()->renderHtml(
            $newsletter,
            'Probenplan',
            '<p>Inhalt</p>',
            'https://chor.example',
            false,
            [[
                'name' => 'programm.pdf',
                'size' => (int) $attachment->file_size,
                'url' => 'https://chor.example/attachments/' . $attachment->id . '/download',
            ]]
        );

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    /**
     * Die Gegenseite dazu: Ohne diese beiden Erlaubnisse bleibt der Klick im
     * Rahmen stecken. allow-scripts und allow-same-origin bleiben bewusst
     * draußen - das Mail-HTML soll weder Skripte ausführen noch an unsere
     * Herkunft kommen.
     */
    public function testPreviewFramesAllowOpeningLinksInANewTab(): void
    {
        $templates = [
            dirname(__DIR__, 2) . '/templates/newsletters/edit.twig',
            dirname(__DIR__, 2) . '/templates/newsletters/preview.twig',
            dirname(__DIR__, 2) . '/templates/admin/mail_queue/show.twig',
        ];

        foreach ($templates as $path) {
            $content = (string) file_get_contents($path);

            $this->assertStringContainsString(
                'sandbox="allow-popups allow-popups-to-escape-sandbox allow-downloads"',
                $content,
                basename($path) . ': Der Vorschau-Rahmen muss Links in ein neues Tab lassen.'
            );
            $this->assertStringNotContainsString('allow-scripts', $content, basename($path));
            $this->assertStringNotContainsString('allow-same-origin', $content, basename($path));
        }
    }

    public function testWithoutLinkedFilesNoSectionIsRendered(): void
    {
        $newsletter = $this->draft();

        $html = $this->mailRenderer()->renderHtml(
            $newsletter,
            'Probenplan',
            '<p>Inhalt</p>',
            'https://chor.example'
        );

        $this->assertStringNotContainsString('newsletter-files', $html);
    }

    /**
     * Der Versand löst die Link-Liste selbst auf - die Empfängerinnen sehen
     * dieselben Dateien wie die Vorschau, ohne dass der Aufrufer sie mitgeben
     * muss.
     */
    public function testSendPutsTheLinkedFileIntoTheQueuedMail(): void
    {
        $newsletter = $this->draft();
        $attachment = $this->attachment((int) $newsletter->id, 'programm.pdf', 'link');

        $this->newsletterService()->send($newsletter, (int) $_SESSION['user_id'], 'https://chor.example');

        $queued = \App\Models\MailQueue::query()
            ->where('mail_type', 'newsletter')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($queued);
        $this->assertStringContainsString(
            '/attachments/' . $attachment->id . '/download',
            (string) $queued->body_html
        );
    }

    public function testAttachedFileEndsUpInTheMimeMessage(): void
    {
        $mime = (new Mailer())->buildMimeMessage(
            'georg@example.test',
            'Probenplan',
            '<p>Inhalt</p>',
            [['content' => 'pdf-inhalt', 'name' => 'programm.pdf', 'mime' => 'application/pdf']]
        );

        $this->assertStringContainsString('programm.pdf', $mime);
        $this->assertStringContainsString(base64_encode('pdf-inhalt'), $mime);
    }

    /**
     * Die PHPMailer-Instanz lebt über mehrere Mails hinweg. Ohne Zurücksetzen
     * hinge die Datei der Vormail an der nächsten - beim Newsletter an jeder
     * folgenden Mail eines anderen Empfängers.
     */
    public function testTheNextMailDoesNotCarryThePreviousAttachment(): void
    {
        $mailer = new Mailer();

        $mailer->buildMimeMessage(
            'georg@example.test',
            'Erste',
            '<p>Inhalt</p>',
            [['content' => 'pdf-inhalt', 'name' => 'programm.pdf', 'mime' => 'application/pdf']]
        );

        $second = $mailer->buildMimeMessage('anna@example.test', 'Zweite', '<p>Inhalt</p>');

        $this->assertStringNotContainsString('programm.pdf', $second);
    }

    /**
     * Die Dateien liegen nicht in der Queue, sondern am Newsletter: Eine Kopie
     * je Empfängerzeile wären bei 80 Empfängern und einem 3-MB-PDF eine
     * Viertelgigabyte in mail_queue. Der Worker lädt sie zur Sendezeit nach.
     */
    public function testTheWorkerLoadsAttachedFilesForAQueuedNewsletterMail(): void
    {
        $newsletter = $this->draft();
        $this->attachment((int) $newsletter->id, 'programm.pdf', 'attach');
        $this->attachment((int) $newsletter->id, 'nur-link.pdf', 'link');

        $entry = MailQueue::create([
            'mail_type' => 'newsletter',
            'recipient_email' => 'georg@example.test',
            'subject' => 'Probenplan',
            'body_html' => '<p>Inhalt</p>',
            'status' => 'queued',
            'payload_json' => ['newsletter_id' => (int) $newsletter->id],
        ]);

        $attachments = $this->invokeAttachmentsFor($entry);

        $this->assertCount(1, $attachments);
        $this->assertSame('programm.pdf', $attachments[0]['name']);
    }

    public function testTheWorkerLoadsNothingForAMailOfAnotherType(): void
    {
        $newsletter = $this->draft();
        $this->attachment((int) $newsletter->id, 'programm.pdf', 'attach');

        $entry = MailQueue::create([
            'mail_type' => 'notification',
            'recipient_email' => 'georg@example.test',
            'subject' => 'Hinweis',
            'body_html' => '<p>Inhalt</p>',
            'status' => 'queued',
            'payload_json' => ['newsletter_id' => (int) $newsletter->id],
        ]);

        $this->assertSame([], $this->invokeAttachmentsFor($entry));
    }

    /**
     * @return array<int, array{content: string, name: string, mime: string}>
     */
    private function invokeAttachmentsFor(MailQueue $entry): array
    {
        $service = new MailDeliveryService(new Mailer(new NullLogger()), new NewsletterAttachmentService());

        return (new \ReflectionMethod($service, 'attachmentsFor'))->invoke($service, $entry);
    }

    public function testSendIsRefusedWhenAttachmentsExceedTheLimit(): void
    {
        $newsletter = $this->draft();

        // Nur die Angabe in file_size zählt - ein echtes 11-MB-BLOB im Test
        // wäre nichts als Laufzeit.
        $this->attachment((int) $newsletter->id, 'riesig.pdf', 'attach', 11 * 1024 * 1024);

        try {
            $this->newsletterService()->send($newsletter, (int) $_SESSION['user_id'], 'https://chor.example');
            $this->fail('Der Versand hätte abgelehnt werden müssen.');
        } catch (NewsletterAttachmentsTooLargeException) {
            $this->assertSame(
                Newsletter::STATUS_DRAFT,
                Newsletter::query()->find($newsletter->id)->status
            );
        }
    }

    public function testLinkedFilesDoNotCountTowardsTheLimit(): void
    {
        $newsletter = $this->draft();

        $this->attachment((int) $newsletter->id, 'riesig.pdf', 'link', 11 * 1024 * 1024);

        $this->newsletterService()->send($newsletter, (int) $_SESSION['user_id'], 'https://chor.example');

        $this->assertSame(
            Newsletter::STATUS_SENT,
            Newsletter::query()->find($newsletter->id)->status
        );
    }

    /**
     * Die Meldung nennt den Ausweg. "Fehler beim Versand." hülfe niemandem
     * weiter, und der Entwurf bliebe unverstanden liegen.
     */
    public function testTheControllerExplainsWhatToDoAboutTooLargeAttachments(): void
    {
        $newsletter = $this->draft();
        $this->attachment((int) $newsletter->id, 'riesig.pdf', 'attach', 11 * 1024 * 1024);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/send")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->send($request, $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('In der Mail verlinken', (string) ($_SESSION['error'] ?? ''));
        $this->assertSame(
            Newsletter::STATUS_DRAFT,
            Newsletter::query()->find($newsletter->id)->status
        );
    }

    private function draft(): Newsletter
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        return $this->createNewsletter($creator, $recipient);
    }

    private function attachment(int $newsletterId, string $name, string $mode, int $size = 12): Attachment
    {
        return Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => $newsletterId,
            'filename' => bin2hex(random_bytes(4)) . '_' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => $size,
            'file_content' => str_repeat('x', min($size, 12)),
            'delivery_mode' => $mode,
        ]);
    }
}

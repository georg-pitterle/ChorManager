<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\User;
use App\Policies\NewsletterPolicy;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Wer den Newsletter ansehen darf, darf auch die darin verlinkte Datei laden -
 * und sonst niemand. Ohne diese Kopplung wäre die zentrale Anhang-Route ein Weg
 * an der Newsletter-Sichtbarkeit vorbei.
 *
 * Die Verwaltung kommt ebenfalls durch, und zwar schon vor dem Versand: Solange
 * kein Empfänger feststeht, gäbe es sonst niemanden, der die eigene Datei in
 * Vorschau oder Testmail öffnen könnte.
 */
final class NewsletterAttachmentAccessFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testRecipientOfTheNewsletterMayLoadTheFile(): void
    {
        [$newsletter, $recipient] = $this->newsletterWithRecipient();
        $attachment = $this->createNewsletterAttachment((int) $newsletter->id);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $this->assertTrue($this->registry()->mayAccess($attachment));
    }

    public function testSomeoneOutsideTheDistributionListIsRejected(): void
    {
        [$newsletter] = $this->newsletterWithRecipient();
        $stranger = $this->createUser('Lena');
        $attachment = $this->createNewsletterAttachment((int) $newsletter->id);

        $_SESSION['user_id'] = (int) $stranger->id;
        $_SESSION['can_manage_newsletters'] = false;

        $this->assertFalse($this->registry()->mayAccess($attachment));
    }

    public function testNewsletterManagementMayLoadTheFileBeforeSending(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);
        $attachment = $this->createNewsletterAttachment((int) $newsletter->id);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $this->assertTrue($this->registry()->mayAccess($attachment));
    }

    public function testDisabledModuleLocksTheFile(): void
    {
        [$newsletter, $recipient] = $this->newsletterWithRecipient();
        $attachment = $this->createNewsletterAttachment((int) $newsletter->id);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = true;

        $this->assertFalse($this->registry(false)->mayAccess($attachment));
    }

    /**
     * Ein versendeter Newsletter samt Archiv-Zeile - die Zeile ist es, die den
     * Zugriff trägt.
     *
     * @return array{0: Newsletter, 1: User}
     */
    private function newsletterWithRecipient(): array
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => '2026-09-12 18:30:00',
        ]);

        return [$newsletter, $recipient];
    }

    private function registry(bool $moduleEnabled = true): AttachmentAccessRegistry
    {
        return new AttachmentAccessRegistry(
            new SponsoringPolicy($_SESSION),
            new TaskPolicy($_SESSION),
            ['newsletter' => $moduleEnabled],
            new NewsletterPolicy($_SESSION),
            $_SESSION
        );
    }

    private function createNewsletterAttachment(int $newsletterId): Attachment
    {
        return Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => $newsletterId,
            'filename' => bin2hex(random_bytes(4)) . '_programm.pdf',
            'original_name' => 'programm.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'file_content' => 'pdf-inhalt-x',
            'delivery_mode' => 'link',
        ]);
    }
}

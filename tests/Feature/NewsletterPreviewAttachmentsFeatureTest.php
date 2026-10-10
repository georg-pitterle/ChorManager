<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Newsletter;
use PHPUnit\Framework\TestCase;

/**
 * Das Archiv öffnet die Vorschau, und der Mail-Rahmen darin kennt nur die
 * verlinkten Dateien. Eine angehängte Datei (der Standard bis 2 MB) stünde
 * nirgends - wer den Newsletter empfangen hat, sähe nicht, dass etwas dranhing.
 */
final class NewsletterPreviewAttachmentsFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testPreviewListsAttachedFilesWithDownloadLink(): void
    {
        $newsletter = $this->draft();
        $attached = $this->attachment((int) $newsletter->id, 'programm.pdf', 'attach');

        $html = $this->renderPreview($newsletter);

        $this->assertStringContainsString('Anhänge', $html);
        $this->assertStringContainsString('programm.pdf', $html);
        $this->assertStringContainsString('/attachments/' . $attached->id . '/download', $html);
    }

    public function testPreviewDoesNotListLinkedFilesTwice(): void
    {
        $newsletter = $this->draft();
        $this->attachment((int) $newsletter->id, 'nur-link.pdf', 'link');

        $html = $this->renderPreview($newsletter);

        // Verlinkte Dateien stehen im Mail-Rahmen selbst, nicht in der Anhangliste.
        $this->assertStringNotContainsString('nur-link.pdf', $html);
    }

    public function testPreviewWithoutAttachmentsHasNoAttachmentSection(): void
    {
        $newsletter = $this->draft();

        $this->assertStringNotContainsString('newsletter-preview-attachments', $this->renderPreview($newsletter));
    }

    /**
     * Beim Versenden sieht man die Vorschau im Editor, nicht die des Archivs - dort
     * muss ebenso erkennbar sein, was als Datei an der Mail hängt.
     */
    public function testEditorPreviewModalListsAttachedFilesOnly(): void
    {
        $newsletter = $this->draft();
        $this->attachment((int) $newsletter->id, 'programm.pdf', 'attach');
        $this->attachment((int) $newsletter->id, 'nur-link.pdf', 'link');

        $body = $this->renderEditor($newsletter);

        $this->assertMatchesRegularExpression(
            '/id="preview-modal-attachments".*programm\.pdf.*<\/ul>/s',
            $body
        );
        $section = substr($body, (int) strpos($body, 'id="preview-modal-attachments"'));
        $section = substr($section, 0, (int) strpos($section, '</ul>'));
        $this->assertStringNotContainsString('nur-link.pdf', $section);
    }

    public function testEditorPreviewModalHasNoAttachmentListWithoutAttachedFiles(): void
    {
        $newsletter = $this->draft();
        $this->attachment((int) $newsletter->id, 'nur-link.pdf', 'link');

        $this->assertStringNotContainsString(
            'id="preview-modal-attachments"',
            $this->renderEditor($newsletter)
        );
    }

    private function renderEditor(Newsletter $newsletter): string
    {
        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/edit")
            ->withAttribute('id', (string) $newsletter->id);

        return (string) $this->controller()->edit($request, $this->makeResponse())->getBody();
    }

    public function testPreviewFrameDropsTheBrowserLinkPlaceholder(): void
    {
        $newsletter = $this->draft();
        $newsletter->content_html = '<p>Lies <a href="#">hier</a>: {{archiv_link}}</p>';
        $newsletter->save();

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview-frame")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->previewFrame($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Im Browser ansehen', (string) $response->getBody());
    }

    private function renderPreview(Newsletter $newsletter): string
    {
        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function draft(): Newsletter
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        return $this->createNewsletter($creator, $recipient);
    }

    private function attachment(int $newsletterId, string $name, string $mode): Attachment
    {
        return Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => $newsletterId,
            'filename' => bin2hex(random_bytes(4)) . '_' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'file_content' => str_repeat('x', 12),
            'delivery_mode' => $mode,
        ]);
    }
}

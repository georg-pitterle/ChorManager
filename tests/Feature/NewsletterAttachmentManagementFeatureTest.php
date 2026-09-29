<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Newsletter;
use App\Services\NewsletterAttachmentService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

/**
 * Dateien gehören zum Entwurf. Nach dem Versand sind sie eingefroren - eine
 * nachträglich gelöschte Datei ließe die Links in bereits zugestellten Mails
 * ins Leere zeigen.
 */
final class NewsletterAttachmentManagementFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testUploadSuggestsAttachmentForASmallFile(): void
    {
        $newsletter = $this->draft();

        $this->upload($newsletter, 'probenplan.pdf', 'kurz');

        $attachment = $this->attachments($newsletter)->first();

        $this->assertNotNull($attachment);
        $this->assertSame('probenplan.pdf', $attachment->original_name);
        $this->assertSame('attach', $attachment->delivery_mode);
    }

    public function testUploadSuggestsLinkForALargeFile(): void
    {
        $newsletter = $this->draft();

        $this->upload(
            $newsletter,
            'konzertprogramm.pdf',
            str_repeat('x', NewsletterAttachmentService::ATTACH_SUGGESTION_LIMIT + 1)
        );

        $this->assertSame('link', $this->attachments($newsletter)->first()->delivery_mode);
    }

    public function testModeCanBeSwitchedToLink(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'probenplan.pdf', 'kurz');
        $attachment = $this->attachments($newsletter)->firstOrFail();

        $this->switchMode($newsletter, (int) $attachment->id, 'link');

        $this->assertSame('link', Attachment::query()->find($attachment->id)->delivery_mode);
    }

    public function testAnUnknownModeChangesNothing(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'probenplan.pdf', 'kurz');
        $attachment = $this->attachments($newsletter)->firstOrFail();

        $this->switchMode($newsletter, (int) $attachment->id, 'inline');

        $this->assertSame('attach', Attachment::query()->find($attachment->id)->delivery_mode);
    }

    public function testAttachmentCanBeDeletedFromADraft(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'probenplan.pdf', 'kurz');
        $attachment = $this->attachments($newsletter)->firstOrFail();

        $this->deleteAttachment($newsletter, (int) $attachment->id);

        $this->assertNull(Attachment::query()->find($attachment->id));
    }

    public function testASentNewsletterAcceptsNoFurtherUpload(): void
    {
        $newsletter = $this->draft();
        $newsletter->update(['status' => Newsletter::STATUS_SENT]);

        $this->upload($newsletter, 'nachtrag.pdf', 'kurz');

        $this->assertSame(0, $this->attachments($newsletter)->count());
    }

    public function testASentNewsletterKeepsItsAttachments(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'programm.pdf', 'kurz');
        $attachment = $this->attachments($newsletter)->firstOrFail();
        $newsletter->update(['status' => Newsletter::STATUS_SENT]);

        $this->deleteAttachment($newsletter, (int) $attachment->id);

        $this->assertNotNull(Attachment::query()->find($attachment->id));
    }

    public function testASentNewsletterKeepsTheChosenMode(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'programm.pdf', 'kurz');
        $attachment = $this->attachments($newsletter)->firstOrFail();
        $newsletter->update(['status' => Newsletter::STATUS_SENT]);

        $this->switchMode($newsletter, (int) $attachment->id, 'link');

        $this->assertSame('attach', Attachment::query()->find($attachment->id)->delivery_mode);
    }

    public function testWithoutTheManagementRightNothingIsStored(): void
    {
        $newsletter = $this->draft();
        $_SESSION['can_manage_newsletters'] = false;

        $this->upload($newsletter, 'fremd.pdf', 'kurz');

        $this->assertSame(0, $this->attachments($newsletter)->count());
    }

    public function testTheEditPageListsTheFiles(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'probenplan.pdf', 'kurz');

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/edit")
            ->withAttribute('id', (string) $newsletter->id);
        $body = (string) $this->controller()->edit($request, $this->makeResponse())->getBody();

        $this->assertStringContainsString('probenplan.pdf', $body);
        $this->assertStringContainsString("/newsletters/{$newsletter->id}/attachments", $body);
    }

    private function draft(): Newsletter
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        return $this->createNewsletter($creator, $recipient);
    }

    private function upload(Newsletter $newsletter, string $name, string $contents): void
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/attachments")
            ->withUploadedFiles([
                'attachments' => [
                    new UploadedFile($path, $name, 'application/pdf', strlen($contents), UPLOAD_ERR_OK),
                ],
            ]);

        $this->controller()->uploadAttachments(
            $request,
            $this->makeResponse(),
            ['id' => (string) $newsletter->id]
        );
    }

    private function switchMode(Newsletter $newsletter, int $attachmentId, string $mode): void
    {
        $request = $this->makeRequest(
            'POST',
            "/newsletters/{$newsletter->id}/attachments/{$attachmentId}/mode"
        )->withParsedBody(['delivery_mode' => $mode]);

        $this->controller()->updateAttachmentMode(
            $request,
            $this->makeResponse(),
            ['id' => (string) $newsletter->id, 'attachment_id' => (string) $attachmentId]
        );
    }

    private function deleteAttachment(Newsletter $newsletter, int $attachmentId): void
    {
        $this->controller()->deleteAttachment(
            $this->makeRequest('POST', "/newsletters/{$newsletter->id}/attachments/{$attachmentId}/delete"),
            $this->makeResponse(),
            ['id' => (string) $newsletter->id, 'attachment_id' => (string) $attachmentId]
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Attachment>
     */
    private function attachments(Newsletter $newsletter)
    {
        return Attachment::query()
            ->where('entity_type', NewsletterAttachmentService::ENTITY_TYPE)
            ->where('entity_id', $newsletter->id);
    }
}

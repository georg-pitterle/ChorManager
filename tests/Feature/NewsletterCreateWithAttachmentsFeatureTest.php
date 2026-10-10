<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Newsletter;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

/**
 * Dateien lassen sich schon beim Anlegen mitgeben: Wer den Entwurf erstellt, soll
 * nicht erst im Editor ein zweites Mal zum Datei-Feld wechseln müssen.
 */
final class NewsletterCreateWithAttachmentsFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testStoreKeepsFilesSentWithTheCreateForm(): void
    {
        $this->actAsManager();

        $response = $this->store(['probenplan.pdf' => 'kurz']);

        $this->assertSame(201, $response->getStatusCode());
        $newsletter = Newsletter::query()->where('title', 'Mit Datei')->firstOrFail();
        $attachment = Attachment::query()
            ->where('entity_type', 'newsletter')
            ->where('entity_id', $newsletter->id)
            ->firstOrFail();
        $this->assertSame('probenplan.pdf', $attachment->original_name);
        $this->assertSame('attach', $attachment->delivery_mode);
    }

    public function testStoreWithoutFilesCreatesNoAttachment(): void
    {
        $this->actAsManager();

        $this->store([]);

        $newsletter = Newsletter::query()->where('title', 'Mit Datei')->firstOrFail();
        $this->assertSame(
            0,
            Attachment::query()->where('entity_type', 'newsletter')->where('entity_id', $newsletter->id)->count()
        );
    }

    /**
     * Der Entwurf steht, auch wenn eine Datei abgelehnt wird - die Meldung kommt
     * als Warnung zurück, statt den Entwurf samt Text zu verwerfen.
     */
    public function testARejectedFileKeepsTheDraftAndReportsAWarning(): void
    {
        $this->actAsManager();

        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, 'x');
        $request = $this->makeRequest('POST', '/newsletters', [
            'title' => 'Mit Datei',
            'content_html' => '<p>Hallo</p>',
        ], [], ['Accept' => 'application/json'])->withUploadedFiles([
            'attachments' => [new UploadedFile($path, 'kaputt.pdf', 'application/pdf', 1, UPLOAD_ERR_PARTIAL)],
        ]);

        $response = $this->controller()->store($request, $this->makeResponse());

        $this->assertSame(201, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertNotEmpty($payload['warnings']);
        $this->assertSame(1, Newsletter::query()->where('title', 'Mit Datei')->count());
    }

    /**
     * @param array<string, string> $files Dateiname => Inhalt
     */
    private function store(array $files): \Psr\Http\Message\ResponseInterface
    {
        $uploads = [];
        foreach ($files as $name => $contents) {
            $path = tempnam(sys_get_temp_dir(), 'att');
            file_put_contents($path, $contents);
            $uploads[] = new UploadedFile($path, $name, 'application/pdf', strlen($contents), UPLOAD_ERR_OK);
        }

        $request = $this->makeRequest('POST', '/newsletters', [
            'title' => 'Mit Datei',
            'content_html' => '<p>Hallo</p>',
        ], [], ['Accept' => 'application/json']);

        if ($uploads !== []) {
            $request = $request->withUploadedFiles(['attachments' => $uploads]);
        }

        return $this->controller()->store($request, $this->makeResponse());
    }

    private function actAsManager(): void
    {
        $author = $this->createUser('Autor');
        $_SESSION = ['user_id' => (int) $author->id, 'can_manage_newsletters' => true];
    }
}

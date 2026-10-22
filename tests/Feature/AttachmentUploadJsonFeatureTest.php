<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SongLibraryController;
use App\Controllers\SponsorController;
use App\Controllers\TaskController;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Song;
use App\Models\Sponsor;
use App\Models\Task;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Ablegefläche schickt jede Datei einzeln und mit `Accept: application/json`.
 * Die Endpunkte antworten dann mit JSON statt Weiterleitung und Flash-Meldung -
 * die Rückmeldung steht an der Datei, nicht oben auf der neu geladenen Seite.
 * Ohne JavaScript bleibt alles beim Formular mit Weiterleitung.
 */
class AttachmentUploadJsonFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'first_name' => 'Ablage',
            'last_name' => 'Testperson',
            'email' => 'dropzone-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $_SESSION = [];
        parent::tearDown();
    }

    public function testSongJsonUploadStoresTheFileAndAnswersWithoutFlash(): void
    {
        $song = Song::create(['title' => 'Ablage ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['can_manage_song_library' => true];

        $response = $this->songController()->uploadAttachments(
            $this->jsonUpload('/song-library/songs/' . $song->id . '/attachments', $this->pdf('stimme-sopran.pdf')),
            $this->makeResponse(),
            ['id' => (string) $song->id]
        );

        $this->assertJsonResponse($response, 200, ['ok' => true, 'stored' => 1]);
        $this->assertSame(1, $this->attachmentCount(SongLibraryController::ENTITY_TYPE, (int) $song->id));
        $this->assertArrayNotHasKey('success', $_SESSION, 'Die Rückmeldung steht an der Datei.');
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testSongJsonUploadReportsARejectedFileAs422(): void
    {
        $song = Song::create(['title' => 'Ablage ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['can_manage_song_library' => true];

        $executable = $this->file('skript.exe', 'MZ binär', 'application/x-msdownload');
        $response = $this->songController()->uploadAttachments(
            $this->jsonUpload('/song-library/songs/' . $song->id . '/attachments', $executable),
            $this->makeResponse(),
            ['id' => (string) $song->id]
        );

        $payload = $this->assertJsonResponse($response, 422, ['ok' => false]);
        $this->assertNotSame('', (string) $payload['error']);
        $this->assertSame(0, $this->attachmentCount(SongLibraryController::ENTITY_TYPE, (int) $song->id));
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    /**
     * Über post_max_size verwirft PHP den ganzen Rumpf - es kommt keine Datei an.
     */
    public function testSongJsonUploadWithoutFileAnswers413(): void
    {
        $song = Song::create(['title' => 'Ablage ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['can_manage_song_library' => true];

        $request = $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/attachments', [], [], [
            'Accept' => 'application/json',
        ]);
        $response = $this->songController()->uploadAttachments($request, $this->makeResponse(), ['id' => (string) $song->id]);

        $payload = $this->assertJsonResponse($response, 413, ['ok' => false]);
        $this->assertStringContainsString('zu groß', (string) $payload['error']);
    }

    public function testSongJsonUploadForMissingSongAnswers404(): void
    {
        $_SESSION = ['can_manage_song_library' => true];

        $response = $this->songController()->uploadAttachments(
            $this->jsonUpload('/song-library/songs/999999/attachments', $this->pdf('x.pdf')),
            $this->makeResponse(),
            ['id' => '999999']
        );

        $this->assertJsonResponse($response, 404, ['ok' => false]);
    }

    public function testSongFormUploadStillRedirectsWithFlash(): void
    {
        $song = Song::create(['title' => 'Ablage ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['can_manage_song_library' => true];

        $request = $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/attachments')
            ->withUploadedFiles(['attachments' => [$this->pdf('noten.pdf')]]);
        $response = $this->songController()->uploadAttachments($request, $this->makeResponse(), ['id' => (string) $song->id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/song-library/' . $song->id, $response->getHeaderLine('Location'));
        $this->assertSame('Dateien erfolgreich hochgeladen.', $_SESSION['success'] ?? null);
    }

    public function testTaskJsonUploadStoresTheFileAndNamesItInTheActivity(): void
    {
        $task = $this->task();
        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_tasks' => true];

        $response = $this->taskController()->uploadAttachment(
            $this->jsonUpload('/tasks/' . $task->id . '/attachments', $this->pdf('protokoll.pdf')),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertJsonResponse($response, 200, ['ok' => true, 'stored' => 1]);
        $this->assertSame(1, $this->attachmentCount(TaskController::ENTITY_TYPE, (int) $task->id));
        $this->assertArrayNotHasKey('success', $_SESSION);

        // Je Datei eine Anfrage heißt je Datei ein Eintrag - dann soll er sagen, welche.
        $activity = Activity::where('entity_type', TaskController::ENTITY_TYPE)
            ->where('entity_id', (int) $task->id)
            ->where('action', 'attachment_added')
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame('Anhang „protokoll.pdf“ hinzugefügt.', $activity->description);
    }

    public function testTaskJsonUploadWithoutAccessAnswers403(): void
    {
        $task = $this->task();
        $_SESSION = ['user_id' => (int) $this->user->id];

        $response = $this->taskController()->uploadAttachment(
            $this->jsonUpload('/tasks/' . $task->id . '/attachments', $this->pdf('protokoll.pdf')),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertJsonResponse($response, 403, ['ok' => false]);
        $this->assertSame(0, $this->attachmentCount(TaskController::ENTITY_TYPE, (int) $task->id));
    }

    public function testTaskJsonUploadForMissingTaskAnswers404(): void
    {
        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_tasks' => true];

        $response = $this->taskController()->uploadAttachment(
            $this->jsonUpload('/tasks/999999/attachments', $this->pdf('protokoll.pdf')),
            $this->makeResponse(),
            ['id' => '999999']
        );

        $this->assertJsonResponse($response, 404, ['ok' => false]);
    }

    public function testTaskFormUploadStillRedirectsWithFlash(): void
    {
        $task = $this->task();
        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_tasks' => true];

        $request = $this->makeRequest('POST', '/tasks/' . $task->id . '/attachments')
            ->withUploadedFiles(['attachments' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]]);
        $response = $this->taskController()->uploadAttachment($request, $this->makeResponse(), ['id' => (string) $task->id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/tasks/' . $task->id, $response->getHeaderLine('Location'));
        $this->assertSame('Anhänge hochgeladen.', $_SESSION['success'] ?? null);
        $this->assertSame(
            '2 Anhang/Anhänge hinzugefügt.',
            Activity::where('entity_type', TaskController::ENTITY_TYPE)
                ->where('entity_id', (int) $task->id)
                ->value('description')
        );
    }

    public function testSponsorJsonUploadForMissingSponsorAnswers404(): void
    {
        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_sponsoring' => true];

        $response = $this->sponsorController()->uploadAttachment(
            $this->jsonUpload('/sponsoring/sponsors/999999/attachments', $this->pdf('logo.pdf')),
            $this->makeResponse(),
            ['id' => '999999']
        );

        $this->assertJsonResponse($response, 404, ['ok' => false]);
    }

    public function testSponsorFormUploadStillRedirectsWithFlash(): void
    {
        $sponsor = Sponsor::create(['name' => 'Ablage ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_sponsoring' => true];

        $request = $this->makeRequest('POST', '/sponsoring/sponsors/' . $sponsor->id . '/attachments')
            ->withUploadedFiles(['attachments' => [$this->pdf('logo.pdf')]]);
        $response = $this->sponsorController()->uploadAttachment($request, $this->makeResponse(), ['id' => (string) $sponsor->id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/sponsoring/sponsors/' . $sponsor->id, $response->getHeaderLine('Location'));
        $this->assertSame('Anhang erfolgreich hochgeladen.', $_SESSION['success'] ?? null);
    }

    public function testSponsorJsonUploadStoresTheFile(): void
    {
        $sponsor = Sponsor::create(['name' => 'Ablage ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_sponsoring' => true];

        $response = $this->sponsorController()->uploadAttachment(
            $this->jsonUpload('/sponsoring/sponsors/' . $sponsor->id . '/attachments', $this->pdf('logo.pdf')),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );

        $this->assertJsonResponse($response, 200, ['ok' => true, 'stored' => 1]);
        $this->assertSame(1, $this->attachmentCount(SponsorController::ENTITY_TYPE, (int) $sponsor->id));
        $this->assertArrayNotHasKey('success', $_SESSION);
    }

    public function testSponsorJsonUploadWithoutEditRightAnswers403(): void
    {
        $sponsor = Sponsor::create(['name' => 'Ablage ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['user_id' => (int) $this->user->id, 'can_create_own_sponsorships' => true];

        $response = $this->sponsorController()->uploadAttachment(
            $this->jsonUpload('/sponsoring/sponsors/' . $sponsor->id . '/attachments', $this->pdf('logo.pdf')),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );

        $this->assertJsonResponse($response, 403, ['ok' => false]);
        $this->assertSame(0, $this->attachmentCount(SponsorController::ENTITY_TYPE, (int) $sponsor->id));
    }

    /**
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    private function assertJsonResponse(ResponseInterface $response, int $status, array $expected): array
    {
        $this->assertSame($status, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $payload[$key] ?? null, $key);
        }

        return $payload;
    }

    private function jsonUpload(string $path, UploadedFile $file): ServerRequestInterface
    {
        return $this->makeRequest('POST', $path, [], [], ['Accept' => 'application/json'])
            ->withUploadedFiles(['attachments' => [$file]]);
    }

    private function pdf(string $name): UploadedFile
    {
        return $this->file($name, '%PDF-1.4 Testinhalt', 'application/pdf');
    }

    private function file(string $name, string $content, string $type): UploadedFile
    {
        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $name,
            $type,
            strlen($content),
            UPLOAD_ERR_OK
        );
    }

    private function attachmentCount(string $entityType, int $entityId): int
    {
        return Attachment::where('entity_type', $entityType)->where('entity_id', $entityId)->count();
    }

    private function task(): Task
    {
        $project = Project::create(['name' => 'Ablage ' . bin2hex(random_bytes(4))]);
        $project->users()->attach($this->user->id);

        return Task::create([
            'project_id' => $project->id,
            'name' => 'Ablageaufgabe',
            'status' => Task::STATUS_OPEN,
            'created_by' => $this->user->id,
        ]);
    }

    private function songController(): SongLibraryController
    {
        return new SongLibraryController($this->createStub(Twig::class), new Logger('test'));
    }

    private function taskController(): TaskController
    {
        return new TaskController(
            $this->createStub(Twig::class),
            new HtmlSanitizer(),
            new TaskPolicy($_SESSION),
            new NameFormatterService(),
            new Logger('test')
        );
    }

    private function sponsorController(): SponsorController
    {
        return new SponsorController(
            $this->createStub(Twig::class),
            new SponsoringPolicy($_SESSION),
            $this->attachmentService()
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SongLibraryController;
use App\Models\Attachment;
use App\Models\Song;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Wer die Noten eines Liedes ersetzt, soll nicht 24 Anhänge einzeln wegklicken. Die
 * Sammel-Route nimmt die IDs aus dem Formular entgegen - entscheidend ist deshalb,
 * dass sie nur Anhänge genau dieses Liedes trifft, egal was im Formular steht.
 */
class SongAttachmentBulkDeleteFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testDeletesOnlyTheSelectedAttachmentsOfTheSong(): void
    {
        $song = $this->makeSong();
        $first = $this->makeAttachment('song', (int) $song->id, 'Sopran.pdf');
        $second = $this->makeAttachment('song', (int) $song->id, 'Alt.pdf');
        $kept = $this->makeAttachment('song', (int) $song->id, 'Tenor.pdf');

        $response = $this->bulkDelete($song, ['attachment_ids' => [(string) $first->id, (string) $second->id]]);

        $this->assertSame([(int) $kept->id], $this->remainingIds('song', (int) $song->id));
        $this->assertSame('2 Anhänge erfolgreich gelöscht.', $_SESSION['success'] ?? null);
        $this->assertRedirectsToSong($response, $song);
    }

    public function testSingleSelectionReportsSingular(): void
    {
        $song = $this->makeSong();
        $attachment = $this->makeAttachment('song', (int) $song->id, 'Sopran.pdf');

        $this->bulkDelete($song, ['attachment_ids' => [(string) $attachment->id]]);

        $this->assertSame([], $this->remainingIds('song', (int) $song->id));
        $this->assertSame('Anhang erfolgreich gelöscht.', $_SESSION['success'] ?? null);
    }

    public function testAttachmentOfAnotherSongStaysUntouched(): void
    {
        $song = $this->makeSong();
        $otherSong = $this->makeSong();
        $own = $this->makeAttachment('song', (int) $song->id, 'Sopran.pdf');
        $foreign = $this->makeAttachment('song', (int) $otherSong->id, 'Fremd.pdf');

        $this->bulkDelete($song, ['attachment_ids' => [(string) $own->id, (string) $foreign->id]]);

        $this->assertSame([(int) $foreign->id], $this->remainingIds('song', (int) $otherSong->id));
        $this->assertSame('Anhang erfolgreich gelöscht.', $_SESSION['success'] ?? null);
    }

    public function testAttachmentOfOtherEntityTypeWithSameIdStaysUntouched(): void
    {
        // `attachments` ist polymorph: Projekt 7 und Lied 7 teilen sich die entity_id.
        $song = $this->makeSong();
        $foreign = $this->makeAttachment('project', (int) $song->id, 'Projektplan.pdf');

        $this->bulkDelete($song, ['attachment_ids' => [(string) $foreign->id]]);

        $this->assertSame([(int) $foreign->id], $this->remainingIds('project', (int) $song->id));
        $this->assertSame('Keiner der ausgewählten Anhänge wurde gefunden.', $_SESSION['error'] ?? null);
    }

    public function testEmptySelectionDeletesNothing(): void
    {
        $song = $this->makeSong();
        $kept = $this->makeAttachment('song', (int) $song->id, 'Sopran.pdf');

        $response = $this->bulkDelete($song, ['unrelated' => '1']);

        $this->assertSame([(int) $kept->id], $this->remainingIds('song', (int) $song->id));
        $this->assertSame('Keine Anhänge ausgewählt.', $_SESSION['error'] ?? null);
        $this->assertRedirectsToSong($response, $song);
    }

    public function testMalformedIdsDeleteNothing(): void
    {
        $song = $this->makeSong();
        $kept = $this->makeAttachment('song', (int) $song->id, 'Sopran.pdf');

        $this->bulkDelete($song, ['attachment_ids' => ['abc', '', '-1', '0']]);

        $this->assertSame([(int) $kept->id], $this->remainingIds('song', (int) $song->id));
        $this->assertArrayHasKey('error', $_SESSION);
    }

    public function testRouteIsRegisteredInsideTheSongLibraryGroup(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $groupStart = strpos($routes, "'/song-library',");
        $groupEnd = strpos($routes, "gates: ['requiresSongLibraryManagement']");

        $this->assertIsInt($groupStart);
        $this->assertIsInt($groupEnd);

        $group = substr($routes, $groupStart, $groupEnd - $groupStart);
        $this->assertStringContainsString("'/songs/{id:[0-9]+}/attachments/bulk-delete'", $group);
        $this->assertStringContainsString("[SongLibraryController::class, 'deleteAttachments']", $group);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function bulkDelete(Song $song, array $body): ResponseInterface
    {
        $controller = new SongLibraryController($this->createStub(Twig::class));

        return $controller->deleteAttachments(
            $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/attachments/bulk-delete', $body),
            $this->makeResponse(),
            ['id' => (string) $song->id]
        );
    }

    private function assertRedirectsToSong(ResponseInterface $response, Song $song): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/song-library/' . $song->id, $response->getHeaderLine('Location'));
    }

    /**
     * @return list<int>
     */
    private function remainingIds(string $entityType, int $entityId): array
    {
        return Attachment::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function makeSong(): Song
    {
        return Song::create(['title' => 'Sammellöschen-Test ' . bin2hex(random_bytes(4))]);
    }

    private function makeAttachment(string $entityType, int $entityId, string $name): Attachment
    {
        return Attachment::create([
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'filename'      => bin2hex(random_bytes(8)) . '_' . $name,
            'original_name' => $name,
            'mime_type'     => 'application/pdf',
            'file_size'     => 4,
            'file_content'  => 'Test',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\Song;
use App\Controllers\SongLibraryController;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Schreibwege der Liedbibliothek, durch den Controller hindurch.
 *
 * Sie laufen seit der Trennung über SongPersistence; hier steht, was der Controller
 * davon übergibt: nur gültige Kategorien, nur Anhänge des eigenen Liedes.
 */
final class SongLibraryWriteFlowFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
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

    private function controller(): SongLibraryController
    {
        return $this->songLibraryController(['view' => $this->createStub(Twig::class)]);
    }

    private function category(): Category
    {
        return Category::create(['name' => 'Kat ' . bin2hex(random_bytes(4)), 'sort_order' => 1]);
    }

    private function attachment(int $songId): Attachment
    {
        return Attachment::create([
            'entity_type' => SongLibraryController::ENTITY_TYPE,
            'entity_id' => $songId,
            'filename' => bin2hex(random_bytes(8)) . '_noten.pdf',
            'original_name' => 'noten.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 6,
            'file_content' => 'Inhalt',
        ]);
    }

    public function testCreatingASongKeepsOnlyValidDistinctCategories(): void
    {
        $category = $this->category();
        $title = 'Neu ' . bin2hex(random_bytes(3));

        $this->controller()->createSong(
            $this->makeRequest('POST', '/song-library/songs', [
                'title' => $title,
                'category_ids' => [(string) $category->id, '0', (string) $category->id],
            ]),
            $this->makeResponse()
        );

        $song = Song::where('title', $title)->firstOrFail();
        $this->assertSame([(int) $category->id], $song->categories()->pluck('repertoire_categories.id')->map('intval')->all());
    }

    public function testSyncingCategoriesReplacesThem(): void
    {
        $old = $this->category();
        $new = $this->category();
        $song = Song::create(['title' => 'Sync ' . bin2hex(random_bytes(3))]);
        $song->categories()->sync([(int) $old->id]);

        $this->controller()->syncCategories(
            $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/categories', [
                'category_ids' => [(string) $new->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $song->id]
        );

        $this->assertSame([(int) $new->id], $song->categories()->pluck('repertoire_categories.id')->map('intval')->all());
    }

    public function testUpdatingASongStoresTheFieldsAndEmptiesBecomeNull(): void
    {
        $song = Song::create(['title' => 'Alt', 'composer' => 'Bach']);

        $this->controller()->updateSong(
            $this->makeRequest('POST', '/song-library/songs/' . $song->id, [
                'title' => 'Neu ' . $song->id,
                'composer' => '  ',
                'arranger' => 'Reger',
            ]),
            $this->makeResponse(),
            ['id' => (string) $song->id]
        );

        $fresh = $song->fresh();
        $this->assertSame('Neu ' . $song->id, $fresh->title);
        $this->assertNull($fresh->composer);
        $this->assertSame('Reger', $fresh->arranger);
    }

    public function testDeletingOwnAttachmentRemovesIt(): void
    {
        $song = Song::create(['title' => 'Eigen ' . bin2hex(random_bytes(3))]);
        $attachment = $this->attachment((int) $song->id);

        $this->controller()->deleteAttachment(
            $this->makeRequest('POST', '/x'),
            $this->makeResponse(),
            ['song_id' => (string) $song->id, 'attachment_id' => (string) $attachment->id]
        );

        $this->assertSame('Anhang erfolgreich gelöscht.', $_SESSION['success'] ?? null);
        $this->assertNull(Attachment::find($attachment->id));
    }

    public function testAnAttachmentOfAnotherSongIsNotDeleted(): void
    {
        $songA = Song::create(['title' => 'A ' . bin2hex(random_bytes(3))]);
        $songB = Song::create(['title' => 'B ' . bin2hex(random_bytes(3))]);
        $foreign = $this->attachment((int) $songB->id);

        $this->controller()->deleteAttachment(
            $this->makeRequest('POST', '/x'),
            $this->makeResponse(),
            ['song_id' => (string) $songA->id, 'attachment_id' => (string) $foreign->id]
        );

        $this->assertSame('Anhang nicht gefunden.', $_SESSION['error'] ?? null);
        $this->assertNotNull(Attachment::find($foreign->id));
    }
}

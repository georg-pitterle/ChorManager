<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SongLibraryController;
use App\Models\Attachment;
use App\Models\Song;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class SongLibraryFeatureTest extends TestCase
{
    use TestHttpHelpers;

    public function testUploadAttachmentsLogsUploadRejectedForOversizedFileWithoutFilename(): void
    {
        Bootstrap::setupTestDatabase();

        $song = Song::create(['title' => 'Upload-Ablehnungs-Test ' . bin2hex(random_bytes(4))]);

        $handlerLog = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handlerLog);
        $controller = new SongLibraryController($this->createStub(Twig::class), $logger);

        $oversizedContent = str_repeat('x', (10 * 1024 * 1024) + 1);
        $stream = (new StreamFactory())->createStream($oversizedContent);
        $uploadedFile = new UploadedFile(
            $stream,
            'geheime-noten.pdf',
            'application/pdf',
            strlen($oversizedContent),
            UPLOAD_ERR_OK
        );

        $request = $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/attachments')
            ->withUploadedFiles(['attachments' => [$uploadedFile]]);

        try {
            $controller->uploadAttachments($request, $this->makeResponse(), ['id' => (string) $song->id]);

            $records = $handlerLog->getRecords();
            $match = array_values(array_filter(
                $records,
                static fn ($record): bool => ($record->context['event'] ?? null) === 'security.upload.rejected'
            ));

            $this->assertNotEmpty($match);
            $this->assertSame('size_exceeded', $match[0]->context['reason']);

            foreach ($records as $record) {
                $this->assertStringNotContainsString('geheime-noten', (string) json_encode($record->context));
            }
        } finally {
            $song->delete();
        }
    }

    public function testSongLibraryStructureExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\SongLibraryController::class));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'show'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'createSong'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'updateSong'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'deleteSong'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'uploadAttachments'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'deleteAttachment'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'syncCategories'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/song-library'", $routesContent);
        $this->assertStringContainsString("'/{id:[0-9]+}', [SongLibraryController::class, 'show']", $routesContent);
        $this->assertStringContainsString("'/songs/{id:[0-9]+}/attachments'", $routesContent);
        $this->assertStringContainsString(
            'new RoleMiddleware(requiresSongLibraryManagement: true)',
            $routesContent
        );
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/manage.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/detail.twig'));
    }

    /**
     * Geprüft wird das Verhalten, nicht der Quelltext.
     *
     * Vorher suchte dieser Test die Zeichenkette
     * "Attachment::where('entity_type', 'song')" in der Datei. Das hielt weder
     * fest, dass gelöscht wird, noch überlebte es die Umstellung des Literals auf
     * die Konstante ENTITY_TYPE - eine Änderung, die am Verhalten nichts dreht.
     */
    public function testSongDeleteAlsoRemovesAttachments(): void
    {
        Bootstrap::setupTestDatabase();

        $song = Song::create(['title' => 'Löschtest ' . bin2hex(random_bytes(4))]);
        $otherSong = Song::create(['title' => 'Bleibt ' . bin2hex(random_bytes(4))]);
        $attachment = $this->songAttachment((int) $song->id);
        $foreignAttachment = $this->songAttachment((int) $otherSong->id);

        $_SESSION = ['can_manage_song_library' => true];

        try {
            $this->controller()->deleteSong(
                $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/delete'),
                $this->makeResponse(),
                ['id' => (string) $song->id]
            );

            $this->assertNull(Song::find($song->id), 'Das Lied ist gelöscht.');
            $this->assertNull(Attachment::find($attachment->id), 'Sein Anhang ebenso.');
            $this->assertNotNull(
                Attachment::find($foreignAttachment->id),
                'Der Anhang eines anderen Liedes bleibt unberührt.'
            );
        } finally {
            Attachment::whereIn('id', [$attachment->id, $foreignAttachment->id])->delete();
            $otherSong->delete();
            $song->delete();
            $_SESSION = [];
        }
    }

    /**
     * Der Dateityp wird geprüft, *bevor* etwas geschrieben wird.
     *
     * Vorher las dieser Test die Reihenfolge der Anweisungen aus dem Quelltext ab -
     * detectMimeType, getContents, strlen, validateFileSize. Die Zeilen stehen seit
     * der Umstellung auf EntityAttachmentService nicht mehr im Controller, und
     * abgesichert war die Reihenfolge damit ohnehin nicht: Sie hätte sich ändern
     * lassen, ohne dass der Test etwas gemerkt hätte. Jetzt zählt das Ergebnis -
     * eine Datei mit unerlaubtem Typ landet nicht in der Tabelle.
     */
    public function testSongUploadValidatesDeclaredMimeBeforePersisting(): void
    {
        Bootstrap::setupTestDatabase();

        $song = Song::create(['title' => 'Typtest ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['can_manage_song_library' => true];

        $content = '<?php echo "kein Notenblatt";';
        $uploadedFile = new UploadedFile(
            (new StreamFactory())->createStream($content),
            'noten.php',
            'application/x-php',
            strlen($content),
            UPLOAD_ERR_OK
        );

        try {
            $this->controller()->uploadAttachments(
                $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/attachments')
                    ->withUploadedFiles(['attachments' => [$uploadedFile]]),
                $this->makeResponse(),
                ['id' => (string) $song->id]
            );

            $this->assertSame(
                0,
                Attachment::where('entity_type', SongLibraryController::ENTITY_TYPE)
                    ->where('entity_id', (int) $song->id)
                    ->count(),
                'Eine Datei mit unerlaubtem Typ darf nicht in der Tabelle landen.'
            );
            $this->assertNotNull($_SESSION['error'] ?? null, 'Die Ablehnung wird gemeldet.');
        } finally {
            Attachment::where('entity_type', SongLibraryController::ENTITY_TYPE)
                ->where('entity_id', (int) $song->id)
                ->delete();
            $song->delete();
            $_SESSION = [];
        }
    }

    /**
     * Eine gültige Datei landet unter dem Lied - mit Namen gekürzt auf die
     * Spaltenbreite. Ohne diesen Fall blieb die Suite grün, selbst wenn der Anhang
     * unter einem falschen `entity_type` gelandet wäre.
     */
    public function testSongUploadStoresTheAttachmentUnderTheSong(): void
    {
        Bootstrap::setupTestDatabase();

        $song = Song::create(['title' => 'Ablagetest ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['can_manage_song_library' => true];

        $longName = str_repeat('Partitur-', 40) . '.pdf';
        $content = '%PDF-1.4 Testinhalt';
        $uploadedFile = new UploadedFile(
            (new StreamFactory())->createStream($content),
            $longName,
            'application/pdf',
            strlen($content),
            UPLOAD_ERR_OK
        );

        try {
            $this->controller()->uploadAttachments(
                $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/attachments')
                    ->withUploadedFiles(['attachments' => [$uploadedFile]]),
                $this->makeResponse(),
                ['id' => (string) $song->id]
            );

            $stored = Attachment::where('entity_type', SongLibraryController::ENTITY_TYPE)
                ->where('entity_id', (int) $song->id)
                ->get();

            $this->assertCount(1, $stored, 'Der Anhang muss unter dem Lied liegen: '
                . ($_SESSION['error'] ?? 'kein Fehler'));

            $attachment = $stored->first();
            $this->assertLessThanOrEqual(255, mb_strlen((string) $attachment->filename));
            $this->assertLessThanOrEqual(255, mb_strlen((string) $attachment->original_name));
            $this->assertStringEndsWith('.pdf', (string) $attachment->original_name);
            $this->assertSame('application/pdf', (string) $attachment->mime_type);
            $this->assertSame(strlen($content), (int) $attachment->file_size);
        } finally {
            Attachment::where('entity_type', SongLibraryController::ENTITY_TYPE)
                ->where('entity_id', (int) $song->id)
                ->delete();
            $song->delete();
            $_SESSION = [];
        }
    }

    /**
     * Eine beanstandete Datei bricht den Lauf nicht mehr ab.
     *
     * Vorher kehrte persistAttachments() beim ersten beanstandeten Anhang zurück:
     * die davor lagen, waren schon geschrieben, die danach nie. Wer fünf Noten
     * wählt und bei der dritten die Größe reißt, hatte hinterher zwei gespeichert
     * und keine Ahnung, welche fehlen.
     */
    public function testAValidFileAfterARejectedOneIsStillStored(): void
    {
        Bootstrap::setupTestDatabase();

        $song = Song::create(['title' => 'Reihentest ' . bin2hex(random_bytes(4))]);
        $_SESSION = ['can_manage_song_library' => true];

        $good = '%PDF-1.4 gueltig';

        try {
            $this->controller()->uploadAttachments(
                $this->makeRequest('POST', '/song-library/songs/' . $song->id . '/attachments')
                    ->withUploadedFiles(['attachments' => [
                        new UploadedFile(
                            (new StreamFactory())->createStream('<?php'),
                            'abgelehnt.php',
                            'application/x-php',
                            5,
                            UPLOAD_ERR_OK
                        ),
                        new UploadedFile(
                            (new StreamFactory())->createStream($good),
                            'danach.pdf',
                            'application/pdf',
                            strlen($good),
                            UPLOAD_ERR_OK
                        ),
                    ]]),
                $this->makeResponse(),
                ['id' => (string) $song->id]
            );

            $stored = Attachment::where('entity_type', SongLibraryController::ENTITY_TYPE)
                ->where('entity_id', (int) $song->id)
                ->pluck('original_name')
                ->all();

            $this->assertSame(['danach.pdf'], $stored);
            $this->assertNotNull($_SESSION['error'] ?? null, 'Die Ablehnung wird trotzdem gemeldet.');
        } finally {
            Attachment::where('entity_type', SongLibraryController::ENTITY_TYPE)
                ->where('entity_id', (int) $song->id)
                ->delete();
            $song->delete();
            $_SESSION = [];
        }
    }

    private function controller(): SongLibraryController
    {
        return new SongLibraryController($this->createStub(Twig::class), new Logger('test'));
    }

    private function songAttachment(int $songId): Attachment
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

    public function testDevSeedServiceSeedsSongsAndSongAttachments(): void
    {
        $seedContent = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seedContent);
        $this->assertStringContainsString("'songs'", $seedContent);
        $this->assertStringContainsString('$categories = $this->seedCategories();', $seedContent);
        $this->assertStringContainsString('$songs = $this->seedSongs($users[\'active\']);', $seedContent);
        $this->assertStringContainsString('$this->seedProjectSongAssignments($songs, $projects);', $seedContent);
        $this->assertStringContainsString('$this->seedSongAttachments($songs, 48);', $seedContent);
    }

    public function testDevSeedServiceSeedsSongLinkResources(): void
    {
        $seedContent = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seedContent);
        $this->assertStringContainsString("'song_link_resources' => 0", $seedContent);
        $this->assertStringContainsString("'song_resources'", $seedContent);
        $this->assertStringContainsString('$this->seedSongLinkResources($songs, 24);', $seedContent);
    }

    public function testDevSeedServiceSeedsSheetArchives(): void
    {
        $seedContent = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seedContent);
        $this->assertStringContainsString("'sheet_archives' => 0", $seedContent);
        $this->assertStringContainsString("'sheet_archive_line_items' => 0", $seedContent);
        $this->assertStringContainsString('$this->seedSheetArchives($songs);', $seedContent);
        $this->assertStringContainsString('private function seedSheetArchives(array $songs): void', $seedContent);
        $this->assertStringContainsString('SheetArchive::updateOrCreate(', $seedContent);
        $this->assertStringContainsString('SheetArchiveLineItem::create([', $seedContent);
    }

    public function testCreateRouteAndMethodExist(): void
    {
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'create'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/create'", $routesContent);
        $this->assertStringContainsString("[SongLibraryController::class, 'create']", $routesContent);
    }

    public function testCreateTwigTemplateExists(): void
    {
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/create.twig'));
    }

    public function testCreateSongRedirectsToDetailPageOnSuccess(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("'/song-library/' . \$song->id", $controllerContent);
    }

    public function testCreateSongHandlesCategoryIdsOnCreation(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("'category_ids'", $controllerContent);
        $this->assertStringContainsString('$song->categories()->sync($categoryIds)', $controllerContent);
    }

    public function testCreateSongHandlesAttachmentsOnCreation(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('$this->persistAttachments((int) $song->id,', $controllerContent);
    }

    public function testCreateSongErrorRedirectsToCreatePage(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("'/song-library/create'", $controllerContent);
    }

    public function testManageTwigNoLongerContainsAddSongModal(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/songs/manage.twig');
        $this->assertIsString($content);
        $this->assertStringNotContainsString('addSongModal', $content);
        $this->assertStringNotContainsString('id="addSongModal"', $content);
    }

    public function testManageTwigRendersRepertoireAsTableEngineList(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/songs/manage.twig');

        $this->assertIsString($content);
        $this->assertStringContainsString('data-table-engine="true"', $content);
        $this->assertStringContainsString('data-table-id="songs.manage"', $content);
        $this->assertStringContainsString('id="songsTable"', $content);
        $this->assertStringContainsString("include('partials/table_toolbar.twig')", $content);
        $this->assertStringNotContainsString('dashboard-action-grid', $content);
        $this->assertStringNotContainsString('dashboard-panel--action', $content);
    }

    public function testAreasNavigationUsesRepertoireLabel(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Navigation/NavigationBuilder.php');

        $this->assertIsString($content);
        $this->assertStringContainsString("'label' => 'Repertoire',", $content);
        $this->assertStringNotContainsString('Liedbibliothek', $content);
    }

    public function testUploadAttachmentsUsesSharedPersistMethod(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString(
            'private function persistAttachments(int $songId, array $files): ?string',
            $controllerContent
        );
        $this->assertStringContainsString('$this->persistAttachments($songId, $files)', $controllerContent);
    }

    public function testDetailTemplateGuardsArchiveSectionByFeatureFlagAndPermission(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/songs/detail.twig');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            '/\{\% if settings\\.modules\\.sheet_archive and session\\.can_manage_sheet_archive \%\}[\s\S]*id="song-archive-title"[\s\S]*\{\% endif \%\}/',
            $content
        );
    }
}

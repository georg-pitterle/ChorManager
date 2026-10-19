<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Concerns\HasEntityAttachments;
use App\Models\Project;
use App\Models\Song;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Beziehung `attachments()` lädt nie den Datei-Inhalt.
 *
 * Anlass war das Repertoire im Live-Betrieb: Nach dem Hochladen von 24 Dateien
 * mit zusammen rund 100 MB antwortete `/song-library` nur noch mit einem leeren
 * 500. Die Übersicht lud die Anhänge aller Lieder über die Beziehung - und damit
 * per `select *` jeden BLOB, für die Projektliste derselben Seite gleich ein
 * zweites Mal. PHP lief ins Speicherlimit, ein Fatal Error, der an Monolog
 * vorbeigeht; deshalb stand auch nichts im Log.
 *
 * Seitdem wählt die Beziehung selbst die Metadaten-Spalten. Wer den Inhalt
 * braucht, holt ihn gezielt über eine eigene Abfrage (`findWithContent()`,
 * `WebdavTreeService::loadFile()`, `NewsletterAttachmentService::attachedFiles()`).
 */
final class AttachmentRelationBlobLoadFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function modelsWithAttachmentsProvider(): array
    {
        $cases = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (class_exists($class) && in_array(HasEntityAttachments::class, class_uses($class), true)) {
                $cases[basename($file, '.php')] = [$class];
            }
        }

        return $cases;
    }

    /**
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelsWithAttachmentsProvider')]
    public function testTheRelationSelectsMetadataColumnsOnly(string $class): void
    {
        $sql = (new $class())->attachments()->toSql();

        $this->assertStringNotContainsString('*', $sql, 'Ein select * holt den BLOB mit: ' . $sql);
        $this->assertStringNotContainsString('file_content', $sql, 'Der Inhalt gehört nicht in die Beziehung: ' . $sql);
    }

    public function testEagerLoadingSongAttachmentsSkipsTheContent(): void
    {
        $song = $this->songWithAttachment();

        $statements = $this->attachmentStatementsDuring(function () use ($song): void {
            $loaded = Song::with(['attachments' => fn ($query) => $query->orderBy('original_name', 'asc')])
                ->findOrFail($song->id);

            $attachment = $loaded->attachments->first();
            $this->assertInstanceOf(Attachment::class, $attachment);
            $this->assertSame('Sopran.mp3', $attachment->original_name, 'Die Liste braucht den Namen weiterhin.');
            $this->assertSame(600000, (int) $attachment->file_size, 'Die Liste braucht die Größe weiterhin.');
            $this->assertArrayNotHasKey('file_content', $attachment->getAttributes());
        });

        $this->assertNoBlobSelect($statements);
    }

    public function testEagerLoadingProjectSongAttachmentsSkipsTheContent(): void
    {
        $song = $this->songWithAttachment();
        $project = Project::create(['name' => 'Blob-Projekt']);
        $project->assignedSongs()->attach($song->id);

        $statements = $this->attachmentStatementsDuring(function () use ($project): void {
            $loaded = Project::with('assignedSongs.attachments')->findOrFail($project->id);

            $attachment = $loaded->assignedSongs->first()?->attachments->first();
            $this->assertInstanceOf(Attachment::class, $attachment);
            $this->assertArrayNotHasKey('file_content', $attachment->getAttributes());
        });

        $this->assertNoBlobSelect($statements);
    }

    public function testTargetedLookupStillReturnsTheContent(): void
    {
        $song = $this->songWithAttachment();
        $id = (int) $song->attachments()->firstOrFail()->id;

        $attachment = Attachment::where('entity_type', Song::ENTITY_TYPE)->find($id);

        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->assertSame(600000, strlen((string) $attachment->file_content));
    }

    private function songWithAttachment(): Song
    {
        $song = Song::create(['title' => 'Blob-Lied']);
        // Bewusst kein Kurzinhalt: Genau die Größe ist der Grund, warum der BLOB
        // in keine Übersicht gehört.
        $content = str_repeat('ID3', 200000);

        Attachment::create([
            'entity_type' => Song::ENTITY_TYPE,
            'entity_id' => $song->id,
            'filename' => bin2hex(random_bytes(16)) . '_Sopran.mp3',
            'original_name' => 'Sopran.mp3',
            'mime_type' => 'audio/mpeg',
            'file_size' => strlen($content),
            'file_content' => $content,
        ]);

        return $song;
    }

    /**
     * @return list<string>
     */
    private function attachmentStatementsDuring(callable $run): array
    {
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $run();
            $log = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return array_values(array_filter(
            array_map(static fn (array $entry): string => (string) ($entry['query'] ?? ''), $log),
            static fn (string $sql): bool => str_contains($sql, 'from `attachments`')
        ));
    }

    /**
     * @param list<string> $statements
     */
    private function assertNoBlobSelect(array $statements): void
    {
        $this->assertNotSame([], $statements, 'Ohne Anhang-Abfrage prüft der Test nichts.');

        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('*', $sql, 'Eine Abfrage ohne Spaltenliste holt den BLOB mit: ' . $sql);
            $this->assertStringNotContainsString('file_content', $sql, 'Der Inhalt gehört in keine Übersicht: ' . $sql);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AttachmentController;
use App\Models\Attachment;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use App\Services\AttachmentResponseFactory;
use App\Services\EntityAttachmentService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Die zentrale Auslieferung. Geprüft wird, was an der alten, verstreuten
 * Fassung nicht prüfbar war: dass fehlendes Recht und fehlender Datensatz
 * dieselbe Antwort geben, und dass ein nicht darstellbarer Typ auf der
 * Vorschau-Route abgewiesen wird statt zum Download zu werden.
 */
final class AttachmentAccessFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** @var list<int> */
    private array $createdAttachmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->createdAttachmentIds !== []) {
            Attachment::whereIn('id', $this->createdAttachmentIds)->delete();
            $this->createdAttachmentIds = [];
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function createAttachment(string $entityType, string $mime, string $name): Attachment
    {
        $content = 'Inhalt für ' . $name;

        $attachment = Attachment::create([
            'entity_type'   => $entityType,
            'entity_id'     => 424242,
            'filename'      => 'seed_' . $name,
            'original_name' => $name,
            'mime_type'     => $mime,
            'file_size'     => strlen($content),
            'file_content'  => $content,
        ]);

        $this->createdAttachmentIds[] = (int) $attachment->id;

        return $attachment;
    }

    private function makeController(): AttachmentController
    {
        $registry = new AttachmentAccessRegistry(
            new SponsoringPolicy(),
            new TaskPolicy(),
            ['finance' => true, 'sponsoring' => true, 'tasks' => true]
        );

        return new AttachmentController($registry, new AttachmentResponseFactory(), new NullLogger());
    }

    public function testTaskAttachmentDownloadForPermittedUser(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment('task', 'application/pdf', 'protokoll.pdf');

        $response = $this->makeController()->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('attachment; ', $response->getHeaderLine('Content-Disposition'));
    }

    public function testTaskAttachmentPreviewForPermittedUser(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment('task', 'application/pdf', 'protokoll.pdf');

        $response = $this->makeController()->preview(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/preview'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('inline; ', $response->getHeaderLine('Content-Disposition'));
    }

    public function testWithoutPermissionBothRoutesAnswerNotFound(): void
    {
        $attachment = $this->createAttachment('task', 'application/pdf', 'protokoll.pdf');
        $controller = $this->makeController();

        $download = $controller->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );
        $preview = $controller->preview(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/preview'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(404, $download->getStatusCode());
        $this->assertSame(404, $preview->getStatusCode());
    }

    public function testMissingAttachmentAnswersNotFound(): void
    {
        $_SESSION['can_manage_tasks'] = true;

        $response = $this->makeController()->download(
            $this->makeRequest('GET', '/attachments/99999999/download'),
            $this->makeResponse(),
            ['id' => '99999999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPreviewRejectsNonServableMimeType(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment(
            'task',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'konzept.docx'
        );

        $response = $this->makeController()->preview(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/preview'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testDownloadAcceptsNonServableMimeType(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment(
            'task',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'konzept.docx'
        );

        $response = $this->makeController()->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testFinanceAttachmentNeedsFinancePermission(): void
    {
        $attachment = $this->createAttachment('finance', 'text/plain', 'beleg.txt');
        $controller = $this->makeController();

        $denied = $controller->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );
        $this->assertSame(404, $denied->getStatusCode());

        $_SESSION['can_read_finances'] = true;
        $allowed = $controller->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );
        $this->assertSame(200, $allowed->getStatusCode());
    }

    public function testPreviewPassesRangeHeaderThrough(): void
    {
        $_SESSION['can_manage_song_library'] = true;
        $attachment = $this->createAttachment('song', 'audio/mpeg', 'probe.mp3');

        $response = $this->makeController()->preview(
            $this->makeRequest(
                'GET',
                '/attachments/' . $attachment->id . '/preview',
                [],
                [],
                ['Range' => 'bytes=0-3']
            ),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('4', $response->getHeaderLine('Content-Length'));
    }

    /**
     * Vertrag aus AttachmentAccessRegistry::mayAccess(): die übergebene
     * Kennung muss aus der Sitzung stammen, nie aus der Anfrage. Ein Mock von
     * AttachmentAccessRegistry scheidet aus - die Klasse ist `final`, und
     * PHPUnit weigert sich, eine `final`-Klasse zu doubeln. Beobachtet wird
     * deshalb an einer anderen, bereits vorhandenen Stelle: bei
     * `entity_type=song` landet `$userId` unverändert als zweite Bindung in
     * der project_song_assignments-Abfrage, die `maySeeSong()` ausführt. Das
     * Abfrage-Protokoll zeigt damit unmittelbar, welche Kennung
     * `authorize()` tatsächlich weitergereicht hat.
     *
     * Die Routen-Kennung (die Anhang-Id) bekommt bewusst eine andere Zahl als
     * die Sitzungs-Kennung: eine Verwechslung der beiden Werte wird dadurch
     * sichtbar, statt zufällig unbemerkt zu bleiben.
     */
    public function testAuthorizeReadsUserIdFromSessionNotFromRequest(): void
    {
        $_SESSION['user_id'] = 555444;
        $attachment = $this->createAttachment('song', 'audio/mpeg', 'probe.mp3');

        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->makeController()->download(
                $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
                $this->makeResponse(),
                ['id' => (string) $attachment->id]
            );

            $songAssignmentQueries = array_values(array_filter(
                $connection->getQueryLog(),
                static fn (array $entry): bool => str_contains($entry['query'], 'project_song_assignments')
            ));
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $this->assertCount(
            1,
            $songAssignmentQueries,
            'Die Zugriffsprüfung für song-Anhänge muss genau einmal laufen.'
        );
        // entity_id des Test-Anhangs (Zeile in createAttachment()), gefolgt von der
        // tatsächlich übergebenen Nutzer-Kennung.
        $this->assertSame([424242, 555444], $songAssignmentQueries[0]['bindings']);
    }

    /**
     * Vertrag aus AttachmentController::authorize(): erst nur die
     * Metadaten-Spalten laden, dann entscheiden, und den vollen Datensatz
     * (mit `file_content`) erst danach - und nur bei erteiltem Zugriff -
     * nachladen. Ebenfalls über das Abfrage-Protokoll beobachtet, aus
     * demselben Grund wie oben: AttachmentAccessRegistry lässt sich nicht
     * doubeln.
     */
    public function testAuthorizeLoadsMetadataOnlyBeforeDecidingAccess(): void
    {
        $attachment = $this->createAttachment('task', 'application/pdf', 'ordnung.pdf');

        $connection = Capsule::connection();
        $connection->enableQueryLog();

        try {
            $connection->flushQueryLog();
            $denied = $this->makeController()->download(
                $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
                $this->makeResponse(),
                ['id' => (string) $attachment->id]
            );
            $deniedQueries = $this->attachmentTableQueries($connection);

            // TaskPolicy liest can_manage_tasks im eigenen Konstruktor - der
            // wiederverwendete Controller von oben trüge weiterhin die zuerst
            // gelesene Verweigerung, deshalb hier ein frischer.
            $_SESSION['can_manage_tasks'] = true;
            $connection->flushQueryLog();
            $allowed = $this->makeController()->download(
                $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
                $this->makeResponse(),
                ['id' => (string) $attachment->id]
            );
            $allowedQueries = $this->attachmentTableQueries($connection);
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $this->assertSame(404, $denied->getStatusCode());
        $this->assertCount(1, $deniedQueries, 'Ohne Recht darf nur die Metadaten-Abfrage laufen.');
        $this->assertMetadataOnlyQuery($deniedQueries[0]);

        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertCount(
            2,
            $allowedQueries,
            'Der volle Inhalt darf erst nach der Rechteentscheidung geladen werden.'
        );
        $this->assertMetadataOnlyQuery($allowedQueries[0]);
        // Die zweite Abfrage ist der uneingeschränkte Nachlader (`Attachment::find()`) -
        // sie listet die Spalten nicht einzeln auf, anders als die Metadaten-Abfrage.
        $this->assertStringNotContainsString('`filename`', $allowedQueries[1]['query']);
    }

    /**
     * Die Typprüfung der Vorschau muss vor dem Nachladen des Inhalts stehen.
     * Stünde sie danach, zöge eine abgelehnte Vorschau erst den ganzen BLOB
     * durch den Speicher, um ihn mit 415 zu verwerfen - bei einem
     * Word-Dokument von zehn Megabyte genau der Fall, den die Reihenfolge in
     * authorize() für die Rechteentscheidung längst vermeidet.
     */
    public function testPreviewRejectsNonServableTypeWithoutLoadingTheContent(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment(
            'task',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'konzept.docx'
        );

        $connection = Capsule::connection();
        $connection->enableQueryLog();

        try {
            $connection->flushQueryLog();
            $rejected = $this->makeController()->preview(
                $this->makeRequest('GET', '/attachments/' . $attachment->id . '/preview'),
                $this->makeResponse(),
                ['id' => (string) $attachment->id]
            );
            $queries = $this->attachmentTableQueries($connection);
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $this->assertSame(415, $rejected->getStatusCode());
        $this->assertCount(
            1,
            $queries,
            'Ein nicht darstellbarer Typ darf nur die Metadaten-Abfrage auslösen.'
        );
        $this->assertMetadataOnlyQuery($queries[0]);
    }

    /**
     * @return list<array{query: string, bindings: list<mixed>, time: float|null}>
     */
    private function attachmentTableQueries(Connection $connection): array
    {
        return array_values(array_filter(
            $connection->getQueryLog(),
            static fn (array $entry): bool => str_contains($entry['query'], '`attachments`')
        ));
    }

    /**
     * @param array{query: string, bindings: list<mixed>, time: float|null} $entry
     */
    private function assertMetadataOnlyQuery(array $entry): void
    {
        foreach (EntityAttachmentService::METADATA_COLUMNS as $column) {
            $this->assertStringContainsString('`' . $column . '`', $entry['query']);
        }
        $this->assertStringNotContainsString('file_content', $entry['query']);
    }

    public function testRoutesAreRegistered(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString("'/attachments/{id:[0-9]+}/preview'", $routes);
        $this->assertStringContainsString("'/attachments/{id:[0-9]+}/download'", $routes);
    }
}

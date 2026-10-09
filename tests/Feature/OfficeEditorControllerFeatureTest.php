<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FileBrowserController;
use App\Controllers\FileDetailController;
use App\Controllers\OfficeEditorController;
use App\Models\FileFolderShare as Share;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileActor;
use App\Services\Files\FileService;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use DI\Container;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Bootstrap;

/**
 * Die Editor-Seite stellt das Token aus und schickt es per Formular in den
 * Collabora-Rahmen; Detail- und Ordnerseite bieten die Bearbeitung je nach Stufe an.
 */
class OfficeEditorControllerFeatureTest extends TestCase
{
    use FileFixtures;
    use OfficeFixtures;
    use TestHttpHelpers;

    private Container $container;
    private ?string $previousStoragePath = null;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $previous = getenv('FILES_STORAGE_PATH');
        $this->previousStoragePath = $previous === false ? null : $previous;
        putenv('FILES_STORAGE_PATH=' . $this->storageDir);
        $_ENV['FILES_STORAGE_PATH'] = $this->storageDir;
        $_SERVER['FILES_STORAGE_PATH'] = $this->storageDir;
        $this->container = $this->buildContainer(true);
    }

    protected function tearDown(): void
    {
        if ($this->previousStoragePath === null) {
            putenv('FILES_STORAGE_PATH');
            unset($_ENV['FILES_STORAGE_PATH'], $_SERVER['FILES_STORAGE_PATH']);
        } else {
            putenv('FILES_STORAGE_PATH=' . $this->previousStoragePath);
            $_ENV['FILES_STORAGE_PATH'] = $this->previousStoragePath;
            $_SERVER['FILES_STORAGE_PATH'] = $this->previousStoragePath;
        }
        $this->tearDownOfficeFixtures();
        $this->tearDownFileFixtures();
    }

    /**
     * @param (\Closure(string): ?string)|null $fetch
     */
    private function buildContainer(bool $officeEnabled, ?\Closure $fetch = null): Container
    {
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $container = $builder->build();
        $container->set(Capsule::class, Bootstrap::getCapsule());
        $settings = $container->get('settings');
        $settings['modules']['files'] = true;
        $settings['modules']['office'] = $officeEnabled;
        $container->set('settings', $settings);
        $container->set(OfficeSettings::class, $this->officeSettings());
        $container->set(OfficeDiscovery::class, $this->discovery($fetch, null, $fetch !== null));

        return $container;
    }

    private function login(int $userId): void
    {
        $_SESSION = ['user_id' => $userId, 'can_manage_files' => false];
    }

    /** @return array{0: User, 1: StoredFile} */
    private function sharedFile(int $level, string $name = 'Protokoll.odt'): array
    {
        $member = $this->createMember('Erika');
        $root = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $this->share($root, 'user', (int) $member->id, $level);
        $upload = new UploadedFile((new StreamFactory())->createStream('Stand 1'), $name, 'application/octet-stream', 7);
        $file = $this->container->get(FileService::class)
            ->upload(new FileActor((int) $member->id, true), $root, $upload)->file;

        return [$member, $file];
    }

    public function testEditorPagePostsTokenIntoCollaboraFrame(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);

        $response = $controller->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ]);
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'action="https://office.example.test/browser/abc123/cool.html?WOPISrc=http%3A%2F%2Fweb%2Fwopi%2Ffiles%2F' . $file->id,
            $html
        );
        $this->assertStringContainsString('target="officeEditorFrame"', $html);
        $this->assertStringContainsString('data-office-origin="https://office.example.test"', $html);
        $this->assertStringContainsString('title="Dokument bearbeiten: Protokoll.odt"', $html);
        $this->assertMatchesRegularExpression('/name="access_token_ttl" value="\d{13}"/', $html);

        $this->assertSame(1, preg_match('/name="access_token" value="([A-Za-z0-9_-]{43})"/', $html, $match));
        $this->assertNotNull($this->container->get(OfficeTokenService::class)->resolve($match[1], (int) $file->id));
    }

    public function testReaderGetsViewOnlyPage(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_READ);
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);

        $html = (string) $controller->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ])->getBody();

        $this->assertStringContainsString('title="Dokument ansehen: Protokoll.odt"', $html);
    }

    public function testStrangerIsSentBackToFiles(): void
    {
        [, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $stranger = $this->createMember('Fremd');
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $stranger->id);

        $response = $controller->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ]);

        $this->assertRedirect($response, '/files');
    }

    public function testUnsupportedTypeAndUnreachableServerLeadBackToFile(): void
    {
        [$member, $audio] = $this->sharedFile(Share::LEVEL_EDIT, 'Einsingen.mp3');
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);
        $response = $controller->edit($this->makeRequest('GET', '/files/' . $audio->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $audio->id,
        ]);
        $this->assertRedirect($response, '/files/' . $audio->id);
        $this->assertStringContainsString('Dateityp', (string) ($_SESSION['error'] ?? ''));

        [$editor, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $down = $this->buildContainer(true, static fn (string $url): ?string => null);
        $downController = $down->get(OfficeEditorController::class);
        $this->login((int) $editor->id);
        $response = $downController->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ]);
        $this->assertRedirect($response, '/files/' . $file->id);
        $this->assertStringContainsString('nicht erreichbar', (string) ($_SESSION['error'] ?? ''));
    }

    public function testFileAndFolderPagesOfferBrowserEditingByLevel(): void
    {
        [$editor, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        [$reader, $readable] = $this->sharedFile(Share::LEVEL_READ);
        [$audioOwner, $audio] = $this->sharedFile(Share::LEVEL_EDIT, 'Einsingen.mp3');
        $detail = $this->container->get(FileDetailController::class);
        $browser = $this->container->get(FileBrowserController::class);

        $this->login((int) $editor->id);
        $html = (string) $detail->show($this->makeRequest('GET', '/files/' . $file->id), $this->makeResponse(), [
            'id' => (string) $file->id,
        ])->getBody();
        $this->assertStringContainsString('href="/files/' . $file->id . '/edit"', $html);
        $this->assertStringContainsString('Im Browser bearbeiten', $html);

        $folderHtml = (string) $browser->folder(
            $this->makeRequest('GET', '/files/folders/' . $file->folder_id),
            $this->makeResponse(),
            ['id' => (string) $file->folder_id]
        )->getBody();
        $this->assertStringContainsString('href="/files/' . $file->id . '/edit?from=folder"', $folderHtml);

        $this->login((int) $reader->id);
        $html = (string) $detail->show($this->makeRequest('GET', '/files/' . $readable->id), $this->makeResponse(), [
            'id' => (string) $readable->id,
        ])->getBody();
        $this->assertStringContainsString('Im Browser ansehen', $html);

        $this->login((int) $audioOwner->id);
        $html = (string) $detail->show($this->makeRequest('GET', '/files/' . $audio->id), $this->makeResponse(), [
            'id' => (string) $audio->id,
        ])->getBody();
        $this->assertStringNotContainsString('/files/' . $audio->id . '/edit', $html);
    }

    public function testEditorFillsTheWholePageWithoutAppNavigation(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);

        $html = (string) $controller->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ])->getBody();

        $this->assertStringContainsString('<body class="office-editor-page">', $html);
        $this->assertStringContainsString('<title>Protokoll.odt', $html);
        $this->assertStringNotContainsString('app-topbar', $html, 'Wie in Nextcloud: keine Kopfzeile der App.');
        $this->assertStringNotContainsString('app-sidebar', $html, 'Wie in Nextcloud: keine Seitenleiste.');
        $this->assertStringContainsString('class="office-editor-frame"', $html);
    }

    /**
     * Wer aus der Ordnerliste öffnet, landet beim Schließen wieder dort - nicht auf
     * der Detailseite. Ohne Zugriff auf den Ordner (nur Dateifreigabe) bleibt es
     * bei der Detailseite.
     */
    public function testClosingReturnsToWhereTheEditorWasOpened(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);
        $open = fn (array $query) => (string) $controller->edit(
            $this->makeRequest('GET', '/files/' . $file->id . '/edit', [], $query),
            $this->makeResponse(),
            ['id' => (string) $file->id]
        )->getBody();

        $this->assertStringContainsString('data-back-url="/files/folders/' . $file->folder_id . '"', $open(['from' => 'folder']));
        $this->assertStringContainsString('data-back-url="/files/' . $file->id . '"', $open([]));
        $this->assertStringContainsString('data-back-url="/files/' . $file->id . '"', $open(['from' => 'https://evil.test']));

        $guest = $this->createMember('Gast');
        $this->shareFileWith($file, ['user' => [(int) $guest->id]], Share::LEVEL_EDIT);
        $this->login((int) $guest->id);
        $this->assertStringContainsString(
            'data-back-url="/files/' . $file->id . '"',
            $open(['from' => 'folder']),
            'Den Ordner sieht nur die Dateifreigabe nicht.'
        );
    }

    public function testFolderListOpensEditorWithReturnToFolder(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $browser = $this->container->get(FileBrowserController::class);
        $this->login((int) $member->id);

        $html = (string) $browser->folder(
            $this->makeRequest('GET', '/files/folders/' . $file->folder_id),
            $this->makeResponse(),
            ['id' => (string) $file->folder_id]
        )->getBody();

        // Wie bei den Projekten: eigener Knopf direkt neben dem "..."-Menü, nicht im Menü.
        $this->assertMatchesRegularExpression(
            '#<a class="btn btn-sm btn-outline-secondary"\s+href="/files/' . $file->id . '/edit\?from=folder">#',
            $html
        );
        $this->assertStringNotContainsString(
            '<a class="dropdown-item" href="/files/' . $file->id . '/edit',
            $html,
            'Nicht zusätzlich im Menü.'
        );
    }

    public function testCreatingADocumentOpensTheEditor(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_UPLOAD);
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);

        $response = $controller->create(
            $this->makeRequest('POST', '/files/folders/' . $file->folder_id . '/office-documents', [
                'type' => 'text',
                'name' => 'Protokoll Oktober',
            ]),
            $this->makeResponse(),
            ['id' => (string) $file->folder_id]
        );

        $created = StoredFile::query()->where('folder_id', $file->folder_id)->where('name', 'Protokoll Oktober.docx')->sole();
        $this->assertRedirect($response, '/files/' . $created->id . '/edit?from=folder');
    }

    public function testFailedCreationReturnsToFolderWithMessage(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_UPLOAD, 'Protokoll.docx');
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);

        $response = $controller->create(
            $this->makeRequest('POST', '/files/folders/' . $file->folder_id . '/office-documents', [
                'type' => 'text',
                'name' => 'Protokoll',
            ]),
            $this->makeResponse(),
            ['id' => (string) $file->folder_id]
        );

        $this->assertRedirect($response, '/files/folders/' . $file->folder_id);
        $this->assertStringContainsString('gibt es dort schon', (string) ($_SESSION['error'] ?? ''));
    }

    public function testFolderOffersNewDocumentToUploadersOnly(): void
    {
        [$uploader, $file] = $this->sharedFile(Share::LEVEL_UPLOAD);
        [$reader, $readable] = $this->sharedFile(Share::LEVEL_READ);
        $browser = $this->container->get(FileBrowserController::class);
        $folderHtml = function (StoredFile $in) use ($browser): string {
            return (string) $browser->folder(
                $this->makeRequest('GET', '/files/folders/' . $in->folder_id),
                $this->makeResponse(),
                ['id' => (string) $in->folder_id]
            )->getBody();
        };

        $this->login((int) $uploader->id);
        $html = $folderHtml($file);
        $this->assertStringContainsString('data-bs-target="#filesNewDocumentModal"', $html);
        $this->assertStringContainsString('action="/files/folders/' . $file->folder_id . '/office-documents"', $html);
        $this->assertStringContainsString('Textdokument', $html);
        $this->assertStringNotContainsString('Präsentation', $html, 'Nur, was der Office-Server bearbeiten kann.');

        $this->login((int) $reader->id);
        $this->assertStringNotContainsString('filesNewDocumentModal', $folderHtml($readable));

        [$offUploader, $offFile] = $this->sharedFile(Share::LEVEL_UPLOAD);
        $offBrowser = $this->buildContainer(false)->get(FileBrowserController::class);
        $this->login((int) $offUploader->id);
        $offHtml = (string) $offBrowser->folder(
            $this->makeRequest('GET', '/files/folders/' . $offFile->folder_id),
            $this->makeResponse(),
            ['id' => (string) $offFile->folder_id]
        )->getBody();
        $this->assertStringNotContainsString('filesNewDocumentModal', $offHtml);
    }

    public function testNoBrowserEditingWithoutModule(): void
    {
        [$editor, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $detail = $this->buildContainer(false)->get(FileDetailController::class);
        $this->login((int) $editor->id);

        $html = (string) $detail->show($this->makeRequest('GET', '/files/' . $file->id), $this->makeResponse(), [
            'id' => (string) $file->id,
        ])->getBody();

        $this->assertStringNotContainsString('/files/' . $file->id . '/edit', $html);
    }

    public function testPagesRenderWithoutButtonsWhenOfficeServerIsDown(): void
    {
        [$editor, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $down = $this->buildContainer(true, static fn (string $url): ?string => null);
        $detail = $down->get(FileDetailController::class);
        $browser = $down->get(FileBrowserController::class);
        $this->login((int) $editor->id);

        $detailResponse = $detail->show($this->makeRequest('GET', '/files/' . $file->id), $this->makeResponse(), [
            'id' => (string) $file->id,
        ]);
        $folderResponse = $browser->folder(
            $this->makeRequest('GET', '/files/folders/' . $file->folder_id),
            $this->makeResponse(),
            ['id' => (string) $file->folder_id]
        );

        $this->assertSame(200, $detailResponse->getStatusCode());
        $this->assertSame(200, $folderResponse->getStatusCode());
        $this->assertStringNotContainsString('/files/' . $file->id . '/edit', (string) $detailResponse->getBody());
        $this->assertStringNotContainsString('/files/' . $file->id . '/edit', (string) $folderResponse->getBody());
    }
}

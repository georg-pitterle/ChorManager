<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FileBrowserController;
use App\Controllers\FileController;
use App\Controllers\FileFolderController;
use App\Controllers\FileTrashController;
use App\Models\FileFolderShare as Share;
use App\Models\StoredFile;
use DI\Container;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Bootstrap;

/**
 * Die Controller gegen den echten Container und die echten Templates: Seiten
 * rendern, Uploads kommen als JSON zurück, Fremde sehen nichts.
 */
class FileControllerFeatureTest extends TestCase
{
    use FileFixtures;
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

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $this->container = $builder->build();
        // Dieselbe Verbindung wie die Fixtures - sonst liefe der Controller über
        // eine zweite Verbindung und sähe nichts aus der offenen Transaktion.
        $this->container->set(Capsule::class, Bootstrap::getCapsule());
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
        $this->tearDownFileFixtures();
    }

    private function login(int $userId, bool $isAdmin = false): void
    {
        $_SESSION = ['user_id' => $userId, 'can_manage_files' => $isAdmin];
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private function uploadRequest(int $folderId, string $name, string $content)
    {
        $file = new UploadedFile((new StreamFactory())->createStream($content), $name, 'text/plain', strlen($content));

        return $this->makeRequest('POST', '/files/folders/' . $folderId . '/upload', [], [], [
            'Accept' => 'application/json',
        ])->withUploadedFiles(['file' => $file]);
    }

    private function uploadAs(int $folderId, string $name, string $content): StoredFile
    {
        $response = $this->container->get(FileController::class)
            ->upload($this->uploadRequest($folderId, $name, $content), $this->makeResponse(), ['id' => $folderId]);
        $payload = json_decode($this->body($response), true);
        $this->assertTrue($payload['ok'] ?? false, $this->body($response));

        return StoredFile::findOrFail($payload['file']['id']);
    }

    public function testRoutesExistOnlyWithModuleEnabled(): void
    {
        foreach ([true, false] as $enabled) {
            $builder = new ContainerBuilder();
            (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
            $container = $builder->build();
            $settings = $container->get('settings');
            $settings['modules']['files'] = $enabled;
            $container->set('settings', $settings);

            AppFactory::setContainer($container);
            $app = AppFactory::create();
            (require dirname(__DIR__, 2) . '/src/Routes.php')($app);
            $patterns = array_map(
                static fn ($route): string => $route->getPattern(),
                $app->getRouteCollector()->getRoutes()
            );

            $this->assertSame($enabled, in_array('/files', $patterns, true), $enabled ? 'an' : 'aus');
            $this->assertSame($enabled, in_array('/files/roots', $patterns, true));
        }
    }

    public function testIndexListsVisibleTeamFoldersOnly(): void
    {
        $member = $this->createMember();
        $visible = $this->createFolder('Sichtbar ' . bin2hex(random_bytes(3)));
        $hidden = $this->createFolder('Versteckt ' . bin2hex(random_bytes(3)));
        $this->share($visible, Share::TYPE_ALL_MEMBERS, 0, Share::LEVEL_READ);
        // Erst auflösen, dann anmelden: Der Aufbau von Twig setzt die Sitzung neu auf.
        $controller = $this->container->get(FileBrowserController::class);
        $this->login((int) $member->id);

        $html = $this->body($controller->index($this->makeRequest('GET', '/files'), $this->makeResponse()));

        $this->assertStringContainsString($visible->name, $html);
        $this->assertStringNotContainsString($hidden->name, $html);
        $this->assertStringNotContainsString('filesCreateRootModal', $html, 'Kein Anlegen ohne Recht.');
    }

    public function testFolderPageShowsActionsByLevel(): void
    {
        $reader = $this->createMember('Leser');
        $manager = $this->createMember('Verwalter');
        $role = $this->createRoleFor($manager);
        $root = $this->createFolder('Noten');
        $this->share($root, Share::TYPE_USER, (int) $reader->id, Share::LEVEL_READ);
        $this->share($root, Share::TYPE_ROLE, (int) $role->id, Share::LEVEL_MANAGE);
        $this->login((int) $manager->id);
        $this->uploadAs((int) $root->id, 'Ave Maria.pdf', '%PDF-1.4');
        $controller = $this->container->get(FileBrowserController::class);

        $this->login((int) $reader->id);
        $html = $this->body($controller->folder($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $root->id]));
        $this->assertStringContainsString('Ave Maria.pdf', $html);
        $this->assertStringNotContainsString('data-files-dropzone', $html);
        $this->assertStringNotContainsString('filesSharesModal', $html);

        $this->login((int) $manager->id);
        $html = $this->body($controller->folder($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $root->id]));
        $this->assertStringContainsString('data-files-dropzone', $html);
        $this->assertStringContainsString('filesSharesModal', $html);
        $this->assertMatchesRegularExpression('#value="role:' . $role->id . '"\s+selected#', $html);
    }

    public function testFolderOfOthersRedirectsWithoutRevealingIt(): void
    {
        $stranger = $this->createMember();
        $root = $this->createFolder('Vorstand');
        $this->login((int) $stranger->id);

        $response = $this->container->get(FileBrowserController::class)
            ->folder($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $root->id]);

        $this->assertRedirect($response, '/files');
    }

    public function testUploadAnswersJsonAndDownloadStreamsContent(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Uploads');
        $this->share($root, Share::TYPE_USER, (int) $member->id, Share::LEVEL_UPLOAD);
        $this->login((int) $member->id);

        $file = $this->uploadAs((int) $root->id, 'Probenplan.txt', 'Dienstag 19 Uhr');

        $response = $this->container->get(FileController::class)
            ->download($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $file->id]);
        $this->assertSame('Dienstag 19 Uhr', $this->body($response));
        $this->assertStringStartsWith('attachment; filename="Probenplan.txt"', $response->getHeaderLine('Content-Disposition'));

        $this->login((int) $this->createMember('Fremd')->id);
        $response = $this->container->get(FileController::class)
            ->download($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $file->id]);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUploadRejectionIsJsonWithStatus(): void
    {
        $reader = $this->createMember();
        $root = $this->createFolder('Nur lesen');
        $this->share($root, Share::TYPE_USER, (int) $reader->id, Share::LEVEL_READ);
        $this->login((int) $reader->id);

        $response = $this->container->get(FileController::class)
            ->upload($this->uploadRequest((int) $root->id, 'a.txt', 'x'), $this->makeResponse(), ['id' => $root->id]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse(json_decode($this->body($response), true)['ok']);

        $response = $this->container->get(FileController::class)->upload(
            $this->makeRequest('POST', '/'),
            $this->makeResponse(),
            ['id' => $root->id]
        );
        $this->assertSame(413, $response->getStatusCode(), 'Ohne Datei: meist post_max_size überschritten.');
    }

    public function testPreviewNeverServesActiveContentInline(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Vorschau');
        $this->share($root, Share::TYPE_USER, (int) $member->id, Share::LEVEL_UPLOAD);
        $this->login((int) $member->id);
        $html = $this->uploadAs((int) $root->id, 'seite.html', '<html><script>alert(1)</script></html>');
        $text = $this->uploadAs((int) $root->id, 'notiz.txt', 'hallo');
        $controller = $this->container->get(FileController::class);

        $response = $controller->preview($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $html->id]);
        $this->assertStringStartsWith('attachment;', $response->getHeaderLine('Content-Disposition'));

        $response = $controller->preview($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $text->id]);
        $this->assertStringStartsWith('inline;', $response->getHeaderLine('Content-Disposition'));
    }

    public function testSharesFormStoresCombinedTargets(): void
    {
        $manager = $this->createMember();
        $group = $this->createVoiceGroupFor($this->createMember('Sopranistin'));
        $root = $this->createFolder('Freigaben');
        $this->share($root, Share::TYPE_USER, (int) $manager->id, Share::LEVEL_MANAGE);
        $this->login((int) $manager->id);

        $response = $this->container->get(FileFolderController::class)->shares(
            $this->makeRequest('POST', '/', ['shares' => [
                ['target' => 'user:' . $manager->id, 'level' => '4'],
                ['target' => 'voice_group:' . $group->id, 'level' => '1'],
                ['target' => 'kaputt', 'level' => '1'],
            ]]),
            $this->makeResponse(),
            ['id' => $root->id]
        );

        $this->assertRedirect($response, '/files/folders/' . $root->id);
        $this->assertSame(2, Share::query()->where('folder_id', $root->id)->count());
        $this->assertSame('Freigaben gespeichert.', $_SESSION['success'] ?? null);
    }

    public function testCreateRootRedirectsIntoNewFolder(): void
    {
        $admin = $this->createMember();
        $this->login((int) $admin->id, true);
        $name = 'Neu ' . bin2hex(random_bytes(3));

        $response = $this->container->get(FileFolderController::class)->createRoot(
            $this->makeRequest('POST', '/files/roots', ['name' => $name, 'quota_mb' => '50']),
            $this->makeResponse()
        );

        $folder = \App\Models\FileFolder::query()->where('name', $name)->firstOrFail();
        $this->assertRedirect($response, '/files/folders/' . $folder->id);
        $this->assertSame(50 * 1024 * 1024, $folder->quota_bytes);
    }

    public function testTrashAndSearchPagesRender(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Seiten');
        $this->share($root, Share::TYPE_USER, (int) $member->id, Share::LEVEL_EDIT);
        $this->login((int) $member->id);
        $token = 'zz' . bin2hex(random_bytes(3));
        $file = $this->uploadAs((int) $root->id, "Suchbar {$token}.txt", 'x');
        $gone = $this->uploadAs((int) $root->id, 'Weg.txt', 'y');
        $this->container->get(FileController::class)
            ->delete($this->makeRequest('POST', '/'), $this->makeResponse(), ['id' => $gone->id]);

        $html = $this->body($this->container->get(FileBrowserController::class)
            ->search($this->makeRequest('GET', '/files/search', [], ['q' => $token]), $this->makeResponse()));
        $this->assertStringContainsString("Suchbar {$token}.txt", $html);

        $html = $this->body($this->container->get(FileTrashController::class)
            ->index($this->makeRequest('GET', '/files/trash'), $this->makeResponse()));
        $this->assertStringContainsString('Weg.txt', $html);
        $this->assertStringNotContainsString('/purge', $html, 'Endgültig löschen erst mit Stufe Verwalten.');

        $response = $this->container->get(FileTrashController::class)->restore(
            $this->makeRequest('POST', '/'),
            $this->makeResponse(),
            ['type' => 'file', 'id' => $gone->id]
        );
        $this->assertRedirect($response, '/files/trash');
        $this->assertNull(StoredFile::find($gone->id)?->deleted_at);
        $this->assertNotNull($file);
    }

    public function testZipDownload(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Archiv');
        $this->share($root, Share::TYPE_USER, (int) $member->id, Share::LEVEL_UPLOAD);
        $this->login((int) $member->id);
        $this->uploadAs((int) $root->id, 'a.txt', 'a');

        $response = $this->container->get(FileFolderController::class)
            ->zip($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $root->id]);

        $this->assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('PK', $this->body($response));
    }
}

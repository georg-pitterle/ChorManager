<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FileBrowserController;
use App\Controllers\FileController;
use App\Controllers\FileDetailController;
use App\Controllers\PublicFileLinkController;
use App\Models\FileFolderShare as Share;
use App\Models\FilePublicLink;
use App\Models\FileShare;
use App\Models\StoredFile;
use App\Services\Files\FileActor;
use App\Services\Files\FileShareService;
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
 * Dateiseite, Dateifreigaben und öffentliche Links gegen den echten Container.
 */
class FileSharingControllerFeatureTest extends TestCase
{
    use FileFixtures;
    use TestHttpHelpers;

    private Container $container;
    private ?string $previousStoragePath = null;
    private FileDetailController $detail;
    private PublicFileLinkController $public;
    private FileController $files;
    private FileBrowserController $browser;

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
        $this->container->set(Capsule::class, Bootstrap::getCapsule());

        // Erst auflösen, dann anmelden: Der Aufbau von Twig setzt die Sitzung neu auf.
        $this->detail = $this->container->get(FileDetailController::class);
        $this->public = $this->container->get(PublicFileLinkController::class);
        $this->files = $this->container->get(FileController::class);
        $this->browser = $this->container->get(FileBrowserController::class);
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

    private function uploadedFile(string $name, string $content): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($content), $name, 'text/plain', strlen($content));
    }

    /** @return array{0: StoredFile, 1: \App\Models\User} Datei in einem Ordner, den der Verwalter verwaltet */
    private function managedFile(string $content = 'Pressetext'): array
    {
        $manager = $this->createMember('Verwalter');
        $folder = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $this->share($folder, 'user', (int) $manager->id, Share::LEVEL_MANAGE);
        $this->login((int) $manager->id);
        $request = $this->makeRequest('POST', '/', [], [], ['Accept' => 'application/json'])
            ->withUploadedFiles(['file' => $this->uploadedFile('Presse.txt', $content)]);
        $payload = json_decode($this->body($this->files->upload($request, $this->makeResponse(), ['id' => $folder->id])), true);

        return [StoredFile::findOrFail($payload['file']['id']), $manager];
    }

    public function testRecipientOfFileShareSeesFileButNotFolder(): void
    {
        [$file] = $this->managedFile();
        $recipient = $this->createMember('Empfänger');
        $this->shareFileWith($file, ['user' => [(int) $recipient->id]], 1);
        $this->login((int) $recipient->id);

        $html = $this->body($this->detail->show($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $file->id]));
        $this->assertStringContainsString('Presse.txt', $html);
        $this->assertStringContainsString('Mit mir geteilt', $html);
        $this->assertStringNotContainsString('Vorstand', $html, 'Kein Ordnername.');
        $this->assertStringNotContainsString('Öffentliche Links', $html);
        $this->assertStringNotContainsString('Neue Version hochladen', $html);

        $index = $this->body($this->browser->index($this->makeRequest('GET', '/files'), $this->makeResponse()));
        $this->assertStringContainsString('href="/files/' . $file->id . '"', $index);

        $folder = $this->browser->folder($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $file->folder_id]);
        $this->assertRedirect($folder, '/files');
    }

    public function testFileShareEditorCanUploadNewVersionButNotRename(): void
    {
        [$file] = $this->managedFile('alt');
        $editor = $this->createMember('Bearbeiter');
        $this->shareFileWith($file, ['user' => [(int) $editor->id]], 3);
        $this->login((int) $editor->id);

        $request = $this->makeRequest('POST', '/')->withUploadedFiles(['file' => $this->uploadedFile('beliebig.txt', 'neu')]);
        $response = $this->detail->replace($request, $this->makeResponse(), ['id' => $file->id]);

        $this->assertRedirect($response, '/files/' . $file->id);
        $fresh = $file->fresh(['currentVersion']);
        $this->assertSame(2, $fresh->currentVersion->version_number);
        $this->assertSame('Presse.txt', $fresh->name, 'Name bleibt.');

        $response = $this->files->rename(
            $this->makeRequest('POST', '/', ['name' => 'anders.txt']),
            $this->makeResponse(),
            ['id' => $file->id]
        );
        $this->assertRedirect($response, '/files');
        $this->assertSame('Presse.txt', $file->fresh()->name);
    }

    public function testManagerCreatesLinkShownOnceAndSharesFile(): void
    {
        [$file, $manager] = $this->managedFile();
        $group = $this->createVoiceGroupFor($this->createMember());
        $this->login((int) $manager->id);

        $this->detail->saveShares(
            $this->makeRequest('POST', '/', ['shares' => [
                ['level' => '3', 'conditions' => ['voice_group' => [(string) $group->id]]],
            ]]),
            $this->makeResponse(),
            ['id' => $file->id]
        );
        $this->assertSame(1, FileShare::query()->where('file_id', $file->id)->count());

        $response = $this->detail->createLink(
            $this->makeRequest('POST', 'https://chor.example/files/' . $file->id . '/links', ['label' => 'Presse']),
            $this->makeResponse(),
            ['id' => $file->id]
        );
        $this->assertRedirect($response, '/files/' . $file->id);

        $html = $this->body($this->detail->show($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $file->id]));
        $this->assertMatchesRegularExpression('#/s/[A-Za-z0-9_-]{32}#', $html);
        $this->assertStringContainsString('Presse', $html);

        $again = $this->body($this->detail->show($this->makeRequest('GET', '/'), $this->makeResponse(), ['id' => $file->id]));
        $this->assertDoesNotMatchRegularExpression('#/s/[A-Za-z0-9_-]{32}#', $again, 'Klartext nur einmal.');
    }

    public function testPublicLinkDeliversFileWithoutLogin(): void
    {
        [$file, $manager] = $this->managedFile('Öffentlich');
        [$link, $token] = $this->container->get(FileShareService::class)
            ->createLink(new FileActor((int) $manager->id, false), $file, null, null, null);
        $_SESSION = [];

        $page = $this->public->show($this->makeRequest('GET', '/'), $this->makeResponse(), ['token' => $token]);
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('Presse.txt', $this->body($page));
        $this->assertStringNotContainsString('Vorstand', $this->body($page));
        $this->assertStringContainsString('noindex', $page->getHeaderLine('X-Robots-Tag'));

        $download = $this->public->download($this->makeRequest('GET', '/'), $this->makeResponse(), ['token' => $token]);
        $this->assertSame('Öffentlich', $this->body($download));
        $this->assertSame(1, FilePublicLink::find($link->id)->download_count);

        $invalid = $this->public->show($this->makeRequest('GET', '/'), $this->makeResponse(), ['token' => str_repeat('a', 32)]);
        $this->assertSame(404, $invalid->getStatusCode());
    }

    public function testPasswordProtectedLinkNeedsUnlock(): void
    {
        [$file, $manager] = $this->managedFile('Geschützt');
        [, $token] = $this->container->get(FileShareService::class)
            ->createLink(new FileActor((int) $manager->id, false), $file, null, null, 'Chor2026');
        $_SESSION = [];

        $html = $this->body($this->public->show($this->makeRequest('GET', '/'), $this->makeResponse(), ['token' => $token]));
        $this->assertStringContainsString('Diese Datei ist geschützt', $html);
        $this->assertStringNotContainsString('Presse.txt', $html, 'Ohne Passwort kein Dateiname.');

        $blocked = $this->public->download($this->makeRequest('GET', '/'), $this->makeResponse(), ['token' => $token]);
        $this->assertRedirect($blocked, '/s/' . $token);

        $this->public->unlock(
            $this->makeRequest('POST', '/', ['password' => 'falsch']),
            $this->makeResponse(),
            ['token' => $token]
        );
        $this->assertSame('Das Passwort stimmt nicht.', $_SESSION['public_link_error'] ?? null);

        $this->public->unlock(
            $this->makeRequest('POST', '/', ['password' => 'Chor2026']),
            $this->makeResponse(),
            ['token' => $token]
        );
        $download = $this->public->download($this->makeRequest('GET', '/'), $this->makeResponse(), ['token' => $token]);
        $this->assertSame('Geschützt', $this->body($download));
    }

    /**
     * Die Passwort-Bremse galt nur je Link und IP. Wer die IP bei jedem Versuch
     * wechselte, riet unbegrenzt. Jetzt gilt zusätzlich eine Obergrenze je Link
     * über alle Quellen - danach geht auch das richtige Passwort eine Weile nicht
     * durch, wie bei jeder Sperre dieser Art.
     */
    public function testPasswordAttemptsAreCappedPerLinkAcrossChangingAddresses(): void
    {
        [$file, $manager] = $this->managedFile('Geschützt');
        [, $token] = $this->container->get(FileShareService::class)
            ->createLink(new FileActor((int) $manager->id, false), $file, null, null, 'Chor2026');
        $_SESSION = [];

        $storeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_link_limit_' . bin2hex(random_bytes(6));
        $controller = new PublicFileLinkController(
            $this->container->get(\Slim\Views\Twig::class),
            $this->container->get(FileShareService::class),
            $this->container->get(\App\Services\Files\FileResponseFactory::class),
            new \App\Services\RateLimiterService($storeDir),
            new \Psr\Log\NullLogger()
        );

        try {
            for ($i = 0; $i < PublicFileLinkController::MAX_PASSWORD_ATTEMPTS_PER_LINK; $i++) {
                $controller->unlock(
                    $this->requestFrom('203.0.113.' . ($i + 1), ['password' => 'falsch-' . $i]),
                    $this->makeResponse(),
                    ['token' => $token]
                );
            }

            $controller->unlock(
                $this->requestFrom('198.51.100.200', ['password' => 'Chor2026']),
                $this->makeResponse(),
                ['token' => $token]
            );

            $this->assertSame(
                'Zu viele Versuche. Bitte in einigen Minuten erneut probieren.',
                $_SESSION['public_link_error'] ?? null
            );
            $blocked = $controller->download($this->makeRequest('GET', '/'), $this->makeResponse(), ['token' => $token]);
            $this->assertRedirect($blocked, '/s/' . $token);
        } finally {
            foreach ((array) glob($storeDir . DIRECTORY_SEPARATOR . '*') as $leftover) {
                @unlink($leftover);
            }
            @rmdir($storeDir);
        }
    }

    /**
     * Gezählt wird das Raten, nicht das Öffnen.
     *
     * Gefunden im rotierenden Review (Lauf 77): Die Obergrenze je Link zählte
     * auch die richtigen Eingaben mit, und zurückgesetzt wurde nach dem Treffer
     * nur der Zähler der eigenen IP. Ein Link aus dem Newsletter an den ganzen
     * Chor sperrte sich damit nach fünfzig Empfängern in einer Viertelstunde
     * für alle übrigen - ohne dass jemand ein Passwort geraten hätte.
     */
    public function testCorrectPasswordDoesNotUseUpTheLinkWideBudget(): void
    {
        [$file, $manager] = $this->managedFile('Geschützt');
        [, $token] = $this->container->get(FileShareService::class)
            ->createLink(new FileActor((int) $manager->id, false), $file, null, null, 'Chor2026');
        $_SESSION = [];

        $storeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_link_ok_' . bin2hex(random_bytes(6));
        $controller = new PublicFileLinkController(
            $this->container->get(\Slim\Views\Twig::class),
            $this->container->get(FileShareService::class),
            $this->container->get(\App\Services\Files\FileResponseFactory::class),
            new \App\Services\RateLimiterService($storeDir),
            new \Psr\Log\NullLogger()
        );

        try {
            // So viele richtige Eingaben, wie die Obergrenze je Link zulässt -
            // jede von einer anderen Adresse, wie bei einem Rundschreiben.
            for ($i = 0; $i < PublicFileLinkController::MAX_PASSWORD_ATTEMPTS_PER_LINK; $i++) {
                $_SESSION = [];
                $controller->unlock(
                    $this->requestFrom('203.0.113.' . ($i + 1), ['password' => 'Chor2026']),
                    $this->makeResponse(),
                    ['token' => $token]
                );
                $this->assertNull($_SESSION['public_link_error'] ?? null, 'Versuch ' . ($i + 1));
            }

            // Und noch einer darüber: Die Datei öffnet sich weiterhin.
            $_SESSION = [];
            $controller->unlock(
                $this->requestFrom('198.51.100.200', ['password' => 'Chor2026']),
                $this->makeResponse(),
                ['token' => $token]
            );
            $this->assertNull($_SESSION['public_link_error'] ?? null);
            $download = $controller->download($this->makeRequest('GET', '/'), $this->makeResponse(), ['token' => $token]);
            $this->assertSame('Geschützt', $this->body($download));
        } finally {
            foreach ((array) glob($storeDir . DIRECTORY_SEPARATOR . '*') as $leftover) {
                @unlink($leftover);
            }
            @rmdir($storeDir);
        }
    }

    /**
     * @param array<string, string> $body
     */
    private function requestFrom(string $remoteAddress, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/', ['REMOTE_ADDR' => $remoteAddress])
            ->withParsedBody($body);
    }

    public function testPublicRoutesExistOnlyWithModuleAndOutsideTheLoginGroup(): void
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
            $publicRoutes = array_values(array_filter(
                $app->getRouteCollector()->getRoutes(),
                static fn ($route): bool => str_starts_with($route->getPattern(), '/s/')
            ));

            $this->assertSame($enabled ? 4 : 0, count($publicRoutes));
            foreach ($publicRoutes as $route) {
                // Die geschützte Gruppe hängt AuthMiddleware an; hier darf keine Gruppe dazwischen sein.
                $this->assertSame([], $route->getGroups(), $route->getPattern());
            }
        }
    }
}

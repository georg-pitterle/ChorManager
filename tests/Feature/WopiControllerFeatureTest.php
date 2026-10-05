<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\WopiController;
use App\Models\FileFolderShare as Share;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileActor;
use App\Services\Files\FileService;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use Carbon\Carbon;
use DI\Container;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Bootstrap;

/**
 * Die drei WOPI-Endpunkte gegen den echten Container: Collabora weist sich nur
 * mit dem Token aus, jede Anfrage prüft die Rechte neu.
 */
class WopiControllerFeatureTest extends TestCase
{
    use FileFixtures;
    use OfficeFixtures;
    use TestHttpHelpers;

    private Container $container;
    private TestHandler $logs;
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
        $this->container->set(Capsule::class, Bootstrap::getCapsule());

        $settings = $this->container->get('settings');
        $settings['modules']['files'] = true;
        $settings['modules']['office'] = true;
        $settings['files']['max_upload_bytes'] = 1024;
        $this->container->set('settings', $settings);

        $this->logs = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($this->logs);
        $this->container->set(LoggerInterface::class, $logger);
        $this->container->set(OfficeSettings::class, $this->officeSettings());
        $this->container->set(OfficeDiscovery::class, $this->discovery());
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

    private function upload(string $content, string $name): UploadedFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return new UploadedFile($stream, $name, 'application/octet-stream', strlen($content));
    }

    /** @return array{0: User, 1: StoredFile, 2: string} */
    private function sharedFile(int $level = Share::LEVEL_EDIT, string $name = 'Protokoll.odt'): array
    {
        $member = $this->createMember('Erika');
        $root = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $this->share($root, 'user', (int) $member->id, $level);
        $file = $this->container->get(FileService::class)
            ->upload(new FileActor((int) $member->id, true), $root, $this->upload('Stand 1', $name))->file;
        $token = $this->container->get(OfficeTokenService::class)->issue((int) $member->id, (int) $file->id)->plain;

        return [$member, $file, $token];
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(
        string $action,
        StoredFile $file,
        string $token,
        string $body = '',
        array $headers = []
    ): ResponseInterface {
        $path = '/wopi/files/' . $file->id . ($action === 'checkFileInfo' ? '' : '/contents');
        $method = $action === 'putFile' ? 'POST' : 'GET';
        $request = $this->makeRequest($method, $path, [], ['access_token' => $token], $headers);
        if ($action === 'putFile') {
            $request = $request->withBody((new StreamFactory())->createStream($body));
        }

        return $this->container->get(WopiController::class)
            ->{$action}($request, $this->makeResponse(), ['id' => (string) $file->id]);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function currentContent(StoredFile $file): string
    {
        $version = FileVersion::findOrFail(StoredFile::findOrFail($file->id)->current_version_id);

        return $this->storage()->read((string) $version->storage_path);
    }

    public function testRoutesExistOnlyWithOfficeModule(): void
    {
        foreach ([true, false] as $enabled) {
            $builder = new ContainerBuilder();
            (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
            $container = $builder->build();
            $settings = $container->get('settings');
            $settings['modules']['files'] = true;
            $settings['modules']['office'] = $enabled;
            $container->set('settings', $settings);

            AppFactory::setContainer($container);
            $app = AppFactory::create();
            (require dirname(__DIR__, 2) . '/src/Routes.php')($app);
            $patterns = array_map(
                static fn ($route): string => $route->getPattern(),
                $app->getRouteCollector()->getRoutes()
            );

            $this->assertSame($enabled, in_array('/wopi/files/{id:[0-9]+}', $patterns, true));
            $this->assertSame($enabled, in_array('/wopi/files/{id:[0-9]+}/contents', $patterns, true));
            $this->assertSame($enabled, in_array('/files/{id:[0-9]+}/edit', $patterns, true));
            $this->assertSame($enabled, in_array('/files/folders/{id:[0-9]+}/office-documents', $patterns, true));
        }
    }

    public function testCheckFileInfoDescribesFileAndWriteRightForEditor(): void
    {
        [$member, $file, $token] = $this->sharedFile();

        $response = $this->call('checkFileInfo', $file, $token);

        $this->assertSame(200, $response->getStatusCode());
        $info = $this->json($response);
        $this->assertSame('Protokoll.odt', $info['BaseFileName']);
        $this->assertSame(7, $info['Size']);
        $this->assertSame((string) $file->current_version_id, $info['Version']);
        $this->assertSame((string) $member->id, $info['UserId']);
        $this->assertStringContainsString('Erika', $info['UserFriendlyName']);
        $this->assertTrue($info['UserCanWrite']);
        $this->assertTrue($info['UserCanNotWriteRelative']);
        $this->assertSame('https://chor.example.test', $info['PostMessageOrigin']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $info['LastModifiedTime']);
    }

    public function testReaderMayReadButNotWrite(): void
    {
        [, $file, $token] = $this->sharedFile(Share::LEVEL_READ);

        $this->assertFalse($this->json($this->call('checkFileInfo', $file, $token))['UserCanWrite']);
        $this->assertSame(403, $this->call('putFile', $file, $token, 'Stand 2')->getStatusCode());
        $this->assertSame('Stand 1', $this->currentContent($file));
    }

    public function testViewOnlyFormatIsNotWritableEvenForEditors(): void
    {
        [, $file, $token] = $this->sharedFile(Share::LEVEL_EDIT, 'Partitur.pdf');

        $this->assertFalse($this->json($this->call('checkFileInfo', $file, $token))['UserCanWrite']);
        $this->assertSame(403, $this->call('putFile', $file, $token, 'Stand 2')->getStatusCode());
    }

    public function testFileAdminWithoutShareMayWrite(): void
    {
        $admin = $this->createMember('Admin');
        $role = $this->createRoleFor($admin);
        $role->can_manage_files = 1;
        $role->save();
        $root = $this->createFolder('Archiv ' . bin2hex(random_bytes(3)));
        $file = $this->container->get(FileService::class)
            ->upload(new FileActor((int) $admin->id, true), $root, $this->upload('Stand 1', 'Liste.odt'))->file;
        $token = $this->container->get(OfficeTokenService::class)->issue((int) $admin->id, (int) $file->id)->plain;

        $this->assertTrue($this->json($this->call('checkFileInfo', $file, $token))['UserCanWrite']);
    }

    public function testInvalidTokensAreRejectedWith401(): void
    {
        [, $file, $token] = $this->sharedFile();
        [, $other] = $this->sharedFile();

        $this->assertSame(401, $this->call('checkFileInfo', $file, '')->getStatusCode());
        $this->assertSame(401, $this->call('checkFileInfo', $file, 'unbekannt')->getStatusCode());
        $this->assertSame(401, $this->call('checkFileInfo', $other, $token)->getStatusCode(), 'Token gilt nur für seine Datei.');

        Carbon::setTestNow(Carbon::now()->addSeconds(OfficeTokenService::TTL_SECONDS + 1));
        $this->assertSame(401, $this->call('checkFileInfo', $file, $token)->getStatusCode());
    }

    public function testDeactivatedUserLosesAccess(): void
    {
        [$member, $file, $token] = $this->sharedFile();
        $member->is_active = 0;
        $member->save();

        $this->assertSame(401, $this->call('checkFileInfo', $file, $token)->getStatusCode());
    }

    public function testRevokedShareAndTrashedFileEndAccess(): void
    {
        [, $file, $token] = $this->sharedFile();
        Share::query()->where('folder_id', $file->folder_id)->delete();

        $this->assertSame(404, $this->call('checkFileInfo', $file, $token)->getStatusCode());
        $this->assertSame(404, $this->call('putFile', $file, $token, 'Stand 2')->getStatusCode());
        $this->assertSame('Stand 1', $this->currentContent($file), 'Nichts gespeichert.');

        [, $trashed, $trashedToken] = $this->sharedFile();
        $trashed->delete();
        $this->assertSame(404, $this->call('getFile', $trashed, $trashedToken)->getStatusCode());
    }

    public function testGetFileStreamsCurrentVersion(): void
    {
        [, $file, $token] = $this->sharedFile();

        $response = $this->call('getFile', $file, $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Stand 1', (string) $response->getBody());
    }

    public function testPutFileSavesAndAnswersWithNewTimestamp(): void
    {
        [, $file, $token] = $this->sharedFile();

        $response = $this->call('putFile', $file, $token, 'Stand 2', ['X-WOPI-Override' => 'PUT']);

        $this->assertSame(200, $response->getStatusCode());
        $saved = $this->json($response)['LastModifiedTime'];
        $this->assertSame('Stand 2', $this->currentContent($file));
        $this->assertSame($saved, $this->json($this->call('checkFileInfo', $file, $token))['LastModifiedTime']);
        $this->assertSame(2, FileVersion::query()->where('file_id', $file->id)->count());
    }

    public function testExitSaveHeaderClosesSession(): void
    {
        [, $file, $token] = $this->sharedFile();

        $this->call('putFile', $file, $token, 'Stand 2', ['X-COOL-WOPI-IsExitSave' => 'true']);

        $version = FileVersion::findOrFail(StoredFile::findOrFail($file->id)->current_version_id);
        $this->assertFalse($version->office_session_open);
    }

    public function testStaleTimestampIsAConflict(): void
    {
        [$member, $file, $token] = $this->sharedFile();
        $known = $this->json($this->call('checkFileInfo', $file, $token))['LastModifiedTime'];

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->container->get(FileService::class)
            ->replace(new FileActor((int) $member->id, true), (int) $file->id, $this->upload('Fremd', 'Protokoll.odt'));

        $conflict = $this->call('putFile', $file, $token, 'Meins', ['X-COOL-WOPI-Timestamp' => $known]);
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame(['COOLStatusCode' => 1010], $this->json($conflict));
        $this->assertSame('Fremd', $this->currentContent($file));
        $this->assertTrue($this->logs->hasNoticeThatPasses(
            static fn ($record): bool => ($record->context['event'] ?? '') === 'office.save_conflict'
        ));

        // "Überschreiben" in Collabora: dasselbe Speichern ohne Zeitstempel.
        $this->assertSame(200, $this->call('putFile', $file, $token, 'Meins')->getStatusCode());
        $this->assertSame('Meins', $this->currentContent($file));
    }

    public function testMatchingTimestampSaves(): void
    {
        [, $file, $token] = $this->sharedFile();
        $known = $this->json($this->call('checkFileInfo', $file, $token))['LastModifiedTime'];

        $response = $this->call('putFile', $file, $token, 'Stand 2', ['X-COOL-WOPI-Timestamp' => $known]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testOversizedBodyIs413(): void
    {
        [, $file, $token] = $this->sharedFile();

        $this->assertSame(413, $this->call('putFile', $file, $token, str_repeat('x', 2048))->getStatusCode());
        $this->assertTrue($this->logs->hasNoticeThatPasses(
            static fn ($record): bool => ($record->context['event'] ?? '') === 'office.save_rejected'
        ));
    }

    /**
     * Über `post_max_size` verwirft PHP den Rumpf - es kommt nichts an, obwohl
     * Content-Length etwas ankündigt. Das ist "zu groß", nicht "leer".
     */
    public function testBodyDroppedByPhpCountsAsTooLarge(): void
    {
        [, $file, $token] = $this->sharedFile();

        $response = $this->call('putFile', $file, $token, '', ['Content-Length' => '200000000']);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('Stand 1', $this->currentContent($file));
    }

    public function testEmptyBodyIsRejected(): void
    {
        [, $file, $token] = $this->sharedFile();

        $this->assertSame(400, $this->call('putFile', $file, $token, '')->getStatusCode());
        $this->assertSame('Stand 1', $this->currentContent($file));
    }

    public function testRenameDuringSessionKeepsSaving(): void
    {
        [$member, $file, $token] = $this->sharedFile();
        $this->container->get(FileService::class)
            ->renameFile(new FileActor((int) $member->id, true), StoredFile::findOrFail($file->id), 'Protokoll neu.odt');

        $this->assertSame('Protokoll neu.odt', $this->json($this->call('checkFileInfo', $file, $token))['BaseFileName']);
        $this->assertSame(200, $this->call('putFile', $file, $token, 'Stand 2')->getStatusCode());
        $this->assertSame('Stand 2', $this->currentContent($file));
    }

    public function testTokenNeverAppearsInLogs(): void
    {
        [, $file, $token] = $this->sharedFile();
        [, $other] = $this->sharedFile();

        $this->call('checkFileInfo', $file, $token);
        $this->call('getFile', $file, $token);
        $this->call('putFile', $file, $token, 'Stand 2');
        $this->call('putFile', $file, $token, 'Stand 3', ['X-COOL-WOPI-Timestamp' => 'veraltet']);
        $this->call('putFile', $file, $token, str_repeat('x', 2048));
        $this->call('checkFileInfo', $other, $token);

        $this->assertNotEmpty($this->logs->getRecords());
        foreach ($this->logs->getRecords() as $record) {
            $this->assertStringNotContainsString($token, (string) json_encode($record->toArray()));
        }
    }
}

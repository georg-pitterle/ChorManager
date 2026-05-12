<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SheetArchiveController;
use App\Models\Song;
use App\Services\SheetArchiveService;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class SheetArchiveHttpTest extends TestCase
{
    use TestHttpHelpers;

    private static ?Capsule $capsule = null;
    private SheetArchiveController $controller;
    private Song $song;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset session
        $_SESSION = [];

        // Create test song
        $this->song = Song::create([
            'title' => 'Test Song',
            'composer' => 'Test Composer',
        ]);

        // Create mock container with services
        $container = $this->createMockContainer();
        $this->controller = new SheetArchiveController($container);

        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * CRITICAL: Test unauthorized user cannot save archive
     */
    public function testUnauthorizedUserCannotSaveArchive(): void
    {
        // User is NOT authenticated
        $_SESSION = [];

        $request = $this->makeRequest('POST', '/song-library/songs/' . $this->song->id . '/archive/save', [
            'archive_number' => 'ARCH-001',
            'location' => 'Shelf A',
            'line_items' => [
                ['voice_category' => 'Sopran', 'count' => 5],
            ],
        ]);

        $response = $this->controller->save($request, $this->makeResponse(), ['songId' => (string) $this->song->id]);

        $this->assertEquals(403, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Unauthorized', $responseBody['error'] ?? null);
    }

    /**
     * CRITICAL: Test user without permission cannot save archive
     */
    public function testUserWithoutPermissionCannotSaveArchive(): void
    {
        // User is authenticated but has NO can_manage_sheet_archive permission
        $_SESSION = [
            'user_id' => 1,
            'can_manage_sheet_archive' => false,
        ];

        $request = $this->makeRequest('POST', '/song-library/songs/' . $this->song->id . '/archive/save', [
            'archive_number' => 'ARCH-001',
            'location' => 'Shelf A',
            'line_items' => [
                ['voice_category' => 'Sopran', 'count' => 5],
            ],
        ]);

        $response = $this->controller->save($request, $this->makeResponse(), ['songId' => (string) $this->song->id]);

        $this->assertEquals(403, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Unauthorized', $responseBody['error'] ?? null);
    }

    /**
     * CRITICAL: Test authorized user CAN save archive
     */
    public function testAuthorizedUserCanSaveArchive(): void
    {
        // User is authenticated AND has permission
        $_SESSION = [
            'user_id' => 1,
            'can_manage_sheet_archive' => true,
        ];

        $request = $this->makeRequest('POST', '/song-library/songs/' . $this->song->id . '/archive/save', [
            'archive_number' => 'ARCH-001',
            'location' => 'Shelf A',
            'line_items' => [
                ['voice_category' => 'Sopran', 'count' => 5],
                ['voice_category' => 'Alt', 'count' => 4],
            ],
        ]);

        $response = $this->controller->save($request, $this->makeResponse(), ['songId' => (string) $this->song->id]);

        $this->assertEquals(200, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertTrue($responseBody['success'] ?? false);
        $this->assertEquals(9, $responseBody['archive']['total_count'] ?? null);
    }

    /**
     * IMPORTANT: Test voice_category validation - too long
     */
    public function testVoiceCategoryValidationRejectsLongStrings(): void
    {
        $_SESSION = [
            'user_id' => 1,
            'can_manage_sheet_archive' => true,
        ];

        $longCategory = str_repeat('a', 101); // > 100 chars

        $request = $this->makeRequest('POST', '/song-library/songs/' . $this->song->id . '/archive/save', [
            'archive_number' => 'ARCH-001',
            'location' => 'Shelf A',
            'line_items' => [
                ['voice_category' => $longCategory, 'count' => 5],
            ],
        ]);

        $response = $this->controller->save($request, $this->makeResponse(), ['songId' => (string) $this->song->id]);

        $this->assertEquals(400, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('100', $responseBody['error'] ?? '');
    }

    /**
     * IMPORTANT: Test voice_category validation - invalid characters
     */
    public function testVoiceCategoryValidationRejectsInvalidCharacters(): void
    {
        $_SESSION = [
            'user_id' => 1,
            'can_manage_sheet_archive' => true,
        ];

        $request = $this->makeRequest('POST', '/song-library/songs/' . $this->song->id . '/archive/save', [
            'archive_number' => 'ARCH-001',
            'location' => 'Shelf A',
            'line_items' => [
                ['voice_category' => 'Sopran<script>', 'count' => 5],
            ],
        ]);

        $response = $this->controller->save($request, $this->makeResponse(), ['songId' => (string) $this->song->id]);

        $this->assertEquals(400, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('invalid', strtolower($responseBody['error'] ?? ''));
    }

    /**
     * IMPORTANT: Test unauthorized user cannot get voice categories
     */
    public function testUnauthorizedUserCannotGetVoiceCategories(): void
    {
        $_SESSION = [];

        $request = $this->makeRequest('GET', '/song-library/sheet-archive/voice-categories');

        $response = $this->controller->getVoiceCategories($request, $this->makeResponse());

        $this->assertEquals(403, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals('Unauthorized', $responseBody['error'] ?? null);
    }

    /**
     * Test authorized user CAN get voice categories
     */
    public function testAuthorizedUserCanGetVoiceCategories(): void
    {
        $_SESSION = [
            'user_id' => 1,
            'can_manage_sheet_archive' => true,
        ];

        $request = $this->makeRequest('GET', '/song-library/sheet-archive/voice-categories');

        $response = $this->controller->getVoiceCategories($request, $this->makeResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($responseBody['categories'] ?? null);
    }

    private function createMockContainer(): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) {
            if ($id === SheetArchiveService::class) {
                return new SheetArchiveService();
            }
            if ($id === LoggerInterface::class) {
                return $this->createMock(LoggerInterface::class);
            }
            throw new \Exception("Unknown service: $id");
        });

        return $container;
    }
}

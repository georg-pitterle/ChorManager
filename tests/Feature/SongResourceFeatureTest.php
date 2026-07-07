<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SongLibraryController;
use App\Models\Song;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class SongResourceFeatureTest extends TestCase
{
    use TestHttpHelpers;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        if (!$schema->hasTable('songs')) {
            $schema->create('songs', function ($table): void {
                $table->increments('id');
                $table->string('title');
                $table->string('composer')->nullable();
                $table->string('arranger')->nullable();
                $table->string('publisher')->nullable();
                $table->integer('created_by_user_id')->nullable();
            });
        }

        if (!$schema->hasTable('song_resources')) {
            $schema->create('song_resources', function ($table): void {
                $table->increments('id');
                $table->integer('song_id');
                $table->string('resource_type', 32);
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('url', 2048)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Capsule::table('song_resources')->delete();
        Capsule::table('songs')->delete();
    }

    public function testSongResourceMigrationExists(): void
    {
        $migrationDir = dirname(__DIR__) . '/../db/migrations/';
        $files = glob($migrationDir . '*_create_song_resources_table.php');

        $this->assertNotEmpty($files, 'Song resource migration file not found.');
    }

    public function testSongResourceMigrationDefinesExpectedSchema(): void
    {
        $migrationDir = dirname(__DIR__) . '/../db/migrations/';
        $files = glob($migrationDir . '*_create_song_resources_table.php');
        $migrationPath = $files[0] ?? '';
        $content = $migrationPath !== '' ? file_get_contents($migrationPath) : false;

        $this->assertIsString($content);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS song_resources', $content);
        $this->assertStringContainsString('resource_type varchar(32) NOT NULL', $content);
        $this->assertStringContainsString('url varchar(2048) DEFAULT NULL', $content);
        $this->assertStringContainsString(
            'CONSTRAINT song_resources_song_fk FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE',
            $content
        );
    }

    public function testSongModelHasLinkResourcesRelation(): void
    {
        $this->assertTrue(method_exists(Song::class, 'resources'));
        $this->assertTrue(method_exists(Song::class, 'linkResources'));
    }

    public function testSongLibraryControllerHasLinkCrudMethods(): void
    {
        $this->assertTrue(method_exists(SongLibraryController::class, 'createLinkResource'));
        $this->assertTrue(method_exists(SongLibraryController::class, 'updateLinkResource'));
        $this->assertTrue(method_exists(SongLibraryController::class, 'deleteLinkResource'));
    }

    public function testCreateLinkRejectsUnsupportedScheme(): void
    {
        $songId = (int) Capsule::table('songs')->insertGetId(['title' => 'Abendlied']);
        $controller = new SongLibraryController($this->createStub(Twig::class));

        $request = $this->makeRequest('POST', '/song-library/songs/' . $songId . '/resources/links', [
            'title' => 'MIDI Player',
            'url' => 'ftp://example.invalid/file.mid',
            'description' => 'Nicht erlaubt',
        ]);
        $response = $this->makeResponse();

        $result = $controller->createLinkResource($request, $response, ['id' => (string) $songId]);

        $this->assertRedirect($result, '/song-library/' . $songId);
        $this->assertSame('Link-URLs müssen mit http:// oder https:// beginnen.', $_SESSION['error']);
    }

    public function testDeleteLinkRejectsUnknownResourceId(): void
    {
        $songId = (int) Capsule::table('songs')->insertGetId(['title' => 'Abendlied']);
        $controller = new SongLibraryController($this->createStub(Twig::class));

        $request = $this->makeRequest('POST', '/song-library/songs/' . $songId . '/resources/links/999/delete');
        $response = $this->makeResponse();

        $result = $controller->deleteLinkResource($request, $response, [
            'song_id' => (string) $songId,
            'resource_id' => '999',
        ]);

        $this->assertRedirect($result, '/song-library/' . $songId);
        $this->assertSame('Link nicht gefunden.', $_SESSION['error']);
    }

    public function testSongDetailLoadsLinkResources(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("'linkResources' => function (" . '$' . "query)", $controllerContent);
        $this->assertStringContainsString("$" . "query->where('resource_type', 'link')", $controllerContent);
    }

    public function testSongDetailTemplateContainsLinkManagementForms(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/songs/detail.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('action="/song-library/songs/{{ song.id }}/resources/links"', $template);
        $this->assertStringContainsString('name="description"', $template);
        $this->assertStringContainsString('resources/links/{{ resource.id }}/update', $template);
        $this->assertStringContainsString('resources/links/{{ resource.id }}/delete', $template);
    }
}

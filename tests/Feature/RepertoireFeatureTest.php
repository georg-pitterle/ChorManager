<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\CategoryController;
use App\Controllers\ProjectSongAssignmentController;
use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectSongAssignment;
use App\Models\Song;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPUnit\Framework\TestCase;

class RepertoireFeatureTest extends TestCase
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
        if (!$schema->hasTable('project_song_assignments')) {
            $schema->create('project_song_assignments', function ($table): void {
                $table->increments('id');
                $table->integer('project_id');
                $table->integer('song_id');
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Capsule::table('project_song_assignments')->delete();
    }

    public function testMigrationForRepertoireTablesExists(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260421100000_add_repertoire_tables.php';

        $this->assertFileExists($migrationPath);
    }

    public function testMigrationDefinesExpectedRepertoireSchema(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260421100000_add_repertoire_tables.php';
        $content = file_get_contents($migrationPath);

        $this->assertIsString($content);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS repertoire_categories', $content);
        $this->assertStringContainsString('song_category_assignments', $content);
        $this->assertStringContainsString('repertoire_category_id int(11) NOT NULL', $content);
        $this->assertStringContainsString('PRIMARY KEY (song_id, repertoire_category_id)', $content);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS project_song_assignments', $content);
        $this->assertStringContainsString('PRIMARY KEY (id)', $content);
        $this->assertStringContainsString('UNIQUE KEY project_song_unique (project_id, song_id)', $content);
        $this->assertStringContainsString('note varchar(1000) DEFAULT NULL', $content);
        $this->assertStringContainsString('created_at timestamp NOT NULL DEFAULT current_timestamp()', $content);
    }

    public function testMigrationDownGuardsAgainstNullProjectIds(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260421100000_add_repertoire_tables.php';
        $content = file_get_contents($migrationPath);

        $this->assertIsString($content);
        $this->assertStringContainsString('SELECT COUNT(*) AS count FROM songs WHERE project_id IS NULL', $content);
        $this->assertStringContainsString('Rollback blocked: songs.project_id contains NULL values', $content);
        $this->assertStringContainsString('MODIFY COLUMN project_id int(11) NOT NULL', $content);
        $this->assertStringContainsString('ON DELETE CASCADE', $content);
    }

    public function testMigrationForSeedingAssignmentsExists(): void
    {
        $migrationDir = dirname(__DIR__) . '/../db/migrations/';
        $files = glob($migrationDir . '*_migrate_songs_to_project_assignments.php');

        $this->assertNotEmpty($files, 'Data migration file not found.');
    }

    public function testAssignmentBackfillMigrationDefinesExpectedInsertSemantics(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260421110000_migrate_songs_to_project_assignments.php';
        $content = file_get_contents($migrationPath);

        $this->assertIsString($content);
        $this->assertStringContainsString('INSERT INTO project_song_assignments', $content);
        $this->assertStringContainsString('SELECT project_id, id AS song_id, NULL AS note, created_at', $content);
        $this->assertStringContainsString('FROM songs', $content);
        $this->assertStringContainsString('WHERE project_id IS NOT NULL', $content);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE note = note', $content);
    }

    public function testAssignmentBackfillMigrationIsExplicitlyIrreversible(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260421110000_migrate_songs_to_project_assignments.php';
        $content = file_get_contents($migrationPath);

        $this->assertIsString($content);
        $this->assertStringContainsString('Irreversible migration: project_song_assignments backfill', $content);
    }

    public function testAssignmentIdCompatibilityMigrationExists(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260421113000_add_id_to_project_song_assignments.php';
        $content = file_get_contents($migrationPath);

        $this->assertFileExists($migrationPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('SHOW COLUMNS FROM project_song_assignments LIKE \'' . 'id' . '\'', $content);
        $this->assertStringContainsString('ADD COLUMN id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST', $content);
    }

    public function testDropProjectIdMigrationExists(): void
    {
        $migrationDir = dirname(__DIR__) . '/../db/migrations/';
        $files = glob($migrationDir . '*_drop_songs_project_id.php');
        $this->assertNotEmpty($files, 'Drop project_id migration file not found.');
    }

    public function testCategoryModelExists(): void
    {
        $this->assertTrue(class_exists(\App\Models\Category::class));
        $this->assertTrue(method_exists(\App\Models\Category::class, 'songs'));
    }

    public function testProjectSongAssignmentModelExists(): void
    {
        $this->assertTrue(class_exists(ProjectSongAssignment::class));
        $this->assertTrue(method_exists(ProjectSongAssignment::class, 'song'));
        $this->assertTrue(method_exists(ProjectSongAssignment::class, 'project'));

        $content = file_get_contents(dirname(__DIR__) . '/../src/Models/ProjectSongAssignment.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("protected \$table = 'project_song_assignments';", $content);
        $this->assertStringNotContainsString('public $incrementing = false;', $content);
        $this->assertStringNotContainsString('protected $primaryKey = null;', $content);
        $this->assertStringContainsString('belongsTo(Song::class, "song_id", "id")', str_replace("'", '"', $content));
        $this->assertStringContainsString('belongsTo(Project::class, "project_id", "id")', str_replace("'", '"', $content));
    }

    public function testSongModelHasRepertoireRelationships(): void
    {
        $this->assertTrue(method_exists(Song::class, 'categories'));
        $this->assertTrue(method_exists(Song::class, 'projectAssignments'));

        $content = file_get_contents(dirname(__DIR__) . '/../src/Models/Song.php');
        $this->assertIsString($content);
        $normalized = str_replace("'", '"', $content);
        $this->assertStringContainsString('belongsToMany(', $content);
        $this->assertStringContainsString('"song_category_assignments"', $normalized);
        $this->assertStringContainsString('"song_id"', $normalized);
        $this->assertStringContainsString('"repertoire_category_id"', $normalized);
        $this->assertStringContainsString('hasMany(ProjectSongAssignment::class, "song_id", "id")', $normalized);
    }

    public function testProjectModelHasAssignedSongs(): void
    {
        $this->assertTrue(method_exists(Project::class, 'assignedSongs'));

        $content = file_get_contents(dirname(__DIR__) . '/../src/Models/Project.php');
        $this->assertIsString($content);
        $normalized = str_replace("'", '"', $content);
        $this->assertStringContainsString('belongsToMany(', $content);
        $this->assertStringContainsString('"project_song_assignments"', $normalized);
        $this->assertStringContainsString('"project_id"', $normalized);
        $this->assertStringContainsString('"song_id"', $normalized);
        $this->assertStringContainsString('withPivot("note", "created_at")', $normalized);
    }

    public function testCategoryModelUsesExpectedPivotMapping(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Models/Category.php');
        $this->assertIsString($content);
        $normalized = str_replace("'", '"', $content);
        $this->assertStringContainsString('belongsToMany(', $content);
        $this->assertStringContainsString('"song_category_assignments"', $normalized);
        $this->assertStringContainsString('"repertoire_category_id"', $normalized);
        $this->assertStringContainsString('"song_id"', $normalized);
    }

    public function testRuntimeRelationsResolveToExpectedRelationTypes(): void
    {
        $this->assertInstanceOf(BelongsToMany::class, (new Category())->songs());
        $this->assertInstanceOf(BelongsTo::class, (new ProjectSongAssignment())->song());
        $this->assertInstanceOf(BelongsTo::class, (new ProjectSongAssignment())->project());
        $this->assertInstanceOf(BelongsToMany::class, (new Song())->categories());
        $this->assertInstanceOf(HasMany::class, (new Song())->projectAssignments());
        $this->assertInstanceOf(BelongsToMany::class, (new Project())->assignedSongs());
    }

    public function testCategoryControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\CategoryController::class));
        $this->assertTrue(method_exists(\App\Controllers\CategoryController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\CategoryController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\CategoryController::class, 'delete'));
    }

    public function testProjectSongAssignmentControllerExists(): void
    {
        $this->assertTrue(class_exists(ProjectSongAssignmentController::class));
        $this->assertTrue(method_exists(ProjectSongAssignmentController::class, 'create'));
        $this->assertTrue(method_exists(ProjectSongAssignmentController::class, 'update'));
        $this->assertTrue(method_exists(ProjectSongAssignmentController::class, 'delete'));

        $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/ProjectSongAssignmentController.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('ProjectSongAssignment::find($id)', $content);
        $this->assertStringNotContainsString('where(\'song_id\', $id)', $content);
    }

    public function testCategoryCreateRejectsEmptyNameBeforeDatabaseAccess(): void
    {
        $controller = new CategoryController();

        $request = $this->makeRequest('POST', '/song-library/categories', ['name' => '   ']);
        $response = $this->makeResponse();

        $result = $controller->create($request, $response);

        $this->assertRedirect($result, '/song-library');
        $this->assertSame('Kategoriename ist ein Pflichtfeld.', $_SESSION['error']);
    }

    public function testCategoryCreateRejectsInvalidSortOrderBeforeDatabaseAccess(): void
    {
        $controller = new CategoryController();

        $request = $this->makeRequest('POST', '/song-library/categories', [
            'name' => 'Sakral',
            'sort_order' => '-1',
        ]);
        $response = $this->makeResponse();

        $result = $controller->create($request, $response);

        $this->assertRedirect($result, '/song-library');
        $this->assertSame('Sortierung muss eine ganze Zahl zwischen 0 und 9999 sein.', $_SESSION['error']);
    }

    public function testCategoryUpdateRejectsInvalidIdBeforeDatabaseAccess(): void
    {
        $controller = new CategoryController();

        $request = $this->makeRequest('POST', '/song-library/categories/0/update', [
            'name' => 'Sakral',
            'sort_order' => '10',
        ]);
        $response = $this->makeResponse();

        $result = $controller->update($request, $response, ['id' => '0']);

        $this->assertRedirect($result, '/song-library');
        $this->assertSame('Kategorie nicht gefunden.', $_SESSION['error']);
    }

    public function testAssignmentUpdateUpdatesNoteByAssignmentId(): void
    {
        $id = (int) Capsule::table('project_song_assignments')->insertGetId([
            'project_id' => 101,
            'song_id' => 202,
            'note' => 'alt',
            'created_at' => '2026-04-21 10:00:00',
        ]);

        $controller = new ProjectSongAssignmentController();
        $request = $this->makeRequest('POST', '/song-library/assignments/' . $id . '/update', [
            'note' => 'neu',
        ]);
        $response = $this->makeResponse();

        $result = $controller->update($request, $response, ['id' => (string) $id]);

        $this->assertRedirect($result, '/song-library/202');
        $this->assertSame('Zuordnung erfolgreich aktualisiert.', $_SESSION['success']);
        $this->assertSame('neu', Capsule::table('project_song_assignments')->where('id', $id)->value('note'));
    }

    public function testAssignmentDeleteRemovesAssignmentById(): void
    {
        $id = (int) Capsule::table('project_song_assignments')->insertGetId([
            'project_id' => 111,
            'song_id' => 222,
            'note' => 'x',
            'created_at' => '2026-04-21 10:00:00',
        ]);

        $controller = new ProjectSongAssignmentController();
        $request = $this->makeRequest('POST', '/song-library/assignments/' . $id . '/delete');
        $response = $this->makeResponse();

        $result = $controller->delete($request, $response, ['id' => (string) $id]);

        $this->assertRedirect($result, '/song-library');
        $this->assertSame('Zuordnung erfolgreich geloescht.', $_SESSION['success']);
        $this->assertSame(0, Capsule::table('project_song_assignments')->where('id', $id)->count());
    }

    public function testAssignmentUpdateRejectsUnknownId(): void
    {
        $controller = new ProjectSongAssignmentController();
        $request = $this->makeRequest('POST', '/song-library/assignments/999/update', [
            'note' => 'neu',
        ]);
        $response = $this->makeResponse();

        $result = $controller->update($request, $response, ['id' => '999']);

        $this->assertRedirect($result, '/song-library');
        $this->assertSame('Zuordnung nicht gefunden.', $_SESSION['error']);
    }

    public function testRoutesIncludeCategoryEndpoints(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/song-library'", $routesContent);
        $this->assertStringContainsString("'/categories'", $routesContent);
        $this->assertStringContainsString("'/categories/{id:[0-9]+}/update'", $routesContent);
        $this->assertStringContainsString("'/categories/{id:[0-9]+}/delete'", $routesContent);
        $this->assertStringContainsString('CategoryController::class', $routesContent);
    }

    public function testRoutesIncludeAssignmentEndpoints(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/song-library'", $routesContent);
        $this->assertStringContainsString("'/assignments'", $routesContent);
        $this->assertStringContainsString("'/assignments/{id:[0-9]+}/update'", $routesContent);
        $this->assertStringContainsString("'/assignments/{id:[0-9]+}/delete'", $routesContent);
        $this->assertStringContainsString('ProjectSongAssignmentController::class', $routesContent);
    }

    public function testSongLibraryControllerHasNewMethods(): void
    {
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'show'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'syncCategories'));
    }

    public function testSongCreationNoLongerRequiresProjectId(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');
        $this->assertIsString($content);
        $this->assertStringNotContainsString(
            '$projectId <= 0 || !Project::find($projectId)',
            $content
        );
    }

    public function testRoutesIncludeSongDetailEndpoint(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/song-library'", $routesContent);
        $this->assertStringContainsString("'/{id:[0-9]+}'", $routesContent);
        $this->assertStringContainsString("'show'", $routesContent);
    }
}

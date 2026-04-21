# Repertoire Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the project-scoped song library with a global repertoire that supports multi-category assignment, project usage with notes, and a redesigned management UI.

**Architecture:** Songs become standalone global records. A new `repertoire_categories` table and `song_category_assignments` pivot enable multi-category tagging. A `project_song_assignments` table replaces the direct `songs.project_id` foreign key and becomes the single source of truth for which songs appear in a project's download area. The management UI is redesigned as a searchable list with a dedicated song-detail page; the download area stays project-grouped but reads from the new assignment table.

**Tech Stack:** PHP 8, Slim 4, Eloquent ORM, Phinx migrations, Twig 3, Bootstrap 5, PHPUnit 10

---

## File Map

### New files
| Path                                                                    | Responsibility                                                                                                         |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `db/migrations/20260421100000_add_repertoire_tables.php`                | Add `repertoire_categories`, `song_category_assignments`, `project_song_assignments`; make `songs.project_id` nullable |
| `db/migrations/20260421110000_migrate_songs_to_project_assignments.php` | Copy existing `songs.project_id` rows into `project_song_assignments`                                                  |
| `db/migrations/20260421120000_drop_songs_project_id.php`                | Drop `songs.project_id` column (applied last, after all code changes)                                                  |
| `src/Models/Category.php`                                               | Eloquent model for `repertoire_categories`                                                                             |
| `src/Models/ProjectSongAssignment.php`                                  | Eloquent model for `project_song_assignments`                                                                          |
| `src/Controllers/CategoryController.php`                                | CRUD actions for categories (create, update, delete)                                                                   |
| `src/Controllers/ProjectSongAssignmentController.php`                   | Create / update-note / delete song-project assignments                                                                 |
| `templates/songs/detail.twig`                                           | Song detail page (master data, categories, attachments, project assignments)                                           |
| `tests/Feature/RepertoireFeatureTest.php`                               | Feature tests for the new repertoire domain                                                                            |

### Modified files
| Path                                        | Change summary                                                                                                    |
| ------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `src/Models/Song.php`                       | Add `categories()` and `projectAssignments()` relationships; remove `project_id` from `$fillable` after migration |
| `src/Models/Project.php`                    | Add `assignedSongs()` relationship via `project_song_assignments`                                                 |
| `src/Controllers/SongLibraryController.php` | Refactor `index()` to global list; add `show()`, `syncCategories()`; remove project_id guard from `createSong()`  |
| `src/Controllers/DownloadController.php`    | Use `assignedSongs` eager load; update `findMemberAttachment()` to query through `project_song_assignments`       |
| `src/Routes.php`                            | Add routes for detail, categories CRUD, assignment CRUD                                                           |
| `templates/songs/manage.twig`               | Replace project accordion with searchable repertoire card list                                                    |
| `templates/songs/downloads.twig`            | Replace `project.songs` with `project.assignedSongs`                                                              |
| `src/Services/DevSeedService.php`           | Seed global songs, categories, song-category links, project assignments                                           |
| `tests/Feature/SongLibraryFeatureTest.php`  | Update assertions to match new controller shape                                                                   |

---

## Task 1: Migration — add repertoire tables and make `songs.project_id` nullable

**Files:**
- Create: `db/migrations/20260421100000_add_repertoire_tables.php`

- [ ] **Step 1: Write the failing test**

Add to a new file `tests/Feature/RepertoireFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class RepertoireFeatureTest extends TestCase
{
    public function testMigrationForRepertoireTablesExists(): void
    {
        $migrationDir = dirname(__DIR__) . '/../db/migrations/';
        $files = glob($migrationDir . '*_add_repertoire_tables.php');
        $this->assertNotEmpty($files, 'Migration file for repertoire tables not found.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testMigrationForRepertoireTablesExists
```

Expected: FAIL — `Migration file for repertoire tables not found.`

- [ ] **Step 3: Create the migration**

Create `db/migrations/20260421100000_add_repertoire_tables.php`:

```php
<?php

use Phinx\Migration\AbstractMigration;

final class AddRepertoireTables extends AbstractMigration
{
    public function up(): void
    {
        // Make songs.project_id nullable so songs can exist without a project
        $this->execute('ALTER TABLE songs
            MODIFY project_id int(11) DEFAULT NULL,
            DROP FOREIGN KEY songs_ibfk_1');
        $this->execute('ALTER TABLE songs
            ADD CONSTRAINT songs_ibfk_1
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');

        // Repertoire categories
        $this->execute("CREATE TABLE IF NOT EXISTS repertoire_categories (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY repertoire_categories_name_unique (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // Song ↔ category many-to-many
        $this->execute("CREATE TABLE IF NOT EXISTS song_category_assignments (
            song_id int(11) NOT NULL,
            category_id int(11) NOT NULL,
            PRIMARY KEY (song_id, category_id),
            CONSTRAINT song_cat_fk_song FOREIGN KEY (song_id)
                REFERENCES songs (id) ON DELETE CASCADE,
            CONSTRAINT song_cat_fk_category FOREIGN KEY (category_id)
                REFERENCES repertoire_categories (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // Song ↔ project many-to-many with note
        $this->execute("CREATE TABLE IF NOT EXISTS project_song_assignments (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) NOT NULL,
            song_id int(11) NOT NULL,
            note text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY project_song_unique (project_id, song_id),
            CONSTRAINT proj_song_fk_project FOREIGN KEY (project_id)
                REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT proj_song_fk_song FOREIGN KEY (song_id)
                REFERENCES songs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS project_song_assignments;');
        $this->execute('DROP TABLE IF EXISTS song_category_assignments;');
        $this->execute('DROP TABLE IF EXISTS repertoire_categories;');

        $this->execute('ALTER TABLE songs
            DROP FOREIGN KEY songs_ibfk_1');
        $this->execute('ALTER TABLE songs
            MODIFY project_id int(11) NOT NULL');
        $this->execute('ALTER TABLE songs
            ADD CONSTRAINT songs_ibfk_1
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testMigrationForRepertoireTablesExists
```

Expected: PASS

- [ ] **Step 5: Apply migration and verify schema**

```bash
ddev exec vendor/bin/phinx migrate
```

Expected output includes `== 20260421100000 AddRepertoireTables: migrated`

- [ ] **Step 6: Commit**

```bash
git add db/migrations/20260421100000_add_repertoire_tables.php tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(db): add repertoire_categories, song_category_assignments, project_song_assignments tables"
```

---

## Task 2: Migration — populate `project_song_assignments` from existing `songs.project_id`

**Files:**
- Create: `db/migrations/20260421110000_migrate_songs_to_project_assignments.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testMigrationForSeedingAssignmentsExists(): void
{
    $migrationDir = dirname(__DIR__) . '/../db/migrations/';
    $files = glob($migrationDir . '*_migrate_songs_to_project_assignments.php');
    $this->assertNotEmpty($files, 'Data migration file not found.');
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testMigrationForSeedingAssignmentsExists
```

Expected: FAIL

- [ ] **Step 3: Create the migration**

Create `db/migrations/20260421110000_migrate_songs_to_project_assignments.php`:

```php
<?php

use Phinx\Migration\AbstractMigration;

final class MigrateSongsToProjectAssignments extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            INSERT INTO project_song_assignments (project_id, song_id, note, created_at)
            SELECT project_id, id, NULL, created_at
            FROM songs
            WHERE project_id IS NOT NULL
            ON DUPLICATE KEY UPDATE note = note
        ");
    }

    public function down(): void
    {
        // Removing seeded assignments is destructive and non-reversible in production.
        // Down intentionally left as no-op.
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testMigrationForSeedingAssignmentsExists
```

Expected: PASS

- [ ] **Step 5: Apply migration and spot-check data**

```bash
ddev exec vendor/bin/phinx migrate
ddev exec php -r "
require 'vendor/autoload.php';
\$c = require 'bootstrap.php';
\$count = \Illuminate\Database\Capsule\Manager::table('project_song_assignments')->count();
echo 'Assignments: ' . \$count . PHP_EOL;
"
```

Expected: count matches the number of songs that previously had a project_id.

- [ ] **Step 6: Commit**

```bash
git add db/migrations/20260421110000_migrate_songs_to_project_assignments.php
git commit -m "feat(db): migrate existing songs.project_id into project_song_assignments"
```

---

## Task 3: Models — `Category`, `ProjectSongAssignment`, update `Song`, update `Project`

**Files:**
- Create: `src/Models/Category.php`
- Create: `src/Models/ProjectSongAssignment.php`
- Modify: `src/Models/Song.php`
- Modify: `src/Models/Project.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testCategoryModelExists(): void
{
    $this->assertTrue(class_exists(\App\Models\Category::class));
    $this->assertTrue(method_exists(\App\Models\Category::class, 'songs'));
}

public function testProjectSongAssignmentModelExists(): void
{
    $this->assertTrue(class_exists(\App\Models\ProjectSongAssignment::class));
    $this->assertTrue(method_exists(\App\Models\ProjectSongAssignment::class, 'song'));
    $this->assertTrue(method_exists(\App\Models\ProjectSongAssignment::class, 'project'));
}

public function testSongModelHasRepertoireRelationships(): void
{
    $this->assertTrue(method_exists(\App\Models\Song::class, 'categories'));
    $this->assertTrue(method_exists(\App\Models\Song::class, 'projectAssignments'));
}

public function testProjectModelHasAssignedSongs(): void
{
    $this->assertTrue(method_exists(\App\Models\Project::class, 'assignedSongs'));
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testCategoryModelExists|testProjectSongAssignmentModelExists|testSongModelHasRepertoireRelationships|testProjectModelHasAssignedSongs"
```

Expected: all 4 FAIL

- [ ] **Step 3: Create `src/Models/Category.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'repertoire_categories';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'sort_order',
    ];

    public function songs()
    {
        return $this->belongsToMany(Song::class, 'song_category_assignments', 'category_id', 'song_id');
    }
}
```

- [ ] **Step 4: Create `src/Models/ProjectSongAssignment.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectSongAssignment extends Model
{
    protected $table = 'project_song_assignments';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'song_id',
        'note',
    ];

    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }
}
```

- [ ] **Step 5: Update `src/Models/Song.php`**

Add two new relationships. The existing `project()` relationship is kept for now (it is used by the seed migration in Task 2); it will be removed only in Task 14.

Replace the closing `}` brace of `Song` with the two new methods and then the brace:

```php
    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'song_category_assignments',
            'song_id',
            'category_id'
        );
    }

    public function projectAssignments()
    {
        return $this->hasMany(ProjectSongAssignment::class, 'song_id', 'id');
    }
}
```

Full resulting `src/Models/Song.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    protected $table = 'songs';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'title',
        'composer',
        'arranger',
        'publisher',
        'created_by_user_id',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id', 'id')->where('entity_type', 'song');
    }

    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'song_category_assignments',
            'song_id',
            'category_id'
        );
    }

    public function projectAssignments()
    {
        return $this->hasMany(ProjectSongAssignment::class, 'song_id', 'id');
    }
}
```

- [ ] **Step 6: Update `src/Models/Project.php`**

Add `assignedSongs()` after the existing `songs()` method:

```php
    public function assignedSongs()
    {
        return $this->belongsToMany(
            Song::class,
            'project_song_assignments',
            'project_id',
            'song_id'
        )->withPivot('note', 'created_at');
    }
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testCategoryModelExists|testProjectSongAssignmentModelExists|testSongModelHasRepertoireRelationships|testProjectModelHasAssignedSongs"
```

Expected: all 4 PASS

- [ ] **Step 8: Commit**

```bash
git add src/Models/Category.php src/Models/ProjectSongAssignment.php src/Models/Song.php src/Models/Project.php tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(model): add Category, ProjectSongAssignment; add relationships to Song and Project"
```

---

## Task 4: `CategoryController` + routes

**Files:**
- Create: `src/Controllers/CategoryController.php`
- Modify: `src/Routes.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testCategoryControllerExists(): void
{
    $this->assertTrue(class_exists(\App\Controllers\CategoryController::class));
    $this->assertTrue(method_exists(\App\Controllers\CategoryController::class, 'create'));
    $this->assertTrue(method_exists(\App\Controllers\CategoryController::class, 'update'));
    $this->assertTrue(method_exists(\App\Controllers\CategoryController::class, 'delete'));
}

public function testRoutesIncludeCategoryEndpoints(): void
{
    $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
    $this->assertIsString($routesContent);
    $this->assertStringContainsString('/song-library/categories', $routesContent);
    $this->assertStringContainsString('CategoryController', $routesContent);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testCategoryControllerExists|testRoutesIncludeCategoryEndpoints"
```

Expected: both FAIL

- [ ] **Step 3: Create `src/Controllers/CategoryController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoryController
{
    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if ($name === '') {
            $_SESSION['error'] = 'Kategoriename ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        if (Category::where('name', $name)->exists()) {
            $_SESSION['error'] = 'Eine Kategorie mit diesem Namen existiert bereits.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        Category::create([
            'name' => $name,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $_SESSION['success'] = 'Kategorie erfolgreich angelegt.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $category = Category::find($id);

        if (!$category) {
            $_SESSION['error'] = 'Kategorie nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if ($name === '') {
            $_SESSION['error'] = 'Kategoriename ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        if (Category::where('name', $name)->where('id', '!=', $id)->exists()) {
            $_SESSION['error'] = 'Eine Kategorie mit diesem Namen existiert bereits.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $category->update([
            'name' => $name,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $_SESSION['success'] = 'Kategorie erfolgreich aktualisiert.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $category = Category::find($id);

        if (!$category) {
            $_SESSION['error'] = 'Kategorie nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        // Detach all songs before deleting
        $category->songs()->detach();
        $category->delete();

        $_SESSION['success'] = 'Kategorie erfolgreich gelöscht.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }
}
```

- [ ] **Step 4: Add routes in `src/Routes.php`**

Add the import at the top with the other controller imports:

```php
use App\Controllers\CategoryController;
```

Then inside the existing `/song-library` group, after the last `$songsGroup->post(...)` line:

```php
                    // Category management
                    $songsGroup->post('/categories', [CategoryController::class, 'create']);
                    $songsGroup->post(
                        '/categories/{id:[0-9]+}/update',
                        [CategoryController::class, 'update']
                    );
                    $songsGroup->post(
                        '/categories/{id:[0-9]+}/delete',
                        [CategoryController::class, 'delete']
                    );
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testCategoryControllerExists|testRoutesIncludeCategoryEndpoints"
```

Expected: both PASS

- [ ] **Step 6: Run PHP lint**

```bash
ddev exec php -l src/Controllers/CategoryController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/CategoryController.php src/Routes.php tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(category): add CategoryController and category management routes"
```

---

## Task 5: `ProjectSongAssignmentController` + routes

**Files:**
- Create: `src/Controllers/ProjectSongAssignmentController.php`
- Modify: `src/Routes.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testProjectSongAssignmentControllerExists(): void
{
    $this->assertTrue(class_exists(\App\Controllers\ProjectSongAssignmentController::class));
    $this->assertTrue(method_exists(\App\Controllers\ProjectSongAssignmentController::class, 'create'));
    $this->assertTrue(method_exists(\App\Controllers\ProjectSongAssignmentController::class, 'update'));
    $this->assertTrue(method_exists(\App\Controllers\ProjectSongAssignmentController::class, 'delete'));
}

public function testRoutesIncludeAssignmentEndpoints(): void
{
    $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
    $this->assertIsString($routesContent);
    $this->assertStringContainsString('/song-library/assignments', $routesContent);
    $this->assertStringContainsString('ProjectSongAssignmentController', $routesContent);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testProjectSongAssignmentControllerExists|testRoutesIncludeAssignmentEndpoints"
```

Expected: both FAIL

- [ ] **Step 3: Create `src/Controllers/ProjectSongAssignmentController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\ProjectSongAssignment;
use App\Models\Song;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProjectSongAssignmentController
{
    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $songId = (int) ($data['song_id'] ?? 0);
        $projectId = (int) ($data['project_id'] ?? 0);

        if ($songId <= 0 || !Song::find($songId)) {
            $_SESSION['error'] = 'Ungültiges Lied.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        if ($projectId <= 0 || !Project::find($projectId)) {
            $_SESSION['error'] = 'Ungültiges Projekt.';
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        if (ProjectSongAssignment::where('song_id', $songId)->where('project_id', $projectId)->exists()) {
            $_SESSION['error'] = 'Das Lied ist diesem Projekt bereits zugewiesen.';
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        ProjectSongAssignment::create([
            'song_id' => $songId,
            'project_id' => $projectId,
            'note' => trim($data['note'] ?? '') ?: null,
        ]);

        $_SESSION['success'] = 'Lied dem Projekt zugewiesen.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $assignment = ProjectSongAssignment::find($id);

        if (!$assignment) {
            $_SESSION['error'] = 'Zuweisung nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $assignment->update(['note' => trim($data['note'] ?? '') ?: null]);

        $_SESSION['success'] = 'Notiz aktualisiert.';
        return $response->withHeader('Location', '/song-library/' . $assignment->song_id)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $assignment = ProjectSongAssignment::find($id);

        if (!$assignment) {
            $_SESSION['error'] = 'Zuweisung nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $songId = (int) $assignment->song_id;
        $assignment->delete();

        $_SESSION['success'] = 'Projektzuweisung entfernt.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }
}
```

- [ ] **Step 4: Add routes in `src/Routes.php`**

Add the import at the top with the other controller imports:

```php
use App\Controllers\ProjectSongAssignmentController;
```

Inside the `/song-library` group, after the category routes added in Task 4:

```php
                    // Project-song assignments
                    $songsGroup->post('/assignments', [ProjectSongAssignmentController::class, 'create']);
                    $songsGroup->post(
                        '/assignments/{id:[0-9]+}/update',
                        [ProjectSongAssignmentController::class, 'update']
                    );
                    $songsGroup->post(
                        '/assignments/{id:[0-9]+}/delete',
                        [ProjectSongAssignmentController::class, 'delete']
                    );
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testProjectSongAssignmentControllerExists|testRoutesIncludeAssignmentEndpoints"
```

Expected: both PASS

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/ProjectSongAssignmentController.php src/Routes.php tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(assignment): add ProjectSongAssignmentController and assignment routes"
```

---

## Task 6: Refactor `SongLibraryController` — global list, song detail, category sync, remove project_id guard

**Files:**
- Modify: `src/Controllers/SongLibraryController.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
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
    $this->assertStringContainsString("'/song-library/{id:[0-9]+}'", $routesContent);
    $this->assertStringContainsString("'show'", $routesContent);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testSongLibraryControllerHasNewMethods|testSongCreationNoLongerRequiresProjectId|testRoutesIncludeSongDetailEndpoint"
```

Expected: all 3 FAIL

- [ ] **Step 3: Replace `src/Controllers/SongLibraryController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Models\Project;
use App\Models\Song;
use App\Models\Attachment;
use App\Util\UploadValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SongLibraryController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $search = trim($queryParams['search'] ?? '');
        $categoryId = (int) ($queryParams['category'] ?? 0);

        $query = Song::with(['categories', 'projectAssignments', 'attachments'])
            ->orderBy('title', 'asc');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhere('composer', 'like', '%' . $search . '%')
                  ->orWhere('arranger', 'like', '%' . $search . '%');
            });
        }

        if ($categoryId > 0) {
            $query->whereHas(
                'categories',
                fn($q) => $q->where('repertoire_categories.id', $categoryId)
            );
        }

        $songs = $query->get();
        $categories = Category::orderBy('sort_order', 'asc')->orderBy('name', 'asc')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'songs/manage.twig', [
            'songs' => $songs,
            'categories' => $categories,
            'search' => $search,
            'selected_category_id' => $categoryId,
            'success' => $success,
            'error' => $error,
            'active_nav' => 'song_library',
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::with([
            'categories',
            'attachments' => fn($q) => $q->orderBy('original_name', 'asc'),
            'projectAssignments.project',
        ])->find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $allCategories = Category::orderBy('sort_order', 'asc')->orderBy('name', 'asc')->get();
        $allProjects = Project::orderBy('name', 'asc')->get();
        $assignedProjectIds = $song->projectAssignments->pluck('project_id')->toArray();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'songs/detail.twig', [
            'song' => $song,
            'all_categories' => $allCategories,
            'all_projects' => $allProjects,
            'assigned_project_ids' => $assignedProjectIds,
            'success' => $success,
            'error' => $error,
            'active_nav' => 'song_library',
        ]);
    }

    public function createSong(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $title = trim($data['title'] ?? '');

        if ($title === '') {
            $_SESSION['error'] = 'Der Liedtitel ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        Song::create([
            'title' => $title,
            'composer' => trim($data['composer'] ?? '') ?: null,
            'arranger' => trim($data['arranger'] ?? '') ?: null,
            'publisher' => trim($data['publisher'] ?? '') ?: null,
            'created_by_user_id' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
        ]);

        $_SESSION['success'] = 'Lied erfolgreich angelegt.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    public function updateSong(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $title = trim($data['title'] ?? '');

        if ($title === '') {
            $_SESSION['error'] = 'Der Liedtitel ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $song->update([
            'title' => $title,
            'composer' => trim($data['composer'] ?? '') ?: null,
            'arranger' => trim($data['arranger'] ?? '') ?: null,
            'publisher' => trim($data['publisher'] ?? '') ?: null,
        ]);

        $_SESSION['success'] = 'Lied erfolgreich aktualisiert.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }

    public function deleteSong(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        Attachment::where('entity_type', 'song')
            ->where('entity_id', $songId)
            ->delete();
        $song->delete();

        $_SESSION['success'] = 'Lied erfolgreich gelöscht.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    public function syncCategories(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $categoryIds = array_values(
            array_filter(array_map('intval', (array) ($data['category_ids'] ?? [])))
        );
        $song->categories()->sync($categoryIds);

        $_SESSION['success'] = 'Kategorien erfolgreich aktualisiert.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }

    public function uploadAttachments(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (!isset($uploadedFiles['attachments'])) {
            $_SESSION['error'] = 'Keine Dateien uebergeben.';
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $files = $uploadedFiles['attachments'];
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $mimeType = trim((string) $file->getClientMediaType()) ?: 'application/octet-stream';
            $contents = $file->getStream()->getContents();
            $size = strlen($contents);
            if ($size <= 0) {
                $_SESSION['error'] = 'Leere Dateien sind nicht erlaubt.';
                return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
            }

            $validation = UploadValidator::validateFileSize($size, $mimeType);
            if (!$validation['valid']) {
                $_SESSION['error'] = $validation['error'];
                return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
            }

            $originalName = trim((string) $file->getClientFilename());
            if ($originalName === '') {
                $_SESSION['error'] = 'Dateiname fehlt.';
                return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
            }

            Attachment::create([
                'entity_type' => 'song',
                'entity_id' => $songId,
                'filename' => bin2hex(random_bytes(16)) . '_' . $originalName,
                'original_name' => $originalName,
                'mime_type' => UploadValidator::normalizeMimeType($mimeType),
                'file_size' => $size,
                'file_content' => $contents,
            ]);
        }

        $_SESSION['success'] = 'Dateien erfolgreich hochgeladen.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['song_id'] ?? 0);
        $attachmentId = (int) ($args['attachment_id'] ?? 0);

        $attachment = Attachment::where('entity_type', 'song')->find($attachmentId);
        if (!$attachment || (int) $attachment->entity_id !== $songId) {
            $_SESSION['error'] = 'Anhang nicht gefunden.';
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $attachment->delete();
        $_SESSION['success'] = 'Anhang erfolgreich gelöscht.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }
}
```

- [ ] **Step 4: Add GET detail route in `src/Routes.php`**

Inside the `/song-library` group, after the existing `$songsGroup->get('', ...)` line:

```php
                    $songsGroup->get('/{id:[0-9]+}', [SongLibraryController::class, 'show']);
```

Also add the `syncCategories` route after the delete attachment route:

```php
                    $songsGroup->post('/songs/{id:[0-9]+}/categories', [SongLibraryController::class, 'syncCategories']);
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testSongLibraryControllerHasNewMethods|testSongCreationNoLongerRequiresProjectId|testRoutesIncludeSongDetailEndpoint"
```

Expected: all 3 PASS

- [ ] **Step 6: Run full feature test suite**

```bash
ddev exec vendor/bin/phpunit tests/Feature/SongLibraryFeatureTest.php tests/Feature/RepertoireFeatureTest.php
```

Expected: all existing SongLibraryFeatureTest tests still PASS (the test for `new RoleMiddleware` and route string assertions are unaffected); all new tests PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/SongLibraryController.php src/Routes.php tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(repertoire): refactor SongLibraryController to global list, add show() and syncCategories()"
```

---

## Task 7: Refactor `DownloadController` — use `assignedSongs` and updated authorization

**Files:**
- Modify: `src/Controllers/DownloadController.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testDownloadUsesAssignedSongsRelationship(): void
{
    $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DownloadController.php');
    $this->assertIsString($content);
    $this->assertStringContainsString("'assignedSongs'", $content);
    $this->assertStringContainsString('project_song_assignments', $content);
}

public function testDownloadDoesNotJoinOnSongsProjectId(): void
{
    $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DownloadController.php');
    $this->assertIsString($content);
    $this->assertStringNotContainsString("'project_users.project_id', '=', 'songs.project_id'", $content);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testDownloadUsesAssignedSongsRelationship|testDownloadDoesNotJoinOnSongsProjectId"
```

Expected: both FAIL

- [ ] **Step 3: Update `index()` in `src/Controllers/DownloadController.php`**

Replace the `$projects = Project::query()...->get();` block inside `index()` with:

```php
        $projects = Project::query()
            ->select('projects.*')
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->with([
                'assignedSongs' => function ($query) {
                    $query->orderBy('title', 'asc');
                },
                'assignedSongs.attachments' => function ($query) {
                    $query->orderBy('original_name', 'asc');
                },
            ])
            ->distinct()
            ->orderBy('projects.name', 'asc')
            ->get();
```

- [ ] **Step 4: Update `findMemberAttachment()` in `src/Controllers/DownloadController.php`**

Replace the full private method with:

```php
    private function findMemberAttachment(int $userId, int $attachmentId): ?Attachment
    {
        if ($userId <= 0 || $attachmentId <= 0) {
            return null;
        }

        return Attachment::query()
            ->where('id', $attachmentId)
            ->where('entity_type', 'song')
            ->whereExists(function ($query) use ($userId) {
                $query->selectRaw('1')
                    ->from('songs')
                    ->join(
                        'project_song_assignments',
                        'project_song_assignments.song_id',
                        '=',
                        'songs.id'
                    )
                    ->join(
                        'project_users',
                        'project_users.project_id',
                        '=',
                        'project_song_assignments.project_id'
                    )
                    ->whereColumn('songs.id', 'attachments.entity_id')
                    ->where('project_users.user_id', $userId);
            })
            ->first();
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testDownloadUsesAssignedSongsRelationship|testDownloadDoesNotJoinOnSongsProjectId"
```

Expected: both PASS

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/DownloadController.php tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(download): load songs via project_song_assignments; update attachment authorization"
```

---

## Task 8: Template — `manage.twig` (repertoire list)

**Files:**
- Modify: `templates/songs/manage.twig`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testManageTemplateUsesGlobalSongList(): void
{
    $content = file_get_contents(dirname(__DIR__) . '/../templates/songs/manage.twig');
    $this->assertIsString($content);
    $this->assertStringContainsString('for song in songs', $content);
    $this->assertStringNotContainsString('for project in projects', $content);
    $this->assertStringContainsString('song-library/{{ song.id }}', $content);
    $this->assertStringContainsString('manageCategoriesModal', $content);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testManageTemplateUsesGlobalSongList
```

Expected: FAIL

- [ ] **Step 3: Replace `templates/songs/manage.twig`**

```twig
{% extends 'layout.twig' %}

{% block title %}
    Repertoire - {{ app_settings.app_name|default('Chor-Manager') }}
{% endblock title %}

{% block page_header %}
    <section class="page-header">
        <div>
            <p class="text-uppercase text-muted small mb-1">Bereiche</p>
            <h1 class="h2 mb-1">Repertoire</h1>
            <p class="text-muted mb-0">Alle Lieder zentral verwalten und Projekten zuweisen.</p>
        </div>
        <div class="page-actions">
            <button type="button"
                    class="btn btn-outline-secondary"
                    data-bs-toggle="modal"
                    data-bs-target="#manageCategoriesModal">
                <i class="bi bi-tags"></i> Kategorien
            </button>
            <button type="button"
                    class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#addSongModal">
                <i class="bi bi-music-note-list"></i> Neues Lied
            </button>
        </div>
    </section>
{% endblock page_header %}

{% block content %}
    {% if success %}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ success }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    {% endif %}
    {% if error %}
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ error }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    {% endif %}

    <form class="row g-2 mb-4" method="get" action="/song-library">
        <div class="col-md-6">
            <input type="text"
                   name="search"
                   class="form-control"
                   placeholder="Titel, Komponist, Arrangeur..."
                   value="{{ search }}">
        </div>
        <div class="col-md-4">
            <select name="category" class="form-select">
                <option value="">Alle Kategorien</option>
                {% for cat in categories %}
                    <option value="{{ cat.id }}"
                            {% if selected_category_id == cat.id %}selected{% endif %}>
                        {{ cat.name }}
                    </option>
                {% endfor %}
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary flex-grow-1">
                <i class="bi bi-search"></i>
            </button>
            {% if search or selected_category_id %}
                <a href="/song-library" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
            {% endif %}
        </div>
    </form>

    <div class="row g-3">
        {% for song in songs %}
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="flex-grow-1 min-width-0">
                            <a href="/song-library/{{ song.id }}"
                               class="fw-semibold text-decoration-none fs-5 stretched-link-exclude">
                                {{ song.title }}
                            </a>
                            <div class="text-muted small mt-1">
                                {% if song.composer %}{{ song.composer }}{% endif %}
                                {% if song.arranger %} &middot; Arr. {{ song.arranger }}{% endif %}
                                {% if song.publisher %} &middot; {{ song.publisher }}{% endif %}
                            </div>
                            {% if song.categories|length > 0 %}
                                <div class="mt-2 d-flex flex-wrap gap-1">
                                    {% for cat in song.categories %}
                                        <span class="badge bg-primary-subtle text-primary-emphasis">
                                            {{ cat.name }}
                                        </span>
                                    {% endfor %}
                                </div>
                            {% endif %}
                        </div>
                        <div class="d-none d-md-flex gap-4 text-center text-muted small flex-shrink-0">
                            <div>
                                <div class="fw-semibold text-dark">{{ song.attachments|length }}</div>
                                <div>Dateien</div>
                            </div>
                            <div>
                                <div class="fw-semibold text-dark">{{ song.projectAssignments|length }}</div>
                                <div>Projekte</div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-shrink-0">
                            <a href="/song-library/{{ song.id }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="/song-library/songs/{{ song.id }}/delete"
                                  method="post"
                                  class="d-inline"
                                  data-confirm="Lied '{{ song.title }}' wirklich löschen? Alle Anhänge und Projektzuweisungen werden entfernt.">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        {% else %}
            <div class="col-12">
                <div class="alert alert-info">
                    {% if search or selected_category_id %}
                        Keine Lieder gefunden. <a href="/song-library">Filter zurücksetzen</a>
                    {% else %}
                        Das Repertoire ist noch leer. Lege das erste Lied an.
                    {% endif %}
                </div>
            </div>
        {% endfor %}
    </div>

    {# ===== Add song modal ===== #}
    <div class="modal fade"
         id="addSongModal"
         tabindex="-1"
         aria-labelledby="addSongModalLabel"
         aria-hidden="true">
        <div class="modal-dialog">
            <form action="/song-library/songs" method="post" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addSongModalLabel">Neues Lied anlegen</h5>
                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="add_title" class="form-label">Titel *</label>
                        <input type="text" id="add_title" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="add_composer" class="form-label">Komponist</label>
                        <input type="text" id="add_composer" name="composer" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label for="add_arranger" class="form-label">Arrangeur</label>
                        <input type="text" id="add_arranger" name="arranger" class="form-control">
                    </div>
                    <div class="mb-0">
                        <label for="add_publisher" class="form-label">Verlag</label>
                        <input type="text" id="add_publisher" name="publisher" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    {# ===== Manage categories modal ===== #}
    <div class="modal fade"
         id="manageCategoriesModal"
         tabindex="-1"
         aria-labelledby="manageCategoriesModalLabel"
         aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="manageCategoriesModalLabel">Kategorien verwalten</h5>
                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form action="/song-library/categories" method="post" class="row g-2 mb-4">
                        <div class="col-md-8">
                            <input type="text"
                                   name="name"
                                   class="form-control"
                                   placeholder="Neue Kategorie..."
                                   required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-plus"></i> Anlegen
                            </button>
                        </div>
                    </form>

                    {% if categories|length > 0 %}
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th class="text-end">Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for cat in categories %}
                                    <tr>
                                        <td>
                                            <form action="/song-library/categories/{{ cat.id }}/update"
                                                  method="post"
                                                  class="d-flex gap-2 align-items-center">
                                                <input type="text"
                                                       name="name"
                                                       class="form-control form-control-sm"
                                                       value="{{ cat.name }}"
                                                       required>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <form action="/song-library/categories/{{ cat.id }}/delete"
                                                  method="post"
                                                  class="d-inline"
                                                  data-confirm="Kategorie '{{ cat.name }}' wirklich löschen?">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    {% else %}
                        <p class="text-muted mb-0">Noch keine Kategorien vorhanden.</p>
                    {% endif %}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>
{% endblock content %}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testManageTemplateUsesGlobalSongList
```

Expected: PASS

- [ ] **Step 5: Run Twig lint**

```bash
ddev composer twigcs -- templates/songs/manage.twig
```

Expected: no errors or only style warnings.

- [ ] **Step 6: Commit**

```bash
git add templates/songs/manage.twig tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(ui): redesign song library manage.twig as global repertoire list"
```

---

## Task 9: Template — `detail.twig` (song detail page)

**Files:**
- Create: `templates/songs/detail.twig`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testDetailTemplateExists(): void
{
    $this->assertTrue(
        file_exists(dirname(__DIR__) . '/../templates/songs/detail.twig')
    );
}

public function testDetailTemplateContainsKeyStructure(): void
{
    $content = file_get_contents(dirname(__DIR__) . '/../templates/songs/detail.twig');
    $this->assertIsString($content);
    $this->assertStringContainsString('song-library/songs/{{ song.id }}/update', $content);
    $this->assertStringContainsString('song-library/songs/{{ song.id }}/categories', $content);
    $this->assertStringContainsString('song-library/assignments', $content);
    $this->assertStringContainsString('for assignment in song.projectAssignments', $content);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testDetailTemplateExists|testDetailTemplateContainsKeyStructure"
```

Expected: both FAIL

- [ ] **Step 3: Create `templates/songs/detail.twig`**

```twig
{% extends 'layout.twig' %}

{% block title %}
    {{ song.title }} - {{ app_settings.app_name|default('Chor-Manager') }}
{% endblock title %}

{% block page_header %}
    <section class="page-header">
        <div>
            <p class="text-uppercase text-muted small mb-1">
                <a href="/song-library" class="text-muted text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Repertoire
                </a>
            </p>
            <h1 class="h2 mb-1">{{ song.title }}</h1>
            {% if song.composer %}
                <p class="text-muted mb-0">{{ song.composer }}
                    {% if song.arranger %}&middot; Arr. {{ song.arranger }}{% endif %}
                </p>
            {% endif %}
        </div>
    </section>
{% endblock page_header %}

{% block content %}
    {% if success %}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ success }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    {% endif %}
    {% if error %}
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ error }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    {% endif %}

    <div class="row g-4">
        {# ===== Left column: song data ===== #}
        <div class="col-lg-7">

            {# Stammdaten #}
            <div class="surface-card form-surface p-4 mb-4">
                <h2 class="h5 mb-3">Stammdaten</h2>
                <form action="/song-library/songs/{{ song.id }}/update" method="post">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="title" class="form-label">Titel *</label>
                            <input type="text"
                                   id="title"
                                   name="title"
                                   class="form-control"
                                   value="{{ song.title }}"
                                   required>
                        </div>
                        <div class="col-md-6">
                            <label for="composer" class="form-label">Komponist</label>
                            <input type="text"
                                   id="composer"
                                   name="composer"
                                   class="form-control"
                                   value="{{ song.composer|default('') }}">
                        </div>
                        <div class="col-md-6">
                            <label for="arranger" class="form-label">Arrangeur</label>
                            <input type="text"
                                   id="arranger"
                                   name="arranger"
                                   class="form-control"
                                   value="{{ song.arranger|default('') }}">
                        </div>
                        <div class="col-12">
                            <label for="publisher" class="form-label">Verlag</label>
                            <input type="text"
                                   id="publisher"
                                   name="publisher"
                                   class="form-control"
                                   value="{{ song.publisher|default('') }}">
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i> Speichern
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            {# Kategorien #}
            <div class="surface-card form-surface p-4 mb-4">
                <h2 class="h5 mb-3">Kategorien</h2>
                <form action="/song-library/songs/{{ song.id }}/categories" method="post">
                    {% if all_categories|length > 0 %}
                        <div class="d-flex flex-wrap gap-3 mb-3">
                            {% for cat in all_categories %}
                                {% set is_assigned = cat.id in (song.categories|map(c => c.id)|list) %}
                                <div class="form-check">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="category_ids[]"
                                           value="{{ cat.id }}"
                                           id="cat-{{ cat.id }}"
                                           {% if is_assigned %}checked{% endif %}>
                                    <label class="form-check-label" for="cat-{{ cat.id }}">
                                        {{ cat.name }}
                                    </label>
                                </div>
                            {% endfor %}
                        </div>
                    {% else %}
                        <p class="text-muted mb-3">
                            Noch keine Kategorien angelegt.
                            <a href="/song-library" data-bs-toggle="modal" data-bs-target="#manageCategoriesModal">
                                Jetzt anlegen
                            </a>
                        </p>
                    {% endif %}
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-check"></i> Kategorien speichern
                    </button>
                </form>
            </div>

            {# Dateien #}
            <div class="surface-card form-surface p-4 mb-4">
                <h2 class="h5 mb-3">Globale Dateien</h2>
                <form action="/song-library/songs/{{ song.id }}/attachments"
                      method="post"
                      enctype="multipart/form-data"
                      id="song-library-form-{{ song.id }}"
                      class="song-library-form row g-2 align-items-center mb-3"
                      data-upload-compress="true">
                    <div class="col-md-8">
                        <label class="form-label">Dateien anhängen (mehrfach möglich)</label>
                        <input type="file"
                               name="attachments[]"
                               class="form-control"
                               multiple
                               required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-success mt-4">
                            <i class="bi bi-upload"></i> Hochladen
                        </button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Dateiname</th>
                                <th>Typ</th>
                                <th>Größe</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            {% for attachment in song.attachments %}
                                <tr>
                                    <td>{{ attachment.original_name }}</td>
                                    <td><small>{{ attachment.mime_type }}</small></td>
                                    <td>{{ ((attachment.file_size / 1024)|round(1)) }} KB</td>
                                    <td class="text-end">
                                        <form action="/song-library/songs/{{ song.id }}/attachments/{{ attachment.id }}/delete"
                                              method="post"
                                              class="d-inline"
                                              data-confirm="Anhang wirklich löschen?">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            {% else %}
                                <tr>
                                    <td colspan="4" class="text-muted">Noch keine Dateien vorhanden.</td>
                                </tr>
                            {% endfor %}
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        {# ===== Right column: project assignments ===== #}
        <div class="col-lg-5">
            <div class="surface-card form-surface p-4">
                <h2 class="h5 mb-3">Projekteinsatz</h2>

                {% for assignment in song.projectAssignments %}
                    <div class="card mb-2 border">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold">{{ assignment.project.name }}</div>
                                    {% if assignment.note %}
                                        <small class="text-muted d-block mt-1">{{ assignment.note }}</small>
                                    {% endif %}
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editNoteModal-{{ assignment.id }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form action="/song-library/assignments/{{ assignment.id }}/delete"
                                          method="post"
                                          class="d-inline"
                                          data-confirm="Zuweisung zu '{{ assignment.project.name }}' entfernen?">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    {# Note edit modal per assignment #}
                    <div class="modal fade"
                         id="editNoteModal-{{ assignment.id }}"
                         tabindex="-1"
                         aria-hidden="true">
                        <div class="modal-dialog">
                            <form action="/song-library/assignments/{{ assignment.id }}/update"
                                  method="post"
                                  class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Notiz: {{ assignment.project.name }}</h5>
                                    <button type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                            aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <textarea name="note"
                                              class="form-control"
                                              rows="3"
                                              placeholder="Projektspezifische Notiz...">{{ assignment.note|default('') }}</textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button"
                                            class="btn btn-secondary"
                                            data-bs-dismiss="modal">Abbrechen</button>
                                    <button type="submit" class="btn btn-primary">Speichern</button>
                                </div>
                            </form>
                        </div>
                    </div>
                {% else %}
                    <p class="text-muted">Noch keinem Projekt zugewiesen.</p>
                {% endfor %}

                {# Assign to a project #}
                {% set unassigned_projects = all_projects|filter(p => p.id not in assigned_project_ids) %}
                {% if unassigned_projects|length > 0 %}
                    <form action="/song-library/assignments" method="post" class="mt-3">
                        <input type="hidden" name="song_id" value="{{ song.id }}">
                        <label class="form-label small">Projekt zuweisen</label>
                        <div class="input-group">
                            <select name="project_id" class="form-select" required>
                                <option value="">Projekt auswählen...</option>
                                {% for project in unassigned_projects %}
                                    <option value="{{ project.id }}">{{ project.name }}</option>
                                {% endfor %}
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </form>
                {% else %}
                    <p class="text-muted small mt-3">Das Lied ist bereits allen vorhandenen Projekten zugewiesen.</p>
                {% endif %}
            </div>
        </div>
    </div>
{% endblock content %}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter "testDetailTemplateExists|testDetailTemplateContainsKeyStructure"
```

Expected: both PASS

- [ ] **Step 5: Run Twig lint**

```bash
ddev composer twigcs -- templates/songs/detail.twig
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add templates/songs/detail.twig tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(ui): add song detail.twig with categories, attachments and project assignment panels"
```

---

## Task 10: Template — `downloads.twig` (use `assignedSongs`)

**Files:**
- Modify: `templates/songs/downloads.twig`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testDownloadsTemplateUsesAssignedSongs(): void
{
    $content = file_get_contents(dirname(__DIR__) . '/../templates/songs/downloads.twig');
    $this->assertIsString($content);
    $this->assertStringContainsString('for song in project.assignedSongs', $content);
    $this->assertStringNotContainsString('for song in project.songs', $content);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testDownloadsTemplateUsesAssignedSongs
```

Expected: FAIL

- [ ] **Step 3: Update `templates/songs/downloads.twig`**

Replace every occurrence of `project.songs` in the template with `project.assignedSongs`.

Lines to change (there are two — the badge count and the for loop):

Change:
```twig
                            <span class="badge bg-secondary ms-3">{{ project.songs|length }} Lieder</span>
```
To:
```twig
                            <span class="badge bg-secondary ms-3">{{ project.assignedSongs|length }} Lieder</span>
```

Change:
```twig
                            {% if project.songs|length == 0 %}<p class="text-muted mb-0">Keine Lieder in diesem Projekt vorhanden.</p>{% endif %}

                            {% for song in project.songs %}
```
To:
```twig
                            {% if project.assignedSongs|length == 0 %}<p class="text-muted mb-0">Keine Lieder in diesem Projekt vorhanden.</p>{% endif %}

                            {% for song in project.assignedSongs %}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testDownloadsTemplateUsesAssignedSongs
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add templates/songs/downloads.twig tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(download): update downloads.twig to iterate project.assignedSongs"
```

---

## Task 11: `DevSeedService` — seed categories, global songs, and project assignments

**Files:**
- Modify: `src/Services/DevSeedService.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testDevSeedIncludesRepertoireSeedMethods(): void
{
    $content = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');
    $this->assertIsString($content);
    $this->assertStringContainsString('seedCategories', $content);
    $this->assertStringContainsString('seedProjectSongAssignments', $content);
    $this->assertStringContainsString("'repertoire_categories'", $content);
    $this->assertStringContainsString("'song_category_assignments'", $content);
    $this->assertStringContainsString("'project_song_assignments'", $content);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testDevSeedIncludesRepertoireSeedMethods
```

Expected: FAIL

- [ ] **Step 3: Add `use` import for `Category` and `ProjectSongAssignment` at the top of `DevSeedService.php`**

Locate the block of `use` statements (around line 10–30) and add:

```php
use App\Models\Category;
use App\Models\ProjectSongAssignment;
```

- [ ] **Step 4: Add new tables to `resetSeedData()` in `DevSeedService.php`**

Inside the `$tables = [...]` array in `resetSeedData()`, add before the `'songs'` entry:

```php
            'project_song_assignments',
            'song_category_assignments',
            'repertoire_categories',
```

- [ ] **Step 5: Add calls in the main `run()` method in `DevSeedService.php`**

In the transaction block, replace:
```php
            $songs = $this->seedSongs($projects, $users['active']);
            $this->seedSongAttachments($songs, 48);
```
With:
```php
            $categories = $this->seedCategories();
            $songs = $this->seedSongs($users['active']);
            $this->seedSongCategoryAssignments($songs, $categories);
            $this->seedProjectSongAssignments($songs, $projects);
            $this->seedSongAttachments($songs, 48);
```

- [ ] **Step 6: Refactor `seedSongs()` to create global songs (no project_id)**

Replace the existing `private function seedSongs(array $projects, array $activeUsers): array` with:

```php
    private function seedSongs(array $activeUsers): array
    {
        $definitions = [
            ['title' => 'Ave Verum', 'composer' => 'W. A. Mozart', 'arranger' => null, 'publisher' => 'Chor Verlag'],
            ['title' => 'Dona Nobis Pacem', 'composer' => 'Traditional', 'arranger' => 'M. Leitner', 'publisher' => null],
            ['title' => 'Cantate Domino', 'composer' => 'K. Jenkins', 'arranger' => null, 'publisher' => 'Musica Nova'],
            ['title' => 'Hallelujah', 'composer' => 'L. Cohen', 'arranger' => 'A. Huber', 'publisher' => 'Harmony Print'],
            ['title' => 'Gaudete', 'composer' => 'Traditional', 'arranger' => null, 'publisher' => null],
            ['title' => 'Abendlied', 'composer' => 'J. Rheinberger', 'arranger' => null, 'publisher' => 'Edition Klang'],
            ['title' => 'O Magnum Mysterium', 'composer' => 'T. L. de Victoria', 'arranger' => null, 'publisher' => 'Chor Verlag'],
            ['title' => 'Shenandoah', 'composer' => 'Traditional', 'arranger' => 'B. Eder', 'publisher' => null],
        ];

        $songs = [];
        $activeUserCount = count($activeUsers);

        foreach ($definitions as $index => $definition) {
            $createdBy = $activeUserCount > 0 ? $activeUsers[$index % $activeUserCount] : null;

            $song = Song::firstOrCreate(
                ['title' => $definition['title']],
                [
                    'composer' => $definition['composer'],
                    'arranger' => $definition['arranger'],
                    'publisher' => $definition['publisher'],
                    'created_by_user_id' => $createdBy?->id,
                ]
            );

            if ($song->wasRecentlyCreated) {
                $this->report['counts']['songs']++;
            }

            $songs[] = $song;
        }

        return $songs;
    }
```

- [ ] **Step 7: Add `seedCategories()` method to `DevSeedService.php`**

Add after `seedSongs()`:

```php
    private function seedCategories(): array
    {
        $definitions = [
            ['name' => 'Sakral', 'sort_order' => 1],
            ['name' => 'Klassik', 'sort_order' => 2],
            ['name' => 'Volksmusik', 'sort_order' => 3],
            ['name' => 'Pop / Rock', 'sort_order' => 4],
            ['name' => 'Weihnachten', 'sort_order' => 5],
            ['name' => 'Weltlich', 'sort_order' => 6],
        ];

        $categories = [];
        foreach ($definitions as $definition) {
            $category = Category::firstOrCreate(
                ['name' => $definition['name']],
                ['sort_order' => $definition['sort_order']]
            );
            if ($category->wasRecentlyCreated) {
                $this->report['counts']['repertoire_categories'] = ($this->report['counts']['repertoire_categories'] ?? 0) + 1;
            }
            $categories[$category->name] = $category;
        }

        return $categories;
    }
```

- [ ] **Step 8: Add `seedSongCategoryAssignments()` method to `DevSeedService.php`**

```php
    private function seedSongCategoryAssignments(array $songs, array $categories): void
    {
        // Assignment map: song title => category names
        $map = [
            'Ave Verum'          => ['Sakral', 'Klassik'],
            'Dona Nobis Pacem'   => ['Sakral'],
            'Cantate Domino'     => ['Sakral', 'Klassik'],
            'Hallelujah'         => ['Pop / Rock', 'Weltlich'],
            'Gaudete'            => ['Sakral', 'Weihnachten'],
            'Abendlied'          => ['Klassik', 'Weltlich'],
            'O Magnum Mysterium' => ['Sakral', 'Weihnachten', 'Klassik'],
            'Shenandoah'         => ['Volksmusik', 'Weltlich'],
        ];

        foreach ($songs as $song) {
            $catNames = $map[$song->title] ?? [];
            $catIds = array_values(array_filter(array_map(
                fn(string $n) => isset($categories[$n]) ? (int) $categories[$n]->id : null,
                $catNames
            )));
            if (count($catIds) > 0) {
                $song->categories()->syncWithoutDetaching($catIds);
                $this->report['counts']['song_category_assignments'] =
                    ($this->report['counts']['song_category_assignments'] ?? 0) + count($catIds);
            }
        }
    }
```

- [ ] **Step 9: Add `seedProjectSongAssignments()` method to `DevSeedService.php`**

```php
    private function seedProjectSongAssignments(array $songs, array $projects): void
    {
        if (count($songs) === 0 || count($projects) === 0) {
            return;
        }

        $songCount = count($songs);

        foreach ($projects as $projectIndex => $project) {
            // Each project gets approximately 60-75 % of all songs
            $assignCount = max(3, (int) round($songCount * (0.6 + ($projectIndex % 3) * 0.05)));
            $assignCount = min($assignCount, $songCount);
            $subset = array_slice($songs, $projectIndex % $songCount, $assignCount);

            foreach ($subset as $song) {
                if (!ProjectSongAssignment::where('project_id', $project->id)->where('song_id', $song->id)->exists()) {
                    ProjectSongAssignment::create([
                        'project_id' => $project->id,
                        'song_id' => $song->id,
                        'note' => null,
                    ]);
                    $this->report['counts']['project_song_assignments'] =
                        ($this->report['counts']['project_song_assignments'] ?? 0) + 1;
                }
            }
        }
    }
```

- [ ] **Step 10: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testDevSeedIncludesRepertoireSeedMethods
```

Expected: PASS

- [ ] **Step 11: Run full feature test suite**

```bash
ddev exec vendor/bin/phpunit tests/Feature/
```

Expected: all tests PASS.

- [ ] **Step 12: Commit**

```bash
git add src/Services/DevSeedService.php tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(seed): seed global repertoire songs, categories, and project assignments"
```

---

## Task 12: Update `SongLibraryFeatureTest` — reflect new controller shape

**Files:**
- Modify: `tests/Feature/SongLibraryFeatureTest.php`

- [ ] **Step 1: Run existing tests to identify failures**

```bash
ddev exec vendor/bin/phpunit tests/Feature/SongLibraryFeatureTest.php
```

Note any failures caused by the new controller shape.

- [ ] **Step 2: Update `testSongLibraryStructureExists()`**

The existing assertion checks for `new RoleMiddleware(false, 0, false, false, false, false, false, true)` which is still correct. The assertion for `'/songs/{id:[0-9]+}/attachments'` is still correct. Update only the assertion that previously checked for project_id logic:

Replace the entire `testSongLibraryStructureExists` method body with:

```php
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
        $this->assertStringContainsString("'/song-library/{id:[0-9]+}'", $routesContent);
        $this->assertStringContainsString("'/songs/{id:[0-9]+}/attachments'", $routesContent);
        $this->assertStringContainsString(
            'new RoleMiddleware(false, 0, false, false, false, false, false, true)',
            $routesContent
        );
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/manage.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/detail.twig'));
    }
```

- [ ] **Step 3: Update `testSongDeleteAlsoRemovesAttachments()`**

This test already passes because the delete method still removes attachments. No change needed — verify only:

```bash
ddev exec vendor/bin/phpunit tests/Feature/SongLibraryFeatureTest.php --filter testSongDeleteAlsoRemovesAttachments
```

Expected: PASS

- [ ] **Step 4: Update `testDevSeedServiceSeedsSongsAndSongAttachments()`**

The seed no longer passes `$projects` to `seedSongs()`. Update the assertion to match the new call:

```php
    public function testDevSeedServiceSeedsSongsAndSongAttachments(): void
    {
        $seedContent = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seedContent);
        $this->assertStringContainsString("'songs'", $seedContent);
        $this->assertStringContainsString('$songs = $this->seedSongs($users[\'active\']);', $seedContent);
        $this->assertStringContainsString('$this->seedSongAttachments($songs, 48);', $seedContent);
        $this->assertStringContainsString('$categories = $this->seedCategories();', $seedContent);
        $this->assertStringContainsString('$this->seedProjectSongAssignments($songs, $projects);', $seedContent);
    }
```

- [ ] **Step 5: Run full SongLibraryFeatureTest**

```bash
ddev exec vendor/bin/phpunit tests/Feature/SongLibraryFeatureTest.php
```

Expected: all PASS.

- [ ] **Step 6: Run all feature tests**

```bash
ddev exec vendor/bin/phpunit tests/Feature/
```

Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/SongLibraryFeatureTest.php
git commit -m "test: update SongLibraryFeatureTest to reflect repertoire controller shape"
```

---

## Task 13: PHP style check and Twig style check

- [ ] **Step 1: Run PHPCS on changed PHP files**

```bash
ddev composer phpcs -- src/Controllers/SongLibraryController.php src/Controllers/CategoryController.php src/Controllers/ProjectSongAssignmentController.php src/Models/Category.php src/Models/ProjectSongAssignment.php src/Models/Song.php src/Models/Project.php src/Controllers/DownloadController.php src/Services/DevSeedService.php
```

- [ ] **Step 2: Fix any violations**

```bash
ddev composer phpcbf -- src/Controllers/SongLibraryController.php src/Controllers/CategoryController.php src/Controllers/ProjectSongAssignmentController.php src/Models/Category.php src/Models/ProjectSongAssignment.php src/Models/Song.php src/Models/Project.php src/Controllers/DownloadController.php src/Services/DevSeedService.php
```

- [ ] **Step 3: Run TwigCS on all changed templates**

```bash
ddev composer twigcs -- templates/songs/manage.twig templates/songs/detail.twig templates/songs/downloads.twig
```

- [ ] **Step 4: Fix any Twig violations**

```bash
ddev composer twigcbf -- templates/songs/manage.twig templates/songs/detail.twig templates/songs/downloads.twig
```

- [ ] **Step 5: Commit style fixes if any were applied**

```bash
git add src/ templates/songs/
git commit -m "style: apply PHPCS and TwigCS fixes to repertoire files"
```

---

## Task 14: Migration — drop `songs.project_id`

**Files:**
- Create: `db/migrations/20260421120000_drop_songs_project_id.php`

> **Prerequisite:** All previous tasks must be deployed and verified. No application code may reference `songs.project_id` at this point.

- [ ] **Step 1: Verify no application PHP file reads songs.project_id**

```bash
ddev exec grep -rn "songs\.project_id\|->project_id\b" src/Controllers/ src/Queries/ src/Persistence/ src/Services/
```

Expected: zero matches in controllers, queries, persistence, and non-seed services. The `DevSeedService` still references it in the legacy seed path for older data — verify it only appears in the history or in migration files, not in live request paths.

- [ ] **Step 2: Write the failing test**

Append to `tests/Feature/RepertoireFeatureTest.php`:

```php
public function testDropProjectIdMigrationExists(): void
{
    $migrationDir = dirname(__DIR__) . '/../db/migrations/';
    $files = glob($migrationDir . '*_drop_songs_project_id.php');
    $this->assertNotEmpty($files, 'Cleanup migration to drop songs.project_id not found.');
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testDropProjectIdMigrationExists
```

Expected: FAIL

- [ ] **Step 4: Create `db/migrations/20260421120000_drop_songs_project_id.php`**

```php
<?php

use Phinx\Migration\AbstractMigration;

final class DropSongsProjectId extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE songs DROP FOREIGN KEY songs_ibfk_1');
        $this->execute('ALTER TABLE songs DROP INDEX project_id');
        $this->execute('ALTER TABLE songs DROP COLUMN project_id');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE songs ADD COLUMN project_id int(11) DEFAULT NULL');
        $this->execute('ALTER TABLE songs ADD INDEX project_id (project_id)');
        $this->execute('ALTER TABLE songs ADD CONSTRAINT songs_ibfk_1
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
    }
}
```

- [ ] **Step 5: Remove `project_id` from `Song.$fillable`**

In `src/Models/Song.php`, remove `'project_id'` from `$fillable`:

```php
    protected $fillable = [
        'title',
        'composer',
        'arranger',
        'publisher',
        'created_by_user_id',
    ];
```

Also remove the `project()` relationship method from `Song` (it references the now-dropped column):

Remove:
```php
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }
```

And remove the now-unused `songs()` relationship on `Project` (the one using `project_id`):

```php
    // Remove this from Project.php:
    public function songs()
    {
        return $this->hasMany(Song::class, 'project_id', 'id');
    }
```

- [ ] **Step 6: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit tests/Feature/RepertoireFeatureTest.php --filter testDropProjectIdMigrationExists
```

Expected: PASS

- [ ] **Step 7: Apply migration**

```bash
ddev exec vendor/bin/phinx migrate
```

Expected: `== 20260421120000 DropSongsProjectId: migrated`

- [ ] **Step 8: Run all feature tests**

```bash
ddev exec vendor/bin/phpunit tests/Feature/
```

Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add db/migrations/20260421120000_drop_songs_project_id.php src/Models/Song.php src/Models/Project.php tests/Feature/RepertoireFeatureTest.php
git commit -m "feat(db): drop songs.project_id; remove legacy project() relationship from Song"
```

---

## Post-implementation smoke test

After all tasks are complete, run a full dev seed to verify end-to-end data integrity:

```bash
ddev exec php bin/dev_seed.php reset-and-seed
```

Expected: seed completes without errors; output shows counts for `repertoire_categories`, `song_category_assignments`, `project_song_assignments`.

Then verify the test suite is fully green:

```bash
ddev exec vendor/bin/phpunit tests/
```

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Global repertoire for songs (Tasks 1–6)
- ✅ Multiple categories per song (Tasks 3, 5, 6, 8, 9, 11)
- ✅ Song assigned to multiple projects (Tasks 1, 5)
- ✅ Project note on assignment (Task 5, 9)
- ✅ Attachments global only (Tasks 6, 9 — no project-specific attachment path)
- ✅ Downloads still project-grouped via assignments (Tasks 7, 10)
- ✅ Authorization through project_song_assignments (Task 7)
- ✅ Migration from project_id to assignments (Tasks 1, 2, 14)
- ✅ Redesigned management UI (Tasks 8, 9)
- ✅ Category CRUD with duplicate name guard (Task 4)
- ✅ Duplicate assignment guard (Task 5)
- ✅ Dev seed covers all new tables (Task 11)
- ✅ Feature tests for all behaviors (Tasks 1–12)

**No placeholders found.**

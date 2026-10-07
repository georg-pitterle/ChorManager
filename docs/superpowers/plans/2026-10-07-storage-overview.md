# Speicherplatz-Übersicht Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine Seite `/storage` und eine Dashboard-Kachel zeigen den belegten Speicherplatz der Installation, aufgeteilt nach Dateiablage, Datenbank (mit Anhängen nach Bereich), Backups und Sonstigem in `var/`. Jede Kategorie trägt einen festen Schlüssel als Andockpunkt für spätere Aufräum-Funktionen.

**Architecture:** Je Bereich ein `StorageUsageProvider`, der einen Baum aus `StorageUsageNode` liefert. `StorageUsageService::collect()` ruft alle Provider auf, fängt Fehler je Bereich ab und schreibt eine Zusammenfassung in eine Cache-Datei; `summary()` liest diese für die Dashboard-Kachel (höchstens 1 Stunde alt). Zugriff über das neue Rollenrecht `can_manage_storage`.

**Tech Stack:** PHP 8 / Slim 4 / PHP-DI, Eloquent (Illuminate Capsule) auf MySQL, Twig, Phinx, PHPUnit (paratest), Bootstrap 5.

**Spec:** `docs/superpowers/specs/2026-10-07-storage-overview-design.md`

## Global Constraints

- Alle Befehle über DDEV: `ddev php vendor/bin/phpunit --filter "<Muster>"`, volle Suite `ddev composer test:parallel`, Stil `ddev composer phpcs` / `ddev composer twigcs`.
- Bezeichner englisch, UI-Texte, Kommentare, Testbeschreibungen und Commit-Nachrichten deutsch mit echten Umlauten (`ä ö ü ß`).
- Neue und geänderte Textdateien mit LF-Zeilenenden. Nach jedem Schreiben unter Windows normalisieren:
  `$f = "<absoluter Pfad>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
- PHP: PSR-12, 4 Leerzeichen, Zeilenlänge höchstens 130. Twig: doppelte Anführungszeichen, keine mehrzeiligen Bool-Ausdrücke, keine Inline-Skripte, `style` nur für CSS-Variablen mit dynamischem Wert.
- Logs strukturiert über `Psr\Log\LoggerInterface` mit `event`-Schlüssel; Ausnahmen unter `exception` im Kontext.
- Schemaänderung nur über Phinx-Migration.
- Recht: `can_manage_storage`, Label „Speicherplatz-Verwaltung“. Migration vergibt es an alle Rollen mit dem höchsten vorhandenen `hierarchy_level`.
- Cache der Zusammenfassung: `var/cache/storage-usage-summary.json`, gültig 3600 Sekunden.
- Kein `git push`. Gearbeitet wird auf dem Branch `feat/storage-overview`; am Ende führt `/git-commit` Squash und Merge nach `main` durch.

## Review Focus

1. **Speicherpfad von mehreren Versionen geteilt** (zurückgeholte alte Version): Er muss genau einmal und in der höchsten Kategorie zählen, sonst weicht die Summe vom Kontingent ab. → Test in Task 4.
2. **Veraltete Tabellengrößen aus `information_schema`** (MySQL cacht die Werte bis zu 24 h): Die Nutzdaten der Anhänge können größer sein als die gemeldete Tabellengröße. Der Wert für „Verwaltung und Index“ darf dann nicht negativ werden, und die Wurzel muss trotzdem die Summe ihrer Kinder sein. → Test in Task 5.
3. **Cache-Datei mit Zeitstempel in der Zukunft** (Uhr verstellt, Container gewechselt): Sie darf nicht als „frisch“ gelten, sonst bleibt die Kachel beliebig lange stehen. → Test in Task 8.
4. **Dateiablage oder Backups liegen unter `var/` in einem tieferen Unterordner** (z. B. `var/data/files`): Sie dürfen in „Sonstiges“ nicht doppelt gezählt werden. → Test in Task 7.
5. **Ein Bereich wirft** (z. B. ein nicht lesbares Verzeichnis): Die Seite rendert trotzdem, die anderen Bereiche bleiben korrekt, und die Gesamtsumme enthält den Bereich als 0. → Test in Task 8.

---

## Dateiübersicht

| Datei | Verantwortung |
|---|---|
| `src/Util/ByteFormatter.php` (neu) | Bytes → „12,4 MB“ |
| `db/migrations/20261007090000_add_can_manage_storage_to_roles.php` (neu) | Spalte + Vergabe ans höchste Level |
| `src/Services/Storage/StorageUsageNode.php` (neu) | Wertobjekt eines Knotens |
| `src/Services/Storage/StorageUsageProvider.php` (neu) | Interface je Bereich |
| `src/Services/Storage/StorageUsageReport.php` (neu) | Ergebnis von `collect()` |
| `src/Services/Storage/StorageUsageSummary.php` (neu) | Kurzfassung für die Kachel, (de)serialisierbar |
| `src/Services/Storage/DirectorySize.php` (neu) | Verzeichnisgröße ohne Symlinks, mit Ausschlüssen |
| `src/Services/Storage/FileStorageUsageProvider.php` (neu) | Dateiablage |
| `src/Services/Storage/DatabaseUsageProvider.php` (neu) | Datenbank und Anhänge |
| `src/Services/Storage/BackupUsageProvider.php` (neu) | Backup-Verzeichnis |
| `src/Services/Storage/VarDirectoryUsageProvider.php` (neu) | Übriges unter `var/` |
| `src/Services/Storage/StorageUsageService.php` (neu) | Sammeln, Fehler abfangen, Cache |
| `src/Controllers/StorageController.php` (neu) | Seite `/storage` |
| `templates/storage/index.twig` (neu) | Oberfläche |
| `src/Models/Role.php`, `src/Controllers/RoleController.php`, `src/Controllers/AuthController.php`, `src/Services/DevSeedService.php`, `templates/roles/index.twig`, `public/js/roles.js`, `src/Middleware/RoleMiddleware.php` | neues Recht durchreichen |
| `src/Settings.php`, `src/Dependencies.php`, `src/Routes.php`, `src/Navigation/NavigationBuilder.php` | Verdrahtung |
| `src/Controllers/DashboardController.php`, `templates/dashboard/index.twig` | Kachel |
| `src/Controllers/SponsoringAttachmentController.php` | nutzt `ByteFormatter` |
| `public/css/style.css` | Balken und Kennzahl-Karten |

---

### Task 0: Branch anlegen

- [ ] **Step 1:** `git switch -c feat/storage-overview` (vom aktuellen `main`).
- [ ] **Step 2:** Spec und Plan committen:

```bash
git add docs/superpowers/specs/2026-10-07-storage-overview-design.md docs/superpowers/plans/2026-10-07-storage-overview.md
git commit -m "docs(storage): Spec und Plan für die Speicherplatz-Übersicht"
```

---

### Task 1: `ByteFormatter` und Twig-Filter `format_bytes`

**Files:**
- Create: `src/Util/ByteFormatter.php`
- Modify: `src/Controllers/SponsoringAttachmentController.php:162,168-179`
- Modify: `src/Dependencies.php` (Twig-Filter neben `person_name`, ca. Zeile 895)
- Modify: `tests/Feature/TwigViewStubs.php:36-39` (Filter auch in der Test-Umgebung)
- Test: `tests/Unit/Util/ByteFormatterTest.php`

**Interfaces:**
- Produces: `App\Util\ByteFormatter::format(int $bytes): string`; Twig-Filter `format_bytes`.

- [ ] **Step 1: Failing test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\ByteFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Formatierung folgt der bisherigen Anzeige der Sponsoring-Anhänge: KB ganzzahlig,
 * ab MB eine Nachkommastelle, deutsches Komma und Tausenderpunkt.
 */
final class ByteFormatterTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function cases(): array
    {
        return [
            'null Byte' => [0, '0 B'],
            'negativ wird null' => [-5, '0 B'],
            'knapp unter KB' => [1023, '1023 B'],
            'genau 1 KB' => [1024, '1 KB'],
            'KB gerundet' => [1536, '2 KB'],
            'KB mit Tausenderpunkt' => [1023 * 1024, '1.023 KB'],
            'genau 1 MB' => [1048576, '1,0 MB'],
            'MB mit Komma' => [(int) (12.4 * 1048576), '12,4 MB'],
            'GB' => [3 * 1073741824 + 536870912, '3,5 GB'],
            'TB' => [2 * 1099511627776, '2,0 TB'],
            'über TB bleibt TB' => [2048 * 1099511627776, '2.048,0 TB'],
        ];
    }

    #[DataProvider('cases')]
    public function testFormat(int $bytes, string $expected): void
    {
        $this->assertSame($expected, ByteFormatter::format($bytes));
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter ByteFormatterTest` → FAIL (Klasse fehlt).

- [ ] **Step 3: Implementieren**

```php
<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Lesbare Größenangabe für Bytes. KB ganzzahlig, ab MB eine Nachkommastelle -
 * so zeigten die Sponsoring-Anhänge Größen schon bisher an.
 */
final class ByteFormatter
{
    private const LARGE_UNITS = ['MB', 'GB', 'TB'];

    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return max(0, $bytes) . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }

        $value = $bytes / 1048576;
        $unit = self::LARGE_UNITS[0];
        foreach (array_slice(self::LARGE_UNITS, 1) as $next) {
            if ($value < 1024) {
                break;
            }
            $value /= 1024;
            $unit = $next;
        }

        return number_format($value, 1, ',', '.') . ' ' . $unit;
    }
}
```

- [ ] **Step 4:** `ddev php vendor/bin/phpunit --filter ByteFormatterTest` → PASS.

- [ ] **Step 5: Sponsoring umstellen.** In `SponsoringAttachmentController` `'size_display' => ByteFormatter::format((int) $attachment->file_size),` und die private Methode `formatSize()` (Zeilen 168-179) löschen; `use App\Util\ByteFormatter;` ergänzen.

- [ ] **Step 6: Twig-Filter registrieren.** In `src/Dependencies.php` direkt nach dem `person_name`-Filter:

```php
            $environment->addFilter(new TwigFilter(
                'format_bytes',
                static fn (mixed $bytes): string => ByteFormatter::format((int) $bytes)
            ));
```

`use App\Util\ByteFormatter;` oben ergänzen. Dasselbe in `tests/Feature/TwigViewStubs.php::createAppTwig()` nach dem `person_name`-Filter (mit `use App\Util\ByteFormatter;`).

- [ ] **Step 7:** `ddev php vendor/bin/phpunit --filter "ByteFormatter|Sponsoring"` → PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Util/ByteFormatter.php tests/Unit/Util/ByteFormatterTest.php src/Controllers/SponsoringAttachmentController.php src/Dependencies.php tests/Feature/TwigViewStubs.php
git commit -m "feat(storage): ByteFormatter und Twig-Filter format_bytes"
```

---

### Task 2: Recht `can_manage_storage`

**Files:**
- Create: `db/migrations/20261007090000_add_can_manage_storage_to_roles.php`
- Modify: `src/Models/Role.php:44` (PERMISSIONS)
- Modify: `src/Controllers/RoleController.php:98,385,496`
- Modify: `src/Controllers/AuthController.php:237`
- Modify: `src/Services/DevSeedService.php:447`
- Modify: `src/Middleware/RoleMiddleware.php` (GATES, Konstruktor-Parameter, `$requestedGates`)
- Modify: `templates/roles/index.twig` (Matrix ~362, Datenattribut ~403, Anlegen-Formular ~782, Bearbeiten-Formular ~1134)
- Modify: `public/js/roles.js:67`
- Modify: `tests/Unit/Services/SessionAuthServicePermissionMappingTest.php:52`
- Test: `tests/Feature/StoragePermissionFeatureTest.php`

**Interfaces:**
- Produces: Rollenspalte und Sitzungsschlüssel `can_manage_storage`; `new RoleMiddleware(requiresStorageManagement: true)`; Migrationsklasse `AddCanManageStorageToRoles` mit Konstante `GRANT_SQL`.

- [ ] **Step 1: Failing tests schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Middleware\RoleMiddleware;
use App\Models\Role;
use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Das Recht "Speicherplatz-Verwaltung" an allen Stellen, an denen ein Rollenrecht
 * ankommen muss: Migration, Rollenformular, Gate.
 */
final class StoragePermissionFeatureTest extends TestCase
{
    private const MIGRATION = '/db/migrations/20261007090000_add_can_manage_storage_to_roles.php';

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = ['user_id' => 7];
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $_SESSION = [];
    }

    /**
     * Die Migration vergibt das Recht an das höchste vorhandene Level, auch wenn es
     * nicht 100 ist - sonst hätte es nach einer umgestellten Rollenordnung niemand.
     */
    public function testMigrationGrantsTheRightToEveryRoleOnTheHighestLevelOnly(): void
    {
        require_once dirname(__DIR__, 2) . self::MIGRATION;

        DB::table('roles')->update(['hierarchy_level' => 1, 'can_manage_storage' => 0]);
        $top = Role::create(['name' => 'Oberste ' . bin2hex(random_bytes(4)), 'hierarchy_level' => 500]);
        $secondTop = Role::create(['name' => 'Auch oben ' . bin2hex(random_bytes(4)), 'hierarchy_level' => 500]);
        $below = Role::create(['name' => 'Darunter ' . bin2hex(random_bytes(4)), 'hierarchy_level' => 499]);

        DB::statement(\AddCanManageStorageToRoles::GRANT_SQL);

        $this->assertSame(1, (int) $top->fresh()->can_manage_storage);
        $this->assertSame(1, (int) $secondTop->fresh()->can_manage_storage);
        $this->assertSame(0, (int) $below->fresh()->can_manage_storage);
        $this->assertSame(2, DB::table('roles')->where('can_manage_storage', 1)->count());
    }

    public function testRoleFormMapsTheRight(): void
    {
        $this->assertSame(1, RoleController::buildPermissionFlags(['can_manage_storage' => '1'])['can_manage_storage']);
        $this->assertSame(0, RoleController::buildPermissionFlags([])['can_manage_storage']);
    }

    public function testRoleTemplateAndScriptCarryTheRight(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/roles/index.twig');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/roles.js');

        $this->assertStringContainsString('role.can_manage_storage', $template);
        $this->assertStringContainsString('name="can_manage_storage"', $template);
        $this->assertStringContainsString('id="edit_can_manage_storage"', $template);
        $this->assertStringContainsString('data-storage=', $template);
        $this->assertStringContainsString('Speicherplatz-Verwaltung', $template);
        $this->assertStringContainsString("'edit_can_manage_storage'", $script);
    }

    public function testSetupAndSeedGiveTheAdminRoleTheRight(): void
    {
        $setup = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/AuthController.php');
        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertStringContainsString("'can_manage_storage' => 1", $setup);
        $this->assertStringContainsString("'can_manage_storage' => 1", $seed);
    }

    public function testGateLetsTheRightThroughAndStopsOthers(): void
    {
        $_SESSION['can_manage_storage'] = true;
        $this->assertSame(200, $this->pass(new RoleMiddleware(requiresStorageManagement: true)));

        $_SESSION['can_manage_storage'] = false;
        $_SESSION['can_manage_backups'] = true;
        $_SESSION['can_manage_roles'] = true;
        $this->assertSame(403, $this->pass(new RoleMiddleware(requiresStorageManagement: true)));
    }

    private function pass(RoleMiddleware $middleware): int
    {
        return $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/storage'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        )->getStatusCode();
    }
}
```

In `SessionAuthServicePermissionMappingTest::EXPECTED_SESSION_KEYS` nach `'can_manage_backups',` die Zeile `'can_manage_storage',` einfügen.

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter "StoragePermissionFeatureTest|SessionAuthServicePermissionMappingTest"` → FAIL.

- [ ] **Step 3: Migration**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Recht für die Speicherplatz-Übersicht und später die Aufräum-Funktionen.
 *
 * Vergeben wird es an die Rollen mit dem höchsten vorhandenen Level, nicht an
 * eine Rolle namens "Admin": Rollen sind pro Installation frei benannt, und das
 * Level 100 der Ersteinrichtung lässt sich nachträglich ändern. So hat das Recht
 * nach der Migration immer mindestens eine Rolle.
 */
final class AddCanManageStorageToRoles extends AbstractMigration
{
    /**
     * Die abgeleitete Tabelle ist nötig: MySQL erlaubt in einem UPDATE keine
     * Unterabfrage auf dieselbe Tabelle (Fehler 1093).
     */
    public const GRANT_SQL = 'UPDATE roles SET can_manage_storage = 1 WHERE hierarchy_level = '
        . '(SELECT max_level FROM (SELECT MAX(hierarchy_level) AS max_level FROM roles) AS highest)';

    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles
             ADD COLUMN can_manage_storage TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_files;"
        );
        $this->execute(self::GRANT_SQL . ';');
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_storage;");
    }
}
```

- [ ] **Step 4: Recht durchreichen**
  - `Role::PERMISSIONS`: nach `'can_manage_files',` → `'can_manage_storage',`.
  - `RoleController::buildPermissionFlags`: nach der `can_manage_files`-Zeile
    `'can_manage_storage' => isset($data['can_manage_storage']) ? 1 : 0,`
  - `RoleController::store` und `::update`: in beiden `Role::create`/`update`-Arrays nach `can_manage_files`
    `'can_manage_storage' => $permissions['can_manage_storage'],`
  - `AuthController` (Ersteinrichtung) und `DevSeedService::seedRoles` (Admin): nach `'can_manage_files' => 1,` → `'can_manage_storage' => 1,`.
  - `RoleMiddleware::GATES` nach `requiresFilesManagement`:

```php
        'requiresStorageManagement' => [
            'permissions' => ['can_manage_storage'],
            'logged_permission' => 'can_manage_storage',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Speicherplatz-Verwaltung.',
        ],
```

    Konstruktor: nach `bool $requiresFilesManagement = false,` → `bool $requiresStorageManagement = false,`; in `$requestedGates` nach `requiresFilesManagement` → `'requiresStorageManagement' => $requiresStorageManagement,`.

- [ ] **Step 5: Rollen-Oberfläche.** In `templates/roles/index.twig`:
  - Matrix: nach der `<tr>` „Backup-Verwaltung“ (endet ~Zeile 362) eine gleich gebaute Zeile mit Label `Speicherplatz-Verwaltung` und `{% if role.can_manage_storage %}`.
  - Bearbeiten-Knopf: nach `data-files="..."` → `data-storage="{{ role.can_manage_storage ? '1' : '0' }}"` (Achtung: dort stehen bestehend einfache Anführungszeichen in `'1' : '0'` - genauso übernehmen, twigcs prüft den Bestand bereits).
  - Anlegen-Formular nach dem Backup-Schalter (~Zeile 782) und Bearbeiten-Formular nach dem Backup-Schalter (~Zeile 1134):

```twig
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input"
                               type="checkbox"
                               role="switch"
                               id="can_manage_storage"
                               name="can_manage_storage"
                               value="1">
                        <label class="form-check-label fw-bold text-primary"
                               for="can_manage_storage">Speicherplatz-Verwaltung</label>
                        <div class="form-text">Wenn aktiv, darf diese Person die Übersicht über den belegten Speicherplatz sehen.</div>
                    </div>
```

    Im Bearbeiten-Formular `id`/`for` als `edit_can_manage_storage`.
  - `public/js/roles.js` nach Zeile 67:
    `setCheckedIfPresent('edit_can_manage_storage', this.getAttribute('data-storage') === '1');`

- [ ] **Step 6:** `ddev php vendor/bin/phpunit --filter "StoragePermission|SessionAuthService|RoleMiddleware|RoleController|RolePermission|Role"` → PASS. (Die neue Migration spielt `tests/bootstrap.php` beim Lauf selbst ein.)

- [ ] **Step 7: Entwicklungsdatenbank migrieren:** `ddev php vendor/bin/phinx migrate` → Migration `20261007090000` läuft ohne Fehler.

- [ ] **Step 8: Commit**

```bash
git add db/migrations/20261007090000_add_can_manage_storage_to_roles.php src/Models/Role.php src/Controllers/RoleController.php src/Controllers/AuthController.php src/Services/DevSeedService.php src/Middleware/RoleMiddleware.php templates/roles/index.twig public/js/roles.js tests/Feature/StoragePermissionFeatureTest.php tests/Unit/Services/SessionAuthServicePermissionMappingTest.php
git commit -m "feat(storage): Recht Speicherplatz-Verwaltung für das höchste Rollenlevel"
```

---

### Task 3: Grundbausteine - Knoten, Interface, Verzeichnisgröße

**Files:**
- Create: `src/Services/Storage/StorageUsageNode.php`, `src/Services/Storage/StorageUsageProvider.php`, `src/Services/Storage/DirectorySize.php`
- Test: `tests/Unit/Services/Storage/StorageUsageNodeTest.php`, `tests/Unit/Services/Storage/DirectorySizeTest.php`

**Interfaces:**
- Produces:
  - `StorageUsageNode(string $key, string $label, int $bytes, ?int $count = null, list<StorageUsageNode> $children = [], ?string $error = null, ?int $quotaBytes = null, bool $breakdown = false)`, alle `public readonly`.
  - `StorageUsageNode::sum(string $key, string $label, array $children, ?int $count = null): self` - `bytes` = Summe der Kinder ohne `breakdown`.
  - `StorageUsageNode::failed(string $key, string $label): self` - `bytes` 0, `error` = `'nicht ermittelbar'`.
  - `StorageUsageNode::child(string $key): ?self`
  - `interface StorageUsageProvider { key(): string; label(): string; usage(): StorageUsageNode; }`
  - `DirectorySize::measure(string $path, list<string> $excludedRealPaths = []): array{bytes: int, count: int}`

- [ ] **Step 1: Failing tests**

`tests/Unit/Services/Storage/StorageUsageNodeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\StorageUsageNode;
use PHPUnit\Framework\TestCase;

final class StorageUsageNodeTest extends TestCase
{
    /**
     * Eine Aufschlüsselung (Teamordner) zeigt dieselben Bytes noch einmal anders
     * gruppiert - sie darf die Summe nicht verdoppeln.
     */
    public function testSumIgnoresBreakdownChildren(): void
    {
        $node = StorageUsageNode::sum('files', 'Dateiablage', [
            new StorageUsageNode('files.current', 'Aktuell', 300),
            new StorageUsageNode('files.trash', 'Papierkorb', 50),
            new StorageUsageNode('files.team_folders', 'Teamordner', 350, breakdown: true),
        ], 7);

        $this->assertSame(350, $node->bytes);
        $this->assertSame(7, $node->count);
        $this->assertSame(50, $node->child('files.trash')?->bytes);
        $this->assertNull($node->child('files.unknown'));
    }

    public function testFailedNodeHasNoBytesAndAnError(): void
    {
        $node = StorageUsageNode::failed('backups', 'Backups');

        $this->assertSame(0, $node->bytes);
        $this->assertSame('nicht ermittelbar', $node->error);
        $this->assertSame([], $node->children);
    }
}
```

`tests/Unit/Services/Storage/DirectorySizeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\DirectorySize;
use PHPUnit\Framework\TestCase;

final class DirectorySizeTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dirsize-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/a/b', 0777, true);
        file_put_contents($this->dir . '/top.txt', str_repeat('x', 10));
        file_put_contents($this->dir . '/a/one.txt', str_repeat('x', 20));
        file_put_contents($this->dir . '/a/b/two.txt', str_repeat('x', 30));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testMeasuresAllFilesRecursively(): void
    {
        $this->assertSame(['bytes' => 60, 'count' => 3], DirectorySize::measure($this->dir));
    }

    public function testMissingPathIsEmpty(): void
    {
        $this->assertSame(['bytes' => 0, 'count' => 0], DirectorySize::measure($this->dir . '/fehlt'));
    }

    public function testExcludedSubdirectoryIsSkippedAtAnyDepth(): void
    {
        $excluded = (string) realpath($this->dir . '/a/b');

        $this->assertSame(['bytes' => 30, 'count' => 2], DirectorySize::measure($this->dir, [$excluded]));
    }

    public function testExcludedRootIsEmpty(): void
    {
        $this->assertSame(['bytes' => 0, 'count' => 0], DirectorySize::measure($this->dir, [(string) realpath($this->dir)]));
    }

    public function testSymlinksAreNotFollowed(): void
    {
        $target = sys_get_temp_dir() . '/dirsize-target-' . bin2hex(random_bytes(6));
        mkdir($target);
        file_put_contents($target . '/big.bin', str_repeat('x', 1000));
        if (!@symlink($target, $this->dir . '/link')) {
            exec('rm -rf ' . escapeshellarg($target));
            $this->markTestSkipped('Symlinks werden hier nicht unterstützt.');
        }

        try {
            $this->assertSame(['bytes' => 60, 'count' => 3], DirectorySize::measure($this->dir));
        } finally {
            exec('rm -rf ' . escapeshellarg($target));
        }
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter "StorageUsageNodeTest|DirectorySizeTest"` → FAIL.

- [ ] **Step 3: Implementieren**

`src/Services/Storage/StorageUsageNode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ein Knoten der Speicherübersicht. Der Schlüssel ist stabil und der Andockpunkt
 * für spätere Aufräum-Funktionen; das Label ist reine Anzeige.
 *
 * `breakdown` markiert eine Aufschlüsselung derselben Bytes nach einem anderen
 * Merkmal (Teamordner). Sie zählt nicht in die Summe des Elternknotens.
 */
final class StorageUsageNode
{
    public const ERROR_UNAVAILABLE = 'nicht ermittelbar';

    /**
     * @param list<StorageUsageNode> $children
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $bytes,
        public readonly ?int $count = null,
        public readonly array $children = [],
        public readonly ?string $error = null,
        public readonly ?int $quotaBytes = null,
        public readonly bool $breakdown = false
    ) {
    }

    /**
     * @param list<StorageUsageNode> $children
     */
    public static function sum(string $key, string $label, array $children, ?int $count = null): self
    {
        $bytes = 0;
        foreach ($children as $child) {
            if (!$child->breakdown) {
                $bytes += $child->bytes;
            }
        }

        return new self($key, $label, $bytes, $count, $children);
    }

    public static function failed(string $key, string $label): self
    {
        return new self($key, $label, 0, null, [], self::ERROR_UNAVAILABLE);
    }

    public function child(string $key): ?self
    {
        foreach ($this->children as $child) {
            if ($child->key === $key) {
                return $child;
            }
        }

        return null;
    }
}
```

`src/Services/Storage/StorageUsageProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ein Bereich der Speicherübersicht. Ein Provider darf werfen - der
 * StorageUsageService fängt das ab und setzt für den Bereich einen Fehlerknoten
 * mit key() und label() ein.
 */
interface StorageUsageProvider
{
    public function key(): string;

    public function label(): string;

    public function usage(): StorageUsageNode;
}
```

`src/Services/Storage/DirectorySize.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Größe eines Verzeichnisbaums. Symbolische Links werden nicht verfolgt - sie
 * könnten auf Daten außerhalb zeigen oder einen Kreis bilden. Ausgeschlossene
 * Verzeichnisse (reale Pfade) misst ein anderer Bereich; sie fallen in jeder Tiefe
 * heraus, damit nichts doppelt zählt.
 */
final class DirectorySize
{
    /**
     * @param list<string> $excludedRealPaths
     * @return array{bytes: int, count: int}
     */
    public static function measure(string $path, array $excludedRealPaths = []): array
    {
        if (is_link($path)) {
            return ['bytes' => 0, 'count' => 0];
        }
        if (is_file($path)) {
            return ['bytes' => (int) filesize($path), 'count' => 1];
        }
        $real = realpath($path);
        if (!is_dir($path) || $real === false || in_array($real, $excludedRealPaths, true)) {
            return ['bytes' => 0, 'count' => 0];
        }

        $filter = static function (\SplFileInfo $item) use ($excludedRealPaths): bool {
            if ($item->isLink()) {
                return false;
            }
            if ($item->isDir()) {
                return !in_array($item->getRealPath(), $excludedRealPaths, true);
            }

            return true;
        };

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                $filter
            )
        );

        $bytes = 0;
        $count = 0;
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $bytes += (int) $item->getSize();
                $count++;
            }
        }

        return ['bytes' => $bytes, 'count' => $count];
    }
}
```

- [ ] **Step 4:** `ddev php vendor/bin/phpunit --filter "StorageUsageNodeTest|DirectorySizeTest"` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Storage tests/Unit/Services/Storage
git commit -m "feat(storage): Knoten, Provider-Interface und Verzeichnisgröße"
```

---

### Task 4: `FileStorageUsageProvider`

**Files:**
- Create: `src/Services/Storage/FileStorageUsageProvider.php`
- Test: `tests/Feature/FileStorageUsageProviderFeatureTest.php`

**Interfaces:**
- Consumes: `FileAccessService::isInTrash(int)`, `FileQuotaService::usedBytesInSubtree(int)`, `FileQuotaService::totalUsedBytes()`, `StorageUsageNode`.
- Produces: `new FileStorageUsageProvider(FileAccessService $access, FileQuotaService $quota)`; Wurzel `files` mit Kindern `files.current`, `files.versions.old`, `files.trash` und Aufschlüsselung `files.team_folders` (Kinder `files.team_folders.<id>` mit `quotaBytes`).

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\Storage\FileStorageUsageProvider;
use App\Services\Storage\StorageUsageNode;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Zuordnung der Speicherpfade: aktuell vor älterer Version vor Papierkorb, jeder
 * Pfad genau einmal. Die Summe muss dem Kontingent-Zähler entsprechen.
 */
final class FileStorageUsageProviderFeatureTest extends TestCase
{
    use FileFixtures;

    private FileService $files;
    private FileFolderService $folders;
    private FileQuotaService $quota;
    private FileAccessService $access;
    private FileActor $admin;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->access = new FileAccessService();
        $this->quota = new FileQuotaService($this->access, 0);
        $this->folders = new FileFolderService($this->access, $this->quota, new NullLogger());
        $registry = new FileStorageRegistry($this->storage());
        $this->files = new FileService($this->access, $this->folders, $this->quota, $registry, new NullLogger(), 1 << 20, 10);
        $this->admin = $this->actor($this->createMember('Admin'), true);
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function upload(FileFolder $folder, string $name, string $content): StoredFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return $this->files->upload($this->admin, $folder, new UploadedFile($stream, $name, 'text/plain', strlen($content)))
            ->file;
    }

    private function usage(): StorageUsageNode
    {
        FileAccessService::invalidate();

        return (new FileStorageUsageProvider($this->access, $this->quota))->usage();
    }

    public function testCurrentOldAndTrashAreSeparated(): void
    {
        $root = $this->createFolder('Wurzel', null, 5000);
        $this->upload($root, 'plan.txt', str_repeat('a', 100));
        $this->upload($root, 'plan.txt', str_repeat('b', 40));          // neue Version, 100 werden "alt"
        $gone = $this->upload($root, 'weg.txt', str_repeat('c', 7));
        $this->files->trashFile($this->admin, $gone);
        $sub = $this->createFolder('Unterordner', $root);
        $this->upload($sub, 'drin.txt', str_repeat('d', 3));
        $this->folders->trash($this->admin, $sub);

        $node = $this->usage();

        $this->assertSame(40, $node->child('files.current')?->bytes);
        $this->assertSame(1, $node->child('files.current')?->count);
        $this->assertSame(100, $node->child('files.versions.old')?->bytes);
        $this->assertSame(10, $node->child('files.trash')?->bytes, 'Gelöschte Datei und Datei im gelöschten Ordner.');
        $this->assertSame(2, $node->child('files.trash')?->count);
        $this->assertSame(150, $node->bytes);
        $this->assertSame($this->quota->totalUsedBytes(), $node->bytes);
    }

    /**
     * Eine zurückgeholte alte Version zeigt auf denselben Pfad wie die alte. Der Pfad
     * zählt einmal - und zwar als aktuell, nicht zusätzlich als ältere Version.
     */
    public function testSharedStoragePathCountsOnceInTheHighestCategory(): void
    {
        $root = $this->createFolder('Wurzel');
        $this->upload($root, 'lied.txt', str_repeat('a', 100));
        $file = $this->upload($root, 'lied.txt', str_repeat('b', 40));
        $first = FileVersion::query()->where('file_id', $file->id)->orderBy('version_number')->firstOrFail();
        $this->files->restoreVersion($this->admin, $first);

        $node = $this->usage();

        $this->assertSame(100, $node->child('files.current')?->bytes);
        $this->assertSame(40, $node->child('files.versions.old')?->bytes);
        $this->assertSame(140, $node->bytes);
        $this->assertSame($this->quota->totalUsedBytes(), $node->bytes);
    }

    public function testTeamFoldersAreABreakdownWithQuota(): void
    {
        $choir = $this->createFolder('Chor', null, 1000);
        $board = $this->createFolder('Vorstand');
        $this->upload($choir, 'a.txt', str_repeat('a', 30));
        $this->upload($board, 'b.txt', str_repeat('b', 12));

        $node = $this->usage();
        $teams = $node->child('files.team_folders');

        $this->assertNotNull($teams);
        $this->assertTrue($teams->breakdown);
        $this->assertSame(42, $node->bytes, 'Die Aufschlüsselung verdoppelt die Summe nicht.');
        $choirNode = $teams->child('files.team_folders.' . $choir->id);
        $this->assertSame(30, $choirNode?->bytes);
        $this->assertSame(1000, $choirNode?->quotaBytes);
        $this->assertSame('Chor', $choirNode?->label);
        $this->assertNull($teams->child('files.team_folders.' . $board->id)?->quotaBytes);
    }

    public function testEmptyStorageIsZero(): void
    {
        $node = $this->usage();

        $this->assertSame('files', $node->key);
        $this->assertSame(0, $node->bytes);
        $this->assertSame(0, $node->child('files.trash')?->bytes);
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter FileStorageUsageProviderFeatureTest` → FAIL.

- [ ] **Step 3: Implementieren**

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\FileFolder;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileQuotaService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Dateiablage. Gezählt wird wie im Kontingent: jeder Speicherpfad einmal, auch wenn
 * mehrere Versionen auf ihn zeigen. Ein Pfad landet in der ersten zutreffenden
 * Kategorie - aktuelle Version vor älterer Version vor Papierkorb -, damit eine
 * spätere Aufräum-Funktion für "ältere Versionen" nie einen Pfad anfasst, den eine
 * aktuelle Version noch braucht.
 */
final class FileStorageUsageProvider implements StorageUsageProvider
{
    private const RANK_CURRENT = 0;
    private const RANK_OLD = 1;
    private const RANK_TRASH = 2;

    private const CATEGORIES = [
        self::RANK_CURRENT => ['files.current', 'Aktuelle Versionen'],
        self::RANK_OLD => ['files.versions.old', 'Ältere Versionen'],
        self::RANK_TRASH => ['files.trash', 'Papierkorb'],
    ];

    public function __construct(
        private readonly FileAccessService $access,
        private readonly FileQuotaService $quota
    ) {
    }

    public function key(): string
    {
        return 'files';
    }

    public function label(): string
    {
        return 'Dateiablage';
    }

    public function usage(): StorageUsageNode
    {
        /** @var array<string, array{rank: int, size: int}> $paths */
        $paths = [];
        $rows = DB::table('file_versions')
            ->join('files', 'files.id', '=', 'file_versions.file_id')
            ->select([
                'file_versions.id',
                'file_versions.storage_path',
                'file_versions.size',
                'files.current_version_id',
                'files.folder_id',
                'files.deleted_at',
            ])
            ->get();

        foreach ($rows as $row) {
            $inTrash = $row->deleted_at !== null || $this->access->isInTrash((int) $row->folder_id);
            $rank = match (true) {
                $inTrash => self::RANK_TRASH,
                (int) $row->id === (int) $row->current_version_id => self::RANK_CURRENT,
                default => self::RANK_OLD,
            };

            $path = (string) $row->storage_path;
            if (!isset($paths[$path]) || $rank < $paths[$path]['rank']) {
                $paths[$path] = ['rank' => $rank, 'size' => (int) $row->size];
            }
        }

        $totals = array_fill_keys(array_keys(self::CATEGORIES), ['bytes' => 0, 'count' => 0]);
        foreach ($paths as $entry) {
            $totals[$entry['rank']]['bytes'] += $entry['size'];
            $totals[$entry['rank']]['count']++;
        }

        $children = [];
        foreach (self::CATEGORIES as $rank => [$key, $label]) {
            $children[] = new StorageUsageNode($key, $label, $totals[$rank]['bytes'], $totals[$rank]['count']);
        }
        $children[] = $this->teamFolders();

        return StorageUsageNode::sum($this->key(), $this->label(), $children, count($paths));
    }

    private function teamFolders(): StorageUsageNode
    {
        $children = [];
        $roots = FileFolder::withTrashed()->whereNull('parent_id')->orderBy('name')->get();
        foreach ($roots as $root) {
            $label = (string) $root->name . ($root->deleted_at !== null ? ' (Papierkorb)' : '');
            $children[] = new StorageUsageNode(
                'files.team_folders.' . (int) $root->id,
                $label,
                $this->quota->usedBytesInSubtree((int) $root->id),
                quotaBytes: $root->quota_bytes === null ? null : (int) $root->quota_bytes
            );
        }

        $bytes = array_sum(array_map(static fn (StorageUsageNode $node): int => $node->bytes, $children));

        return new StorageUsageNode('files.team_folders', 'Teamordner', $bytes, count($children), $children, breakdown: true);
    }
}
```

- [ ] **Step 4:** `ddev php vendor/bin/phpunit --filter FileStorageUsageProviderFeatureTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Storage/FileStorageUsageProvider.php tests/Feature/FileStorageUsageProviderFeatureTest.php
git commit -m "feat(storage): Belegung der Dateiablage nach aktuell, alt und Papierkorb"
```

---

### Task 5: `DatabaseUsageProvider`

**Files:**
- Create: `src/Services/Storage/DatabaseUsageProvider.php`
- Test: `tests/Feature/DatabaseUsageProviderFeatureTest.php`

**Interfaces:**
- Produces: `new DatabaseUsageProvider()`; Wurzel `database` mit `database.attachments` (Kinder `database.attachments.<entity_type>`, `database.attachments.overhead`), `database.mail_queue`, `database.notifications` (Kinder `database.notifications.user_notifications`, `database.notifications.notification_dispatch_log`), `database.tables.<tabelle>` (höchstens 10), `database.other`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Services\Storage\DatabaseUsageProvider;
use App\Services\Storage\StorageUsageNode;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Tabellengrößen aus information_schema sind bei MySQL zwischengespeichert und im
 * Test nicht verlässlich. Geprüft werden deshalb die exakt gerechneten Teile - die
 * Anhänge nach Bereich - und die Struktur, nicht einzelne Tabellengrößen.
 */
final class DatabaseUsageProviderFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }

    private function attach(string $entityType, int $bytes): void
    {
        Attachment::create([
            'entity_type' => $entityType,
            'entity_id' => 1,
            'filename' => bin2hex(random_bytes(6)) . '.bin',
            'original_name' => 'datei.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => $bytes,
            'file_content' => str_repeat('x', $bytes),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function usage(): StorageUsageNode
    {
        return (new DatabaseUsageProvider())->usage();
    }

    public function testAttachmentsAreGroupedByAreaWithExactPayload(): void
    {
        $this->attach('finance', 1000);
        $this->attach('finance', 500);
        $this->attach('song', 300);
        $this->attach('mystery', 20);

        $attachments = $this->usage()->child('database.attachments');

        $this->assertNotNull($attachments);
        $this->assertSame(1500, $attachments->child('database.attachments.finance')?->bytes);
        $this->assertSame(2, $attachments->child('database.attachments.finance')?->count);
        $this->assertSame('Finanzen', $attachments->child('database.attachments.finance')?->label);
        $this->assertSame('Lieder', $attachments->child('database.attachments.song')?->label);
        $this->assertSame('mystery', $attachments->child('database.attachments.mystery')?->label);
        $this->assertSame(4, $attachments->count);
    }

    /**
     * Ist die gemeldete Tabellengröße kleiner als die Nutzdaten (veraltete Statistik),
     * darf "Verwaltung und Index" nicht negativ werden.
     */
    public function testOverheadIsNeverNegativeAndRootIsSumOfChildren(): void
    {
        $this->attach('event', 200000);

        $root = $this->usage();
        $attachments = $root->child('database.attachments');

        $this->assertGreaterThanOrEqual(0, $attachments?->child('database.attachments.overhead')?->bytes ?? 0);
        $this->assertGreaterThanOrEqual(200000, $attachments?->bytes);

        $sum = 0;
        foreach ($root->children as $child) {
            $sum += $child->bytes;
        }
        $this->assertSame($sum, $root->bytes);
    }

    public function testStructureContainsMailQueueNotificationsAndAtMostTenTopTables(): void
    {
        $root = $this->usage();

        $this->assertSame('database', $root->key);
        $this->assertNotNull($root->child('database.mail_queue'));
        $this->assertIsInt($root->child('database.mail_queue')?->count, 'Zeilenzahl über COUNT(*).');
        $notifications = $root->child('database.notifications');
        $this->assertNotNull($notifications?->child('database.notifications.user_notifications'));
        $this->assertNotNull($notifications?->child('database.notifications.notification_dispatch_log'));

        $top = array_filter($root->children, static fn (StorageUsageNode $n): bool => str_starts_with($n->key, 'database.tables.'));
        $this->assertLessThanOrEqual(10, count($top));
        foreach ($top as $node) {
            $this->assertNotSame('database.tables.attachments', $node->key, 'Anhänge stehen nicht doppelt.');
            $this->assertNotSame('database.tables.mail_queue', $node->key);
        }
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter DatabaseUsageProviderFeatureTest` → FAIL.

- [ ] **Step 3: Implementieren**

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Datenbank. Tabellengrößen kommen aus information_schema (Daten + Index); MySQL
 * hält sie bis zu einen Tag zwischengespeichert, sie sind also eine Näherung.
 * Exakt gerechnet werden die Anhänge (LENGTH der BLOBs) und alle Zeilenzahlen
 * (COUNT(*) statt TABLE_ROWS, das bei InnoDB nur geschätzt ist).
 *
 * Die Wurzel ist immer die Summe ihrer Kinder, damit die Übersicht in sich aufgeht,
 * auch wenn die Statistik hinter den Nutzdaten herhinkt.
 */
final class DatabaseUsageProvider implements StorageUsageProvider
{
    private const TOP_TABLES = 10;

    private const ATTACHMENT_LABELS = [
        'event' => 'Termine',
        'finance' => 'Finanzen',
        'song' => 'Lieder',
        'sponsor' => 'Sponsoren',
        'sponsorship' => 'Sponsoring',
        'task' => 'Aufgaben',
        'newsletter' => 'Newsletter',
    ];

    private const NOTIFICATION_TABLES = [
        'user_notifications' => 'In der App',
        'notification_dispatch_log' => 'Versandprotokoll',
    ];

    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Datenbank';
    }

    public function usage(): StorageUsageNode
    {
        $sizes = $this->tableSizes();
        $children = [];

        if (isset($sizes['attachments'])) {
            $children[] = $this->attachments($sizes['attachments']);
            unset($sizes['attachments']);
        }

        if (isset($sizes['mail_queue'])) {
            $children[] = $this->table('database.mail_queue', 'Mail-Warteschlange', 'mail_queue', $sizes['mail_queue']);
            unset($sizes['mail_queue']);
        }

        $notifications = [];
        foreach (self::NOTIFICATION_TABLES as $table => $label) {
            if (isset($sizes[$table])) {
                $notifications[] = $this->table('database.notifications.' . $table, $label, $table, $sizes[$table]);
                unset($sizes[$table]);
            }
        }
        if ($notifications !== []) {
            $children[] = StorageUsageNode::sum('database.notifications', 'Benachrichtigungen', $notifications);
        }

        arsort($sizes);
        foreach (array_slice($sizes, 0, self::TOP_TABLES, true) as $table => $bytes) {
            $children[] = $this->table('database.tables.' . $table, (string) $table, (string) $table, $bytes);
        }

        $rest = array_slice($sizes, self::TOP_TABLES, null, true);
        if ($rest !== []) {
            $children[] = new StorageUsageNode('database.other', 'Übrige Tabellen', array_sum($rest), count($rest));
        }

        return StorageUsageNode::sum($this->key(), $this->label(), $children);
    }

    /**
     * @return array<string, int>
     */
    private function tableSizes(): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME AS name, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );

        $sizes = [];
        foreach ($rows as $row) {
            $sizes[(string) $row->name] = (int) $row->bytes;
        }

        return $sizes;
    }

    private function table(string $key, string $label, string $table, int $bytes): StorageUsageNode
    {
        return new StorageUsageNode($key, $label, $bytes, DB::table($table)->count());
    }

    private function attachments(int $tableBytes): StorageUsageNode
    {
        $rows = DB::table('attachments')
            ->selectRaw('entity_type, COUNT(*) AS file_count, COALESCE(SUM(LENGTH(file_content)), 0) AS payload')
            ->groupBy('entity_type')
            ->get();

        $children = [];
        $payload = 0;
        $count = 0;
        foreach ($rows as $row) {
            $type = (string) $row->entity_type;
            $bytes = (int) $row->payload;
            $children[] = new StorageUsageNode(
                'database.attachments.' . $type,
                self::ATTACHMENT_LABELS[$type] ?? $type,
                $bytes,
                (int) $row->file_count
            );
            $payload += $bytes;
            $count += (int) $row->file_count;
        }

        usort($children, static fn (StorageUsageNode $a, StorageUsageNode $b): int => $b->bytes <=> $a->bytes);
        $children[] = new StorageUsageNode(
            'database.attachments.overhead',
            'Verwaltung und Index',
            max(0, $tableBytes - $payload)
        );

        return StorageUsageNode::sum('database.attachments', 'Anhänge', $children, $count);
    }
}
```

- [ ] **Step 4:** `ddev php vendor/bin/phpunit --filter DatabaseUsageProviderFeatureTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Storage/DatabaseUsageProvider.php tests/Feature/DatabaseUsageProviderFeatureTest.php
git commit -m "feat(storage): Belegung der Datenbank mit Anhängen nach Bereich"
```

---

### Task 6: `BackupUsageProvider`

**Files:**
- Create: `src/Services/Storage/BackupUsageProvider.php`
- Test: `tests/Unit/Services/Storage/BackupUsageProviderTest.php`

**Interfaces:**
- Consumes: `DirectorySize::measure()`.
- Produces: `new BackupUsageProvider(string $backupDir)`; Wurzel `backups` mit `backups.dumps.manual`, `backups.dumps.auto`, `backups.file_pool`, optional `backups.other`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\BackupUsageProvider;
use PHPUnit\Framework\TestCase;

final class BackupUsageProviderTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/backups-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/files/ab', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function put(string $relative, int $bytes): void
    {
        file_put_contents($this->dir . '/' . $relative, str_repeat('x', $bytes));
    }

    public function testDumpsAreSplitByTypeAndCountedPerBackup(): void
    {
        $manual = 'backup_manual_20261001T100000Z_0123abcd';
        $auto1 = 'backup_auto_20261002T030000Z_89abcdef';
        $auto2 = 'backup_auto_20261003T030000Z_deadbeef';
        $this->put($manual . '.sql.gz', 1000);
        $this->put($manual . '.json', 10);
        $this->put($manual . '.files.json', 5);
        $this->put($auto1 . '.sql.gz', 200);
        $this->put($auto1 . '.json', 10);
        $this->put($auto2 . '.sql', 300);
        $this->put($auto2 . '.json', 10);
        $this->put('files/ab/abcdef', 4000);
        $this->put('notiz.txt', 7);

        $node = (new BackupUsageProvider($this->dir))->usage();

        $this->assertSame(1015, $node->child('backups.dumps.manual')?->bytes);
        $this->assertSame(1, $node->child('backups.dumps.manual')?->count);
        $this->assertSame(520, $node->child('backups.dumps.auto')?->bytes);
        $this->assertSame(2, $node->child('backups.dumps.auto')?->count);
        $this->assertSame(4000, $node->child('backups.file_pool')?->bytes);
        $this->assertSame(1, $node->child('backups.file_pool')?->count);
        $this->assertSame(7, $node->child('backups.other')?->bytes);
        $this->assertSame(5542, $node->bytes);
    }

    public function testMissingDirectoryIsZeroWithoutError(): void
    {
        $node = (new BackupUsageProvider($this->dir . '/fehlt'))->usage();

        $this->assertSame(0, $node->bytes);
        $this->assertNull($node->error);
        $this->assertNull($node->child('backups.other'));
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter BackupUsageProviderTest` → FAIL.

- [ ] **Step 3: Implementieren**

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Backup-Verzeichnis. Der Typ eines Backups steht in seinem Dateinamen
 * (siehe BackupService::ID_PATTERN); Dump, Metadaten und Datei-Manifest eines
 * Backups zählen zusammen. Der Datei-Pool unter `files/` teilen sich alle Backups,
 * er steht deshalb für sich.
 *
 * BackupService selbst wird nicht benutzt: Sein Konstruktor legt das Verzeichnis an
 * und braucht einen Dump-Runner - beides hat in einer Messung nichts verloren.
 */
final class BackupUsageProvider implements StorageUsageProvider
{
    private const BACKUP_FILE = '/^(backup_(manual|auto)_[^.]+)\./';
    private const POOL_DIRECTORY = 'files';

    public function __construct(private readonly string $backupDir)
    {
    }

    public function key(): string
    {
        return 'backups';
    }

    public function label(): string
    {
        return 'Backups';
    }

    public function usage(): StorageUsageNode
    {
        $dumps = [
            'manual' => ['bytes' => 0, 'ids' => []],
            'auto' => ['bytes' => 0, 'ids' => []],
        ];
        $pool = ['bytes' => 0, 'count' => 0];
        $other = ['bytes' => 0, 'count' => 0];

        if (is_dir($this->backupDir)) {
            foreach (new \FilesystemIterator($this->backupDir) as $item) {
                if (!$item instanceof \SplFileInfo || $item->isLink()) {
                    continue;
                }
                $name = $item->getFilename();

                if ($item->isDir()) {
                    $measured = DirectorySize::measure($item->getPathname());
                    if ($name === self::POOL_DIRECTORY) {
                        $pool = $measured;
                    } else {
                        $other['bytes'] += $measured['bytes'];
                        $other['count'] += $measured['count'];
                    }
                    continue;
                }

                if (preg_match(self::BACKUP_FILE, $name, $match) === 1) {
                    $dumps[$match[2]]['bytes'] += (int) $item->getSize();
                    $dumps[$match[2]]['ids'][$match[1]] = true;
                    continue;
                }

                $other['bytes'] += (int) $item->getSize();
                $other['count']++;
            }
        }

        $children = [
            new StorageUsageNode(
                'backups.dumps.manual',
                'Datenbank-Backups (manuell)',
                $dumps['manual']['bytes'],
                count($dumps['manual']['ids'])
            ),
            new StorageUsageNode(
                'backups.dumps.auto',
                'Datenbank-Backups (automatisch)',
                $dumps['auto']['bytes'],
                count($dumps['auto']['ids'])
            ),
            new StorageUsageNode('backups.file_pool', 'Datei-Pool der Backups', $pool['bytes'], $pool['count']),
        ];
        if ($other['bytes'] > 0) {
            $children[] = new StorageUsageNode('backups.other', 'Sonstiges', $other['bytes'], $other['count']);
        }

        return StorageUsageNode::sum($this->key(), $this->label(), $children);
    }
}
```

- [ ] **Step 4:** `ddev php vendor/bin/phpunit --filter BackupUsageProviderTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Storage/BackupUsageProvider.php tests/Unit/Services/Storage/BackupUsageProviderTest.php
git commit -m "feat(storage): Belegung des Backup-Verzeichnisses"
```

---

### Task 7: `VarDirectoryUsageProvider`

**Files:**
- Create: `src/Services/Storage/VarDirectoryUsageProvider.php`
- Test: `tests/Unit/Services/Storage/VarDirectoryUsageProviderTest.php`

**Interfaces:**
- Consumes: `DirectorySize::measure()`.
- Produces: `new VarDirectoryUsageProvider(string $varDir, list<string> $excludedPaths)`; Wurzel `var` mit Kindern `var.<verzeichnis>` und optional `var.loose`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\VarDirectoryUsageProvider;
use PHPUnit\Framework\TestCase;

final class VarDirectoryUsageProviderTest extends TestCase
{
    private string $var = '';

    protected function setUp(): void
    {
        $this->var = sys_get_temp_dir() . '/var-' . bin2hex(random_bytes(6));
        foreach (['cache', 'import', 'files', 'backups', 'data/files', 'neu'] as $dir) {
            mkdir($this->var . '/' . $dir, 0777, true);
        }
        file_put_contents($this->var . '/cache/a', str_repeat('x', 100));
        file_put_contents($this->var . '/import/b', str_repeat('x', 20));
        file_put_contents($this->var . '/files/c', str_repeat('x', 5000));
        file_put_contents($this->var . '/backups/d', str_repeat('x', 6000));
        file_put_contents($this->var . '/data/files/e', str_repeat('x', 7000));
        file_put_contents($this->var . '/data/note', str_repeat('x', 3));
        file_put_contents($this->var . '/neu/f', str_repeat('x', 1));
        file_put_contents($this->var . '/lose.log', str_repeat('x', 9));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->var));
    }

    public function testMeasuresSubdirectoriesWithoutTheOtherAreas(): void
    {
        $provider = new VarDirectoryUsageProvider($this->var, [$this->var . '/files', $this->var . '/backups']);

        $node = $provider->usage();

        $this->assertNull($node->child('var.files'));
        $this->assertNull($node->child('var.backups'));
        $this->assertSame(100, $node->child('var.cache')?->bytes);
        $this->assertSame('Zwischenspeicher', $node->child('var.cache')?->label);
        $this->assertSame('neu', $node->child('var.neu')?->label);
        $this->assertSame(9, $node->child('var.loose')?->bytes);
        $this->assertSame(100 + 20 + 7003 + 1 + 9, $node->bytes);
    }

    /**
     * Liegt die Dateiablage in einem tieferen Unterordner von var/, fällt nur dieser
     * Unterordner heraus, nicht sein ganzer Elternordner.
     */
    public function testNestedAreaDirectoryIsExcludedInsideItsParent(): void
    {
        $provider = new VarDirectoryUsageProvider($this->var, [$this->var . '/data/files', $this->var . '/backups']);

        $node = $provider->usage();

        $this->assertSame(3, $node->child('var.data')?->bytes);
        $this->assertSame(5000, $node->child('var.files')?->bytes, 'var/files ist hier nicht die Ablage.');
    }

    public function testMissingExcludedPathAndMissingVarAreHarmless(): void
    {
        $node = (new VarDirectoryUsageProvider($this->var . '/fehlt', [$this->var . '/gibtsnicht']))->usage();

        $this->assertSame(0, $node->bytes);
        $this->assertSame([], $node->children);
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter VarDirectoryUsageProviderTest` → FAIL.

- [ ] **Step 3: Implementieren**

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Alles Übrige unter var/: Zwischenspeicher, Importe, Anmeldebegrenzung. Die
 * Verzeichnisse von Dateiablage und Backups misst je ein eigener Bereich; sie
 * fallen hier heraus, egal ob sie direkt unter var/, tiefer oder ganz woanders
 * liegen.
 */
final class VarDirectoryUsageProvider implements StorageUsageProvider
{
    private const LABELS = [
        'cache' => 'Zwischenspeicher',
        'import' => 'Import',
        'bank_statement_import' => 'Kontoauszug-Import',
        'htmlpurifier' => 'HTML-Filter-Cache',
        'rate-limits' => 'Anmeldebegrenzung',
    ];

    /**
     * @param list<string> $excludedPaths
     */
    public function __construct(
        private readonly string $varDir,
        private readonly array $excludedPaths
    ) {
    }

    public function key(): string
    {
        return 'var';
    }

    public function label(): string
    {
        return 'Sonstiges';
    }

    public function usage(): StorageUsageNode
    {
        if (!is_dir($this->varDir)) {
            return StorageUsageNode::sum($this->key(), $this->label(), []);
        }

        $excluded = array_values(array_filter(array_map(
            static fn (string $path): string|false => realpath($path),
            $this->excludedPaths
        )));

        $children = [];
        $loose = ['bytes' => 0, 'count' => 0];
        foreach (new \FilesystemIterator($this->varDir) as $item) {
            if (!$item instanceof \SplFileInfo || $item->isLink()) {
                continue;
            }
            $name = $item->getFilename();

            if ($item->isDir()) {
                if (in_array($item->getRealPath(), $excluded, true)) {
                    continue;
                }
                $measured = DirectorySize::measure($item->getPathname(), $excluded);
                $children[] = new StorageUsageNode(
                    'var.' . $name,
                    self::LABELS[$name] ?? $name,
                    $measured['bytes'],
                    $measured['count']
                );
                continue;
            }

            $loose['bytes'] += (int) $item->getSize();
            $loose['count']++;
        }

        usort($children, static fn (StorageUsageNode $a, StorageUsageNode $b): int => $b->bytes <=> $a->bytes);
        if ($loose['count'] > 0) {
            $children[] = new StorageUsageNode('var.loose', 'Einzelne Dateien', $loose['bytes'], $loose['count']);
        }

        return StorageUsageNode::sum($this->key(), $this->label(), $children);
    }
}
```

- [ ] **Step 4:** `ddev php vendor/bin/phpunit --filter VarDirectoryUsageProviderTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Storage/VarDirectoryUsageProvider.php tests/Unit/Services/Storage/VarDirectoryUsageProviderTest.php
git commit -m "feat(storage): Belegung der übrigen Verzeichnisse unter var"
```

---

### Task 8: `StorageUsageService`, Report und Zusammenfassung

**Files:**
- Create: `src/Services/Storage/StorageUsageReport.php`, `src/Services/Storage/StorageUsageSummary.php`, `src/Services/Storage/StorageUsageService.php`
- Test: `tests/Unit/Services/Storage/StorageUsageServiceTest.php`

**Interfaces:**
- Consumes: `StorageUsageProvider`, `StorageUsageNode::failed()`.
- Produces:
  - `StorageUsageReport(list<StorageUsageNode> $areas, \DateTimeImmutable $generatedAt)` mit `totalBytes(): int`, `area(string $key): ?StorageUsageNode`.
  - `StorageUsageSummary(list<array{key: string, label: string, bytes: int, failed: bool}> $areas, int $totalBytes, \DateTimeImmutable $generatedAt)` mit `fromReport()`, `toArray()`, `fromArray(array): ?self`.
  - `StorageUsageService(list<StorageUsageProvider> $providers, string $summaryCachePath, LoggerInterface $logger, ?\Closure $clock = null)` mit `collect(): StorageUsageReport` und `summary(): StorageUsageSummary`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\StorageUsageNode;
use App\Services\Storage\StorageUsageProvider;
use App\Services\Storage\StorageUsageService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class StorageUsageServiceTest extends TestCase
{
    private string $cache = '';
    private int $now = 1_800_000_000;
    private int $calls = 0;
    private TestHandler $log;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/storage-cache-' . bin2hex(random_bytes(6)) . '/summary.json';
        $this->calls = 0;
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->cache)));
    }

    private function provider(string $key, int $bytes, bool $throws = false): StorageUsageProvider
    {
        $test = $this;

        return new class ($key, $bytes, $throws, $test) implements StorageUsageProvider {
            public function __construct(
                private readonly string $key,
                private readonly int $bytes,
                private readonly bool $throws,
                private readonly StorageUsageServiceTest $test
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Bereich ' . $this->key;
            }

            public function usage(): StorageUsageNode
            {
                $this->test->countCall();
                if ($this->throws) {
                    throw new \RuntimeException('kaputt');
                }

                return new StorageUsageNode($this->key, $this->label(), $this->bytes);
            }
        };
    }

    public function countCall(): void
    {
        $this->calls++;
    }

    /**
     * @param list<StorageUsageProvider> $providers
     */
    private function service(array $providers): StorageUsageService
    {
        $logger = new Logger('test');
        $logger->pushHandler($this->log);

        return new StorageUsageService($providers, $this->cache, $logger, fn (): int => $this->now);
    }

    public function testTotalIsSumOfAreasAndCollectWritesTheCache(): void
    {
        $report = $this->service([$this->provider('a', 100), $this->provider('b', 23)])->collect();

        $this->assertSame(123, $report->totalBytes());
        $this->assertSame(100, $report->area('a')?->bytes);
        $this->assertFileExists($this->cache);
    }

    public function testAThrowingAreaBecomesAnErrorNodeAndIsLogged(): void
    {
        $report = $this->service([$this->provider('a', 100), $this->provider('b', 999, true)])->collect();

        $this->assertSame(100, $report->totalBytes());
        $this->assertSame('nicht ermittelbar', $report->area('b')?->error);
        $this->assertSame('Bereich b', $report->area('b')?->label);
        $records = array_values(array_filter(
            $this->log->getRecords(),
            static fn ($r): bool => ($r->context['event'] ?? null) === 'storage.usage_failed'
        ));
        $this->assertCount(1, $records);
        $this->assertSame('b', $records[0]->context['provider']);
        $this->assertInstanceOf(\RuntimeException::class, $records[0]->context['exception']);
    }

    public function testFreshCacheIsUsedWithoutRecomputing(): void
    {
        $service = $this->service([$this->provider('a', 100)]);
        $service->collect();
        $this->calls = 0;
        $this->now += 3599;

        $summary = $service->summary();

        $this->assertSame(0, $this->calls);
        $this->assertSame(100, $summary->totalBytes);
        $this->assertSame([['key' => 'a', 'label' => 'Bereich a', 'bytes' => 100, 'failed' => false]], $summary->areas);
    }

    public function testStaleCacheIsRecomputed(): void
    {
        $service = $this->service([$this->provider('a', 100)]);
        $service->collect();
        $this->calls = 0;
        $this->now += 3600;

        $service->summary();

        $this->assertSame(1, $this->calls);
    }

    /**
     * Ein Zeitstempel in der Zukunft (verstellte Uhr) darf nicht als frisch gelten -
     * sonst bliebe die Kachel beliebig lange auf dem alten Stand.
     */
    public function testCacheFromTheFutureIsRecomputed(): void
    {
        $service = $this->service([$this->provider('a', 100)]);
        $service->collect();
        $this->calls = 0;
        $this->now -= 60;

        $service->summary();

        $this->assertSame(1, $this->calls);
    }

    public function testBrokenCacheCountsAsMissing(): void
    {
        mkdir(dirname($this->cache), 0777, true);
        file_put_contents($this->cache, '{kaputt');

        $summary = $this->service([$this->provider('a', 5)])->summary();

        $this->assertSame(1, $this->calls);
        $this->assertSame(5, $summary->totalBytes);
    }

    public function testFailedAreaIsFlaggedInTheSummary(): void
    {
        $summary = $this->service([$this->provider('a', 5), $this->provider('b', 1, true)])->summary();

        $this->assertTrue($summary->areas[1]['failed']);
        $this->assertFalse($summary->areas[0]['failed']);
    }

    public function testUnwritableCacheIsLoggedButResultReturned(): void
    {
        $blocker = sys_get_temp_dir() . '/storage-blocker-' . bin2hex(random_bytes(6));
        file_put_contents($blocker, 'x');
        $this->cache = $blocker . '/sub/summary.json';

        try {
            $report = $this->service([$this->provider('a', 7)])->collect();
        } finally {
            unlink($blocker);
        }

        $this->assertSame(7, $report->totalBytes());
        $events = array_map(static fn ($r) => $r->context['event'] ?? null, $this->log->getRecords());
        $this->assertContains('storage.summary_cache_write_failed', $events);
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter StorageUsageServiceTest` → FAIL.

- [ ] **Step 3: Implementieren**

`src/Services/Storage/StorageUsageReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ergebnis einer vollständigen Messung: die Wurzelknoten der Bereiche.
 */
final class StorageUsageReport
{
    /**
     * @param list<StorageUsageNode> $areas
     */
    public function __construct(
        public readonly array $areas,
        public readonly \DateTimeImmutable $generatedAt
    ) {
    }

    public function totalBytes(): int
    {
        return array_sum(array_map(static fn (StorageUsageNode $area): int => $area->bytes, $this->areas));
    }

    public function area(string $key): ?StorageUsageNode
    {
        foreach ($this->areas as $area) {
            if ($area->key === $key) {
                return $area;
            }
        }

        return null;
    }
}
```

`src/Services/Storage/StorageUsageSummary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Kurzfassung für die Dashboard-Kachel: nur die Wurzeln. Wird als JSON
 * zwischengespeichert, damit das Dashboard nicht bei jedem Aufruf Verzeichnisse
 * durchläuft.
 */
final class StorageUsageSummary
{
    /**
     * @param list<array{key: string, label: string, bytes: int, failed: bool}> $areas
     */
    public function __construct(
        public readonly array $areas,
        public readonly int $totalBytes,
        public readonly \DateTimeImmutable $generatedAt
    ) {
    }

    public static function fromReport(StorageUsageReport $report): self
    {
        $areas = [];
        foreach ($report->areas as $area) {
            $areas[] = [
                'key' => $area->key,
                'label' => $area->label,
                'bytes' => $area->bytes,
                'failed' => $area->error !== null,
            ];
        }

        return new self($areas, $report->totalBytes(), $report->generatedAt);
    }

    /**
     * @return array{generated_at: int, total_bytes: int, areas: list<array{key: string, label: string, bytes: int, failed: bool}>}
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->getTimestamp(),
            'total_bytes' => $this->totalBytes,
            'areas' => $this->areas,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!is_int($data['generated_at'] ?? null) || !is_int($data['total_bytes'] ?? null) || !is_array($data['areas'] ?? null)) {
            return null;
        }

        $areas = [];
        foreach ($data['areas'] as $area) {
            if (
                !is_array($area)
                || !is_string($area['key'] ?? null)
                || !is_string($area['label'] ?? null)
                || !is_int($area['bytes'] ?? null)
                || !is_bool($area['failed'] ?? null)
            ) {
                return null;
            }
            $areas[] = [
                'key' => $area['key'],
                'label' => $area['label'],
                'bytes' => $area['bytes'],
                'failed' => $area['failed'],
            ];
        }

        return new self($areas, $data['total_bytes'], (new \DateTimeImmutable())->setTimestamp($data['generated_at']));
    }
}
```

`src/Services/Storage/StorageUsageService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Psr\Log\LoggerInterface;

/**
 * Sammelt die Bereiche der Speicherübersicht. Ein Bereich, der wirft, wird hier an
 * einer Stelle abgefangen und als "nicht ermittelbar" eingesetzt - die Seite bleibt
 * benutzbar, die übrigen Zahlen stimmen.
 *
 * Jede vollständige Messung schreibt eine Kurzfassung in eine Cache-Datei. Die
 * Dashboard-Kachel liest nur sie (höchstens eine Stunde alt); die Seite /storage
 * misst immer neu.
 */
final class StorageUsageService
{
    private const SUMMARY_MAX_AGE_SECONDS = 3600;

    /**
     * @param list<StorageUsageProvider> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $summaryCachePath,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $clock = null
    ) {
    }

    public function collect(): StorageUsageReport
    {
        $areas = [];
        foreach ($this->providers as $provider) {
            try {
                $areas[] = $provider->usage();
            } catch (\Throwable $e) {
                $this->logger->error('Storage usage could not be determined.', [
                    'event' => 'storage.usage_failed',
                    'provider' => $provider->key(),
                    'exception' => $e,
                ]);
                $areas[] = StorageUsageNode::failed($provider->key(), $provider->label());
            }
        }

        $report = new StorageUsageReport($areas, (new \DateTimeImmutable())->setTimestamp($this->now()));
        $this->writeSummary(StorageUsageSummary::fromReport($report));

        return $report;
    }

    public function summary(): StorageUsageSummary
    {
        $cached = $this->readSummary();
        if ($cached !== null) {
            $age = $this->now() - $cached->generatedAt->getTimestamp();
            if ($age >= 0 && $age < self::SUMMARY_MAX_AGE_SECONDS) {
                return $cached;
            }
        }

        return StorageUsageSummary::fromReport($this->collect());
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }

    private function readSummary(): ?StorageUsageSummary
    {
        if (!is_file($this->summaryCachePath)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($this->summaryCachePath), true);

        return is_array($decoded) ? StorageUsageSummary::fromArray($decoded) : null;
    }

    /**
     * Erst in eine Nachbardatei, dann umbenennen: Ein gleichzeitiger Leser sieht nie
     * eine halb geschriebene Datei.
     */
    private function writeSummary(StorageUsageSummary $summary): void
    {
        $directory = dirname($this->summaryCachePath);
        $temporary = $this->summaryCachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $written = (is_dir($directory) || @mkdir($directory, 0750, true))
            && @file_put_contents($temporary, (string) json_encode($summary->toArray())) !== false
            && @rename($temporary, $this->summaryCachePath);

        if (!$written) {
            @unlink($temporary);
            $this->logger->warning('Storage usage summary could not be cached.', [
                'event' => 'storage.summary_cache_write_failed',
                'path' => $this->summaryCachePath,
            ]);
        }
    }
}
```

- [ ] **Step 4:** `ddev php vendor/bin/phpunit --filter StorageUsageServiceTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Storage/StorageUsageReport.php src/Services/Storage/StorageUsageSummary.php src/Services/Storage/StorageUsageService.php tests/Unit/Services/Storage/StorageUsageServiceTest.php
git commit -m "feat(storage): Messung sammeln, Fehler je Bereich abfangen, Kurzfassung zwischenspeichern"
```

---

### Task 9: Seite `/storage` mit Route, Navigation und Oberfläche

**Files:**
- Create: `src/Controllers/StorageController.php`, `templates/storage/index.twig`
- Modify: `src/Settings.php` (neuer Block `storage` nach `backup`)
- Modify: `src/Dependencies.php` (StorageUsageService, StorageController)
- Modify: `src/Routes.php` (nach der Backup-Gruppe, ~Zeile 850)
- Modify: `src/Navigation/NavigationBuilder.php` (nach dem Backups-Eintrag, ~Zeile 434)
- Modify: `public/css/style.css` (ans Ende)
- Modify: `tests/e2e/data/roleAccessMatrix.mjs:15`
- Test: `tests/Feature/StoragePageFeatureTest.php`, Ergänzung in `tests/Feature/NavigationBuilderFeatureTest.php`

**Interfaces:**
- Consumes: `StorageUsageService::collect()`, alle Provider, Twig-Filter `format_bytes`.
- Produces: `new StorageController(Twig $view, StorageUsageService $usage)` mit `index(Request, Response): Response`; Settings `storage.summary_cache`, `storage.var_dir`; DI-Eintrag `StorageUsageService::class`.

- [ ] **Step 1: Failing tests**

`tests/Feature/StoragePageFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\StorageController;
use App\Services\Storage\StorageUsageNode;
use App\Services\Storage\StorageUsageProvider;
use App\Services\Storage\StorageUsageService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StoragePageFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private string $cache = '';

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 1, 'can_manage_storage' => true];
        $this->cache = sys_get_temp_dir() . '/storage-page-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cache);
        $_SESSION = [];
    }

    private function fixed(StorageUsageNode $node): StorageUsageProvider
    {
        return new class ($node) implements StorageUsageProvider {
            public function __construct(private readonly StorageUsageNode $node)
            {
            }

            public function key(): string
            {
                return $this->node->key;
            }

            public function label(): string
            {
                return $this->node->label;
            }

            public function usage(): StorageUsageNode
            {
                return $this->node;
            }
        };
    }

    private function failing(): StorageUsageProvider
    {
        return new class implements StorageUsageProvider {
            public function key(): string
            {
                return 'backups';
            }

            public function label(): string
            {
                return 'Backups';
            }

            public function usage(): StorageUsageNode
            {
                throw new \RuntimeException('nicht lesbar');
            }
        };
    }

    public function testPageShowsAreasNestedRowsTeamFoldersAndFailedArea(): void
    {
        $files = StorageUsageNode::sum('files', 'Dateiablage', [
            new StorageUsageNode('files.current', 'Aktuelle Versionen', 3 * 1048576, 12),
            new StorageUsageNode('files.versions.old', 'Ältere Versionen', 1048576, 4),
            new StorageUsageNode('files.team_folders', 'Teamordner', 4 * 1048576, 1, [
                new StorageUsageNode('files.team_folders.5', 'Vorstand', 4 * 1048576, quotaBytes: 10 * 1048576),
            ], breakdown: true),
        ]);
        $database = StorageUsageNode::sum('database', 'Datenbank', [
            StorageUsageNode::sum('database.attachments', 'Anhänge', [
                new StorageUsageNode('database.attachments.finance', 'Finanzen', 2048, 3),
            ], 3),
        ]);
        $service = new StorageUsageService(
            [$this->fixed($files), $this->fixed($database), $this->failing()],
            $this->cache,
            new NullLogger()
        );

        $response = (new StorageController($this->createAppTwig('/storage'), $service))
            ->index($this->makeRequest('GET', '/storage'), $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Speicherplatz', $body);
        $this->assertStringContainsString('Dateiablage', $body);
        $this->assertStringContainsString('Ältere Versionen', $body);
        $this->assertStringContainsString('4,0 MB', $body, 'Summe der Dateiablage ohne Aufschlüsselung.');
        $this->assertStringContainsString('Finanzen', $body);
        $this->assertStringContainsString('2 KB', $body);
        $this->assertStringContainsString('Vorstand', $body);
        $this->assertStringContainsString('10,0 MB', $body, 'Kontingent des Teamordners.');
        $this->assertStringContainsString('nicht ermittelbar', $body);
        $this->assertStringContainsString('--usage-share:', $body);
        $this->assertStringContainsString('data-storage-key="files.versions.old"', $body);
    }

    public function testRouteIsGatedByTheStorageRight(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertMatchesRegularExpression(
            "~get\\('/storage', \\[StorageController::class, 'index'\\]\\)\\s*->add\\(new RoleMiddleware\\(requiresStorageManagement: true\\)\\)~",
            $routes
        );
    }
}
```

In `NavigationBuilderFeatureTest` ergänzen:

```php
    public function testStorageItemFollowsTheStorageRight(): void
    {
        $administration = $this->section($this->build(['can_manage_storage' => true]), 'administration');

        $this->assertNotNull($administration);
        $this->assertSame(['/storage'], array_column($administration['items'], 'url'));
        $this->assertNotContains('/storage', $this->urls($this->build(['can_manage_backups' => true])));
    }
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter "StoragePageFeatureTest|NavigationBuilderFeatureTest"` → FAIL.

- [ ] **Step 3: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Storage\StorageUsageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Übersicht über den belegten Speicherplatz. Misst bei jedem Aufruf neu; die
 * Messung frischt nebenbei die Kurzfassung der Dashboard-Kachel auf.
 */
final class StorageController
{
    public function __construct(
        private readonly Twig $view,
        private readonly StorageUsageService $usage
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'storage/index.twig', [
            'report' => $this->usage->collect(),
        ]);
    }
}
```

- [ ] **Step 4: Vorlage `templates/storage/index.twig`**

```twig
{% extends "layout.twig" %}

{% macro rows(nodes, total, depth) %}
    {% for node in nodes %}
        {% if not node.breakdown %}
            {% set _share = total > 0 ? node.bytes / total * 100 : 0 %}
            <tr class="storage-row storage-row--depth-{{ depth }}" data-storage-key="{{ node.key }}">
                <th scope="row" data-label="Bereich">{{ node.label }}</th>
                <td data-label="Anzahl" class="text-end">{{ node.count is null ? "–" : node.count }}</td>
                <td data-label="Größe" class="text-end text-nowrap">{{ node.bytes|format_bytes }}</td>
                <td data-label="Anteil" class="text-end">{{ _share|round(1)|number_format(1, ",", ".") }} %</td>
            </tr>
            {% if node.children is not empty %}
                {{ _self.rows(node.children, total, depth + 1) }}
            {% endif %}
        {% endif %}
    {% endfor %}
{% endmacro %}

{% block title %}
    Speicherplatz - {{ app_settings.app_name|default("Chor-Manager") }}
{% endblock title %}

{% block page_header %}
    <section class="page-header">
        <div>
            <p class="text-uppercase text-muted small mb-1">Administration</p>
            <h1 class="h2 mb-1">Speicherplatz</h1>
            <p class="text-muted mb-0">
                Belegter Speicherplatz der Installation, berechnet am {{ report.generatedAt|date("d.m.Y H:i") }} Uhr.
            </p>
        </div>
    </section>
{% endblock page_header %}

{% block content %}
    {% set _total = report.totalBytes %}

    <div class="storage-summary">
        <article class="surface-card storage-kpi">
            <p class="storage-kpi__label">Gesamt</p>
            <p class="storage-kpi__value">{{ _total|format_bytes }}</p>
        </article>
        {% for area in report.areas %}
            <article class="surface-card storage-kpi">
                <p class="storage-kpi__label">{{ area.label }}</p>
                <p class="storage-kpi__value">
                    {% if area.error %}
                        <span class="text-muted">nicht ermittelbar</span>
                    {% else %}
                        {{ area.bytes|format_bytes }}
                    {% endif %}
                </p>
            </article>
        {% endfor %}
    </div>

    {% for area in report.areas %}
        <section class="surface-card storage-area" aria-labelledby="storage-area-{{ area.key }}">
            <h2 class="h5 mb-3" id="storage-area-{{ area.key }}">{{ area.label }}</h2>

            {% if area.error %}
                <p class="text-muted mb-0">Dieser Bereich ist nicht ermittelbar. Details stehen im Protokoll.</p>
            {% else %}
                {% set _area_share = _total > 0 ? area.bytes / _total * 100 : 0 %}
                <div class="usage-bar mb-3" role="img" aria-label="{{ _area_share|round(1) }} % des Gesamtspeichers">
                    <span class="usage-bar__fill" style="--usage-share: {{ _area_share|round(1) }}%"></span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle storage-table">
                        <thead>
                            <tr>
                                <th scope="col">Bereich</th>
                                <th scope="col" class="text-end">Anzahl</th>
                                <th scope="col" class="text-end">Größe</th>
                                <th scope="col" class="text-end">Anteil</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{ _self.rows(area.children, _total, 0) }}
                        </tbody>
                    </table>
                </div>

                {% for breakdown in area.children|filter(child => child.breakdown) %}
                    <h3 class="h6 mt-3">{{ breakdown.label }}</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle storage-table">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col" class="text-end">Belegt</th>
                                    <th scope="col" class="text-end">Kontingent</th>
                                    <th scope="col">Auslastung</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for item in breakdown.children %}
                                    <tr data-storage-key="{{ item.key }}">
                                        <th scope="row" data-label="Name">{{ item.label }}</th>
                                        <td data-label="Belegt" class="text-end text-nowrap">{{ item.bytes|format_bytes }}</td>
                                        <td data-label="Kontingent" class="text-end text-nowrap">
                                            {{ item.quotaBytes is null ? "unbegrenzt" : item.quotaBytes|format_bytes }}
                                        </td>
                                        <td data-label="Auslastung">
                                            {% if item.quotaBytes %}
                                                {% set _quota_share = min(100, item.bytes / item.quotaBytes * 100) %}
                                                <div class="usage-bar" role="img" aria-label="{{ _quota_share|round }} % des Kontingents">
                                                    <span class="usage-bar__fill" style="--usage-share: {{ _quota_share|round(1) }}%"></span>
                                                </div>
                                            {% else %}
                                                <span class="text-muted">–</span>
                                            {% endif %}
                                        </td>
                                    </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    </div>
                {% endfor %}
            {% endif %}
        </section>
    {% endfor %}
{% endblock content %}
```

Hinweis: Hält twigcs `{{ _self.rows(...) }}` aus einem Block heraus nicht aus oder meldet Twig „macro not found“, stattdessen `{% import _self as storage %}` als erste Zeile in `{% block content %}` und im Makro selbst, Aufrufe dann `storage.rows(...)`.

- [ ] **Step 5: CSS** ans Ende von `public/css/style.css`:

```css
/* Speicherplatz: Kennzahlen, Bereichskarten und Belegungsbalken.
   Die Balkenbreite setzt die Vorlage über --usage-share. */
.storage-summary {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
    margin-bottom: 1.5rem;
}

.storage-kpi {
    padding: 1rem 1.25rem;
}

.storage-kpi__label {
    color: var(--bs-secondary-color);
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}

.storage-kpi__value {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 0;
}

.storage-area {
    margin-bottom: 1.5rem;
    padding: 1rem 1.25rem;
}

.storage-row--depth-1 th {
    padding-left: 1.5rem;
}

.storage-row--depth-2 th {
    padding-left: 3rem;
}

.storage-row--depth-0 th {
    font-weight: 600;
}

.usage-bar {
    background: var(--bs-secondary-bg);
    border-radius: 999px;
    display: flex;
    height: 0.75rem;
    min-width: 6rem;
    overflow: hidden;
}

.usage-bar__fill {
    background: var(--bs-primary);
    display: block;
    width: var(--usage-share, 0%);
}

.usage-bar__fill--2 {
    background: var(--bs-info);
}

.usage-bar__fill--3 {
    background: var(--bs-warning);
}

.usage-bar__fill--4 {
    background: var(--bs-secondary);
}

.usage-legend {
    display: flex;
    flex-wrap: wrap;
    font-size: 0.875rem;
    gap: 0.25rem 1rem;
    list-style: none;
    margin: 0.5rem 0 0;
    padding: 0;
}

.usage-legend__swatch {
    border-radius: 2px;
    display: inline-block;
    height: 0.75rem;
    margin-right: 0.35rem;
    vertical-align: -0.1rem;
    width: 0.75rem;
}

@media (max-width: 575.98px) {
    .storage-row--depth-1 th,
    .storage-row--depth-2 th {
        padding-left: 0.5rem;
    }
}
```

Für die Legende in Task 10 bekommen `.usage-legend__swatch` dieselben Farbklassen: in der Vorlage `class="usage-legend__swatch usage-bar__fill usage-bar__fill--N"` - das `width` aus `.usage-bar__fill` überschreibt `.usage-legend__swatch` durch spätere Deklaration nicht; deshalb ergänzen:

```css
.usage-legend__swatch.usage-bar__fill {
    width: 0.75rem;
}
```

- [ ] **Step 6: Settings.** In `src/Settings.php` nach dem `'backup' => [...]`-Block:

```php
            // Speicherplatz-Übersicht: Kurzfassung für die Dashboard-Kachel und das
            // Verzeichnis, dessen übrige Unterordner unter "Sonstiges" erscheinen.
            'storage' => [
                'summary_cache' => __DIR__ . '/../var/cache/storage-usage-summary.json',
                'var_dir' => __DIR__ . '/../var',
            ],
```

- [ ] **Step 7: DI.** In `src/Dependencies.php` nach dem `FileQuotaService`-Eintrag:

```php
        // Dateiablage nur mit aktivem Modul - ohne sie gibt es keine Tabellen-Inhalte
        // und kein Ablageverzeichnis, das sich zu zeigen lohnt.
        StorageUsageService::class => function (ContainerInterface $c): StorageUsageService {
            $settings = $c->get('settings');
            $providers = [];
            if ($settings['modules']['files'] ?? false) {
                $providers[] = new FileStorageUsageProvider(
                    $c->get(FileAccessService::class),
                    $c->get(FileQuotaService::class)
                );
            }
            $providers[] = new DatabaseUsageProvider();
            $providers[] = new BackupUsageProvider($settings['backup']['dir']);
            $providers[] = new VarDirectoryUsageProvider(
                $settings['storage']['var_dir'],
                [$settings['files']['storage_path'], $settings['backup']['dir']]
            );

            return new StorageUsageService(
                $providers,
                $settings['storage']['summary_cache'],
                $c->get(LoggerInterface::class)
            );
        },
        StorageController::class => \DI\autowire(),
```

Imports ergänzen: `App\Controllers\StorageController`, `App\Services\Storage\{BackupUsageProvider, DatabaseUsageProvider, FileStorageUsageProvider, StorageUsageService, VarDirectoryUsageProvider}` (einzelne `use`-Zeilen wie im Bestand). `$settings['modules']['files']` ist derselbe Schalter, den `RoleController::moduleFlags()` liest (`src/Settings.php:44`).

- [ ] **Step 8: Route.** In `src/Routes.php` direkt nach der Backup-Gruppe (`->add(new RoleMiddleware(requiresBackupManagement: true));`):

```php

            // Speicherplatz-Übersicht
            $group->get('/storage', [StorageController::class, 'index'])
                ->add(new RoleMiddleware(requiresStorageManagement: true));
```

`use App\Controllers\StorageController;` oben ergänzen.

- [ ] **Step 9: Navigation.** In `NavigationBuilder` nach dem Backups-Eintrag:

```php
                        [
                            'label' => 'Speicherplatz',
                            'url' => '/storage',
                            'icon' => 'bi-hdd',
                            'keywords' => ['speicher', 'platz', 'belegung', 'aufräumen'],
                            'prefixes' => ['/storage'],
                            'navKeys' => ['storage'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_storage'),
                        ],
```

- [ ] **Step 10: E2E-Rechtematrix.** In `tests/e2e/data/roleAccessMatrix.mjs` nach der `/backups`-Zeile:
  `{ path: '/storage', requires: ['can_manage_storage'] },`

- [ ] **Step 11:** `ddev php vendor/bin/phpunit --filter "StoragePageFeatureTest|NavigationBuilderFeatureTest|NavigationMenuRender|RoleMiddlewareGateTable"` → PASS. Bricht ein Navigationstest, der die Einträge der Administration vollständig aufzählt, diesen um `/storage` ergänzen.

- [ ] **Step 12: Stil.** `ddev composer twigcs` und `ddev composer phpcs` → ohne Befund (sonst `ddev composer twigcbf` / `ddev composer phpcbf`).

- [ ] **Step 13: Commit**

```bash
git add src/Controllers/StorageController.php templates/storage/index.twig src/Settings.php src/Dependencies.php src/Routes.php src/Navigation/NavigationBuilder.php public/css/style.css tests/e2e/data/roleAccessMatrix.mjs tests/Feature/StoragePageFeatureTest.php tests/Feature/NavigationBuilderFeatureTest.php
git commit -m "feat(storage): Seite Speicherplatz mit Bereichen, Teamordnern und Balken"
```

---

### Task 10: Dashboard-Kachel

**Files:**
- Modify: `src/Controllers/DashboardController.php`
- Modify: `src/Dependencies.php:418-425` (DashboardController-Factory)
- Modify: `templates/dashboard/index.twig` (in `dashboard-action-grid`, nach der Kachel „Ausstehende Anmeldungen“, vor `</div>` in Zeile 113)
- Modify: `tests/Feature/DashboardFeatureTest.php` (Filter `format_bytes` in `createDashboardTwig`, neue Tests)

**Interfaces:**
- Consumes: `StorageUsageService::summary(): StorageUsageSummary`.
- Produces: `DashboardController::__construct(Twig, MailQueueAdminService, TaskPolicy, array $settings = [], ?StorageUsageService $storageUsage = null, LoggerInterface $logger = new NullLogger())`; Vorlagenvariable `storage_tile` = `null` oder `array{summary: ?StorageUsageSummary}`.

- [ ] **Step 1: Failing tests** in `DashboardFeatureTest`. In `createDashboardTwig()` nach dem `person_name`-Filter:

```php
        $environment->addFilter(new TwigFilter(
            'format_bytes',
            static fn (mixed $bytes): string => \App\Util\ByteFormatter::format((int) $bytes)
        ));
```

Neue Tests:

```php
    private function storageService(int $bytes, bool $fails = false): \App\Services\Storage\StorageUsageService
    {
        $provider = new class ($bytes, $fails) implements \App\Services\Storage\StorageUsageProvider {
            public function __construct(private readonly int $bytes, private readonly bool $fails)
            {
            }

            public function key(): string
            {
                return 'database';
            }

            public function label(): string
            {
                return 'Datenbank';
            }

            public function usage(): \App\Services\Storage\StorageUsageNode
            {
                if ($this->fails) {
                    throw new \RuntimeException('kaputt');
                }

                return new \App\Services\Storage\StorageUsageNode('database', 'Datenbank', $this->bytes);
            }
        };

        return new \App\Services\Storage\StorageUsageService(
            [$provider],
            sys_get_temp_dir() . '/dashboard-storage-' . bin2hex(random_bytes(6)) . '.json',
            new \Psr\Log\NullLogger()
        );
    }

    private function renderDashboard(?\App\Services\Storage\StorageUsageService $storage): string
    {
        $settings = ['modules' => []];
        $controller = new DashboardController(
            $this->createDashboardTwig($settings),
            new MailQueueAdminService(),
            new TaskPolicy($_SESSION),
            $settings,
            $storage
        );

        return (string) $controller->index($this->makeRequest('GET', '/dashboard'), $this->makeResponse())->getBody();
    }

    public function testStorageTileShowsTotalAndLinkForTheStorageRight(): void
    {
        $_SESSION = ['user_id' => (int) $this->createUser()->id, 'can_manage_storage' => true];

        $body = $this->renderDashboard($this->storageService(5 * 1048576));

        $this->assertStringContainsString('Speicherplatz', $body);
        $this->assertStringContainsString('5,0 MB', $body);
        $this->assertStringContainsString('href="/storage"', $body);
        $this->assertStringContainsString('Stand:', $body);
    }

    public function testStorageTileIsHiddenWithoutTheRight(): void
    {
        $_SESSION = ['user_id' => (int) $this->createUser()->id, 'can_manage_storage' => false];

        $body = $this->renderDashboard($this->storageService(5 * 1048576));

        $this->assertStringNotContainsString('href="/storage"', $body);
    }

    public function testFailedStorageAreaShowsUnavailableButDashboardRenders(): void
    {
        $_SESSION = ['user_id' => (int) $this->createUser()->id, 'can_manage_storage' => true];

        $body = $this->renderDashboard($this->storageService(0, true));

        $this->assertStringContainsString('nicht ermittelbar', $body);
        $this->assertStringContainsString('Schnellzugriff', $body);
    }
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter DashboardFeatureTest` → FAIL (neue Tests).

- [ ] **Step 3: Controller.** Konstruktor erweitern (bestehende Aufrufer mit vier Argumenten bleiben gültig):

```php
    public function __construct(
        Twig $view,
        \App\Services\MailQueueAdminService $mailQueueAdminService,
        TaskPolicy $taskPolicy,
        array $settings = [],
        private readonly ?StorageUsageService $storageUsage = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
```

In `index()` vor `$data = [`:

```php
        // Nur die zwischengespeicherte Kurzfassung - das Dashboard soll nicht bei
        // jedem Aufruf Verzeichnisse durchlaufen. Fehlt sie oder ist sie zu alt,
        // misst summary() einmal neu.
        $storageTile = null;
        if ($this->storageUsage !== null && (bool) ($_SESSION['can_manage_storage'] ?? false)) {
            try {
                $storageTile = ['summary' => $this->storageUsage->summary()];
            } catch (\Throwable $e) {
                $this->logger->error('Storage tile could not be filled.', [
                    'event' => 'storage.dashboard_tile_failed',
                    'exception' => $e,
                ]);
                $storageTile = ['summary' => null];
            }
        }
```

und im `$data`-Array `'storage_tile' => $storageTile,`. Imports: `App\Services\Storage\StorageUsageService`, `Psr\Log\LoggerInterface`, `Psr\Log\NullLogger`.

- [ ] **Step 4: DI.** In der `DashboardController`-Factory nach `$c->get('settings')`:
  `$c->get(StorageUsageService::class),` und `$c->get(LoggerInterface::class)`.

- [ ] **Step 5: Vorlage.** In `templates/dashboard/index.twig` vor dem schließenden `</div>` des `dashboard-action-grid` (nach dem `{% endif %}` der Anmeldungs-Kachel):

```twig
                {% if storage_tile %}
                    {% set _storage = storage_tile.summary %}
                    <article class="surface-card dashboard-panel dashboard-panel--action">
                        <div class="dashboard-panel__body">
                            <p class="dashboard-panel__eyebrow">System</p>
                            <h3 class="dashboard-panel__title">
                                <i class="bi bi-hdd"></i>
                                Speicherplatz
                            </h3>
                            {% if _storage is null %}
                                <p class="dashboard-panel__text mb-0">Speicherplatz nicht ermittelbar.</p>
                            {% else %}
                                <p class="dashboard-panel__text mb-1"><strong>{{ _storage.totalBytes|format_bytes }}</strong> belegt</p>
                                <div class="usage-bar" role="img" aria-label="Aufteilung des Speicherplatzes">
                                    {% for area in _storage.areas %}
                                        {% set _area_share = _storage.totalBytes > 0 ? area.bytes / _storage.totalBytes * 100 : 0 %}
                                        <span class="usage-bar__fill usage-bar__fill--{{ loop.index }}"
                                              style="--usage-share: {{ _area_share|round(1) }}%"></span>
                                    {% endfor %}
                                </div>
                                <ul class="usage-legend">
                                    {% for area in _storage.areas %}
                                        <li>
                                            <span class="usage-legend__swatch usage-bar__fill usage-bar__fill--{{ loop.index }}"></span>
                                            {{ area.label }}:
                                            {{ area.failed ? "nicht ermittelbar" : area.bytes|format_bytes }}
                                        </li>
                                    {% endfor %}
                                </ul>
                                <p class="dashboard-panel__text small text-muted mb-0 mt-2">
                                    Stand: {{ _storage.generatedAt|date("d.m.Y H:i") }}
                                </p>
                            {% endif %}
                        </div>
                        <a href="/storage" class="btn btn-outline-secondary">Details</a>
                    </article>
                {% endif %}
```

- [ ] **Step 6:** `ddev php vendor/bin/phpunit --filter DashboardFeatureTest` → PASS.

- [ ] **Step 7:** `ddev composer twigcs` und `ddev composer phpcs` → ohne Befund.

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/DashboardController.php src/Dependencies.php templates/dashboard/index.twig tests/Feature/DashboardFeatureTest.php
git commit -m "feat(storage): Dashboard-Kachel mit Gesamtbelegung und Aufteilung"
```

---

### Task 11: Seed-Abdeckung prüfen und Gesamtlauf

**Files:**
- Test: `tests/Feature/StorageSeedCoverageFeatureTest.php`

Die Seeds decken bereits ab (geprüft beim Planen, `DevSeedService::seedFileManagement`): ältere Versionen (Partitur dreimal, Mietvertrag zweimal), gelöschte Datei („Pressetext Entwurf alt.txt“), Datei im gelöschten Ordner („Altes Archiv“), Anhänge für `event`, `finance`, `song`, `sponsor`, `sponsorship`, `task`, `newsletter`, dazu `mail_queue` und `user_notifications`. Neu ist nur das Recht der Admin-Rolle (Task 2). Dieser Task hält die Abdeckung fest.

- [ ] **Step 1: Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Jede Kategorie der Speicherübersicht soll auf einer frisch geseedeten
 * Installation einen Wert größer 0 haben. Geprüft wird die Quelle der Seeds,
 * damit eine gelöschte Fixture auffällt, bevor die Übersicht leer aussieht.
 */
final class StorageSeedCoverageFeatureTest extends TestCase
{
    public function testSeedsFeedEveryStorageCategory(): void
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertStringContainsString("'can_manage_storage' => 1", $seed);
        $this->assertGreaterThanOrEqual(2, substr_count($seed, "'Ave verum corpus - Partitur.pdf'"), 'ältere Versionen');
        $this->assertStringContainsString('$this->fileService->trashFile(', $seed, 'gelöschte Datei');
        $this->assertStringContainsString('$this->fileFolderService->trash(', $seed, 'gelöschter Ordner');
        foreach (['event', 'finance', 'song', 'sponsor', 'sponsorship', 'task'] as $type) {
            $this->assertStringContainsString("'entity_type' => '{$type}'", $seed, "Anhänge {$type}");
        }
        $this->assertStringContainsString('NewsletterAttachmentService::ENTITY_TYPE', $seed);
        $this->assertStringContainsString('$this->seedMailQueue(', $seed);
        $this->assertStringContainsString('$this->seedUserNotifications(', $seed);
    }
}
```

Alle geprüften Literale stehen beim Planen im Seed (`event` ~1461, `finance` ~1860, `sponsorship` ~3417, `sponsor` ~3474, `song` ~3649, `task` ~3970). Fehlt eins, ist eine Fixture verloren gegangen - dann die Fixture ergänzen, nicht den Test lockern.

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter StorageSeedCoverageFeatureTest` → PASS.

- [ ] **Step 3: Volle Suite:** `ddev composer test:parallel` → grün. Rote Tests nach `/test-runs` einordnen (parallel rot, allein grün → fehlendes `setUp`).

- [ ] **Step 4: Stil:** `ddev composer phpcs` und `ddev composer twigcs` → ohne Befund.

- [ ] **Step 5: Seed einmal real laufen lassen** (Entwicklungsdatenbank): über die bestehende Dev-Seed-Funktion; danach mit dem Admin-Konto `/storage` aufrufen ist laut Projektregel nur auf ausdrücklichen Wunsch per Browser zu prüfen - sonst genügt die Testsuite.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/StorageSeedCoverageFeatureTest.php
git commit -m "test(storage): Seed-Abdeckung der Speicherübersicht festhalten"
```

- [ ] **Step 7:** Abschluss über `/git-commit` (Squash, deutscher Commit mit Begründung und Nachweisen, Fast-Forward nach `main`, danach einmal nach dem Push fragen).

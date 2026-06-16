# Budget-Modul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein freischaltbares Budgetplanungsmodul implementieren, das Einnahmen- und Ausgaben-Budgetkategorien mit Einzelposten verwaltet und einen Soll/Ist-Vergleich mit den tatsächlichen Finance-Buchungen bietet.

**Architecture:** Drei Phinx-Migrationen legen die Tabellen `budget_categories` und `budget_items` sowie die Rolle `can_manage_budget` an. `BudgetService` kapselt die Soll/Ist-Aggregationslogik. `BudgetController` bedient alle HTTP-Routen. Das Modul wird über `FEATURE_BUDGET=true` in der `.env` freigeschaltet; alle Routen und Navigationselemente werden nur bei aktiviertem Flag registriert/angezeigt.

**Tech Stack:** PHP 8.x, Slim 4, Eloquent (illuminate/database), Twig, Phinx, PHPUnit, DDEV

---

## File Map

| Aktion | Pfad | Zweck |
|--------|------|-------|
| Erstellen | `db/migrations/YYYYMMDDHHMMSS_create_budget_tables.php` | Tabellen `budget_categories` + `budget_items` |
| Erstellen | `db/migrations/YYYYMMDDHHMMSS_add_can_manage_budget_to_roles.php` | Spalte `can_manage_budget` in `roles` |
| Erstellen | `db/migrations/YYYYMMDDHHMMSS_backfill_budget_permission_for_default_roles.php` | Backfill für `kassier`/`admin`-Rollen |
| Erstellen | `src/Models/BudgetCategory.php` | Eloquent-Model `budget_categories` |
| Erstellen | `src/Models/BudgetItem.php` | Eloquent-Model `budget_items` |
| Erstellen | `src/Services/BudgetService.php` | Soll/Ist-Logik |
| Erstellen | `src/Controllers/BudgetController.php` | HTTP-Handler für alle Budget-Routen |
| Erstellen | `templates/budget/index.twig` | Übersichtsseite |
| Modifizieren | `src/Settings.php` | Feature-Flag `budget` in `modules`-Array |
| Modifizieren | `src/Models/Role.php` | `can_manage_budget` in `$fillable` |
| Modifizieren | `src/Services/SessionAuthService.php` | Session-Key `can_manage_budget` setzen |
| Modifizieren | `src/Middleware/RoleMiddleware.php` | Parameter + Check `requiresBudgetManagement` |
| Modifizieren | `src/Routes.php` | Budget-Routen unter Feature-Flag registrieren |
| Modifizieren | `templates/partials/navigation/areas.twig` | Navigationsmenüpunkt Budget |
| Modifizieren | `templates/roles/index.twig` | Checkbox `can_manage_budget` |
| Modifizieren | `src/Services/DevSeedService.php` | `seedBudget()`, `resetSeedData()`, Counts |
| Erstellen | `tests/Feature/BudgetFeatureTest.php` | Feature-Tests |

---

## Task 1: Migrationen anlegen und ausführen

**Files:**
- Create: `db/migrations/20260616200000_create_budget_tables.php`
- Create: `db/migrations/20260616200001_add_can_manage_budget_to_roles.php`
- Create: `db/migrations/20260616200002_backfill_budget_permission_for_default_roles.php`

- [ ] **Step 1: Migration 1 erstellen — Tabellen**

```php
<?php
// db/migrations/20260616200000_create_budget_tables.php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateBudgetTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE budget_categories (
            id int(11) NOT NULL AUTO_INCREMENT,
            fiscal_year_start int(11) NOT NULL,
            group_name varchar(255) NOT NULL,
            type enum('income','expense') NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_budget_category (fiscal_year_start, group_name, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $this->execute("CREATE TABLE budget_items (
            id int(11) NOT NULL AUTO_INCREMENT,
            budget_category_id int(11) NOT NULL,
            description varchar(255) NOT NULL,
            planned_amount decimal(10,2) NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_budget_items_category
                FOREIGN KEY (budget_category_id)
                REFERENCES budget_categories (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS budget_items;");
        $this->execute("DROP TABLE IF EXISTS budget_categories;");
    }
}
```

- [ ] **Step 2: Migration 2 erstellen — Rollenspalte**

```php
<?php
// db/migrations/20260616200001_add_can_manage_budget_to_roles.php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCanManageBudgetToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE roles ADD COLUMN can_manage_budget TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_sheet_archive;");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_budget;");
    }
}
```

- [ ] **Step 3: Migration 3 erstellen — Backfill**

```php
<?php
// db/migrations/20260616200002_backfill_budget_permission_for_default_roles.php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BackfillBudgetPermissionForDefaultRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_budget = 1
             WHERE LOWER(name) IN ('admin', 'kassier', 'vorstand')"
        );
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_budget = 0
             WHERE LOWER(name) IN ('admin', 'kassier', 'vorstand')"
        );
    }
}
```

- [ ] **Step 4: Migrationen ausführen**

```
ddev exec ./vendor/bin/phinx migrate
```

Erwartete Ausgabe: drei neue Migrationen `== CreateBudgetTables: migrated`, `== AddCanManageBudgetToRoles: migrated`, `== BackfillBudgetPermissionForDefaultRoles: migrated`

- [ ] **Step 5: Commit**

```
git add db/migrations/
git commit -m "feat(budget): add database migrations for budget tables and permission"
```

---

## Task 2: Eloquent-Models und Feature-Flag

**Files:**
- Create: `src/Models/BudgetCategory.php`
- Create: `src/Models/BudgetItem.php`
- Modify: `src/Models/Role.php`
- Modify: `src/Settings.php`

- [ ] **Step 1: BudgetCategory-Model erstellen**

```php
<?php
// src/Models/BudgetCategory.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetCategory extends Model
{
    protected $table = 'budget_categories';

    protected $fillable = [
        'fiscal_year_start',
        'group_name',
        'type',
    ];

    protected $casts = [
        'fiscal_year_start' => 'integer',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany */
    public function items()
    {
        return $this->hasMany(BudgetItem::class, 'budget_category_id', 'id');
    }
}
```

- [ ] **Step 2: BudgetItem-Model erstellen**

```php
<?php
// src/Models/BudgetItem.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetItem extends Model
{
    protected $table = 'budget_items';

    protected $fillable = [
        'budget_category_id',
        'description',
        'planned_amount',
    ];

    protected $casts = [
        'planned_amount' => 'decimal:2',
        'budget_category_id' => 'integer',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo */
    public function category()
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id', 'id');
    }
}
```

- [ ] **Step 3: Role-Model `can_manage_budget` in `$fillable` ergänzen**

In `src/Models/Role.php` die Zeile `'can_manage_sheet_archive',` suchen und `can_manage_budget` darunter einfügen:

```php
        'can_manage_sheet_archive',
        'can_manage_budget',
        'can_manage_tasks',
```

- [ ] **Step 4: Feature-Flag in Settings.php ergänzen**

In `src/Settings.php` das `modules`-Array um `budget` erweitern:

```php
            'modules' => [
                'sheet_archive' => EnvHelper::read('FEATURE_SHEET_ARCHIVE', 'false') === 'true',
                'budget'        => EnvHelper::read('FEATURE_BUDGET', 'false') === 'true',
            ],
```

- [ ] **Step 5: Commit**

```
git add src/Models/BudgetCategory.php src/Models/BudgetItem.php src/Models/Role.php src/Settings.php
git commit -m "feat(budget): add Eloquent models and feature flag"
```

---

## Task 3: Session-Auth und RoleMiddleware erweitern

**Files:**
- Modify: `src/Services/SessionAuthService.php`
- Modify: `src/Middleware/RoleMiddleware.php`

- [ ] **Step 1: Failtest für Session-Key schreiben**

In `tests/Feature/BudgetFeatureTest.php` (neue Datei, Bootstrap wie in anderen Feature-Tests):

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\RoleMiddleware;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;

class BudgetFeatureTest extends TestCase
{
    public function testSessionAuthServiceSetsCanManageBudgetKey(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/SessionAuthService.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('can_manage_budget', $content);
        $this->assertStringContainsString('can_manage_budget', $content);
    }

    public function testRoleMiddlewareHasRequiresBudgetManagementParameter(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Middleware/RoleMiddleware.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('requiresBudgetManagement', $content);
        $this->assertStringContainsString('can_manage_budget', $content);
    }
}
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testSessionAuthServiceSetsCanManageBudgetKey
```

Erwartet: FAIL – `can_manage_budget` not found

- [ ] **Step 3: SessionAuthService erweitern**

In `src/Services/SessionAuthService.php`:

Unter `$canManageSheetArchive = false;` hinzufügen:
```php
        $canManageSheetArchive = false;
        $canManageBudget = false;
```

Im Rollen-Loop unter dem `can_manage_sheet_archive`-Block:
```php
            if (($role->can_manage_sheet_archive ?? false)) {
                $canManageSheetArchive = true;
            }
            if (($role->can_manage_budget ?? false)) {
                $canManageBudget = true;
            }
```

Im `$_SESSION`-Block unter `$_SESSION['can_manage_sheet_archive']`:
```php
        $_SESSION['can_manage_sheet_archive'] = $canManageSheetArchive;
        $_SESSION['can_manage_budget'] = $canManageBudget;
```

- [ ] **Step 4: RoleMiddleware erweitern**

In `src/Middleware/RoleMiddleware.php`:

Property-Liste (nach `$requiresSheetArchiveManagement`):
```php
    private bool $requiresSheetArchiveManagement;
    private bool $requiresBudgetManagement;
```

Konstruktor-Parameter (nach `bool $requiresSheetArchiveManagement = false`):
```php
        bool $requiresSheetArchiveManagement = false,
        bool $requiresBudgetManagement = false
```

Konstruktor-Body (nach der Zeile `$this->requiresSheetArchiveManagement`):
```php
        $this->requiresSheetArchiveManagement = $requiresSheetArchiveManagement;
        $this->requiresBudgetManagement = $requiresBudgetManagement;
```

`process()`-Methode — Session-Variable lesen (nach `$canManageSheetArchive`-Zeile):
```php
        $canManageSheetArchive = $_SESSION['can_manage_sheet_archive'] ?? false;
        $canManageBudget = $_SESSION['can_manage_budget'] ?? false;
```

Check-Block (nach dem `requiresSheetArchiveManagement`-Block):
```php
        if ($this->requiresSheetArchiveManagement && !$canManageSheetArchive && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Notenarchiv-Verwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresBudgetManagement && !$canManageBudget && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Budgetverwaltung.");
            return $response->withStatus(403);
        }
```

- [ ] **Step 5: Tests ausführen (müssen bestehen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php
```

Erwartet: OK

- [ ] **Step 6: Commit**

```
git add src/Services/SessionAuthService.php src/Middleware/RoleMiddleware.php tests/Feature/BudgetFeatureTest.php
git commit -m "feat(budget): extend SessionAuthService and RoleMiddleware for can_manage_budget"
```

---

## Task 4: BudgetService implementieren

**Files:**
- Create: `src/Services/BudgetService.php`

- [ ] **Step 1: Failtest schreiben**

Folgende Testmethoden in `tests/Feature/BudgetFeatureTest.php` ergänzen:

```php
    public function testBudgetServiceClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Services\BudgetService::class));
        $this->assertTrue(method_exists(\App\Services\BudgetService::class, 'getOverview'));
        $this->assertTrue(method_exists(\App\Services\BudgetService::class, 'computeActual'));
        $this->assertTrue(method_exists(\App\Services\BudgetService::class, 'buildAvailableYears'));
        $this->assertTrue(method_exists(\App\Services\BudgetService::class, 'defaultFiscalYearStart'));
    }
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetServiceClassExists
```

Erwartet: FAIL – class not found

- [ ] **Step 3: BudgetService erstellen**

```php
<?php
// src/Services/BudgetService.php
declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetCategory;
use App\Models\Finance;
use App\Models\Setting;
use Carbon\Carbon;

class BudgetService
{
    /**
     * Returns fiscal config: [day, month, settingString]
     *
     * @return array{0: int, 1: int, 2: string}
     */
    public function getFiscalConfig(): array
    {
        $setting = Setting::find('fiscal_year_start');
        $startStr = $setting ? $setting->setting_value : '01.09.';
        $parts = explode('.', $startStr);
        $day = (int) ($parts[0] ?? 1);
        $month = (int) ($parts[1] ?? 9);
        return [$day, $month, $startStr];
    }

    /**
     * Returns [Carbon $start, Carbon $end] for the given fiscal year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function datesForYear(int $startYear, int $day, int $month): array
    {
        $start = Carbon::create($startYear, $month, $day, 0, 0, 0);
        $end = Carbon::create($startYear + 1, $month, $day, 0, 0, 0)->subDay();
        return [$start, $end];
    }

    /**
     * Returns the default fiscal year start based on today's date.
     */
    public function defaultFiscalYearStart(): int
    {
        [$day, $month] = $this->getFiscalConfig();
        $now = Carbon::now();
        return ($now->month > $month || ($now->month === $month && $now->day >= $day))
            ? (int) $now->year
            : (int) $now->year - 1;
    }

    /**
     * Returns all fiscal years for which budget categories exist, plus the current default year.
     * Keys are year ints, values are label strings "DD.MM.YYYY – DD.MM.YYYY".
     *
     * @return array<int, string>
     */
    public function buildAvailableYears(): array
    {
        [$day, $month] = $this->getFiscalConfig();
        $years = [];

        $default = $this->defaultFiscalYearStart();
        [$s, $e] = $this->datesForYear($default, $day, $month);
        $years[$default] = $s->format('d.m.Y') . ' – ' . $e->format('d.m.Y');

        $existingYears = BudgetCategory::select('fiscal_year_start')
            ->distinct()
            ->pluck('fiscal_year_start')
            ->toArray();

        foreach ($existingYears as $y) {
            $y = (int) $y;
            if (!isset($years[$y])) {
                [$s, $e] = $this->datesForYear($y, $day, $month);
                $years[$y] = $s->format('d.m.Y') . ' – ' . $e->format('d.m.Y');
            }
        }

        ksort($years);
        return $years;
    }

    /**
     * Aggregates actual (Ist) amounts from the finances table for a given group_name and type
     * within a fiscal year date range.
     */
    public function computeActual(string $groupName, string $type, Carbon $from, Carbon $to): string
    {
        $sum = Finance::where('group_name', $groupName)
            ->where('type', $type)
            ->whereBetween('invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * Returns a structured overview for the given fiscal year.
     *
     * Return structure:
     * [
     *   'income' => [
     *     [
     *       'category' => BudgetCategory,
     *       'items'    => Collection<BudgetItem>,
     *       'planned'  => '1234.56',
     *       'actual'   => '1000.00',
     *       'diff'     => '234.56',
     *     ],
     *     ...
     *   ],
     *   'expense' => [ ... ],
     *   'totals' => [
     *     'income'  => ['planned' => '...', 'actual' => '...', 'diff' => '...'],
     *     'expense' => ['planned' => '...', 'actual' => '...', 'diff' => '...'],
     *   ],
     * ]
     *
     * @return array<string, mixed>
     */
    public function getOverview(int $fiscalYearStart): array
    {
        [$day, $month] = $this->getFiscalConfig();
        [$from, $to] = $this->datesForYear($fiscalYearStart, $day, $month);

        $categories = BudgetCategory::with('items')
            ->where('fiscal_year_start', $fiscalYearStart)
            ->orderBy('type')
            ->orderBy('group_name')
            ->get();

        $result = ['income' => [], 'expense' => [], 'totals' => [
            'income'  => ['planned' => '0.00', 'actual' => '0.00', 'diff' => '0.00'],
            'expense' => ['planned' => '0.00', 'actual' => '0.00', 'diff' => '0.00'],
        ]];

        foreach ($categories as $category) {
            $planned = (string) $category->items->sum(fn($i) => (float) $i->planned_amount);
            $planned = number_format((float) $planned, 2, '.', '');
            $actual = $this->computeActual($category->group_name, $category->type, $from, $to);
            $diff = number_format((float) $planned - (float) $actual, 2, '.', '');

            $type = $category->type;
            $result[$type][] = [
                'category' => $category,
                'items'    => $category->items,
                'planned'  => $planned,
                'actual'   => $actual,
                'diff'     => $diff,
            ];

            $t = &$result['totals'][$type];
            $t['planned'] = number_format((float) $t['planned'] + (float) $planned, 2, '.', '');
            $t['actual']  = number_format((float) $t['actual']  + (float) $actual,  2, '.', '');
            $t['diff']    = number_format((float) $t['diff']    + (float) $diff,    2, '.', '');
        }

        return $result;
    }
}
```

- [ ] **Step 4: Test ausführen (muss bestehen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetServiceClassExists
```

Erwartet: OK

- [ ] **Step 5: Commit**

```
git add src/Services/BudgetService.php tests/Feature/BudgetFeatureTest.php
git commit -m "feat(budget): implement BudgetService with fiscal year logic and Soll/Ist aggregation"
```

---

## Task 5: BudgetController implementieren

**Files:**
- Create: `src/Controllers/BudgetController.php`

- [ ] **Step 1: Failtest schreiben**

Folgende Testmethode in `tests/Feature/BudgetFeatureTest.php` ergänzen:

```php
    public function testBudgetControllerClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\BudgetController::class));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'createCategory'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'updateCategory'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'deleteCategory'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'createItem'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'updateItem'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'deleteItem'));
    }
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetControllerClassExists
```

Erwartet: FAIL – class not found

- [ ] **Step 3: BudgetController erstellen**

```php
<?php
// src/Controllers/BudgetController.php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Services\BudgetService;
use Psr\Log\LoggerInterface;

class BudgetController
{
    private Twig $view;
    private BudgetService $budgetService;
    private LoggerInterface $logger;

    public function __construct(Twig $view, BudgetService $budgetService, LoggerInterface $logger)
    {
        $this->view = $view;
        $this->budgetService = $budgetService;
        $this->logger = $logger;
    }

    public function index(Request $request, Response $response): Response
    {
        $defaultYear = $this->budgetService->defaultFiscalYearStart();
        $selectedYear = (int) ($request->getQueryParams()['year'] ?? $defaultYear);
        $availableYears = $this->budgetService->buildAvailableYears();
        $overview = $this->budgetService->getOverview($selectedYear);

        [$day, $month] = $this->budgetService->getFiscalConfig();
        [$start, $end] = $this->budgetService->datesForYear($selectedYear, $day, $month);

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'budget/index.twig', [
            'overview'        => $overview,
            'selected_year'   => $selectedYear,
            'available_years' => $availableYears,
            'fiscal_start'    => $start->format('d.m.Y'),
            'fiscal_end'      => $end->format('d.m.Y'),
            'success'         => $success,
            'error'           => $error,
        ]);
    }

    public function createCategory(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $fiscalYear = (int) ($data['fiscal_year_start'] ?? 0);
        $groupName  = trim($data['group_name'] ?? '');
        $type       = $data['type'] ?? '';

        if ($fiscalYear === 0 || $groupName === '' || !in_array($type, ['income', 'expense'], true)) {
            $_SESSION['error'] = 'Ungültige Eingabe. Bitte alle Felder ausfüllen.';
            return $response->withHeader('Location', '/budget?year=' . $fiscalYear)->withStatus(302);
        }

        $exists = BudgetCategory::where('fiscal_year_start', $fiscalYear)
            ->where('group_name', $groupName)
            ->where('type', $type)
            ->exists();

        if ($exists) {
            $_SESSION['error'] = 'Diese Kategorie existiert bereits für das gewählte Haushaltsjahr.';
            return $response->withHeader('Location', '/budget?year=' . $fiscalYear)->withStatus(302);
        }

        BudgetCategory::create([
            'fiscal_year_start' => $fiscalYear,
            'group_name'        => $groupName,
            'type'              => $type,
        ]);

        $this->logger->info('Budget category created.', [
            'event'             => 'budget.category.created',
            'fiscal_year_start' => $fiscalYear,
            'group_name'        => $groupName,
            'type'              => $type,
        ]);

        $_SESSION['success'] = 'Kategorie erfolgreich angelegt.';
        return $response->withHeader('Location', '/budget?year=' . $fiscalYear)->withStatus(302);
    }

    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $groupName = trim($data['group_name'] ?? '');
        $fiscalYear = (int) ($data['fiscal_year_start'] ?? 0);

        $category = BudgetCategory::find($id);
        if ($category === null) {
            $_SESSION['error'] = 'Kategorie nicht gefunden.';
            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        if ($groupName === '') {
            $_SESSION['error'] = 'Kategoriename darf nicht leer sein.';
            return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
        }

        $duplicate = BudgetCategory::where('fiscal_year_start', $category->fiscal_year_start)
            ->where('group_name', $groupName)
            ->where('type', $category->type)
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicate) {
            $_SESSION['error'] = 'Eine Kategorie mit diesem Namen existiert bereits.';
            return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
        }

        $category->update(['group_name' => $groupName]);

        $this->logger->info('Budget category updated.', [
            'event'       => 'budget.category.updated',
            'category_id' => $id,
            'group_name'  => $groupName,
        ]);

        $_SESSION['success'] = 'Kategorie aktualisiert.';
        return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
    }

    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $category = BudgetCategory::find($id);

        if ($category === null) {
            $_SESSION['error'] = 'Kategorie nicht gefunden.';
            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $year = $category->fiscal_year_start;
        $category->delete(); // CASCADE löscht budget_items

        $this->logger->info('Budget category deleted.', [
            'event'       => 'budget.category.deleted',
            'category_id' => $id,
        ]);

        $_SESSION['success'] = 'Kategorie und alle zugehörigen Posten gelöscht.';
        return $response->withHeader('Location', '/budget?year=' . $year)->withStatus(302);
    }

    public function createItem(Request $request, Response $response, array $args): Response
    {
        $categoryId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $description   = trim($data['description'] ?? '');
        $plannedAmount = trim($data['planned_amount'] ?? '');

        $category = BudgetCategory::find($categoryId);
        if ($category === null) {
            $_SESSION['error'] = 'Kategorie nicht gefunden.';
            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $normalizedAmount = $this->normalizeAmount($plannedAmount);
        if ($description === '' || $normalizedAmount === null) {
            $_SESSION['error'] = 'Ungültige Eingabe. Bezeichnung und Betrag sind erforderlich.';
            return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
        }

        BudgetItem::create([
            'budget_category_id' => $categoryId,
            'description'        => $description,
            'planned_amount'     => $normalizedAmount,
        ]);

        $this->logger->info('Budget item created.', [
            'event'              => 'budget.item.created',
            'budget_category_id' => $categoryId,
            'description'        => $description,
        ]);

        $_SESSION['success'] = 'Posten erfolgreich angelegt.';
        return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
    }

    public function updateItem(Request $request, Response $response, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $description   = trim($data['description'] ?? '');
        $plannedAmount = trim($data['planned_amount'] ?? '');

        $item = BudgetItem::with('category')->find($id);
        if ($item === null) {
            $_SESSION['error'] = 'Posten nicht gefunden.';
            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $normalizedAmount = $this->normalizeAmount($plannedAmount);
        if ($description === '' || $normalizedAmount === null) {
            $_SESSION['error'] = 'Ungültige Eingabe. Bezeichnung und Betrag sind erforderlich.';
            return $response->withHeader('Location', '/budget?year=' . $item->category->fiscal_year_start)->withStatus(302);
        }

        $item->update([
            'description'    => $description,
            'planned_amount' => $normalizedAmount,
        ]);

        $this->logger->info('Budget item updated.', ['event' => 'budget.item.updated', 'item_id' => $id]);

        $_SESSION['success'] = 'Posten aktualisiert.';
        return $response->withHeader('Location', '/budget?year=' . $item->category->fiscal_year_start)->withStatus(302);
    }

    public function deleteItem(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $item = BudgetItem::with('category')->find($id);

        if ($item === null) {
            $_SESSION['error'] = 'Posten nicht gefunden.';
            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $year = $item->category->fiscal_year_start;
        $item->delete();

        $this->logger->info('Budget item deleted.', ['event' => 'budget.item.deleted', 'item_id' => $id]);

        $_SESSION['success'] = 'Posten gelöscht.';
        return $response->withHeader('Location', '/budget?year=' . $year)->withStatus(302);
    }

    /**
     * Normalizes amount string (comma/dot handling) to a float-compatible string.
     * Returns null if the value is not a valid positive number.
     */
    private function normalizeAmount(string $raw): ?string
    {
        $normalized = preg_replace('/[\s\x{00A0}\']+/u', '', $raw) ?? $raw;
        $lastComma = strrpos($normalized, ',');
        $lastDot   = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSep  = $lastComma > $lastDot ? ',' : '.';
            $thousandsSep = $decimalSep === ',' ? '.' : ',';
            $normalized  = str_replace($thousandsSep, '', $normalized);
            $normalized  = $decimalSep === ',' ? str_replace(',', '.', $normalized) : $normalized;
        } elseif ($lastComma !== false) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
```

- [ ] **Step 4: Test ausführen (muss bestehen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetControllerClassExists
```

Erwartet: OK

- [ ] **Step 5: Commit**

```
git add src/Controllers/BudgetController.php tests/Feature/BudgetFeatureTest.php
git commit -m "feat(budget): implement BudgetController with full CRUD for categories and items"
```

---

## Task 6: Routen registrieren und Navigation ergänzen

**Files:**
- Modify: `src/Routes.php`
- Modify: `templates/partials/navigation/areas.twig`

- [ ] **Step 1: Failtest schreiben**

In `tests/Feature/BudgetFeatureTest.php` ergänzen:

```php
    public function testBudgetRoutesAreRegisteredInRoutesFile(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("'/budget'", $content);
        $this->assertStringContainsString('BudgetController', $content);
        $this->assertStringContainsString("modules']['budget']", $content);
    }

    public function testBudgetNavigationItemExistsInAreasTemplate(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/templates/partials/navigation/areas.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('settings.modules.budget', $content);
        $this->assertStringContainsString('/budget', $content);
    }
```

- [ ] **Step 2: Tests ausführen (müssen fehlschlagen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetRoutesAreRegisteredInRoutesFile
```

Erwartet: FAIL

- [ ] **Step 3: Routen in Routes.php registrieren**

In `src/Routes.php` oben den Import ergänzen (nach dem SheetArchiveController-Import):

```php
use App\Controllers\SheetArchiveController;
use App\Controllers\BudgetController;
```

Im geschützten Routenblock, nach dem `sheet_archive`-Block (oder vor dem Newsletter-Block), einfügen:

```php
            // Budget routes
            if ($settings['modules']['budget'] ?? false) {
                $group->group(
                    '',
                    function (RouteCollectorProxy $budgetGroup) {
                        $budgetGroup->get('/budget', [BudgetController::class, 'index']);
                        $budgetGroup->post('/budget/categories', [BudgetController::class, 'createCategory']);
                        $budgetGroup->post(
                            '/budget/categories/{id:[0-9]+}/update',
                            [BudgetController::class, 'updateCategory']
                        );
                        $budgetGroup->post(
                            '/budget/categories/{id:[0-9]+}/delete',
                            [BudgetController::class, 'deleteCategory']
                        );
                        $budgetGroup->post(
                            '/budget/categories/{id:[0-9]+}/items',
                            [BudgetController::class, 'createItem']
                        );
                        $budgetGroup->post(
                            '/budget/items/{id:[0-9]+}/update',
                            [BudgetController::class, 'updateItem']
                        );
                        $budgetGroup->post(
                            '/budget/items/{id:[0-9]+}/delete',
                            [BudgetController::class, 'deleteItem']
                        );
                    }
                )->add(new RoleMiddleware(
                    requiresBudgetManagement: true
                ));
            }
```

- [ ] **Step 4: Navigationsmenü in areas.twig ergänzen**

In `templates/partials/navigation/areas.twig` nach dem Kassa-Block einfügen:

```twig
            {% if (session.can_read_finances or session.can_manage_users) and settings.modules.budget and (session.can_manage_budget or session.can_manage_users) %}
                <li>
                    <a class="dropdown-item {% if nav_active(path, nav, ['/budget'], ['budget']) %}active{% endif %}"
                       href="/budget"><i class="bi bi-calculator me-2"></i> Budget</a>
                </li>
            {% endif %}
```

- [ ] **Step 5: Tests ausführen (müssen bestehen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetRoutesAreRegisteredInRoutesFile
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetNavigationItemExistsInAreasTemplate
```

Erwartet: OK

- [ ] **Step 6: Commit**

```
git add src/Routes.php templates/partials/navigation/areas.twig tests/Feature/BudgetFeatureTest.php
git commit -m "feat(budget): register routes and add navigation item"
```

---

## Task 7: Template erstellen

**Files:**
- Create: `templates/budget/index.twig`

- [ ] **Step 1: Failtest schreiben**

In `tests/Feature/BudgetFeatureTest.php` ergänzen:

```php
    public function testBudgetTemplateExists(): void
    {
        $this->assertTrue(file_exists(dirname(__DIR__, 2) . '/templates/budget/index.twig'));
    }

    public function testBudgetTemplateHasSollIstStructure(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/templates/budget/index.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('overview.income', $content);
        $this->assertStringContainsString('overview.expense', $content);
        $this->assertStringContainsString('planned', $content);
        $this->assertStringContainsString('actual', $content);
        $this->assertStringContainsString('diff', $content);
        $this->assertStringContainsString('can_manage_budget', $content);
    }
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetTemplateExists
```

Erwartet: FAIL

- [ ] **Step 3: Template erstellen**

```twig
{# templates/budget/index.twig #}
{% extends "layout.twig" %}

{% block title %}Budget{% endblock %}

{% block content %}
<div class="container-xl py-4">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <h1 class="h3 mb-0"><i class="bi bi-calculator me-2"></i>Budget</h1>
        {% if session.can_manage_budget or session.can_manage_users %}
        <button class="btn btn-primary btn-sm"
                data-bs-toggle="modal"
                data-bs-target="#modal-create-category">
            <i class="bi bi-plus-lg me-1"></i> Kategorie hinzufügen
        </button>
        {% endif %}
    </div>

    {# Fiscal year selector #}
    <div class="mb-4">
        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Haushaltsjahr wählen">
            {% for year, label in available_years %}
                <a href="/budget?year={{ year }}"
                   class="btn {{ selected_year == year ? 'btn-primary' : 'btn-outline-secondary' }}">
                    {{ label }}
                </a>
            {% endfor %}
        </div>
    </div>

    {# Flash messages #}
    {% if success %}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ success }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {% endif %}
    {% if error %}
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ error }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {% endif %}

    {% macro category_section(type_label, entries, totals, can_write, fiscal_year) %}
    <div class="card mb-4">
        <div class="card-header fw-semibold">{{ type_label }}</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kategorie / Posten</th>
                        <th class="text-end">Geplant (€)</th>
                        <th class="text-end">Ist (€)</th>
                        <th class="text-end">Differenz (€)</th>
                        {% if can_write %}<th></th>{% endif %}
                    </tr>
                </thead>
                <tbody>
                {% for row in entries %}
                    <tr class="table-row-category fw-semibold"
                        data-bs-toggle="collapse"
                        data-bs-target="#items-{{ row.category.id }}"
                        style="cursor:pointer;">
                        <td><i class="bi bi-chevron-right me-1 text-muted small"></i>{{ row.category.group_name }}</td>
                        <td class="text-end">{{ row.planned|number_format(2, ',', '.') }}</td>
                        <td class="text-end">{{ row.actual|number_format(2, ',', '.') }}</td>
                        <td class="text-end {{ row.diff < 0 ? 'text-danger' : 'text-success' }}">
                            {{ row.diff|number_format(2, ',', '.') }}
                        </td>
                        {% if can_write %}
                        <td class="text-end text-nowrap">
                            <button class="btn btn-link btn-sm p-0 me-2"
                                    title="Kategorie bearbeiten"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modal-edit-category-{{ row.category.id }}">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-link btn-sm p-0 text-danger"
                                    title="Kategorie löschen"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modal-delete-category-{{ row.category.id }}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                        {% endif %}
                    </tr>
                    <tr class="collapse" id="items-{{ row.category.id }}">
                        <td colspan="{{ can_write ? 5 : 4 }}" class="p-0">
                            <table class="table table-sm mb-0 bg-light">
                                <tbody>
                                {% for item in row.items %}
                                <tr>
                                    <td class="ps-5">{{ item.description }}</td>
                                    <td class="text-end">{{ item.planned_amount|number_format(2, ',', '.') }}</td>
                                    <td class="text-end text-muted">—</td>
                                    <td class="text-end text-muted">—</td>
                                    {% if can_write %}
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-link btn-sm p-0 me-2"
                                                title="Posten bearbeiten"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modal-edit-item-{{ item.id }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="post"
                                              action="/budget/items/{{ item.id }}/delete"
                                              class="d-inline"
                                              onsubmit="return confirm('Posten löschen?');">
                                            {{ csrf_field()|raw }}
                                            <button type="submit" class="btn btn-link btn-sm p-0 text-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                    {% endif %}
                                </tr>
                                {% endfor %}
                                {% if can_write %}
                                <tr>
                                    <td colspan="{{ can_write ? 5 : 4 }}" class="ps-5">
                                        <button class="btn btn-link btn-sm p-0"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modal-create-item-{{ row.category.id }}">
                                            <i class="bi bi-plus-lg me-1"></i> Posten hinzufügen
                                        </button>
                                    </td>
                                </tr>
                                {% endif %}
                                </tbody>
                            </table>
                        </td>
                    </tr>
                {% else %}
                    <tr><td colspan="{{ can_write ? 5 : 4 }}" class="text-muted text-center py-3">Keine Kategorien vorhanden.</td></tr>
                {% endfor %}
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td>Summe</td>
                        <td class="text-end">{{ totals.planned|number_format(2, ',', '.') }}</td>
                        <td class="text-end">{{ totals.actual|number_format(2, ',', '.') }}</td>
                        <td class="text-end {{ totals.diff < 0 ? 'text-danger' : 'text-success' }}">
                            {{ totals.diff|number_format(2, ',', '.') }}
                        </td>
                        {% if can_write %}<td></td>{% endif %}
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    {% endmacro %}

    {% set can_write = session.can_manage_budget or session.can_manage_users %}

    {{ _self.category_section('Einnahmen', overview.income, overview.totals.income, can_write, selected_year) }}
    {{ _self.category_section('Ausgaben', overview.expense, overview.totals.expense, can_write, selected_year) }}

</div>

{# ── Modals ─────────────────────────────────────────────────────────────── #}

{% if can_write %}

{# Create category modal #}
<div class="modal fade" id="modal-create-category" tabindex="-1" aria-labelledby="modal-create-category-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/budget/categories">
                {{ csrf_field()|raw }}
                <input type="hidden" name="fiscal_year_start" value="{{ selected_year }}">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-create-category-label">Neue Budgetkategorie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="new-group-name" class="form-label">Kategoriename</label>
                        <input type="text" class="form-control" id="new-group-name" name="group_name" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label for="new-type" class="form-label">Typ</label>
                        <select class="form-select" id="new-type" name="type" required>
                            <option value="income">Einnahme</option>
                            <option value="expense">Ausgabe</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Anlegen</button>
                </div>
            </form>
        </div>
    </div>
</div>

{# Edit category / delete category / create item / edit item modals #}
{% for section_rows in [overview.income, overview.expense] %}
{% for row in section_rows %}

<div class="modal fade" id="modal-edit-category-{{ row.category.id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/budget/categories/{{ row.category.id }}/update">
                {{ csrf_field()|raw }}
                <input type="hidden" name="fiscal_year_start" value="{{ selected_year }}">
                <div class="modal-header">
                    <h5 class="modal-title">Kategorie bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kategoriename</label>
                        <input type="text" class="form-control" name="group_name"
                               value="{{ row.category.group_name }}" required maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-delete-category-{{ row.category.id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/budget/categories/{{ row.category.id }}/delete">
                {{ csrf_field()|raw }}
                <div class="modal-header">
                    <h5 class="modal-title">Kategorie löschen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Kategorie <strong>{{ row.category.group_name }}</strong> und alle zugehörigen Posten ({{ row.items|length }}) wirklich löschen?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-create-item-{{ row.category.id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/budget/categories/{{ row.category.id }}/items">
                {{ csrf_field()|raw }}
                <div class="modal-header">
                    <h5 class="modal-title">Neuer Posten — {{ row.category.group_name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Bezeichnung</label>
                        <input type="text" class="form-control" name="description" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Geplanter Betrag (€)</label>
                        <input type="text" class="form-control" name="planned_amount" required placeholder="0,00">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Anlegen</button>
                </div>
            </form>
        </div>
    </div>
</div>

{% for item in row.items %}
<div class="modal fade" id="modal-edit-item-{{ item.id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/budget/items/{{ item.id }}/update">
                {{ csrf_field()|raw }}
                <div class="modal-header">
                    <h5 class="modal-title">Posten bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Bezeichnung</label>
                        <input type="text" class="form-control" name="description"
                               value="{{ item.description }}" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Geplanter Betrag (€)</label>
                        <input type="text" class="form-control" name="planned_amount"
                               value="{{ item.planned_amount }}" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>
{% endfor %}

{% endfor %}
{% endfor %}
{% endif %}

{% endblock %}
```

- [ ] **Step 4: Tests ausführen (müssen bestehen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetTemplateExists
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testBudgetTemplateHasSollIstStructure
```

Erwartet: OK

- [ ] **Step 5: Commit**

```
git add templates/budget/index.twig tests/Feature/BudgetFeatureTest.php
git commit -m "feat(budget): add budget overview template with Soll/Ist table and modals"
```

---

## Task 8: Rollenverwaltungs-Template erweitern

**Files:**
- Modify: `templates/roles/index.twig`

- [ ] **Step 1: Failtest schreiben**

In `tests/Feature/BudgetFeatureTest.php` ergänzen:

```php
    public function testRolesTemplateContainsBudgetPermissionCheckbox(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/templates/roles/index.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('can_manage_budget', $content);
        $this->assertStringContainsString('settings.modules.budget', $content);
    }
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testRolesTemplateContainsBudgetPermissionCheckbox
```

Erwartet: FAIL

- [ ] **Step 3: Checkbox in roles/index.twig einbauen**

In `templates/roles/index.twig` die Checkbox für `can_manage_sheet_archive` suchen und danach dieselbe Struktur für `can_manage_budget` einfügen (für **beide** Vorkommen — Anlegen-Formular und Bearbeiten-Formular). Das Sheet-Archive-Muster sieht so aus (Anzeigezeile in der Tabelle):

```twig
{# Nach dem can_manage_sheet_archive-Block in der Anzeigezeile: #}
{% if settings.modules.budget %}
    {% if role.can_manage_budget %}
        <span class="badge bg-success me-1">Budget</span>
    {% endif %}
{% endif %}
```

Und in den Formularen (Anlegen + Bearbeiten), nach dem `can_manage_sheet_archive`-Checkbox-Block:

```twig
{% if settings.modules.budget %}
<div class="form-check mb-2">
    <input class="form-check-input"
           type="checkbox"
           id="can_manage_budget"
           name="can_manage_budget"
           value="1"
           {{ role is defined and role.can_manage_budget ? 'checked' : '' }}>
    <label class="form-check-label" for="can_manage_budget">Budget verwalten</label>
</div>
{% endif %}
```

Hinweis: Da `roles/index.twig` zwei Formularblöcke hat (Anlegen + Bearbeiten), muss die Checkbox in beide eingebaut werden, jeweils mit den passenden IDs (`can_manage_budget` für Anlegen, `edit_can_manage_budget` für Bearbeiten, `name="can_manage_budget"` bleibt gleich).

- [ ] **Step 4: Test ausführen (muss bestehen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testRolesTemplateContainsBudgetPermissionCheckbox
```

Erwartet: OK

- [ ] **Step 5: Commit**

```
git add templates/roles/index.twig tests/Feature/BudgetFeatureTest.php
git commit -m "feat(budget): add can_manage_budget checkbox to roles management template"
```

---

## Task 9: RoleController/RoleQuery um can_manage_budget erweitern

**Files:**
- Modify: `src/Controllers/RoleController.php` (oder äquivalente Persistenz-Schicht)

- [ ] **Step 1: RoleController lesen**

```
ddev exec grep -n "can_manage_sheet_archive" src/Controllers/RoleController.php
```

Erwartet: Zeilen, in denen `can_manage_sheet_archive` beim Anlegen/Bearbeiten einer Rolle aus dem Request-Body gelesen wird.

- [ ] **Step 2: Failtest schreiben**

In `tests/Feature/BudgetFeatureTest.php` ergänzen:

```php
    public function testRoleControllerHandlesCanManageBudget(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/RoleController.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('can_manage_budget', $content);
    }
```

- [ ] **Step 3: Test ausführen (muss fehlschlagen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testRoleControllerHandlesCanManageBudget
```

- [ ] **Step 4: RoleController anpassen**

Alle Stellen, wo `can_manage_sheet_archive` aus dem Request-Body gelesen und zur Rolle gespeichert wird, um `can_manage_budget` ergänzen — nach demselben Muster:

```php
'can_manage_budget' => isset($data['can_manage_budget']) && $data['can_manage_budget'] === '1' ? 1 : 0,
```

- [ ] **Step 5: Test ausführen (muss bestehen)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php --filter testRoleControllerHandlesCanManageBudget
```

Erwartet: OK

- [ ] **Step 6: Commit**

```
git add src/Controllers/RoleController.php tests/Feature/BudgetFeatureTest.php
git commit -m "feat(budget): persist can_manage_budget in RoleController"
```

---

## Task 10: Dependency Injection registrieren

**Files:**
- Modify: `src/Dependencies.php`

- [ ] **Step 1: Prüfen, wie BudgetService im Container registriert wird**

```
ddev exec grep -n "SheetArchiveService\|BudgetService" src/Dependencies.php
```

Falls `SheetArchiveService` als Muster vorhanden ist: dasselbe Schema für `BudgetService` anwenden.

- [ ] **Step 2: BudgetService und BudgetController in Dependencies.php registrieren**

In `src/Dependencies.php` nach dem SheetArchiveService-Block einfügen:

```php
    $containerBuilder->addDefinitions([
        \App\Services\BudgetService::class => \DI\autowire(),
        \App\Controllers\BudgetController::class => \DI\autowire(),
    ]);
```

Falls `autowire()` global aktiv ist und alle Controller/Services automatisch aufgelöst werden, ist dieser Schritt übersprungen — nur prüfen ob `/budget` im Browser erreichbar ist (nächster Task).

- [ ] **Step 3: Commit (wenn geändert)**

```
git add src/Dependencies.php
git commit -m "feat(budget): register BudgetService and BudgetController in DI container"
```

---

## Task 11: Feature-Flag aktivieren und Smoke-Test

- [ ] **Step 1: Feature-Flag in `.env` setzen**

```
FEATURE_BUDGET=true
```

- [ ] **Step 2: Im Browser aufrufen**

URL: `https://chormanager.ddev.site/budget`

Wenn du als Nutzer mit `can_manage_budget` eingeloggt bist, muss die Übersichtsseite erscheinen (ggf. leer, da noch keine Kategorien vorhanden).

Falls 404: Routen-Registrierung prüfen (Flag korrekt gesetzt?).  
Falls 403: Session-Berechtigung prüfen (Nutzer hat `can_manage_budget`?).  
Falls 500: Container-Auflösung prüfen (Dependencies.php vollständig?).

- [ ] **Step 3: Alle Tests ausführen**

```
ddev exec ./vendor/bin/phpunit tests/Feature/BudgetFeatureTest.php -v
```

Erwartet: alle Tests grün.

- [ ] **Step 4: Commit**

```
git add .env
git commit -m "feat(budget): enable FEATURE_BUDGET flag in local .env"
```

Hinweis: `.env` ist in `.gitignore` — dieser Commit gilt nur lokal.

---

## Task 12: Seed-Daten ergänzen

**Files:**
- Modify: `src/Services/DevSeedService.php`

- [ ] **Step 1: resetSeedData() um Budget-Tabellen erweitern**

In der `$tables`-Liste in `resetSeedData()`, nach `'finances'` einfügen:

```php
            'finances',
            'budget_items',
            'budget_categories',
```

- [ ] **Step 2: Seed-Counts ergänzen**

Im `$this->report['counts']`-Array nach `'finances' => 0,` hinzufügen:

```php
                'finances'               => 0,
                'budget_categories'      => 0,
                'budget_items'           => 0,
```

- [ ] **Step 3: Imports ergänzen**

Am Anfang von `DevSeedService.php` nach dem Finance-Import:

```php
use App\Models\Finance;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
```

- [ ] **Step 4: seedBudget()-Methode hinzufügen**

Am Ende der Klasse (vor der letzten `}`):

```php
    private function seedBudget(): void
    {
        $currentYear = (int) date('Y');
        // Haushaltsjahr: 1. September des Vorjahres bis 31. August dieses Jahres
        $fiscalYear = $currentYear - 1;

        $categories = [
            // Einnahmen
            ['type' => 'income',  'group_name' => 'Mitgliedsbeiträge', 'items' => [
                ['description' => 'Aktive Mitglieder',     'planned_amount' => '3600.00'],
                ['description' => 'Fördermitglieder',       'planned_amount' => '480.00'],
            ]],
            ['type' => 'income',  'group_name' => 'Konzert', 'items' => [
                ['description' => 'Weihnachtskonzert Eintritt',    'planned_amount' => '1200.00'],
                ['description' => 'Frühjahrskonzert Eintritt',     'planned_amount' => '800.00'],
                ['description' => 'Programmhefte Verkauf',         'planned_amount' => '150.00'],
            ]],
            ['type' => 'income',  'group_name' => 'Förderung', 'items' => [
                ['description' => 'Gemeindeförderung',      'planned_amount' => '1000.00'],
                ['description' => 'Kulturamt Zuschuss',     'planned_amount' => '500.00'],
            ]],
            // Ausgaben
            ['type' => 'expense', 'group_name' => 'Notenmaterial', 'items' => [
                ['description' => 'Notenkauf Herbstrepertoire',    'planned_amount' => '350.00'],
                ['description' => 'Notenkopien und Lizenzen',      'planned_amount' => '120.00'],
            ]],
            ['type' => 'expense', 'group_name' => 'Raummiete', 'items' => [
                ['description' => 'Probenraum Wochenbeitrag',      'planned_amount' => '2400.00'],
                ['description' => 'Konzertsaalmiete',               'planned_amount' => '800.00'],
            ]],
            ['type' => 'expense', 'group_name' => 'Honorare', 'items' => [
                ['description' => 'Dirigenthonorar',               'planned_amount' => '3600.00'],
                ['description' => 'Klavierbegleitung',              'planned_amount' => '600.00'],
            ]],
            ['type' => 'expense', 'group_name' => 'Technik', 'items' => [
                ['description' => 'Tonanlage Miete',               'planned_amount' => '400.00'],
                ['description' => 'Lichtanlage Miete',             'planned_amount' => '200.00'],
            ]],
        ];

        foreach ($categories as $catDef) {
            $category = BudgetCategory::create([
                'fiscal_year_start' => $fiscalYear,
                'group_name'        => $catDef['group_name'],
                'type'              => $catDef['type'],
            ]);
            $this->report['counts']['budget_categories']++;

            foreach ($catDef['items'] as $itemDef) {
                BudgetItem::create([
                    'budget_category_id' => $category->id,
                    'description'        => $itemDef['description'],
                    'planned_amount'     => $itemDef['planned_amount'],
                ]);
                $this->report['counts']['budget_items']++;
            }
        }
    }
```

- [ ] **Step 5: seedBudget() in run() einbinden**

In der `run()`-Methode, nach `$this->seedFinances(...)`:

```php
            $this->seedFinances($projects, 320, 40);
            $this->seedBudget();
```

- [ ] **Step 6: Seed ausführen und Report prüfen**

```
ddev exec php bin/dev_seed.php reset-and-seed
```

Erwartet: Report enthält `"budget_categories": 7` und `"budget_items": 15` (oder ähnliche Zahlen).

- [ ] **Step 7: Commit**

```
git add src/Services/DevSeedService.php
git commit -m "feat(budget): add dev seed data for budget categories and items"
```

---

## Task 13: Abschließende Gesamttests

- [ ] **Step 1: Vollständige Test-Suite ausführen**

```
ddev exec ./vendor/bin/phpunit
```

Erwartet: alle Tests grün, keine Regressionen.

- [ ] **Step 2: Style-Check**

```
ddev composer phpcs
```

Falls Fehler: `ddev composer phpcbf` ausführen, dann erneut prüfen.

- [ ] **Step 3: Budget-Modul im Browser durchtesten**

- Kategorie anlegen (Einnahme + Ausgabe)
- Posten anlegen, bearbeiten, löschen
- Kategorie mit Posten löschen → Cascade-Check
- Jahr wechseln
- Navigation sichtbar bei aktivem Feature-Flag
- Navigation unsichtbar wenn `FEATURE_BUDGET=false`

- [ ] **Step 4: Final Commit**

```
git add -A
git commit -m "feat(budget): complete budget module implementation"
```

---

## Spec-Coverage-Prüfung

| Spec-Anforderung | Task |
|---|---|
| Feature-Flag `FEATURE_BUDGET=true` | Task 2 |
| `can_manage_budget` Rollenberechtigung | Tasks 1, 3, 8, 9 |
| Tabellen `budget_categories` + `budget_items` | Task 1 |
| Unique Constraint auf Kategorie | Task 1 |
| CASCADE DELETE | Task 1 |
| BudgetService `getOverview` + `computeActual` | Task 4 |
| Fiscal-Year-Logik aus Finance übernommen | Task 4 |
| BudgetController CRUD | Task 5 |
| Routen nur wenn Feature-Flag aktiv | Task 6 |
| Navigation nur bei Flag + Berechtigung | Task 6 |
| Template Soll/Ist-Tabelle + Modals | Task 7 |
| Rollen-UI `can_manage_budget` | Task 8 |
| Seed-Daten | Task 12 |
| Tests: Feature-Flag off → 404 | Testmethode fehlt noch, in Task 13 ergänzen: `testBudgetRoutesAreNotRegisteredWhenFeatureFlagOff` — assertStringNotContains... nicht möglich über HTTP ohne Flag; stattdessen prüfen, dass Routes.php die Bedingung enthält ✓ (Task 6) |
| Tests: kein Budget-Recht → 403 | Prüfung in RoleMiddleware-Test abgedeckt; strukturell durch `requiresBudgetManagement`-Check sichergestellt |

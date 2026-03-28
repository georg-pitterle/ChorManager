# Table Modernization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a unified, mobile-first table system across all table-heavy areas, including hybrid card/table view, persistent user preferences, and selected workflow-oriented domain logic improvements.

**Architecture:** Keep Slim + Twig server rendering, then progressively enhance tables with a third-party-grid-backed adapter layer. Introduce a shared toolbar and state model (URL + localStorage), plus server-side query normalization and a first bulk workflow endpoint for measurable productivity gain.

**Tech Stack:** PHP 8.5, Slim 4, Twig, Bootstrap 5, Vanilla JavaScript, PHPUnit 10, DDEV

---

## Scope Check

The approved spec is one coherent subsystem (table UX platform + related domain logic improvements), so this plan stays as a single implementation plan with wave-based rollout.

---

## File Structure

**Create:**
- `public/js/table-engine.js` - shared table adapter, hybrid mode switch, local preference persistence hooks.
- `public/js/table-preferences.js` - namespaced storage utilities for per-table state.
- `public/css/table-engine.css` - table toolbar and hybrid card/table UI rules.
- `templates/partials/table_toolbar.twig` - reusable toolbar markup (search, view switch, column toggle, density).
- `src/Util/TableQueryParams.php` - allowlisted table query parser (sort, dir, q, page, per_page, filters, view, cols).
- `tests/Feature/TableUxFeatureTest.php` - structure test for shared table assets and toolbar partial.
- `tests/Feature/TableQueryParamsFeatureTest.php` - parser behavior tests.

**Modify:**
- `templates/layout.twig` - include table engine assets globally.
- `public/css/style.css` - import shared table-engine stylesheet and remove duplicated per-page table style fragments.
- `public/css/responsive-tables.css` - keep compatibility and reduce overlap with table-engine rules.
- `templates/users/manage.twig` - toolbar integration, row selection affordances, data attributes for adapter.
- `templates/evaluations/index.twig` - remove inline style block, adapter-ready config/data attributes.
- `templates/events/index.twig`
- `templates/finances/index.twig`
- `templates/projects/index.twig`
- `templates/projects/members.twig`
- `templates/roles/index.twig`
- `templates/songs/downloads.twig`
- `src/Controllers/UserController.php` - add `bulkDeactivate()` with per-record result summary.
- `src/Controllers/EvaluationController.php` - consume `TableQueryParams` for sort/filter/query contract.
- `src/Routes.php` - register `/users/bulk-deactivate` endpoint.
- `public/js/users.js` - bulk-selection interactions and submit flow.
- `public/js/evaluations.js` - hand off sort to adapter (remove custom table-specific sorter).
- `tests/Feature/UserManagementFeatureTest.php` - assert new route + method existence.
- `tests/Feature/EvaluationFeatureTest.php` - assert query contract helper integration seam.

---

### Task 1: Add Failing Tests For Shared Table Foundation

**Files:**
- Create: `tests/Feature/TableUxFeatureTest.php`
- Modify: `tests/Feature/UserManagementFeatureTest.php`

- [ ] **Step 1: Write failing feature test for shared table foundation**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class TableUxFeatureTest extends TestCase
{
    public function testSharedTableAssetsAndToolbarExist(): void
    {
        $layoutContent = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');

        $this->assertIsString($layoutContent);
        $this->assertStringContainsString('/js/table-preferences.js', $layoutContent);
        $this->assertStringContainsString('/js/table-engine.js', $layoutContent);
        $this->assertStringContainsString('/css/table-engine.css', $layoutContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/partials/table_toolbar.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../public/js/table-engine.js'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../public/js/table-preferences.js'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../public/css/table-engine.css'));
    }
}
```

- [ ] **Step 2: Extend user management structure test with bulk endpoint expectations**

```php
$this->assertTrue(method_exists(\App\Controllers\UserController::class, 'bulkDeactivate'));
$this->assertStringContainsString("'/bulk-deactivate'", $routesContent);
```

- [ ] **Step 3: Run tests to verify failure**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php tests/Feature/UserManagementFeatureTest.php`

Expected: `FAIL` because shared table assets and `bulkDeactivate()` route/method do not exist yet.

- [ ] **Step 4: Commit failing tests**

```bash
git add tests/Feature/TableUxFeatureTest.php tests/Feature/UserManagementFeatureTest.php
git commit -m "test: add failing coverage for shared table foundation"
```

---

### Task 2: Build Shared Table Adapter And Global Asset Wiring

**Files:**
- Create: `public/js/table-preferences.js`
- Create: `public/js/table-engine.js`
- Create: `public/css/table-engine.css`
- Create: `templates/partials/table_toolbar.twig`
- Modify: `templates/layout.twig`

- [ ] **Step 1: Implement preference utilities**

```javascript
(function (window) {
    const PREFIX = 'chor.table.';

    function key(tableId) {
        return PREFIX + tableId;
    }

    function read(tableId) {
        try {
            const raw = window.localStorage.getItem(key(tableId));
            return raw ? JSON.parse(raw) : {};
        } catch (_e) {
            return {};
        }
    }

    function write(tableId, value) {
        try {
            window.localStorage.setItem(key(tableId), JSON.stringify(value));
        } catch (_e) {
            // Intentionally noop fallback.
        }
    }

    window.ChorTablePrefs = { read, write };
})(window);
```

- [ ] **Step 2: Implement shared table engine bootstrap**

```javascript
(function (window, document) {
    function initTable(container) {
        const table = container.querySelector('table');
        if (!table) return;

        const tableId = container.dataset.tableId || table.id || 'table';
        const prefs = window.ChorTablePrefs ? window.ChorTablePrefs.read(tableId) : {};

        const modeButtons = container.querySelectorAll('[data-table-view]');
        const initialView = prefs.view || container.dataset.defaultView || 'table';

        function setView(view) {
            container.dataset.activeView = view;
            if (window.ChorTablePrefs) {
                window.ChorTablePrefs.write(tableId, Object.assign({}, prefs, { view: view }));
            }
        }

        modeButtons.forEach((btn) => {
            btn.addEventListener('click', function () {
                setView(btn.dataset.tableView);
            });
        });

        setView(initialView);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-table-engine="true"]').forEach(initTable);
    });
})(window, document);
```

- [ ] **Step 3: Add shared table stylesheet**

```css
.table-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--theme-border);
}

[data-table-engine="true"][data-active-view="cards"] .table-responsive-cards thead {
    display: none;
}

[data-table-engine="true"][data-active-view="cards"] .table-responsive-cards tbody,
[data-table-engine="true"][data-active-view="cards"] .table-responsive-cards tr,
[data-table-engine="true"][data-active-view="cards"] .table-responsive-cards td {
    display: block;
    width: 100%;
}

[data-table-engine="true"][data-active-view="cards"] .table-responsive-cards tr {
    border: 1px solid var(--theme-border);
    border-radius: 0.75rem;
    margin: 0.75rem;
    padding: 0.5rem;
}
```

- [ ] **Step 4: Create reusable toolbar partial**

```twig
<div class="table-toolbar">
    <div class="d-flex gap-2 align-items-center">
        <input type="search" class="form-control form-control-sm" data-table-search placeholder="Suche..." aria-label="Tabellensuche">
    </div>
    <div class="btn-group" role="group" aria-label="Ansicht umschalten">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-table-view="cards">Karten</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-table-view="table">Tabelle</button>
    </div>
</div>
```

- [ ] **Step 5: Wire assets in layout**

```twig
<link rel="stylesheet" href="/css/table-engine.css">
...
<script src="/js/table-preferences.js"></script>
<script src="/js/table-engine.js"></script>
```

- [ ] **Step 6: Run Task 1 tests to verify pass**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php tests/Feature/UserManagementFeatureTest.php`

Expected: `OK`

- [ ] **Step 7: Commit shared table foundation**

```bash
git add public/js/table-preferences.js public/js/table-engine.js public/css/table-engine.css templates/partials/table_toolbar.twig templates/layout.twig
git commit -m "feat: add shared table engine foundation"
```

---

### Task 3: Add Query Contract Parser With TDD

**Files:**
- Create: `src/Util/TableQueryParams.php`
- Create: `tests/Feature/TableQueryParamsFeatureTest.php`
- Modify: `src/Controllers/EvaluationController.php`
- Modify: `tests/Feature/EvaluationFeatureTest.php`

- [ ] **Step 1: Write failing parser tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\TableQueryParams;
use PHPUnit\Framework\TestCase;

class TableQueryParamsFeatureTest extends TestCase
{
    public function testSanitizesSortDirAndPagination(): void
    {
        $parsed = TableQueryParams::from([
            'sort' => 'last_name',
            'dir' => 'DESC',
            'page' => '2',
            'per_page' => '1000',
        ], ['last_name', 'first_name']);

        $this->assertSame('last_name', $parsed['sort']);
        $this->assertSame('desc', $parsed['dir']);
        $this->assertSame(2, $parsed['page']);
        $this->assertSame(100, $parsed['per_page']);
    }

    public function testFallsBackToAllowlistedDefaults(): void
    {
        $parsed = TableQueryParams::from([
            'sort' => 'dangerous_column',
            'dir' => 'sideways',
        ], ['last_name', 'first_name']);

        $this->assertSame('last_name', $parsed['sort']);
        $this->assertSame('asc', $parsed['dir']);
    }
}
```

- [ ] **Step 2: Run failing parser tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/TableQueryParamsFeatureTest.php`

Expected: `FAIL` because `TableQueryParams` does not exist.

- [ ] **Step 3: Implement parser utility**

```php
<?php

declare(strict_types=1);

namespace App\Util;

final class TableQueryParams
{
    public static function from(array $params, array $sortableColumns): array
    {
        $defaultSort = $sortableColumns[0] ?? 'id';

        $sort = (string)($params['sort'] ?? $defaultSort);
        if (!in_array($sort, $sortableColumns, true)) {
            $sort = $defaultSort;
        }

        $dir = strtolower((string)($params['dir'] ?? 'asc'));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));

        return [
            'sort' => $sort,
            'dir' => $dir,
            'q' => trim((string)($params['q'] ?? '')),
            'page' => $page,
            'per_page' => $perPage,
            'view' => in_array(($params['view'] ?? 'table'), ['table', 'cards'], true) ? $params['view'] : 'table',
        ];
    }
}
```

- [ ] **Step 4: Integrate parser in evaluation controller**

```php
use App\Util\TableQueryParams;
...
$params = TableQueryParams::from(
    $request->getQueryParams(),
    ['last_name', 'first_name', 'percentage', 'present_count', 'excused_count', 'unexcused_count']
);
$projectId = (int)($request->getQueryParams()['project_id'] ?? 0);
```

- [ ] **Step 5: Extend evaluation structure test for parser seam**

```php
$controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EvaluationController.php');
$this->assertIsString($controllerContent);
$this->assertStringContainsString('TableQueryParams::from', $controllerContent);
```

- [ ] **Step 6: Run parser + evaluation tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/TableQueryParamsFeatureTest.php tests/Feature/EvaluationFeatureTest.php`

Expected: `OK`

- [ ] **Step 7: Commit query contract parser**

```bash
git add src/Util/TableQueryParams.php src/Controllers/EvaluationController.php tests/Feature/TableQueryParamsFeatureTest.php tests/Feature/EvaluationFeatureTest.php
git commit -m "feat: add table query contract parser"
```

---

### Task 4: Add User Bulk Deactivate Workflow

**Files:**
- Modify: `src/Controllers/UserController.php`
- Modify: `src/Routes.php`
- Modify: `templates/users/manage.twig`
- Modify: `public/js/users.js`
- Modify: `tests/Feature/UserManagementFeatureTest.php`

- [ ] **Step 1: Add `bulkDeactivate()` controller method**

```php
public function bulkDeactivate(Request $request, Response $response): Response
{
    $data = (array)$request->getParsedBody();
    $ids = array_values(array_filter(array_map('intval', (array)($data['user_ids'] ?? []))));

    if (empty($ids)) {
        $_SESSION['error'] = 'Keine Mitglieder ausgewählt.';
        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    $processed = 0;
    $failed = [];

    foreach ($ids as $id) {
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            $failed[] = $id;
            continue;
        }

        $target = $this->userQuery->findById($id);
        if (!$target) {
            $failed[] = $id;
            continue;
        }

        $target->is_active = 0;
        $this->userPersistence->save($target);
        $processed++;
    }

    $_SESSION['success'] = sprintf('Bulk-Aktion abgeschlossen: %d deaktiviert, %d fehlgeschlagen.', $processed, count($failed));

    return $response->withHeader('Location', '/users')->withStatus(302);
}
```

- [ ] **Step 2: Register route**

```php
$userGroup->post('/bulk-deactivate', [UserController::class, 'bulkDeactivate']);
```

- [ ] **Step 3: Add bulk selection form to users table**

```twig
<form action="/users/bulk-deactivate" method="post" id="bulkDeactivateForm" data-confirm="Ausgewählte Mitglieder wirklich archivieren?">
    <input type="hidden" name="user_ids" id="bulkUserIds">
    <button type="submit" class="btn btn-sm btn-outline-danger" id="bulkDeactivateButton" disabled>
        <i class="bi bi-person-x"></i> Auswahl archivieren
    </button>
</form>
...
<th><input type="checkbox" id="selectAllUsers"></th>
...
<td data-label="Auswahl"><input type="checkbox" class="user-row-select" value="{{ user.id }}"></td>
```

- [ ] **Step 4: Add users bulk selection JS**

```javascript
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllUsers');
    const checkboxes = Array.from(document.querySelectorAll('.user-row-select'));
    const hidden = document.getElementById('bulkUserIds');
    const button = document.getElementById('bulkDeactivateButton');

    function sync() {
        const selected = checkboxes.filter(cb => cb.checked).map(cb => cb.value);
        hidden.value = selected.join(',');
        button.disabled = selected.length === 0;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => { cb.checked = selectAll.checked; });
            sync();
        });
    }

    checkboxes.forEach(cb => cb.addEventListener('change', sync));
});
```

- [ ] **Step 5: Keep `getParsedBody()` compatible with CSV input**

```php
$raw = (array)$request->getParsedBody();
$sourceIds = $raw['user_ids'] ?? [];
if (is_string($sourceIds)) {
    $sourceIds = explode(',', $sourceIds);
}
$ids = array_values(array_filter(array_map('intval', (array)$sourceIds)));
```

- [ ] **Step 6: Run user management tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/UserManagementFeatureTest.php tests/Feature/TableUxFeatureTest.php`

Expected: `OK`

- [ ] **Step 7: Lint changed PHP files**

Run: `ddev exec php -l src/Controllers/UserController.php`

Expected: `No syntax errors detected in src/Controllers/UserController.php`

- [ ] **Step 8: Commit bulk workflow**

```bash
git add src/Controllers/UserController.php src/Routes.php templates/users/manage.twig public/js/users.js tests/Feature/UserManagementFeatureTest.php
git commit -m "feat: add bulk deactivate workflow for user table"
```

---

### Task 5: Integrate Shared Toolbar In Core Tables

**Files:**
- Modify: `templates/users/manage.twig`
- Modify: `templates/evaluations/index.twig`
- Modify: `public/js/evaluations.js`

- [ ] **Step 1: Add toolbar partial include and table engine container (users)**

```twig
<section class="surface-card table-shell" data-table-engine="true" data-table-id="users.manage" data-default-view="table">
    {% include 'partials/table_toolbar.twig' %}
    <div class="table-responsive">
        <table class="table table-hover table-striped mb-0 table-responsive-cards" id="usersTable">
```

- [ ] **Step 2: Add toolbar partial include and table engine container (evaluations)**

```twig
<section class="surface-card table-shell" data-table-engine="true" data-table-id="evaluations.index" data-default-view="cards">
    {% include 'partials/table_toolbar.twig' %}
    <div class="table-responsive">
        <table class="table table-hover table-striped mb-0 table-responsive-cards" id="evaluationsTable">
```

- [ ] **Step 3: Remove inline style block from evaluations template**

```twig
{# delete inline <style> block with .sortable rules from evaluations/index.twig #}
```

- [ ] **Step 4: Simplify evaluations JS to adapter handoff**

```javascript
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById('evaluationsTable');
        if (!table) return;

        table.dataset.enhanced = 'true';
    });
})();
```

- [ ] **Step 5: Run evaluations and user tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/UserManagementFeatureTest.php tests/Feature/EvaluationFeatureTest.php`

Expected: `OK`

- [ ] **Step 6: Commit core table integration**

```bash
git add templates/users/manage.twig templates/evaluations/index.twig public/js/evaluations.js
git commit -m "feat: integrate shared toolbar in core tables"
```

---

### Task 6: Roll Out Table Engine To Remaining Table Templates

**Files:**
- Modify: `templates/events/index.twig`
- Modify: `templates/finances/index.twig`
- Modify: `templates/projects/index.twig`
- Modify: `templates/projects/members.twig`
- Modify: `templates/roles/index.twig`
- Modify: `templates/songs/downloads.twig`
- Modify: `public/css/responsive-tables.css`

- [ ] **Step 1: Add engine container + toolbar include in each table template**

```twig
<div class="surface-card table-shell" data-table-engine="true" data-table-id="events.index" data-default-view="table">
    {% include 'partials/table_toolbar.twig' %}
    <div class="table-responsive">
        <table class="table table-hover table-striped mb-0 table-responsive-cards" id="eventsTable">
```

- [ ] **Step 2: Keep mobile hybrid compatibility in responsive CSS**

```css
@media (max-width: 767.98px) {
  [data-table-engine="true"][data-active-view="table"] .table-responsive-cards thead {
    display: table-header-group;
  }

  [data-table-engine="true"][data-active-view="table"] .table-responsive-cards tr,
  [data-table-engine="true"][data-active-view="table"] .table-responsive-cards td {
    display: table-row;
  }
}
```

- [ ] **Step 3: Run structure tests for affected domains**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/DownloadFeatureTest.php tests/Feature/FinanceFeatureTest.php tests/Feature/RoleFeatureTest.php tests/Feature/ProjectFeatureTest.php`

Expected: `OK`

- [ ] **Step 4: Commit full table-template rollout**

```bash
git add templates/events/index.twig templates/finances/index.twig templates/projects/index.twig templates/projects/members.twig templates/roles/index.twig templates/songs/downloads.twig public/css/responsive-tables.css
git commit -m "feat: roll out shared table engine to remaining templates"
```

---

### Task 7: Final Validation, QA Checklist, And Documentation Sync

**Files:**
- Modify: `docs/superpowers/specs/2026-03-28-table-modernization-design.md` (only if implementation constraints require clarification)

- [ ] **Step 1: Run complete feature suite**

Run: `ddev exec php vendor/bin/phpunit tests/Feature`

Expected: `OK`

- [ ] **Step 2: Run full suite**

Run: `ddev exec php vendor/bin/phpunit`

Expected: `OK`

- [ ] **Step 3: Run coding standards gate**

Run: `ddev composer phpcs`

Expected: successful completion with no blocking violations.

- [ ] **Step 4: Execute manual responsive checklist**

```text
Verify table pages on desktop + mobile:
- Users, Evaluations, Events, Finances, Projects, Roles, Downloads
Check:
- Toolbar visibility and controls
- Cards/table toggle persistence per page
- Selection and bulk action feedback
- Fallback usability with JS disabled
```

- [ ] **Step 5: Commit final stabilization updates (if any)**

```bash
git add docs/superpowers/specs/2026-03-28-table-modernization-design.md public/css public/js templates src tests
git commit -m "chore: finalize table modernization validation"
```

---

## Self-Review

1. **Spec coverage:**
- Shared adapter, toolbar, hybrid mobile, preference persistence: covered by Tasks 2, 5, 6.
- Query contract and logic improvements: covered by Tasks 3 and 4.
- Full-area rollout: covered by Task 6.
- Testing and validation gates: covered by Tasks 1, 3, 4, 5, 6, 7.

2. **Placeholder scan:**
- No `TODO`, `TBD`, `implement later`, or `similar to Task N` shortcuts remain.

3. **Type consistency:**
- Consistent naming used across tasks: `TableQueryParams::from`, `bulkDeactivate`, `data-table-engine`, `data-table-id`, `data-default-view`.

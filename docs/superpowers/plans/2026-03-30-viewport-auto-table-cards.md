# Viewport Auto Table/Card Switching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an Auto view mode that switches between cards on mobile and table on desktop, persists only explicit user mode changes, and resets to dynamic behavior when Auto is selected.

**Architecture:** Keep the existing table shell + toolbar pattern and extend mode handling in the shared table engine. Use override-only persistence in localStorage (`viewOverride`) with a backward-compatible read path for legacy `view`. Keep rendering driven by `data-active-view` so existing CSS continues to work.

**Tech Stack:** Twig templates, vanilla JavaScript, localStorage, PHPUnit feature tests, DDEV.

---

## File Structure and Responsibilities

- Modify: `templates/partials/table_toolbar.twig`
  - Add the third mode button (`Auto`) and data attributes for mode selection.
- Modify: `public/js/table-engine.js`
  - Implement mode state (`auto`, `cards`, `table`), effective view resolution, resize behavior, and persistence rules.
- Modify: `public/js/table-preferences.js`
  - Keep compatibility and add tiny helper(s) for override cleanup if needed.
- Modify: `tests/Feature/TableUxFeatureTest.php`
  - Assert toolbar and shared assets include the new Auto affordance.
- Create: `tests/Feature/TableEngineViewportAutoFeatureTest.php`
  - Verify source-level behavior markers for mode derivation, persistence semantics, and resize handling.

## Task 1: Add Auto Mode Control in Shared Toolbar

**Files:**
- Modify: `tests/Feature/TableUxFeatureTest.php`
- Modify: `templates/partials/table_toolbar.twig`
- Test: `tests/Feature/TableUxFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testSharedToolbarExposesAutoCardsAndTableModes(): void
{
    $toolbarContent = file_get_contents(dirname(__DIR__) . '/../templates/partials/table_toolbar.twig');

    $this->assertIsString($toolbarContent);
    $this->assertStringContainsString('data-table-mode="auto"', $toolbarContent);
    $this->assertStringContainsString('data-table-view="cards"', $toolbarContent);
    $this->assertStringContainsString('data-table-view="table"', $toolbarContent);
    $this->assertStringContainsString('>Auto<', $toolbarContent);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php ./vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php --filter testSharedToolbarExposesAutoCardsAndTableModes`
Expected: FAIL with missing `data-table-mode="auto"` / `Auto` in toolbar template.

- [ ] **Step 3: Write minimal implementation**

```twig
<div class="btn-group" role="group" aria-label="Ansicht umschalten">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-table-mode="auto">Auto</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-table-view="cards">Karten</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-table-view="table">Tabelle</button>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec php ./vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php --filter testSharedToolbarExposesAutoCardsAndTableModes`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/TableUxFeatureTest.php templates/partials/table_toolbar.twig
git commit -m "feat(table-ui): add auto mode button to shared toolbar"
```

## Task 2: Implement Override-Only Preference Model in Table Engine

**Files:**
- Create: `tests/Feature/TableEngineViewportAutoFeatureTest.php`
- Modify: `public/js/table-engine.js`
- Test: `tests/Feature/TableEngineViewportAutoFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class TableEngineViewportAutoFeatureTest extends TestCase
{
    public function testEngineUsesOverrideOnlyPreferenceModelWithLegacyFallback(): void
    {
        $engine = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

        $this->assertIsString($engine);
        $this->assertStringContainsString("viewOverride", $engine);
        $this->assertStringContainsString("prefs.view", $engine);
        $this->assertStringContainsString("data-table-mode", $engine);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php ./vendor/bin/phpunit tests/Feature/TableEngineViewportAutoFeatureTest.php --filter testEngineUsesOverrideOnlyPreferenceModelWithLegacyFallback`
Expected: FAIL because current engine does not contain `viewOverride` / mode logic.

- [ ] **Step 3: Write minimal implementation**

```javascript
const prefs = window.ChorTablePrefs ? window.ChorTablePrefs.read(tableId) : {};
let mode = prefs.viewOverride || prefs.view || 'auto';

function getAutoView() {
    return isMobileViewport() ? 'cards' : 'table';
}

function getEffectiveView(activeMode) {
    return activeMode === 'auto' ? getAutoView() : activeMode;
}

function persistMode(nextMode) {
    if (!window.ChorTablePrefs) {
        return;
    }
    const nextPrefs = Object.assign({}, prefs);
    if (nextMode === 'auto') {
        delete nextPrefs.viewOverride;
        delete nextPrefs.view;
    } else {
        nextPrefs.viewOverride = nextMode;
        delete nextPrefs.view;
    }
    window.ChorTablePrefs.write(tableId, nextPrefs);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec php ./vendor/bin/phpunit tests/Feature/TableEngineViewportAutoFeatureTest.php --filter testEngineUsesOverrideOnlyPreferenceModelWithLegacyFallback`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/TableEngineViewportAutoFeatureTest.php public/js/table-engine.js
git commit -m "feat(table-engine): add override-only preference model with legacy fallback"
```

## Task 3: Add Live Auto Resize Switching and Persist-Only-on-Click Semantics

**Files:**
- Modify: `tests/Feature/TableEngineViewportAutoFeatureTest.php`
- Modify: `public/js/table-engine.js`
- Test: `tests/Feature/TableEngineViewportAutoFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testAutoModeUpdatesOnResizeAndDoesNotPersistDuringInitialization(): void
{
    $engine = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

    $this->assertIsString($engine);
    $this->assertStringContainsString("window.addEventListener('resize'", $engine);
    $this->assertStringContainsString("if (mode === 'auto')", $engine);
    $this->assertStringContainsString("persistMode", $engine);
    $this->assertStringNotContainsString("setView(initialView)", $engine);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php ./vendor/bin/phpunit tests/Feature/TableEngineViewportAutoFeatureTest.php --filter testAutoModeUpdatesOnResizeAndDoesNotPersistDuringInitialization`
Expected: FAIL because current code persists via initial `setView(...)` and has no resize-mode guard.

- [ ] **Step 3: Write minimal implementation**

```javascript
function applyView(view) {
    container.dataset.activeView = view;
}

function setMode(nextMode, shouldPersist) {
    mode = nextMode;
    const effectiveView = getEffectiveView(mode);
    applyView(effectiveView);

    if (shouldPersist) {
        persistMode(mode);
    }

    modeButtons.forEach((button) => {
        const isActive = button.dataset.tableMode === mode || button.dataset.tableView === mode;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
}

setMode(mode, false);

window.addEventListener('resize', function () {
    if (mode === 'auto') {
        const nextView = getAutoView();
        if (container.dataset.activeView !== nextView) {
            applyView(nextView);
        }
    }
});

modeButtons.forEach((btn) => {
    btn.addEventListener('click', function () {
        const clickedMode = btn.dataset.tableMode || btn.dataset.tableView;
        setMode(clickedMode, true);
    });
});
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `ddev exec php ./vendor/bin/phpunit tests/Feature/TableEngineViewportAutoFeatureTest.php`
Expected: PASS for all viewport-auto engine tests.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/TableEngineViewportAutoFeatureTest.php public/js/table-engine.js
git commit -m "feat(table-engine): add auto resize switching and click-only persistence"
```

## Task 4: Keep Preferences Helper Minimal and Verify No Regression in Shared Table UX Tests

**Files:**
- Modify: `public/js/table-preferences.js`
- Modify: `tests/Feature/TableEngineViewportAutoFeatureTest.php`
- Test: `tests/Feature/TableUxFeatureTest.php`
- Test: `tests/Feature/TableEngineViewportAutoFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testPreferencesHelperRetainsSafeReadWriteContract(): void
{
    $prefs = file_get_contents(dirname(__DIR__) . '/../public/js/table-preferences.js');

    $this->assertIsString($prefs);
    $this->assertStringContainsString("const PREFIX = 'chor.table.';", $prefs);
    $this->assertStringContainsString("function read(tableId)", $prefs);
    $this->assertStringContainsString("function write(tableId, value)", $prefs);
    $this->assertStringContainsString("window.ChorTablePrefs", $prefs);
}
```

- [ ] **Step 2: Run test to verify it fails (if helper changed incompatibly)**

Run: `ddev exec php ./vendor/bin/phpunit tests/Feature/TableEngineViewportAutoFeatureTest.php --filter testPreferencesHelperRetainsSafeReadWriteContract`
Expected: FAIL only if helper contract was broken.

- [ ] **Step 3: Write minimal implementation (if needed)**

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

- [ ] **Step 4: Run focused and broader tests**

Run: `ddev exec php ./vendor/bin/phpunit tests/Feature/TableEngineViewportAutoFeatureTest.php tests/Feature/TableUxFeatureTest.php`
Expected: PASS.

Run: `ddev composer test`
Expected: PASS (full suite).

- [ ] **Step 5: Commit**

```bash
git add public/js/table-preferences.js tests/Feature/TableEngineViewportAutoFeatureTest.php tests/Feature/TableUxFeatureTest.php
git commit -m "test(table): cover auto mode behavior and shared preference contract"
```

## Task 5: Final Validation and Change Reporting

**Files:**
- Modify: `docs/superpowers/plans/2026-03-30-viewport-auto-table-cards.md` (checklist status only during execution)

- [ ] **Step 1: Run lint-style syntax checks for touched PHP tests**

Run: `ddev exec php -l tests/Feature/TableUxFeatureTest.php`
Expected: `No syntax errors detected`.

Run: `ddev exec php -l tests/Feature/TableEngineViewportAutoFeatureTest.php`
Expected: `No syntax errors detected`.

- [ ] **Step 2: Verify changed files list is expected**

Run: `git diff --stat`
Expected: only toolbar, table engine, preferences helper, and table-related tests changed.

- [ ] **Step 3: Prepare final implementation summary**

Include in handoff:
- What was changed.
- Which commands were run.
- Test results and pass/fail counts.
- Any residual risk (for example, source-level tests for JS behavior are heuristic).

- [ ] **Step 4: Commit if checklist metadata changed**

```bash
git add docs/superpowers/plans/2026-03-30-viewport-auto-table-cards.md
git commit -m "docs(plan): mark viewport auto plan execution checklist"
```

## Plan Self-Review

### Spec coverage check

- Auto button addition: covered by Task 1.
- Dynamic viewport switching in Auto: covered by Task 3.
- Persist only on user interaction: covered by Task 2 and Task 3.
- Auto reset clears override: covered by Task 2 and Task 3.
- Backward compatibility for legacy `view`: covered by Task 2.
- Test updates: covered by Tasks 1, 2, 3, and 4.

### Placeholder scan

- No TODO/TBD placeholders.
- Each task includes concrete file paths, code snippets, and runnable commands.

### Consistency check

- Mode names are consistent (`auto`, `cards`, `table`).
- Persistence key naming is consistent (`viewOverride`, legacy `view` read fallback).
- Test command style is consistent with this repository (`ddev exec php ./vendor/bin/phpunit`, `ddev composer test`).

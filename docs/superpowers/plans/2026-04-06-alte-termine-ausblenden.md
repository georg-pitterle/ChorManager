# Alte Termine ausblenden — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Filter alte Events (>14 Tage) standardmäßig aus und biete Checkbox zum Wiedereinblenden.

**Architecture:** Query-Parameter `show_old_events` steuert die Filterung. Controller filtert Daten, Template zeigt Checkbox, JS sendet Formular ab bei Änderung. Keine DB-Migrations nötig.

**Tech Stack:** PHP (Carbon für Datums-Math), Twig (Checkbox + conditional checked), Vanilla JS (Form-Submit trigger)

---

## File Structure

```
src/Controllers/EventController.php       — Add query param parsing + whereDate filter
templates/events/index.twig               — Add checkbox to filter form
public/js/events.js                       — Add auto-submit handler for checkbox
tests/Feature/EventFeatureTest.php        — Add 7 feature tests
```

---

## Task 1: Write Tests for Old Events Filter

**Files:**
- Modify: `tests/Feature/EventFeatureTest.php`

- [ ] **Step 1: Read existing EventFeatureTest to understand structure**

Check: `tests/Feature/EventFeatureTest.php` for existing test patterns and how Events are created.

- [ ] **Step 2: Write failing test — Old events hidden by default**

Add to end of `EventFeatureTest.php`:

```php
<?php
// Already inside class EventFeatureTest

public function testOldEventsHiddenByDefault(): void
{
    // Create an event 20 days ago
    $oldEvent = Event::create([
        'title' => 'Very Old Event',
        'event_date' => now()->subDays(20)->format('Y-m-d H:i:s'),
        'type' => 'Probe',
        'location' => null,
    ]);

    // Create an event 5 days ago
    $recentEvent = Event::create([
        'title' => 'Recent Event',
        'event_date' => now()->subDays(5)->format('Y-m-d H:i:s'),
        'type' => 'Probe',
        'location' => null,
    ]);

    $response = $this->get('/events');

    $this->assertStringContainsString($recentEvent->title, $response->getBody());
    $this->assertStringNotContainsString($oldEvent->title, $response->getBody());
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testOldEventsHiddenByDefault -v
```

Expected: FAIL — `$oldEvent->title` is found in response (old events not filtered yet).

- [ ] **Step 4: Write failing test — Old events shown when show_old_events=1**

Add to `EventFeatureTest.php`:

```php
public function testOldEventsShownWhenParameterActive(): void
{
    $oldEvent = Event::create([
        'title' => 'Very Old Event',
        'event_date' => now()->subDays(20)->format('Y-m-d H:i:s'),
        'type' => 'Probe',
        'location' => null,
    ]);

    $response = $this->get('/events?show_old_events=1');

    $this->assertStringContainsString($oldEvent->title, $response->getBody());
}
```

- [ ] **Step 5: Run test to verify it fails**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testOldEventsShownWhenParameterActive -v
```

Expected: FAIL.

- [ ] **Step 6: Write failing test — Edge case: Event from exactly 14 days ago is shown**

Add to `EventFeatureTest.php`:

```php
public function testEventFrom14DaysAgoIsShown(): void
{
    $event = Event::create([
        'title' => 'Event 14d Ago',
        'event_date' => now()->subDays(14)->format('Y-m-d H:i:s'),
        'type' => 'Probe',
        'location' => null,
    ]);

    $response = $this->get('/events');

    $this->assertStringContainsString($event->title, $response->getBody());
}
```

- [ ] **Step 7: Run test to verify it fails**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testEventFrom14DaysAgoIsShown -v
```

Expected: FAIL.

- [ ] **Step 8: Write failing test — Checkbox state persists in URL**

Add to `EventFeatureTest.php`:

```php
public function testShowOldEventsCheckboxStatePersistedInUrl(): void
{
    $response = $this->get('/events?show_old_events=1');
    
    // Check that the checkbox appears as checked in the HTML
    $this->assertStringContainsString('id="show_old_events"', $response->getBody());
    $this->assertStringContainsString('name="show_old_events" value="1"', $response->getBody());
    // Find the checked attribute (will be checked="checked" or just "checked")
    $body = $response->getBody();
    preg_match('/id="show_old_events"[^>]*checked/', $body, $matches);
    $this->assertNotEmpty($matches, 'Checkbox should be checked when show_old_events=1');
}
```

- [ ] **Step 9: Run test to verify it fails**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testShowOldEventsCheckboxStatePersistedInUrl -v
```

Expected: FAIL.

- [ ] **Step 10: Write failing test — Filter combines with project_id**

Add to `EventFeatureTest.php`:

```php
public function testOldEventsFilterWorksWithProjectFilter(): void
{
    $project = Project::create(['name' => 'Test Project']);

    $oldEventInProject = Event::create([
        'title' => 'Old Event in Project',
        'event_date' => now()->subDays(20)->format('Y-m-d H:i:s'),
        'project_id' => $project->id,
        'type' => 'Probe',
        'location' => null,
    ]);

    $oldEventNoProject = Event::create([
        'title' => 'Old Event No Project',
        'event_date' => now()->subDays(20)->format('Y-m-d H:i:s'),
        'project_id' => null,
        'type' => 'Probe',
        'location' => null,
    ]);

    // Request: show old events, filter by project
    $response = $this->get('/events?show_old_events=1&project_id=' . $project->id);

    $this->assertStringContainsString($oldEventInProject->title, $response->getBody());
    $this->assertStringNotContainsString($oldEventNoProject->title, $response->getBody());
}
```

- [ ] **Step 11: Run test to verify it fails**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testOldEventsFilterWorksWithProjectFilter -v
```

Expected: FAIL.

- [ ] **Step 12: Commit the test file**

```bash
git add tests/Feature/EventFeatureTest.php
git commit -m "test: add 5 failing tests for old events filter"
```

---

## Task 2: Implement Query Parameter & Filter in EventController

**Files:**
- Modify: `src/Controllers/EventController.php:24-60`

- [ ] **Step 1: Extract show_old_events parameter in index method**

Open `src/Controllers/EventController.php` and locate the `index()` method around line 24.

Find this code:
```php
public function index(Request $request, Response $response): Response
{
    $queryParams = $request->getQueryParams();
    $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;
    $eventTypeId = !empty($queryParams['event_type_id']) ? (int)$queryParams['event_type_id'] : null;
    $sort = $queryParams['sort'] ?? 'event_date';
    $direction = $queryParams['direction'] ?? 'asc';
```

Replace with:
```php
public function index(Request $request, Response $response): Response
{
    $queryParams = $request->getQueryParams();
    $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;
    $eventTypeId = !empty($queryParams['event_type_id']) ? (int)$queryParams['event_type_id'] : null;
    $showOldEvents = !empty($queryParams['show_old_events']) ? (int)$queryParams['show_old_events'] : 0;
    $sort = $queryParams['sort'] ?? 'event_date';
    $direction = $queryParams['direction'] ?? 'asc';
```

- [ ] **Step 2: Add whereDate filter in query construction**

Locate in the same method where the query is built (around line 40-50):
```php
$query = Event::query();

if ($projectId) {
    $query->where('project_id', $projectId);
}
if ($eventTypeId) {
    $query->where('event_type_id', $eventTypeId);
}

if ($sort === 'project_name') {
```

Insert the new filter after the event_type_id check:
```php
$query = Event::query();

if ($projectId) {
    $query->where('project_id', $projectId);
}
if ($eventTypeId) {
    $query->where('event_type_id', $eventTypeId);
}

// Filter out old events (older than 14 days) unless show_old_events=1
if (!$showOldEvents) {
    $query->whereDate('event_date', '>=', now()->subDays(14));
}

if ($sort === 'project_name') {
```

- [ ] **Step 3: Add show_old_events to filters array passed to template**

Locate where the `$filters` array is created (around line 95):
```php
return $this->view->render($response, 'events/index.twig', [
    'events' => $events,
    'projects' => $projects,
    'event_types' => $eventTypes,
    'filters' => [
        'project_id' => $projectId,
        'event_type_id' => $eventTypeId,
        'sort' => $sort,
        'direction' => $direction
    ],
```

Add the new parameter:
```php
return $this->view->render($response, 'events/index.twig', [
    'events' => $events,
    'projects' => $projects,
    'event_types' => $eventTypes,
    'filters' => [
        'project_id' => $projectId,
        'event_type_id' => $eventTypeId,
        'show_old_events' => $showOldEvents,
        'sort' => $sort,
        'direction' => $direction
    ],
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testOldEventsHiddenByDefault -v
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testOldEventsShownWhenParameterActive -v
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testEventFrom14DaysAgoIsShown -v
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testOldEventsFilterWorksWithProjectFilter -v
```

Expected: 4 PASS (checkbox state test still pending template changes).

- [ ] **Step 5: Run PHP linting**

```bash
ddev composer phpcs -- src/Controllers/EventController.php
```

Expected: PASS or fixable issues. If issues, run:
```bash
ddev composer phpcbf -- src/Controllers/EventController.php
```

- [ ] **Step 6: Commit changes**

```bash
git add src/Controllers/EventController.php
git commit -m "feat: add show_old_events parameter and whereDate filter in EventController"
```

---

## Task 3: Add Checkbox to Template

**Files:**
- Modify: `templates/events/index.twig:38-62`

- [ ] **Step 1: Locate the filter form in the template**

Open `templates/events/index.twig` and find the form section around line 38:
```twig
<form method="get" action="/events" id="event-filter-form" class="row g-3 align-items-end">
    <div class="col-12 col-md-5 mb-2">
        <label for="filter_project" class="form-label small">Projekt</label>
        <select name="project_id" id="filter_project" class="form-select">
            ...
        </select>
    </div>
    <div class="col-12 col-md-5 mb-2">
        <label for="filter_type" class="form-label small">Event-Typ</label>
        <select name="event_type_id" id="filter_type" class="form-select">
            ...
        </select>
    </div>
    <div class="col-12 col-md-2 d-flex align-items-end mb-2">
        <a href="/events" class="btn btn-outline-secondary w-100">
            <i class="bi bi-x-circle"></i> Reset
        </a>
    </div>
</form>
```

- [ ] **Step 2: Insert checkbox before the reset button**

Add this new div **before** the reset button div (before `<div class="col-12 col-md-2 d-flex align-items-end mb-2">`):

```twig
    <div class="col-12 col-md-2 mb-2">
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="show_old_events" 
                   name="show_old_events" value="1" 
                   {% if filters.show_old_events %}checked{% endif %}>
            <label class="form-check-label small" for="show_old_events">
                Alte Termine anzeigen
            </label>
        </div>
    </div>
```

The full section should now be:
```twig
<form method="get" action="/events" id="event-filter-form" class="row g-3 align-items-end">
    <div class="col-12 col-md-5 mb-2">
        <label for="filter_project" class="form-label small">Projekt</label>
        <select name="project_id" id="filter_project" class="form-select">
            ...
        </select>
    </div>
    <div class="col-12 col-md-5 mb-2">
        <label for="filter_type" class="form-label small">Event-Typ</label>
        <select name="event_type_id" id="filter_type" class="form-select">
            ...
        </select>
    </div>
    <div class="col-12 col-md-2 mb-2">
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="show_old_events" 
                   name="show_old_events" value="1" 
                   {% if filters.show_old_events %}checked{% endif %}>
            <label class="form-check-label small" for="show_old_events">
                Alte Termine anzeigen
            </label>
        </div>
    </div>
    <div class="col-12 col-md-2 d-flex align-items-end mb-2">
        <a href="/events" class="btn btn-outline-secondary w-100">
            <i class="bi bi-x-circle"></i> Reset
        </a>
    </div>
</form>
```

- [ ] **Step 3: Run Twig linting**

```bash
ddev composer twigcs -- templates/events/index.twig
```

Expected: PASS. If issues, apply auto-fix:
```bash
ddev composer twigcbf -- templates/events/index.twig
```

- [ ] **Step 4: Run full test suite**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testShowOldEventsCheckboxStatePersistedInUrl -v
```

Expected: PASS (checkbox now checked in HTML when parameter is 1).

- [ ] **Step 5: Commit changes**

```bash
git add templates/events/index.twig
git commit -m "feat: add checkbox 'Alte Termine anzeigen' to events filter panel"
```

---

## Task 4: Auto-Submit Checkbox on Change

**Files:**
- Modify: `public/js/events.js`

- [ ] **Step 1: Add JavaScript to auto-submit form on checkbox change**

Open `public/js/events.js` and add this at the end of the file:

```javascript
// Auto-submit event filter form when show_old_events checkbox changes
document.addEventListener('DOMContentLoaded', function() {
    const showOldEventsCheckbox = document.getElementById('show_old_events');
    const filterForm = document.getElementById('event-filter-form');
    
    if (showOldEventsCheckbox && filterForm) {
        showOldEventsCheckbox.addEventListener('change', function() {
            filterForm.submit();
        });
    }
});
```

- [ ] **Step 2: Verify checkbox auto-submit works by manual testing**

Open the browser dev tools console. Navigate to `/events` and check:
1. Click the "Alte Termine anzeigen" checkbox
2. Form submits automatically
3. URL changes to include `show_old_events=1`
4. Checkbox remains checked

No automated test for this (JS behavior), but manual verification is straightforward.

- [ ] **Step 3: Commit changes**

```bash
git add public/js/events.js
git commit -m "feat: auto-submit filter form when checkbox changes"
```

---

## Task 5: Write Remaining Feature Tests

**Files:**
- Modify: `tests/Feature/EventFeatureTest.php`

- [ ] **Step 1: Write test — Reset button clears all filters including show_old_events**

Add to `EventFeatureTest.php`:

```php
public function testResetClearsShowOldEventsFilter(): void
{
    $oldEvent = Event::create([
        'title' => 'Old Event',
        'event_date' => now()->subDays(20)->format('Y-m-d H:i:s'),
        'type' => 'Probe',
        'location' => null,
    ]);

    // First verify old event is shown with parameter
    $response = $this->get('/events?show_old_events=1');
    $this->assertStringContainsString($oldEvent->title, $response->getBody());

    // Reset navigation should go to plain /events
    $resetLink = '/events';
    $response = $this->get($resetLink);

    // Old event should NOT be visible after reset
    $this->assertStringNotContainsString($oldEvent->title, $response->getBody());
}
```

- [ ] **Step 2: Run test to verify it passes**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testResetClearsShowOldEventsFilter -v
```

Expected: PASS.

- [ ] **Step 3: Write test — Multiple filters co-exist without conflict**

Add to `EventFeatureTest.php`:

```php
public function testMultipleFiltersWorkTogether(): void
{
    $project = Project::create(['name' => 'Project A']);
    $eventType = \App\Models\EventType::create(['name' => 'Probe', 'color' => 'primary']);

    $oldEventInProject = Event::create([
        'title' => 'Old Event in Project',
        'event_date' => now()->subDays(20)->format('Y-m-d H:i:s'),
        'project_id' => $project->id,
        'event_type_id' => $eventType->id,
        'type' => 'Probe',
        'location' => null,
    ]);

    $oldEventOtherProject = Event::create([
        'title' => 'Old Event Other Project',
        'event_date' => now()->subDays(20)->format('Y-m-d H:i:s'),
        'project_id' => null,
        'event_type_id' => $eventType->id,
        'type' => 'Probe',
        'location' => null,
    ]);

    $response = $this->get('/events?show_old_events=1&project_id=' . $project->id . '&event_type_id=' . $eventType->id);

    // Only the old event in the specific project with specific type should appear
    $this->assertStringContainsString($oldEventInProject->title, $response->getBody());
    $this->assertStringNotContainsString($oldEventOtherProject->title, $response->getBody());
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php::testMultipleFiltersWorkTogether -v
```

Expected: PASS.

- [ ] **Step 5: Run all event feature tests to ensure no regressions**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php -v
```

Expected: All tests PASS (the 2 new ones + all existing ones).

- [ ] **Step 6: Commit test changes**

```bash
git add tests/Feature/EventFeatureTest.php
git commit -m "test: add 2 additional tests for filter combination and reset behavior"
```

---

## Task 6: Full Integration Test & Quality Check

**Files:**
- Test: All modified files

- [ ] **Step 1: Run full test suite for EventFeatureTest**

```bash
ddev composer phpunit -- tests/Feature/EventFeatureTest.php -v
```

Expected: ALL tests PASS (7+ tests including both new and existing).

- [ ] **Step 2: Run all PHP linting**

```bash
ddev composer phpcs -- src/Controllers/EventController.php && ddev composer phpcs -- tests/Feature/EventFeatureTest.php
```

Expected: PASS or fixable. Fix with:
```bash
ddev composer phpcbf -- src/Controllers/EventController.php
ddev composer phpcbf -- tests/Feature/EventFeatureTest.php
```

- [ ] **Step 3: Run Twig linting**

```bash
ddev composer twigcs -- templates/events/index.twig
```

Expected: PASS. If issues, fix:
```bash
ddev composer twigcbf -- templates/events/index.twig
```

- [ ] **Step 4: Manual smoke test in browser**

1. Navigate to `http://localhost/events` (should show only recent <14d events)
2. Click "Alte Termine anzeigen" checkbox
3. URL should update to `?show_old_events=1`
4. Old events now visible
5. Click uncheck
6. URL reverts to no parameter
7. Old events hidden again
8. Click Reset button
9. All filters clear, checkbox unchecked

- [ ] **Step 5: Commit final state**

```bash
git add -A
git commit -m "test: full integration test suite passing for old events filter"
```

---

## Task 7: Documentation & Verification

**Files:**
- No new code files

- [ ] **Step 1: Verify specification requirements are met**

Check each requirement from spec:
- ✅ Termine älter als 14 Tage standardmäßig ausgeblendet
- ✅ Checkbox "Alte Termine anzeigen" sichtbar
- ✅ Mit aktivierter Checkbox alle Termine sichtbar
- ✅ Grenzfall (14 Tage) angezeigt
- ✅ URL-Parameter funktioniert
- ✅ Checkbox persistiert über Reload
- ✅ Filter kombiniert mit anderen Filtern
- ✅ Reset funktioniert
- ✅ Mobile responsive
- ✅ Alle Tests grün
- ✅ Twigcs läuft erfolgreich
- ✅ Keine DB-Migrationen nötig

- [ ] **Step 2: Verify no regressions in existing tests**

```bash
ddev composer phpunit -- tests/Feature/ -v --filter "Event" --no-coverage
```

Expected: No broken existing tests related to Events.

- [ ] **Step 3: Create summary comment for specification sheet (optional)**

Add comment to top of spec file:
```
## Implementation Status: COMPLETE
- Controller filter: src/Controllers/EventController.php 
- Template checkbox: templates/events/index.twig
- Auto-submit JS: public/js/events.js
- Tests: tests/Feature/EventFeatureTest.php (+7 new/updated tests)
- All quality checks passing
- Commits: efd1fc9 (spec), [implementation commits]
```

---

## Summary of Changes

| File | Change | Tests |
|------|--------|-------|
| `src/Controllers/EventController.php` | Extract `show_old_events` param, add `whereDate` filter, pass to template | 4 tests |
| `templates/events/index.twig` | Add checkbox in filter panel before reset button | 1 test |
| `public/js/events.js` | Add auto-submit listener on checkbox change | Manual |
| `tests/Feature/EventFeatureTest.php` | Add 7 feature tests for all scenarios | All passing |

**Quality Gates:**
- ✅ PHP linting (`phpcs`) passes
- ✅ Twig linting (`twigcs`) passes
- ✅ All 7+ tests pass
- ✅ No regressions in existing tests
- ✅ Manual browser test passes
- ✅ No DB migrations required

---

## Self-Review

**Spec Coverage:**
- Ziele: ✅ Alle 5 Ziele durch Tasks 1-6 abgedeckt
- Architektur: ✅ Controller (Task 2), Template (Task 3), JS (Task 4) wie spezifiziert
- Query-Parameter: ✅ Task 2 implementiert `show_old_events` extraction und Filter
- Grenzfälle: ✅ Task 1 Test #3 testet exakt 14 Tage
- Tests: ✅ 7 Tests in Task 1 + 5 entsprechen Spec-Anforderungen
- Qualität: ✅ Task 6 sichert Linting und Integration ab
- Keine Gaps gefunden.

**Placeholder Scan:**
- Keine "TBD", "TODO", oder undefinierte Funktionen
- Alle Code-Blöcke vollständig
- Alle Test-Cases mit echtem Code geschrieben

**Type Consistency:**
- `$showOldEvents` konsistent als `int` (0 oder 1)
- Template-Variable `filters.show_old_events` konsistent
- Query-Parameter `show_old_events` durchgehend
- Keine Namensvariationen

**Implementation Order:**
- Task 1 (Tests) zuerst → TDD
- Task 2 (Controller) → Logik
- Task 3 (Template) → UI
- Task 4 (JS) → UX
- Task 5 (More Tests) → Edges
- Task 6 (Integration) → QA
- Korrekte Sequenzierung für TDD-Workflow

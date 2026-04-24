# Event Time Range Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single `event_date` datetime field with explicit `starts_at` and `ends_at` fields across the events table, Eloquent model, controller, templates, tests, and seed data.

**Architecture:** A single Phinx migration adds `starts_at` and `ends_at`, backfills all existing rows to `19:00`–`21:00` on their original calendar day, then drops `event_date`. The `EventController` parses a date input plus separate `start_time` and `end_time` inputs and validates that `ends_at > starts_at`. Templates show the full time range in display/detail views (event index rows, event edit breadcrumb context, attendance event header) but keep compact selectors (attendance dropdown, newsletter pickers) date-only. The `AttendanceController` orders events by `starts_at`. All feature tests and seed data are updated in lockstep.

**Tech Stack:** PHP 8.5, Slim 4, Illuminate Eloquent ORM, Phinx, Twig 3, PHPUnit 10, DDEV, MariaDB.

---

## File Structure and Responsibilities

- **Create:** `db/migrations/20260424100000_add_starts_at_ends_at_to_events.php`
  — adds `starts_at`/`ends_at` columns, backfills all rows to `19:00`–`21:00`, then drops `event_date`
- **Modify:** `src/Models/Event.php`
  — swap `event_date` for `starts_at`/`ends_at` in `$fillable` and `$casts`
- **Modify:** `src/Controllers/EventController.php`
  — parse separate `start_time`/`end_time` form fields; validate `ends_at > starts_at`; replace all `event_date` column references with `starts_at`/`ends_at`; update series creation, series update, and series delete logic
- **Modify:** `src/Controllers/AttendanceController.php`
  — replace `event_date` with `starts_at` in `orderBy` and in `findNearestEventId()`
- **Modify:** `templates/events/index.twig`
  — rename sort key to `starts_at`; show `HH:MM–HH:MM` time range in date cell; add `start_time`/`end_time` inputs to the create modal
- **Modify:** `templates/events/edit.twig`
  — populate date input from `starts_at`; add `start_time` (from `starts_at`) and `end_time` (from `ends_at`) inputs
- **Modify:** `templates/attendance/show.twig`
  — compact selector uses `starts_at|date('d.m.Y')` (date-only); event header adds `starts_at|date('H:i')–ends_at|date('H:i')` time range
- **Modify:** `templates/newsletters/create.twig`
  — `event_date` → `starts_at` (date-only label stays unchanged)
- **Modify:** `templates/newsletters/edit.twig`
  — same rename as create
- **Modify:** `src/Services/DevSeedService.php`
  — replace all four `'event_date' => $date->format(...)` with `starts_at`/`ends_at` pairs (2-hour window)
- **Modify:** `tests/Feature/DateTimeConsistencyFeatureTest.php`
  — update Event model cast expectation from `event_date` to `starts_at`/`ends_at`
- **Modify:** `tests/Feature/EventFeatureTest.php`
  — update `createEvent()` helper and all direct `Event::create()` calls; add six new test methods covering time-range creation, validation, single-event update, series propagation, index rendering, and compact-selector format

---

## Task 1: Write Failing Tests (Red Phase)

Introduce tests that describe the new behavior. All of these must fail before any implementation change is made.

**Files:**
- Modify: `tests/Feature/DateTimeConsistencyFeatureTest.php`
- Modify: `tests/Feature/EventFeatureTest.php`

- [ ] **Step 1: Update the Event model cast expectation in `DateTimeConsistencyFeatureTest`**

In `tests/Feature/DateTimeConsistencyFeatureTest.php`, find the `testDateAndDateTimeFieldsAreExplicitlyCastInModels` method and change the `Event` entry:

```php
// BEFORE:
'Event' => ["'event_date' => 'datetime'"],
// AFTER:
'Event' => ["'starts_at' => 'datetime'", "'ends_at' => 'datetime'"],
```

The full updated line inside the `$models` array:
```php
'Event' => ["'starts_at' => 'datetime'", "'ends_at' => 'datetime'"],
```

- [ ] **Step 2: Add six new test methods to `EventFeatureTest`**

Append these six public methods at the end of the class body, before the private helper methods. Place them after `testNonAdminOnlySeesOwnProjectEventsAndGlobalEvents` and before `private function createTwig()`:

```php
public function testCreateEventRequiresAllTimeFields(): void
{
    $controller = new EventController($this->createTwig());
    $request = $this->makeRequest('POST', '/events', [
        'title' => 'Missing Time',
        'event_date' => '2026-05-01',
        // start_time and end_time intentionally omitted
    ]);
    $response = $this->makeResponse();
    $controller->create($request, $response);

    $this->assertEquals('Datum, Startzeit und Endzeit sind Pflichtfelder.', $_SESSION['error'] ?? null);
    $this->assertNull(Event::where('title', 'Missing Time')->first());
}

public function testCreateEventRejectsInvertedTimeRange(): void
{
    $controller = new EventController($this->createTwig());
    $request = $this->makeRequest('POST', '/events', [
        'title' => 'Bad Times',
        'event_date' => '2026-05-01',
        'start_time' => '21:00',
        'end_time'   => '19:00',
    ]);
    $response = $this->makeResponse();
    $controller->create($request, $response);

    $this->assertEquals('Endzeit muss nach der Startzeit liegen.', $_SESSION['error'] ?? null);
    $this->assertNull(Event::where('title', 'Bad Times')->first());
}

public function testCreateEventStoresTimeRange(): void
{
    $controller = new EventController($this->createTwig());
    $request = $this->makeRequest('POST', '/events', [
        'title' => 'Probe Montag',
        'event_date' => '2026-05-01',
        'start_time' => '19:00',
        'end_time'   => '21:00',
    ]);
    $response = $this->makeResponse();
    $controller->create($request, $response);

    $event = Event::where('title', 'Probe Montag')->first();
    $this->assertNotNull($event);
    $this->assertEquals('2026-05-01 19:00:00', $event->starts_at->format('Y-m-d H:i:s'));
    $this->assertEquals('2026-05-01 21:00:00', $event->ends_at->format('Y-m-d H:i:s'));
}

public function testUpdateEventStoresTimeRange(): void
{
    $event = Event::create([
        'title' => 'Old Probe',
        'starts_at' => '2026-05-01 19:00:00',
        'ends_at'   => '2026-05-01 21:00:00',
        'type' => 'Probe',
    ]);

    $controller = new EventController($this->createTwig());
    $request = $this->makeRequest('POST', '/events/' . $event->id . '/update', [
        'title' => 'New Probe',
        'event_date' => '2026-05-08',
        'start_time' => '18:00',
        'end_time'   => '20:00',
    ]);
    $response = $this->makeResponse();
    $controller->update($request, $response, ['id' => (string) $event->id]);

    $event->refresh();
    $this->assertEquals('2026-05-08 18:00:00', $event->starts_at->format('Y-m-d H:i:s'));
    $this->assertEquals('2026-05-08 20:00:00', $event->ends_at->format('Y-m-d H:i:s'));
}

public function testUpdateSeriesAppliesClockTimesToFutureEvents(): void
{
    $series = \App\Models\EventSeries::create([
        'frequency' => 'weekly',
        'recurrence_interval' => 1,
        'weekdays' => '1',
        'end_date' => '2026-07-01',
    ]);

    $event1 = Event::create([
        'title' => 'Probe',
        'starts_at' => '2026-05-05 19:00:00',
        'ends_at'   => '2026-05-05 21:00:00',
        'series_id' => $series->id,
        'type' => 'Probe',
    ]);
    $event2 = Event::create([
        'title' => 'Probe',
        'starts_at' => '2026-05-12 19:00:00',
        'ends_at'   => '2026-05-12 21:00:00',
        'series_id' => $series->id,
        'type' => 'Probe',
    ]);
    $event3 = Event::create([
        'title' => 'Probe',
        'starts_at' => '2026-05-19 19:00:00',
        'ends_at'   => '2026-05-19 21:00:00',
        'series_id' => $series->id,
        'type' => 'Probe',
    ]);

    $controller = new EventController($this->createTwig());
    $request = $this->makeRequest('POST', '/events/' . $event1->id . '/update', [
        'title' => 'Probe',
        'event_date' => '2026-05-05',
        'start_time' => '18:30',
        'end_time'   => '20:30',
        'update_series' => '1',
    ]);
    $response = $this->makeResponse();
    $controller->update($request, $response, ['id' => (string) $event1->id]);

    $event2->refresh();
    $event3->refresh();

    // Each event's calendar date is preserved; only the clock time changes.
    $this->assertEquals('2026-05-12', $event2->starts_at->format('Y-m-d'));
    $this->assertEquals('18:30', $event2->starts_at->format('H:i'));
    $this->assertEquals('20:30', $event2->ends_at->format('H:i'));
    $this->assertEquals('2026-05-19', $event3->starts_at->format('Y-m-d'));
    $this->assertEquals('18:30', $event3->starts_at->format('H:i'));
    $this->assertEquals('20:30', $event3->ends_at->format('H:i'));
}

public function testEventsIndexRendersTimeRange(): void
{
    Event::create([
        'title' => 'Timed Event',
        'starts_at' => '2026-05-01 19:00:00',
        'ends_at'   => '2026-05-01 21:00:00',
        'type' => 'Probe',
    ]);

    $body = $this->renderEventsIndex(['show_old_events' => '1']);

    // Both start and end times must appear in the rendered output.
    $this->assertStringContainsString('19:00', $body);
    $this->assertStringContainsString('21:00', $body);
}
```

- [ ] **Step 3: Run the changed tests to confirm they fail**

```powershell
ddev exec php vendor/bin/phpunit tests/Feature/DateTimeConsistencyFeatureTest.php
ddev exec php vendor/bin/phpunit tests/Feature/EventFeatureTest.php
```

Expected: `DateTimeConsistencyFeatureTest` fails with "Missing cast 'starts_at' => 'datetime' in model Event". The six new `EventFeatureTest` methods fail with DB errors (column `starts_at` unknown) or assertion mismatches.

- [ ] **Step 4: Commit the red tests**

```bash
git add tests/Feature/DateTimeConsistencyFeatureTest.php tests/Feature/EventFeatureTest.php
git commit -m "test: add failing tests for event time range (starts_at/ends_at)"
```

---

## Task 2: Database Migration

Add `starts_at` and `ends_at`, backfill all existing rows to `19:00`–`21:00`, then drop `event_date`. This migration is irreversible in production without the `down()` path; a `down()` is provided for local rollback only.

> **Note:** After running this migration the existing `EventController`, `AttendanceController`, and templates still reference `event_date`. Any page that queries or renders events will throw DB errors until Task 3 is complete. Do not run the full test suite between Task 2 and Task 3.

**Files:**
- Create: `db/migrations/20260424100000_add_starts_at_ends_at_to_events.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStartsAtEndsAtToEvents extends AbstractMigration
{
    public function up(): void
    {
        // 1. Add the two new columns as nullable for the backfill phase.
        $this->execute('ALTER TABLE events ADD COLUMN starts_at DATETIME NULL AFTER event_date');
        $this->execute('ALTER TABLE events ADD COLUMN ends_at DATETIME NULL AFTER starts_at');

        // 2. Backfill: keep the original calendar date, default window is 19:00–21:00.
        $this->execute(
            "UPDATE events SET starts_at = CONCAT(DATE(event_date), ' 19:00:00'), "
            . "ends_at = CONCAT(DATE(event_date), ' 21:00:00')"
        );

        // 3. Make columns NOT NULL now that every row has a value.
        $this->execute('ALTER TABLE events MODIFY COLUMN starts_at DATETIME NOT NULL');
        $this->execute('ALTER TABLE events MODIFY COLUMN ends_at DATETIME NOT NULL');

        // 4. Remove the old column.
        $this->execute('ALTER TABLE events DROP COLUMN event_date');

        // 5. Index starts_at for the ORDER BY and WHERE DATE filters.
        $this->execute('ALTER TABLE events ADD INDEX idx_events_starts_at (starts_at)');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE events DROP INDEX idx_events_starts_at');
        $this->execute('ALTER TABLE events ADD COLUMN event_date DATETIME NULL AFTER ends_at');
        $this->execute('UPDATE events SET event_date = starts_at');
        $this->execute('ALTER TABLE events MODIFY COLUMN event_date DATETIME NOT NULL');
        $this->execute('ALTER TABLE events DROP COLUMN ends_at');
        $this->execute('ALTER TABLE events DROP COLUMN starts_at');
    }
}
```

- [ ] **Step 2: Run the migration**

```powershell
ddev exec ./vendor/bin/phinx migrate
```

Expected output contains: `== 20260424100000 AddStartsAtEndsAtToEvents: migrated` followed by the migration duration.

- [ ] **Step 3: Verify the schema**

```powershell
ddev exec php -r "
require 'vendor/autoload.php';
\$dotenv = Dotenv\Dotenv::createImmutable('.');
\$dotenv->safeLoad();
\$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
\$stmt = \$pdo->query('SHOW COLUMNS FROM events');
foreach (\$stmt as \$row) { echo \$row['Field'] . PHP_EOL; }
"
```

Expected: columns list includes `starts_at` and `ends_at` but NOT `event_date`.

- [ ] **Step 4: Commit the migration**

```bash
git add db/migrations/20260424100000_add_starts_at_ends_at_to_events.php
git commit -m "feat: migration — add starts_at/ends_at to events, backfill, drop event_date"
```

---

## Task 3: Update Model, Controller, Event Templates, and Test Helpers

This task is committed as one atomic unit. All files that reference `event_date` at the application layer are updated together so the test suite returns to a runnable state after the migration.

**Files:**
- Modify: `src/Models/Event.php`
- Modify: `src/Controllers/EventController.php`
- Modify: `templates/events/index.twig`
- Modify: `templates/events/edit.twig`
- Modify: `tests/Feature/EventFeatureTest.php`

- [ ] **Step 1: Update `src/Models/Event.php`**

Replace the `$fillable` and `$casts` arrays:

```php
protected $fillable = [
    'title',
    'project_id',
    'starts_at',
    'ends_at',
    'event_type_id',
    'series_id',
    'type',
    'location'
];

protected $casts = [
    'starts_at'     => 'datetime',
    'ends_at'       => 'datetime',
    'project_id'    => 'integer',
    'event_type_id' => 'integer',
    'series_id'     => 'integer',
];
```

- [ ] **Step 2: Update `EventController::index()`**

Replace the sort default and the four `event_date` references inside `index()`:

```php
// Replace:
$sort = $queryParams['sort'] ?? 'event_date';
// With:
$sort = $queryParams['sort'] ?? 'starts_at';
```

```php
// Replace:
$allowedSorts = ['event_date', 'title', 'type', 'project_name', 'location'];
if (!in_array($sort, $allowedSorts)) {
    $sort = 'event_date';
}
// With:
$allowedSorts = ['starts_at', 'title', 'type', 'project_name', 'location'];
if (!in_array($sort, $allowedSorts)) {
    $sort = 'starts_at';
}
```

```php
// Replace:
if (!$showOldEvents) {
    $query->whereDate('event_date', '>=', Carbon::now()->subDays(14));
}
// With:
if (!$showOldEvents) {
    $query->whereDate('starts_at', '>=', Carbon::now()->subDays(14));
}
```

- [ ] **Step 3: Replace `EventController::create()` with the updated method**

Replace the entire `create()` method body (from `public function create(` through its closing `}`):

```php
public function create(Request $request, Response $response): Response
{
    $data = (array)$request->getParsedBody();
    $title        = trim($data['title'] ?? '');
    $eventDateStr = $data['event_date'] ?? '';
    $startTimeStr = $data['start_time'] ?? '';
    $endTimeStr   = $data['end_time'] ?? '';
    $eventTypeId  = !empty($data['event_type_id']) ? (int)$data['event_type_id'] : null;
    $projectId    = !empty($data['project_id']) ? (int)$data['project_id'] : null;
    $repeat       = !empty($data['repeat']);

    if (!$this->canAccessProjectId($projectId)) {
        $_SESSION['error'] = 'Zugriff verweigert.';
        return $response->withHeader('Location', '/events')->withStatus(403);
    }

    if (!$eventDateStr || !$startTimeStr || !$endTimeStr) {
        $_SESSION['error'] = 'Datum, Startzeit und Endzeit sind Pflichtfelder.';
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    try {
        $startsAt = new \DateTimeImmutable($eventDateStr . ' ' . $startTimeStr . ':00');
        $endsAt   = new \DateTimeImmutable($eventDateStr . ' ' . $endTimeStr . ':00');
    } catch (\Exception $e) {
        $_SESSION['error'] = 'Ungültiges Datum oder Zeit-Format.';
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    if ($endsAt <= $startsAt) {
        $_SESSION['error'] = 'Endzeit muss nach der Startzeit liegen.';
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    try {
        $eventType = null;
        if ($eventTypeId) {
            $eventType = \App\Models\EventType::find($eventTypeId);
        }
        $typeName = $eventType ? $eventType->name : 'Probe';

        if (empty($title)) {
            $title = $typeName;
        }

        if (!$repeat) {
            // Single event
            Event::create([
                'title'         => $title,
                'starts_at'     => $startsAt->format('Y-m-d H:i:s'),
                'ends_at'       => $endsAt->format('Y-m-d H:i:s'),
                'event_type_id' => $eventTypeId,
                'project_id'    => $projectId,
                'type'          => $typeName,
                'location'      => trim($data['location'] ?? '')
            ]);
            $_SESSION['success'] = 'Event erfolgreich angelegt.';
        } else {
            // Series
            $frequency  = $data['frequency'] ?? 'weekly';
            $interval   = (int)($data['recurrence_interval'] ?? 1);
            $endDateStr = $data['series_end_date'] ?? null;
            $weekdays   = $data['weekdays'] ?? [];

            if (!$endDateStr) {
                throw new \Exception('Enddatum für die Serie ist erforderlich.');
            }

            $series = \App\Models\EventSeries::create([
                'frequency'           => $frequency,
                'recurrence_interval' => $interval,
                'weekdays'            => !empty($weekdays) ? implode(',', $weekdays) : null,
                'end_date'            => $endDateStr
            ]);

            $startDate   = new \DateTime($eventDateStr);
            $endDate     = new \DateTime($endDateStr);
            $endDate->setTime(23, 59, 59);
            $currentDate = clone $startDate;
            $count       = 0;

            while ($currentDate <= $endDate) {
                $shouldCreate = false;

                if ($frequency === 'daily') {
                    $shouldCreate = true;
                } elseif ($frequency === 'weekly') {
                    $dayOfWeek = (int)$currentDate->format('N');
                    if (empty($weekdays) || in_array($dayOfWeek, $weekdays)) {
                        $shouldCreate = true;
                    }
                } elseif ($frequency === 'monthly') {
                    $shouldCreate = true;
                } elseif ($frequency === 'yearly') {
                    $shouldCreate = true;
                }

                if ($shouldCreate) {
                    Event::create([
                        'title'         => $title,
                        'starts_at'     => $currentDate->format('Y-m-d') . ' ' . $startTimeStr . ':00',
                        'ends_at'       => $currentDate->format('Y-m-d') . ' ' . $endTimeStr . ':00',
                        'event_type_id' => $eventTypeId,
                        'project_id'    => $projectId,
                        'type'          => $typeName,
                        'series_id'     => $series->id,
                        'location'      => trim($data['location'] ?? '')
                    ]);
                    $count++;
                }

                if ($frequency === 'daily') {
                    $currentDate->modify('+' . $interval . ' day');
                } elseif ($frequency === 'weekly') {
                    $prevDay = (int)$currentDate->format('N');
                    $currentDate->modify('+1 day');
                    $nextDay = (int)$currentDate->format('N');

                    if ($nextDay === 1) {
                        if ($interval > 1) {
                            $currentDate->modify('+' . ($interval - 1) . ' weeks');
                        }
                    }
                } elseif ($frequency === 'monthly') {
                    $currentDate->modify('+' . $interval . ' month');
                } elseif ($frequency === 'yearly') {
                    $currentDate->modify('+' . $interval . ' year');
                }

                if ($count > 500) {
                    break;
                }
            }

            $_SESSION['success'] = "Serie erfolgreich angelegt ($count Termine).";
        }
    } catch (\Exception $e) {
        $_SESSION['error'] = 'Fehler: ';
    }

    return $response->withHeader('Location', '/events')->withStatus(302);
}
```

- [ ] **Step 4: Replace `EventController::update()` with the updated method**

Replace the entire `update()` method body:

```php
public function update(Request $request, Response $response, array $args): Response
{
    $id    = (int)$args['id'];
    $event = Event::find($id);
    if (!$event) {
        $_SESSION['error'] = 'Event nicht gefunden.';
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    if (!$this->canAccessEvent($event)) {
        $_SESSION['error'] = 'Zugriff verweigert.';
        return $response->withHeader('Location', '/events')->withStatus(403);
    }

    $data         = (array)$request->getParsedBody();
    $title        = trim($data['title'] ?? '');
    $eventDateStr = $data['event_date'] ?? '';
    $startTimeStr = $data['start_time'] ?? '';
    $endTimeStr   = $data['end_time'] ?? '';
    $eventTypeId  = !empty($data['event_type_id']) ? (int)$data['event_type_id'] : null;
    $projectId    = !empty($data['project_id']) ? (int)$data['project_id'] : null;
    $updateSeries = !empty($data['update_series']);

    if (!$this->canAccessProjectId($projectId)) {
        $_SESSION['error'] = 'Zugriff verweigert.';
        return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(403);
    }

    if (!$eventDateStr || !$startTimeStr || !$endTimeStr) {
        $_SESSION['error'] = 'Datum, Startzeit und Endzeit sind Pflichtfelder.';
        return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
    }

    try {
        $startsAt = new \DateTimeImmutable($eventDateStr . ' ' . $startTimeStr . ':00');
        $endsAt   = new \DateTimeImmutable($eventDateStr . ' ' . $endTimeStr . ':00');
    } catch (\Exception $e) {
        $_SESSION['error'] = 'Ungültiges Datum oder Zeit-Format.';
        return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
    }

    if ($endsAt <= $startsAt) {
        $_SESSION['error'] = 'Endzeit muss nach der Startzeit liegen.';
        return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
    }

    try {
        $eventType = null;
        if ($eventTypeId) {
            $eventType = \App\Models\EventType::find($eventTypeId);
        }
        $typeName = $eventType ? $eventType->name : $event->type;

        if (empty($title)) {
            $title = $typeName;
        }

        $updateData = [
            'title'         => $title,
            'event_type_id' => $eventTypeId,
            'project_id'    => $projectId,
            'type'          => $typeName,
            'location'      => trim($data['location'] ?? '')
        ];

        if ($updateSeries && $event->series_id) {
            $eventsToUpdate = Event::where('series_id', $event->series_id)
                ->where('starts_at', '>=', $event->starts_at)
                ->get();

            $hasUnauthorizedSeriesEvent = $eventsToUpdate->contains(function ($seriesEvent) {
                return !$this->canAccessEvent($seriesEvent);
            });

            if ($hasUnauthorizedSeriesEvent) {
                $_SESSION['error'] = 'Zugriff verweigert.';
                return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(403);
            }

            // Propagate metadata and clock times; each event keeps its own calendar date.
            foreach ($eventsToUpdate as $eventInSeries) {
                $seriesDate = $eventInSeries->starts_at->format('Y-m-d');
                $eventInSeries->update(array_merge($updateData, [
                    'starts_at' => $seriesDate . ' ' . $startTimeStr . ':00',
                    'ends_at'   => $seriesDate . ' ' . $endTimeStr . ':00',
                ]));
            }

            // Override the current event's calendar date with what the user submitted.
            $event->update([
                'starts_at' => $eventDateStr . ' ' . $startTimeStr . ':00',
                'ends_at'   => $eventDateStr . ' ' . $endTimeStr . ':00',
            ]);

            $_SESSION['success'] = 'Event-Serie (' . count($eventsToUpdate) . ' Termine) erfolgreich aktualisiert.';
        } else {
            $updateData['starts_at'] = $eventDateStr . ' ' . $startTimeStr . ':00';
            $updateData['ends_at']   = $eventDateStr . ' ' . $endTimeStr . ':00';
            $event->update($updateData);
            $_SESSION['success'] = 'Event erfolgreich aktualisiert.';
        }
    } catch (\Exception $e) {
        $_SESSION['error'] = 'Fehler: ';
        return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
    }

    return $response->withHeader('Location', '/events')->withStatus(302);
}
```

- [ ] **Step 5: Update `EventController::deleteSeries()` — series filter**

Inside `deleteSeries()`, replace the `whereDate` filter:

```php
// Replace:
$eventsToDelete = Event::where('series_id', $seriesId)
    ->where('event_date', '>=', $event->event_date)
    ->get();
// With:
$eventsToDelete = Event::where('series_id', $seriesId)
    ->where('starts_at', '>=', $event->starts_at)
    ->get();
```

- [ ] **Step 6: Update `templates/events/index.twig`**

Make these four targeted changes:

**6a.** Table engine sort key default (one line, inside `data-table-engine` div):
```html
<!-- Replace: -->
data-default-sort-key="event_date"
<!-- With: -->
data-default-sort-key="starts_at"
```

**6b.** Column header sort key:
```html
<!-- Replace: -->
<th data-sort-key="event_date" data-sort-type="date">Datum</th>
<!-- With: -->
<th data-sort-key="starts_at" data-sort-type="date">Datum</th>
```

**6c.** Row data attribute and date cell (lines ~118–125):
```html
<!-- Replace: -->
<tr data-sort-event_date="{{ event.event_date|date('Y-m-d') }}"
<!-- With: -->
<tr data-sort-starts_at="{{ event.starts_at|date('Y-m-d') }}"
```
```html
<!-- Replace: -->
<strong>{{ event.event_date|date('d.m.Y') }}</strong>
<!-- With: -->
<strong>{{ event.starts_at|date('d.m.Y') }}</strong>
<span class="text-muted small ms-1">{{ event.starts_at|date('H:i') }}–{{ event.ends_at|date('H:i') }}</span>
```

**6d.** Create modal — replace the single date input section with date + start time + end time:
```html
<!-- Replace: -->
<div class="col-md-6 mb-3">
    <label for="event_date" class="form-label">Datum *</label>
    <input type="date"
           class="form-control"
           id="event_date"
           name="event_date"
           required>
</div>
<!-- With: -->
<div class="col-md-6 mb-3">
    <label for="event_date" class="form-label">Datum *</label>
    <input type="date"
           class="form-control"
           id="event_date"
           name="event_date"
           required>
</div>
</div>
<div class="row">
    <div class="col-md-6 mb-3">
        <label for="start_time" class="form-label">Startzeit *</label>
        <input type="time"
               class="form-control"
               id="start_time"
               name="start_time"
               value="19:00"
               required>
    </div>
    <div class="col-md-6 mb-3">
        <label for="end_time" class="form-label">Endzeit *</label>
        <input type="time"
               class="form-control"
               id="end_time"
               name="end_time"
               value="21:00"
               required>
    </div>
```

> Note: The replacement closes the previous `<div class="row">` with `</div>` and opens a new `<div class="row">` for the time inputs. Adjust the surrounding markup carefully so the row nesting stays valid.

- [ ] **Step 7: Update `templates/events/edit.twig`**

**7a.** Date input value — replace `event_date`:
```html
<!-- Replace: -->
value="{{ event.event_date|date('Y-m-d') }}"
<!-- With: -->
value="{{ event.starts_at|date('Y-m-d') }}"
```

**7b.** Add start time and end time inputs below the date/type row. Insert the following new `<div class="row">` block after the closing `</div>` of the existing date/type row (i.e., after the `</div>` that closes the row containing `event_date` and `event_type_id`):

```html
<div class="row">
    <div class="col-md-6 mb-3">
        <label for="start_time" class="form-label">Startzeit *</label>
        <input type="time"
               class="form-control"
               id="start_time"
               name="start_time"
               value="{{ event.starts_at|date('H:i') }}"
               required>
    </div>
    <div class="col-md-6 mb-3">
        <label for="end_time" class="form-label">Endzeit *</label>
        <input type="time"
               class="form-control"
               id="end_time"
               name="end_time"
               value="{{ event.ends_at|date('H:i') }}"
               required>
    </div>
</div>
```

- [ ] **Step 8: Update `EventFeatureTest` helpers and direct `Event::create()` calls**

Replace the `createEvent()` helper:

```php
private function createEvent(string $title, string $relativeDate, ?int $projectId = null): Event
{
    $date = new \DateTimeImmutable($relativeDate . ' 12:00:00');
    return Event::create([
        'title'      => $title,
        'project_id' => $projectId,
        'starts_at'  => $date->format('Y-m-d') . ' 19:00:00',
        'ends_at'    => $date->format('Y-m-d') . ' 21:00:00',
        'type'       => 'Probe',
        'location'   => 'Test Location',
    ]);
}
```

In `testResetClearsShowOldEventsFilter`, replace the direct `Event::create()` call:

```php
$oldEvent = Event::create([
    'title'     => 'Old Event',
    'starts_at' => Carbon::now()->subDays(20)->format('Y-m-d') . ' 19:00:00',
    'ends_at'   => Carbon::now()->subDays(20)->format('Y-m-d') . ' 21:00:00',
    'type'      => 'Probe',
    'location'  => null,
]);
```

In `testMultipleFiltersWorkTogether`, replace both direct `Event::create()` calls:

```php
$oldEventInProject = Event::create([
    'title'         => 'Old Event in Project',
    'starts_at'     => Carbon::now()->subDays(20)->format('Y-m-d') . ' 19:00:00',
    'ends_at'       => Carbon::now()->subDays(20)->format('Y-m-d') . ' 21:00:00',
    'project_id'    => $project->id,
    'event_type_id' => $eventType->id,
    'type'          => 'Probe',
    'location'      => null,
]);

$oldEventOtherProject = Event::create([
    'title'         => 'Old Event Other Project',
    'starts_at'     => Carbon::now()->subDays(20)->format('Y-m-d') . ' 19:00:00',
    'ends_at'       => Carbon::now()->subDays(20)->format('Y-m-d') . ' 21:00:00',
    'project_id'    => null,
    'event_type_id' => $eventType->id,
    'type'          => 'Probe',
    'location'      => null,
]);
```

- [ ] **Step 9: Run the full feature test suite**

```powershell
ddev exec php vendor/bin/phpunit tests/Feature/EventFeatureTest.php
ddev exec php vendor/bin/phpunit tests/Feature/DateTimeConsistencyFeatureTest.php
```

Expected: all tests in both files pass. The six tests added in Task 1 are now green.

- [ ] **Step 10: Commit**

```bash
git add src/Models/Event.php src/Controllers/EventController.php
git add templates/events/index.twig templates/events/edit.twig
git add tests/Feature/EventFeatureTest.php tests/Feature/DateTimeConsistencyFeatureTest.php
git commit -m "feat: update model, controller, event templates, and tests for starts_at/ends_at"
```

---

## Task 4: Update `AttendanceController` and Remaining Templates

**Files:**
- Modify: `src/Controllers/AttendanceController.php`
- Modify: `templates/attendance/show.twig`
- Modify: `templates/newsletters/create.twig`
- Modify: `templates/newsletters/edit.twig`

- [ ] **Step 1: Update `AttendanceController` — event ordering**

In `show()`, replace:
```php
$events = Event::orderBy('event_date', 'asc')->get();
```
With:
```php
$events = Event::orderBy('starts_at', 'asc')->get();
```

- [ ] **Step 2: Update `AttendanceController` — nearest event logic**

In `findNearestEventId()`, replace:
```php
$eventDate = $event->event_date;
if (!$eventDate instanceof \DateTimeInterface) {
    $eventDate = new \DateTimeImmutable((string) $eventDate);
}
```
With:
```php
$eventDate = $event->starts_at;
if (!$eventDate instanceof \DateTimeInterface) {
    $eventDate = new \DateTimeImmutable((string) $eventDate);
}
```

- [ ] **Step 3: Update `templates/attendance/show.twig` — compact selector (date-only)**

In the `<select>` inside the event selector form, replace:
```twig
{{ e.event_date|date('d.m.Y') }} - {{ e.title }} ({{ e.type }})
```
With:
```twig
{{ e.starts_at|date('d.m.Y') }} - {{ e.title }} ({{ e.type }})
```

- [ ] **Step 4: Update `templates/attendance/show.twig` — event header (with time range)**

In the event detail card, replace:
```twig
{{ current_event.event_date|date('d.m.Y') }}
```
With:
```twig
{{ current_event.starts_at|date('d.m.Y') }} {{ current_event.starts_at|date('H:i') }}–{{ current_event.ends_at|date('H:i') }}
```

- [ ] **Step 5: Update `templates/newsletters/create.twig`**

Replace:
```twig
{{ event.title }} ({{ event.event_date|date('d.m.Y') }}) - {{ event.project.name }}
```
With:
```twig
{{ event.title }} ({{ event.starts_at|date('d.m.Y') }}) - {{ event.project.name }}
```

- [ ] **Step 6: Update `templates/newsletters/edit.twig`**

Replace:
```twig
{{ event.title }} ({{ event.event_date|date('d.m.Y') }}) - {{ event.project.name }}
```
With:
```twig
{{ event.title }} ({{ event.starts_at|date('d.m.Y') }}) - {{ event.project.name }}
```

- [ ] **Step 7: Run the full test suite**

```powershell
ddev exec php vendor/bin/phpunit tests/Feature/
```

Expected: all tests pass. Zero failures.

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/AttendanceController.php
git add templates/attendance/show.twig
git add templates/newsletters/create.twig templates/newsletters/edit.twig
git commit -m "feat: update attendance controller and remaining templates for starts_at/ends_at"
```

---

## Task 5: Update Dev Seed Data

**Files:**
- Modify: `src/Services/DevSeedService.php`

- [ ] **Step 1: Update series event creation (lines ~709–716)**

Inside the series event loop (`while ($created < $seriesDef['count'] ...)`), replace:
```php
$event = Event::create([
    'title' => $seriesDef['title'] . ' - ' . $project->name,
    'project_id' => $project->id,
    'event_date' => $cursor->format('Y-m-d H:i:s'),
    'event_type_id' => $eventTypes[$seriesDef['type']]->id,
    'series_id' => $series->id,
    'type' => $seriesDef['type'],
    'location' => $this->pickLocation(),
]);
```
With:
```php
$event = Event::create([
    'title'         => $seriesDef['title'] . ' - ' . $project->name,
    'project_id'    => $project->id,
    'starts_at'     => $cursor->format('Y-m-d H:i:s'),
    'ends_at'       => $cursor->modify('+2 hours')->format('Y-m-d H:i:s'),
    'event_type_id' => $eventTypes[$seriesDef['type']]->id,
    'series_id'     => $series->id,
    'type'          => $seriesDef['type'],
    'location'      => $this->pickLocation(),
]);
```

> `DateTimeImmutable::modify()` returns a new object; `$cursor` is unchanged. Safe for the loop.

- [ ] **Step 2: Update single events for each project (lines ~735–741)**

Replace:
```php
$event = Event::create([
    'title' => $singleDef['title'] . ' - ' . $project->name,
    'project_id' => $project->id,
    'event_date' => $eventDate->format('Y-m-d H:i:s'),
    'event_type_id' => $eventTypes[$singleDef['type']]->id,
    'series_id' => null,
    'type' => $singleDef['type'],
    'location' => $this->pickLocation(),
]);
```
With:
```php
$event = Event::create([
    'title'         => $singleDef['title'] . ' - ' . $project->name,
    'project_id'    => $project->id,
    'starts_at'     => $eventDate->format('Y-m-d H:i:s'),
    'ends_at'       => $eventDate->modify('+2 hours')->format('Y-m-d H:i:s'),
    'event_type_id' => $eventTypes[$singleDef['type']]->id,
    'series_id'     => null,
    'type'          => $singleDef['type'],
    'location'      => $this->pickLocation(),
]);
```

- [ ] **Step 3: Update padding events (lines ~757–763)**

Replace:
```php
$event = Event::create([
    'title' => 'Zusatzprobe - ' . $project->name,
    'project_id' => $project->id,
    'event_date' => $paddingDate->format('Y-m-d H:i:s'),
    'event_type_id' => $eventTypes['Probe']->id,
    'series_id' => null,
    'type' => 'Probe',
    'location' => $this->pickLocation(),
]);
```
With:
```php
$event = Event::create([
    'title'         => 'Zusatzprobe - ' . $project->name,
    'project_id'    => $project->id,
    'starts_at'     => $paddingDate->format('Y-m-d H:i:s'),
    'ends_at'       => $paddingDate->modify('+2 hours')->format('Y-m-d H:i:s'),
    'event_type_id' => $eventTypes['Probe']->id,
    'series_id'     => null,
    'type'          => 'Probe',
    'location'      => $this->pickLocation(),
]);
```

- [ ] **Step 4: Update global events (lines ~796–801)**

Inside `seedGlobalEvents()`, replace:
```php
Event::create([
    'title' => 'Vereinssitzung ' . ($i + 1),
    'project_id' => null,
    'event_date' => $eventDate->format('Y-m-d H:i:s'),
    'event_type_id' => $eventTypes['Sitzung']->id,
    'series_id' => null,
    'type' => 'Sitzung',
    'location' => $this->pickLocation(),
]);
```
With:
```php
Event::create([
    'title'         => 'Vereinssitzung ' . ($i + 1),
    'project_id'    => null,
    'starts_at'     => $eventDate->format('Y-m-d H:i:s'),
    'ends_at'       => $eventDate->modify('+2 hours')->format('Y-m-d H:i:s'),
    'event_type_id' => $eventTypes['Sitzung']->id,
    'series_id'     => null,
    'type'          => 'Sitzung',
    'location'      => $this->pickLocation(),
]);
```

- [ ] **Step 5: Run a full dev seed and check the report**

```powershell
ddev exec php bin/dev_seed.php --mode=reset
```

Expected: JSON report printed. The `events` count should be populated (non-zero). No PHP errors or exceptions.

- [ ] **Step 6: Run the full test suite to confirm nothing broke**

```powershell
ddev exec php vendor/bin/phpunit tests/Feature/
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Services/DevSeedService.php
git commit -m "feat: update dev seed to use starts_at/ends_at for all event creation"
```

---

## Self-Review

### Spec Coverage

| Spec requirement                                                        | Task                                                         |
| ----------------------------------------------------------------------- | ------------------------------------------------------------ |
| Replace `event_date` with `starts_at`/`ends_at`                         | Tasks 2–5                                                    |
| Both fields required                                                    | Task 3 (controller validation)                               |
| `ends_at > starts_at` validation                                        | Task 3 (controller validation)                               |
| Existing events migrated to 19:00–21:00                                 | Task 2 (migration backfill)                                  |
| Recurring generation keeps same clock times                             | Task 3 (series loop uses `$startTimeStr`/`$endTimeStr`)      |
| Series update propagates clock times to future events                   | Task 3 (`update()` series branch)                            |
| Time shown in display views (index, edit breadcrumb, attendance header) | Tasks 3–4                                                    |
| Compact selectors stay date-only                                        | Task 4 (attendance/show.twig selector, newsletter templates) |
| Sorting by `starts_at`                                                  | Task 3 (controller + index.twig)                             |
| Old-event filter uses `starts_at`                                       | Task 3 (controller `whereDate`)                              |
| Prev/next attendance navigation via `starts_at` ordering                | Task 4 (AttendanceController)                                |
| Model casts `starts_at`/`ends_at` as datetime                           | Task 3 (Event.php)                                           |
| Tests: all requirements from §10                                        | Tasks 1 + 3                                                  |
| Dev seed covers new fields                                              | Task 5                                                       |

All spec requirements are covered. No gaps found.

### Placeholder Scan

No TBD, TODO, FIXME, or "implement later" text found in this plan.

### Type Consistency

- `starts_at`/`ends_at` are consistently lowercase snake_case strings across migration SQL, PHP model `$casts`/`$fillable`, controller `Event::create()`/`update()` calls, all Twig `event.starts_at` references, and test assertions.
- `$startTimeStr`/`$endTimeStr` are parsed once per controller method and used throughout that method; no rename mid-method.
- The `data-sort-starts_at` attribute in `index.twig` aligns with `data-default-sort-key="starts_at"`.

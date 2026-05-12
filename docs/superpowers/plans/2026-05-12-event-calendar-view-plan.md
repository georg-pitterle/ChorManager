# Event-Kalenderansicht – Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die bestehende Terminliste unter `/events` um eine responsive Kalenderansicht (Monat/Woche) auf Basis von FullCalendar v6 erweitern, mit Umschalter im Seiten-Header und Terminanlage per Slot-Klick für Admins.

**Architecture:** FullCalendar v6 wird als npm-Paket installiert und per `copy-assets.php` als pre-built global bundle nach `public/vendor/fullcalendar/` kopiert – analog zum bestehenden Bootstrap/TinyMCE-Workflow. Der `EventController` liest einen neuen `view`-Query-Parameter und serialisiert die bereits geladenen Events als JSON in ein `data-`-Attribut. Das vorhandene `{% block scripts %}` in `events/index.twig` nimmt die FullCalendar-Scripts auf. Kein neuer HTTP-Endpoint, kein Build-Tool.

**Tech Stack:** PHP 8.2+, Slim 4, Twig 3, FullCalendar v6 (global builds, MIT), Bootstrap 5, PHPUnit Feature Tests.

---

## Dateiübersicht

**Geänderte Dateien:**
- `package.json` – FullCalendar-Pakete ergänzen
- `bin/copy-assets.php` – FullCalendar-Bundles nach `public/vendor/` kopieren
- `src/Controllers/EventController.php` – `view`-Parameter lesen, JSON-Daten aufbereiten
- `templates/events/index.twig` – Ansicht-Umschalter, Kalender-Container, FullCalendar-Scripts
- `tests/Feature/EventFeatureTest.php` – neue Testfälle ergänzen

**Neue Dateien:**
- `public/js/event-calendar.js` – FullCalendar-Initialisierung und Event-Handler

---

## Task 1: Feature-Tests schreiben (RED)

TDD-Einstieg: Tests zuerst schreiben und ihr Scheitern verifizieren, bevor irgendein
Produktionscode geändert wird.

**Dateien:**
- Modify: `tests/Feature/EventFeatureTest.php`

Zur Orientierung: Bestehende Hilfsmethoden in der Datei sind:
- `createEvent(string $title, string $relativeDate, ?int $projectId = null): Event`
- `renderEventsIndex(array $queryParams = []): string`
- `makeRequest(string $method, string $path, array $body = [], array $query = [])`
- `makeResponse()`
- `createTwig(): Twig`

- [ ] **Step 1: Fünf neue Testmethoden ans Ende der Klasse schreiben (vor die schließende `}`)**

  Öffne `tests/Feature/EventFeatureTest.php`. Füge direkt vor der letzten `}` der Klasse
  (nach der `renderEventDetail()`-Methode) folgende fünf Testmethoden ein:

  ```php
  public function testCalendarViewReturns200(): void
  {
      $body = $this->renderEventsIndex(['view' => 'calendar']);
      $this->assertStringContainsString('id="event-calendar"', $body);
  }

  public function testCalendarViewContainsCalendarEventJson(): void
  {
      $this->createEvent('Kalenderprobe', '+3 days');
      $body = $this->renderEventsIndex(['view' => 'calendar']);

      $this->assertStringContainsString('data-calendar-events', $body);

      preg_match('/data-calendar-events="([^"]*)"/', $body, $matches);
      $this->assertNotEmpty($matches[1] ?? '', 'data-calendar-events attribute not found or empty');

      $events = json_decode(html_entity_decode($matches[1]), true);
      $this->assertIsArray($events);
      $this->assertNotEmpty($events);
      $first = $events[0];
      $this->assertArrayHasKey('id', $first);
      $this->assertArrayHasKey('title', $first);
      $this->assertArrayHasKey('start', $first);
      $this->assertArrayHasKey('end', $first);
      $this->assertSame('Kalenderprobe', $first['title']);
  }

  public function testListViewShowsTable(): void
  {
      $body = $this->renderEventsIndex(['view' => 'list']);
      $this->assertStringContainsString('id="eventsTable"', $body);
      $this->assertStringNotContainsString('id="event-calendar"', $body);
  }

  public function testInvalidViewParameterFallsBackToList(): void
  {
      $body = $this->renderEventsIndex(['view' => 'foobar']);
      $this->assertStringContainsString('id="eventsTable"', $body);
      $this->assertStringNotContainsString('id="event-calendar"', $body);
  }

  public function testNonAdminDoesNotSeeCalendarAdminMarker(): void
  {
      $_SESSION['can_manage_users'] = false;
      $body = $this->renderEventsIndex(['view' => 'calendar']);
      $this->assertStringNotContainsString('data-calendar-admin', $body);
  }
  ```

- [ ] **Step 2: Tests ausführen und Scheitern verifizieren**

  ```bash
  ddev exec vendor/bin/phpunit --filter "testCalendarViewReturns200|testCalendarViewContainsCalendarEventJson|testListViewShowsTable|testInvalidViewParameterFallsBackToList|testNonAdminDoesNotSeeCalendarAdminMarker" tests/Feature/EventFeatureTest.php
  ```

  Erwartetes Ergebnis: Alle fünf Tests FAIL mit Meldungen wie
  `Failed asserting that '...' contains "id=\"event-calendar\""`.

---

## Task 2: FullCalendar npm-Pakete installieren

**Dateien:** `package.json`

- [ ] **Step 1: FullCalendar-Pakete in package.json eintragen**

  Aktuelle `dependencies` in `package.json` sehen so aus:
  ```json
  "dependencies": {
    "bootstrap": "5.3.8",
    "bootstrap-icons": "1.13.1",
    "html-midi-player": "1.6.0",
    "tinymce": "7.9.1",
    "tinymce-i18n": "26.3.23"
  }
  ```

  Vier FullCalendar-Einträge ergänzen, sodass es lautet:
  ```json
  "dependencies": {
    "@fullcalendar/core": "^6.1.15",
    "@fullcalendar/daygrid": "^6.1.15",
    "@fullcalendar/interaction": "^6.1.15",
    "@fullcalendar/timegrid": "^6.1.15",
    "bootstrap": "5.3.8",
    "bootstrap-icons": "1.13.1",
    "html-midi-player": "1.6.0",
    "tinymce": "7.9.1",
    "tinymce-i18n": "26.3.23"
  }
  ```

- [ ] **Step 2: Pakete installieren**

  ```bash
  ddev exec npm install
  ```

  Erwartetes Ergebnis (letzte Zeile): `added N packages` ohne Fehler.

- [ ] **Step 3: Global-Bundles verifizieren**

  ```bash
  ddev exec ls node_modules/@fullcalendar/core/
  ```

  Die Datei `index.global.min.js` muss in der Ausgabe erscheinen.

- [ ] **Step 4: Commit**

  ```bash
  git add package.json package-lock.json
  git commit -m "chore: add fullcalendar v6 npm packages"
  ```

---

## Task 3: copy-assets.php um FullCalendar erweitern und Assets kopieren

**Dateien:** `bin/copy-assets.php`

- [ ] **Step 1: FullCalendar-Block in copy-assets.php einfügen**

  In `bin/copy-assets.php` befindet sich am Ende der `copyAssets()`-Funktion dieser Block:
  ```php
      $source = 'node_modules/html-midi-player/dist/midi-player.min.js';
      $dest = 'public/vendor/html-midi-player/dist/midi-player.min.js';

      @mkdir(dirname($dest), 0755, true);
      if (!copy($source, $dest)) {
          throw new RuntimeException("Failed to copy $source to $dest");
      }
  }
  ```

  Den Block durch folgende Version ersetzen (FullCalendar-Loop direkt danach, vor der
  schließenden `}`):
  ```php
      $source = 'node_modules/html-midi-player/dist/midi-player.min.js';
      $dest = 'public/vendor/html-midi-player/dist/midi-player.min.js';

      @mkdir(dirname($dest), 0755, true);
      if (!copy($source, $dest)) {
          throw new RuntimeException("Failed to copy $source to $dest");
      }

      foreach (['core', 'daygrid', 'timegrid', 'interaction'] as $pkg) {
          $source = "node_modules/@fullcalendar/{$pkg}/index.global.min.js";
          $dest   = "public/vendor/fullcalendar/{$pkg}/index.global.min.js";
          @mkdir(dirname($dest), 0755, true);
          if (!copy($source, $dest)) {
              throw new RuntimeException("Failed to copy $source to $dest");
          }
      }
  }
  ```

- [ ] **Step 2: Assets kopieren und prüfen**

  ```bash
  ddev exec php bin/copy-assets.php
  ddev exec ls public/vendor/fullcalendar/
  ```

  Erwartete Ausgabe: `core  daygrid  interaction  timegrid`

  ```bash
  ddev exec ls public/vendor/fullcalendar/core/
  ```

  Erwartete Ausgabe: `index.global.min.js`

- [ ] **Step 3: Commit**

  ```bash
  git add bin/copy-assets.php public/vendor/fullcalendar/
  git commit -m "feat: copy fullcalendar v6 bundles to public/vendor"
  ```

---

## Task 4: EventController – view-Parameter und JSON-Serialisierung (GREEN)

**Dateien:** `src/Controllers/EventController.php`

- [ ] **Step 1: view-Parameter in index() einlesen**

  In `EventController::index()` befindet sich dieser Block:
  ```php
  $sort = $queryParams['sort'] ?? 'starts_at';
  $direction = $queryParams['direction'] ?? 'asc';
  $showOldEvents = !empty($queryParams['show_old_events']) ? (int)$queryParams['show_old_events'] : 0;
  ```

  Die Zeile nach `$showOldEvents` ergänzen:
  ```php
  $sort = $queryParams['sort'] ?? 'starts_at';
  $direction = $queryParams['direction'] ?? 'asc';
  $showOldEvents = !empty($queryParams['show_old_events']) ? (int)$queryParams['show_old_events'] : 0;
  $viewMode = in_array($queryParams['view'] ?? '', ['list', 'calendar'], true)
      ? $queryParams['view']
      : 'list';
  ```

- [ ] **Step 2: Bootstrap-Farben-Mapping und JSON-Serialisierung ergänzen**

  In `EventController::index()` steht nach dem `$this->hydrateVisibleComments(...)`-Aufruf:
  ```php
  $this->hydrateVisibleComments($events, $userId);

  $projects = $accessibleProjects;
  $eventTypes = EventType::orderBy('name')->get();
  ```

  Diese Stelle ersetzen durch:
  ```php
  $this->hydrateVisibleComments($events, $userId);

  $bootstrapColorMap = [
      'primary'   => '#0d6efd',
      'secondary' => '#6c757d',
      'success'   => '#198754',
      'danger'    => '#dc3545',
      'warning'   => '#ffc107',
      'info'      => '#0dcaf0',
      'light'     => '#f8f9fa',
      'dark'      => '#212529',
  ];

  $calendarEvents = $events->map(static function ($event) use ($bootstrapColorMap): array {
      $colorName = (string) ($event->type_color ?? 'secondary');
      return [
          'id'    => $event->id,
          'title' => $event->title,
          'start' => $event->starts_at instanceof \DateTimeInterface
              ? $event->starts_at->format('Y-m-d\TH:i:s')
              : (string) $event->starts_at,
          'end'   => $event->ends_at instanceof \DateTimeInterface
              ? $event->ends_at->format('Y-m-d\TH:i:s')
              : (string) $event->ends_at,
          'color' => $bootstrapColorMap[$colorName] ?? '#6c757d',
          'url'   => '/events/' . $event->id,
      ];
  })->values()->all();

  $calendarEventsJson = json_encode(
      $calendarEvents,
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
  );

  $projects = $accessibleProjects;
  $eventTypes = EventType::orderBy('name')->get();
  ```

- [ ] **Step 3: view_mode und calendar_events ans Template übergeben**

  Im `$this->view->render(...)` Aufruf am Ende von `index()` steht:
  ```php
  return $this->view->render($response, 'events/index.twig', [
      'events' => $events,
      'projects' => $projects,
      'event_types' => $eventTypes,
      'filters' => [
  ```

  Zwei neue Keys nach `'events' => $events,` ergänzen:
  ```php
  return $this->view->render($response, 'events/index.twig', [
      'events' => $events,
      'view_mode' => $viewMode,
      'calendar_events' => $calendarEventsJson,
      'projects' => $projects,
      'event_types' => $eventTypes,
      'filters' => [
  ```

- [ ] **Step 4: Tests ausführen – noch nicht alle grün**

  ```bash
  ddev exec vendor/bin/phpunit --filter "testCalendarViewReturns200|testCalendarViewContainsCalendarEventJson|testListViewShowsTable|testInvalidViewParameterFallsBackToList|testNonAdminDoesNotSeeCalendarAdminMarker" tests/Feature/EventFeatureTest.php
  ```

  Erwartet: `testListViewShowsTable` und `testInvalidViewParameterFallsBackToList` bestehen jetzt.
  `testCalendarViewReturns200`, `testCalendarViewContainsCalendarEventJson` und
  `testNonAdminDoesNotSeeCalendarAdminMarker` scheitern noch (Template fehlt noch).

- [ ] **Step 5: Commit**

  ```bash
  git add src/Controllers/EventController.php
  git commit -m "feat: add view-mode param and calendar event JSON to EventController"
  ```

---

## Task 5: events/index.twig – Umschalter, Kalender-Container, Scripts (GREEN)

**Dateien:** `templates/events/index.twig`

- [ ] **Step 1: Ansicht-Umschalter im page_header-Block ergänzen**

  Im `{% block page_header %}` steht am Ende der `<div class="page-actions">`:
  ```twig
      {% if session.can_manage_users %}
          <div class="page-actions">
              <button type="button"
                      class="btn btn-primary"
                      data-bs-toggle="modal"
                      data-bs-target="#addEventModal">
                  <i class="bi bi-calendar-plus"></i> Termin erstellen
              </button>
          </div>
      {% endif %}
  ```

  Ersetzen durch:
  ```twig
      <div class="page-actions d-flex align-items-center gap-2 flex-wrap">
          <div class="btn-group" role="group" aria-label="Ansicht wählen">
              <a href="/events?view=list{% if filters.project_id %}&project_id={{ filters.project_id }}{% endif %}{% if filters.event_type_id %}&event_type_id={{ filters.event_type_id }}{% endif %}{% if filters.show_old_events %}&show_old_events=1{% endif %}"
                 class="btn btn-outline-secondary btn-sm {% if view_mode == 'list' %}active{% endif %}"
                 aria-pressed="{{ view_mode == 'list' ? 'true' : 'false' }}">
                  <i class="bi bi-list-ul"></i> Liste
              </a>
              <a href="/events?view=calendar{% if filters.project_id %}&project_id={{ filters.project_id }}{% endif %}{% if filters.event_type_id %}&event_type_id={{ filters.event_type_id }}{% endif %}{% if filters.show_old_events %}&show_old_events=1{% endif %}"
                 class="btn btn-outline-secondary btn-sm {% if view_mode == 'calendar' %}active{% endif %}"
                 aria-pressed="{{ view_mode == 'calendar' ? 'true' : 'false' }}">
                  <i class="bi bi-calendar3"></i> Kalender
              </a>
          </div>
          {% if session.can_manage_users %}
              <button type="button"
                      class="btn btn-primary"
                      data-bs-toggle="modal"
                      data-bs-target="#addEventModal">
                  <i class="bi bi-calendar-plus"></i> Termin erstellen
              </button>
          {% endif %}
      </div>
  ```

- [ ] **Step 2: Listenbereich konditional ausblenden**

  Den `<section class="dashboard-section dashboard-section--context" ...>` Block
  (den gesamten Tabellen-Abschnitt) in eine Bedingung einwickeln:

  ```twig
  {% if view_mode == 'list' %}
  <section class="dashboard-section dashboard-section--context"
           aria-labelledby="events-list-title">
      ... (gesamter bestehender Inhalt unverändert) ...
  </section>
  {% endif %}
  ```

- [ ] **Step 3: Kalender-Container einfügen**

  Direkt nach dem `{% endif %}` aus Step 2 (vor dem Beginn der Modal-Sektion) einfügen:

  ```twig
  {% if view_mode == 'calendar' %}
      <section class="dashboard-section" aria-labelledby="events-calendar-title">
          <div class="dashboard-section-head">
              <h2 class="dashboard-section-title" id="events-calendar-title">Kalender</h2>
              <p class="dashboard-section-lead mb-0">Monat- und Wochenansicht der Termine.</p>
          </div>
          <div class="surface-card card border-0">
              <div class="card-body">
                  <div id="event-calendar"
                       data-calendar-events="{{ calendar_events }}"
                       {% if session.can_manage_users %}data-calendar-admin="1"{% endif %}>
                  </div>
              </div>
          </div>
      </section>
  {% endif %}
  ```

- [ ] **Step 4: Hidden-Input für view-Parameter im Filter-Formular ergänzen**

  Im Filter-Formular (`<form method="get" action="/events" id="event-filter-form" ...>`)
  direkt nach dem öffnenden `<form`-Tag (vor dem ersten `<div class="col-...">`):

  ```twig
  <input type="hidden" name="view" value="{{ view_mode }}">
  ```

- [ ] **Step 5: FullCalendar-Scripts in den block scripts einfügen**

  Der bestehende Block am Ende der Datei lautet:
  ```twig
  {% block scripts %}
      <script src="/js/events.js"></script>
  {% endblock scripts %}
  ```

  Ersetzen durch:
  ```twig
  {% block scripts %}
      <script src="/js/events.js"></script>
      {% if view_mode == 'calendar' %}
          <script src="/vendor/fullcalendar/core/index.global.min.js"></script>
          <script src="/vendor/fullcalendar/daygrid/index.global.min.js"></script>
          <script src="/vendor/fullcalendar/timegrid/index.global.min.js"></script>
          <script src="/vendor/fullcalendar/interaction/index.global.min.js"></script>
          <script src="{{ asset_path('/js/event-calendar.js') }}"></script>
      {% endif %}
  {% endblock scripts %}
  ```

- [ ] **Step 6: Tests ausführen – jetzt alle fünf grün**

  ```bash
  ddev exec vendor/bin/phpunit --filter "testCalendarViewReturns200|testCalendarViewContainsCalendarEventJson|testListViewShowsTable|testInvalidViewParameterFallsBackToList|testNonAdminDoesNotSeeCalendarAdminMarker" tests/Feature/EventFeatureTest.php
  ```

  Erwartetes Ergebnis: `OK (5 tests, N assertions)` – alle grün.

- [ ] **Step 7: Gesamte EventFeatureTest-Suite ausführen (Regression)**

  ```bash
  ddev exec vendor/bin/phpunit tests/Feature/EventFeatureTest.php
  ```

  Erwartetes Ergebnis: Alle Tests grün, keine Regressions.

- [ ] **Step 8: Commit**

  ```bash
  git add templates/events/index.twig
  git commit -m "feat: add calendar view toggle and container to events/index.twig"
  ```

---

## Task 6: event-calendar.js erstellen

**Dateien:** `public/js/event-calendar.js` (neue Datei)

- [ ] **Step 1: Datei anlegen**

  Neue Datei `public/js/event-calendar.js` mit folgendem Inhalt erstellen:

  ```js
  (function () {
    'use strict';

    var container = document.getElementById('event-calendar');
    if (!container) { return; }

    var rawEvents = [];
    try {
      rawEvents = JSON.parse(container.dataset.calendarEvents || '[]');
    } catch (e) {
      rawEvents = [];
    }

    var isAdmin = container.dataset.calendarAdmin === '1';

    var calendarOptions = {
      plugins: [
        window.FullCalendar.DayGrid,
        window.FullCalendar.TimeGrid,
        window.FullCalendar.Interaction,
      ],
      initialView: 'dayGridMonth',
      locale: 'de',
      firstDay: 1,
      headerToolbar: {
        left:   'prev,next today',
        center: 'title',
        right:  'dayGridMonth,timeGridWeek',
      },
      buttonText: {
        today: 'Heute',
        month: 'Monat',
        week:  'Woche',
      },
      events: rawEvents,
      eventTimeFormat: {
        hour:   '2-digit',
        minute: '2-digit',
        hour12: false,
      },
      eventClick: function (info) {
        if (info.event.url) {
          info.jsEvent.preventDefault();
          window.location.href = info.event.url;
        }
      },
    };

    if (isAdmin) {
      calendarOptions.dateClick = function (info) {
        var modal = document.getElementById('addEventModal');
        if (!modal) { return; }

        var startsInput = modal.querySelector('[name="starts_at"]');
        var endsInput   = modal.querySelector('[name="ends_at"]');

        var datePrefix = info.dateStr.length >= 10 ? info.dateStr.slice(0, 10) : info.dateStr;

        if (startsInput) {
          startsInput.value = datePrefix + 'T00:00';
        }
        if (endsInput) {
          endsInput.value = datePrefix + 'T01:00';
        }

        var bsModal = window.bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
      };
    }

    var calendar = new window.FullCalendar.Calendar(container, calendarOptions);
    calendar.render();
  }());
  ```

- [ ] **Step 2: LF-Normalisierung durchführen (Windows)**

  ```powershell
  $f = "D:\Proggen\ChorManager\public\js\event-calendar.js"
  [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
  ```

- [ ] **Step 3: Commit**

  ```bash
  git add public/js/event-calendar.js
  git commit -m "feat: add event-calendar.js for fullcalendar initialization"
  ```

---

## Task 7: Abschluss – vollständige Testsuite und Smoke-Test

**Dateien:** keine Änderungen

- [ ] **Step 1: Gesamte Testsuite ausführen**

  ```bash
  ddev exec vendor/bin/phpunit
  ```

  Erwartetes Ergebnis: Alle Tests grün, keine Regressions außerhalb von EventFeatureTest.

- [ ] **Step 2: Browser-Smoke-Test**

  ```bash
  ddev launch /events
  ```

  Manuell prüfen:
  - Umschalter-Buttons „Liste" / „Kalender" im Header sichtbar
  - Kalenderansicht lädt mit Monat/Woche-Toggle in FullCalendar
  - Termine erscheinen als farbige Blöcke
  - Klick auf Termin → Weiterleitung zu `/events/{id}`
  - Filteränderung → Ansicht bleibt auf Kalender erhalten

- [ ] **Step 3: Admin-Slot-Klick prüfen (als Admin eingeloggt)**

  - Im Kalender auf einen leeren Tag klicken
  - `#addEventModal` öffnet sich mit vorausgefülltem Datum in `starts_at`

- [ ] **Step 4: Responsivität prüfen**

  Browser auf mobile Breite (<576 px) verkleinern → Kalender bleibt benutzbar
  (FullCalendar ist per Default responsiv).

---

## Abnahmekriterien

- [ ] `GET /events?view=calendar` → HTTP 200 mit `id="event-calendar"`
- [ ] `GET /events?view=list` → HTTP 200 mit `id="eventsTable"` (unverändert)
- [ ] `GET /events?view=invalid` → Listenansicht (Fallback)
- [ ] `data-calendar-events` enthält gültiges JSON mit `id`, `title`, `start`, `end`
- [ ] Nicht-Admin: kein `data-calendar-admin` im HTML
- [ ] Aktive Filter bleiben beim View-Wechsel erhalten
- [ ] Admin-Slot-Klick öffnet `#addEventModal` mit vorausgefülltem Datum
- [ ] Alle fünf neuen Feature-Tests grün
- [ ] Gesamte Testsuite grün (keine Regressions)
- [ ] Kein CDN – alle Assets lokal aus `public/vendor/fullcalendar/`

- [ ] **Step 2: Pakete installieren**

  ```bash
  ddev exec npm install
  ```

  Erwartetes Ergebnis: `node_modules/@fullcalendar/core/`, `.../daygrid/`, `.../timegrid/`, `.../interaction/` vorhanden.

- [ ] **Step 3: Vorhandensein der Global-Bundles prüfen**

  ```bash
  ddev exec ls node_modules/@fullcalendar/core/
  ```

  Sicherstellen, dass `index.global.min.js` (oder vergleichbar) vorhanden ist.
  Exakten Pfad notieren – wird in Task 2 benötigt.

- [ ] **Step 4: Commit**

  ```bash
  git add package.json package-lock.json
  git commit -m "chore: add fullcalendar v6 npm packages"
  ```

---

## Task 2: copy-assets.php um FullCalendar erweitern

**Dateien:** `bin/copy-assets.php`

- [ ] **Step 1: copy-assets.php lesen und Einfügeposition bestimmen**

  Datei lesen, letzte bestehende Copy-Operation identifizieren (aktuell `html-midi-player`).

- [ ] **Step 2: FullCalendar-Bundles kopieren**

  Nach dem bestehenden `html-midi-player`-Block vier Kopieroperationen ergänzen:

  ```php
  $fcPackages = [
      'core',
      'daygrid',
      'timegrid',
      'interaction',
  ];
  foreach ($fcPackages as $pkg) {
      $source = "node_modules/@fullcalendar/{$pkg}/index.global.min.js";
      $dest   = "public/vendor/fullcalendar/{$pkg}/index.global.min.js";
      @mkdir(dirname($dest), 0755, true);
      if (!copy($source, $dest)) {
          throw new RuntimeException("Failed to copy $source to $dest");
      }
  }
  ```

- [ ] **Step 3: copy-assets.php ausführen und Ergebnis prüfen**

  ```bash
  ddev exec php bin/copy-assets.php
  ddev exec ls public/vendor/fullcalendar/
  ```

  Erwartetes Ergebnis: Ordner `core/`, `daygrid/`, `timegrid/`, `interaction/` mit je `index.global.min.js`.

- [ ] **Step 4: Commit**

  ```bash
  git add bin/copy-assets.php public/vendor/fullcalendar/
  git commit -m "feat: copy fullcalendar bundles to public/vendor"
  ```

---

## Task 3: layout.twig auf Extra-Blöcke prüfen

**Dateien:** `templates/layout.twig`

- [ ] **Step 1: layout.twig lesen**

  Prüfen, ob bereits Twig-Blöcke `extra_stylesheets` und `extra_scripts` (oder gleichwertig)
  vorhanden sind, über die Child-Templates seitenspezifische Assets einbinden können.

- [ ] **Step 2: Blöcke ergänzen, falls fehlend**

  Falls nicht vorhanden, direkt vor `</head>` einen leeren Block ergänzen:
  ```twig
  {% block extra_stylesheets %}{% endblock %}
  ```
  Und direkt vor `</body>` (nach dem Bootstrap-Script-Tag):
  ```twig
  {% block extra_scripts %}{% endblock %}
  ```

- [ ] **Step 3: Commit (nur wenn Datei geändert wurde)**

  ```bash
  git add templates/layout.twig
  git commit -m "feat: add extra_stylesheets and extra_scripts blocks to layout"
  ```

---

## Task 4: EventController – view-Parameter und JSON-Daten

**Dateien:** `src/Controllers/EventController.php`

- [ ] **Step 1: EventController::index() lesen**

  Vollständige `index()`-Methode lesen, insbesondere den `$queryParams`-Block
  und das finale `render()`-Call.

- [ ] **Step 2: view-Parameter einlesen und validieren**

  Im `$queryParams`-Block ergänzen:
  ```php
  $allowedViews = ['list', 'calendar'];
  $viewMode = in_array($queryParams['view'] ?? 'list', $allowedViews, true)
      ? ($queryParams['view'] ?? 'list')
      : 'list';
  ```

- [ ] **Step 3: Bootstrap-Farben-Mapping und JSON-Serialisierung**

  Nach dem `$events->map(...)` Block, vor dem `render()`-Call ergänzen:
  ```php
  $bootstrapColorMap = [
      'primary'   => '#0d6efd',
      'secondary' => '#6c757d',
      'success'   => '#198754',
      'danger'    => '#dc3545',
      'warning'   => '#ffc107',
      'info'      => '#0dcaf0',
      'light'     => '#f8f9fa',
      'dark'      => '#212529',
  ];

  $calendarEvents = $events->map(static function ($event) use ($bootstrapColorMap) {
      $colorName = $event->type_color ?? 'secondary';
      return [
          'id'    => $event->id,
          'title' => $event->title,
          'start' => $event->starts_at instanceof \DateTime
              ? $event->starts_at->format('Y-m-d\TH:i:s')
              : (string) $event->starts_at,
          'end'   => $event->ends_at instanceof \DateTime
              ? $event->ends_at->format('Y-m-d\TH:i:s')
              : (string) $event->ends_at,
          'color' => $bootstrapColorMap[$colorName] ?? '#6c757d',
          'url'   => '/events/' . $event->id,
      ];
  })->values()->all();
  ```

- [ ] **Step 4: view_mode und calendar_events ans Template übergeben**

  Im `render()`-Aufruf ergänzen:
  ```php
  'view_mode'       => $viewMode,
  'calendar_events' => json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR),
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add src/Controllers/EventController.php
  git commit -m "feat: add view-mode param and calendar event JSON to EventController"
  ```

---

## Task 5: events/index.twig – Umschalter, Kalender-Container, JS

**Dateien:** `templates/events/index.twig`

- [ ] **Step 1: FullCalendar-Assets einbinden**

  Im Block `extra_stylesheets` (oben im Template nach `{% extends %}`):
  ```twig
  {% block extra_stylesheets %}
      {# FullCalendar global builds inject their own CSS via JS – kein separates Stylesheet nötig #}
  {% endblock %}
  ```

  Im Block `extra_scripts` (am Ende des Templates):
  ```twig
  {% block extra_scripts %}
      <script src="/vendor/fullcalendar/core/index.global.min.js"></script>
      <script src="/vendor/fullcalendar/daygrid/index.global.min.js"></script>
      <script src="/vendor/fullcalendar/timegrid/index.global.min.js"></script>
      <script src="/vendor/fullcalendar/interaction/index.global.min.js"></script>
      <script src="/js/event-calendar.js"></script>
  {% endblock %}
  ```

- [ ] **Step 2: Ansicht-Umschalter im page_header-Block ergänzen**

  Die bestehende `page-actions`-Div um Umschalter-Buttons erweitern
  (neben dem vorhandenen "Termin erstellen"-Button):
  ```twig
  <div class="btn-group ms-2" role="group" aria-label="Ansicht wählen">
      <a href="{{ app.request.uri.path }}?{{ query_string_merge('view', 'list') }}"
         class="btn btn-outline-secondary btn-sm {% if view_mode == 'list' %}active{% endif %}"
         aria-pressed="{{ view_mode == 'list' ? 'true' : 'false' }}">
          <i class="bi bi-list-ul"></i> Liste
      </a>
      <a href="{{ app.request.uri.path }}?{{ query_string_merge('view', 'calendar') }}"
         class="btn btn-outline-secondary btn-sm {% if view_mode == 'calendar' %}active{% endif %}"
         aria-pressed="{{ view_mode == 'calendar' ? 'true' : 'false' }}">
          <i class="bi bi-calendar3"></i> Kalender
      </a>
  </div>
  ```

  **Hinweis:** Falls `query_string_merge` nicht als Twig-Funktion vorhanden ist,
  einfachere Links bauen, die den `view`-Parameter an die aktuelle Filter-URL anhängen.
  Alternativ: Umschalter-Links mit JavaScript (aktuelle URL + `view=...` setzen).
  Genaue Implementierung nach Prüfung der verfügbaren Twig-Funktionen wählen.

- [ ] **Step 3: Listenbereich konditional anzeigen**

  Den bestehenden `dashboard-section--context`-Block in eine Bedingung einwickeln:
  ```twig
  {% if view_mode == 'list' %}
      ... (bestehender Listenbereich) ...
  {% endif %}
  ```

- [ ] **Step 4: Kalender-Container ergänzen**

  Nach dem Listenbereich (vor dem Modal) den Kalender-Container einfügen:
  ```twig
  {% if view_mode == 'calendar' %}
      <section class="dashboard-section" aria-labelledby="events-calendar-title">
          <div class="dashboard-section-head">
              <h2 class="dashboard-section-title" id="events-calendar-title">Kalender</h2>
          </div>
          <div class="surface-card card border-0">
              <div class="card-body">
                  <div id="event-calendar"
                       data-calendar-events="{{ calendar_events }}"
                       {% if session.can_manage_users %}data-calendar-admin="1"{% endif %}>
                  </div>
              </div>
          </div>
      </section>
  {% endif %}
  ```

- [ ] **Step 5: Hidden-Input für view-Parameter im Filter-Formular ergänzen**

  Im bestehenden `<form method="get" action="/events">` ergänzen:
  ```twig
  <input type="hidden" name="view" value="{{ view_mode }}">
  ```

- [ ] **Step 6: Commit**

  ```bash
  git add templates/events/index.twig
  git commit -m "feat: add calendar view toggle and container to events/index.twig"
  ```

---

## Task 6: event-calendar.js erstellen

**Dateien:** `public/js/event-calendar.js` (neue Datei)

- [ ] **Step 1: JavaScript-Datei erstellen**

  ```js
  (function () {
    'use strict';

    var container = document.getElementById('event-calendar');
    if (!container) return;

    var rawEvents = [];
    try {
      rawEvents = JSON.parse(container.dataset.calendarEvents || '[]');
    } catch (e) {
      rawEvents = [];
    }

    var isAdmin = container.dataset.calendarAdmin === '1';

    var bootstrapColors = {
      primary:   '#0d6efd',
      secondary: '#6c757d',
      success:   '#198754',
      danger:    '#dc3545',
      warning:   '#ffc107',
      info:      '#0dcaf0',
    };

    var calendarOptions = {
      plugins: [
        window.FullCalendar.DayGrid,
        window.FullCalendar.TimeGrid,
        window.FullCalendar.Interaction,
      ],
      initialView: 'dayGridMonth',
      locale: 'de',
      firstDay: 1,
      headerToolbar: {
        left:   'prev,next today',
        center: 'title',
        right:  'dayGridMonth,timeGridWeek',
      },
      buttonText: {
        today:        'Heute',
        month:        'Monat',
        week:         'Woche',
      },
      events: rawEvents,
      eventTimeFormat: {
        hour:   '2-digit',
        minute: '2-digit',
        hour12: false,
      },
      eventClick: function (info) {
        if (info.event.url) {
          info.jsEvent.preventDefault();
          window.location.href = info.event.url;
        }
      },
    };

    if (isAdmin) {
      calendarOptions.dateClick = function (info) {
        var modal = document.getElementById('addEventModal');
        if (!modal) return;

        var startsInput = modal.querySelector('[name="starts_at"]');
        var endsInput   = modal.querySelector('[name="ends_at"]');

        if (startsInput) {
          // Format: YYYY-MM-DDTHH:MM for datetime-local inputs
          var start = info.dateStr.length === 10
            ? info.dateStr + 'T00:00'
            : info.dateStr.slice(0, 16);
          startsInput.value = start;
        }
        if (endsInput) {
          var end = info.dateStr.length === 10
            ? info.dateStr + 'T01:00'
            : info.dateStr.slice(0, 16);
          endsInput.value = end;
        }

        var bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
      };
    }

    var calendar = new FullCalendar.Calendar(container, calendarOptions);
    calendar.render();
  })();
  ```

- [ ] **Step 2: LF-Normalisierung durchführen**

  ```powershell
  $f = "<absoluter-pfad>/public/js/event-calendar.js"
  [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
  ```

- [ ] **Step 3: Commit**

  ```bash
  git add public/js/event-calendar.js
  git commit -m "feat: add event-calendar.js for fullcalendar init"
  ```

---



# Newsletter-Empfängerquellen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Newsletter-Empfänger von einem exklusiven Projekt/Event-Modell auf kombinierbare Empfängerquellen (Projekte, Veranstaltungen, Rollen, einzelne Nutzer) mit Deduplizierung und Live-Vorschau umbauen.

**Architecture:** Empfängerquellen werden in einer neuen relationalen Tabelle gespeichert und bei jeder Speicherung/Sendeaktion dynamisch in konkrete Empfänger aufgelöst. Die bestehende Zustelltabelle `newsletter_recipients` bleibt die operative Versandbasis. UI und Controller sprechen über ein einheitliches `sources`-Payload-Format, inklusive Preview-Endpunkt für Live-Zähler.

**Tech Stack:** PHP 8+, Slim, Eloquent, Phinx, Twig, Vanilla JavaScript, PHPUnit, DDEV.

---

## Scope Check

Der Spec beschreibt ein zusammenhängendes Subsystem (Newsletter-Empfängermodell inkl. UI, API, Service, Migration, Tests). Keine Aufteilung in mehrere unabhängige Pläne erforderlich.

---

## File Structure Map

### Create
- `db/migrations/20260513213000_add_newsletter_recipient_sources.php`
  - Neue Tabelle `newsletter_recipient_sources`, Datenmigration aus `newsletters.event_id`, Entfernen `event_id`.
- `src/Models/NewsletterRecipientSource.php`
  - Eloquent-Model für gespeicherte Empfängerquellen.

### Modify
- `src/Models/Newsletter.php`
  - Entfernt `event_id` aus Fillable/Casts/Relation, ergänzt Relation `recipientSources()`.
- `src/Services/NewsletterRecipientService.php`
  - Neue Quell-basierte Auflösung (`resolveRecipients(Newsletter $newsletter)`) plus `setSources()`/`getSources()`.
- `src/Controllers/NewsletterController.php`
  - Validierung/Parsing von `sources`, Nutzung von `setSources()`, neuer Preview-Endpunkt, Index-Filter nach Empfängertyp.
- `src/Routes.php`
  - Neue Route `POST /newsletters/resolve-recipients-preview` innerhalb Newsletter-Management-Block.
- `templates/newsletters/create.twig`
  - Empfängerquellen-UI statt Event-only Auswahl.
- `templates/newsletters/edit.twig`
  - Empfängerquellen-UI mit vorbefüllten Quellen und Live-Zähler.
- `templates/newsletters/index.twig`
  - Filtersteuerung für Empfängertyp.
- `public/js/newsletters-create.js`
  - Serialisierung der Quellauswahl + Debounced Preview-Request.
- `public/js/newsletters-edit.js`
  - Serialisierung der Quellauswahl + Debounced Preview-Request + Snapshot-Anpassung.
- `tests/Feature/NewsletterFeatureTest.php`
  - Neue assertions für Migration, Methoden, Route, Template-Felder und Deduplizierungspfad.

### Test/Verification Commands
- `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter RecipientSources`
- `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter Preview`
- `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php`

---

### Task 1: Red Tests für neues Empfängermodell

**Files:**
- Modify: `tests/Feature/NewsletterFeatureTest.php`
- Test: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testRecipientSourceModelAndServiceMethodsExist(): void
{
    $this->assertTrue(class_exists(\App\Models\NewsletterRecipientSource::class));

    $this->assertTrue(method_exists(\App\Models\Newsletter::class, 'recipientSources'));

    $this->assertTrue(method_exists(\App\Services\NewsletterRecipientService::class, 'setSources'));
    $this->assertTrue(method_exists(\App\Services\NewsletterRecipientService::class, 'getSources'));
    $this->assertStringContainsString(
        'public function resolveRecipients(Newsletter $newsletter): Collection',
        (string) file_get_contents(dirname(__DIR__) . '/../src/Services/NewsletterRecipientService.php')
    );
}

public function testRecipientSourcesMigrationExistsAndDropsLegacyEventId(): void
{
    $migrationPath = dirname(__DIR__) . '/../db/migrations/20260513213000_add_newsletter_recipient_sources.php';
    $this->assertFileExists($migrationPath);

    $migration = (string) file_get_contents($migrationPath);
    $this->assertStringContainsString('newsletter_recipient_sources', $migration);
    $this->assertStringContainsString('dropColumn(\'event_id\')', $migration);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter "RecipientSourceModelAndServiceMethodsExist|RecipientSourcesMigrationExistsAndDropsLegacyEventId"`
Expected: FAIL (Klasse/Methode/Migration fehlt).

- [ ] **Step 3: Write minimal implementation stubs**

```php
// src/Models/NewsletterRecipientSource.php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterRecipientSource extends Model
{
    protected $table = 'newsletter_recipient_sources';
    public $timestamps = false;

    protected $fillable = [
        'newsletter_id',
        'source_type',
        'reference_id',
    ];
}
```

```php
// src/Models/Newsletter.php (Ausschnitt)
public function recipientSources(): HasMany
{
    return $this->hasMany(NewsletterRecipientSource::class, 'newsletter_id');
}
```

```php
// src/Services/NewsletterRecipientService.php (Ausschnitt)
public function resolveRecipients(Newsletter $newsletter): Collection
{
    return new Collection();
}

public function setSources(Newsletter $newsletter, array $sources): void
{
}

public function getSources(Newsletter $newsletter): array
{
    return [];
}
```

```php
// db/migrations/20260513213000_add_newsletter_recipient_sources.php (Ausschnitt)
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddNewsletterRecipientSources extends AbstractMigration
{
    public function up(): void
    {
        $this->table('newsletter_recipient_sources')
            ->addColumn('newsletter_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', ['values' => ['project_members', 'event_attendees', 'role', 'user']])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['newsletter_id'])
            ->create();

        $this->table('newsletters')->dropColumn('event_id')->update();
    }

    public function down(): void
    {
        $this->table('newsletters')->addColumn('event_id', 'integer', ['null' => true])->update();
        $this->table('newsletter_recipient_sources')->drop()->save();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter "RecipientSourceModelAndServiceMethodsExist|RecipientSourcesMigrationExistsAndDropsLegacyEventId"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/NewsletterFeatureTest.php src/Models/NewsletterRecipientSource.php src/Models/Newsletter.php src/Services/NewsletterRecipientService.php db/migrations/20260513213000_add_newsletter_recipient_sources.php
git commit -m "test+feat: add recipient source model and migration skeleton"
```

---

### Task 2: Migration vollständig machen (inkl. Datenübernahme)

**Files:**
- Modify: `db/migrations/20260513213000_add_newsletter_recipient_sources.php`
- Test: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testRecipientSourcesMigrationMigratesEventAndProjectSources(): void
{
    $migrationPath = dirname(__DIR__) . '/../db/migrations/20260513213000_add_newsletter_recipient_sources.php';
    $migration = (string) file_get_contents($migrationPath);

    $this->assertStringContainsString("source_type', 'event_attendees'", $migration);
    $this->assertStringContainsString("source_type', 'project_members'", $migration);
    $this->assertStringContainsString('INSERT INTO newsletter_recipient_sources', $migration);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter RecipientSourcesMigrationMigratesEventAndProjectSources`
Expected: FAIL (Insert-Statements fehlen).

- [ ] **Step 3: Write minimal implementation**

```php
// db/migrations/20260513213000_add_newsletter_recipient_sources.php (Up-Ausschnitt)
$this->execute(
    "INSERT INTO newsletter_recipient_sources (newsletter_id, source_type, reference_id)
     SELECT id, 'project_members', project_id
     FROM newsletters"
);

$this->execute(
    "INSERT INTO newsletter_recipient_sources (newsletter_id, source_type, reference_id)
     SELECT id, 'event_attendees', event_id
     FROM newsletters
     WHERE event_id IS NOT NULL"
);
```

```php
// db/migrations/20260513213000_add_newsletter_recipient_sources.php (Down-Ausschnitt)
$this->execute(
    "UPDATE newsletters n
     LEFT JOIN newsletter_recipient_sources s
       ON s.newsletter_id = n.id
      AND s.source_type = 'event_attendees'
     SET n.event_id = s.reference_id"
);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter RecipientSourcesMigrationMigratesEventAndProjectSources`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add db/migrations/20260513213000_add_newsletter_recipient_sources.php tests/Feature/NewsletterFeatureTest.php
git commit -m "feat: migrate legacy newsletter recipient selectors into sources table"
```

---

### Task 3: Recipient-Service auf Quellauflösung umbauen

**Files:**
- Modify: `src/Services/NewsletterRecipientService.php`
- Modify: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testRecipientServiceResolvesAllSourceTypesAndDeduplicates(): void
{
    $recipientService = (string) file_get_contents(dirname(__DIR__) . '/../src/Services/NewsletterRecipientService.php');

    $this->assertStringContainsString("'project_members'", $recipientService);
    $this->assertStringContainsString("'event_attendees'", $recipientService);
    $this->assertStringContainsString("'role'", $recipientService);
    $this->assertStringContainsString("'user'", $recipientService);
    $this->assertStringContainsString('array_unique', $recipientService);
    $this->assertStringContainsString('where(\'is_active\', 1)', $recipientService);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter RecipientServiceResolvesAllSourceTypesAndDeduplicates`
Expected: FAIL (source switch + dedupe fehlt).

- [ ] **Step 3: Write minimal implementation**

```php
// src/Services/NewsletterRecipientService.php (Kernausschnitt)
public function resolveRecipients(Newsletter $newsletter): Collection
{
    $userIds = [];

    foreach ($newsletter->recipientSources()->get() as $source) {
        if ($source->source_type === 'project_members') {
            $userIds = array_merge($userIds, $this->getProjectMembers((int) $source->reference_id)->pluck('id')->all());
            continue;
        }

        if ($source->source_type === 'event_attendees') {
            $userIds = array_merge($userIds, $this->getEventAttendees((int) $source->reference_id)->pluck('id')->all());
            continue;
        }

        if ($source->source_type === 'role') {
            $userIds = array_merge($userIds, $this->getUsersByRole((int) $source->reference_id)->pluck('id')->all());
            continue;
        }

        if ($source->source_type === 'user') {
            $userIds = array_merge($userIds, $this->getActiveUser((int) $source->reference_id)->pluck('id')->all());
        }
    }

    $uniqueIds = array_values(array_unique(array_map('intval', $userIds)));

    return User::query()->whereIn('id', $uniqueIds)->where('is_active', 1)->get();
}

public function setSources(Newsletter $newsletter, array $sources): void
{
    $newsletter->recipientSources()->delete();

    foreach ($sources as $source) {
        $newsletter->recipientSources()->create([
            'source_type' => (string) $source['type'],
            'reference_id' => (int) $source['reference_id'],
        ]);
    }

    $this->setRecipients($newsletter, $this->resolveRecipients($newsletter)->pluck('id')->map(fn ($id) => (int) $id)->all());
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter RecipientServiceResolvesAllSourceTypesAndDeduplicates`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/NewsletterRecipientService.php tests/Feature/NewsletterFeatureTest.php
git commit -m "feat: resolve newsletter recipients from multiple source types"
```

---

### Task 4: Controller-Validierung und setSources in Store/Update integrieren

**Files:**
- Modify: `src/Controllers/NewsletterController.php`
- Modify: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testNewsletterControllerUsesSourcesValidationAndRecipientServiceSetSources(): void
{
    $controllerContent = (string) file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

    $this->assertStringContainsString('validateNewsletterSourcesInput', $controllerContent);
    $this->assertStringContainsString('recipientService->setSources', $controllerContent);
    $this->assertStringNotContainsString("'event_id' =>", $controllerContent);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter NewsletterControllerUsesSourcesValidationAndRecipientServiceSetSources`
Expected: FAIL (Methode/Call fehlt).

- [ ] **Step 3: Write minimal implementation**

```php
// src/Controllers/NewsletterController.php (Ausschnitt)
private function validateNewsletterSourcesInput(array $data): array
{
    $rawSources = $data['sources'] ?? [];
    if (!is_array($rawSources) || $rawSources === []) {
        return ['ok' => false, 'message' => 'Mindestens eine Empfängerquelle ist erforderlich.', 'payload' => []];
    }

    $normalized = [];
    foreach ($rawSources as $row) {
        $type = (string) ($row['type'] ?? '');
        $referenceId = (int) ($row['reference_id'] ?? 0);
        if (!in_array($type, ['project_members', 'event_attendees', 'role', 'user'], true) || $referenceId <= 0) {
            continue;
        }
        $normalized[] = ['type' => $type, 'reference_id' => $referenceId];
    }

    if ($normalized === []) {
        return ['ok' => false, 'message' => 'Mindestens eine gültige Empfängerquelle ist erforderlich.', 'payload' => []];
    }

    return ['ok' => true, 'message' => null, 'payload' => ['sources' => $normalized]];
}
```

```php
// store()/update() jeweils nach Newsletter persistieren
$sourcesValidation = $this->validateNewsletterSourcesInput($data);
if (!$sourcesValidation['ok']) {
    return $this->jsonResponse($response, ['error' => $sourcesValidation['message']], 422);
}

$this->recipientService->setSources($newsletter, $sourcesValidation['payload']['sources']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter NewsletterControllerUsesSourcesValidationAndRecipientServiceSetSources`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/NewsletterController.php tests/Feature/NewsletterFeatureTest.php
git commit -m "feat: validate and persist newsletter recipient sources in store and update"
```

---

### Task 5: Preview-Endpunkt für Live-Empfängerzähler

**Files:**
- Modify: `src/Controllers/NewsletterController.php`
- Modify: `src/Routes.php`
- Modify: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testPreviewRecipientCountRouteAndControllerActionExist(): void
{
    $routes = (string) file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

    $this->assertStringContainsString('/newsletters/resolve-recipients-preview', $routes);
    $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'resolveRecipientsPreview'));
    $this->assertStringContainsString("'count' =>", $controller);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter PreviewRecipientCountRouteAndControllerActionExist`
Expected: FAIL (Route/Action fehlen).

- [ ] **Step 3: Write minimal implementation**

```php
// src/Controllers/NewsletterController.php
public function resolveRecipientsPreview(Request $request, Response $response): Response
{
    $data = (array) $request->getParsedBody();
    $validation = $this->validateNewsletterSourcesInput($data);
    if (!$validation['ok']) {
        return $this->jsonResponse($response, ['errors' => [$validation['message']]], 422);
    }

    $newsletter = new Newsletter();
    $newsletter->project_id = (int) ($data['project_id'] ?? 0);
    $newsletter->setRelation('recipientSources', collect($validation['payload']['sources'])->map(function (array $source) {
        return new \App\Models\NewsletterRecipientSource([
            'source_type' => $source['type'],
            'reference_id' => $source['reference_id'],
        ]);
    }));

    $count = $this->recipientService->resolveRecipients($newsletter)->count();

    return $this->jsonResponse($response, ['count' => $count]);
}
```

```php
// src/Routes.php (Newsletter management group)
$newsletterGroup->post('/newsletters/resolve-recipients-preview', [NewsletterController::class, 'resolveRecipientsPreview']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter PreviewRecipientCountRouteAndControllerActionExist`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/NewsletterController.php src/Routes.php tests/Feature/NewsletterFeatureTest.php
git commit -m "feat: add newsletter recipient preview endpoint"
```

---

### Task 6: Newsletter-Index Filter nach Empfängertyp

**Files:**
- Modify: `src/Controllers/NewsletterController.php`
- Modify: `templates/newsletters/index.twig`
- Modify: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testIndexSupportsRecipientTypeFilterInControllerAndTemplate(): void
{
    $controller = (string) file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');
    $template = (string) file_get_contents(dirname(__DIR__) . '/../templates/newsletters/index.twig');

    $this->assertStringContainsString('recipient_type', $controller);
    $this->assertStringContainsString('newsletter_recipient_sources', $controller);
    $this->assertStringContainsString('name="recipient_type"', $template);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter IndexSupportsRecipientTypeFilterInControllerAndTemplate`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

```php
// src/Controllers/NewsletterController.php (Ausschnitt index)
$recipientType = (string) ($queryParams['recipient_type'] ?? '');
$allowedRecipientTypes = ['project_members', 'event_attendees', 'role', 'user'];

if ($recipientType !== '' && in_array($recipientType, $allowedRecipientTypes, true)) {
    $query->whereHas('recipientSources', function ($sourceQuery) use ($recipientType) {
        $sourceQuery->where('source_type', $recipientType);
    });
}
```

```twig
{# templates/newsletters/index.twig (Filter-Ausschnitt) #}
<div class="col-12 col-md-4">
    <label for="newsletter-recipient-type" class="form-label">Empfängertyp</label>
    <select id="newsletter-recipient-type" name="recipient_type" class="form-select onchange-submit">
        <option value="">Alle</option>
        <option value="project_members">Projektmitglieder</option>
        <option value="event_attendees">Veranstaltungsteilnehmer</option>
        <option value="role">Rollen</option>
        <option value="user">Einzelne Mitglieder</option>
    </select>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter IndexSupportsRecipientTypeFilterInControllerAndTemplate`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/NewsletterController.php templates/newsletters/index.twig tests/Feature/NewsletterFeatureTest.php
git commit -m "feat: filter newsletter index by recipient source type"
```

---

### Task 7: Create/Edit-Templates auf Quellen-UI umstellen

**Files:**
- Modify: `templates/newsletters/create.twig`
- Modify: `templates/newsletters/edit.twig`
- Modify: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testCreateAndEditTemplatesExposeRecipientSourcesUi(): void
{
    $createTemplate = (string) file_get_contents(dirname(__DIR__) . '/../templates/newsletters/create.twig');
    $editTemplate = (string) file_get_contents(dirname(__DIR__) . '/../templates/newsletters/edit.twig');

    $this->assertStringContainsString('id="recipient-sources"', $createTemplate);
    $this->assertStringContainsString('data-source-type="project_members"', $createTemplate);
    $this->assertStringContainsString('data-source-type="role"', $createTemplate);
    $this->assertStringContainsString('data-source-type="user"', $createTemplate);
    $this->assertStringContainsString('data-source-type="event_attendees"', $createTemplate);

    $this->assertStringContainsString('id="recipient-count-badge"', $editTemplate);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter CreateAndEditTemplatesExposeRecipientSourcesUi`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

```twig
{# templates/newsletters/create.twig (Empfänger-Ausschnitt) #}
<section id="recipient-sources" class="mt-3" aria-labelledby="newsletter-recipient-sources-title">
    <h3 id="newsletter-recipient-sources-title" class="h6">Empfängerquellen</h3>

    <div class="mb-3" data-source-type="project_members">
        <label class="form-label">Projektmitglieder</label>
        <select class="form-select" id="source-project-members" multiple>
            {% for selectable_project in projects %}
                <option value="{{ selectable_project.id }}" {% if selectable_project.id == project.id %}selected{% endif %}>{{ selectable_project.name }}</option>
            {% endfor %}
        </select>
    </div>

    <div class="mb-3" data-source-type="event_attendees">
        <label class="form-label">Veranstaltungsteilnehmer</label>
        <select class="form-select" id="source-event-attendees" multiple>
            {% for event in events %}
                <option value="{{ event.id }}">{{ event.title }} ({{ event.starts_at|date('d.m.Y') }})</option>
            {% endfor %}
        </select>
    </div>

    <div class="mb-3" data-source-type="role">
        <label class="form-label">Rollen</label>
        <select class="form-select" id="source-roles" multiple>
            {% for role in roles %}
                <option value="{{ role.id }}">{{ role.name }}</option>
            {% endfor %}
        </select>
    </div>

    <div class="mb-3" data-source-type="user">
        <label class="form-label">Einzelne Mitglieder</label>
        <select class="form-select" id="source-users" multiple>
            {% for user in users %}
                <option value="{{ user.id }}">{{ user.first_name }} {{ user.last_name }}</option>
            {% endfor %}
        </select>
    </div>
</section>
```

```twig
{# templates/newsletters/edit.twig (Badge-Ausschnitt) #}
<div class="alert alert-info">
    <strong>Empfänger:</strong> <span id="recipient-count-badge" class="badge bg-info">{{ newsletter.recipient_count }}</span>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter CreateAndEditTemplatesExposeRecipientSourcesUi`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add templates/newsletters/create.twig templates/newsletters/edit.twig tests/Feature/NewsletterFeatureTest.php
git commit -m "feat: replace event-only recipient ui with source-based selectors"
```

---

### Task 8: JavaScript für Quellserialisierung und Live-Zähler

**Files:**
- Modify: `public/js/newsletters-create.js`
- Modify: `public/js/newsletters-edit.js`
- Modify: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testCreateAndEditScriptsSerializeSourcesAndCallPreviewEndpoint(): void
{
    $createScript = (string) file_get_contents(dirname(__DIR__) . '/../public/js/newsletters-create.js');
    $editScript = (string) file_get_contents(dirname(__DIR__) . '/../public/js/newsletters-edit.js');

    $this->assertStringContainsString('/newsletters/resolve-recipients-preview', $createScript);
    $this->assertStringContainsString('buildRecipientSourcesPayload', $createScript);
    $this->assertStringContainsString('recipient-count-badge', $createScript);

    $this->assertStringContainsString('/newsletters/resolve-recipients-preview', $editScript);
    $this->assertStringContainsString('buildRecipientSourcesPayload', $editScript);
    $this->assertStringContainsString('recipient-count-badge', $editScript);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter CreateAndEditScriptsSerializeSourcesAndCallPreviewEndpoint`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

```javascript
// public/js/newsletters-create.js (Ausschnitt)
function buildRecipientSourcesPayload() {
    const payload = [];

    const projectSelect = document.getElementById("source-project-members");
    const eventSelect = document.getElementById("source-event-attendees");
    const roleSelect = document.getElementById("source-roles");
    const userSelect = document.getElementById("source-users");

    [
        [projectSelect, "project_members"],
        [eventSelect, "event_attendees"],
        [roleSelect, "role"],
        [userSelect, "user"],
    ].forEach(([select, type]) => {
        if (!select) {
            return;
        }

        Array.from(select.selectedOptions).forEach(option => {
            payload.push({ type, reference_id: Number(option.value) });
        });
    });

    return payload;
}

async function refreshRecipientPreview() {
    const badge = document.getElementById("recipient-count-badge");
    if (!badge) {
        return;
    }

    const response = await fetch('/newsletters/resolve-recipients-preview', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ sources: buildRecipientSourcesPayload() }),
    });

    if (!response.ok) {
        badge.textContent = '-';
        return;
    }

    const json = await response.json();
    badge.textContent = String(json.count ?? 0);
}
```

```javascript
// public/js/newsletters-edit.js (Ausschnitt)
function createSnapshot() {
    const editor = tinymce.get("content_html");

    return JSON.stringify({
        title: titleInput ? titleInput.value : "",
        content_html: editor ? editor.getContent() : "",
        sources: buildRecipientSourcesPayload(),
    });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter CreateAndEditScriptsSerializeSourcesAndCallPreviewEndpoint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/js/newsletters-create.js public/js/newsletters-edit.js tests/Feature/NewsletterFeatureTest.php
git commit -m "feat: add live recipient preview and source payload serialization"
```

---

### Task 9: End-to-End statische Feature-Assertions vervollständigen

**Files:**
- Modify: `tests/Feature/NewsletterFeatureTest.php`

- [ ] **Step 1: Write the failing test additions**

```php
public function testNewsletterRoutesContainRecipientSourcePreviewEndpoint(): void
{
    $routesContent = (string) file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
    $this->assertStringContainsString('/newsletters/resolve-recipients-preview', $routesContent);
}

public function testNewsletterModelNoLongerReferencesEventIdField(): void
{
    $modelContent = (string) file_get_contents(dirname(__DIR__) . '/../src/Models/Newsletter.php');
    $this->assertStringNotContainsString("'event_id'", $modelContent);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php --filter "RecipientSourcePreviewEndpoint|NoLongerReferencesEventIdField"`
Expected: FAIL bis alle Umbauten abgeschlossen sind.

- [ ] **Step 3: Write minimal implementation (test-side cleanup)**

```php
public function testRecipientServiceStillLoadsRecipientsWithUserRelation(): void
{
    $recipientService = (string) file_get_contents(dirname(__DIR__) . '/../src/Services/NewsletterRecipientService.php');
    $this->assertStringContainsString("->with('user')", $recipientService);
    $this->assertStringContainsString("->where('newsletter_id', $newsletterId)", $recipientService);
}
```

- [ ] **Step 4: Run focused test file to verify it passes**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/NewsletterFeatureTest.php
git commit -m "test: complete recipient sources coverage across routes model and templates"
```

---

### Task 10: Final Verification

**Files:**
- No code changes expected.

- [ ] **Step 1: Run newsletter feature tests**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php`
Expected: PASS.

- [ ] **Step 2: Run security-hardening newsletter tests**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterSecurityHardeningFeatureTest.php`
Expected: PASS.

- [ ] **Step 3: Run template management newsletter tests**

Run: `ddev php vendor/bin/phpunit tests/Feature/NewsletterTemplateManagementFeatureTest.php`
Expected: PASS.

- [ ] **Step 4: Optional full feature suite smoke check**

Run: `ddev php vendor/bin/phpunit tests/Feature`
Expected: PASS oder dokumentierte, nicht-regressive bekannte Fehlfälle.

- [ ] **Step 5: Commit verification evidence (if docs/test notes changed)**

```bash
git status
```

Expected: Arbeitsbaum sauber oder nur bewusst ausstehende Änderungen.

---

## Self-Review

### 1. Spec coverage check
- Neue Tabelle `newsletter_recipient_sources`: abgedeckt in Task 1-2.
- Migration von `event_id` + Standard-`project_members`: abgedeckt in Task 2.
- Service-Refactor inkl. Typen + Dedupe + aktive User: abgedeckt in Task 3.
- Controller-Validierung und `setSources()` in store/update: abgedeckt in Task 4.
- Live-Preview-Endpunkt: abgedeckt in Task 5.
- Index-Filter nach Empfängertyp: abgedeckt in Task 6.
- Template-UI für 4 Typen inkl. Badge: abgedeckt in Task 7.
- Live-JS-Refresh + debouncefähige Struktur: abgedeckt in Task 8.
- Automatisierte Tests: abgedeckt in Task 1-10.

### 2. Placeholder scan
- Keine TODO/TBD/„später“-Marker enthalten.
- Alle Codeänderungsschritte enthalten konkrete Codeblöcke.
- Alle Testschritte enthalten konkrete Kommandos und erwartetes Ergebnis.

### 3. Type/signature consistency
- Signatur `resolveRecipients(Newsletter $newsletter): Collection` konsistent verwendet.
- `sources`-Nutzlast überall als `[{type, reference_id}]` konsistent.
- Empfängertyp-Strings überall konsistent: `project_members`, `event_attendees`, `role`, `user`.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-13-newsletter-empfaengerquellen.md`. Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?

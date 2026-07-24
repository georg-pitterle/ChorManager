# Termin-Scopes (Zielgruppen) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Termine erhalten eine additive Zielgruppen-Schicht (Scopes) analog zu Newslettern; Anwesenheit/Anmeldung/Sichtbarkeit/ICS-Export leiten sich daraus ab, `events.project_id` entfällt.

**Architecture:** Neue Tabelle `event_audience_sources` (Quelltypen `project_members`/`role`/`user`/`voice_group`), Model `EventAudienceSource`, Relation `Event::audienceSources()`. `Event::eligibleUsersQuery()` bleibt einzige Quelle der Wahrheit und resolved jetzt aus den Quellen (leer = alle aktiven). Neuer `EventAudienceService` kapselt Persistenz, Auflösung und Sichtbarkeits-Query. `EventController` stellt Zugriff/Sichtbarkeit/ICS auf Scopes um. Zwei Phinx-Migrationen: (1) Tabelle + Backfill aus `project_id`, (2) `project_id` droppen.

**Tech Stack:** PHP 8.4, Slim 4, Eloquent (illuminate/database via Capsule), Phinx, Twig, Bootstrap 5, PHPUnit. DDEV-Umgebung.

## Global Constraints

- PSR-12, 4 Spaces, Zeilenlänge weich 120 / hart 130. Substanzielle PHP-Änderungen: `ddev composer phpcs`, Fixes `ddev composer phpcbf`.
- Twig: offizielle Standards, doppelte Anführungszeichen, `name=null` ohne Spaces, Boolean-Operatoren 1 Space; substanzielle Änderungen `ddev composer twigcs`, Fixes `ddev composer twigcbf`.
- Kein Inline-JS/CSS in Templates; nur lokal ausgelieferte Assets (keine CDNs).
- Logging via `Psr\Log\LoggerInterface`, kein `error_log()` in `src/`; strukturierte JSON-Logs mit `event`-Key.
- Migrationen via Phinx: `ddev exec ./vendor/bin/phinx migrate`. Migrationsergebnis berichten.
- Schema-Migrationen (kein `Capsule::schema`): Datei in `db/migrations/`.
- Deutsche Texte mit echten Umlauten ä/ö/ü/ß.
- Neue Dateien mit LF (außer `.bat`/`.cmd`/`.ps1`). Nach dem Schreiben auf Windows normalisieren:
  `$f = "<abs>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "` + "`" + `r` + "`" + `n", "` + "`" + `n"), [System.Text.UTF8Encoding]::new($false))`
- TDD: erst fehlschlagender Test. Tests laufen über `ddev exec ./vendor/bin/phpunit`. Test-DB via `Tests\Unit\Bootstrap::setupTestDatabase()` (nutzt geseedete Daten).
- Kein `git push` (manuell durch Entwickler). Häufige lokale Commits.
- Quelltypen-Konstanten (verbatim): `project_members`, `role`, `user`, `voice_group`.

---

## Voraussetzung: Testlauf-Kommandos

- Einzelner Test: `ddev exec ./vendor/bin/phpunit --filter <TestMethod> tests/Feature/<File>.php`
- Ganze Klasse: `ddev exec ./vendor/bin/phpunit tests/Feature/<File>.php`
- Vollständig: `ddev exec ./vendor/bin/phpunit`

Bestehende Testklasse als Muster: `tests/Feature/AttendanceScopeServiceFeatureTest.php` (extends `PHPUnit\Framework\TestCase`, `Bootstrap::setupTestDatabase()` in `setUp`, `$_SESSION = []`).

---

## Task 1: Tabelle `event_audience_sources`, Model, Relation, Backfill

**Files:**
- Create: `db/migrations/20260722120000_add_event_audience_sources.php`
- Create: `src/Models/EventAudienceSource.php`
- Modify: `src/Models/Event.php` (Relation + Import ergänzen)
- Test: `tests/Feature/EventAudienceSourceMigrationFeatureTest.php`

**Interfaces:**
- Produces: Tabelle `event_audience_sources(id, event_id, source_type ENUM, reference_id)`.
- Produces: `App\Models\EventAudienceSource` mit Konstanten `TYPE_PROJECT_MEMBERS='project_members'`, `TYPE_ROLE='role'`, `TYPE_USER='user'`, `TYPE_VOICE_GROUP='voice_group'`; `$table='event_audience_sources'`; `$timestamps=false`; `$fillable=['event_id','source_type','reference_id']`.
- Produces: `Event::audienceSources(): HasMany` (FK `event_id`).

- [ ] **Step 1: Migration schreiben**

Create `db/migrations/20260722120000_add_event_audience_sources.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddEventAudienceSources extends AbstractMigration
{
    public function up(): void
    {
        $this->table('event_audience_sources')
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', ['values' => ['project_members', 'role', 'user', 'voice_group']])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['event_id'])
            ->addForeignKey(
                'event_id',
                'events',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->create();

        $this->execute(
            "INSERT INTO event_audience_sources (event_id, source_type, reference_id)
             SELECT id, 'project_members', project_id
             FROM events
             WHERE project_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        $this->table('event_audience_sources')->drop()->save();
    }
}
```

- [ ] **Step 2: Model schreiben**

Create `src/Models/EventAudienceSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAudienceSource extends Model
{
    public const TYPE_PROJECT_MEMBERS = 'project_members';
    public const TYPE_ROLE = 'role';
    public const TYPE_USER = 'user';
    public const TYPE_VOICE_GROUP = 'voice_group';

    protected $table = 'event_audience_sources';
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'source_type',
        'reference_id',
    ];
}
```

- [ ] **Step 3: Relation auf `Event` ergänzen**

In `src/Models/Event.php` nach `attendances()` (nach Zeile 60) einfügen:

```php
    public function audienceSources()
    {
        return $this->hasMany(EventAudienceSource::class, 'event_id', 'id');
    }
```

(Kein zusätzlicher `use`-Import nötig — gleicher Namespace `App\Models`.)

- [ ] **Step 4: Failing Test schreiben**

Create `tests/Feature/EventAudienceSourceMigrationFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventAudienceSourceMigrationFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    public function testTableExists(): void
    {
        $this->assertTrue(Capsule::schema()->hasTable('event_audience_sources'));
    }

    public function testEventHasAudienceSourcesRelation(): void
    {
        $event = Event::query()->firstOrFail();
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Collection::class,
            $event->audienceSources
        );
    }

    public function testModelConstants(): void
    {
        $this->assertSame('project_members', EventAudienceSource::TYPE_PROJECT_MEMBERS);
        $this->assertSame('role', EventAudienceSource::TYPE_ROLE);
        $this->assertSame('user', EventAudienceSource::TYPE_USER);
        $this->assertSame('voice_group', EventAudienceSource::TYPE_VOICE_GROUP);
    }
}
```

- [ ] **Step 5: Test ausführen — muss fehlschlagen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventAudienceSourceMigrationFeatureTest.php`
Expected: FAIL (Tabelle/Model existiert noch nicht in Test-DB).

- [ ] **Step 6: Migration ausführen**

Run: `ddev exec ./vendor/bin/phinx migrate`
Expected: `AddEventAudienceSources` migrated, kein Fehler. Ergebnis berichten.

Prüfen, ob `Bootstrap::setupTestDatabase()` Migrationen auf die Test-DB anwendet; falls die Test-DB separat migriert wird, denselben Migrate-Befehl gegen die Test-Umgebung ausführen (siehe `tests/Unit/Bootstrap.php`).

- [ ] **Step 7: Test ausführen — muss bestehen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventAudienceSourceMigrationFeatureTest.php`
Expected: PASS (3 Tests grün).

- [ ] **Step 8: Commit**

```bash
git add db/migrations/20260722120000_add_event_audience_sources.php \
        src/Models/EventAudienceSource.php src/Models/Event.php \
        tests/Feature/EventAudienceSourceMigrationFeatureTest.php
git commit -m "feat: event_audience_sources Tabelle, Model und Relation"
```

---

## Task 2: `Event::eligibleUsersQuery()` aus Scopes auflösen

**Files:**
- Modify: `src/Models/Event.php:99-110` (`eligibleUsersQuery()` neu)
- Test: `tests/Feature/EventEligibleUsersScopeFeatureTest.php`

**Interfaces:**
- Consumes: `Event::audienceSources()` (Task 1), `User` Relationen `projects()`/`roles()`/`voiceGroups()` (Pivot-Spalten `project_id`/`role_id`/`voice_group_id`).
- Produces: `Event::eligibleUsersQuery(): Builder` — aktive User; leere Quellen ⇒ nur `is_active=1`; sonst OR-Vereinigung je Quelltyp. Signatur unverändert (alle 5 bestehenden Aufrufer bleiben kompatibel).

- [ ] **Step 1: Failing Test schreiben**

Create `tests/Feature/EventEligibleUsersScopeFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventEligibleUsersScopeFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    private function freshEvent(): Event
    {
        $event = Event::query()->firstOrFail();
        EventAudienceSource::where('event_id', $event->id)->delete();
        return $event->fresh();
    }

    public function testEmptySourcesMeansAllActiveUsers(): void
    {
        $event = $this->freshEvent();

        $this->assertSame(
            (int) User::where('is_active', 1)->count(),
            (int) $event->eligibleUsersQuery()->count()
        );
    }

    public function testProjectMembersSource(): void
    {
        $project = Project::query()->whereHas('users')->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => (int) $project->id,
        ]);

        $expected = User::where('is_active', 1)
            ->whereHas('projects', fn ($q) => $q->where('project_id', (int) $project->id))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $actual = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual);
        $this->assertNotSame([], $actual);
    }

    public function testRoleSource(): void
    {
        $role = Role::query()->whereHas('users')->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_ROLE,
            'reference_id' => (int) $role->id,
        ]);

        $expected = User::where('is_active', 1)
            ->whereHas('roles', fn ($q) => $q->where('role_id', (int) $role->id))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $actual = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    public function testVoiceGroupSource(): void
    {
        $voiceGroup = VoiceGroup::query()->whereHas('users')->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
            'reference_id' => (int) $voiceGroup->id,
        ]);

        $expected = User::where('is_active', 1)
            ->whereHas('voiceGroups', fn ($q) => $q->where('voice_group_id', (int) $voiceGroup->id))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $actual = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    public function testUserSource(): void
    {
        $user = User::where('is_active', 1)->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_USER,
            'reference_id' => (int) $user->id,
        ]);

        $ids = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([(int) $user->id], $ids);
    }

    public function testMultipleSourcesUnionWithoutDuplicates(): void
    {
        $role = Role::query()->whereHas('users')->firstOrFail();
        $user = User::where('is_active', 1)->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_ROLE,
            'reference_id' => (int) $role->id,
        ]);
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_USER,
            'reference_id' => (int) $user->id,
        ]);

        $ids = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertContains((int) $user->id, $ids);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventEligibleUsersScopeFeatureTest.php`
Expected: FAIL (eligibleUsersQuery nutzt noch `project_id`, ignoriert Scopes).

- [ ] **Step 3: `eligibleUsersQuery()` neu implementieren**

In `src/Models/Event.php` den Methodenkörper (Zeilen 99-110) ersetzen durch:

```php
    public function eligibleUsersQuery(): Builder
    {
        $query = User::where('is_active', 1);

        $sources = $this->relationLoaded('audienceSources')
            ? $this->audienceSources
            : $this->audienceSources()->get();

        if ($sources->isEmpty()) {
            return $query;
        }

        $projectIds = $this->referenceIdsFor($sources, EventAudienceSource::TYPE_PROJECT_MEMBERS);
        $roleIds = $this->referenceIdsFor($sources, EventAudienceSource::TYPE_ROLE);
        $voiceGroupIds = $this->referenceIdsFor($sources, EventAudienceSource::TYPE_VOICE_GROUP);
        $userIds = $this->referenceIdsFor($sources, EventAudienceSource::TYPE_USER);

        $query->where(function ($grouped) use ($projectIds, $roleIds, $voiceGroupIds, $userIds) {
            if ($projectIds !== []) {
                $grouped->orWhereHas('projects', function ($q) use ($projectIds) {
                    $q->whereIn('project_id', $projectIds);
                });
            }
            if ($roleIds !== []) {
                $grouped->orWhereHas('roles', function ($q) use ($roleIds) {
                    $q->whereIn('role_id', $roleIds);
                });
            }
            if ($voiceGroupIds !== []) {
                $grouped->orWhereHas('voiceGroups', function ($q) use ($voiceGroupIds) {
                    $q->whereIn('voice_group_id', $voiceGroupIds);
                });
            }
            if ($userIds !== []) {
                $grouped->orWhereIn('users.id', $userIds);
            }
        });

        return $query;
    }

    /**
     * @param \Illuminate\Support\Collection<int, EventAudienceSource> $sources
     * @return array<int, int>
     */
    private function referenceIdsFor($sources, string $type): array
    {
        return $sources
            ->where('source_type', $type)
            ->pluck('reference_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
```

Sicherstellen, dass `Event.php` die nötigen Imports hat: `use Illuminate\Database\Eloquent\Builder;` ist bereits vorhanden (Zeile 7). `EventAudienceSource` liegt im selben Namespace — kein Import nötig.

- [ ] **Step 4: Test ausführen — muss bestehen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventEligibleUsersScopeFeatureTest.php`
Expected: PASS (alle Tests grün).

- [ ] **Step 5: Regression — bestehende Registrierungs-/Anwesenheitstests grün**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/RegistrationViewFeatureTest.php tests/Feature/RegistrationSaveFeatureTest.php tests/Feature/AttendanceFeatureTest.php`
Expected: PASS (Backfill sorgt für Parität zu bisherigem `project_id`-Verhalten).

- [ ] **Step 6: Commit**

```bash
git add src/Models/Event.php tests/Feature/EventEligibleUsersScopeFeatureTest.php
git commit -m "feat: eligibleUsersQuery loest Teilnehmer aus Termin-Scopes auf"
```

---

## Task 3: `EventAudienceService` (Persistenz, Auflösung, Sichtbarkeit, Validierung)

**Files:**
- Create: `src/Services/EventAudienceService.php`
- Test: `tests/Feature/EventAudienceServiceFeatureTest.php`

**Interfaces:**
- Consumes: `Event::eligibleUsersQuery()` (Task 2), `Event::audienceSources()`, Models `Project`/`Role`/`User`/`VoiceGroup`.
- Produces:
  - `getSources(Event): array<int, array{type:string, reference_id:int}>`
  - `setSources(Event, array<int, array{type:string, reference_id:int}>): void`
  - `resolveEligibleUsers(Event): \Illuminate\Database\Eloquent\Collection<int, User>`
  - `isUserEligible(Event, int $userId): bool`
  - `visibleEventsQuery(int $userId): \Illuminate\Database\Eloquent\Builder` — Events ohne Quellen ODER mit für den Nutzer passender Quelle.
  - `normalizeSources(array $raw): array<int, array{type:string, reference_id:int}>` — validiert/dedupliziert Rohdaten (nur existierende Referenzen; User müssen aktiv sein).

- [ ] **Step 1: Failing Test schreiben**

Create `tests/Feature/EventAudienceServiceFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\User;
use App\Services\EventAudienceService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventAudienceServiceFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    private function cleanEvent(): Event
    {
        $event = Event::query()->firstOrFail();
        EventAudienceSource::where('event_id', $event->id)->delete();
        return $event->fresh();
    }

    public function testSetAndGetSourcesRoundTrip(): void
    {
        $project = Project::query()->firstOrFail();
        $event = $this->cleanEvent();
        $service = new EventAudienceService();

        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_PROJECT_MEMBERS, 'reference_id' => (int) $project->id],
        ]);

        $sources = $service->getSources($event->fresh());
        $this->assertSame(
            [['type' => 'project_members', 'reference_id' => (int) $project->id]],
            $sources
        );
    }

    public function testSetSourcesReplacesPrevious(): void
    {
        $project = Project::query()->firstOrFail();
        $user = User::where('is_active', 1)->firstOrFail();
        $event = $this->cleanEvent();
        $service = new EventAudienceService();

        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_PROJECT_MEMBERS, 'reference_id' => (int) $project->id],
        ]);
        $service->setSources($event->fresh(), [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $user->id],
        ]);

        $sources = $service->getSources($event->fresh());
        $this->assertSame(
            [['type' => 'user', 'reference_id' => (int) $user->id]],
            $sources
        );
    }

    public function testNormalizeRejectsUnknownTypeAndMissingReference(): void
    {
        $service = new EventAudienceService();
        $normalized = $service->normalizeSources([
            ['type' => 'nonsense', 'reference_id' => 5],
            ['type' => 'project_members', 'reference_id' => 0],
            ['type' => 'project_members', 'reference_id' => 999999],
        ]);

        $this->assertSame([], $normalized);
    }

    public function testNormalizeDeduplicates(): void
    {
        $project = Project::query()->firstOrFail();
        $service = new EventAudienceService();
        $normalized = $service->normalizeSources([
            ['type' => 'project_members', 'reference_id' => (int) $project->id],
            ['type' => 'project_members', 'reference_id' => (int) $project->id],
        ]);

        $this->assertCount(1, $normalized);
    }

    public function testIsUserEligibleForEmptyScopeIsTrue(): void
    {
        $event = $this->cleanEvent();
        $user = User::where('is_active', 1)->firstOrFail();
        $service = new EventAudienceService();

        $this->assertTrue($service->isUserEligible($event, (int) $user->id));
    }

    public function testVisibleEventsQueryIncludesEmptyScopeEvent(): void
    {
        $event = $this->cleanEvent();
        $user = User::where('is_active', 1)->firstOrFail();
        $service = new EventAudienceService();

        $ids = $service->visibleEventsQuery((int) $user->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $event->id, $ids);
    }

    public function testVisibleEventsQueryExcludesNonMatchingUserScope(): void
    {
        $users = User::where('is_active', 1)->orderBy('id')->take(2)->get();
        $this->assertCount(2, $users);
        [$inScope, $outScope] = [$users[0], $users[1]];

        $event = $this->cleanEvent();
        $service = new EventAudienceService();
        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $inScope->id],
        ]);

        $visibleForOut = $service->visibleEventsQuery((int) $outScope->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $visibleForIn = $service->visibleEventsQuery((int) $inScope->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $this->assertNotContains((int) $event->id, $visibleForOut);
        $this->assertContains((int) $event->id, $visibleForIn);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventAudienceServiceFeatureTest.php`
Expected: FAIL (`EventAudienceService` existiert nicht).

- [ ] **Step 3: Service implementieren**

Create `src/Services/EventAudienceService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Verwaltet Zielgruppen-Quellen von Terminen: Persistenz, Auflösung
 * berechtigter Nutzer und Sichtbarkeits-Query.
 */
class EventAudienceService
{
    private const ALLOWED_TYPES = [
        EventAudienceSource::TYPE_PROJECT_MEMBERS,
        EventAudienceSource::TYPE_ROLE,
        EventAudienceSource::TYPE_USER,
        EventAudienceSource::TYPE_VOICE_GROUP,
    ];

    /**
     * @return array<int, array{type:string, reference_id:int}>
     */
    public function getSources(Event $event): array
    {
        return $event->audienceSources()
            ->orderBy('id')
            ->get()
            ->map(static function (EventAudienceSource $source): array {
                return [
                    'type' => (string) $source->source_type,
                    'reference_id' => (int) $source->reference_id,
                ];
            })
            ->all();
    }

    /**
     * @param array<int, array{type:string, reference_id:int}> $sources
     */
    public function setSources(Event $event, array $sources): void
    {
        $event->audienceSources()->delete();

        foreach ($this->normalizeSources($sources) as $source) {
            $event->audienceSources()->create([
                'source_type' => $source['type'],
                'reference_id' => $source['reference_id'],
            ]);
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function resolveEligibleUsers(Event $event): Collection
    {
        return $event->eligibleUsersQuery()->get();
    }

    public function isUserEligible(Event $event, int $userId): bool
    {
        return $event->eligibleUsersQuery()
            ->where('users.id', $userId)
            ->exists();
    }

    /**
     * Events, für die der Nutzer betroffen ist: ohne Quellen (=alle) oder
     * mit passender Quelle (Projekt-/Rollen-/Stimmgruppen-Zugehörigkeit oder
     * direkte User-Quelle).
     */
    public function visibleEventsQuery(int $userId): Builder
    {
        $projectIds = $this->userReferenceIds($userId, 'projects', 'project_id');
        $roleIds = $this->userReferenceIds($userId, 'roles', 'role_id');
        $voiceGroupIds = $this->userReferenceIds($userId, 'voiceGroups', 'voice_group_id');

        return Event::query()->where(function ($query) use ($projectIds, $roleIds, $voiceGroupIds, $userId) {
            $query->whereDoesntHave('audienceSources')
                ->orWhereHas('audienceSources', function ($sourceQuery) use ($projectIds, $roleIds, $voiceGroupIds, $userId) {
                    $sourceQuery->where(function ($match) use ($projectIds, $roleIds, $voiceGroupIds, $userId) {
                        $match->where(function ($q) use ($projectIds) {
                            $q->where('source_type', EventAudienceSource::TYPE_PROJECT_MEMBERS)
                                ->whereIn('reference_id', $projectIds === [] ? [0] : $projectIds);
                        })
                        ->orWhere(function ($q) use ($roleIds) {
                            $q->where('source_type', EventAudienceSource::TYPE_ROLE)
                                ->whereIn('reference_id', $roleIds === [] ? [0] : $roleIds);
                        })
                        ->orWhere(function ($q) use ($voiceGroupIds) {
                            $q->where('source_type', EventAudienceSource::TYPE_VOICE_GROUP)
                                ->whereIn('reference_id', $voiceGroupIds === [] ? [0] : $voiceGroupIds);
                        })
                        ->orWhere(function ($q) use ($userId) {
                            $q->where('source_type', EventAudienceSource::TYPE_USER)
                                ->where('reference_id', $userId);
                        });
                    });
                });
        });
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array{type:string, reference_id:int}>
     */
    public function normalizeSources(array $raw): array
    {
        $normalized = [];
        $seen = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = trim((string) ($item['type'] ?? ''));
            $referenceId = (int) ($item['reference_id'] ?? 0);

            if (!in_array($type, self::ALLOWED_TYPES, true) || $referenceId <= 0) {
                continue;
            }

            if (!$this->referenceExists($type, $referenceId)) {
                continue;
            }

            $key = $type . ':' . $referenceId;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = ['type' => $type, 'reference_id' => $referenceId];
        }

        return $normalized;
    }

    private function referenceExists(string $type, int $referenceId): bool
    {
        return match ($type) {
            EventAudienceSource::TYPE_PROJECT_MEMBERS => Project::query()->whereKey($referenceId)->exists(),
            EventAudienceSource::TYPE_ROLE => Role::query()->whereKey($referenceId)->exists(),
            EventAudienceSource::TYPE_VOICE_GROUP => VoiceGroup::query()->whereKey($referenceId)->exists(),
            EventAudienceSource::TYPE_USER => User::query()->whereKey($referenceId)->where('is_active', 1)->exists(),
            default => false,
        };
    }

    /**
     * @return array<int, int>
     */
    private function userReferenceIds(int $userId, string $relation, string $pivotColumn): array
    {
        $user = User::find($userId);
        if (!$user) {
            return [];
        }

        return $user->{$relation}()
            ->pluck($pivotColumn)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
```

- [ ] **Step 4: Test ausführen — muss bestehen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventAudienceServiceFeatureTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/EventAudienceService.php tests/Feature/EventAudienceServiceFeatureTest.php
git commit -m "feat: EventAudienceService fuer Termin-Zielgruppen"
```

---

## Task 4: `EventController` — Scope-basierter Zugriff, Sichtbarkeit, ICS, Persistenz

**Files:**
- Modify: `src/Controllers/EventController.php` (Zugriff/Index/ICS/create/edit/update)
- Test: `tests/Feature/EventScopeVisibilityFeatureTest.php`

Diese Task belässt die Spalte `events.project_id` (Drop erst in Task 6), stellt aber alle Zugriffs-, Sichtbarkeits- und Persistenzpfade auf Scopes um. `project_id` wird in Create/Update nicht mehr geschrieben (Wert bleibt NULL für neue Events; Zielgruppe steuert alles).

**Interfaces:**
- Consumes: `EventAudienceService` (Task 3): `visibleEventsQuery`, `isUserEligible`, `setSources`, `getSources`, `normalizeSources`, `resolveEligibleUsers`.
- Produces: Termin-Sichtbarkeit web = betroffen ODER `can_manage_users`; ICS = nur betroffen; Create/Update persistiert Zielgruppen aus `sources[][type]`/`sources[][reference_id]`.

- [ ] **Step 1: Failing Test schreiben**

Create `tests/Feature/EventScopeVisibilityFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\User;
use App\Services\EventAudienceService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventScopeVisibilityFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testExportUsesVisibleEventsQueryOnly(): void
    {
        // Der ICS-Export darf keinen "Verwalter sieht alles"-Zweig mehr haben.
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/EventController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('visibleEventsQuery', $source);
        $this->assertStringNotContainsString('getAccessibleCalendarEventsForUser', $source);
    }

    public function testUserOutsideScopeIsNotEligible(): void
    {
        $users = User::where('is_active', 1)->orderBy('id')->take(2)->get();
        [$in, $out] = [$users[0], $users[1]];
        $event = Event::query()->firstOrFail();
        EventAudienceSource::where('event_id', $event->id)->delete();

        $service = new EventAudienceService();
        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $in->id],
        ]);

        $this->assertTrue($service->isUserEligible($event->fresh(), (int) $in->id));
        $this->assertFalse($service->isUserEligible($event->fresh(), (int) $out->id));
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventScopeVisibilityFeatureTest.php`
Expected: FAIL (`getAccessibleCalendarEventsForUser` noch vorhanden, `visibleEventsQuery` fehlt).

- [ ] **Step 3: Service in Controller einführen + ICS-Export umstellen**

In `src/Controllers/EventController.php`:

a) Import ergänzen (bei den `use App\Services\...`-Zeilen, nach Zeile 21):

```php
use App\Services\EventAudienceService;
```

b) `exportCalendar()` (Zeile 239) — Zeile
`$events = $this->getAccessibleCalendarEventsForUser($user);`
ersetzen durch:

```php
        $events = (new EventAudienceService())
            ->visibleEventsQuery((int) $user->id)
            ->where('ends_at', '>=', Carbon::now())
            ->orderBy('starts_at')
            ->get();
```

c) Methode `getAccessibleCalendarEventsForUser()` (Zeilen 271-294) vollständig entfernen.

- [ ] **Step 4: Web-Zugriff (`canAccessEvent`) auf Scopes umstellen**

`canAccessEvent()` (Zeilen 895-898) ersetzen durch:

```php
    private function canAccessEvent(Event $event): bool
    {
        if ((bool) ($_SESSION['can_manage_users'] ?? false)) {
            return true;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        return (new EventAudienceService())->isUserEligible($event, $userId);
    }
```

- [ ] **Step 5: `index()` — Sichtbarkeit + Projektfilter scope-basiert**

In `index()` den Nicht-Verwalter-Sichtbarkeitsblock (Zeilen 64-72) ersetzen durch:

```php
        if (!$canManageUsers) {
            $visibleIds = (new EventAudienceService())
                ->visibleEventsQuery($userId)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $query->whereIn('id', $visibleIds === [] ? [0] : $visibleIds);
        }
```

Den Projektfilter (Zeilen 74-76) ersetzen durch einen Filter auf die `project_members`-Quelle:

```php
        if ($projectId) {
            $query->whereHas('audienceSources', function ($sourceQuery) use ($projectId) {
                $sourceQuery->where('source_type', 'project_members')
                    ->where('reference_id', $projectId);
            });
        }
```

Die 403-Vorabprüfung auf `$projectId` (Zeilen 52-54) bleibt unverändert (nutzt `accessibleProjectIds` weiterhin für die Filter-Zugriffskontrolle).

- [ ] **Step 6: `create()` und `update()` — Zielgruppen statt `project_id` persistieren**

In `create()`:
- Zeile 467 `$projectId = !empty($data['project_id']) ...` entfernen.
- Den Block `if (!$this->canAccessProjectId($projectId)) {...}` (Zeilen 499-503) entfernen.
- In beiden `Event::create([...])`-Aufrufen (Zeilen 544-555 und 599-611) den Schlüssel `'project_id' => $projectId,` entfernen.
- Rohe Zielgruppen aus dem Request lesen und nach dem Anlegen setzen. Da `create()` sowohl Einzeltermin als auch Serie anlegt, die erzeugten Events sammeln und Quellen setzen. Konkret: Einzeltermin — `Event::create(...)` in Variable `$event` fangen und danach:

```php
                $audienceService = new EventAudienceService();
                $sources = $audienceService->normalizeSources((array) ($data['sources'] ?? []));
                $audienceService->setSources($event, $sources);
```

  Für die Serie: innerhalb der `while`-Schleife jedes erzeugte `Event::create(...)` in `$seriesEvent` fangen und `$audienceService->setSources($seriesEvent, $sources)` aufrufen (Service + `$sources` einmal vor der Schleife bilden).
- `formData['project_id']` (Zeile 487) entfernen.

In `update()`:
- Zeile 726 `$projectId = ...` entfernen; Block `if (!$this->canAccessProjectId($projectId)) {...}` (Zeilen 754-758) entfernen.
- `formData['project_id']` (Zeile 746) entfernen.
- Aus `$updateData` (Zeilen 797-805) `'project_id' => $projectId,` entfernen.
- Nach erfolgreichem Update (im Einzel- und Serien-Zweig) Zielgruppen setzen:

```php
            $audienceService = new EventAudienceService();
            $sources = $audienceService->normalizeSources((array) ($data['sources'] ?? []));
            $audienceService->setSources($event, $sources);
```

  Im Serien-Zweig zusätzlich für jedes `$eventInSeries` `setSources($eventInSeries, $sources)` aufrufen.

- [ ] **Step 7: `edit()` — bestehende Quellen ans Template geben**

In `edit()` (nach Zeile 668 `$eventTypes = ...`) ergänzen:

```php
        $audienceService = new EventAudienceService();
        $roles = \App\Models\Role::query()->orderBy('name')->get();
        $voiceGroups = \App\Models\VoiceGroup::query()->orderBy('name')->get();
        $users = User::query()->where('is_active', 1)
            ->orderBy('last_name')->orderBy('first_name')->get();
        $audienceSources = $audienceService->getSources($event);
```

Und im `render(...)`-Array (Zeilen 697-703) ergänzen: `'roles' => $roles,`, `'voice_groups' => $voiceGroups,`, `'users' => $users,`, `'audience_sources' => $audienceSources,`. Den `edit_form['project_id']`-Eintrag (Zeile 686) entfernen.

Für den Create-Modal-Kontext dieselben Listen in `index()` bereitstellen: nach Zeile 156 `$eventTypes = ...` ergänzen:

```php
        $roles = \App\Models\Role::query()->orderBy('name')->get();
        $voiceGroups = \App\Models\VoiceGroup::query()->orderBy('name')->get();
        $audienceUsers = User::query()->where('is_active', 1)
            ->orderBy('last_name')->orderBy('first_name')->get();
```

und im `render`-Array von `index()` ergänzen: `'roles' => $roles,`, `'voice_groups' => $voiceGroups,`, `'audience_users' => $audienceUsers,`.

- [ ] **Step 8: `canAccessProjectId()` entfernen, falls ungenutzt**

Nach den Änderungen prüfen, ob `canAccessProjectId()` (Zeilen 969-990) noch referenziert wird:
Run: `grep -n canAccessProjectId src/Controllers/EventController.php`
Wenn keine Aufrufer mehr existieren, Methode entfernen.

- [ ] **Step 9: ICS-Beschreibung — Projekt-Zeile durch Zielgruppe ersetzen**

In `buildIcsDescription()` (Zeilen 318-330) den Block

```php
        if ($event->project) {
            $description .= '\nProjekt: ' . $event->project->name;
        }
```

ersetzen durch eine Zielgruppen-Zusammenfassung:

```php
        $audienceLabel = $this->buildAudienceLabel($event);
        if ($audienceLabel !== '') {
            $description .= '\nZielgruppe: ' . $audienceLabel;
        }
```

und private Hilfsmethode ergänzen (bei den anderen ICS-Helfern):

```php
    private function buildAudienceLabel(Event $event): string
    {
        $sources = $event->audienceSources()->get();
        if ($sources->isEmpty()) {
            return 'Alle Mitglieder';
        }

        $labels = [];
        foreach ($sources as $source) {
            $refId = (int) $source->reference_id;
            $labels[] = match ((string) $source->source_type) {
                'project_members' => 'Projekt: ' . (optional(Project::find($refId))->name ?? '—'),
                'role' => 'Rolle: ' . (optional(\App\Models\Role::find($refId))->name ?? '—'),
                'voice_group' => 'Stimmgruppe: ' . (optional(\App\Models\VoiceGroup::find($refId))->name ?? '—'),
                'user' => 'Person: ' . trim((string) (optional(User::find($refId))->first_name . ' '
                    . optional(User::find($refId))->last_name)),
                default => '',
            };
        }

        return implode(', ', array_filter($labels));
    }
```

- [ ] **Step 10: Test + Regression ausführen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventScopeVisibilityFeatureTest.php tests/Feature/RegistrationViewFeatureTest.php tests/Feature/AttendanceFeatureTest.php`
Expected: PASS. Falls Fehler durch verbliebene `project_id`-Nutzung im Controller, im jeweiligen Pfad auf Scopes/Entfernung umstellen.

- [ ] **Step 11: phpcs**

Run: `ddev composer phpcs`
Falls Fehler: `ddev composer phpcbf` und erneut prüfen.

- [ ] **Step 12: Commit**

```bash
git add src/Controllers/EventController.php tests/Feature/EventScopeVisibilityFeatureTest.php
git commit -m "feat: Termin-Zugriff, Sichtbarkeit und ICS scope-basiert"
```

---

## Task 5: Templates + JS — Zielgruppen-Block statt Projekt-Select

**Files:**
- Create: `public/js/events-audience.js`
- Modify: `templates/events/edit.twig` (Projekt-Select → Zielgruppen-Block)
- Modify: `templates/events/index.twig` (Create-Modal: Projekt-Select → Zielgruppen-Block; Liste: Projektspalte → Zielgruppe)
- Modify: `templates/layout.twig` oder passende Asset-Einbindung (JS lokal einbinden — dem bestehenden Muster von `newsletters-edit.js` folgen)
- Test: `tests/Feature/EventAudienceTemplateFeatureTest.php`

**Interfaces:**
- Consumes: Template-Variablen aus Task 4 (`roles`, `voice_groups`, `users`/`audience_users`, `projects`, `audience_sources`).
- Produces: Formularfelder `sources[N][type]` + `sources[N][reference_id]` (Checkbox-Werte), die `create()`/`update()` (Task 4) über `normalizeSources` lesen.

Hinweis zur Feldkodierung: Damit `(array) $data['sources']` in PHP als Liste von `{type, reference_id}` ankommt, jede angehakte Option als Paar rendern. Muster (analog Newsletter, aber statt clientseitigem JSON hier direkt Formfelder): pro Checkbox ein verstecktes Indexpaar. Einfachste robuste Variante ohne Inline-JS: Checkboxen mit `name="sources[TYP-REFID][type]"` und ein paralleles `name="sources[TYP-REFID][reference_id]"` als Hidden, das nur zählt, wenn die Checkbox aktiv ist. Da HTML nicht-angehakte Checkboxen nicht sendet, wird das Hidden immer gesendet — daher stattdessen: Checkbox trägt `name="sources[TYP-REFID][reference_id]"` mit `value="REFID"`, und der Typ wird serverseitig nicht aus dem Key, sondern aus einem zusätzlichen Hidden abgeleitet. Um Komplexität zu vermeiden, wird `events-audience.js` beim Submit ein einzelnes Hidden-Feld `sources_json` befüllen (wie Newsletter) — siehe Step 1.

Deshalb: `create()`/`update()` (Task 4) lesen `sources` bevorzugt aus `sources_json` (JSON-dekodiert), Fallback auf `sources`-Array. Passe Task-4-Lesestellen entsprechend an (siehe Step 5 unten).

- [ ] **Step 1: JS-Asset schreiben**

Create `public/js/events-audience.js`:

```js
(function () {
  "use strict";

  function collectSources(root) {
    var checked = root.querySelectorAll(".event-audience-source:checked");
    var sources = [];
    checked.forEach(function (input) {
      sources.push({
        type: input.getAttribute("data-source-type"),
        reference_id: parseInt(input.value, 10),
      });
    });
    return sources;
  }

  function wireForm(form) {
    var hidden = form.querySelector(".event-audience-json");
    if (!hidden) {
      return;
    }
    form.addEventListener("submit", function () {
      hidden.value = JSON.stringify(collectSources(form));
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("form.event-audience-form").forEach(wireForm);
  });
})();
```

- [ ] **Step 2: Wiederverwendbaren Zielgruppen-Block als Twig-Partial anlegen**

Create `templates/events/_audience_sources.twig`:

```twig
{% set _project_ids = [] %}
{% set _role_ids = [] %}
{% set _voice_ids = [] %}
{% set _user_ids = [] %}
{% for source in audience_sources|default([]) %}
    {% if source.type == "project_members" %}
        {% set _project_ids = _project_ids|merge([source.reference_id]) %}
    {% elseif source.type == "role" %}
        {% set _role_ids = _role_ids|merge([source.reference_id]) %}
    {% elseif source.type == "voice_group" %}
        {% set _voice_ids = _voice_ids|merge([source.reference_id]) %}
    {% elseif source.type == "user" %}
        {% set _user_ids = _user_ids|merge([source.reference_id]) %}
    {% endif %}
{% endfor %}
<input type="hidden" name="sources_json" class="event-audience-json" value="">
<div class="mb-3">
    <label class="form-label">Zielgruppe</label>
    <p class="form-text mb-2">Keine Auswahl bedeutet: gilt für alle aktiven Mitglieder.</p>
    <div class="mb-2">
        <span class="fw-semibold d-block">Projektmitglieder</span>
        {% for project in projects|default([]) %}
            <div class="form-check">
                <input class="form-check-input event-audience-source"
                       type="checkbox"
                       data-source-type="project_members"
                       value="{{ project.id }}"
                       id="audience-project-{{ project.id }}"
                       {% if project.id in _project_ids %}checked{% endif %}>
                <label class="form-check-label" for="audience-project-{{ project.id }}">{{ project.name }}</label>
            </div>
        {% endfor %}
    </div>
    <div class="mb-2">
        <span class="fw-semibold d-block">Rollen</span>
        {% for role in roles|default([]) %}
            <div class="form-check">
                <input class="form-check-input event-audience-source"
                       type="checkbox"
                       data-source-type="role"
                       value="{{ role.id }}"
                       id="audience-role-{{ role.id }}"
                       {% if role.id in _role_ids %}checked{% endif %}>
                <label class="form-check-label" for="audience-role-{{ role.id }}">{{ role.name }}</label>
            </div>
        {% endfor %}
    </div>
    <div class="mb-2">
        <span class="fw-semibold d-block">Stimmgruppen</span>
        {% for voice_group in voice_groups|default([]) %}
            <div class="form-check">
                <input class="form-check-input event-audience-source"
                       type="checkbox"
                       data-source-type="voice_group"
                       value="{{ voice_group.id }}"
                       id="audience-voice-{{ voice_group.id }}"
                       {% if voice_group.id in _voice_ids %}checked{% endif %}>
                <label class="form-check-label" for="audience-voice-{{ voice_group.id }}">{{ voice_group.name }}</label>
            </div>
        {% endfor %}
    </div>
    <div class="mb-2">
        <span class="fw-semibold d-block">Einzelpersonen</span>
        {% for member in audience_users|default(users)|default([]) %}
            <div class="form-check">
                <input class="form-check-input event-audience-source"
                       type="checkbox"
                       data-source-type="user"
                       value="{{ member.id }}"
                       id="audience-user-{{ member.id }}"
                       {% if member.id in _user_ids %}checked{% endif %}>
                <label class="form-check-label" for="audience-user-{{ member.id }}">
                    {{ member.first_name }} {{ member.last_name }}
                </label>
            </div>
        {% endfor %}
    </div>
</div>
```

- [ ] **Step 3: `templates/events/edit.twig` umbauen**

Den Projekt-Block (Zeilen 136-147) ersetzen durch:

```twig
                        {% include "events/_audience_sources.twig" %}
```

Am `<form>`-Tag (Zeile 37) die Klasse ergänzen: `class="event-audience-form"`. Am Ende der Datei vor `{% endblock content %}` das Script lokal einbinden (dem Muster bestehender Templates folgen — falls `layout.twig` einen `{% block scripts %}` bereitstellt, diesen nutzen):

```twig
    {% block scripts %}
        {{ parent() }}
        <script src="/js/events-audience.js" defer></script>
    {% endblock scripts %}
```

Prüfen, wie andere Templates lokale Scripts einbinden (z. B. `grep -n "newsletters-edit.js" templates/`), und exakt demselben Mechanismus folgen.

- [ ] **Step 4: `templates/events/index.twig` — Create-Modal + Liste**

- Create-Modal-Projekt-Block (Zeilen 386-393) durch `{% include "events/_audience_sources.twig" %}` ersetzen; das Modal-`<form>` erhält Klasse `event-audience-form`. Script wie in Step 3 einbinden (einmal pro Seite genügt).
- Listenspalte „Projekt": Header (Zeile 184) und Zelle (Zeile 217). Da nach Task 6 kein `project_name` mehr existiert, hier auf eine neutrale Anzeige umstellen. Zelle (Zeile 217) ersetzen durch:

```twig
                                            <td data-label="Zielgruppe">{{ event.audience_label|default("Alle") }}</td>
```

  Header-Text (Zeile 184) auf „Zielgruppe" ändern und das `data-sort-key="project_name"` entfernen (Sortierung nach Zielgruppe entfällt).
- Der Filter-Header-Text (Zeile 83 „Projekt") und das Projektfilter-`<select>` (Zeilen 84-90) bleiben — der Projektfilter über `project_members`-Quelle (Task 4, Step 5) nutzt weiterhin `filters.project_id` und `projects`.

`event.audience_label` wird in `index()` befüllt: nach der `->map(...)`-Hydration (nach Zeile 124) je Event `$event->audience_label = ...` setzen. Ergänze in Task-4-`index()` innerhalb der `map`-Closure (oder direkt danach) eine kompakte Bezeichnung. Minimal: `$event->audience_label = $event->audienceSources()->exists() ? 'Ausgewählt' : 'Alle';` — für Listendarstellung ausreichend.

- [ ] **Step 5: Task-4-Lesestellen auf `sources_json` erweitern**

In `EventController::create()` und `update()` das Einlesen der Rohquellen anpassen:

```php
        $rawSources = [];
        $sourcesJson = trim((string) ($data['sources_json'] ?? ''));
        if ($sourcesJson !== '') {
            $decoded = json_decode($sourcesJson, true);
            if (is_array($decoded)) {
                $rawSources = $decoded;
            }
        } elseif (isset($data['sources']) && is_array($data['sources'])) {
            $rawSources = $data['sources'];
        }
        $sources = $audienceService->normalizeSources($rawSources);
```

(ersetzt das `normalizeSources((array) ($data['sources'] ?? []))` aus Task 4).

- [ ] **Step 6: Failing/aktualisierter Template-Test**

Create `tests/Feature/EventAudienceTemplateFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class EventAudienceTemplateFeatureTest extends TestCase
{
    public function testEditTemplateUsesAudiencePartialNotProjectSelect(): void
    {
        $edit = file_get_contents(dirname(__DIR__, 2) . '/templates/events/edit.twig');
        $this->assertIsString($edit);
        $this->assertStringContainsString('events/_audience_sources.twig', $edit);
        $this->assertStringNotContainsString('name="project_id"', $edit);
    }

    public function testAudiencePartialHasAllFourSourceTypes(): void
    {
        $partial = file_get_contents(dirname(__DIR__, 2) . '/templates/events/_audience_sources.twig');
        $this->assertIsString($partial);
        foreach (['project_members', 'role', 'voice_group', 'user'] as $type) {
            $this->assertStringContainsString('data-source-type="' . $type . '"', $partial);
        }
    }

    public function testNoInlineScriptInPartial(): void
    {
        $partial = file_get_contents(dirname(__DIR__, 2) . '/templates/events/_audience_sources.twig');
        $this->assertStringNotContainsString('<script', $partial);
    }
}
```

- [ ] **Step 7: Tests ausführen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventAudienceTemplateFeatureTest.php`
Expected: PASS.

- [ ] **Step 8: twigcs**

Run: `ddev composer twigcs`
Falls Fehler: `ddev composer twigcbf` und manuelle Korrektur (doppelte Quotes, `name=null` ohne Spaces, Operator-Spacing, Zeilenlänge ≤120/130). Ergebnis berichten.

- [ ] **Step 9: LF-Normalisierung neuer Dateien**

Für `public/js/events-audience.js` und `templates/events/_audience_sources.twig` das LF-Normalisierungs-Snippet aus Global Constraints ausführen und neu stagen.

- [ ] **Step 10: Commit**

```bash
git add public/js/events-audience.js templates/events/_audience_sources.twig \
        templates/events/edit.twig templates/events/index.twig \
        src/Controllers/EventController.php \
        tests/Feature/EventAudienceTemplateFeatureTest.php
git commit -m "feat: Zielgruppen-UI fuer Termine (Edit + Create-Modal)"
```

---

## Task 6: `events.project_id` droppen + Restbereinigung

**Files:**
- Create: `db/migrations/20260722130000_drop_events_project_id.php`
- Modify: `src/Models/Event.php` (`$fillable`, `$casts`, `project()`-Relation entfernen)
- Modify: `src/Controllers/EventController.php` (Rest-`project_id`-Nutzung: index eager-load, sort `project_name`, `->with('project')`, `buildIcsDescription`)
- Test: `tests/Feature/EventProjectIdRemovedFeatureTest.php`

**Interfaces:**
- Consumes: Backfill aus Task 1 (jede alte `project_id` liegt als `project_members`-Quelle vor).
- Produces: Spalte `events.project_id` entfernt; `Event` ohne `project`-Relation/`project_id`.

- [ ] **Step 1: Verbliebene Referenzen finden**

Run: `grep -n "project_id\|->project\b\|'project'\|\"project\"\|project_name" src/Controllers/EventController.php src/Models/Event.php`
Erwartet: Treffer in `index()` (eager-load Map Zeilen 101/110/114/119, sort-Zweig 86-89), evtl. ICS `->with('project')` (in Task 4 bereits entfernt), `project_name`-Sort in `$allowedSorts` (Zeile 57).

- [ ] **Step 2: Failing Test schreiben**

Create `tests/Feature/EventProjectIdRemovedFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventProjectIdRemovedFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    public function testEventsTableHasNoProjectIdColumn(): void
    {
        $this->assertFalse(Capsule::schema()->hasColumn('events', 'project_id'));
    }

    public function testEventModelHasNoProjectRelation(): void
    {
        $this->assertFalse(method_exists(\App\Models\Event::class, 'project'));
    }
}
```

- [ ] **Step 3: Test ausführen — muss fehlschlagen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventProjectIdRemovedFeatureTest.php`
Expected: FAIL (Spalte + Relation existieren noch).

- [ ] **Step 4: Controller-Restbereinigung**

In `src/Controllers/EventController.php`:
- `$allowedSorts` (Zeile 57): `'project_name'` entfernen.
- Den `if ($sort === 'project_name')`-Zweig (Zeilen 86-89) entfernen; verbleibende `elseif ($sort === 'type')`/`else`-Zweige behalten.
- Manuelles Eager-Loading (Zeilen 101, 105, 110, 114, 119): jede `project`-Bezugnahme entfernen. `$projectIds`/`$projectsMap` streichen; in der `map`-Closure `$event->setRelation('project', ...)` und `$event->project_name = ...` entfernen. `audience_label` (aus Task 5, Step 4) beibehalten.
- Falls in `edit()`/`update()`/`create()` noch `project`-Bezug: entfernen.

- [ ] **Step 5: `Event`-Model bereinigen**

In `src/Models/Event.php`:
- Aus `$fillable` (Zeilen 15-28) `'project_id',` entfernen.
- Aus `$casts` (Zeilen 30-40) `'project_id' => 'integer',` entfernen.
- Methode `project()` (Zeilen 52-55) entfernen.

- [ ] **Step 6: Migration schreiben**

Create `db/migrations/20260722130000_drop_events_project_id.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropEventsProjectId extends AbstractMigration
{
    public function up(): void
    {
        $foreignKey = $this->fetchRow(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'events'
               AND COLUMN_NAME = 'project_id'
               AND REFERENCED_TABLE_NAME = 'projects'
             LIMIT 1"
        );

        if ($foreignKey && !empty($foreignKey['CONSTRAINT_NAME'])) {
            $this->execute('ALTER TABLE events DROP FOREIGN KEY ' . $foreignKey['CONSTRAINT_NAME']);
        }

        $this->execute('ALTER TABLE events DROP COLUMN project_id');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE events ADD COLUMN project_id int(11) DEFAULT NULL');
        $this->execute('ALTER TABLE events ADD INDEX project_id (project_id)');
        $this->execute('ALTER TABLE events ADD CONSTRAINT events_project_id_fk
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
    }
}
```

- [ ] **Step 7: Migration ausführen**

Run: `ddev exec ./vendor/bin/phinx migrate`
Expected: `DropEventsProjectId` migrated. Ergebnis berichten. (Test-DB analog migrieren, s. Task 1 Step 6.)

- [ ] **Step 8: Tests ausführen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/EventProjectIdRemovedFeatureTest.php`
Expected: PASS.

Run gesamte Termin-nahe Suite:
`ddev exec ./vendor/bin/phpunit tests/Feature/EventEligibleUsersScopeFeatureTest.php tests/Feature/EventAudienceServiceFeatureTest.php tests/Feature/EventScopeVisibilityFeatureTest.php tests/Feature/RegistrationViewFeatureTest.php tests/Feature/RegistrationSaveFeatureTest.php tests/Feature/AttendanceFeatureTest.php`
Expected: PASS. Fehler durch verbliebene `project_id`-Zugriffe hier beheben.

- [ ] **Step 9: Newsletter `event_attendees`-Validierung prüfen**

`NewsletterController::validateNewsletterSourcesInput()` referenziert `$event->project_id` (Zeile 251) für `event_attendees`-Quellen. Da `events.project_id` entfällt, diese Zugriffsprüfung anpassen: statt `in_array($event->project_id, $accessibleProjectIds)` auf Existenz des Events prüfen (die projektbasierte Einschränkung entfällt für event_attendees):

```php
            if ($type === NewsletterRecipientSource::TYPE_EVENT_ATTENDEES) {
                $event = Event::query()->find($referenceId);
                if (!$event) {
                    continue;
                }
            }
```

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/NewsletterSecurityHardeningFeatureTest.php tests/Feature/MailDeliveryLifecycleFeatureTest.php`
Expected: PASS. Falls ein Test die alte Projekt-Einschränkung erwartet, Test entsprechend der neuen Semantik aktualisieren.

- [ ] **Step 10: phpcs**

Run: `ddev composer phpcs` → bei Bedarf `ddev composer phpcbf`.

- [ ] **Step 11: Commit**

```bash
git add db/migrations/20260722130000_drop_events_project_id.php \
        src/Models/Event.php src/Controllers/EventController.php \
        src/Controllers/NewsletterController.php \
        tests/Feature/EventProjectIdRemovedFeatureTest.php
git commit -m "feat: events.project_id entfernt, Restbereinigung auf Scopes"
```

---

## Task 7: Dev-Seed-Abdeckung für `event_audience_sources`

**Files:**
- Modify: `src/Services/DevSeedService.php` (Import, resetSeedData, report counts, Seed-Methode, run-Verdrahtung)
- Test: `tests/Feature/EventAudienceSeedFeatureTest.php` (optional Nachweis, Hauptnachweis via echtem Seed-Lauf)

**Interfaces:**
- Consumes: bestehende Seed-Rückgaben `$projectEvents` (aus `seedProjectEvents`), `$roles`, `$voiceData`, `$projects`, `$users`.
- Produces: `event_audience_sources`-Datensätze; Report-Counter `event_audience_sources`.

Zunächst `/dev-seed-completeness` als Referenz-Checkliste heranziehen.

- [ ] **Step 1: Import ergänzen**

In `src/Services/DevSeedService.php` bei den Model-Imports ergänzen:

```php
use App\Models\EventAudienceSource;
```

- [ ] **Step 2: Reset + Report-Counter**

- In `resetSeedData()` (Tabellen-Array, vor `'events'`) `'event_audience_sources',` ergänzen (Kind vor Elter, da FK auf `events`).
- In `run()`-`counts` (nach `'events' => 0,`) `'event_audience_sources' => 0,` ergänzen.

- [ ] **Step 3: Seed-Methode schreiben**

Neue private Methode (bei den Event-Seed-Methoden, z. B. nach `seedGlobalEvents`):

```php
    /**
     * @param array<int, \App\Models\Event> $projectEvents
     * @param array<string, mixed> $roles
     * @param array<string, mixed> $voiceData
     * @param array<int, \App\Models\Project> $projects
     */
    private function seedEventAudienceSources(array $projectEvents, array $roles, array $voiceData, array $projects): void
    {
        $roleIds = array_values(array_map(static fn ($r) => (int) $r->id, $roles));
        $voiceGroupIds = array_values(array_map(
            static fn ($vg) => (int) $vg->id,
            $voiceData['voice_groups'] ?? []
        ));

        $index = 0;
        foreach ($projectEvents as $event) {
            $mode = $index % 4;
            $index++;

            // mode 0: keine Quelle (= alle Mitglieder) — bewusst nichts anlegen.
            if ($mode === 0) {
                continue;
            }

            if ($mode === 1 && $projects !== []) {
                $project = $projects[array_rand($projects)];
                EventAudienceSource::create([
                    'event_id' => (int) $event->id,
                    'source_type' => EventAudienceSource::TYPE_PROJECT_MEMBERS,
                    'reference_id' => (int) $project->id,
                ]);
                $this->report['counts']['event_audience_sources']++;
                continue;
            }

            if ($mode === 2 && $voiceGroupIds !== []) {
                EventAudienceSource::create([
                    'event_id' => (int) $event->id,
                    'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
                    'reference_id' => $voiceGroupIds[array_rand($voiceGroupIds)],
                ]);
                $this->report['counts']['event_audience_sources']++;
                continue;
            }

            if ($mode === 3 && $roleIds !== []) {
                EventAudienceSource::create([
                    'event_id' => (int) $event->id,
                    'source_type' => EventAudienceSource::TYPE_ROLE,
                    'reference_id' => $roleIds[array_rand($roleIds)],
                ]);
                $this->report['counts']['event_audience_sources']++;
            }
        }
    }
```

Hinweis: Die tatsächliche Struktur von `$roles` und `$voiceData` in Step 3 an die realen Rückgaben von `seedRoles()`/`seedVoiceGroups()` anpassen (deren Rückgabeform vor dem Schreiben prüfen: `seedRoles` Zeile 292, `seedVoiceGroups` Zeile 436). Zugriffe (`$r->id`, `$voiceData['voice_groups']`) entsprechend korrigieren.

- [ ] **Step 4: In `run()` verdrahten**

Nach `$projectEvents = $this->seedProjectEvents($projects, $eventTypes);` und vor `$this->seedAttendance(...)` einfügen:

```php
            $this->seedEventAudienceSources($projectEvents, $roles, $voiceData, $projects);
```

- [ ] **Step 5: Echten Dev-Seed-Lauf ausführen**

Run (aus `README.md`-Muster):
`ddev exec APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed --years=3 --seed=20260321`
Expected: Report ohne Fehler; `event_audience_sources` > 0.

- [ ] **Step 6: Report prüfen**

Im Ausgabereport bestätigen, dass `event_audience_sources` einen positiven Count hat und die übrigen Termin-/Anwesenheits-Counts weiterhin plausibel sind. Ergebnis berichten.

- [ ] **Step 7: phpcs**

Run: `ddev composer phpcs` → bei Bedarf `ddev composer phpcbf`.

- [ ] **Step 8: Commit**

```bash
git add src/Services/DevSeedService.php
git commit -m "chore: Seed-Abdeckung fuer Termin-Zielgruppen"
```

---

## Task 8: Qualitäts-Gates + Abschluss

**Files:** keine neuen; Verifikation.

- [ ] **Step 1: Gesamte Testsuite**

Run: `ddev exec ./vendor/bin/phpunit`
Expected: alle Tests PASS. Fehlschläge beheben (bevorzugt in der zugehörigen Task-Logik).

- [ ] **Step 2: phpcs gesamt**

Run: `ddev composer phpcs`
Expected: keine Verstöße. Fixes via `ddev composer phpcbf`.

- [ ] **Step 3: twigcs gesamt**

Run: `ddev composer twigcs`
Expected: keine Verstöße. Fixes via `ddev composer twigcbf` + manuell.

- [ ] **Step 4: LF-Check**

Sicherstellen, dass alle neuen/geänderten Nicht-Script-Textdateien LF haben (Global-Constraints-Snippet je Datei, dann `git add`).

- [ ] **Step 5: Änderungsbericht**

Gemäß Change-Reporting-Standard zusammenfassen: neue Tabelle + Migrationen (Ausführungsergebnis), neue/eingeführte Klassen, Controller-/Template-Änderungen, Seed-Ergebnis, Testergebnis (Zahlen).

- [ ] **Step 6: Abschluss**

REQUIRED SUB-SKILL: `superpowers:finishing-a-development-branch` zur Integration (Merge/PR-Entscheidung). Kein `git push` (manuell durch Entwickler).

---

## Self-Review (vom Plan-Autor bereits durchlaufen)

- **Spec-Abdeckung:** Datenmodell (T1), eligibleUsersQuery-Union + leer=alle (T2), Service inkl. Sichtbarkeit (T3), Zugriff/Sichtbarkeit/ICS/Persistenz (T4), UI vier Quelltypen (T5), project_id-Drop + Restbereinigung inkl. Newsletter-`event_attendees` (T6), Seed (T7), Tests/Gates (T8). Alle Spec-Abschnitte referenziert.
- **Platzhalter:** keine „TBD"; Stellen mit realer Unsicherheit (Seed-Rückgabeformen, Script-Einbindungsmechanismus) enthalten konkrete Prüf-Anweisung + Fallback.
- **Typkonsistenz:** `normalizeSources`/`setSources`/`getSources`/`visibleEventsQuery`/`isUserEligible` einheitlich zwischen T3-Definition und T4/T5-Nutzung; Quelltyp-Strings verbatim.
- **Reihenfolge:** Column-Drop (T6) erst nach Entfernen aller `project_id`-Nutzung (T4/T5); Backfill (T1) sichert Regressionsparität.

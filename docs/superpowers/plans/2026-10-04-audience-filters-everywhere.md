# Zielgruppen-Filter für Termine, Newsletter und Vorlagen – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Termine, Newsletter und Newsletter-Vorlagen wählen ihre Zielgruppe über dieselben Filter-Zeilen wie die Datei-Freigaben; alle Filter hängen über Besitzer-Spalten mit `ON DELETE CASCADE` an ihrem Besitzer.

**Architecture:** `audience_filters` bekommt fünf Besitzer-Spalten (`event_id`, `newsletter_id`, `newsletter_template_id`, `file_folder_share_id`, `file_share_id`) und zuletzt einen `CHECK` „genau eine gesetzt“. `AudienceFilterService` bekommt eine besitzerbezogene API; Termin- und Newsletter-Auswertung bauen darauf auf. Die alten Quellentabellen werden je Modul per Migration umgestellt; `newsletter_recipient_sources` und `newsletter_template_recipient_sources` bleiben nur für `event_attendees`. Oberfläche: ein gemeinsames Partial `partials/audience/filter_row.twig` und `public/js/audience-filter.js`.

**Tech Stack:** PHP 8.5, Slim 4, Eloquent (illuminate/database), Phinx, MariaDB 11.8, Twig, PHPUnit 13, Vanilla-JS, TomSelect (lokal).

**Spec:** `docs/superpowers/specs/2026-10-04-audience-filters-everywhere-design.md` (baut auf `2026-10-02-audience-filters-design.md` auf)

## Global Constraints

- Schemaänderungen nur per Phinx-Migration (`/phinx-migration`); jede Kette endet mit `create()`/`save()`/`update()` (`MigrationChainCompletionTest`).
- Destruktive Schritte (`DROP TABLE`, `DROP COLUMN`, Enum verkleinern) erst nach einer Prüfung, die mit `RuntimeException` abbricht – die Prüfung steht im Code davor (`DestructiveStepNeedsGuardTest`, `DestructiveMigrationGuardTest`).
- Bezeichner englisch; Kommentare, UI-Texte, Testbeschreibungen, Commit-Nachrichten deutsch mit echten Umlauten.
- PSR-12, Zeilenlänge hart 130 (`ddev composer phpcs`); Twig mit doppelten Anführungszeichen, keine mehrzeiligen Bool-Ausdrücke (`ddev composer twigcs`); kein Inline-JS/-CSS.
- Zeilenenden LF (nach jedem Schreiben normalisieren, siehe `instructions/line-endings.md`).
- Kategorien exakt `role`, `voice_group`, `sub_voice`, `project`, `user` (`AudienceFilterCondition::CATEGORIES`).
- Besitzer-Spalten exakt `event_id`, `newsletter_id`, `newsletter_template_id`, `file_folder_share_id`, `file_share_id` (`AudienceFilter::OWNER_COLUMNS`).
- Regel: innerhalb einer Kategorie ODER, zwischen Kategorien UND, mehrere Filter eines Besitzers ODER; Filter ohne Bedingung trifft alle aktiven Mitglieder; Besitzer ohne Filter trifft niemanden.
- Termin: mindestens eine gültige Zeile; Newsletter/Vorlage: Zeilen freiwillig.
- Stimmgruppen in der Reihenfolge Sopran, Alt, Tenor, Bass (`VoiceGroup::query()->orderBy('id')`), Untergruppen alphabetisch.
- Tests gefiltert: `ddev php vendor/bin/phpunit --filter "<Muster>" 2>&1 | tail -30`; volle Suite nur einmal am Schluss: `ddev composer test:parallel`.
- Keine Hilfe-Überarbeitung, nur kurze Hinweise (AGENT.md, „Hilfetexte“).

## Review Focus

1. **Serie löschen per Massenabfrage** (`Event::whereIn(...)->delete()` in `EventController::deleteSeries`): Erwartung – keine verwaisten Zeilen in `audience_filters`. Test in Task 4 (`testDeletingSeriesByQueryRemovesFilters`).
2. **Verwalter mit zwei verwalteten Mitgliedern** (eines Sopran ohne Projekt, eines Alt im Projekt X) öffnet einen Termin „Sopran · Projekt X“: Erwartung – kein Zugriff. Test in Task 4 (`testManagedMembersAreNotMixed`).
3. **Termin-Formular mit nur einer leeren Zeile ohne Häkchen** (halb ausgefüllt): Erwartung – abgelehnt mit Meldung, Formular behält Titel und Zeile, Termin unverändert. Test in Task 4.
4. **Newsletter-Vorschau/Speichern mit gelöschter Rolle als einzigem Wert einer Kategorie**: Erwartung – 422/Fehlermeldung statt stiller Verbreiterung. Test in Task 5.
5. **Vorschau-Endpunkt mit Termin-Recht, aber ohne Datei-Rechte**: Erwartung – Zahl wird geliefert; ohne jedes der drei Rechte 403. Test in Task 3.

---

## Dateistruktur

| Datei | Verantwortung |
| --- | --- |
| `db/migrations/20261004090000_add_owner_columns_to_audience_filters.php` | Fünf Besitzer-Spalten, nullable, FK, Index |
| `db/migrations/20261004090100_attach_share_filters_to_shares.php` | Freigaben: Richtung drehen, `audience_filter_id` entfernen |
| `db/migrations/20261004090200_move_event_audience_to_filters.php` | `event_audience_sources` → Filter, Tabelle entfernen |
| `db/migrations/20261004090300_move_newsletter_audience_to_filters.php` | Newsletter- und Vorlagen-Quellen → Filter, Enum auf `event_attendees` |
| `db/migrations/20261004090400_require_single_audience_filter_owner.php` | `CHECK` genau ein Besitzer |
| `src/Models/AudienceFilter.php` | `OWNER_COLUMNS`, `conditionSet()` |
| `src/Services/Audience/AudienceFilterService.php` | Besitzer-API, Mitglieder-Abfrage aus Bedingungsmengen, Profile im Bündel |
| `src/Services/Audience/AudienceFilterNormalizer.php` | zusätzlich `normalizeRows()` (mehrere Zeilen, zusammengelegt) |
| `src/Services/Audience/AudienceDescriber.php` | (aus `Files/FileShareDescriber.php`) Zusammenfassung, Auswahllisten, gelöschte Werte |
| `src/Services/Audience/AudienceFormInput.php` | Formularzeilen aus dem Request lesen (aus `FileControllerSupport::parseShareRows`) |
| `src/Controllers/AudiencePreviewController.php` | `POST /audience-preview` (ersetzt `FileAudienceController`) |
| `templates/partials/audience/filter_row.twig`, `condition_select.twig`, `filter_rows.twig` | Gemeinsame Oberfläche |
| `public/js/audience-filter.js` | (aus `file-audience.js`) Zeilen-Logik, Hinzufügen, Vorschau, `window.AudienceFilter.setRows` |
| `src/Models/Event.php`, `src/Services/EventAudienceService.php`, `src/Services/AttendanceScopeService.php`, `src/Services/CalendarFeedService.php` | Termine über Filter |
| `src/Services/NewsletterRecipientService.php`, `src/Persistence/NewsletterTemplatePersistence.php` | Newsletter/Vorlagen über Filter + `event_attendees` |
| `tests/Feature/AudienceFixtures.php` | Test-Helfer `giveAudience()` für alle Besitzer |

Entfernt: `src/Models/EventAudienceSource.php`, `src/Exceptions/InvalidAudienceSourcesException.php` (falls danach unbenutzt), `src/Controllers/FileAudienceController.php`, `src/Services/Files/FileShareDescriber.php`, `templates/files/partials/share_row.twig`, `templates/files/partials/share_condition_select.twig`, `templates/events/_audience_sources.twig`, `public/js/file-audience.js`, `public/js/events-audience.js`.

---

### Task 1: Besitzer-Spalten und Besitzer-API des Bausteins

**Files:**
- Create: `db/migrations/20261004090000_add_owner_columns_to_audience_filters.php`
- Modify: `src/Models/AudienceFilter.php`, `src/Services/Audience/AudienceFilterService.php`, `src/Services/Audience/AudienceFilterNormalizer.php`
- Create: `tests/Feature/AudienceFixtures.php`
- Test: `tests/Feature/AudienceFilterOwnerFeatureTest.php`

**Interfaces:**
- Produces:
  - `AudienceFilter::OWNER_COLUMNS` (list<string>, Reihenfolge wie Global Constraints), `AudienceFilter::conditionSet(): array<string, list<int>>` (aus geladener `conditions`-Beziehung, Kategorien in `CATEGORIES`-Reihenfolge, Kennungen aufsteigend)
  - `AudienceFilterService::create(array $conditions, string $ownerColumn, int $ownerId): AudienceFilter` (**neue Signatur**; alte Aufrufer passt Task 2 an – bis dahin bleibt ein Übergangs-Default, siehe Step 3)
  - `AudienceFilterService::replaceForOwner(string $ownerColumn, int $ownerId, array $conditionSets): void`
  - `AudienceFilterService::conditionSetsForOwners(string $ownerColumn, array $ownerIds): array<int, list<array<string, list<int>>>>` (jeder angefragte Besitzer als Schlüssel, ohne Filter `[]`)
  - `AudienceFilterService::membersQueryForSets(array $conditionSets): Builder<User>` (`[]` → niemand, `[[]]` → alle aktiven)
  - `AudienceFilterService::membersQueryForOwner(string $ownerColumn, int $ownerId): Builder<User>`
  - `AudienceFilterService::fitsAny(MemberProfile $profile, array $conditionSets): bool`
  - `AudienceFilterService::matchingOwnerIds(MemberProfile $profile, string $ownerColumn): list<int>`
  - `AudienceFilterService::profilesOf(array $userIds): array<int, MemberProfile>` (vier Abfragen für alle)
  - `AudienceFilterNormalizer::normalizeRows(array $rows): list<array<string, list<int>>>` (jede Zeile über `normalize()`, gleiche Signaturen zusammengelegt, Reihenfolge der ersten Nennung)
  - Test-Trait `Tests\Feature\AudienceFixtures::giveAudience(string $ownerColumn, int $ownerId, array ...$conditionSets): void`

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/AudienceFilterOwnerFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilter;
use App\Models\Event;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\InvalidAudienceFilterException;
use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * Besitzerbezogene Auswertung: mehrere Filter eines Besitzers sind ODER,
 * gelöscht wird über den Fremdschlüssel des Besitzers.
 */
class AudienceFilterOwnerFeatureTest extends TestCase
{
    use FileFixtures;
    use AudienceFixtures;

    private AudienceFilterService $service;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->service = new AudienceFilterService();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function event(): Event
    {
        return Event::create([
            'title' => 'Probe ' . bin2hex(random_bytes(3)),
            'starts_at' => '2030-01-01 19:00:00',
            'ends_at' => '2030-01-01 21:00:00',
            'type' => 'Probe',
        ]);
    }

    public function testFiltersOfOneOwnerAreCombinedWithOr(): void
    {
        $soprano = $this->createMember('Sopran');
        $alto = $this->createMember('Alt');
        $other = $this->createMember('Bass');
        $sopranoGroup = $this->createVoiceGroupFor($soprano);
        $altoGroup = $this->createVoiceGroupFor($alto);
        $event = $this->event();

        $this->giveAudience('event_id', (int) $event->id, ['voice_group' => [(int) $sopranoGroup->id]], [
            'voice_group' => [(int) $altoGroup->id],
        ]);

        $ids = $this->service->membersQueryForOwner('event_id', (int) $event->id)->pluck('users.id')->all();
        $this->assertContains((int) $soprano->id, $ids);
        $this->assertContains((int) $alto->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function testOwnerWithoutFilterMatchesNobodyAndEmptyFilterMatchesAll(): void
    {
        $member = $this->createMember();
        $event = $this->event();
        $this->assertSame(0, $this->service->membersQueryForOwner('event_id', (int) $event->id)->count());

        $this->giveAudience('event_id', (int) $event->id, []);
        $ids = $this->service->membersQueryForOwner('event_id', (int) $event->id)->pluck('users.id')->all();
        $this->assertContains((int) $member->id, $ids);
    }

    public function testMatchingOwnerIdsUsesAndWithinAFilter(): void
    {
        $member = $this->createMember();
        $group = $this->createVoiceGroupFor($member);
        $project = $this->createProjectFor($member);
        $foreignProject = $this->createProjectFor($this->createMember());
        $fits = $this->event();
        $misses = $this->event();
        $this->giveAudience('event_id', (int) $fits->id, [
            'voice_group' => [(int) $group->id],
            'project' => [(int) $project->id],
        ]);
        $this->giveAudience('event_id', (int) $misses->id, [
            'voice_group' => [(int) $group->id],
            'project' => [(int) $foreignProject->id],
        ]);

        $profile = $this->service->profileOf((int) $member->id);
        $this->assertNotNull($profile);
        $matching = $this->service->matchingOwnerIds($profile, 'event_id');
        $this->assertContains((int) $fits->id, $matching);
        $this->assertNotContains((int) $misses->id, $matching);
    }

    public function testReplaceForOwnerSwapsAllFilters(): void
    {
        $event = $this->event();
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $this->giveAudience('event_id', (int) $event->id, [], ['role' => [(int) $role->id]]);

        $this->service->replaceForOwner('event_id', (int) $event->id, [['user' => [(int) $member->id]]]);

        $sets = $this->service->conditionSetsForOwners('event_id', [(int) $event->id]);
        $this->assertSame([['user' => [(int) $member->id]]], $sets[(int) $event->id]);
    }

    public function testDeletingOwnerByQueryRemovesFiltersAndConditions(): void
    {
        $event = $this->event();
        $this->giveAudience('event_id', (int) $event->id, ['user' => [(int) $this->createMember()->id]]);
        $filterIds = AudienceFilter::query()->where('event_id', $event->id)->pluck('id')->all();
        $this->assertNotSame([], $filterIds);

        Event::query()->whereIn('id', [(int) $event->id])->delete();

        $this->assertSame(0, AudienceFilter::query()->whereIn('id', $filterIds)->count());
        $this->assertSame(0, DB::table('audience_filter_conditions')->whereIn('audience_filter_id', $filterIds)->count());
    }

    public function testUnknownOwnerColumnIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->membersQueryForOwner('users.id; DROP', 1);
    }

    public function testProfilesOfLoadsEveryMemberSeparately(): void
    {
        $a = $this->createMember();
        $b = $this->createMember();
        $groupA = $this->createVoiceGroupFor($a);
        $projectB = $this->createProjectFor($b);

        $profiles = $this->service->profilesOf([(int) $a->id, (int) $b->id]);

        $this->assertTrue($profiles[(int) $a->id]->has('voice_group', (int) $groupA->id));
        $this->assertFalse($profiles[(int) $a->id]->has('project', (int) $projectB->id));
        $this->assertTrue($profiles[(int) $b->id]->has('project', (int) $projectB->id));
    }

    public function testNormalizeRowsMergesEqualRowsAndRejectsHalfFilledRow(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $normalizer = new AudienceFilterNormalizer();
        $rows = [
            ['conditions' => ['role' => [(string) $role->id]]],
            ['conditions' => ['role' => [(string) $role->id]]],
            ['all' => '1'],
        ];
        $this->assertSame([['role' => [(int) $role->id]], []], $normalizer->normalizeRows($rows));

        $this->expectException(InvalidAudienceFilterException::class);
        $normalizer->normalizeRows([['conditions' => []]]);
    }
}
```

`tests/Feature/AudienceFixtures.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audience\AudienceFilterService;

/**
 * Legt Zielgruppen-Filter für einen Besitzer an. Jede übergebene
 * Bedingungsmenge wird ein eigener Filter; [] heißt „alle Mitglieder“.
 */
trait AudienceFixtures
{
    /**
     * @param array<string, list<int>> ...$conditionSets
     */
    protected function giveAudience(string $ownerColumn, int $ownerId, array ...$conditionSets): void
    {
        $service = new AudienceFilterService();
        foreach ($conditionSets as $conditions) {
            $service->create($conditions, $ownerColumn, $ownerId);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen – rot**

Run: `ddev php vendor/bin/phpunit --filter AudienceFilterOwnerFeatureTest 2>&1 | tail -30`
Expected: FAIL (unbekannte Spalte `event_id` bzw. undefinierte Methoden).

- [ ] **Step 3: Migration, Model und Service**

`db/migrations/20261004090000_add_owner_columns_to_audience_filters.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Filter bekommen ihren Besitzer als Spalte mit Fremdschlüssel. Gelöscht wird
 * dadurch über die Datenbank - auch bei Massenlöschung und bei Cascades von
 * weiter oben, wo keine Model-Events laufen. Ein weiteres Modul braucht nur
 * eine weitere Spalte, keine eigene Tabelle.
 */
final class AddOwnerColumnsToAudienceFilters extends AbstractMigration
{
    private const OWNERS = [
        'event_id' => 'events',
        'newsletter_id' => 'newsletters',
        'newsletter_template_id' => 'newsletter_templates',
        'file_folder_share_id' => 'file_folder_shares',
        'file_share_id' => 'file_shares',
    ];

    public function up(): void
    {
        $table = $this->table('audience_filters');
        foreach (self::OWNERS as $column => $owner) {
            $table->addColumn($column, 'integer', ['null' => true, 'signed' => $this->ownerIdSigned($owner)])
                ->addIndex([$column], ['name' => 'idx_audience_filters_' . $column])
                ->addForeignKey($column, $owner, 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                    'constraint' => 'fk_audience_filters_' . $column,
                ]);
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('audience_filters');
        foreach (array_keys(self::OWNERS) as $column) {
            $table->dropForeignKey($column);
        }
        $table->update();

        $table = $this->table('audience_filters');
        foreach (array_keys(self::OWNERS) as $column) {
            $table->removeIndexByName('idx_audience_filters_' . $column)->removeColumn($column);
        }
        $table->update();
    }

    /**
     * Fremdschlüssel verlangen denselben Typ: ältere Tabellen haben signierte
     * Kennungen, die Dateiverwaltung unsignierte.
     */
    private function ownerIdSigned(string $owner): bool
    {
        $row = $this->fetchRow(sprintf("SHOW COLUMNS FROM `%s` LIKE 'id'", $owner));

        return !str_contains(strtolower((string) ($row['Type'] ?? '')), 'unsigned');
    }
}
```

Run: `ddev exec ./vendor/bin/phinx migrate` und `ddev exec ./vendor/bin/phinx migrate -e testing` (Testdatenbank, siehe `/test-runs`). Expected: beide ok.

`src/Models/AudienceFilter.php` ergänzen:

```php
    /** Besitzer-Spalten; genau eine ist gesetzt (CHECK ab Migration 20261004090400). */
    public const OWNER_COLUMNS = [
        'event_id',
        'newsletter_id',
        'newsletter_template_id',
        'file_folder_share_id',
        'file_share_id',
    ];

    protected $fillable = self::OWNER_COLUMNS;

    /**
     * Bedingungen dieses Filters aus der geladenen Beziehung, Kategorien in
     * fester Reihenfolge, Kennungen aufsteigend.
     *
     * @return array<string, list<int>>
     */
    public function conditionSet(): array
    {
        $grouped = [];
        foreach ($this->conditions as $condition) {
            $grouped[(string) $condition->category][] = (int) $condition->reference_id;
        }

        $set = [];
        foreach (AudienceFilterCondition::CATEGORIES as $category) {
            if (isset($grouped[$category])) {
                $ids = array_values(array_unique($grouped[$category]));
                sort($ids);
                $set[$category] = $ids;
            }
        }

        return $set;
    }
```

(Den Klassenkommentar auf „Zielgruppe aus Bedingungen; gehört genau einem Besitzer.“ ändern.)

`src/Services/Audience/AudienceFilterService.php` – ersetzen bzw. ergänzen:

```php
    /**
     * @param array<string, list<int>> $conditions bereits normalisiert
     */
    public function create(array $conditions, string $ownerColumn, int $ownerId): AudienceFilter
    {
        $filter = AudienceFilter::create([self::ownerColumn($ownerColumn) => $ownerId]);
        foreach ($conditions as $category => $ids) {
            foreach ($ids as $id) {
                $filter->conditions()->create(['category' => $category, 'reference_id' => (int) $id]);
            }
        }

        return $filter;
    }

    /**
     * Ersetzt alle Filter eines Besitzers. Löschen und Anlegen gehören
     * zusammen: Bricht es dazwischen ab, stünde der Besitzer ohne Filter da.
     *
     * @param list<array<string, list<int>>> $conditionSets bereits normalisiert
     */
    public function replaceForOwner(string $ownerColumn, int $ownerId, array $conditionSets): void
    {
        $column = self::ownerColumn($ownerColumn);
        DB::connection()->transaction(function () use ($column, $ownerId, $conditionSets): void {
            AudienceFilter::query()->where($column, $ownerId)->delete();
            foreach ($conditionSets as $conditions) {
                $this->create($conditions, $column, $ownerId);
            }
        });
    }

    /**
     * @param list<int> $ownerIds
     * @return array<int, list<array<string, list<int>>>>
     */
    public function conditionSetsForOwners(string $ownerColumn, array $ownerIds): array
    {
        $column = self::ownerColumn($ownerColumn);
        $ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
        $result = array_fill_keys($ownerIds, []);
        if ($ownerIds === []) {
            return $result;
        }

        $filters = AudienceFilter::query()->with('conditions')->whereIn($column, $ownerIds)->orderBy('id')->get();
        foreach ($filters as $filter) {
            $result[(int) $filter->{$column}][] = $filter->conditionSet();
        }

        return $result;
    }

    /**
     * Aktive Mitglieder, die mindestens eine der Bedingungsmengen trifft.
     *
     * @param list<array<string, list<int>>> $conditionSets
     * @return Builder<User>
     */
    public function membersQueryForSets(array $conditionSets): Builder
    {
        $query = User::query()->where('users.is_active', 1);
        if ($conditionSets === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $any) use ($conditionSets): void {
            foreach ($conditionSets as $conditions) {
                $any->orWhere(function (Builder $all) use ($conditions): void {
                    $all->whereRaw('1 = 1');
                    $this->applyConditions($all, $conditions);
                });
            }
        });

        return $query;
    }

    /**
     * @return Builder<User>
     */
    public function membersQueryForOwner(string $ownerColumn, int $ownerId): Builder
    {
        return $this->membersQueryForSets($this->conditionSetsForOwners($ownerColumn, [$ownerId])[$ownerId]);
    }

    /**
     * @param list<array<string, list<int>>> $conditionSets
     */
    public function fitsAny(MemberProfile $profile, array $conditionSets): bool
    {
        foreach ($conditionSets as $conditions) {
            if ($this->fits($profile, $conditions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Besitzer, von denen mindestens ein Filter auf das Mitglied passt.
     *
     * @return list<int>
     */
    public function matchingOwnerIds(MemberProfile $profile, string $ownerColumn): array
    {
        $column = self::ownerColumn($ownerColumn);
        $owners = AudienceFilter::query()->whereNotNull($column)->pluck($column, 'id');
        $matching = [];
        foreach ($this->matchingFilterIds($profile, array_map('intval', array_keys($owners->all()))) as $filterId) {
            $matching[(int) $owners[$filterId]] = true;
        }

        return array_keys($matching);
    }

    /**
     * Profile mehrerer Mitglieder mit vier Abfragen statt vier je Mitglied.
     *
     * @param list<int> $userIds
     * @return array<int, MemberProfile>
     */
    public function profilesOf(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $existing = User::query()->whereIn('id', $userIds ?: [0])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $roles = DB::table('user_roles')->whereIn('user_id', $existing ?: [0])->get(['user_id', 'role_id']);
        $voices = DB::table('user_voice_groups')->whereIn('user_id', $existing ?: [0])
            ->get(['user_id', 'voice_group_id', 'sub_voice_id']);
        $projects = DB::table('project_users')->whereIn('user_id', $existing ?: [0])->get(['user_id', 'project_id']);

        $profiles = [];
        foreach ($existing as $userId) {
            $own = static fn ($rows) => $rows->where('user_id', $userId);
            $profiles[$userId] = new MemberProfile(
                $userId,
                self::ints($own($roles)->pluck('role_id')),
                self::ints($own($voices)->pluck('voice_group_id')),
                self::ints($own($voices)->pluck('sub_voice_id')->filter()),
                self::ints($own($projects)->pluck('project_id'))
            );
        }

        return $profiles;
    }

    /**
     * @param Builder<User> $query
     * @param array<string, list<int>> $conditions
     */
    private function applyConditions(Builder $query, array $conditions): void
    {
        $relations = [
            C::CATEGORY_ROLE => ['roles', 'roles.id'],
            C::CATEGORY_VOICE_GROUP => ['voiceGroups', 'voice_groups.id'],
            C::CATEGORY_SUB_VOICE => ['subVoices', 'sub_voices.id'],
            C::CATEGORY_PROJECT => ['projects', 'projects.id'],
        ];
        foreach ($conditions as $category => $ids) {
            if ($category === C::CATEGORY_USER) {
                $query->whereIn('users.id', $ids);
                continue;
            }
            [$relation, $column] = $relations[$category];
            $query->whereHas($relation, static fn ($q) => $q->whereIn($column, $ids));
        }
    }

    private static function ownerColumn(string $column): string
    {
        if (!in_array($column, AudienceFilter::OWNER_COLUMNS, true)) {
            throw new \InvalidArgumentException('Unbekannte Besitzer-Spalte: ' . $column);
        }

        return $column;
    }
```

`profileOf()` wird zu `return $this->profilesOf([$userId])[$userId] ?? null;`. `membersQuery(int $filterId)` entfällt; die bisherige Bedingungs-Schleife lebt in `applyConditions()`. `delete()` bleibt bis Task 2.

**Task 1 und Task 2 werden gemeinsam committet.** Die neue `create()`-Signatur verlangt einen Besitzer, die Freigaben haben aber erst nach Task 2 einen; ein Zwischenstand mit `audience_filter_id NOT NULL` an den Freigaben und Pflicht-Besitzer am Filter lässt sich nicht sauber grün halten. Zwischen Task 1 und 2 laufen deshalb nur die Tests aus Step 4; die Datei-Tests sind bis zum Ende von Task 2 rot.

In Task 1 schon umstellen: `FileAudienceController::preview` zählt ohne Persistenz – statt anlegen/zurückrollen `$count = $this->filters->membersQueryForSets([$conditions])->count();` (Transaktion und `DB`-Import entfallen).

`AudienceFilterNormalizer::normalizeRows()`:

```php
    /**
     * Mehrere Zeilen eines Formulars. Gleiche Bedingungsmengen werden zu einer
     * zusammengelegt; eine ungültige Zeile lehnt das ganze Formular ab.
     *
     * @param array<int, mixed> $rows
     * @return list<array<string, list<int>>>
     */
    public function normalizeRows(array $rows): array
    {
        $bySignature = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $conditions = $this->normalize($row);
            $bySignature[$this->signature($conditions)] ??= $conditions;
        }

        return array_values($bySignature);
    }
```

- [ ] **Step 4: Tests grün**

Run: `ddev php vendor/bin/phpunit --filter "AudienceFilterOwnerFeatureTest|AudienceFilterServiceFeatureTest|AudienceFilterNormalizerFeatureTest" 2>&1 | tail -30`
Expected: PASS. `AudienceFilterServiceFeatureTest` nutzt `membersQuery()` und `create($conditions)` – dort auf `membersQueryForSets([$set])` bzw. Bedingungsmengen ohne Persistenz umstellen (Aussagen unverändert).

- [ ] **Step 5: kein Commit – weiter mit Task 2 (gemeinsamer Commit in Task 2 Step 6)**

---

### Task 2: Freigaben an die Besitzer-Spalten hängen

**Files:**
- Create: `db/migrations/20261004090100_attach_share_filters_to_shares.php`
- Modify: `src/Models/FileFolderShare.php`, `src/Models/FileShare.php`, `src/Services/Files/FileFolderService.php`, `src/Services/Files/FileShareService.php`, `src/Services/Files/FileTrashService.php`, `src/Services/Files/FileAccessService.php`, `src/Services/Files/FileShareDescriber.php`, `src/Services/DevSeedService.php` (Freigaben-Teil), `tests/Feature/FileFixtures.php`
- Test: `tests/Feature/AudienceFilterMigrationFeatureTest.php`, `tests/Feature/FileTrashServiceFeatureTest.php`, `tests/Feature/FileFolderServiceFeatureTest.php`

**Interfaces:**
- Consumes: Task 1 (`create(..., $ownerColumn, $ownerId)`, `conditionSetsForOwners`).
- Produces: `FileFolderShare::filter(): HasOne` und `FileShare::filter(): HasOne` (über `file_folder_share_id` bzw. `file_share_id`); `file_folder_shares`/`file_shares` ohne `audience_filter_id`.

- [ ] **Step 1: Failing Tests**

In `AudienceFilterMigrationFeatureTest::testOldColumnsAreGoneAndFilterIsRequired` die letzte Zusicherung ersetzen:

```php
            $this->assertArrayNotHasKey('audience_filter_id', $columns, $table);
```

und neu:

```php
    public function testEveryShareOwnsExactlyOneFilter(): void
    {
        Bootstrap::setupTestDatabase();
        foreach (['file_folder_shares' => 'file_folder_share_id', 'file_shares' => 'file_share_id'] as $table => $column) {
            $orphans = DB::selectOne(
                "SELECT COUNT(*) AS n FROM {$table} s
                 WHERE (SELECT COUNT(*) FROM audience_filters f WHERE f.{$column} = s.id) <> 1"
            );
            $this->assertSame(0, (int) $orphans->n, $table);
        }
    }

    public function testShareMigrationChecksBeforeDroppingTheOldColumn(): void
    {
        $content = (string) file_get_contents(
            dirname(__DIR__, 2) . '/db/migrations/20261004090100_attach_share_filters_to_shares.php'
        );
        $guard = strpos($content, 'throw new RuntimeException');
        $drop = strpos($content, "removeColumn('audience_filter_id')");
        $this->assertNotFalse($guard);
        $this->assertNotFalse($drop);
        $this->assertLessThan($drop, $guard);
    }
```

In `FileTrashServiceFeatureTest` den bestehenden Test „Filter beim endgültigen Löschen entfernt“ so belassen; er muss nach dem Umbau ohne `$this->filters->delete()` grün bleiben (Cascade). In `FileFolderServiceFeatureTest` den Test „Filter beim Ersetzen entfernt“ ebenso.

- [ ] **Step 2: rot**

Run: `ddev php vendor/bin/phpunit --filter AudienceFilterMigrationFeatureTest 2>&1 | tail -20`
Expected: FAIL (`audience_filter_id` existiert noch).

- [ ] **Step 3: Migration**

`db/migrations/20261004090100_attach_share_filters_to_shares.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Richtung dreht sich: Der Filter zeigt auf seine Freigabe, nicht mehr die
 * Freigabe auf den Filter. Damit räumt der Fremdschlüssel den Filter mit ab,
 * wenn die Freigabe verschwindet - auch über den Cascade vom Ordner her.
 */
final class AttachShareFiltersToShares extends AbstractMigration
{
    private const TABLES = [
        'file_folder_shares' => 'file_folder_share_id',
        'file_shares' => 'file_share_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            $this->execute(
                "UPDATE audience_filters f JOIN {$table} s ON s.audience_filter_id = f.id SET f.{$column} = s.id"
            );
        }

        // Prüfung vor dem destruktiven Schritt: jede Freigabe hat genau einen Filter.
        foreach (self::TABLES as $table => $column) {
            $broken = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} s
                 WHERE (SELECT COUNT(*) FROM audience_filters f WHERE f.{$column} = s.id) <> 1"
            )['n'] ?? 0);
            if ($broken > 0) {
                throw new RuntimeException(sprintf(
                    '%d Freigabe(n) in %s ohne eindeutigen Filter - Abbruch vor dem Entfernen von audience_filter_id.',
                    $broken,
                    $table
                ));
            }
        }

        foreach (array_keys(self::TABLES) as $table) {
            $this->table($table)->dropForeignKey('audience_filter_id')->update();
            $this->table($table)->removeColumn('audience_filter_id')->update();
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $column) {
            $this->table($table)
                ->addColumn('audience_filter_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'id'])
                ->update();
            $this->execute(
                "UPDATE {$table} s JOIN audience_filters f ON f.{$column} = s.id SET s.audience_filter_id = f.id"
            );
            $missing = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} WHERE audience_filter_id IS NULL"
            )['n'] ?? 0);
            if ($missing > 0) {
                throw new RuntimeException(sprintf('%d Freigabe(n) in %s ohne Filter - Rückbau abgebrochen.', $missing, $table));
            }
            $this->table($table)
                ->changeColumn('audience_filter_id', 'integer', ['null' => false, 'signed' => false])
                ->addForeignKey('audience_filter_id', 'audience_filters', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                    'constraint' => 'fk_' . $table . '_audience_filter',
                ])
                ->update();
            $this->execute("UPDATE audience_filters SET {$column} = NULL");
        }
    }
}
```

Hinweis: Der Name des Fremdschlüssels aus `20261002090100` ist `fk_<tabelle>_audience_filter`; `dropForeignKey('audience_filter_id')` findet ihn über die Spalte.

Run: `ddev exec ./vendor/bin/phinx migrate` und für die Testdatenbank wie in `/test-runs`.

- [ ] **Step 4: Models und Services**

`FileFolderShare` / `FileShare`: `audience_filter_id` aus `$fillable` und `$casts` entfernen; `filter()` wird

```php
    public function filter(): HasOne
    {
        return $this->hasOne(AudienceFilter::class, 'file_folder_share_id'); // FileShare: 'file_share_id'
    }
```

`FileFolderService::setShares` (Transaktionsinhalt):

```php
            FileFolderShare::query()->where('folder_id', $folder->id)->delete();
            foreach ($shares as $share) {
                $row = FileFolderShare::create([
                    'folder_id' => (int) $folder->id,
                    'level' => $share['level'],
                    'created_by' => $actor->userId,
                ]);
                $this->filters->create($share['conditions'], 'file_folder_share_id', (int) $row->id);
            }
```

`FileShareService::setShares` entsprechend mit `'file_share_id'`. Das Mitlöschen über `$old`/`$this->filters->delete()` entfällt in beiden.

`FileTrashService`: `shareFilterIds()` und beide `$this->filters->delete($filterIds)` entfernen, ebenso den Kommentar „die Filter der Freigaben nicht, die zeigen nicht zurück“ (ersetzen durch „Der Cascade räumt Unterordner, Dateien, Versionen, Freigaben samt Filtern und Favoriten mit ab.“). Den Konstruktor-Parameter `$filters` entfernen, wenn ungenutzt.

`AudienceFilterService::delete()` entfernen (keine Aufrufer mehr: `grep -rn "filters->delete" src`).

`FileAccessService::matchingLevels`:

```php
    private function matchingLevels(FileActor $actor, Builder $query, string $keyColumn, string $ownerColumn): array
    {
        $profile = $this->filters->profileOf($actor->userId);
        if ($profile === null) {
            return [];
        }

        $rows = $query->toBase()->get(['id', $keyColumn . ' AS target_id', 'level']);
        $sets = $this->filters->conditionSetsForOwners($ownerColumn, $rows->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $levels = [];
        foreach ($rows as $row) {
            if (!$this->filters->fitsAny($profile, $sets[(int) $row->id] ?? [])) {
                continue;
            }
            $target = (int) $row->target_id;
            $levels[$target] = max($levels[$target] ?? 0, (int) $row->level);
        }

        return $levels;
    }
```

Aufrufer: `matchingLevels($actor, FileFolderShare::query(), 'folder_id', 'file_folder_share_id')` und für Dateien `..., 'file_id', 'file_share_id'`.

`FileShareDescriber::label()`: statt `conditionsOf(audience_filter_id)` → `$sets = $this->filters->conditionSetsForOwners($ownerColumn, $shareIds)` mit `$ownerColumn` aus der Klasse der Freigabe (`$share instanceof FileFolderShare ? 'file_folder_share_id' : 'file_share_id'`); `$set = $sets[(int) $share->id][0] ?? []`. Schlüssel `filter_id` im Ergebnis entfällt (`grep -rn "filter_id" templates src` – Aufrufer anpassen).

`FileFixtures`: wo bisher `create($conditions)` + `audience_filter_id` gesetzt wird, die Freigabe zuerst anlegen und dann `(new AudienceFilterService())->create($conditions, 'file_folder_share_id', (int) $share->id)` (Datei: `'file_share_id'`).

`DevSeedService` (Zeilen um 2335 und 2397): gleiches Muster.

- [ ] **Step 5: Tests grün**

Run: `ddev php vendor/bin/phpunit --filter "Audience|File" 2>&1 | tail -30`
Expected: PASS. Rote Tests, die `audience_filter_id` an Freigaben lesen, auf `->filter` bzw. `audience_filters.file_folder_share_id` umstellen; Aussagen unverändert.

- [ ] **Step 6: Commit (Task 1 + 2)**

Mit `/git-commit` auf einem Feature-Branch `feature/audience-filters-everywhere`:
`refactor(audience): Filter hängen über Besitzer-Spalten an Freigaben`

---

### Task 3: Gemeinsame Oberfläche, Describer und Vorschau-Endpunkt

**Files:**
- Create: `src/Services/Audience/AudienceDescriber.php` (per `git mv` aus `src/Services/Files/FileShareDescriber.php`)
- Create: `src/Services/Audience/AudienceFormInput.php`
- Create: `src/Controllers/AudiencePreviewController.php` (per `git mv` aus `FileAudienceController.php`)
- Create: `templates/partials/audience/filter_row.twig` (aus `files/partials/share_row.twig`), `templates/partials/audience/condition_select.twig` (aus `share_condition_select.twig`), `templates/partials/audience/filter_rows.twig`
- Create: `public/js/audience-filter.js` (aus `public/js/file-audience.js`)
- Modify: `src/Routes.php`, `src/Controllers/Concerns/FileControllerSupport.php`, `src/Controllers/FileBrowserController.php`, `src/Controllers/FileDetailController.php`, `templates/files/file.twig`, `templates/files/folder.twig`, `templates/files/partials/folder_modals.twig`, `templates/layout.twig` (Skript einbinden, falls dort zentral), `public/js/file-manager.js`, `public/css/style.css`
- Test: `tests/Feature/AudiencePreviewFeatureTest.php` (aus `FileAudienceFeatureTest.php`), `tests/Feature/FileControllerFeatureTest.php`

**Interfaces:**
- Consumes: Task 1/2.
- Produces:
  - `AudienceDescriber::summarize(array $conditions): string`, `::summarizeSets(array $sets): string` (Zeilen mit „ / “ getrennt, `[]` → „Keine Zielgruppe“), `::describeSets(array $sets): list<array{label: string, conditions: array, all: bool, missing: array}>`, `::options(array $selectedProjectIds = [], array $selectedUserIds = []): array`, `::selectedIds(array $sets, string $category): list<int>`
  - `AudienceFormInput::rows(mixed $raw): list<array{level: int, all: bool, conditions: array<string, list<string>>}>` (bisher `parseShareRows`)
  - Twig: `{% include "partials/audience/filter_rows.twig" with {prefix, rows, options, empty_hint, add_label} %}` – `rows` aus `describeSets()`; für Freigaben zusätzlich `level_labels` und je Zeile `level`
  - JS: Container `[data-audience-rows]` mit `data-audience-prefix`, Zeile `[data-audience-row]`, `<template data-audience-row-template>`, Knopf `[data-audience-add]`; `window.AudienceFilter.setRows(container, sets)`; Ereignis `audience:change` (bubbelt) bei jeder Änderung
  - Route `POST /audience-preview` → `AudiencePreviewController::preview`

- [ ] **Step 1: Failing Tests**

`git mv tests/Feature/FileAudienceFeatureTest.php tests/Feature/AudiencePreviewFeatureTest.php`, Klasse umbenennen, URL auf `/audience-preview`, und neu:

```php
    public function testEventManagerWithoutFileRightsGetsCount(): void
    {
        $user = $this->createMember();
        $_SESSION = ['user_id' => (int) $user->id, 'can_manage_events' => true];

        $response = $this->postPreview(['all' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('count', json_decode((string) $response->getBody(), true));
    }

    public function testNewsletterManagerGetsCount(): void
    {
        $user = $this->createMember();
        $_SESSION = ['user_id' => (int) $user->id, 'can_manage_newsletters' => true];

        $this->assertSame(200, $this->postPreview(['all' => '1'])->getStatusCode());
    }

    public function testMemberWithoutAnyOfTheRightsIsRejected(): void
    {
        $user = $this->createMember();
        $_SESSION = ['user_id' => (int) $user->id];

        $this->assertSame(403, $this->postPreview(['all' => '1'])->getStatusCode());
    }

    public function testPreviewWritesNothing(): void
    {
        $user = $this->createMember();
        $_SESSION = ['user_id' => (int) $user->id, 'can_manage_events' => true];
        $before = \Illuminate\Database\Capsule\Manager::table('audience_filters')->count();

        $this->postPreview(['conditions' => ['user' => [(string) $user->id]]]);

        $this->assertSame($before, \Illuminate\Database\Capsule\Manager::table('audience_filters')->count());
    }
```

(`postPreview(array $body): ResponseInterface` ist der vorhandene Helfer des Tests, ggf. aus dem bestehenden Aufruf extrahieren.)

In `FileControllerFeatureTest::testShareSummaryHasItsOwnElementForLiveUpdates` die erwarteten Attribute auf `data-audience-summary-text` umstellen.

- [ ] **Step 2: rot**

Run: `ddev php vendor/bin/phpunit --filter "AudiencePreviewFeatureTest|FileControllerFeatureTest" 2>&1 | tail -30`
Expected: FAIL (Route fehlt).

- [ ] **Step 3: Describer, Form-Input, Controller, Route**

`AudienceDescriber` (Namespace `App\Services\Audience`, Klassenkommentar „für alle Module“): Inhalt von `FileShareDescriber`; `label(iterable $shares)` bleibt für die Freigaben (nutzt intern `describeSets`), neu:

```php
    /**
     * @param list<array<string, list<int>>> $sets
     * @return list<array{label: string, conditions: array<string, list<int>>, all: bool,
     *                    missing: array<string, list<int>>}>
     */
    public function describeSets(array $sets): array
    {
        return array_map(fn (array $set): array => [
            'label' => $this->summarize($set),
            'conditions' => $set,
            'all' => $set === [],
            'missing' => $this->missingOf($set),
        ], $sets);
    }

    /**
     * @param list<array<string, list<int>>> $sets
     */
    public function summarizeSets(array $sets): string
    {
        if ($sets === []) {
            return 'Keine Zielgruppe';
        }

        return implode(' / ', array_map(fn (array $set): string => $this->summarize($set), $sets));
    }

    /**
     * @param list<array<string, list<int>>> $sets
     * @return list<int>
     */
    public static function selectedIds(array $sets, string $category): array
    {
        $ids = [];
        foreach ($sets as $set) {
            foreach ($set[$category] ?? [] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
```

`AudienceFormInput::rows()` = bisheriger Körper von `FileControllerSupport::parseShareRows` als `public static function rows(mixed $raw): array` (Nicht-Array → `[]`). `FileControllerSupport::parseShareRows` ruft nur noch `AudienceFormInput::rows($rows)`; `projectIdsOf`/`selectedIdsOf` dort durch `AudienceDescriber::selectedIds` ersetzen.

`AudiencePreviewController`:

```php
final class AudiencePreviewController
{
    public function __construct(
        private readonly FileAccessService $access,
        private readonly AudienceFilterService $filters,
        private readonly AudienceFilterNormalizer $normalizer
    ) {
    }

    /**
     * Trefferzahl einer Zielgruppen-Zeile, während sie bearbeitet wird. Nur die
     * Zahl, keine Namen; nur für Personen, die irgendwo Zielgruppen festlegen.
     */
    public function preview(Request $request, Response $response): Response
    {
        if (!$this->maySetAudiences()) {
            return $this->json($response, ['ok' => false, 'error' => 'Dafür fehlt die Berechtigung.'], 403);
        }

        $body = $request->getParsedBody();
        $row = AudienceFormInput::rows([is_array($body) ? $body : []])[0];
        try {
            $conditions = $this->normalizer->normalize($row);
        } catch (InvalidAudienceFilterException $exception) {
            return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        $count = $this->filters->membersQueryForSets([$conditions])->count();

        return $this->json($response, ['ok' => true, 'count' => $count]);
    }

    private function maySetAudiences(): bool
    {
        if ((bool) ($_SESSION['can_manage_events'] ?? false) || (bool) ($_SESSION['can_manage_newsletters'] ?? false)) {
            return true;
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        $actor = FileActor::fromSession();

        return $actor->isFileAdmin
            || in_array(FileFolderShare::LEVEL_MANAGE, $this->access->folderLevels($actor), true);
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
```

(Wie der Datei-Actor heute gebaut wird, steht in `FileControllerSupport::actor()` – denselben Weg nehmen; heißt die Fabrik anders, diese verwenden. Ist das Dateimodul abgeschaltet, ist `isFileAdmin`/`folderLevels` trotzdem auswertbar.)

`src/Routes.php`: `$files->post('/audience-preview', …)` entfernen; außerhalb der Modulgruppen im angemeldeten Bereich `$group->post('/audience-preview', [AudiencePreviewController::class, 'preview']);`. Container-Definitionen, falls `FileAudienceController` dort registriert ist, umbenennen (`grep -rn FileAudienceController src config`).

- [ ] **Step 4: Partials und Skript**

`templates/partials/audience/filter_rows.twig`:

```twig
{# Liste von Zielgruppen-Zeilen. Mehrere Zeilen: eine genügt.
   Parameter: prefix (Feldname, z. B. "audience" oder "shares"), rows (aus
   AudienceDescriber::describeSets bzw. ::label), options, add_label,
   empty_hint (optional), level_labels (optional, nur Freigaben). #}
<div class="audience-rows" data-audience-rows data-audience-prefix="{{ prefix }}">
    <div data-audience-row-list>
        {% for row in rows %}
            {{ include("partials/audience/filter_row.twig", {
                prefix: prefix,
                index: loop.index0,
                row: row,
                options: options,
                level_labels: level_labels|default(null),
            }) }}
        {% endfor %}
    </div>
    {% if empty_hint is defined and empty_hint %}
        <p class="form-text" data-audience-empty-hint {{ rows|length > 0 ? "hidden" : "" }}>{{ empty_hint }}</p>
    {% endif %}
    <button type="button" class="btn btn-outline-secondary btn-sm" data-audience-add>
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ add_label|default("Zielgruppe hinzufügen") }}
    </button>
    <template data-audience-row-template>
        {{ include("partials/audience/filter_row.twig", {
            prefix: prefix,
            index: "__INDEX__",
            row: null,
            options: options,
            level_labels: level_labels|default(null),
        }) }}
    </template>
</div>
```

`filter_row.twig`: Inhalt von `share_row.twig` mit diesen Änderungen –
- `shares[{{ index }}]` → `{{ prefix }}[{{ index }}]`, Variable `share` → `row`, `share_options` → `options`;
- `data-files-share-*` → `data-audience-*` (`row`, `toggle`, `summary-text`, `count`, `remove`, `editor`, `all`, `condition`);
- Stufen-Auswahl nur `{% if level_labels %}`, Name `{{ prefix }}[{{ index }}][level]`;
- `_summary` Standard „Neue Zielgruppe“;
- IDs `audience-{{ prefix }}-{{ index }}-all`.

`condition_select.twig`: Inhalt von `share_condition_select.twig`, `id="audience-{{ prefix }}-{{ index }}-{{ category }}"`, `name="{{ prefix }}[{{ index }}][conditions][{{ category }}][]"`, `data-audience-condition` statt `data-files-share-condition`.

`public/js/audience-filter.js` (`git mv public/js/file-audience.js public/js/audience-filter.js`), Selektoren auf `data-audience-*`, Vorschau-URL `/audience-preview`, dazu:

```js
    let nextIndex = Date.now();

    function addRow(container, conditions) {
        const template = container.querySelector('template[data-audience-row-template]');
        const list = container.querySelector('[data-audience-row-list]');
        if (!template || !list) {
            return null;
        }
        const index = String(nextIndex++);
        const html = template.innerHTML.split('__INDEX__').join(index);
        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const row = holder.firstElementChild;
        list.appendChild(row);
        if (conditions) {
            applyConditions(row, conditions);
        }
        initRow(row);
        container.dispatchEvent(new CustomEvent('audience:change', { bubbles: true }));
        updateEmptyHint(container);
        return row;
    }

    function applyConditions(row, conditions) {
        const all = row.querySelector('[data-audience-all]');
        if (all) {
            all.checked = Object.keys(conditions).length === 0;
        }
        row.querySelectorAll('[data-audience-condition]').forEach(function (select) {
            const match = select.name.match(/\[conditions\]\[([a-z_]+)\]/);
            const wanted = match && Array.isArray(conditions[match[1]]) ? conditions[match[1]].map(String) : [];
            Array.prototype.forEach.call(select.options, function (option) {
                option.selected = wanted.indexOf(option.value) !== -1;
            });
        });
    }

    function updateEmptyHint(container) {
        const hint = container.querySelector('[data-audience-empty-hint]');
        if (hint) {
            hint.hidden = container.querySelectorAll('[data-audience-row-list] [data-audience-row]').length > 0;
        }
    }

    /** Ersetzt alle Zeilen, z. B. beim Übernehmen einer Newsletter-Vorlage. */
    function setRows(container, sets) {
        container.querySelectorAll('[data-audience-row-list] [data-audience-row]').forEach(function (row) {
            row.remove();
        });
        (sets || []).forEach(function (conditions) {
            addRow(container, conditions);
        });
        updateEmptyHint(container);
    }

    window.AudienceFilter = { setRows: setRows, addRow: addRow };
```

`initRow(row)` bündelt, was bisher beim Laden je Zeile passiert (TomSelect initialisieren – über die vorhandene Initialisierung für `[data-tom-select]`, siehe `grep -rn "data-tom-select" public/js` –, Sperren, Zusammenfassung, Trefferzahl). Klick auf `[data-audience-add]` → `addRow(container, null)`; Entfernen → Zeile weg, `audience:change`, `updateEmptyHint`. Jede Änderung in einer Zeile löst `audience:change` aus.

Freigaben-Templates (`files/file.twig`, `files/partials/folder_modals.twig`, `files/folder.twig`): `share_row.twig`-Schleifen durch `filter_rows.twig` mit `prefix: "shares"`, `rows: shares`, `options: share_options`, `level_labels: …`, `add_label: "Freigabe hinzufügen"` ersetzen; die bisherigen Hinzufügen-Knöpfe und Zeilen-Templates in `file-manager.js` entfernen (`grep -n "share" public/js/file-manager.js`). Skript-Einbindung `file-audience.js` → `audience-filter.js`. CSS-Klassen `files-share-*` in `style.css` auf `audience-*` umbenennen.

Alte Dateien entfernen: `git rm templates/files/partials/share_row.twig templates/files/partials/share_condition_select.twig`.

- [ ] **Step 5: grün und Lint**

Run: `ddev php vendor/bin/phpunit --filter "Audience|File" 2>&1 | tail -30` – Expected: PASS.
Run: `ddev composer phpcs` und `ddev composer twigcs` – Expected: keine Verstöße.

- [ ] **Step 6: Commit**

`/git-commit`: `refactor(audience): gemeinsame Zielgruppen-Zeilen und Vorschau für alle Module`

---

### Task 4: Termine über Filter

**Files:**
- Create: `db/migrations/20261004090200_move_event_audience_to_filters.php`
- Modify: `src/Models/Event.php`, `src/Models/Project.php`, `src/Services/EventAudienceService.php`, `src/Services/AttendanceScopeService.php`, `src/Services/CalendarFeedService.php`, `src/Controllers/EventController.php`, `src/Controllers/EvaluationController.php`, `src/Controllers/AttendanceController.php`, `src/Controllers/RegistrationController.php`, `src/Services/DevSeedService.php` (Termin-Teil), `templates/events/edit.twig`, `templates/events/index.twig`, `tests/e2e/steps/events.mjs`
- Delete: `src/Models/EventAudienceSource.php`, `templates/events/_audience_sources.twig`, `public/js/events-audience.js`
- Test: `tests/Feature/EventAudienceFilterFeatureTest.php` (neu), `tests/Feature/EventAudienceFilterMigrationFeatureTest.php` (neu, ersetzt `EventAudienceSourceMigrationFeatureTest.php`), alle in der Dateiliste von Step 6 genannten Tests

**Interfaces:**
- Consumes: Task 1 (`replaceForOwner`, `membersQueryForSets`, `fitsAny`, `matchingOwnerIds`, `profilesOf`), Task 3 (`AudienceDescriber`, `AudienceFormInput`, Partials).
- Produces:
  - `Event::audienceFilters(): HasMany` (über `event_id`), `Event::audienceConditionSets(): list<array<string, list<int>>>` (nutzt geladene `audienceFilters.conditions`), `Event::scopeForProject(Builder $query, int $projectId): Builder`
  - `EventAudienceService::setAudience(Event $event, array $conditionSets): void`, `::conditionSets(Event $event): list<array>`, `::readRows(array $data): list<array>` (wirft `InvalidAudienceFilterException`, auch bei 0 Zeilen mit Meldung „Bitte mindestens eine Zielgruppe angeben.“)
  - Konstante `EventAudienceService::OWNER = 'event_id'`
  - Formularfelder `audience[i][all]`, `audience[i][conditions][<kategorie>][]`

- [ ] **Step 1: Failing Tests**

`tests/Feature/EventAudienceFilterFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilter;
use App\Models\Event;
use App\Models\EventSeries;
use App\Services\AttendanceScopeService;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\EventAudienceService;
use PHPUnit\Framework\TestCase;

/**
 * Termin-Zielgruppen als Filter: UND zwischen Kategorien, ODER zwischen Zeilen,
 * keine Vermischung der Merkmale verschiedener verwalteter Mitglieder.
 */
class EventAudienceFilterFeatureTest extends TestCase
{
    use FileFixtures;
    use AudienceFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function event(?int $seriesId = null): Event
    {
        return Event::create([
            'title' => 'Probe ' . bin2hex(random_bytes(3)),
            'starts_at' => '2030-01-01 19:00:00',
            'ends_at' => '2030-01-01 21:00:00',
            'type' => 'Probe',
            'series_id' => $seriesId,
        ]);
    }

    public function testSopranoAndProjectReachesOnlySopranosInProject(): void
    {
        $inBoth = $this->createMember('Sopran im Projekt');
        $sopranoOnly = $this->createMember('Sopran');
        $projectOnly = $this->createMember('Alt im Projekt');
        $soprano = $this->createVoiceGroupFor($inBoth);
        $sopranoOnly->voiceGroups()->attach($soprano->id);
        $project = $this->createProjectFor($inBoth);
        $projectOnly->projects()->attach($project->id);
        $event = $this->event();
        $this->giveAudience('event_id', (int) $event->id, [
            'voice_group' => [(int) $soprano->id],
            'project' => [(int) $project->id],
        ]);

        $eligible = $event->eligibleUsersQuery()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame([(int) $inBoth->id], $eligible);

        $service = new EventAudienceService();
        $this->assertTrue($service->visibleEventsQuery((int) $inBoth->id)->whereKey($event->id)->exists());
        $this->assertFalse($service->visibleEventsQuery((int) $sopranoOnly->id)->whereKey($event->id)->exists());
        $this->assertFalse($service->visibleEventsQuery((int) $projectOnly->id)->whereKey($event->id)->exists());
    }

    public function testManagedMembersAreNotMixed(): void
    {
        $manager = $this->createMember('Stimmführung');
        $sopranoWithoutProject = $this->createMember('Sopran');
        $altoInProject = $this->createMember('Alt');
        $soprano = $this->createVoiceGroupFor($sopranoWithoutProject);
        $project = $this->createProjectFor($altoInProject);
        $event = $this->event();
        $this->giveAudience('event_id', (int) $event->id, [
            'voice_group' => [(int) $soprano->id],
            'project' => [(int) $project->id],
        ]);

        $scope = $this->getMockBuilder(AttendanceScopeService::class)
            ->onlyMethods(['canManageOthers', 'getManageableUserIds'])
            ->getMock();
        $scope->method('canManageOthers')->willReturn(true);
        $scope->method('getManageableUserIds')->willReturn([(int) $sopranoWithoutProject->id, (int) $altoInProject->id]);
        $_SESSION = ['user_id' => (int) $manager->id];

        $this->assertFalse($scope->canAccessEvent($event));
    }

    public function testProjectScopeFindsEventsWithProjectCondition(): void
    {
        $member = $this->createMember();
        $project = $this->createProjectFor($member);
        $combined = $this->event();
        $other = $this->event();
        $this->giveAudience('event_id', (int) $combined->id, [
            'role' => [(int) $this->createRoleFor($member)->id],
            'project' => [(int) $project->id],
        ]);
        $this->giveAudience('event_id', (int) $other->id, []);

        $ids = Event::query()->forProject((int) $project->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame([(int) $combined->id], $ids);
        $this->assertSame([(int) $combined->id], $project->events()->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function testEmptyFormIsRejected(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new EventAudienceService())->readRows(['audience' => []]);
    }

    public function testHalfFilledRowIsRejected(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new EventAudienceService())->readRows(['audience' => [['conditions' => []]]]);
    }

    public function testSeriesEventsGetOwnFiltersAndDeletingByQueryRemovesThem(): void
    {
        $series = EventSeries::create(['title' => 'Serie', 'frequency' => 'weekly', 'interval' => 1]);
        $first = $this->event((int) $series->id);
        $second = $this->event((int) $series->id);
        $service = new EventAudienceService();
        foreach ([$first, $second] as $event) {
            $service->setAudience($event, [[]]);
        }
        $filterIds = AudienceFilter::query()->whereIn('event_id', [$first->id, $second->id])->pluck('id')->all();
        $this->assertCount(2, $filterIds);

        Event::query()->whereIn('id', [$first->id, $second->id])->delete();

        $this->assertSame(0, AudienceFilter::query()->whereIn('id', $filterIds)->count());
    }
}
```

(Die `EventSeries::create`-Felder an `src/Models/EventSeries.php` `$fillable` anpassen. Sind `canManageOthers`/`getManageableUserIds` private, für den Test auf `protected` heben.)

`tests/Feature/EventAudienceFilterMigrationFeatureTest.php` (ersetzt `EventAudienceSourceMigrationFeatureTest.php`, `git rm` der alten Datei):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventAudienceFilterMigrationFeatureTest extends TestCase
{
    private function migration(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/db/migrations/20261004090200_move_event_audience_to_filters.php'
        );
    }

    public function testSourceTableIsGone(): void
    {
        Bootstrap::setupTestDatabase();
        $this->assertSame([], DB::select("SHOW TABLES LIKE 'event_audience_sources'"));
    }

    public function testMappingCoversAllOldSourceTypes(): void
    {
        foreach (
            ["'role' => 'role'", "'voice_group' => 'voice_group'", "'user' => 'user'", "'project_members' => 'project'"]
            as $pair
        ) {
            $this->assertStringContainsString($pair, $this->migration());
        }
    }

    public function testGuardStandsBeforeDroppingTheTable(): void
    {
        $content = $this->migration();
        $guard = strpos($content, 'throw new RuntimeException');
        $drop = strpos($content, "table('event_audience_sources')->drop()");
        $this->assertNotFalse($guard);
        $this->assertNotFalse($drop);
        $this->assertLessThan($drop, $guard);
    }

    public function testDownRefusesCombinedFilters(): void
    {
        $this->assertMatchesRegularExpression('/function down\(\).*?COUNT\(\*\).*?> 1.*?throw new RuntimeException/s', $this->migration());
    }
}
```

- [ ] **Step 2: rot**

Run: `ddev php vendor/bin/phpunit --filter "EventAudienceFilter" 2>&1 | tail -30`
Expected: FAIL.

- [ ] **Step 3: Migration**

`db/migrations/20261004090200_move_event_audience_to_filters.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Termin-Zielgruppen werden Filter: je alte Quelle ein Filter mit genau einer
 * Bedingung, ein Termin ohne Quelle bekommt einen Filter ohne Bedingung - er
 * galt für alle und gilt weiter für alle. An der Wirkung ändert sich nichts.
 */
final class MoveEventAudienceToFilters extends AbstractMigration
{
    private const CATEGORY_FOR_TYPE = [
        'role' => 'role',
        'voice_group' => 'voice_group',
        'user' => 'user',
        'project_members' => 'project',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo = $this->getAdapter()->getConnection();

        foreach ($this->fetchAll('SELECT id, event_id, source_type, reference_id FROM event_audience_sources') as $row) {
            $category = self::CATEGORY_FOR_TYPE[$row['source_type']] ?? null;
            if ($category === null) {
                continue;
            }
            $this->execute(sprintf(
                "INSERT INTO audience_filters (created_at, event_id) VALUES ('%s', %d)",
                $now,
                (int) $row['event_id']
            ));
            $this->execute(sprintf(
                "INSERT INTO audience_filter_conditions (audience_filter_id, category, reference_id) VALUES (%d, '%s', %d)",
                (int) $pdo->lastInsertId(),
                $category,
                (int) $row['reference_id']
            ));
        }

        $this->execute(sprintf(
            "INSERT INTO audience_filters (created_at, event_id)
             SELECT '%s', e.id FROM events e
             WHERE NOT EXISTS (SELECT 1 FROM event_audience_sources s WHERE s.event_id = e.id)",
            $now
        ));

        // Prüfung vor dem destruktiven Schritt: auswertbare Quellen 1:1 übertragen,
        // jeder Termin hat mindestens einen Filter. Unbekannte Quellarten trafen
        // bisher niemanden; sie gehen verloren und werden gezählt gemeldet.
        $expected = (int) ($this->fetchRow(
            "SELECT COUNT(*) AS n FROM event_audience_sources
             WHERE source_type IN ('role', 'voice_group', 'user', 'project_members')"
        )['n'] ?? 0);
        $emptyEvents = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM events e
             WHERE NOT EXISTS (SELECT 1 FROM event_audience_sources s WHERE s.event_id = e.id)'
        )['n'] ?? 0);
        $actual = (int) ($this->fetchRow('SELECT COUNT(*) AS n FROM audience_filters WHERE event_id IS NOT NULL')['n'] ?? 0);
        $withoutFilter = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM events e WHERE NOT EXISTS (SELECT 1 FROM audience_filters f WHERE f.event_id = e.id)'
        )['n'] ?? 0);
        if ($actual !== $expected + $emptyEvents || $withoutFilter > 0) {
            throw new RuntimeException(sprintf(
                'Termin-Zielgruppen unvollständig übertragen (erwartet %d Filter, vorhanden %d, Termine ohne Filter %d)'
                . ' - Abbruch vor dem Entfernen von event_audience_sources.',
                $expected + $emptyEvents,
                $actual,
                $withoutFilter
            ));
        }

        $this->table('event_audience_sources')->drop()->save();
    }

    public function down(): void
    {
        // Prüfung zuerst: Kombinierte Filter und Untergruppen kennt das alte Modell nicht.
        $complex = (int) ($this->fetchRow(
            "SELECT COUNT(*) AS n FROM audience_filters f WHERE f.event_id IS NOT NULL AND (
                (SELECT COUNT(*) FROM audience_filter_conditions c WHERE c.audience_filter_id = f.id) > 1
                OR EXISTS (SELECT 1 FROM audience_filter_conditions c
                           WHERE c.audience_filter_id = f.id AND c.category = 'sub_voice'))"
        )['n'] ?? 0);
        $allBesideOthers = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM audience_filters f WHERE f.event_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM audience_filter_conditions c WHERE c.audience_filter_id = f.id)
               AND (SELECT COUNT(*) FROM audience_filters g WHERE g.event_id = f.event_id) > 1'
        )['n'] ?? 0);
        if ($complex > 0 || $allBesideOthers > 0) {
            throw new RuntimeException(sprintf(
                '%d Termin-Filter sind kombiniert oder nutzen Untergruppen, %d stehen als "alle" neben weiteren'
                . ' - im alten Modell nicht darstellbar. Rückbau abgebrochen.',
                $complex,
                $allBesideOthers
            ));
        }

        $this->table('event_audience_sources')
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', ['values' => ['project_members', 'role', 'user', 'voice_group']])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['event_id', 'source_type', 'reference_id'], ['unique' => true, 'name' => 'uq_event_audience_source'])
            ->addForeignKey('event_id', 'events', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->execute(
            "INSERT INTO event_audience_sources (event_id, source_type, reference_id)
             SELECT f.event_id,
                    CASE c.category WHEN 'project' THEN 'project_members' ELSE c.category END,
                    c.reference_id
             FROM audience_filters f JOIN audience_filter_conditions c ON c.audience_filter_id = f.id
             WHERE f.event_id IS NOT NULL"
        );
        $this->execute('DELETE FROM audience_filters WHERE event_id IS NOT NULL');
    }
}
```

Vor dem Schreiben die tatsächliche Struktur von `event_audience_sources` prüfen (`ddev exec mysql -e "SHOW CREATE TABLE event_audience_sources" db`), damit `down()` sie inkl. Index-Namen trifft (Migrationen `20260825120100`, `20260901120000`, `20260923120000`). `DestructiveStepNeedsGuardTest`/`DestructiveMigrationGuardTest` listen ggf. Tabellen – dort `event_audience_sources` anpassen, wenn rot.

Run: `ddev exec ./vendor/bin/phinx migrate` (+ Testdatenbank).

- [ ] **Step 4: Model und Services**

`src/Models/Event.php`:

```php
    public function audienceFilters(): HasMany
    {
        return $this->hasMany(AudienceFilter::class, 'event_id', 'id');
    }

    /**
     * Bedingungsmengen der Zielgruppe; nutzt eine vorab geladene Beziehung
     * (`with('audienceFilters.conditions')`).
     *
     * @return list<array<string, list<int>>>
     */
    public function audienceConditionSets(): array
    {
        $filters = $this->relationLoaded('audienceFilters')
            ? $this->audienceFilters
            : $this->audienceFilters()->with('conditions')->orderBy('id')->get();

        return $filters->map(static fn (AudienceFilter $filter): array => $filter->conditionSet())->values()->all();
    }

    /**
     * Termine, deren Zielgruppe in mindestens einer Zeile das Projekt nennt.
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->whereHas('audienceFilters.conditions', static function ($condition) use ($projectId): void {
            $condition->where('category', AudienceFilterCondition::CATEGORY_PROJECT)->where('reference_id', $projectId);
        });
    }

    /**
     * (bisheriger Kommentar, angepasst:) aktive Mitglieder, die mindestens eine
     * Zeile der Zielgruppe trifft. Ein Termin ohne Zeile trifft niemanden.
     */
    public function eligibleUsersQuery(): Builder
    {
        return (new AudienceFilterService())
            ->membersQueryForSets($this->audienceConditionSets())
            ->select(array_map(static fn (string $column): string => 'users.' . $column, User::LIST_COLUMNS));
    }
```

`audienceSources()` und `referenceIdsFor()` entfernen. `Builder`-Import: `Illuminate\Database\Eloquent\Builder` (schon vorhanden). Wird `User::LIST_COLUMNS` irgendwo mit Tabellenpräfix erwartet, `pluck('id')` bei Aufrufern weiter funktionsfähig lassen (Spaltenname `id` bleibt).

`src/Models/Project.php::events()`: `return Event::query()->forProject((int) $this->id);`

`src/Services/EventAudienceService.php` – neu aufgebaut:

```php
class EventAudienceService
{
    public const OWNER = 'event_id';

    public function __construct(
        private readonly AudienceFilterService $filters = new AudienceFilterService(),
        private readonly AudienceFilterNormalizer $normalizer = new AudienceFilterNormalizer()
    ) {
    }

    /**
     * Zielgruppe aus dem Formular. Ein Termin braucht mindestens eine Zeile;
     * „alle Mitglieder“ ist eine Zeile mit Häkchen, kein leeres Formular.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, list<int>>>
     * @throws InvalidAudienceFilterException
     */
    public function readRows(array $data): array
    {
        $sets = $this->normalizer->normalizeRows(AudienceFormInput::rows($data['audience'] ?? []));
        if ($sets === []) {
            throw new InvalidAudienceFilterException('Bitte mindestens eine Zielgruppe angeben.');
        }

        return $sets;
    }

    /**
     * @param list<array<string, list<int>>> $conditionSets
     */
    public function setAudience(Event $event, array $conditionSets): void
    {
        $this->filters->replaceForOwner(self::OWNER, (int) $event->id, $conditionSets);
        // Eine vorab geladene Beziehung trägt sonst die alten Filter weiter.
        $event->unsetRelation('audienceFilters');
    }

    /**
     * @return list<array<string, list<int>>>
     */
    public function conditionSets(Event $event): array
    {
        return $event->audienceConditionSets();
    }

    /** @return Collection<int, User> */
    public function resolveEligibleUsers(Event $event): Collection
    {
        return $event->eligibleUsersQuery()->get();
    }

    /**
     * (bisheriger Kommentar zu eligibleUserIdsForEvents bleibt, Signatur jetzt aus
     * den Bedingungsmengen; vorab `with('audienceFilters.conditions')` laden.)
     *
     * @param iterable<Event> $events
     * @return array<int, list<int>>
     */
    public function eligibleUserIdsForEvents(iterable $events): array
    {
        $idsByEvent = [];
        $idsBySignature = [];
        foreach ($events as $event) {
            $sets = $event->audienceConditionSets();
            $signature = $this->signature($sets);
            if (!array_key_exists($signature, $idsBySignature)) {
                $idsBySignature[$signature] = $this->filters->membersQueryForSets($sets)
                    ->pluck('users.id')->map(static fn ($id): int => (int) $id)->all();
            }
            $idsByEvent[(int) $event->id] = $idsBySignature[$signature];
        }

        return $idsByEvent;
    }

    public function isUserEligible(Event $event, int $userId): bool
    {
        return $event->eligibleUsersQuery()->where('users.id', $userId)->exists();
    }

    /**
     * Termine, deren Zielgruppe das Mitglied trifft.
     */
    public function visibleEventsQuery(int $userId): Builder
    {
        $profile = $this->filters->profileOf($userId);
        $ids = $profile === null ? [] : $this->filters->matchingOwnerIds($profile, self::OWNER);

        return Event::query()->whereIn('events.id', $ids === [] ? [0] : $ids);
    }

    /**
     * @param list<array<string, list<int>>> $sets
     */
    private function signature(array $sets): string
    {
        $parts = array_map(fn (array $set): string => $this->normalizer->signature($set), $sets);
        sort($parts);

        return implode('|', $parts);
    }
}
```

`normalizeSources`, `referenceExists`, `userReferenceIds`, `getSources`, `setSources` entfallen.

`AttendanceScopeService::canAccessEvent()`:

```php
        $sets = $event->audienceConditionSets();
        foreach ($this->accessibleProfiles() as $profile) {
            if ($this->filters->fitsAny($profile, $sets)) {
                return true;
            }
        }

        return false;
```

`accessibleAudienceSets()`/`pivotReferenceIds()` werden zu

```php
    /**
     * Eigenes Profil und - wer für andere eintragen darf - die Profile der
     * verwaltbaren Mitglieder, jedes für sich. Gemischt werden die Merkmale nicht:
     * Bei UND-Bedingungen ergäbe Sopran von einem und Projekt vom anderen einen
     * Zugriff, den keiner der beiden hat.
     *
     * @return array<int, MemberProfile>
     */
    private function accessibleProfiles(): array
    {
        if ($this->profilesCache !== null) {
            return $this->profilesCache;
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $userIds = $userId > 0 ? [$userId] : [];
        if ($this->canManageOthers()) {
            $userIds = array_values(array_unique(array_merge($userIds, $this->getManageableUserIds())));
        }

        return $this->profilesCache = $userIds === [] ? [] : $this->filters->profilesOf($userIds);
    }
```

(`$filters` per Konstruktor-Default `new AudienceFilterService()`; Cache-Feld `?array $profilesCache = null` ersetzt `audienceSetsCache`. Der Sonderfall „ohne Quelle = alle“ entfällt: ein Filter ohne Bedingung passt in `fitsAny`.)

`CalendarFeedService`: `->with('audienceSources')` → `->with('audienceFilters.conditions')`; `buildAudienceLabel()` → `return $this->describer->summarizeSets($event->audienceConditionSets());` mit `AudienceDescriber` (per Konstruktor, Default über `new AudienceDescriber(new NameFormatterService(...))` wie im übrigen Code – den vorhandenen Weg der Klasse zum `NameFormatterService` nutzen). `audienceName()`-Cache entfällt; damit der Feed nicht je Termin nachschlägt, `AudienceDescriber::summarize` innerhalb einer Instanz Namen je Kategorie/Kennung zwischenspeichern lassen (privates Array).

Controller:
- `EventController`: `readAudienceSources()` entfernen. In `create()` und `update()` statt `normalizeSources`-Block:

```php
        $audienceService = new EventAudienceService();
        $formData['audience'] = AudienceFormInput::rows($data['audience'] ?? []);
        try {
            $audienceSets = $audienceService->readRows($data);
        } catch (InvalidAudienceFilterException $exception) {
            $createService = new ModalFormService('event_create');
            $createService->setError($exception->getMessage(), $formData);
            return $response->withHeader('Location', '/events')->withStatus(302);
        }
```

  (in `update()` mit `event_edit` und Redirect auf `/events/{id}/edit`). Alle `setSources($x, $sources)` → `setAudience($x, $audienceSets)`. Projektfilter der Liste (Zeile ~359) → `$query->forProject((int) $projectId)`. Spalte „Zielgruppe“ der Terminliste (Zeilen ~398–415): `$scopedEventIds` entfällt; `$events->load('audienceFilters.conditions')` und `$event->audience_label = $describer->summarizeSets($event->audienceConditionSets())` (ein Filter ohne Bedingung ergibt „Alle Mitglieder“). Test dazu in `EventFeatureTest`: Liste zeigt „Stimmgruppe: … · Projekt: …“ für einen kombinierten Termin. In `edit()`: `audience_sources` → `audience_rows` = `$describer->describeSets($editForm['audience'] ?? null ? $this->setsFromForm($editForm['audience']) : $audienceService->conditionSets($event))` – einfacher: wenn `$editForm['audience']` gesetzt ist (Fehlerfall), die Zeilen roh an das Template geben, sonst `describeSets(conditionSets($event))`. Für die Roh-Zeilen eine kleine Hilfsmethode im Controller:

```php
    /**
     * Formularzeilen nach einem Fehler wieder anzeigen, ohne sie zu prüfen.
     *
     * @param list<array{all: bool, conditions: array<string, list<string>>}> $rows
     * @return list<array<string, mixed>>
     */
    private function rowsForRedisplay(array $rows, AudienceDescriber $describer): array
    {
        return array_map(static function (array $row) use ($describer): array {
            $conditions = array_map(static fn (array $ids): array => array_map('intval', $ids), $row['conditions']);
            return [
                'label' => $row['all'] ? 'Alle Mitglieder' : $describer->summarize(array_filter($conditions)),
                'conditions' => $conditions,
                'all' => $row['all'],
                'missing' => [],
            ];
        }, $rows);
    }
```

  `options` für das Template: `$describer->options(AudienceDescriber::selectedIds($sets, 'project'), AudienceDescriber::selectedIds($sets, 'user'))`. Die bisher übergebenen `roles`, `voice_groups`, `users` nur entfernen, wenn `edit.twig` sie sonst nicht braucht. In `index()` (Anlege-Modal) analog: bei Fehler die Roh-Zeilen, sonst eine Zeile `[['label' => 'Alle Mitglieder', 'conditions' => [], 'all' => true, 'missing' => []]]`.
- `EvaluationController` (Zeile ~121): `->whereHas('audienceSources', …)` → `->forProject($projectId)`; `->with('audienceSources')` → `->with('audienceFilters.conditions')`.
- `AttendanceController`, `RegistrationController`: `with('audienceSources')` → `with('audienceFilters.conditions')`.
- `grep -rn "audienceSources\|EventAudienceSource\|InvalidAudienceSourcesException" src templates` muss danach leer sein; `src/Exceptions/InvalidAudienceSourcesException.php` löschen, wenn unbenutzt.

Templates:
- `templates/events/edit.twig` (Zeile 163) und `templates/events/index.twig` (Zeile 362): `{% include "events/_audience_sources.twig" … %}` ersetzen durch

```twig
<div class="mb-3">
    <label class="form-label">Zielgruppe</label>
    {{ include("partials/audience/filter_rows.twig", {
        prefix: "audience",
        rows: audience_rows,
        options: audience_options,
    }) }}
</div>
```

- Skript `events-audience.js` aus den Templates entfernen, `audience-filter.js` einbinden (`grep -rn "events-audience" templates`); `git rm templates/events/_audience_sources.twig public/js/events-audience.js`.

`tests/e2e/steps/events.mjs`: `pickAudienceUsers` auf die neue Zeile umstellen – in der ersten Zeile „Alle Mitglieder“ abhaken (`[data-audience-all]`), Zeile aufklappen (`[data-audience-toggle]`), im Feld `select[name$="[conditions][user][]"]` die TomSelect-Steuerung (`#<id>-ts-control`) befüllen; den Kommentar mit den Selektoren aktualisieren.

`DevSeedService::seedEventAudienceSources` → `seedEventAudience`: statt `EventAudienceSource::create` `(new AudienceFilterService())->create([...], 'event_id', $eventId)`; dieselben Projekt-/Stimmgruppen-/Rollen-Zuordnungen als je eigene Zeile; Termine ohne Quelle bekommen `create([], 'event_id', $id)`. Neu: ein Termin „Stimmprobe Sopran“ im laufenden Projekt mit einer Zeile `['voice_group' => [$sopranId], 'project' => [$projectId]]`. Bericht: `event_audience_sources` raus, `audience_filters_events` (Zahl `audience_filters` mit `event_id`) rein; `resetSeedData()`-Tabellenliste ohne `event_audience_sources`.

- [ ] **Step 5: neue Tests grün**

Run: `ddev php vendor/bin/phpunit --filter "EventAudienceFilter" 2>&1 | tail -30`
Expected: PASS.

- [ ] **Step 6: bestehende Tests umstellen**

Regel für jede Fundstelle: `EventAudienceSource::create(['event_id' => $e, 'source_type' => T, 'reference_id' => R])` → `$this->giveAudience('event_id', $e, [K => [R]])` mit K = `project` für `project_members`, sonst T (Trait `AudienceFixtures` einbinden). Mehrere Quellen eines Termins = mehrere Bedingungsmengen im selben Aufruf. Termine, die bisher ohne Quelle „für alle“ galten, bekommen `$this->giveAudience('event_id', $e, [])` – sonst trifft der Termin niemanden. `setSources($e, [...])` → `setAudience($e, [...])` mit Bedingungsmengen. Controller-Tests, die `sources_json` oder `sources[...]` posten, posten `audience[0][all]=1` bzw. `audience[0][conditions][project][]=<id>`.

Betroffen (`grep -rlE "EventAudienceSource|audienceSources|sources_json|setSources|event_audience_sources" tests`):
`AttendanceEventScopeFeatureTest`, `AttendanceRequiredFeatureTest`, `CalendarFeedIcsEscapingFeatureTest`, `CalendarFeedPastEventsFeatureTest`, `CalendarTaskFeedFeatureTest`, `EvaluationAttendanceQuotaFeatureTest`, `EventAudienceControllerFeatureTest`, `EventAudienceServiceFeatureTest`, `EventEligibleUsersScopeFeatureTest`, `EventFeatureTest`, `EventScopeVisibilityFeatureTest`, `EventSeriesScopeAndDeletionFeatureTest`, `ForbiddenResponseRendersReasonFeatureTest`, `NameDisplayPhpCoverageFeatureTest`, `NotificationEventTriggersFeatureTest`, `PendingRegistrationSummaryServiceFeatureTest`, `RegistrationEvaluationFeatureTest`, `RegistrationReminderServiceFeatureTest`, `RegistrationSaveFeatureTest`, `RegistrationViewFeatureTest`, `SchemaConstraintFeatureTest`, `SourceReplacementRefreshesRelationFeatureTest`, `SourceTableIndexCoverageTest`, `DestructiveMigrationGuardTest`, `tests/Unit/Migrations/DestructiveStepNeedsGuardTest.php`.

Tests, die den alten Typ „unbekannter `source_type` trifft niemanden“ oder die Normalisierung alter Quellen prüfen (`EventAudienceServiceFeatureTest`), werden durch die Fälle in `EventAudienceFilterFeatureTest` ersetzt und entfernt; in der Commit-Nachricht nennen, welche Aussagen wohin gewandert sind. `SchemaConstraintFeatureTest`/`SourceTableIndexCoverageTest`: Einträge für `event_audience_sources` entfernen.

Run: `ddev php vendor/bin/phpunit --filter "Event|Attendance|Registration|Calendar|Evaluation|Notification|Schema|Source|Destructive|Forbidden|NameDisplay|Pending" 2>&1 | tail -40`
Expected: PASS.

- [ ] **Step 7: Lint, Seed, Commit**

Run: `ddev composer phpcs`, `ddev composer twigcs`, `ddev exec env APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed` – Expected: ok, Bericht mit `audience_filters_events` > 0.

`/git-commit`: `feat(events): Termin-Zielgruppen als Filter mit UND-Verknüpfung`

---

### Task 5: Newsletter und Vorlagen über Filter

**Files:**
- Create: `db/migrations/20261004090300_move_newsletter_audience_to_filters.php`
- Modify: `src/Models/Newsletter.php`, `src/Models/NewsletterTemplate.php`, `src/Models/NewsletterRecipientSource.php`, `src/Models/NewsletterTemplateRecipientSource.php`, `src/Services/NewsletterRecipientService.php`, `src/Persistence/NewsletterTemplatePersistence.php`, `src/Controllers/NewsletterController.php`, `src/Controllers/NewsletterTemplateController.php`, `src/Services/DevSeedService.php` (Newsletter-Teil), `templates/newsletters/create.twig`, `templates/newsletters/edit.twig`, `templates/newsletters/templates_edit.twig`, `templates/newsletters/index.twig`, `templates/newsletters/details.twig`, `public/js/newsletters-create.js`, `public/js/newsletters-edit.js`
- Test: `tests/Feature/NewsletterAudienceFilterFeatureTest.php` (neu), `tests/Feature/NewsletterAudienceFilterMigrationFeatureTest.php` (neu) und die in Step 6 genannten

**Interfaces:**
- Consumes: Task 1, 3, 4 (`Event::eligibleUsersQuery`).
- Produces:
  - `Newsletter::audienceFilters(): HasMany` (`newsletter_id`), `NewsletterTemplate::audienceFilters(): HasMany` (`newsletter_template_id`)
  - `NewsletterRecipientService::readAudience(array $data): array{sets: list<array>, event_ids: list<int>}` (wirft `InvalidAudienceFilterException`; 0 Zeilen erlaubt)
  - `NewsletterRecipientService::setAudience(Newsletter $n, array $sets, array $eventIds): void` (ersetzt Filter und Termin-Quellen, löst Empfänger neu auf)
  - `NewsletterRecipientService::resolveFor(array $sets, array $eventIds): Collection<int, User>`; `resolveRecipients(Newsletter $n)` nutzt es
  - `NewsletterRecipientService::audienceOf(Newsletter $n): array{sets: list<array>, event_ids: list<int>}`
  - `NewsletterTemplatePersistence::audienceOf(NewsletterTemplate $t)` gleiches Format; `create(...)`/`update(...)` nehmen `?array $audience` (`['sets' => …, 'event_ids' => …]`) statt `$recipientSources`
  - Formularfelder `audience[i][…]` wie Termine, dazu `event_ids[]`
  - JSON `/newsletters/template/{id}`: `audience` (list von Bedingungsmengen) und `event_ids` statt `recipient_sources`

- [ ] **Step 1: Failing Tests**

`tests/Feature/NewsletterAudienceFilterFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Newsletter;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\NewsletterRecipientService;
use PHPUnit\Framework\TestCase;

class NewsletterAudienceFilterFeatureTest extends TestCase
{
    use FileFixtures;
    use AudienceFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function newsletter(): Newsletter
    {
        return Newsletter::create([
            'title' => 'Info ' . bin2hex(random_bytes(3)),
            'content_html' => '<p>Hallo</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => (int) $this->createMember('Autor')->id,
        ]);
    }

    public function testRecipientsAreFilterMembersOrEventAudience(): void
    {
        $sopranoInProject = $this->createMember('Sopran');
        $altoInProject = $this->createMember('Alt');
        $eventGuest = $this->createMember('Gast');
        $soprano = $this->createVoiceGroupFor($sopranoInProject);
        $project = $this->createProjectFor($sopranoInProject);
        $altoInProject->projects()->attach($project->id);
        $event = Event::create([
            'title' => 'Probe', 'starts_at' => '2030-01-01 19:00:00', 'ends_at' => '2030-01-01 21:00:00', 'type' => 'Probe',
        ]);
        $this->giveAudience('event_id', (int) $event->id, ['user' => [(int) $eventGuest->id]]);

        $service = new NewsletterRecipientService();
        $newsletter = $this->newsletter();
        $service->setAudience(
            $newsletter,
            [['voice_group' => [(int) $soprano->id], 'project' => [(int) $project->id]]],
            [(int) $event->id]
        );

        $ids = $service->resolveRecipients($newsletter->fresh())->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $expected = [(int) $sopranoInProject->id, (int) $eventGuest->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame(2, (int) $newsletter->fresh()->recipient_count);
    }

    public function testWithoutRowsAndEventsNobodyIsRecipient(): void
    {
        $this->createMember();
        $service = new NewsletterRecipientService();
        $newsletter = $this->newsletter();
        $service->setAudience($newsletter, [], []);

        $this->assertCount(0, $service->resolveRecipients($newsletter->fresh()));
    }

    public function testRowWithOnlyDeletedRoleIsRejected(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new NewsletterRecipientService())->readAudience(['audience' => [['conditions' => ['role' => ['999999999']]]]]);
    }

    public function testDeletingNewsletterRemovesFilters(): void
    {
        $service = new NewsletterRecipientService();
        $newsletter = $this->newsletter();
        $service->setAudience($newsletter, [[]], []);
        $id = (int) $newsletter->id;

        Newsletter::query()->whereKey($id)->delete();

        $this->assertSame(0, \App\Models\AudienceFilter::query()->where('newsletter_id', $id)->count());
    }
}
```

(`Newsletter::create`-Felder an `$fillable` des Models anpassen.)

`tests/Feature/NewsletterAudienceFilterMigrationFeatureTest.php` nach dem Muster von `EventAudienceFilterMigrationFeatureTest` (Datei `20261004090300_move_newsletter_audience_to_filters.php`): Enum von `newsletter_recipient_sources.source_type` und `newsletter_template_recipient_sources.source_type` ist genau `enum('event_attendees')` (`SHOW COLUMNS … LIKE 'source_type'`, Feld `Type`); Zuordnung `'project_members' => 'project'`, `'role' => 'role'`, `'user' => 'user'` steht in der Migration; `throw new RuntimeException` steht vor dem ersten `DELETE FROM newsletter_recipient_sources` bzw. vor dem `changeColumn('source_type'`; `down()` verweigert bei `COUNT(*) … > 1` und bei den Kategorien `voice_group`/`sub_voice`.

Controller-Test (in `NewsletterFeatureTest` oder neu in derselben Datei oben, über den vorhandenen `NewsletterControllerTestScaffold`): `POST /newsletters` mit `audience[0][conditions][role][]=<id>` und `event_ids[]=<eventId>` legt einen Filter mit `newsletter_id` und eine Quelle `event_attendees` an; `GET /newsletters?recipient_type=voice_group` listet nur Newsletter mit einer Stimmgruppen-Bedingung.

- [ ] **Step 2: rot**

Run: `ddev php vendor/bin/phpunit --filter "NewsletterAudienceFilter" 2>&1 | tail -30` – Expected: FAIL.

- [ ] **Step 3: Migration**

`db/migrations/20261004090300_move_newsletter_audience_to_filters.php` – gleicher Aufbau wie Task 4, für zwei Tabellen:

```php
final class MoveNewsletterAudienceToFilters extends AbstractMigration
{
    /** Quelltabelle => [Elternspalte, Besitzer-Spalte am Filter] */
    private const TABLES = [
        'newsletter_recipient_sources' => ['newsletter_id', 'newsletter_id'],
        'newsletter_template_recipient_sources' => ['newsletter_template_id', 'newsletter_template_id'],
    ];

    private const CATEGORY_FOR_TYPE = [
        'project_members' => 'project',
        'role' => 'role',
        'user' => 'user',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo = $this->getAdapter()->getConnection();
        foreach (self::TABLES as $table => [$parent, $owner]) {
            $rows = $this->fetchAll(
                "SELECT {$parent} AS parent_id, source_type, reference_id FROM {$table} WHERE source_type <> 'event_attendees'"
            );
            foreach ($rows as $row) {
                $category = self::CATEGORY_FOR_TYPE[$row['source_type']] ?? null;
                if ($category === null) {
                    continue;
                }
                $this->execute(sprintf(
                    "INSERT INTO audience_filters (created_at, %s) VALUES ('%s', %d)",
                    $owner,
                    $now,
                    (int) $row['parent_id']
                ));
                $this->execute(sprintf(
                    "INSERT INTO audience_filter_conditions (audience_filter_id, category, reference_id) VALUES (%d, '%s', %d)",
                    (int) $pdo->lastInsertId(),
                    $category,
                    (int) $row['reference_id']
                ));
            }
        }

        // Prüfung vor dem destruktiven Schritt.
        foreach (self::TABLES as $table => [, $owner]) {
            $expected = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} WHERE source_type IN ('project_members', 'role', 'user')"
            )['n'] ?? 0);
            $actual = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM audience_filters WHERE {$owner} IS NOT NULL"
            )['n'] ?? 0);
            if ($expected !== $actual) {
                throw new RuntimeException(sprintf(
                    '%s: %d Quellen, aber %d Filter - Abbruch vor dem Entfernen der übertragenen Quellen.',
                    $table,
                    $expected,
                    $actual
                ));
            }
        }

        foreach (array_keys(self::TABLES) as $table) {
            $this->execute("DELETE FROM {$table} WHERE source_type <> 'event_attendees'");
            $this->table($table)
                ->changeColumn('source_type', 'enum', ['values' => ['event_attendees'], 'null' => false])
                ->update();
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => [, $owner]) {
            $complex = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM audience_filters f WHERE f.{$owner} IS NOT NULL AND (
                    (SELECT COUNT(*) FROM audience_filter_conditions c WHERE c.audience_filter_id = f.id) <> 1
                    OR EXISTS (SELECT 1 FROM audience_filter_conditions c
                               WHERE c.audience_filter_id = f.id AND c.category IN ('voice_group', 'sub_voice')))"
            )['n'] ?? 0);
            if ($complex > 0) {
                throw new RuntimeException(sprintf(
                    '%s: %d Filter sind kombiniert, leer oder nutzen Stimm-/Untergruppen'
                    . ' - im alten Modell nicht darstellbar. Rückbau abgebrochen.',
                    $table,
                    $complex
                ));
            }
        }

        foreach (self::TABLES as $table => [$parent, $owner]) {
            $this->table($table)
                ->changeColumn('source_type', 'enum', [
                    'values' => ['project_members', 'event_attendees', 'role', 'user'],
                    'null' => false,
                ])
                ->update();
            $this->execute(
                "INSERT INTO {$table} ({$parent}, source_type, reference_id)
                 SELECT f.{$owner}, CASE c.category WHEN 'project' THEN 'project_members' ELSE c.category END, c.reference_id
                 FROM audience_filters f JOIN audience_filter_conditions c ON c.audience_filter_id = f.id
                 WHERE f.{$owner} IS NOT NULL"
            );
            $this->execute("DELETE FROM audience_filters WHERE {$owner} IS NOT NULL");
        }
    }
}
```

Vorher die Enum-Werte der beiden Tabellen nachsehen (`SHOW COLUMNS FROM newsletter_recipient_sources LIKE 'source_type'`) und in `down()` exakt so wiederherstellen; ebenso die Elternspalte der Vorlagen-Tabelle (`SHOW CREATE TABLE newsletter_template_recipient_sources`). `newsletters.created_at`-Spalten u. Ä. sind nicht betroffen.

- [ ] **Step 4: Models, Service, Persistenz, Controller**

Models: `Newsletter::audienceFilters()` / `NewsletterTemplate::audienceFilters()` (`hasMany(AudienceFilter::class, '<besitzer>')`). In `NewsletterRecipientSource` und `NewsletterTemplateRecipientSource` bleiben nur `TYPE_EVENT_ATTENDEES` (die anderen Konstanten löschen; `grep -rn "TYPE_PROJECT_MEMBERS\|TYPE_ROLE\|TYPE_USER" src tests` danach leer bzw. umgestellt).

`NewsletterRecipientService`:

```php
    public const OWNER = 'newsletter_id';

    public function __construct(
        private readonly AudienceFilterService $filters = new AudienceFilterService(),
        private readonly AudienceFilterNormalizer $normalizer = new AudienceFilterNormalizer()
    ) {
    }

    /**
     * Zielgruppe aus dem Formular: Filter-Zeilen (freiwillig) und Termine,
     * deren Zielgruppe mit angeschrieben wird.
     *
     * @param array<string, mixed> $data
     * @return array{sets: list<array<string, list<int>>>, event_ids: list<int>}
     * @throws InvalidAudienceFilterException
     */
    public function readAudience(array $data): array
    {
        $sets = $this->normalizer->normalizeRows(AudienceFormInput::rows($data['audience'] ?? []));
        $raw = is_array($data['event_ids'] ?? null) ? $data['event_ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($raw, 'is_scalar')), fn (int $id): bool => $id > 0)));
        $eventIds = Event::query()->whereIn('id', $ids ?: [0])->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return ['sets' => $sets, 'event_ids' => $eventIds];
    }

    /**
     * @param list<array<string, list<int>>> $sets
     * @param list<int> $eventIds
     */
    public function setAudience(Newsletter $newsletter, array $sets, array $eventIds): void
    {
        Capsule::connection()->transaction(function () use ($newsletter, $sets, $eventIds): void {
            $this->filters->replaceForOwner(self::OWNER, (int) $newsletter->id, $sets);
            $newsletter->recipientSources()->delete();
            foreach ($eventIds as $eventId) {
                $newsletter->recipientSources()->create([
                    'source_type' => NewsletterRecipientSource::TYPE_EVENT_ATTENDEES,
                    'reference_id' => $eventId,
                ]);
            }
        });
        // Vorab geladene Beziehungen tragen sonst die alte Zielgruppe weiter.
        $newsletter->unsetRelation('recipientSources');
        $newsletter->unsetRelation('audienceFilters');

        $this->setRecipients($newsletter, $this->resolveRecipients($newsletter)->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    /**
     * @return array{sets: list<array<string, list<int>>>, event_ids: list<int>}
     */
    public function audienceOf(Newsletter $newsletter): array
    {
        $sets = $this->filters->conditionSetsForOwners(self::OWNER, [(int) $newsletter->id])[(int) $newsletter->id];
        $sources = $newsletter->relationLoaded('recipientSources')
            ? $newsletter->recipientSources
            : $newsletter->recipientSources()->orderBy('id')->get();

        return ['sets' => $sets, 'event_ids' => $sources->pluck('reference_id')->map(fn ($id): int => (int) $id)->values()->all()];
    }

    /** @return Collection<int, User> */
    public function resolveRecipients(Newsletter $newsletter): Collection
    {
        $audience = $this->audienceOf($newsletter);

        return $this->resolveFor($audience['sets'], $audience['event_ids']);
    }

    /**
     * Mitglieder der Filter ODER der Zielgruppen der Termine, nur aktive.
     *
     * @param list<array<string, list<int>>> $sets
     * @param list<int> $eventIds
     * @return Collection<int, User>
     */
    public function resolveFor(array $sets, array $eventIds): Collection
    {
        $ids = $this->filters->membersQueryForSets($sets)->pluck('users.id')->all();
        foreach ($eventIds as $eventId) {
            $ids = array_merge($ids, $this->getEventAudience($eventId)->pluck('id')->all());
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return new Collection();
        }

        return User::query()->whereIn('id', $ids)->where('is_active', 1)->get();
    }
```

`normalizeSources`, `referenceExists`, `setSources`, `getSources`, `getProjectMembers`, `getUsersByRole`, `getActiveUser` entfernen, sofern nach `grep` unbenutzt (Tests ggf. umstellen).

`NewsletterTemplatePersistence`: `create(array $data, int $createdBy, ?int $projectId = null, array $audience = ['sets' => [], 'event_ids' => []])`, `update(..., ?array $audience = null)`; `setRecipientSources` → `setAudience(NewsletterTemplate $t, array $audience)` (Transaktion: `replaceForOwner('newsletter_template_id', …)`, Termin-Quellen ersetzen); `getRecipientSources` → `audienceOf(NewsletterTemplate $t): array{sets, event_ids}`. `clone` (Zeile ~60) reicht `audienceOf($source)` weiter.

`NewsletterTemplateController`: `SOURCE_FIELDS`/`recipientSourcesFromInput` ersetzen durch `$this->recipientService->readAudience($data)` (Fehler → bisheriger Fehlerweg mit Meldung der Exception); `show()` liefert `'audience' => $audience['sets'], 'event_ids' => $audience['event_ids']`; `storeFromNewsletter` übernimmt `$this->recipientService->audienceOf($newsletter)`.

`NewsletterController`:
- `validateNewsletterSourcesInput()` → `readAudience($data)` mit `try/catch (InvalidAudienceFilterException)`: `ok=false`, `message` = Meldung (JSON 422 bzw. Redirect mit Fehler wie die übrigen Validierungen in `store`/`update`).
- `store()`/`update()`: `setSources(...)` → `setAudience($newsletter, $audience['sets'], $audience['event_ids'])`.
- `resolveRecipientsPreview()`: `$count = $this->recipientService->resolveFor($audience['sets'], $audience['event_ids'])->count()`; `buildSourceCollection` entfällt.
- `create()`: `'audience_rows'` = mit Projekt `describeSets([['project' => [(int) $project->id]]])`, sonst `[]`; `'audience_options'` = `$describer->options()`; `'selected_event_ids' => []`.
- `edit()`: `audienceOf($newsletter)` → `'audience_rows' => describeSets($sets)`, `'audience_options' => options(selectedIds($sets,'project'), selectedIds($sets,'user'))`, `'selected_event_ids' => $audience['event_ids']`.
- `index()`: erlaubte `recipient_type`-Werte = `AudienceFilterCondition::CATEGORIES` + `'event'`. Abfrage: `'event'` → `whereHas('recipientSources')`, sonst `whereHas('audienceFilters.conditions', fn ($q) => $q->where('category', $recipientType))`. Template `index.twig`: Auswahl „Empfängerart“ mit Rolle, Stimmgruppe, Untergruppe, Projekt, Mitglied, Termin.
- `describeRecipientSources()` → Gruppen: `['label' => 'Zielgruppen', 'names' => array_map(summarize, $sets)]` (wenn nicht leer) und `['label' => 'Zielgruppe eines Termins', 'names' => <Titel wie bisher>]`; `recipientSourceNames()` bleibt nur für Termine.

Templates `newsletters/create.twig`, `newsletters/edit.twig`, `newsletters/templates_edit.twig`: die Abschnitte „Empfängerquellen“ ersetzen durch

```twig
<section class="mb-3" id="recipient-sources" aria-labelledby="recipient-sources-title">
    <h3 class="h6" id="recipient-sources-title">Empfänger</h3>
    {{ include("partials/audience/filter_rows.twig", {
        prefix: "audience",
        rows: audience_rows,
        options: audience_options,
        empty_hint: "Noch keine Zielgruppe gewählt.",
    }) }}
    <div class="mt-3">
        <label class="form-label" for="newsletter-event-ids">Zielgruppe eines Termins</label>
        <select id="newsletter-event-ids"
                name="event_ids[]"
                class="form-select"
                data-tom-select
                data-placeholder="Termine wählen …"
                multiple>
            {% for event in events %}
                <option value="{{ event.id }}" {{ event.id in selected_event_ids ? "selected" : "" }}>
                    {{ event.title }} ({{ event.starts_at|date("d.m.Y") }})
                </option>
            {% endfor %}
        </select>
    </div>
</section>
```

(in `templates_edit.twig` heißt die Überschrift wie bisher `h2`, ID-Präfix `template-`; die Variable der Termin-Kennungen dort `selected_event_ids` aus `NewsletterTemplateController::edit`). Die Zähler-Badges je Quelltyp entfallen; das Gesamt-Badge `recipient-count-badge` bleibt.

JS:
- `newsletters-create.js` / `newsletters-edit.js`: Alles rund um `newsletter-source-select`, `sources-hidden-inputs`, `syncSourcesHiddenInputs`, `buildRecipientSourcesPayload` entfernen. Die Felder sind jetzt echte Formularfelder; `new FormData(form)` enthält sie.
  - Vorschau: `const requestData = new URLSearchParams(); new FormData(form).forEach((value, key) => { if (key.startsWith("audience[") || key === "event_ids[]") { requestData.append(key, value); } });` und CSRF wie bisher.
  - Auslöser: `form.addEventListener("audience:change", refreshRecipientPreviewDebounced)` und `change` auf `#newsletter-event-ids`.
  - Snapshot in `newsletters-edit.js`: `audience` = die gefilterten FormData-Einträge als Array von `[key, value]`.
  - Vorlage übernehmen (`newsletters-create.js` ~Zeile 251): `window.AudienceFilter.setRows(form.querySelector("[data-audience-rows]"), data.audience || [])` und die Termin-Auswahl über `select.tomselect.setValue((data.event_ids || []).map(String))`.

`DevSeedService` (Newsletter, Zeilen ~4643–4693 und weitere `NewsletterRecipientSource::create`): Projekt-/Rollen-/Personen-Quellen über `(new AudienceFilterService())->create([...], 'newsletter_id', $id)`; `event_attendees` bleibt. Neu: ein Entwurf „Info an die Altstimmen“ mit Zeile `['voice_group' => [$altId]]`. Vorlagen-Seed analog mit `newsletter_template_id`. Bericht: `audience_filters_newsletters`, `audience_filters_newsletter_templates`.

- [ ] **Step 5: neue Tests grün**

Run: `ddev php vendor/bin/phpunit --filter "NewsletterAudienceFilter" 2>&1 | tail -30` – Expected: PASS.

- [ ] **Step 6: bestehende Tests umstellen**

Regel: `NewsletterRecipientSource::create(['newsletter_id' => N, 'source_type' => T, 'reference_id' => R])` → bei `event_attendees` unverändert, sonst `$this->giveAudience('newsletter_id', N, [K => [R]])` (K = `project` für `project_members`). Posts mit `sources[i][type]`/`sources[i][reference_id]` → `audience[i][conditions][K][]=R` bzw. `event_ids[]=R`. Vorlagen-Posts mit `source_role[]` usw. → dieselben Felder.

Betroffen: `NewsletterArchiveSubjectFeatureTest`, `NewsletterControllerTestScaffold`, `NewsletterEventAudienceRecipientsFeatureTest`, `NewsletterFeatureTest`, `NewsletterPersonalizedSendFeatureTest`, `NewsletterProjectDecouplingFeatureTest`, `NewsletterSendArchiveFeatureTest`, `NewsletterSendClaimReleaseFeatureTest`, `NewsletterSentDetailsFeatureTest`, `NewsletterTemplateCreateSettingsFeatureTest`, `NewsletterTemplateSettingsFeatureTest`, `DashboardFeatureTest`, `SourceReplacementRefreshesRelationFeatureTest`, `SchemaConstraintFeatureTest`, `SourceTableIndexCoverageTest`.

Run: `ddev php vendor/bin/phpunit --filter "Newsletter|Dashboard|Source|Schema" 2>&1 | tail -40` – Expected: PASS.

- [ ] **Step 7: Lint, Seed, Commit**

`ddev composer phpcs`, `ddev composer twigcs`, Seed wie in Task 4 – Expected: ok.

`/git-commit`: `feat(newsletter): Empfänger und Vorlagen über Zielgruppen-Filter`

---

### Task 6: Genau ein Besitzer je Filter

**Files:**
- Create: `db/migrations/20261004090400_require_single_audience_filter_owner.php`
- Test: `tests/Feature/AudienceFilterOwnerFeatureTest.php` (ergänzen)

**Interfaces:**
- Consumes: Tasks 1–5 (alle Filter haben Besitzer).
- Produces: `CHECK` `chk_audience_filters_single_owner`.

- [ ] **Step 1: Failing Tests**

In `AudienceFilterOwnerFeatureTest` ergänzen:

```php
    public function testFilterWithoutOwnerIsRejectedByDatabase(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('audience_filters')->insert(['created_at' => date('Y-m-d H:i:s')]);
    }

    public function testFilterWithTwoOwnersIsRejectedByDatabase(): void
    {
        $event = $this->event();
        $newsletter = \App\Models\Newsletter::create([
            'title' => 'X',
            'content_html' => '<p>x</p>',
            'status' => \App\Models\Newsletter::STATUS_DRAFT,
            'created_by' => (int) $this->createMember()->id,
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('audience_filters')->insert([
            'created_at' => date('Y-m-d H:i:s'),
            'event_id' => (int) $event->id,
            'newsletter_id' => (int) $newsletter->id,
        ]);
    }
```

- [ ] **Step 2: rot**

Run: `ddev php vendor/bin/phpunit --filter AudienceFilterOwnerFeatureTest 2>&1 | tail -20` – Expected: 2 FAIL.

- [ ] **Step 3: Migration**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Jeder Filter gehört genau einem Besitzer. Ohne Besitzer räumt ihn kein
 * Fremdschlüssel mehr ab; mit zweien wäre unklar, wessen Zielgruppe er ist.
 */
final class RequireSingleAudienceFilterOwner extends AbstractMigration
{
    private const EXPRESSION = '(event_id IS NOT NULL) + (newsletter_id IS NOT NULL) + (newsletter_template_id IS NOT NULL)'
        . ' + (file_folder_share_id IS NOT NULL) + (file_share_id IS NOT NULL)';

    public function up(): void
    {
        $broken = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM audience_filters WHERE ' . self::EXPRESSION . ' <> 1'
        )['n'] ?? 0);
        if ($broken > 0) {
            throw new RuntimeException(sprintf(
                '%d Filter ohne oder mit mehreren Besitzern - CHECK nicht angelegt.',
                $broken
            ));
        }

        $this->execute(
            'ALTER TABLE audience_filters ADD CONSTRAINT chk_audience_filters_single_owner CHECK ('
            . self::EXPRESSION . ' = 1)'
        );
        $this->table('audience_filters')->update();
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE audience_filters DROP CONSTRAINT chk_audience_filters_single_owner');
        $this->table('audience_filters')->update();
    }
}
```

(Endet die Kette mit `update()` – `MigrationChainCompletionTest` prüft Ketten; ein reines `execute` ist keine Kette. Läuft der Test trotzdem rot, die beiden `->update()` entfernen.)

Run: Migration lokal und für die Testdatenbank.

- [ ] **Step 4: grün**

Run: `ddev php vendor/bin/phpunit --filter "AudienceFilterOwnerFeatureTest|MigrationChainCompletion" 2>&1 | tail -20` – Expected: PASS.

- [ ] **Step 5: Commit**

`/git-commit`: `feat(audience): jeder Filter gehört genau einem Besitzer`

---

### Task 7: Hilfe-Hinweise, Rückbau-Handlauf, Schärfeprobe, Abschluss

**Files:**
- Modify: Hilfeseiten zu Terminen und Newslettern (`grep -rln "Zielgruppe\|Empfängerquellen" help/`), `help/files/docs/files-sharing.md` (Hinweis bleibt)

- [ ] **Step 1: Hilfe-Hinweise**

In den gefundenen Seiten zu Terminen und Newslettern je einen kurzen Absatz unter der Stelle zur Zielgruppe:

```markdown
> **Hinweis:** Die Auswahl der Zielgruppe hat sich geändert. Eine Zielgruppe besteht jetzt aus
> Zeilen: Innerhalb eines Feldes genügt ein Wert, zwischen den Feldern müssen alle zutreffen,
> und mehrere Zeilen gelten nebeneinander. Die ausführliche Beschreibung folgt.
```

Keine Rollennamen nennen (AGENT.md).

- [ ] **Step 2: Rückbau-Handlauf**

Auf der Entwicklungsdatenbank nach frischem Seed:

```bash
ddev exec ./vendor/bin/phinx rollback -t 20261002090100
```

Expected: bricht in `MoveEventAudienceToFilters::down()` bzw. `MoveNewsletterAudienceToFilters::down()` mit Meldung ab (Seed enthält kombinierte Filter). Danach die kombinierten Seed-Filter per SQL löschen (`DELETE FROM audience_filters WHERE id IN (…)` für die Neuzugänge aus Task 4/5), erneut zurückrollen – Expected: ok, `event_audience_sources` und Quelltabellen gefüllt, `audience_filter_id` an den Freigaben wieder da. Dann `ddev exec ./vendor/bin/phinx migrate` – Expected: ok. Abschließend Seed neu (`--mode=reset-and-seed`). Ergebnis in der Commit-Nachricht festhalten.

- [ ] **Step 3: Schärfeprobe**

Je Sabotage den Produktivcode kurz ändern, gefilterten Test laufen lassen, Rotlauf notieren, zurücksetzen (`git checkout -- <datei>`):

1. `AudienceFilterService::fits()` – `return false` bei fehlender Kategorie zu `continue` (UND → ODER). Erwartet rot: `EventAudienceFilterFeatureTest::testSopranoAndProjectReachesOnlySopranosInProject`, `AudienceFilterOwnerFeatureTest::testMatchingOwnerIdsUsesAndWithinAFilter`.
2. `AttendanceScopeService::accessibleProfiles()` – ein gemischtes Profil aus allen Mitgliedern bauen (alle Rollen/Stimmgruppen/Projekte in ein `MemberProfile`). Erwartet rot: `testManagedMembersAreNotMixed`.
3. `CHECK` per `ALTER TABLE audience_filters DROP CONSTRAINT chk_audience_filters_single_owner` in der Testdatenbank entfernen. Erwartet rot: beide CHECK-Tests aus Task 6. Danach Testdatenbank neu migrieren.
4. In `20261004090200_move_event_audience_to_filters.php` die Prüfung entfernen. Erwartet rot: `EventAudienceFilterMigrationFeatureTest::testGuardStandsBeforeDroppingTheTable` und `DestructiveStepNeedsGuardTest`.
5. `EventAudienceService::readRows()` – die `=== []`-Prüfung entfernen. Erwartet rot: `testEmptyFormIsRejected`.

- [ ] **Step 4: volle Suite und Lint**

Run: `ddev composer test:parallel 2>&1 | tail -15` – Expected: grün (inkl. `eol:check`).
Run: `ddev composer phpcs` und `ddev composer twigcs` – Expected: keine Verstöße.
`git ls-files --eol | grep -v "w/lf" | grep -vE "\.(bat|cmd|ps1)$"` – Expected: keine neuen Treffer.

- [ ] **Step 5: Abschluss-Review und Landung**

Ein Review über den ganzen Branch (Subagent, siehe Memory „Workflow-Aufwand“), Befunde selbst beheben. Dann `/git-commit` bzw. `/squash-branch` und auf `main` bringen; danach einmal fragen „Nach `origin/main` pushen?“.

# Zielgruppen-Filter mit UND-Verknüpfung – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ordner- und Dateifreigaben bekommen statt eines einzelnen Ziels einen Filter aus Bedingungen (ODER innerhalb einer Kategorie, UND zwischen Rolle, Stimmgruppe, Untergruppe, Projekt und Mitglied).

**Architecture:** Ein gemeinsamer Baustein unter `src/Services/Audience/` (Tabellen `audience_filters`, `audience_filter_conditions`, `AudienceFilterService`, `AudienceFilterNormalizer`). Die Freigabe-Tabellen verweisen über `audience_filter_id` darauf; `FileAccessService` fragt die passenden Filter ab statt nach Zieltypen zu filtern. Der Bestand wird per Migration umgestellt.

**Tech Stack:** PHP 8.5, Slim 4, Eloquent (illuminate/database), Phinx, Twig, PHPUnit 13, Vanilla-JS, TomSelect (lokal unter `public/vendor/tom-select`).

**Spec:** `docs/superpowers/specs/2026-10-02-audience-filters-design.md`

## Global Constraints

- Schemaänderungen nur per Phinx-Migration; jede Kette endet mit `create()`/`save()`/`update()` (`MigrationChainCompletionTest`).
- Destruktive Schritte (`DROP COLUMN`, `MODIFY … NOT NULL`) erst nach einer Prüfung, die mit `RuntimeException` abbricht – die Prüfung steht davor, nie danach.
- Bezeichner englisch, Inhalte (Kommentare, UI, Testbeschreibungen, Commit-Nachrichten) deutsch mit echten Umlauten.
- PSR-12, Zeilenlänge hart 130 (`ddev composer phpcs`); Twig mit doppelten Anführungszeichen (`ddev composer twigcs`); kein Inline-JS, kein Inline-CSS außer `--progress-value`.
- Zeilenenden LF.
- Stimmgruppen immer in der Reihenfolge Sopran, Alt, Tenor, Bass (im Bestand: `VoiceGroup::query()->orderBy('id')`); Untergruppen alphabetisch.
- Kategorien exakt: `role`, `voice_group`, `sub_voice`, `project`, `user`.
- Ordnerfreigaben: Stufen 1–4 (`FileFolderShare::LEVEL_LABELS`); Dateifreigaben: 1 und 3 (`FileShare::LEVELS`).
- Tests laufen gegen die Testdatenbank: gefiltert `ddev php vendor/bin/phpunit --filter "<Muster>"`, voll `ddev composer test:parallel`.
- Neue Hilfeseiten nur auf Anforderung (AGENT.md, „Hilfetexte“).

## Review Focus

1. **Alt-Freigabe `all_members` mit `reference_id` ≠ 0** (aus Handarbeit an der Datenbank): Erwartung – wird trotzdem ein Filter ohne Bedingung, also „alle“, nicht „niemand“. Test in Task 3 (`all_members` steht bewusst nicht in der Zuordnungstabelle).
2. **Freigabe-Formular mit fremden Kennungen** (Rolle, die es nicht gibt; Projekt-ID eines gelöschten Projekts): fallen still heraus; bleibt danach nichts übrig und „Alle Mitglieder“ ist nicht gesetzt, wird die Zeile abgelehnt statt zur Freigabe für alle zu werden. Test in Task 2.
3. **Selbstaussperren über einen Filter:** Der Verwalter ersetzt seine eigene Freigabe durch einen Filter, der ihn nicht mehr trifft (z. B. Projekt, in dem er nicht ist). Erwartung: 422 wie bisher, nichts gespeichert. Test in Task 4.
4. **Vorschau ohne Verwalten-Recht:** Ein Mitglied mit nur Lesen ruft `/files/audience-preview` auf. Erwartung: 403, keine Zahl. Test in Task 5.
5. **Filter mit gelöschtem Bezug neben gültigem Wert derselben Kategorie** (Stimmgruppe: gelöschte ID, Sopran): Sopran trifft weiterhin. Test in Task 1.

---

## Dateistruktur

| Datei | Verantwortung |
| --- | --- |
| `db/migrations/20261002090000_create_audience_filter_tables.php` | Neue Tabellen |
| `db/migrations/20261002090100_move_file_shares_to_audience_filters.php` | Bestand umstellen, alte Spalten entfernen |
| `src/Models/AudienceFilter.php`, `src/Models/AudienceFilterCondition.php` | Eloquent-Modelle |
| `src/Services/Audience/MemberProfile.php` | Wertobjekt: Zugehörigkeiten eines Mitglieds |
| `src/Services/Audience/AudienceFilterService.php` | Profil laden, Filter auswerten, Filter anlegen/löschen, Mitglieder-Abfrage |
| `src/Services/Audience/AudienceFilterNormalizer.php` | Formularwerte prüfen, sortieren, Signatur bilden |
| `src/Services/Audience/InvalidAudienceFilterException.php` | Abgelehnte Zeile (leer ohne „Alle“) |
| `src/Services/Files/FileAccessService.php` | `matchingLevels` über Filter |
| `src/Services/Files/FileFolderService.php`, `FileShareService.php` | Freigaben über Filter speichern |
| `src/Services/Files/FileShareDescriber.php` | Zusammenfassung je Filter, Auswahllisten inkl. Untergruppen |
| `src/Controllers/Concerns/FileControllerSupport.php` | `parseShareRows` für das neue Formular |
| `src/Controllers/FileAudienceController.php` | `POST /files/audience-preview` |
| `templates/files/partials/share_row.twig` | Filter-Zeile |
| `public/js/file-audience.js` | Aufklappen, „Alle“-Häkchen, Trefferzahl |
| `tests/Feature/AudienceFilterServiceFeatureTest.php`, `AudienceFilterNormalizerFeatureTest.php`, `AudienceFilterMigrationFeatureTest.php`, `FileAudienceFeatureTest.php` | Neue Tests |
| `tests/Feature/FileFixtures.php` | `share()` legt Filter an, neu `shareWith()` |

---

### Task 1: Filter-Tabellen, Modelle und Auswertung

**Files:**
- Create: `db/migrations/20261002090000_create_audience_filter_tables.php`
- Create: `src/Models/AudienceFilter.php`, `src/Models/AudienceFilterCondition.php`
- Create: `src/Services/Audience/MemberProfile.php`, `src/Services/Audience/AudienceFilterService.php`
- Test: `tests/Feature/AudienceFilterServiceFeatureTest.php`

**Interfaces:**
- Produces:
  - `AudienceFilterCondition::CATEGORY_ROLE|CATEGORY_VOICE_GROUP|CATEGORY_SUB_VOICE|CATEGORY_PROJECT|CATEGORY_USER` und `AudienceFilterCondition::CATEGORIES` (list in genau dieser Reihenfolge)
  - `MemberProfile::__construct(int $userId, list<int> $roleIds, list<int> $voiceGroupIds, list<int> $subVoiceIds, list<int> $projectIds)`, `MemberProfile::has(string $category, int $id): bool`
  - `AudienceFilterService::profileOf(int $userId): ?MemberProfile`
  - `AudienceFilterService::matchingFilterIds(MemberProfile $profile, array $filterIds): array` (list<int>)
  - `AudienceFilterService::conditionsOf(array $filterIds): array` (`array<int, array<string, list<int>>>`, jeder angefragte Filter als Schlüssel, leere Filter mit `[]`)
  - `AudienceFilterService::create(array $conditions): AudienceFilter` (`$conditions`: `array<string, list<int>>`)
  - `AudienceFilterService::delete(array $filterIds): void`
  - `AudienceFilterService::membersQuery(int $filterId): Builder` (Eloquent-Builder auf `User`)

- [ ] **Step 1: Migration für die Tabellen schreiben**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zielgruppen-Filter: ein Filter besteht aus Bedingungen je Kategorie.
 * Innerhalb einer Kategorie genügt ein Wert, zwischen Kategorien müssen alle
 * zutreffen. reference_id hat keinen Fremdschlüssel, weil die Bedingung je
 * nach Kategorie auf eine andere Tabelle zeigt; ein gelöschter Bezug trifft
 * danach schlicht niemanden.
 */
final class CreateAudienceFilterTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('audience_filters')
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->create();

        $this->table('audience_filter_conditions')
            ->addColumn('audience_filter_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('category', 'enum', [
                'values' => ['role', 'voice_group', 'sub_voice', 'project', 'user'],
                'null' => false,
            ])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['audience_filter_id', 'category', 'reference_id'], [
                'unique' => true,
                'name' => 'uniq_audience_filter_conditions',
            ])
            ->addIndex(['category', 'reference_id'])
            ->addForeignKey('audience_filter_id', 'audience_filters', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_audience_filter_conditions_filter',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('audience_filter_conditions')->drop()->save();
        $this->table('audience_filters')->drop()->save();
    }
}
```

Run: `ddev exec ./vendor/bin/phinx migrate` → Expected: `CreateAudienceFilterTables: migrated`.

- [ ] **Step 2: Modelle anlegen**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Zielgruppe aus Bedingungen; gehört genau einer Freigabe. */
class AudienceFilter extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audience_filters';

    protected $fillable = [];

    public function conditions(): HasMany
    {
        return $this->hasMany(AudienceFilterCondition::class, 'audience_filter_id');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudienceFilterCondition extends Model
{
    public const CATEGORY_ROLE = 'role';
    public const CATEGORY_VOICE_GROUP = 'voice_group';
    public const CATEGORY_SUB_VOICE = 'sub_voice';
    public const CATEGORY_PROJECT = 'project';
    public const CATEGORY_USER = 'user';

    /** Reihenfolge gilt auch für Anzeige und Formular. */
    public const CATEGORIES = [
        self::CATEGORY_ROLE,
        self::CATEGORY_VOICE_GROUP,
        self::CATEGORY_SUB_VOICE,
        self::CATEGORY_PROJECT,
        self::CATEGORY_USER,
    ];

    public $timestamps = false;

    protected $table = 'audience_filter_conditions';

    protected $fillable = ['audience_filter_id', 'category', 'reference_id'];

    protected $casts = [
        'audience_filter_id' => 'integer',
        'reference_id' => 'integer',
    ];
}
```

- [ ] **Step 3: Failing Test schreiben**

`tests/Feature/AudienceFilterServiceFeatureTest.php` – nutzt `FileFixtures` (Transaktion, `createMember`, `createRoleFor`, `createVoiceGroupFor`, `createProjectFor`).

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilterCondition as C;
use App\Models\SubVoice;
use App\Services\Audience\AudienceFilterService;
use PHPUnit\Framework\TestCase;

class AudienceFilterServiceFeatureTest extends TestCase
{
    use FileFixtures;

    private AudienceFilterService $filters;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->filters = new AudienceFilterService();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function matches(int $userId, array $conditions): bool
    {
        $filter = $this->filters->create($conditions);
        $profile = $this->filters->profileOf($userId);

        return $this->filters->matchingFilterIds($profile, [(int) $filter->id]) === [(int) $filter->id];
    }

    public function testEmptyFilterMatchesEveryone(): void
    {
        $this->assertTrue($this->matches((int) $this->createMember()->id, []));
    }

    public function testOrWithinCategoryAndAcrossCategories(): void
    {
        $sopran = $this->createMember('Sopran');
        $soprano = $this->createVoiceGroupFor($sopran, 'Sopran');
        $project = $this->createProjectFor($sopran, 'Frühjahrskonzert');
        $alto = $this->createMember('Alt');
        $altGroup = $this->createVoiceGroupFor($alto, 'Alt');
        $alto->projects()->attach($project->id);
        $outsider = $this->createMember('Sopran außerhalb');
        $outsider->voiceGroups()->attach($soprano->id);

        $both = [C::CATEGORY_VOICE_GROUP => [(int) $soprano->id, (int) $altGroup->id], C::CATEGORY_PROJECT => [(int) $project->id]];
        $this->assertTrue($this->matches((int) $sopran->id, $both));
        $this->assertTrue($this->matches((int) $alto->id, $both));
        $this->assertFalse($this->matches((int) $outsider->id, $both), 'Sopran ohne Projekt.');

        $onlySoprano = [C::CATEGORY_VOICE_GROUP => [(int) $soprano->id], C::CATEGORY_PROJECT => [(int) $project->id]];
        $this->assertFalse($this->matches((int) $alto->id, $onlySoprano), 'Alt im Projekt.');
    }

    public function testEachCategoryOnItsOwn(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $group = $this->createVoiceGroupFor($member);
        $sub = SubVoice::create(['name' => 'Sopran 1', 'voice_group_id' => $group->id]);
        $member->voiceGroups()->updateExistingPivot($group->id, ['sub_voice_id' => $sub->id]);
        $project = $this->createProjectFor($member);
        $other = $this->createMember('Andere');

        foreach ([
            [C::CATEGORY_ROLE, (int) $role->id],
            [C::CATEGORY_VOICE_GROUP, (int) $group->id],
            [C::CATEGORY_SUB_VOICE, (int) $sub->id],
            [C::CATEGORY_PROJECT, (int) $project->id],
            [C::CATEGORY_USER, (int) $member->id],
        ] as [$category, $id]) {
            $this->assertTrue($this->matches((int) $member->id, [$category => [$id]]), $category);
            $this->assertFalse($this->matches((int) $other->id, [$category => [$id]]), $category . ' fremd');
        }
    }

    public function testDeletedReferenceBlocksButValidSiblingStillMatches(): void
    {
        $member = $this->createMember();
        $group = $this->createVoiceGroupFor($member);

        $this->assertFalse($this->matches((int) $member->id, [C::CATEGORY_ROLE => [999999]]));
        $this->assertTrue($this->matches((int) $member->id, [C::CATEGORY_VOICE_GROUP => [999999, (int) $group->id]]));
    }

    public function testMembersQueryCountsOnlyActiveMatches(): void
    {
        $active = $this->createMember('Aktiv');
        $role = $this->createRoleFor($active);
        $inactive = $this->createMember('Inaktiv');
        $inactive->roles()->attach($role->id);
        $inactive->is_active = 0;
        $inactive->save();

        $filter = $this->filters->create([C::CATEGORY_ROLE => [(int) $role->id]]);

        $this->assertSame([(int) $active->id], $this->filters->membersQuery((int) $filter->id)->pluck('users.id')->map(fn ($id) => (int) $id)->all());
    }

    public function testConditionsOfAndDelete(): void
    {
        $filter = $this->filters->create([C::CATEGORY_PROJECT => [7, 3], C::CATEGORY_ROLE => [2]]);
        $empty = $this->filters->create([]);

        $this->assertSame(
            [(int) $filter->id => [C::CATEGORY_ROLE => [2], C::CATEGORY_PROJECT => [3, 7]], (int) $empty->id => []],
            $this->filters->conditionsOf([(int) $filter->id, (int) $empty->id])
        );

        $this->filters->delete([(int) $filter->id]);
        $this->assertSame([(int) $empty->id => []], $this->filters->conditionsOf([(int) $filter->id, (int) $empty->id]));
    }
}
```

Zeilen über 130 Zeichen beim Abtippen umbrechen (phpcs prüft `tests/` nicht, die Projektregel gilt trotzdem).

- [ ] **Step 4: Rot sehen**

Run: `ddev php vendor/bin/phpunit --filter AudienceFilterService`
Expected: FAIL – `Class "App\Services\Audience\AudienceFilterService" not found`.

- [ ] **Step 5: `MemberProfile` und `AudienceFilterService` schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\AudienceFilterCondition as C;

/** Zugehörigkeiten eines Mitglieds, einmal je Prüfung geladen. */
final class MemberProfile
{
    /** @var array<string, array<int, true>> */
    private array $index;

    /**
     * @param list<int> $roleIds
     * @param list<int> $voiceGroupIds
     * @param list<int> $subVoiceIds
     * @param list<int> $projectIds
     */
    public function __construct(
        public readonly int $userId,
        array $roleIds,
        array $voiceGroupIds,
        array $subVoiceIds,
        array $projectIds
    ) {
        $this->index = [
            C::CATEGORY_ROLE => array_fill_keys($roleIds, true),
            C::CATEGORY_VOICE_GROUP => array_fill_keys($voiceGroupIds, true),
            C::CATEGORY_SUB_VOICE => array_fill_keys($subVoiceIds, true),
            C::CATEGORY_PROJECT => array_fill_keys($projectIds, true),
            C::CATEGORY_USER => [$userId => true],
        ];
    }

    public function has(string $category, int $id): bool
    {
        return isset($this->index[$category][$id]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\AudienceFilter;
use App\Models\AudienceFilterCondition as C;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;

/**
 * Zielgruppen-Filter auswerten: innerhalb einer Kategorie ODER, zwischen den
 * Kategorien UND, ein Filter ohne Bedingung trifft alle. Ausgewertet wird in
 * PHP über die geladenen Bedingungen – die Regel steht damit an genau einer
 * Stelle, und für einen Chorbestand ist das schneller als verschachteltes SQL.
 */
final class AudienceFilterService
{
    public function profileOf(int $userId): ?MemberProfile
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        $pivot = DB::table('user_voice_groups')->where('user_id', $userId)->get(['voice_group_id', 'sub_voice_id']);

        return new MemberProfile(
            $userId,
            self::ints($user->roles()->pluck('role_id')),
            self::ints($pivot->pluck('voice_group_id')),
            self::ints($pivot->pluck('sub_voice_id')->filter()),
            self::ints($user->projects()->pluck('project_id'))
        );
    }

    /**
     * @param list<int> $filterIds
     * @return list<int>
     */
    public function matchingFilterIds(MemberProfile $profile, array $filterIds): array
    {
        $matching = [];
        foreach ($this->conditionsOf($filterIds) as $filterId => $categories) {
            $fits = true;
            foreach ($categories as $category => $ids) {
                $any = false;
                foreach ($ids as $id) {
                    if ($profile->has($category, $id)) {
                        $any = true;
                        break;
                    }
                }
                if (!$any) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                $matching[] = $filterId;
            }
        }

        return $matching;
    }

    /**
     * @param list<int> $filterIds
     * @return array<int, array<string, list<int>>>
     */
    public function conditionsOf(array $filterIds): array
    {
        $filterIds = array_values(array_unique(array_map('intval', $filterIds)));
        if ($filterIds === []) {
            return [];
        }

        $existing = AudienceFilter::query()->whereIn('id', $filterIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $result = array_fill_keys($existing, []);
        $rows = DB::table('audience_filter_conditions')
            ->whereIn('audience_filter_id', $existing ?: [0])
            ->orderBy('reference_id')
            ->get(['audience_filter_id', 'category', 'reference_id']);
        foreach ($rows as $row) {
            $result[(int) $row->audience_filter_id][(string) $row->category][] = (int) $row->reference_id;
        }

        foreach ($result as $filterId => $categories) {
            $ordered = [];
            foreach (C::CATEGORIES as $category) {
                if (isset($categories[$category])) {
                    $ordered[$category] = $categories[$category];
                }
            }
            $result[$filterId] = $ordered;
        }

        return $result;
    }

    /**
     * @param array<string, list<int>> $conditions bereits normalisiert
     */
    public function create(array $conditions): AudienceFilter
    {
        $filter = AudienceFilter::create([]);
        foreach ($conditions as $category => $ids) {
            foreach ($ids as $id) {
                $filter->conditions()->create(['category' => $category, 'reference_id' => (int) $id]);
            }
        }

        return $filter;
    }

    /**
     * @param list<int> $filterIds
     */
    public function delete(array $filterIds): void
    {
        if ($filterIds !== []) {
            AudienceFilter::query()->whereIn('id', $filterIds)->delete();
        }
    }

    /**
     * Aktive Mitglieder, die der Filter trifft.
     *
     * @return Builder<User>
     */
    public function membersQuery(int $filterId): Builder
    {
        $query = User::query()->where('users.is_active', 1);
        $relations = [
            C::CATEGORY_ROLE => ['roles', 'roles.id'],
            C::CATEGORY_VOICE_GROUP => ['voiceGroups', 'voice_groups.id'],
            C::CATEGORY_SUB_VOICE => ['subVoices', 'sub_voices.id'],
            C::CATEGORY_PROJECT => ['projects', 'projects.id'],
        ];

        foreach ($this->conditionsOf([$filterId])[$filterId] ?? [] as $category => $ids) {
            if ($category === C::CATEGORY_USER) {
                $query->whereIn('users.id', $ids);
                continue;
            }
            [$relation, $column] = $relations[$category];
            $query->whereHas($relation, static fn ($q) => $q->whereIn($column, $ids));
        }

        return $query;
    }

    /**
     * @param iterable<mixed> $values
     * @return list<int>
     */
    private static function ints(iterable $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }
}
```

Hinweis: `conditionsOf` mit unbekannter Filter-ID liefert für diese keinen Schlüssel – `matchingFilterIds` trifft sie daher nie.

- [ ] **Step 6: Grün sehen**

Run: `ddev php vendor/bin/phpunit --filter AudienceFilterService`
Expected: `OK (6 tests …)`.

- [ ] **Step 7: Commit**

```bash
git add db/migrations/20261002090000_create_audience_filter_tables.php src/Models/AudienceFilter.php src/Models/AudienceFilterCondition.php src/Services/Audience tests/Feature/AudienceFilterServiceFeatureTest.php
git commit -F <Nachricht nach /git-commit>
```

---

### Task 2: Normalisierung der Formularwerte

**Files:**
- Create: `src/Services/Audience/AudienceFilterNormalizer.php`, `src/Services/Audience/InvalidAudienceFilterException.php`
- Test: `tests/Feature/AudienceFilterNormalizerFeatureTest.php`

**Interfaces:**
- Consumes: `AudienceFilterCondition::CATEGORIES`
- Produces:
  - `AudienceFilterNormalizer::normalize(array $raw): array` – Eingabe `['all' => mixed, 'conditions' => array<string, mixed>]`, Ausgabe `array<string, list<int>>` (Kategorien in `CATEGORIES`-Reihenfolge, IDs aufsteigend, nur existierende); wirft `InvalidAudienceFilterException`, wenn nichts übrig bleibt und `all` nicht wahr ist; mit `all` wahr ist die Ausgabe `[]`
  - `AudienceFilterNormalizer::signature(array $conditions): string` – gleiche Bedingungsmengen ⇒ gleiche Signatur
  - `InvalidAudienceFilterException extends \InvalidArgumentException`

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilterCondition as C;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\InvalidAudienceFilterException;
use PHPUnit\Framework\TestCase;

class AudienceFilterNormalizerFeatureTest extends TestCase
{
    use FileFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    public function testKeepsExistingIdsSortedAndUnique(): void
    {
        $member = $this->createMember();
        $a = $this->createRoleFor($member, 'A');
        $b = $this->createRoleFor($member, 'B');

        $result = (new AudienceFilterNormalizer())->normalize([
            'conditions' => [
                'unsinn' => [1],
                C::CATEGORY_ROLE => [(string) $b->id, (string) $a->id, (string) $b->id, '999999', 'x'],
            ],
        ]);

        $expected = [(int) $a->id, (int) $b->id];
        sort($expected);
        $this->assertSame([C::CATEGORY_ROLE => $expected], $result);
    }

    public function testEmptyWithoutAllIsRejectedEvenIfOnlyUnknownIdsWereSent(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new AudienceFilterNormalizer())->normalize(['conditions' => [C::CATEGORY_ROLE => ['999999']]]);
    }

    public function testAllMembersYieldsEmptyConditionsAndIgnoresFields(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);

        $this->assertSame([], (new AudienceFilterNormalizer())->normalize([
            'all' => '1',
            'conditions' => [C::CATEGORY_ROLE => [(string) $role->id]],
        ]));
    }

    public function testSignatureIgnoresOrder(): void
    {
        $n = new AudienceFilterNormalizer();
        $this->assertSame(
            $n->signature([C::CATEGORY_ROLE => [1, 2], C::CATEGORY_PROJECT => [5]]),
            $n->signature([C::CATEGORY_PROJECT => [5], C::CATEGORY_ROLE => [2, 1]])
        );
        $this->assertNotSame($n->signature([]), $n->signature([C::CATEGORY_ROLE => [1]]));
    }
}
```

- [ ] **Step 2: Rot sehen**

Run: `ddev php vendor/bin/phpunit --filter AudienceFilterNormalizer` → FAIL, Klasse fehlt.

- [ ] **Step 3: Implementierung**

```php
<?php

declare(strict_types=1);

namespace App\Services\Audience;

final class InvalidAudienceFilterException extends \InvalidArgumentException
{
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\AudienceFilterCondition as C;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Prüft Formularwerte einer Freigabe-Zeile. Nur existierende Kennungen bleiben;
 * eine Zeile ohne Bedingung ist nur mit ausdrücklichem "Alle Mitglieder"
 * gültig – sonst würde eine halb ausgefüllte Zeile den ganzen Chor freigeben.
 */
final class AudienceFilterNormalizer
{
    private const TABLES = [
        C::CATEGORY_ROLE => 'roles',
        C::CATEGORY_VOICE_GROUP => 'voice_groups',
        C::CATEGORY_SUB_VOICE => 'sub_voices',
        C::CATEGORY_PROJECT => 'projects',
        C::CATEGORY_USER => 'users',
    ];

    /**
     * @param array<string, mixed> $raw
     * @return array<string, list<int>>
     */
    public function normalize(array $raw): array
    {
        if (!empty($raw['all'])) {
            return [];
        }

        $conditions = [];
        $input = is_array($raw['conditions'] ?? null) ? $raw['conditions'] : [];
        foreach (C::CATEGORIES as $category) {
            $values = $input[$category] ?? [];
            if (!is_array($values)) {
                continue;
            }
            $ids = [];
            foreach ($values as $value) {
                if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                    $ids[] = (int) $value;
                }
            }
            $ids = array_values(array_unique($ids));
            if ($ids === []) {
                continue;
            }
            $existing = DB::table(self::TABLES[$category])->whereIn('id', $ids)->pluck('id')
                ->map(fn ($id): int => (int) $id)->all();
            sort($existing);
            if ($existing !== []) {
                $conditions[$category] = $existing;
            }
        }

        if ($conditions === []) {
            throw new InvalidAudienceFilterException(
                'Bitte mindestens eine Bedingung wählen oder "Alle Mitglieder" ankreuzen.'
            );
        }

        return $conditions;
    }

    /**
     * @param array<string, list<int>> $conditions
     */
    public function signature(array $conditions): string
    {
        $parts = [];
        foreach (C::CATEGORIES as $category) {
            if (!empty($conditions[$category])) {
                $ids = $conditions[$category];
                sort($ids);
                $parts[] = $category . '=' . implode(',', $ids);
            }
        }

        return implode(';', $parts);
    }
}
```

- [ ] **Step 4: Grün sehen**

Run: `ddev php vendor/bin/phpunit --filter AudienceFilterNormalizer` → `OK (4 tests …)`.

- [ ] **Step 5: Commit** (wie Task 1, Dateien dieser Task).

---

### Task 3: Bestand umstellen – Migration

**Files:**
- Create: `db/migrations/20261002090100_move_file_shares_to_audience_filters.php`
- Test: `tests/Feature/AudienceFilterMigrationFeatureTest.php`

**Interfaces:**
- Produces: `file_folder_shares.audience_filter_id` und `file_shares.audience_filter_id` (int unsigned, NOT NULL, FK auf `audience_filters`); `target_type`, `reference_id`, `uniq_file_folder_shares_target`, `uniq_file_shares_target` entfallen.

Diese Task macht den Bestand fertig; der Code, der noch `target_type` liest, wird erst in Task 4 umgestellt. Zwischen Task 3 und Task 4 ist die Suite deshalb rot – beide in einem Zug ausführen, Commit erst nach Task 4.

- [ ] **Step 1: Migration schreiben**

Die Umstellungslogik steht in zwei statischen Methoden, damit der Test sie gegen die Testdatenbank prüfen kann, ohne Phinx zu starten.

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ordner- und Dateifreigaben bekommen statt target_type/reference_id einen
 * Zielgruppen-Filter. Jede alte Freigabe wird ein Filter mit genau einer
 * Bedingung (all_members: keine Bedingung) – an den Rechten ändert sich nichts.
 *
 * Reihenfolge nach instructions/database.md: Spalte anlegen, befüllen, prüfen,
 * erst dann alte Spalten entfernen.
 */
final class MoveFileSharesToAudienceFilters extends AbstractMigration
{
    private const TABLES = ['file_folder_shares', 'file_shares'];

    private const CATEGORY_FOR_TYPE = [
        'role' => 'role',
        'voice_group' => 'voice_group',
        'user' => 'user',
        'project_members' => 'project',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->table($table)
                ->addColumn('audience_filter_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'id'])
                ->update();
        }

        $now = date('Y-m-d H:i:s');
        foreach (self::TABLES as $table) {
            foreach ($this->fetchAll("SELECT id, target_type, reference_id FROM {$table} WHERE audience_filter_id IS NULL") as $row) {
                $this->execute("INSERT INTO audience_filters (created_at) VALUES ('{$now}')");
                $filterId = (int) $this->getAdapter()->getConnection()->lastInsertId();
                $category = self::CATEGORY_FOR_TYPE[$row['target_type']] ?? null;
                if ($category !== null) {
                    $this->execute(sprintf(
                        "INSERT INTO audience_filter_conditions (audience_filter_id, category, reference_id) VALUES (%d, '%s', %d)",
                        $filterId,
                        $category,
                        (int) $row['reference_id']
                    ));
                }
                $this->execute(sprintf('UPDATE %s SET audience_filter_id = %d WHERE id = %d', $table, $filterId, (int) $row['id']));
            }
        }

        // Prüfung vor jedem destruktiven Schritt.
        foreach (self::TABLES as $table) {
            $missing = (int) ($this->fetchRow("SELECT COUNT(*) AS n FROM {$table} WHERE audience_filter_id IS NULL")['n'] ?? 0);
            if ($missing > 0) {
                throw new RuntimeException(sprintf('%d Freigabe(n) in %s ohne Filter - Abbruch vor dem Entfernen der alten Spalten.', $missing, $table));
            }
        }

        foreach (['file_folder_shares' => 'uniq_file_folder_shares_target', 'file_shares' => 'uniq_file_shares_target'] as $table => $index) {
            $this->table($table)
                ->changeColumn('audience_filter_id', 'integer', ['null' => false, 'signed' => false])
                ->addForeignKey('audience_filter_id', 'audience_filters', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                    'constraint' => 'fk_' . $table . '_audience_filter',
                ])
                ->removeIndexByName($index)
                ->removeColumn('target_type')
                ->removeColumn('reference_id')
                ->update();
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $complex = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} s WHERE
                    (SELECT COUNT(*) FROM audience_filter_conditions c WHERE c.audience_filter_id = s.audience_filter_id) > 1
                 OR EXISTS (SELECT 1 FROM audience_filter_conditions c WHERE c.audience_filter_id = s.audience_filter_id AND c.category = 'sub_voice')"
            )['n'] ?? 0);
            if ($complex > 0) {
                throw new RuntimeException(sprintf(
                    '%d Freigabe(n) in %s nutzen mehrere Bedingungen oder eine Untergruppe - im alten Modell nicht darstellbar. Rückbau abgebrochen.',
                    $complex,
                    $table
                ));
            }
        }

        $idColumn = ['file_folder_shares' => 'folder_id', 'file_shares' => 'file_id'];
        foreach (self::TABLES as $table) {
            $this->table($table)
                ->addColumn('target_type', 'enum', [
                    'values' => ['role', 'user', 'voice_group', 'project_members', 'all_members'],
                    'null' => true,
                    'after' => $idColumn[$table],
                ])
                ->addColumn('reference_id', 'integer', ['null' => false, 'default' => 0, 'after' => 'target_type'])
                ->update();
            $this->execute(
                "UPDATE {$table} s
                 LEFT JOIN audience_filter_conditions c ON c.audience_filter_id = s.audience_filter_id
                 SET s.target_type = CASE c.category
                        WHEN 'project' THEN 'project_members'
                        WHEN 'role' THEN 'role'
                        WHEN 'voice_group' THEN 'voice_group'
                        WHEN 'user' THEN 'user'
                        ELSE 'all_members' END,
                     s.reference_id = COALESCE(c.reference_id, 0)"
            );
        }

        foreach (['file_folder_shares' => 'uniq_file_folder_shares_target', 'file_shares' => 'uniq_file_shares_target'] as $table => $index) {
            $this->table($table)
                ->changeColumn('target_type', 'enum', [
                    'values' => ['role', 'user', 'voice_group', 'project_members', 'all_members'],
                    'null' => false,
                ])
                ->dropForeignKey('audience_filter_id')
                ->update();
            $this->table($table)
                ->removeColumn('audience_filter_id')
                ->addIndex([$idColumn[$table], 'target_type', 'reference_id'], ['unique' => true, 'name' => $index])
                ->update();
        }

        $this->execute('DELETE FROM audience_filters');
    }
}
```

Lange Zeilen beim Übernehmen auf ≤ 130 Zeichen umbrechen.

- [ ] **Step 2: Migrationstest schreiben**

Der Test läuft gegen die bereits migrierte Testdatenbank (`tests/bootstrap.php` migriert vor jedem Lauf). Er prüft deshalb das Ergebnis des Schemas und die beiden Prüfungen über eigene SQL-Abfragen in einer Transaktion:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

class AudienceFilterMigrationFeatureTest extends TestCase
{
    private function migration(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20261002090100_move_file_shares_to_audience_filters.php');
        $this->assertIsString($content);

        return $content;
    }

    public function testOldColumnsAreGoneAndFilterIsRequired(): void
    {
        \Tests\Unit\Bootstrap::setupTestDatabase();
        foreach (['file_folder_shares', 'file_shares'] as $table) {
            $columns = array_column(DB::select("SHOW COLUMNS FROM {$table}"), 'Null', 'Field');
            $this->assertArrayNotHasKey('target_type', $columns, $table);
            $this->assertArrayNotHasKey('reference_id', $columns, $table);
            $this->assertSame('NO', $columns['audience_filter_id'], $table);
        }
    }

    public function testMappingCoversAllOldTargetTypes(): void
    {
        $content = $this->migration();
        foreach (["'role' => 'role'", "'voice_group' => 'voice_group'", "'user' => 'user'", "'project_members' => 'project'"] as $pair) {
            $this->assertStringContainsString($pair, $content);
        }
    }

    public function testAllMembersBecomesFilterWithoutConditionWhateverItsReference(): void
    {
        // Ohne Eintrag in CATEGORY_FOR_TYPE entsteht keine Bedingung - unabhängig von reference_id.
        $this->assertStringNotContainsString("'all_members' =>", $this->migration());
        $this->assertStringContainsString('if ($category !== null)', $this->migration());
    }

    public function testGuardsStandBeforeDestructiveSteps(): void
    {
        $content = $this->migration();
        $up = substr($content, strpos($content, 'function up()'), strpos($content, 'function down()') - strpos($content, 'function up()'));
        $this->assertLessThan(strpos($up, "removeColumn('target_type')"), strpos($up, 'throw new RuntimeException'));

        $down = substr($content, strpos($content, 'function down()'));
        $this->assertLessThan(strpos($down, "addColumn('target_type'"), strpos($down, 'throw new RuntimeException'));
    }
}
```

Zusätzlich von Hand (Task 6, Verifikation): auf der Entwicklungsdatenbank mit Seed-Bestand `phinx migrate`, Freigaben zählen, `phinx rollback -t 20261002090000`, wieder `migrate` – Zahl der Freigaben unverändert. Und einmal eine Freigabe mit zwei Bedingungen anlegen und `rollback` laufen lassen: Abbruch mit der Meldung aus `down()`.

- [ ] **Step 3: Migration ausführen**

Run: `ddev exec ./vendor/bin/phinx migrate`
Expected: `MoveFileSharesToAudienceFilters: migrated`.

Kein Commit – weiter mit Task 4.

---

### Task 4: Freigaben über Filter speichern und auswerten

**Files:**
- Modify: `src/Models/FileFolderShare.php` (fillable: `audience_filter_id` statt `target_type`/`reference_id`; `TYPE_*`-Konstanten und `TYPES` entfernen; `filter()`-Relation)
- Modify: `src/Models/FileShare.php` (dasselbe)
- Modify: `src/Services/Files/FileAccessService.php` (`matchingLevels`)
- Modify: `src/Services/Files/FileFolderService.php` (`setShares`, `normalizeShares`)
- Modify: `src/Services/Files/FileShareService.php` (`setShares`)
- Modify: `src/Services/Files/FileShareDescriber.php` (`label`, `summarize`, `options`)
- Modify: `src/Services/DevSeedService.php` (alle `FileShare::create` und `setShares`-Aufrufe)
- Modify: `tests/Feature/FileFixtures.php` (`share`, neu `shareWith`)
- Modify: `tests/Feature/FileFolderServiceFeatureTest.php`, `FileShareServiceFeatureTest.php`, `FileShareAccessFeatureTest.php`, `FileSharingControllerFeatureTest.php`, `FileControllerFeatureTest.php` (Freigaben im neuen Format)
- Test: dieselben

**Interfaces:**
- Consumes: `AudienceFilterService::{profileOf, matchingFilterIds, create, delete, conditionsOf}`, `AudienceFilterNormalizer::{normalize, signature}`, `InvalidAudienceFilterException`
- Produces:
  - `FileFolderService::normalizeShares(array $rawRows, array $allowedLevels): array` → `list<array{conditions: array<string, list<int>>, level: int}>`; Eingabezeile `['level' => int, 'all' => bool, 'conditions' => array<string, list<int|string>>]`; gleiche Signatur ⇒ eine Zeile mit höchster Stufe; ungültige Zeile ⇒ `FileManagementException` 422 mit der Meldung der `InvalidAudienceFilterException`
  - `FileFolderService::setShares(FileActor $actor, FileFolder $folder, array $rawRows): void` (Signatur unverändert)
  - `FileShareService::setShares(FileActor $actor, StoredFile $file, array $rawRows): void` (Signatur unverändert)
  - `FileShareDescriber::label(iterable $shares): list<array{filter_id: int, level: int, label: string, conditions: array<string, list<int>>, all: bool}>`
  - `FileShareDescriber::summarize(array $conditions): string` („Alle Mitglieder“ bei `[]`)
  - Test-Helfer `FileFixtures::share(FileFolder $folder, string $type, int $referenceId, int $level): FileFolderShare` (alte Typnamen inkl. `all_members`, `project_members`, intern auf Filter abgebildet) und `FileFixtures::shareWith(FileFolder $folder, array $conditions, int $level): FileFolderShare`; für Dateien `FileFixtures::shareFileWith(StoredFile $file, array $conditions, int $level): FileShare`

- [ ] **Step 1: Neue Fälle als Failing Tests ergänzen**

In `tests/Feature/FileShareAccessFeatureTest.php`:

```php
    public function testSopranoAndProjectCombinedOnFolder(): void
    {
        $sopran = $this->createMember('Sopran im Projekt');
        $soprano = $this->createVoiceGroupFor($sopran, 'Sopran');
        $project = $this->createProjectFor($sopran, 'Frühjahrskonzert');
        $outside = $this->createMember('Sopran außerhalb');
        $outside->voiceGroups()->attach($soprano->id);
        $alto = $this->createMember('Alt im Projekt');
        $this->createVoiceGroupFor($alto, 'Alt');
        $alto->projects()->attach($project->id);
        $folder = $this->createFolder('Stimmproben');
        $this->shareWith($folder, ['voice_group' => [(int) $soprano->id], 'project' => [(int) $project->id]], Share::LEVEL_READ);

        $this->assertSame(Share::LEVEL_READ, $this->access->levelFor($this->actor($sopran), $folder));
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($outside), $folder));
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($alto), $folder));
    }
```

In `tests/Feature/FileFolderServiceFeatureTest.php` den bisherigen Test `testSetSharesNeedsManageAndReplacesAllShares` ersetzen durch:

```php
    public function testSetSharesStoresFiltersMergesDuplicatesAndRejectsEmptyRows(): void
    {
        $manager = $this->createMember('Verwalter');
        $role = $this->createRoleFor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);
        $actor = $this->actor($manager);

        $this->folders->setShares($actor, $root, [
            ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [(int) $manager->id]]],
            ['level' => Share::LEVEL_READ, 'conditions' => ['role' => [(int) $role->id]]],
            ['level' => Share::LEVEL_EDIT, 'conditions' => ['role' => [(string) $role->id]]],
            ['level' => Share::LEVEL_READ, 'all' => '1'],
        ]);

        $shares = Share::query()->where('folder_id', $root->id)->get();
        $this->assertCount(3, $shares, 'Doppelte Rollen-Zeile zusammengelegt.');
        $levels = $shares->pluck('level')->sort()->values()->all();
        $this->assertSame([Share::LEVEL_READ, Share::LEVEL_EDIT, Share::LEVEL_MANAGE], $levels);

        try {
            $this->folders->setShares($actor, $root, [
                ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [(int) $manager->id]]],
                ['level' => Share::LEVEL_READ, 'conditions' => []],
            ]);
            $this->fail('Leere Zeile ohne "Alle Mitglieder" angenommen.');
        } catch (FileManagementException $exception) {
            $this->assertSame(422, $exception->status);
        }
        $this->assertCount(3, Share::query()->where('folder_id', $root->id)->get(), 'Nichts geändert.');
    }

    public function testReplacingSharesDeletesTheirFilters(): void
    {
        $manager = $this->createMember();
        $root = $this->createFolder('Wurzel');
        $old = $this->share($root, 'all_members', 0, Share::LEVEL_READ);
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);

        $this->folders->setShares($this->actor($manager), $root, [
            ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [(int) $manager->id]]],
        ]);

        $this->assertNull(\App\Models\AudienceFilter::find($old->audience_filter_id));
    }

    public function testManagerCannotLockThemselvesOutWithAFilter(): void
    {
        $manager = $this->createMember();
        $project = \App\Models\Project::create(['name' => 'Fremdes Projekt ' . bin2hex(random_bytes(3))]);
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);

        try {
            $this->folders->setShares($this->actor($manager), $root, [
                ['level' => Share::LEVEL_MANAGE, 'conditions' => ['project' => [(int) $project->id]]],
            ]);
            $this->fail('Selbstaussperren über Filter erlaubt.');
        } catch (FileManagementException $exception) {
            $this->assertSame(422, $exception->status);
        }
    }
```

Den alten `testManagerCannotLockThemselvesOutOfOwnFolder` belassen (er ruft `setShares($actor, $root, [])`; nach der Umstellung wirft das weiterhin 422).

- [ ] **Step 2: Rot sehen**

Run: `ddev php vendor/bin/phpunit --filter "File"`
Expected: viele Fehler – `Unknown column 'target_type'`. Das ist der Ausgangspunkt der Umstellung.

- [ ] **Step 3: Test-Helfer umstellen** (`tests/Feature/FileFixtures.php`)

```php
    /** Alte Zieltypen der Tests auf Filter-Bedingungen abgebildet. */
    private const LEGACY_TYPES = [
        'role' => 'role',
        'voice_group' => 'voice_group',
        'user' => 'user',
        'project_members' => 'project',
    ];

    protected function share(FileFolder $folder, string $type, int $referenceId, int $level): FileFolderShare
    {
        $conditions = $type === 'all_members' ? [] : [self::LEGACY_TYPES[$type] => [$referenceId]];

        return $this->shareWith($folder, $conditions, $level);
    }

    /** @param array<string, list<int>> $conditions */
    protected function shareWith(FileFolder $folder, array $conditions, int $level): FileFolderShare
    {
        $filter = (new \App\Services\Audience\AudienceFilterService())->create($conditions);

        return FileFolderShare::create([
            'folder_id' => $folder->id,
            'audience_filter_id' => (int) $filter->id,
            'level' => $level,
        ]);
    }

    /** @param array<string, list<int>> $conditions */
    protected function shareFileWith(\App\Models\StoredFile $file, array $conditions, int $level): \App\Models\FileShare
    {
        $filter = (new \App\Services\Audience\AudienceFilterService())->create($conditions);

        return \App\Models\FileShare::create([
            'file_id' => $file->id,
            'audience_filter_id' => (int) $filter->id,
            'level' => $level,
        ]);
    }
```

Aufrufe mit Konstanten (`Share::TYPE_ROLE` usw.) in den Tests durch die Zeichenketten `'role'`, `'voice_group'`, `'user'`, `'project_members'`, `'all_members'` ersetzen. Direkte `FileShare::create([... 'target_type' ...])` in `FileShareAccessFeatureTest::shareFile` und `FileSharingControllerFeatureTest` durch `shareFileWith($file, [<kategorie> => [<id>]], $level)` ersetzen (`project_members` ⇒ `project`, `all_members` ⇒ `[]`).

Formular-Eingaben in `FileControllerFeatureTest::testSharesFormStoresCombinedTargets` und `FileSharingControllerFeatureTest::testManagerCreatesLinkShownOnceAndSharesFile` auf das neue Format umstellen:

```php
['shares' => [
    ['level' => '4', 'conditions' => ['user' => [(string) $manager->id]]],
    ['level' => '1', 'conditions' => ['voice_group' => [(string) $group->id]]],
]]
```

Die dritte, kaputte Zeile (`'target' => 'kaputt'`) wird zu `['level' => '1', 'conditions' => ['unsinn' => ['1']]]` – sie muss jetzt **abgelehnt** werden (422 als Flash), statt still zu verschwinden; Erwartung im Test entsprechend: Redirect, `$_SESSION['error']` gesetzt, Freigaben unverändert. Für den Erfolgsfall eine zweite Anfrage ohne die kaputte Zeile.

`FileShareServiceFeatureTest::testManagerSetsFileSharesWithReadAndEditOnly` im neuen Format:

```php
$this->shares->setShares($manager, $file, [
    ['level' => Share::LEVEL_EDIT, 'conditions' => ['role' => [(int) $role->id]]],
    ['level' => Share::LEVEL_READ, 'all' => '1'],
    ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [(int) $manager->id]]],
    ['level' => Share::LEVEL_UPLOAD, 'conditions' => ['user' => [(int) $manager->id]]],
]);
$this->assertSame([1, 3], FileShare::query()->where('file_id', $file->id)->orderBy('level')->pluck('level')->all());
```

- [ ] **Step 4: Modelle anpassen**

`src/Models/FileFolderShare.php`: `TYPE_*`, `TYPES` entfernen; `$fillable = ['folder_id', 'audience_filter_id', 'level', 'created_by']`; Cast `'audience_filter_id' => 'integer'`; Relation:

```php
    public function filter(): BelongsTo
    {
        return $this->belongsTo(AudienceFilter::class, 'audience_filter_id');
    }
```

`src/Models/FileShare.php` entsprechend mit `file_id`.

- [ ] **Step 5: `FileAccessService::matchingLevels` umstellen**

```php
    /**
     * Gemeinsamer Abgleich für Ordner- und Dateifreigaben über ihre
     * Zielgruppen-Filter. Ergebnis: höchste passende Stufe je Ordner bzw. Datei.
     *
     * @param Builder<FileFolderShare>|Builder<FileShare> $query
     * @return array<int, int>
     */
    private function matchingLevels(FileActor $actor, Builder $query, string $keyColumn): array
    {
        $profile = $this->filters->profileOf($actor->userId);
        if ($profile === null) {
            return [];
        }

        $rows = $query->toBase()->get([$keyColumn . ' AS target_id', 'level', 'audience_filter_id']);
        $matching = array_flip($this->filters->matchingFilterIds(
            $profile,
            $rows->pluck('audience_filter_id')->map(fn ($id): int => (int) $id)->all()
        ));

        $levels = [];
        foreach ($rows as $row) {
            if (!isset($matching[(int) $row->audience_filter_id])) {
                continue;
            }
            $target = (int) $row->target_id;
            $levels[$target] = max($levels[$target] ?? 0, (int) $row->level);
        }

        return $levels;
    }
```

Konstruktor: `public function __construct(private readonly AudienceFilterService $filters = new AudienceFilterService())` – der Default hält alle bestehenden `new FileAccessService()` in Tests und Seed lauffähig. `ids()` und `User`-Import entfernen, falls unbenutzt.

- [ ] **Step 6: `FileFolderService` umstellen**

```php
    public function setShares(FileActor $actor, FileFolder $folder, array $rawShares): void
    {
        $this->requireLevel($actor, $folder, FileFolderShare::LEVEL_MANAGE);
        $shares = $this->normalizeShares($rawShares, array_keys(FileFolderShare::LEVEL_LABELS));

        DB::connection()->transaction(function () use ($actor, $folder, $shares): void {
            $old = FileFolderShare::query()->where('folder_id', $folder->id)->pluck('audience_filter_id')
                ->map(fn ($id): int => (int) $id)->all();
            FileFolderShare::query()->where('folder_id', $folder->id)->delete();
            $this->filters->delete($old);

            foreach ($shares as $share) {
                $filter = $this->filters->create($share['conditions']);
                FileFolderShare::create([
                    'folder_id' => (int) $folder->id,
                    'audience_filter_id' => (int) $filter->id,
                    'level' => $share['level'],
                    'created_by' => $actor->userId,
                ]);
            }

            // Wer hier verwaltet, soll sich nicht versehentlich selbst aussperren.
            if (!$this->access->can($actor, $folder, FileFolderShare::LEVEL_MANAGE)) {
                throw new FileManagementException('Diese Freigaben würden dir selbst die Verwaltung entziehen.', 422);
            }
        });

        $this->logger->info('Folder shares changed.', [
            'event' => 'files.share_changed',
            'folder_id' => (int) $folder->id,
            'share_count' => count($shares),
            'user_id' => $actor->userId,
        ]);
    }

    /**
     * @param array<int, mixed> $rawShares Zeilen mit level, all, conditions
     * @param list<int> $allowedLevels
     * @return list<array{conditions: array<string, list<int>>, level: int}>
     */
    public function normalizeShares(array $rawShares, array $allowedLevels): array
    {
        $bySignature = [];
        foreach ($rawShares as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $level = (int) ($raw['level'] ?? 0);
            if (!in_array($level, $allowedLevels, true)) {
                continue;
            }
            try {
                $conditions = $this->normalizer->normalize($raw);
            } catch (InvalidAudienceFilterException $exception) {
                throw new FileManagementException($exception->getMessage(), 422);
            }
            $signature = $this->normalizer->signature($conditions);
            if (!isset($bySignature[$signature]) || $bySignature[$signature]['level'] < $level) {
                $bySignature[$signature] = ['conditions' => $conditions, 'level' => $level];
            }
        }

        return array_values($bySignature);
    }
```

Konstruktor um `private readonly AudienceFilterService $filters = new AudienceFilterService()` und `private readonly AudienceFilterNormalizer $normalizer = new AudienceFilterNormalizer()` am Ende ergänzen (Defaults halten bestehende `new FileFolderService($access, $quota, $logger)` gültig).

Der Selbstaussperr-Test mit leerer Liste (`setShares($actor, $root, [])`) bleibt gültig: keine Zeile ⇒ keine Freigabe ⇒ 422 aus der Prüfung am Ende.

- [ ] **Step 7: `FileShareService::setShares` umstellen**

```php
    public function setShares(FileActor $actor, StoredFile $file, array $rawShares): void
    {
        $this->requireManage($actor, $file);
        $shares = $this->folders->normalizeShares($rawShares, array_keys(FileShare::LEVELS));

        DB::connection()->transaction(function () use ($actor, $file, $shares): void {
            $old = FileShare::query()->where('file_id', $file->id)->pluck('audience_filter_id')
                ->map(fn ($id): int => (int) $id)->all();
            FileShare::query()->where('file_id', $file->id)->delete();
            $this->filters->delete($old);
            foreach ($shares as $share) {
                $filter = $this->filters->create($share['conditions']);
                FileShare::create([
                    'file_id' => (int) $file->id,
                    'audience_filter_id' => (int) $filter->id,
                    'level' => $share['level'],
                    'created_by' => $actor->userId,
                ]);
            }
        });

        $this->logger->info('File shares changed.', [
            'event' => 'files.file_share_changed',
            'file_id' => (int) $file->id,
            'share_count' => count($shares),
            'user_id' => $actor->userId,
        ]);
    }
```

Konstruktor: `private readonly AudienceFilterService $filters = new AudienceFilterService()` ergänzen.

Hinweis: Wird eine Datei oder ein Ordner endgültig gelöscht, löschen die Fremdschlüssel nur die Freigaben, nicht deren Filter. In `FileTrashService::deleteFolder` und `deleteFiles` **vor** dem `forceDelete` die `audience_filter_id` der betroffenen Freigaben (`FileFolderShare` für alle Ordner des Teilbaums, `FileShare` für alle betroffenen Dateien) einsammeln und nach dem Löschen `AudienceFilterService::delete` aufrufen. Testfall dazu in `FileTrashServiceFeatureTest`:

```php
    public function testPurgeRemovesFiltersOfShares(): void
    {
        $manager = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', $manager->userId, Share::LEVEL_MANAGE);
        $child = $this->createFolder('Kind', $root);
        $childShare = $this->share($child, 'all_members', 0, Share::LEVEL_READ);
        $this->folders->trash($manager, $child);

        $this->trash->purgeFolder($manager, (int) $child->id);

        $this->assertNull(\App\Models\AudienceFilter::find($childShare->audience_filter_id));
    }
```

- [ ] **Step 8: `FileShareDescriber` umstellen**

```php
    /**
     * @param iterable<FileFolderShare|FileShare> $shares
     * @return list<array{filter_id: int, level: int, label: string, conditions: array<string, list<int>>, all: bool}>
     */
    public function label(iterable $shares): array
    {
        $shares = is_array($shares) ? $shares : iterator_to_array($shares, false);
        $conditions = $this->filters->conditionsOf(array_map(static fn ($s): int => (int) $s->audience_filter_id, $shares));

        $described = [];
        foreach ($shares as $share) {
            $set = $conditions[(int) $share->audience_filter_id] ?? [];
            $described[] = [
                'filter_id' => (int) $share->audience_filter_id,
                'level' => (int) $share->level,
                'label' => $this->summarize($set),
                'conditions' => $set,
                'all' => $set === [],
            ];
        }
        usort($described, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $described;
    }

    /**
     * "Stimmgruppe: Sopran, Alt · Projekt: Frühjahrskonzert"
     *
     * @param array<string, list<int>> $conditions
     */
    public function summarize(array $conditions): string
    {
        if ($conditions === []) {
            return 'Alle Mitglieder';
        }

        $labels = [
            C::CATEGORY_ROLE => 'Rolle',
            C::CATEGORY_VOICE_GROUP => 'Stimmgruppe',
            C::CATEGORY_SUB_VOICE => 'Untergruppe',
            C::CATEGORY_PROJECT => 'Projekt',
            C::CATEGORY_USER => 'Mitglied',
        ];
        $parts = [];
        foreach ($conditions as $category => $ids) {
            $names = $this->namesFor($category, $ids);
            $parts[] = $labels[$category] . ': ' . implode(', ', $names);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private function namesFor(string $category, array $ids): array
    {
        $found = match ($category) {
            C::CATEGORY_ROLE => Role::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            C::CATEGORY_VOICE_GROUP => VoiceGroup::query()->whereIn('id', $ids)->orderBy('id')->pluck('name', 'id')->all(),
            C::CATEGORY_SUB_VOICE => SubVoice::query()->whereIn('id', $ids)->orderBy('voice_group_id')->orderBy('name')
                ->pluck('name', 'id')->all(),
            C::CATEGORY_PROJECT => Project::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            C::CATEGORY_USER => User::query()->whereIn('id', $ids)->get()
                ->mapWithKeys(fn (User $u): array => [(int) $u->id => $this->nameFormatter->formatPerson($u)])->all(),
            default => [],
        };

        $names = array_values(array_map('strval', $found));
        $missing = count(array_diff($ids, array_map('intval', array_keys($found))));
        if ($missing > 0) {
            $names[] = $missing === 1 ? 'gelöscht' : $missing . '× gelöscht';
        }

        return $names;
    }

    /**
     * @param list<int> $selectedProjectIds bereits gewählte Projekte bleiben auch beendet in der Liste
     * @return array<string, mixed>
     */
    public function options(array $selectedProjectIds = []): array
    {
        $usersQuery = User::query()->where('is_active', 1);
        $this->nameFormatter->applyNameOrder($usersQuery);
        $today = date('Y-m-d');

        $projects = Project::query()->chronological()->get(['id', 'name', 'end_date'])
            ->filter(static fn (Project $p): bool => $p->end_date === null
                || $p->end_date->format('Y-m-d') >= $today
                || in_array((int) $p->id, $selectedProjectIds, true))
            ->map(static fn (Project $p): array => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'ended' => $p->end_date !== null && $p->end_date->format('Y-m-d') < $today,
            ])
            ->values()->all();

        $groups = VoiceGroup::query()->orderBy('id')->get(['id', 'name']);
        $subVoices = SubVoice::query()->orderBy('name')->get(['id', 'name', 'voice_group_id'])->groupBy('voice_group_id');

        return [
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'voice_groups' => $groups,
            'sub_voices' => $groups->map(static fn (VoiceGroup $g): array => [
                'group' => $g->name,
                'items' => ($subVoices->get($g->id) ?? collect())->values()->all(),
            ])->filter(static fn (array $entry): bool => $entry['items'] !== [])->values()->all(),
            'projects' => $projects,
            'users' => $usersQuery->get(),
        ];
    }
```

Konstruktor: `NameFormatterService $nameFormatter, AudienceFilterService $filters = new AudienceFilterService()`; Imports `App\Models\AudienceFilterCondition as C`, `App\Models\SubVoice`. Aufrufer von `options()`: Ordnerseite übergibt die Projekt-IDs aller Freigaben des Ordners, Dateiseite die der Datei (aus `label(...)[*]['conditions']['project']`).

- [ ] **Step 9: Seed umstellen**

In `src/Services/DevSeedService.php`:
- `$share`-Closure liefert Zeilen im neuen Format:

```php
        $share = static fn (string $type, int $referenceId, int $level): array => $type === 'all_members'
            ? ['level' => $level, 'all' => '1']
            : ['level' => $level, 'conditions' => [
                ['role' => 'role', 'voice_group' => 'voice_group', 'user' => 'user', 'project_members' => 'project'][$type]
                => [$referenceId],
            ]];
```

- Aufrufe `$share(FileFolderShare::TYPE_ROLE, …)` usw. durch Zeichenketten (`'role'`, `'voice_group'`, `'project_members'`, `'user'`, `'all_members'`) ersetzen.
- Die direkten `FileShare::create([... 'target_type' ...])` für Einladung und Mietvertrag ersetzen (Import `App\Services\Audience\AudienceFilterService` ergänzen):

```php
            foreach ($fileShares as [$sharedFile, $conditions, $level]) {
                $filter = (new AudienceFilterService())->create($conditions);
                FileShare::create([
                    'file_id' => (int) $sharedFile->id,
                    'audience_filter_id' => (int) $filter->id,
                    'level' => $level,
                    'created_by' => (int) $adminUser->id,
                ]);
                $this->report['counts']['file_shares']++;
            }
```

mit `$fileShares[] = [$invitation, [], FileFolderShare::LEVEL_READ];` und `$fileShares[] = [$contract, ['role' => [(int) $treasurerRole->id]], FileFolderShare::LEVEL_EDIT];`.

- `audience_filter_conditions` und `audience_filters` in `resetSeedData()` vor `file_folder_shares` eintragen; Zähler `'audience_filters' => 0, 'audience_filter_conditions' => 0` im Bericht; nach `seedFileManagement` die Zähler über `DB::table(...)->count()` setzen.

- [ ] **Step 10: Grün sehen**

Run: `ddev php vendor/bin/phpunit --filter "File|Audience"`
Expected: alle grün, inkl. der neuen Fälle aus Step 1 und `testPurgeRemovesFiltersOfShares`.

Run: `ddev composer phpcs` → keine Verstöße.

- [ ] **Step 11: Commit (Task 3 und 4 zusammen)**

```bash
git add db/migrations/20261002090100_move_file_shares_to_audience_filters.php tests/Feature/AudienceFilterMigrationFeatureTest.php src/Models src/Services tests/Feature
git commit -F <Nachricht nach /git-commit>
```

---

### Task 5: Formular, Oberfläche und Trefferzahl

**Files:**
- Modify: `src/Controllers/Concerns/FileControllerSupport.php` (`parseShareRows`)
- Create: `src/Controllers/FileAudienceController.php`
- Modify: `src/Routes.php` (Route `POST /files/audience-preview`)
- Modify: `src/Controllers/FileBrowserController.php`, `src/Controllers/FileDetailController.php` (`options($projectIds)`)
- Modify: `templates/files/partials/share_row.twig` (neu geschrieben), `templates/files/partials/folder_modals.twig`, `templates/files/file.twig` (Einbindung, `<template>` für neue Zeilen, Skripte)
- Create: `public/js/file-audience.js`
- Modify: `public/js/file-manager.js` (`setupShares`: neue Zeilen → `initTomSelects(row)` und `fileAudience.bind(row)`)
- Modify: `public/css/style.css` (Zeile, gelbe Markierung)
- Modify: `help/files/docs/files-sharing.md` (Hinweis)
- Test: `tests/Feature/FileAudienceFeatureTest.php`

**Interfaces:**
- Consumes: `FileFolderService::normalizeShares`, `AudienceFilterNormalizer::normalize`, `AudienceFilterService::{create, membersQuery, delete}`, `FileShareDescriber::{label, options, summarize}`
- Produces:
  - Formularfelder je Zeile `shares[{i}][level]`, `shares[{i}][all]`, `shares[{i}][conditions][{category}][]`
  - `FileControllerSupport::parseShareRows(array $rows): array` → `list<array{level: int, all: bool, conditions: array<string, list<string>>}>`
  - `POST /files/audience-preview` mit Body wie eine Zeile (`all`, `conditions`), Antwort `{"ok": true, "count": int}` bzw. `{"ok": false, "error": string}` mit 403/422

- [ ] **Step 1: Failing Test für Vorschau und Formular**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FileAudienceController;
use App\Models\FileFolderShare as Share;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class FileAudienceFeatureTest extends TestCase
{
    use FileFixtures;
    use TestHttpHelpers;

    private FileAudienceController $controller;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $container = $builder->build();
        $container->set(Capsule::class, Bootstrap::getCapsule());
        $this->controller = $container->get(FileAudienceController::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function preview(array $body): array
    {
        $response = $this->controller->preview(
            $this->makeRequest('POST', '/files/audience-preview', $body, [], ['Accept' => 'application/json']),
            $this->makeResponse()
        );

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    public function testManagerGetsOnlyACount(): void
    {
        $manager = $this->createMember('Verwalter');
        $folder = $this->createFolder('Wurzel');
        $this->share($folder, 'user', (int) $manager->id, Share::LEVEL_MANAGE);
        $sopran = $this->createMember('Sopran');
        $group = $this->createVoiceGroupFor($sopran);
        $_SESSION = ['user_id' => (int) $manager->id];

        [$status, $payload] = $this->preview(['conditions' => ['voice_group' => [(string) $group->id]]]);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'count' => 1], $payload);
    }

    public function testReaderIsRejected(): void
    {
        $reader = $this->createMember();
        $folder = $this->createFolder('Wurzel');
        $this->share($folder, 'user', (int) $reader->id, Share::LEVEL_READ);
        $_SESSION = ['user_id' => (int) $reader->id];

        [$status, $payload] = $this->preview(['all' => '1']);

        $this->assertSame(403, $status);
        $this->assertArrayNotHasKey('count', $payload);
    }

    public function testEmptyRowIsRejectedWithMessage(): void
    {
        $_SESSION = ['user_id' => (int) $this->createMember()->id, 'can_manage_files' => true];

        [$status, $payload] = $this->preview(['conditions' => []]);

        $this->assertSame(422, $status);
        $this->assertFalse($payload['ok']);
    }

    public function testPreviewLeavesNoFilterBehind(): void
    {
        $_SESSION = ['user_id' => (int) $this->createMember()->id, 'can_manage_files' => true];
        $before = \App\Models\AudienceFilter::query()->count();

        $this->preview(['all' => '1']);

        $this->assertSame($before, \App\Models\AudienceFilter::query()->count());
    }
}
```

Run: `ddev php vendor/bin/phpunit --filter FileAudience` → FAIL, Klasse fehlt.

- [ ] **Step 2: `parseShareRows` umstellen**

```php
    /**
     * Freigabe-Zeilen aus dem Formular: Stufe, "Alle Mitglieder", Bedingungen je
     * Kategorie. Geprüft wird im Service.
     *
     * @param array<mixed> $rows
     * @return list<array{level: int, all: bool, conditions: array<string, list<string>>}>
     */
    private static function parseShareRows(array $rows): array
    {
        $shares = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $conditions = [];
            foreach (is_array($row['conditions'] ?? null) ? $row['conditions'] : [] as $category => $values) {
                if (is_string($category) && is_array($values)) {
                    $conditions[$category] = array_values(array_map('strval', array_filter($values, 'is_scalar')));
                }
            }
            $shares[] = [
                'level' => (int) ($row['level'] ?? 0),
                'all' => !empty($row['all']),
                'conditions' => $conditions,
            ];
        }

        return $shares;
    }
```

- [ ] **Step 3: `FileAudienceController` und Route**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Models\FileFolderShare;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileManagementException;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Trefferzahl einer Freigabe-Zeile, während sie bearbeitet wird. Nur die Zahl,
 * keine Namen; nur für Personen, die irgendwo Freigaben verwalten dürfen.
 */
final class FileAudienceController
{
    use FileControllerSupport;

    public function __construct(
        private readonly FileAccessService $access,
        private readonly AudienceFilterService $filters,
        private readonly AudienceFilterNormalizer $normalizer
    ) {
    }

    public function preview(Request $request, Response $response): Response
    {
        try {
            $actor = $this->actor();
        } catch (FileManagementException $exception) {
            return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], 403);
        }

        $managesSomething = $actor->isFileAdmin || in_array(
            FileFolderShare::LEVEL_MANAGE,
            $this->access->folderLevels($actor),
            true
        );
        if (!$managesSomething) {
            return $this->json($response, ['ok' => false, 'error' => 'Dafür fehlt die Berechtigung.'], 403);
        }

        $body = $request->getParsedBody();
        $row = self::parseShareRows([is_array($body) ? $body : []])[0];
        try {
            $conditions = $this->normalizer->normalize($row);
        } catch (InvalidAudienceFilterException $exception) {
            return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        // Der Filter existiert nur für diese Zählung: anlegen, zählen, zurückrollen.
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            $filter = $this->filters->create($conditions);
            $count = $this->filters->membersQuery((int) $filter->id)->count();
        } finally {
            $connection->rollBack();
        }

        return $this->json($response, ['ok' => true, 'count' => $count]);
    }
}
```

Route in `src/Routes.php` innerhalb der `/files`-Gruppe:

```php
                        $files->post('/audience-preview', [FileAudienceController::class, 'preview']);
```

samt `use App\Controllers\FileAudienceController;`.

Run: `ddev php vendor/bin/phpunit --filter FileAudience` → `OK (4 tests …)`.

- [ ] **Step 4: Template `share_row.twig` neu schreiben**

Variablen: `index`, `share` (Eintrag aus `FileShareDescriber::label` oder `null` für die Vorlage), `level_labels`, `share_options`.

```twig
{# Eine Freigabe: Filter aus Bedingungen plus Stufe. Mehrere Werte in einem Feld
   genügen einzeln, mehrere Felder müssen alle zutreffen. #}
{% set _conditions = share.conditions|default({}) %}
{% set _all = share.all|default(false) %}
{% set _summary = share.label|default("Neue Freigabe") %}
<div class="files-share-row card mb-2" data-files-share-row>
    <div class="card-body py-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button"
                    class="btn btn-link p-0 text-start flex-grow-1 files-share-summary"
                    data-files-share-toggle
                    aria-expanded="{{ share ? "false" : "true" }}">
                {{ _summary }}
            </button>
            <span class="small text-muted" data-files-share-count></span>
            <select class="form-select form-select-sm w-auto" name="shares[{{ index }}][level]" aria-label="Stufe">
                {% for value, label in level_labels %}
                    <option value="{{ value }}" {{ share.level|default(1) == value ? "selected" : "" }}>{{ label }}</option>
                {% endfor %}
            </select>
            <button type="button" class="btn btn-outline-danger btn-sm" data-files-share-remove aria-label="Freigabe entfernen">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="files-share-editor mt-2" data-files-share-editor {{ share ? "hidden" : "" }}>
            <div class="form-check mb-2">
                <input class="form-check-input"
                       type="checkbox"
                       value="1"
                       id="share-all-{{ index }}"
                       name="shares[{{ index }}][all]"
                       data-files-share-all
                       {{ _all ? "checked" : "" }}>
                <label class="form-check-label" for="share-all-{{ index }}">Alle Mitglieder</label>
            </div>
            <div class="row g-2" data-files-share-fields>
                {{ include("files/partials/share_condition_select.twig", {
                    category: "role", label: "Rolle", items: share_options.roles, selected: _conditions.role|default([]),
                }) }}
                {{ include("files/partials/share_condition_select.twig", {
                    category: "voice_group", label: "Stimmgruppe", items: share_options.voice_groups,
                    selected: _conditions.voice_group|default([]),
                }) }}
                {{ include("files/partials/share_condition_select.twig", {
                    category: "sub_voice", label: "Untergruppe", grouped: share_options.sub_voices,
                    selected: _conditions.sub_voice|default([]),
                }) }}
                {{ include("files/partials/share_condition_select.twig", {
                    category: "project", label: "Projekt", items: share_options.projects,
                    selected: _conditions.project|default([]),
                }) }}
                {{ include("files/partials/share_condition_select.twig", {
                    category: "user", label: "Mitglied", items: share_options.users, person: true,
                    selected: _conditions.user|default([]),
                }) }}
            </div>
            <p class="form-text mb-0">
                Mehrere Werte in einem Feld: eines davon genügt. Mehrere Felder: alle müssen zutreffen.
            </p>
        </div>
    </div>
</div>
```

`templates/files/partials/share_condition_select.twig` (Name muss `index` aus dem Kontext sehen – `include` übergibt den Kontext):

```twig
<div class="col-12 col-md-6">
    <label class="form-label small" for="share-{{ index }}-{{ category }}">{{ label }}</label>
    <select class="form-select form-select-sm"
            id="share-{{ index }}-{{ category }}"
            name="shares[{{ index }}][conditions][{{ category }}][]"
            data-tom-select
            data-files-share-condition
            data-placeholder="{{ label }} wählen …"
            multiple>
        {% if grouped is defined %}
            {% for entry in grouped %}
                <optgroup label="{{ entry.group }}">
                    {% for item in entry.items %}
                        <option value="{{ item.id }}" {{ item.id in selected ? "selected" : "" }}>{{ item.name }}</option>
                    {% endfor %}
                </optgroup>
            {% endfor %}
        {% else %}
            {% for item in items %}
                {% set _name = person|default(false) ? item|person_name : item.name %}
                <option value="{{ item.id }}" {{ item.id in selected ? "selected" : "" }}>
                    {{ _name }}{{ item.ended|default(false) ? " (beendet)" : "" }}
                </option>
            {% endfor %}
        {% endif %}
    </select>
</div>
```

Einbindung in `folder_modals.twig` und `file.twig`: Schleife `{% for share in shares %}{{ include("files/partials/share_row.twig", {index: loop.index0, share: share}) }}{% endfor %}`, Vorlage `<template data-files-share-template>{{ include("files/partials/share_row.twig", {index: "__INDEX__", share: null}) }}</template>`; in `file.twig` zusätzlich `level_labels: file_level_labels` übergeben. In beiden Seiten im `scripts`-Block ergänzen (und im `styles`-Block das TomSelect-CSS):

```twig
{% block styles %}
    <link rel="stylesheet" href="/vendor/tom-select/css/tom-select.bootstrap5.min.css">
{% endblock styles %}
```

```twig
    <script src="/vendor/tom-select/js/tom-select.complete.min.js"></script>
    <script src="{{ asset_path("/js/tom-select-init.js") }}"></script>
    <script src="{{ asset_path("/js/file-audience.js") }}"></script>
    <script src="{{ asset_path("/js/file-manager.js") }}"></script>
```

`folder.twig` hat noch keinen `styles`-Block – prüfen, wie `layout.twig` ihn anbietet (`{% block styles %}`), und genauso einbinden wie `templates/events/index.twig`.

- [ ] **Step 5: `public/js/file-audience.js`**

```js
/**
 * Freigabe-Zeilen mit Zielgruppen-Filter: auf-/zuklappen, "Alle Mitglieder"
 * sperrt die Felder, Trefferzahl live über /files/audience-preview.
 */
(function () {
    'use strict';

    const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function rowPayload(row) {
        const data = new URLSearchParams();
        const all = row.querySelector('[data-files-share-all]');
        if (all && all.checked) {
            data.append('all', '1');
        }
        row.querySelectorAll('[data-files-share-condition]').forEach(function (select) {
            const match = select.name.match(/\[conditions\]\[([a-z_]+)\]/);
            if (!match) {
                return;
            }
            Array.prototype.forEach.call(select.selectedOptions, function (option) {
                data.append('conditions[' + match[1] + '][]', option.value);
            });
        });
        return data;
    }

    function setFieldsDisabled(row) {
        const all = row.querySelector('[data-files-share-all]');
        const disabled = !!(all && all.checked);
        row.querySelectorAll('[data-files-share-condition]').forEach(function (select) {
            select.disabled = disabled;
            if (select.tomselect) {
                disabled ? select.tomselect.disable() : select.tomselect.enable();
            }
        });
    }

    function bind(row) {
        let timer = null;
        const counter = row.querySelector('[data-files-share-count]');

        function refresh() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fetch('/files/audience-preview', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
                    body: rowPayload(row),
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        row.classList.toggle('files-share-row--empty', !!(data && data.ok && data.count === 0));
                        counter.textContent = data && data.ok
                            ? 'trifft derzeit ' + data.count + (data.count === 1 ? ' Mitglied' : ' Mitglieder')
                            : (data && data.error ? data.error : '');
                    })
                    .catch(function () { counter.textContent = ''; });
            }, 300);
        }

        const toggle = row.querySelector('[data-files-share-toggle]');
        const editor = row.querySelector('[data-files-share-editor]');
        if (toggle && editor) {
            toggle.addEventListener('click', function () {
                editor.hidden = !editor.hidden;
                toggle.setAttribute('aria-expanded', editor.hidden ? 'false' : 'true');
            });
        }
        row.addEventListener('change', function (event) {
            if (event.target.matches('[data-files-share-all]')) {
                setFieldsDisabled(row);
            }
            refresh();
        });
        setFieldsDisabled(row);
        refresh();
    }

    window.fileAudience = { bind: bind };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-files-share-row]').forEach(bind);
    });
})();
```

In `public/js/file-manager.js`, `setupShares` nach `rows.appendChild(row);`:

```js
            if (window.initTomSelects) {
                window.initTomSelects(row);
            }
            if (window.fileAudience) {
                window.fileAudience.bind(row);
            }
```

- [ ] **Step 6: CSS**

```css
/* Freigabe-Zeile, die derzeit niemanden trifft. */
.files-share-row--empty {
    border-color: var(--bs-warning);
    background: rgba(var(--bs-warning-rgb), 0.08);
}

.files-share-summary {
    color: var(--theme-text);
    text-decoration: none;
}
```

- [ ] **Step 7: Controller-Aufrufe von `options()` anpassen**

`FileBrowserController::folder`: `$described = $canManage ? $this->describeShares($folder) : [];` und `'share_options' => $canManage ? $this->describer->options(self::projectIdsOf($described)) : null`, mit

```php
    /**
     * @param list<array{conditions: array<string, list<int>>}> $described
     * @return list<int>
     */
    private static function projectIdsOf(array $described): array
    {
        $ids = [];
        foreach ($described as $share) {
            foreach ($share['conditions']['project'] ?? [] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
```

`FileDetailController::show` entsprechend (die Hilfsfunktion in `FileControllerSupport` legen, damit beide sie nutzen).

- [ ] **Step 8: Hilfe-Hinweis**

In `help/files/docs/files-sharing.md` direkt unter der Überschrift „## 2. Freigaben setzen“ einfügen:

```markdown
> **Hinweis:** Die Auswahl der Ziele hat sich geändert. Eine Freigabe kann jetzt mehrere Bedingungen verbinden, z. B. „Stimmgruppe Sopran“ **und** „Projekt Frühjahrskonzert“. Mehrere Werte in einem Feld genügen einzeln, mehrere Felder müssen alle zutreffen. Diese Seite wird noch überarbeitet.
```

- [ ] **Step 9: Prüfen**

Run: `ddev php vendor/bin/phpunit --filter "File|Audience"` → grün.
Run: `ddev composer twigcs` → keine Verletzung. `ddev composer phpcs` → keine Verstöße.

- [ ] **Step 10: Commit**

---

### Task 6: Seed, Schärfeprobe, Verifikation

**Files:**
- Modify: `src/Services/DevSeedService.php`

**Interfaces:**
- Consumes: `FileFolderService::setShares` (neues Zeilenformat), `$root`/`$child`-Closures aus `seedFileManagement`

- [ ] **Step 1: Kombinierte Freigaben im Seed**

In `seedFileManagement`, im Block des laufenden Projekts (nach `$projectFolder`):

```php
            $soprano = $voiceData['groups']['Sopran'] ?? null;
            if ($soprano !== null) {
                $rehearsals = $root('Stimmproben ' . $running->name, null, [
                    ['level' => FileFolderShare::LEVEL_READ, 'conditions' => [
                        'voice_group' => [(int) $soprano->id],
                        'project' => [(int) $running->id],
                    ]],
                ]);
                if ($rehearsals !== null) {
                    $upload($rehearsals, 'Sopran Einsingen.mp3', ['mime_type' => 'audio/mpeg', 'content' => $this->silentMp3(40)]);
                }
            }
```

Und für eine Datei „Untergruppe Alt 2 · Rolle Mitglied“ im Notenordner:

```php
            $alt2 = SubVoice::query()->where('name', 'Alt 2')->first();
            $memberRole = $roles['Mitglied'] ?? null;
            $altPart = StoredFile::query()->where('name', 'Stimmauszug Alt - Ave verum.pdf')->first();
            if ($alt2 !== null && $memberRole !== null && $altPart !== null) {
                $filter = (new AudienceFilterService())->create([
                    'role' => [(int) $memberRole->id],
                    'sub_voice' => [(int) $alt2->id],
                ]);
                FileShare::create([
                    'file_id' => (int) $altPart->id,
                    'audience_filter_id' => (int) $filter->id,
                    'level' => FileFolderShare::LEVEL_READ,
                    'created_by' => (int) $adminUser->id,
                ]);
                $this->report['counts']['file_shares']++;
            }
```

Am Ende von `seedFileManagement`:

```php
        $this->report['counts']['audience_filters'] = (int) Capsule::table('audience_filters')->count();
        $this->report['counts']['audience_filter_conditions'] = (int) Capsule::table('audience_filter_conditions')->count();
```

Run: `ddev exec env APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed`
Expected: `"status":"ok"`, `audience_filters` > 0, `audience_filter_conditions` > 0.

- [ ] **Step 2: Schärfeprobe**

Je eine Sabotage, gefilterter Lauf, Rücknahme:

| Sabotage | Datei | Erwartung |
| --- | --- | --- |
| In `matchingFilterIds` nach dem ersten passenden Wert irgendeiner Kategorie `$fits = true; break 2;` (UND → ODER) | `AudienceFilterService.php` | `AudienceFilterService` und `FileShareAccess` rot |
| In `normalize()` die Ausnahme bei leerer Menge auskommentieren | `AudienceFilterNormalizer.php` | `AudienceFilterNormalizer`, `FileFolderService` rot |
| Prüfung in `up()` entfernen | Migration `…090100` | `AudienceFilterMigration` rot |
| Rollback in `FileAudienceController::preview` entfernen | `FileAudienceController.php` | `FileAudience` rot |

- [ ] **Step 3: Volle Verifikation**

```bash
ddev exec ./vendor/bin/phinx migrate
ddev exec ./vendor/bin/phinx rollback -t 20261002090000
ddev exec ./vendor/bin/phinx migrate
ddev composer test:parallel
ddev composer phpcs
ddev composer twigcs
ddev exec env APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed
```

Hinweis: Der Rollback auf dem Seed-Stand bricht wegen der kombinierten Freigaben aus Step 1 ab – das ist der erwartete Schutz. Rollback deshalb **vor** dem Seed-Lauf auf einem Stand mit nur einfachen Freigaben prüfen.

- [ ] **Step 4: Commit** nach `/git-commit` (Beleg-Abschnitt mit den Zahlen der Läufe und der Schärfeprobe).

# Rollenübersicht „Rolle im Fokus“ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Berechtigungsmatrix unter `/roles` (11 Rollen × 22 Rechte, breiter als der Inhaltsbereich) wird durch eine Ansicht ersetzt, die eine Rolle zur Zeit zeigt und zusätzlich die Frage „Wer hat das Recht XY?“ beantwortet. Sie funktioniert ohne Querscrollen auf Desktop und Handy.

**Architecture:** Welche Rechte es gibt, wie sie heißen, wie sie gruppiert sind und an welchem Modul sie hängen, steht künftig an einer Stelle: `App\Services\RolePermissionCatalog`. `App\Services\RoleOverview` rechnet daraus pro Rolle die Zahl der erteilten Rechte und pro Recht die Rollen, die es halten. Das neue Partial `templates/roles/_overview.twig` rendert alles serverseitig: eine Detailsektion pro Rolle und einmalig eine versteckte Halter-Liste je Recht. `public/js/role-overview.js` schaltet nur noch um, filtert und klappt auf. Die reine Logik darin ist unter `node --test` geprüft.

**Tech Stack:** PHP 8 / Slim 4 / Twig, Eloquent, Bootstrap 5.3.8 + Bootstrap Icons, Vanilla-JS im Stil von `public/js/navigation-search-rank.js` (IIFE, `var`, `module.exports`-Weiche), PHPUnit, Node (`node --test`).

**Spec:** Dieses Dokument (Abschnitt „Design“) plus das abgestimmte Modell, Variante 2 „Rolle im Fokus“: https://claude.ai/artifact/AqYJ3q2V53iQQSUvVT2dSJ

## Design

Breiter Container (ab 720 px Breite des Bereichs, per Container Query, nicht per Viewport, weil links die Seitenleiste sitzt):

```
┌ Rollenliste (260px) ─────────┐ ┌ [Recht suchen, z. B. Budget            ] ┐
│ Wer hat „Budget verwalten“?  │ │ Kassier                     [Bearbeiten] │
│ ▸ Admin        ✓  L100       │ │ Level 80 · 1 aktive Mitglieder · 7/22    │
│   1 aktiv · 22/22 Rechte ▬▬▬ │ │ MITGLIEDERVERWALTUNG    FINANZEN         │
│ ▸ Obleute      ✓  L90        │ │ ·  Rollen verwalten  2  ✓ Finanzen lesen 6│
│ ▸ Vorstand     ·  (blass)    │ │ ...                     ✓ Budget verw. 4 │
│ ...                          │ │                           [Admin][Obleute]│
└──────────────────────────────┘ └──────────────────────────────────────────┘
```

Schmaler Container (unter 720 px): Die Rollenliste wird durch ein `<select>` ersetzt („Kassier · Level 80 · 7/22“), und die Gruppen stehen untereinander. Die Zeile „Wer hat …?“ entfällt; die Antwort kommt über die aufgeklappten Chips.

Verhalten:

1. **Rolle wählen:** Ein Klick in der Liste oder eine Auswahl im Select zeigt die Detailsektion dieser Rolle. Liste und Select bleiben synchron. Die Auswahl steht im URL-Hash `#role-<id>` und in `sessionStorage` (`roles.selectedRoleId`), damit nach dem Speichern über das Modal (POST, Redirect auf `/roles`) dieselbe Rolle wieder offen ist. Vorrang beim Laden: Hash, dann sessionStorage, dann die erste Rolle.
2. **Recht anklicken:** Jedes Recht zeigt die Zahl der Rollen, die es halten („4 Rollen“). Ein Klick klappt darunter die Halter als Chips auf und markiert sie in der Rollenliste (✓ bzw. blass). Es ist höchstens ein Recht gleichzeitig aufgeklappt; ein zweiter Klick schließt es.
3. **Chip anklicken:** Wechselt zu dieser Rolle; das aufgeklappte Recht bleibt offen.
4. **Suche:** Filtert die Rechte in allen Detailsektionen nach Label, ohne Rücksicht auf Groß-/Kleinschreibung und Umlaute („STIMMGRUPPE“ findet „Eigene Stimmgruppe verwalten“, „fuhr“ findet dasselbe wie „führ“). Gruppen ohne Treffer verschwinden. Bei höchstens drei Treffern klappen deren Halter automatisch auf (dann ist kein einzelnes Recht „aktiv“, die Rollenliste wird nicht markiert). Ohne Treffer erscheint „Kein Recht passt zu dieser Suche.“
5. **Bearbeiten / Löschen:** Die bestehenden Buttons (`.edit-role-btn` mit allen `data-*`-Attributen, Löschen nur bei `assigned_users_count == 0`) wandern unverändert in den Kopf der Detailsektion. `public/js/roles.js` und beide Modals bleiben unverändert.
6. **Ohne JavaScript:** Alle Detailsektionen stehen untereinander; Halter-Chips bleiben verborgen, die Zahlen sind sichtbar. Liste und Select sind dann ohne Wirkung. Das reicht, weil das Bearbeiten ohnehin Bootstrap-JS braucht.

Darstellung: Erteilt = grüner Haken (`bi-check-circle-fill text-success`), nicht erteilt = blasser Punkt, Label in `text-body-secondary`. Kein Rot mehr. Der Anteilsbalken in der Liste bekommt die Breite über die CSS-Variable `--role-share` (erlaubte Ausnahme aus `instructions/template-hygiene.md`).

Nicht Teil dieses Plans: die Vergleichsansicht (Variante 4) und ein Hilfethema. Beides bei Bedarf als eigener Schritt.

## Global Constraints

- Alle Befehle über DDEV: einzelne Tests `ddev php vendor/bin/phpunit --filter "<Muster>"`, volle Suite einmal am Schluss `ddev composer test:parallel`, Stil `ddev composer phpcs` / `ddev composer twigcs`. JS-Tests auf dem Host: `npm run test:js`.
- Bezeichner englisch. UI-Texte, Kommentare, Testbeschreibungen und Commit-Nachrichten deutsch mit echten Umlauten (`ä ö ü ß`).
- Neue und geänderte Textdateien mit LF-Zeilenenden. Nach jedem Schreiben unter Windows normalisieren:
  `$f = "<absoluter Pfad>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
- PHP: PSR-12, 4 Leerzeichen, Zeilenlänge höchstens 130. Twig: doppelte Anführungszeichen, `name=value` ohne Leerzeichen bei Defaults, keine mehrzeiligen Bool-Ausdrücke, keine Inline-Skripte, `style` nur für `--role-share`.
- Keine externen Assets; Icons aus dem lokal ausgelieferten Bootstrap Icons.
- Keine Schemaänderung, keine Migration, keine neuen Seed-Daten (es wird nichts Neues persistiert).
- Rechte-Labels exakt wie bisher in der Matrix (siehe Task 1); `docs/*.md` verweisen auf diese Labels.
- Kein `git push`. Gearbeitet wird auf dem Branch `feat/role-overview`; am Ende führt `/git-commit` Squash und Merge nach `main` durch.

## Review Focus

1. **Rollenname mit HTML-Zeichen** (`<b>Chor & Co</b>`): Er muss in Liste, Select, Detail-Kopf und Chips escaped erscheinen. Twig-Autoescape greift, wird aber durch `|raw` oder `innerHTML` im JS schnell ausgehebelt. Das JS kopiert die Chips deshalb mit `cloneNode`, nicht über HTML-Strings. → Test in Task 3.
2. **Recht, das keine Rolle hält** (z. B. ein neu eingeführtes Recht): Die Zahl zeigt „0 Rollen“, aufgeklappt steht „Keine Rolle hat dieses Recht.“, kein leerer Kasten. → Tests in Task 2 und Task 3.
3. **Modul ausgeschaltet** (`settings.modules.files = false`): Das Recht taucht weder in der Liste noch in der Gesamtzahl „x/22“ auf. Eine Gruppe, deren Rechte alle ausgeblendet sind (Finanzen bei `finance` und `budget` aus), verschwindet samt Überschrift. → Tests in Task 1, Task 2 und Task 3.
4. **Veralteter Hash oder sessionStorage-Wert** (Rolle inzwischen gelöscht, `#role-abc`): Dann wird die erste Rolle gewählt und kein leerer Bereich gezeigt. → Test in Task 4.
5. **Suche nach Umlauten und mit Leerzeichen** („  Führ “, „fuhr“): Beide finden dieselben Rechte; reine Leerzeichen gelten als leere Suche (alles sichtbar, nichts automatisch aufgeklappt). → Test in Task 4.

---

## Dateien

| Datei | Aufgabe |
|---|---|
| `src/Services/RolePermissionCatalog.php` (neu) | Gruppen, Labels, Modul-Bindung aller Rechte; einzige Quelle |
| `src/Services/RoleOverview.php` (neu) | Zählt erteilte Rechte pro Rolle und Halter pro Recht |
| `src/Controllers/RoleController.php` (ändern) | `MODULE_GATED_PERMISSIONS` aus dem Katalog; `index()` übergibt `permission_groups` und `overview` |
| `templates/roles/_overview.twig` (neu) | Rollenliste, Select, Suche, Detailsektionen, Halter-Quelle |
| `templates/roles/index.twig` (ändern) | Matrix-Tabelle und Table-Engine raus, Partial rein, Skript einbinden |
| `public/js/role-overview.js` (neu) | Reine Funktionen + DOM-Verhalten |
| `public/css/style.css` (ändern) | `.role-overview*`-Regeln, `.roles-matrix-label` entfernen |
| `tests/Unit/Services/RolePermissionCatalogTest.php` (neu) | Katalog |
| `tests/Unit/Services/RoleOverviewTest.php` (neu) | Zählungen |
| `tests/Feature/RoleOverviewTemplateFeatureTest.php` (neu) | Gerendertes Partial |
| `tests/js/role-overview.test.mjs` (neu) | Reine JS-Funktionen |
| `tests/Feature/{EventManagementPermission,RoleAssignOwnVoiceGroupProjectUi,RoleFilesPermission,RoleManagementPermission,RoleOwnVoiceGroupUi,RoleSheetArchiveModuleGate}FeatureTest.php` (ändern) | Matrix-Regex durch Katalog-Prüfung ersetzen |
| `tests/Feature/TableUxFeatureTest.php` (ändern) | `templates/roles/index.twig` aus den Table-Engine-Listen streichen |

---

### Task 0: Branch anlegen

- [ ] **Step 1:** `git switch -c feat/role-overview` (Ausgangspunkt: sauberes `main`).

### Task 1: Rechte-Katalog

**Files:**
- Create: `src/Services/RolePermissionCatalog.php`
- Modify: `src/Controllers/RoleController.php:18-33` (Konstante) und `:112` (Verwendung)
- Test: `tests/Unit/Services/RolePermissionCatalogTest.php`

**Interfaces:**
- Produces:
  - `RolePermissionCatalog::groupsForModules(array<string,bool> $modules): list<array{label: string, permissions: list<array{key: string, label: string}>}>`. Ein fehlender Modulschlüssel zählt als „aus“, wie `settings.modules.x` in Twig.
  - `RolePermissionCatalog::moduleGates(): array<string,string>` (Recht → Modul)
  - `RolePermissionCatalog::keys(): list<string>`

- [ ] **Step 1: Failing test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Services\RolePermissionCatalog;
use PHPUnit\Framework\TestCase;

final class RolePermissionCatalogTest extends TestCase
{
    private const ALL_MODULES = [
        'finance' => true, 'budget' => true, 'sponsoring' => true, 'newsletter' => true,
        'sheet_archive' => true, 'tasks' => true, 'files' => true,
    ];

    public function testCatalogCoversExactlyTheRolePermissions(): void
    {
        $keys = RolePermissionCatalog::keys();
        sort($keys);
        $expected = Role::PERMISSIONS;
        sort($expected);

        $this->assertSame($expected, $keys, 'Jedes Recht aus Role::PERMISSIONS braucht genau einen Katalogeintrag');
    }

    public function testModuleGatesMatchPreviousControllerMap(): void
    {
        $this->assertSame([
            'can_read_finances' => 'finance',
            'can_manage_finances' => 'finance',
            'can_manage_budget' => 'budget',
            'can_manage_sponsoring' => 'sponsoring',
            'can_create_own_sponsorships' => 'sponsoring',
            'can_manage_sheet_archive' => 'sheet_archive',
            'can_manage_newsletters' => 'newsletter',
            'can_manage_tasks' => 'tasks',
            'can_manage_files' => 'files',
        ], RolePermissionCatalog::moduleGates());
    }

    public function testAllModulesOnShowsGroupsAndLabelsInMatrixOrder(): void
    {
        $groups = RolePermissionCatalog::groupsForModules(self::ALL_MODULES);

        $this->assertSame(
            ['Mitgliederverwaltung', 'Finanzen', 'Sponsoring & Repertoire', 'Kommunikation & Planung', 'Stammdaten & System'],
            array_column($groups, 'label')
        );
        $this->assertSame(
            ['key' => 'can_manage_events', 'label' => 'Termine verwalten'],
            $groups[0]['permissions'][3]
        );
        $this->assertCount(22, array_merge(...array_column($groups, 'permissions')));
    }

    public function testMissingModuleFlagHidesPermission(): void
    {
        $groups = RolePermissionCatalog::groupsForModules(['files' => false] + self::ALL_MODULES);
        $keys = array_column(array_merge(...array_column($groups, 'permissions')), 'key');

        $this->assertNotContains('can_manage_files', $keys);
        $this->assertContains('can_manage_mail_queue', $keys);
    }

    public function testGroupWithoutVisiblePermissionDisappears(): void
    {
        $groups = RolePermissionCatalog::groupsForModules(['finance' => false, 'budget' => false] + self::ALL_MODULES);

        $this->assertNotContains('Finanzen', array_column($groups, 'label'));
    }

    public function testEmptyModuleListKeepsUngatedPermissions(): void
    {
        $keys = array_column(
            array_merge(...array_column(RolePermissionCatalog::groupsForModules([]), 'permissions')),
            'key'
        );

        $this->assertCount(13, $keys);
        $this->assertContains('can_manage_song_library', $keys);
        $this->assertNotContains('can_read_finances', $keys);
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter RolePermissionCatalogTest` → FAIL („Class App\Services\RolePermissionCatalog not found“).

- [ ] **Step 3: Implementierung**

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Alle Rollenrechte mit Anzeigename, Gruppe und dem Modul, an dem sie hängen.
 *
 * Vorher stand diese Zuordnung als ausgeschriebene Matrixzeilen im Template und
 * als Modul-Liste im RoleController. Die Rollenübersicht und das Ausblenden
 * abgeschalteter Module lesen jetzt beide hier.
 */
final class RolePermissionCatalog
{
    /**
     * @var list<array{label: string, permissions: list<array{key: string, label: string, module: ?string}>}>
     */
    private const GROUPS = [
        ['label' => 'Mitgliederverwaltung', 'permissions' => [
            ['key' => 'can_manage_users', 'label' => 'Mitgliederverwaltung erlauben', 'module' => null],
            ['key' => 'can_manage_roles', 'label' => 'Rollen verwalten', 'module' => null],
            ['key' => 'can_edit_users', 'label' => 'Mitglieder editieren erlauben', 'module' => null],
            ['key' => 'can_manage_events', 'label' => 'Termine verwalten', 'module' => null],
            [
                'key' => 'can_manage_attendance_all',
                'label' => 'Anwesenheit/Anmeldung verwalten (alle Mitglieder)',
                'module' => null,
            ],
            ['key' => 'can_manage_project_members', 'label' => 'Projektmitglieder verwalten', 'module' => null],
            [
                'key' => 'can_assign_own_voice_group_to_project',
                'label' => 'Eigene Stimmgruppe ins Projekt zuweisen',
                'module' => null,
            ],
            ['key' => 'can_manage_own_voice_group', 'label' => 'Eigene Stimmgruppe verwalten', 'module' => null],
        ]],
        ['label' => 'Finanzen', 'permissions' => [
            ['key' => 'can_read_finances', 'label' => 'Finanzen nur lesen', 'module' => 'finance'],
            ['key' => 'can_manage_finances', 'label' => 'Finanzen lesen und schreiben', 'module' => 'finance'],
            ['key' => 'can_manage_budget', 'label' => 'Budget verwalten', 'module' => 'budget'],
        ]],
        ['label' => 'Sponsoring & Repertoire', 'permissions' => [
            ['key' => 'can_manage_sponsoring', 'label' => 'Sponsoring verwalten', 'module' => 'sponsoring'],
            [
                'key' => 'can_create_own_sponsorships',
                'label' => 'Eigene Sponsoring-Vereinbarungen erfassen',
                'module' => 'sponsoring',
            ],
            ['key' => 'can_manage_song_library', 'label' => 'Repertoire verwalten', 'module' => null],
            ['key' => 'can_manage_sheet_archive', 'label' => 'Notenarchiv verwalten', 'module' => 'sheet_archive'],
        ]],
        ['label' => 'Kommunikation & Planung', 'permissions' => [
            ['key' => 'can_manage_newsletters', 'label' => 'Newsletter verwalten', 'module' => 'newsletter'],
            ['key' => 'can_manage_mail_queue', 'label' => 'Mailversand verwalten', 'module' => null],
            ['key' => 'can_manage_tasks', 'label' => 'Projektplanung (Aufgaben)', 'module' => 'tasks'],
            ['key' => 'can_manage_files', 'label' => 'Dateiverwaltung verwalten', 'module' => 'files'],
        ]],
        ['label' => 'Stammdaten & System', 'permissions' => [
            ['key' => 'can_manage_master_data', 'label' => 'Stammdaten verwalten', 'module' => null],
            ['key' => 'can_manage_backups', 'label' => 'Backup-Verwaltung', 'module' => null],
            ['key' => 'can_manage_storage', 'label' => 'Speicherplatz-Verwaltung', 'module' => null],
        ]],
    ];

    /**
     * Gruppen mit den Rechten, deren Modul aktiv ist. Ein fehlender Modulschlüssel
     * gilt wie in Twig (`settings.modules.x`) als ausgeschaltet; leere Gruppen fallen weg.
     *
     * @param array<string,mixed> $modules
     * @return list<array{label: string, permissions: list<array{key: string, label: string}>}>
     */
    public static function groupsForModules(array $modules): array
    {
        $groups = [];
        foreach (self::GROUPS as $group) {
            $permissions = [];
            foreach ($group['permissions'] as $permission) {
                if ($permission['module'] !== null && !(bool) ($modules[$permission['module']] ?? false)) {
                    continue;
                }
                $permissions[] = ['key' => $permission['key'], 'label' => $permission['label']];
            }
            if ($permissions !== []) {
                $groups[] = ['label' => $group['label'], 'permissions' => $permissions];
            }
        }

        return $groups;
    }

    /**
     * @return array<string,string> Recht => Modul, nur für modulgebundene Rechte
     */
    public static function moduleGates(): array
    {
        $gates = [];
        foreach (self::GROUPS as $group) {
            foreach ($group['permissions'] as $permission) {
                if ($permission['module'] !== null) {
                    $gates[$permission['key']] = $permission['module'];
                }
            }
        }

        return $gates;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::GROUPS as $group) {
            foreach ($group['permissions'] as $permission) {
                $keys[] = $permission['key'];
            }
        }

        return $keys;
    }
}
```

Hinweis: `testModuleGatesMatchPreviousControllerMap` erwartet die Katalogreihenfolge (Notenarchiv vor Newsletter). Inhaltlich ist es dieselbe Map wie die alte Konstante.

- [ ] **Step 4: Controller umstellen.** In `src/Controllers/RoleController.php` die Konstante `MODULE_GATED_PERMISSIONS` samt Docblock löschen, `use App\Services\RolePermissionCatalog;` ergänzen und in Zeile ~112 `foreach (self::MODULE_GATED_PERMISSIONS as $permission => $module)` ersetzen durch `foreach (RolePermissionCatalog::moduleGates() as $permission => $module)`.

- [ ] **Step 5:** `ddev php vendor/bin/phpunit --filter "RolePermissionCatalogTest|RoleFilesPermissionFeatureTest|RoleSheetArchiveModuleGateFeatureTest|RolePermissionGrantCapFeatureTest|RoleDisabledModulePermissionFeatureTest"` → PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Services/RolePermissionCatalog.php src/Controllers/RoleController.php tests/Unit/Services/RolePermissionCatalogTest.php
git commit -m "feat(roles): Rechte-Katalog als einzige Quelle für Labels, Gruppen und Modul-Bindung"
```

### Task 2: Rollenübersicht berechnen

**Files:**
- Create: `src/Services/RoleOverview.php`
- Test: `tests/Unit/Services/RoleOverviewTest.php`

**Interfaces:**
- Consumes: Gruppenstruktur aus `RolePermissionCatalog::groupsForModules()`
- Produces: `RoleOverview::build(iterable<Role> $roles, list<array{label:string, permissions: list<array{key:string,label:string}>}> $groups): array{total: int, granted_counts: array<int,int>, share_percent: array<int,int>, holders: array<string, list<int>>}`. Die Halter stehen in der Reihenfolge von `$roles`; jedes sichtbare Recht hat einen Eintrag, auch wenn er leer ist.

- [ ] **Step 1: Failing test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Services\RoleOverview;
use PHPUnit\Framework\TestCase;

final class RoleOverviewTest extends TestCase
{
    private const GROUPS = [
        ['label' => 'A', 'permissions' => [
            ['key' => 'can_manage_users', 'label' => 'Mitgliederverwaltung erlauben'],
            ['key' => 'can_manage_roles', 'label' => 'Rollen verwalten'],
        ]],
        ['label' => 'B', 'permissions' => [
            ['key' => 'can_manage_backups', 'label' => 'Backup-Verwaltung'],
        ]],
    ];

    /** @param array<string,int> $flags */
    private function role(int $id, array $flags): Role
    {
        $role = new Role();
        $role->forceFill(['id' => $id, 'name' => 'Rolle ' . $id] + $flags);

        return $role;
    }

    public function testCountsGrantedPermissionsAndHoldersInRoleOrder(): void
    {
        $roles = [
            $this->role(7, ['can_manage_users' => 1, 'can_manage_roles' => 1, 'can_manage_backups' => 0]),
            $this->role(3, ['can_manage_users' => 1, 'can_manage_roles' => 0, 'can_manage_backups' => 0]),
        ];

        $overview = RoleOverview::build($roles, self::GROUPS);

        $this->assertSame(3, $overview['total']);
        $this->assertSame([7 => 2, 3 => 1], $overview['granted_counts']);
        $this->assertSame([7 => 67, 3 => 33], $overview['share_percent']);
        $this->assertSame([7, 3], $overview['holders']['can_manage_users']);
        $this->assertSame([7], $overview['holders']['can_manage_roles']);
    }

    public function testPermissionWithoutHolderHasEmptyList(): void
    {
        $overview = RoleOverview::build([$this->role(1, ['can_manage_users' => 0])], self::GROUPS);

        $this->assertSame([], $overview['holders']['can_manage_backups']);
        $this->assertSame([1 => 0], $overview['granted_counts']);
        $this->assertSame([1 => 0], $overview['share_percent']);
    }

    public function testHiddenPermissionDoesNotCount(): void
    {
        $role = $this->role(1, ['can_manage_users' => 1, 'can_manage_files' => 1]);

        $overview = RoleOverview::build([$role], self::GROUPS);

        $this->assertSame([1 => 1], $overview['granted_counts']);
        $this->assertArrayNotHasKey('can_manage_files', $overview['holders']);
    }

    public function testNoVisiblePermissionsGivesZeroShareInsteadOfDivisionByZero(): void
    {
        $overview = RoleOverview::build([$this->role(1, [])], []);

        $this->assertSame(0, $overview['total']);
        $this->assertSame([1 => 0], $overview['share_percent']);
    }
}
```

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter RoleOverviewTest` → FAIL (Klasse fehlt).

- [ ] **Step 3: Implementierung**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Role;

/**
 * Kennzahlen für die Rollenübersicht: wie viele der sichtbaren Rechte jede Rolle
 * hält und welche Rollen ein Recht halten. Nur die übergebenen Gruppen zählen,
 * Rechte abgeschalteter Module bleiben also draußen.
 */
final class RoleOverview
{
    /**
     * @param iterable<Role> $roles
     * @param list<array{label: string, permissions: list<array{key: string, label: string}>}> $groups
     * @return array{total: int, granted_counts: array<int,int>, share_percent: array<int,int>,
     *     holders: array<string, list<int>>}
     */
    public static function build(iterable $roles, array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ($group['permissions'] as $permission) {
                $keys[] = $permission['key'];
            }
        }

        $total = count($keys);
        $holders = array_fill_keys($keys, []);
        $grantedCounts = [];
        $sharePercent = [];

        foreach ($roles as $role) {
            $roleId = (int) $role->getAttribute('id');
            $granted = 0;
            foreach ($keys as $key) {
                if ((bool) $role->getAttribute($key)) {
                    $holders[$key][] = $roleId;
                    $granted++;
                }
            }
            $grantedCounts[$roleId] = $granted;
            $sharePercent[$roleId] = $total === 0 ? 0 : (int) round($granted / $total * 100);
        }

        return [
            'total' => $total,
            'granted_counts' => $grantedCounts,
            'share_percent' => $sharePercent,
            'holders' => $holders,
        ];
    }
}
```

- [ ] **Step 4:** `ddev php vendor/bin/phpunit --filter RoleOverviewTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/RoleOverview.php tests/Unit/Services/RoleOverviewTest.php
git commit -m "feat(roles): Kennzahlen für die Rollenübersicht berechnen"
```

### Task 3: Partial, Controller und Seite umbauen

**Files:**
- Create: `templates/roles/_overview.twig`
- Modify: `templates/roles/index.twig:46-443` (Abschnitt „Berechtigungsmatrix“), `:1196-1198` (Skripte)
- Modify: `src/Controllers/RoleController.php` (`index()`)
- Modify: `public/css/style.css` (`.roles-matrix-label` ersetzen)
- Modify: die sechs Feature-Tests mit Matrix-Regex sowie `tests/Feature/TableUxFeatureTest.php`
- Test: `tests/Feature/RoleOverviewTemplateFeatureTest.php`

**Interfaces:**
- Consumes: `RolePermissionCatalog::groupsForModules()`, `RoleOverview::build()`
- Produces (DOM-Vertrag für Task 4):
  - `[data-role-overview]`: Wurzel
  - `select[data-role-select]#role-overview-select`: Optionen `value="<role id>"`
  - `button[data-role-id="<id>"]`: Listeneintrag mit `aria-pressed`, darin `[data-role-marker]`
  - `[data-role-who]` (hidden) mit `[data-role-who-label]`
  - `input[data-permission-search]#role-permission-search`, `[data-permission-empty]` (hidden)
  - `section[data-role-detail="<id>"]#role-detail-<id>`, darin `[data-permission-group]` mit `li[data-permission-key][data-permission-label]`, darin `button[data-permission-toggle][aria-expanded]` und `div[data-permission-holders-slot][hidden]`
  - `[data-permission-holders-source] [data-permission-holders="<key>"][data-role-ids="1,4"]` mit Chips `button[data-role-jump="<id>"]`

- [ ] **Step 1: Failing test schreiben** (`tests/Feature/RoleOverviewTemplateFeatureTest.php`)

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Services\RoleOverview;
use App\Services\RolePermissionCatalog;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class RoleOverviewTemplateFeatureTest extends TestCase
{
    /** @param array<string,mixed> $attributes */
    private function role(array $attributes): Role
    {
        $role = new Role();
        $role->forceFill($attributes + ['active_users_count' => 0, 'assigned_users_count' => 0]);

        return $role;
    }

    /** @param list<Role> $roles @param array<string,bool> $modules */
    private function render(array $roles, array $modules = ['files' => true, 'finance' => true]): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['autoescape' => 'html']);
        $groups = RolePermissionCatalog::groupsForModules($modules);

        return $twig->render('roles/_overview.twig', [
            'roles' => $roles,
            'permission_groups' => $groups,
            'overview' => RoleOverview::build($roles, $groups),
        ]);
    }

    private function sampleRoles(): array
    {
        return [
            $this->role(['id' => 1, 'name' => 'Admin', 'hierarchy_level' => 100, 'can_manage_events' => 1,
                'can_manage_files' => 1, 'active_users_count' => 2, 'assigned_users_count' => 2]),
            $this->role(['id' => 5, 'name' => 'Kassier', 'hierarchy_level' => 80, 'can_read_finances' => 1]),
        ];
    }

    public function testRendersOneDetailSectionAndOneOptionPerRole(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertStringContainsString('data-role-detail="1"', $html);
        $this->assertStringContainsString('data-role-detail="5"', $html);
        $this->assertMatchesRegularExpression('#<option value="5">\s*Kassier · Level 80 · 1/\d+\s*</option>#', $html);
        $this->assertStringContainsString('data-role-id="1"', $html);
    }

    public function testEditButtonCarriesPermissionDataAndDeleteOnlyWithoutAssignments(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertMatchesRegularExpression('#class="[^"]*edit-role-btn[^"]*"[^>]*data-id="1"[^>]*data-events="1"#s', $html);
        $this->assertStringNotContainsString('data-bs-target="#deleteRoleModal1"', $html);
        $this->assertStringContainsString('data-bs-target="#deleteRoleModal5"', $html);
    }

    public function testHoldersSourceListsRolesWithThePermission(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertMatchesRegularExpression(
            '#data-permission-holders="can_read_finances" data-role-ids="5">\s*<button[^>]*data-role-jump="5"[^>]*>Kassier</button>#',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#data-permission-holders="can_manage_backups" data-role-ids="">\s*<p[^>]*>Keine Rolle hat dieses Recht\.</p>#',
            $html
        );
    }

    public function testPermissionRowShowsHolderCountAndGrantedState(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertMatchesRegularExpression(
            '#data-permission-key="can_manage_events" data-permission-label="Termine verwalten"'
            . '.*?bi-check-circle-fill.*?Termine verwalten.*?1 Rolle<#s',
            $html
        );
    }

    public function testDisabledModuleHidesPermissionEverywhere(): void
    {
        $html = $this->render($this->sampleRoles(), ['finance' => true]);

        $this->assertStringNotContainsString('can_manage_files', $html);
        $this->assertStringNotContainsString('Dateiverwaltung verwalten', $html);
    }

    public function testRoleNameIsEscaped(): void
    {
        $html = $this->render([$this->role(['id' => 9, 'name' => '<b>Chor & Co</b>', 'hierarchy_level' => 1])]);

        $this->assertStringNotContainsString('<b>Chor', $html);
        $this->assertStringContainsString('&lt;b&gt;Chor &amp; Co&lt;/b&gt;', $html);
    }

    public function testEmptyRoleListShowsHint(): void
    {
        $this->assertStringContainsString('Keine Rollen gefunden.', $this->render([]));
    }
}
```

Hinweis zu `testPermissionRowShowsHolderCountAndGrantedState`: Bei genau einem Halter heißt es „1 Rolle“, sonst „n Rollen“.

- [ ] **Step 2:** `ddev php vendor/bin/phpunit --filter RoleOverviewTemplateFeatureTest` → FAIL („Unable to find template roles/_overview.twig“).

- [ ] **Step 3: Partial anlegen** (`templates/roles/_overview.twig`). Den Edit-Button mit **allen** `data-*`-Attributen 1:1 aus `templates/roles/index.twig:390-418` übernehmen (unten gekürzt durch Kommentar markiert, beim Umsetzen vollständig einfügen):

```twig
{# Rollenübersicht: links die Rollen, rechts die Rechte der gewählten Rolle. Die Halter je Recht
   stehen einmal in der versteckten Quelle unten und werden vom Skript an die angeklickte Stelle kopiert. #}
{% if roles is empty %}
    <p class="text-center py-4 text-muted mb-0">Keine Rollen gefunden.</p>
{% else %}
    <div class="role-overview" data-role-overview>
        <div class="role-overview-inner">
        <div class="role-overview-roles">
            <label class="form-label small text-body-secondary role-overview-select-label"
                   for="role-overview-select">Rolle</label>
            <select class="form-select role-overview-select" id="role-overview-select" data-role-select>
                {% for role in roles %}
                    <option value="{{ role.id }}">
                        {{ role.name }} · Level {{ role.hierarchy_level }} · {{ overview.granted_counts[role.id] }}/{{ overview.total }}
                    </option>
                {% endfor %}
            </select>
            <p class="role-overview-who small mb-2" data-role-who hidden>
                Wer hat <strong data-role-who-label></strong>?
            </p>
            <ul class="role-overview-list list-unstyled mb-0">
                {% for role in roles %}
                    <li>
                        <button type="button"
                                class="role-overview-role"
                                data-role-id="{{ role.id }}"
                                aria-pressed="false"
                                aria-controls="role-detail-{{ role.id }}">
                            <span class="role-overview-role-name">{{ role.name }}</span>
                            <span class="role-overview-role-marker" data-role-marker aria-hidden="true"></span>
                            <span class="role-overview-role-meta">
                                Level {{ role.hierarchy_level }} · {{ role.active_users_count }} aktiv · {{ overview.granted_counts[role.id] }}/{{ overview.total }} Rechte
                            </span>
                            <span class="role-overview-share" style="--role-share: {{ overview.share_percent[role.id] }}%"></span>
                        </button>
                    </li>
                {% endfor %}
            </ul>
        </div>

        <div class="role-overview-main">
            <label class="visually-hidden" for="role-permission-search">Recht suchen</label>
            <input type="search"
                   class="form-control role-overview-search"
                   id="role-permission-search"
                   placeholder="Recht suchen, z. B. Budget"
                   autocomplete="off"
                   data-permission-search>
            <p class="text-body-secondary py-3 mb-0" data-permission-empty hidden>Kein Recht passt zu dieser Suche.</p>

            {% for role in roles %}
                <section class="role-overview-detail"
                         id="role-detail-{{ role.id }}"
                         data-role-detail="{{ role.id }}"
                         aria-labelledby="role-detail-title-{{ role.id }}">
                    <header class="role-overview-detail-head">
                        <div>
                            <h3 class="h5 mb-1" id="role-detail-title-{{ role.id }}">{{ role.name }}</h3>
                            <p class="small text-body-secondary mb-0">
                                Level {{ role.hierarchy_level }} · {{ role.active_users_count }} aktive Mitglieder · {{ overview.granted_counts[role.id] }} von {{ overview.total }} Rechten
                            </p>
                        </div>
                        <div class="btn-group" role="group" aria-label="Rollenaktionen für {{ role.name }}">
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary edit-role-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editRoleModal"
                                    data-id="{{ role.id }}"
                                    data-name="{{ role.name }}"
                                    data-level="{{ role.hierarchy_level }}"
                                    {# … alle weiteren data-*-Attribute unverändert aus index.twig:397-417 … #}
                                    data-assign-own-voice-group-project="{{ role.can_assign_own_voice_group_to_project ? "1" : "0" }}">
                                <i class="bi bi-pencil"></i> Bearbeiten
                            </button>
                            {% if role.assigned_users_count == 0 %}
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteRoleModal{{ role.id }}">
                                    <i class="bi bi-trash"></i> Löschen
                                </button>
                            {% endif %}
                        </div>
                    </header>

                    <div class="role-overview-groups">
                        {% for group in permission_groups %}
                            <section class="role-overview-group" data-permission-group>
                                <h4 class="role-overview-group-title">{{ group.label }}</h4>
                                <ul class="list-unstyled mb-0">
                                    {% for permission in group.permissions %}
                                        {% set granted = attribute(role, permission.key) %}
                                        {% set holder_count = overview.holders[permission.key]|length %}
                                        <li data-permission-key="{{ permission.key }}" data-permission-label="{{ permission.label }}">
                                            <button type="button"
                                                    class="role-overview-permission{{ granted ? "" : " is-denied" }}"
                                                    data-permission-toggle
                                                    aria-expanded="false"
                                                    aria-controls="holders-{{ role.id }}-{{ permission.key }}">
                                                {% if granted %}
                                                    <i class="bi bi-check-circle-fill text-success" aria-label="erteilt"></i>
                                                {% else %}
                                                    <span class="role-overview-dot" aria-label="nicht erteilt"></span>
                                                {% endif %}
                                                <span class="role-overview-permission-label">{{ permission.label }}</span>
                                                <span class="role-overview-count">{{ holder_count }} {{ holder_count == 1 ? "Rolle" : "Rollen" }}</span>
                                            </button>
                                            <div class="role-overview-holders"
                                                 id="holders-{{ role.id }}-{{ permission.key }}"
                                                 data-permission-holders-slot
                                                 hidden></div>
                                        </li>
                                    {% endfor %}
                                </ul>
                            </section>
                        {% endfor %}
                    </div>
                </section>
            {% endfor %}
        </div>
        </div>

        <div data-permission-holders-source hidden>
            {% for group in permission_groups %}
                {% for permission in group.permissions %}
                    {% set holder_ids = overview.holders[permission.key] %}
                    <div data-permission-holders="{{ permission.key }}" data-role-ids="{{ holder_ids|join(",") }}">
                        {% for role in roles %}
                            {% if role.id in holder_ids %}
                                <button type="button" class="role-overview-chip" data-role-jump="{{ role.id }}">{{ role.name }}</button>
                            {% endif %}
                        {% endfor %}
                        {% if holder_ids is empty %}
                            <p class="small text-body-secondary mb-0">Keine Rolle hat dieses Recht.</p>
                        {% endif %}
                    </div>
                {% endfor %}
            {% endfor %}
        </div>
    </div>
{% endif %}
```

Hinweise: `.role-overview-inner` ist nötig, weil eine Container Query nur auf Nachfahren des Containers wirkt, nicht auf `.role-overview` selbst; beim Umsetzen den Inhalt darin eine Ebene tiefer einrücken. Die Leerzeichen zwischen den Tags sind so gesetzt, dass die Regex-Tests oben greifen (`\s*`). Wenn twigcs Zeilen über 130 Zeichen meldet, die Ausgabe in `{% set %}`-Variablen auslagern.

- [ ] **Step 4: Controller.** In `RoleController::index()` nach dem Laden von `$roles`:

```php
        $permissionGroups = RolePermissionCatalog::groupsForModules($this->moduleFlags());
```

und im Render-Array ergänzen:

```php
            'permission_groups' => $permissionGroups,
            'overview' => RoleOverview::build($roles, $permissionGroups),
```

(`use App\Services\RoleOverview;` ergänzen.)

- [ ] **Step 5: `templates/roles/index.twig` umbauen.** Im `<section class="dashboard-section …">` den Block von `<div class="surface-card card border-0 table-shell" data-table-engine="true" …>` bis zu seinem schließenden `</div>` (bisher Zeilen 52–441: Toolbar-Include, Tabelle, Aktionen) ersetzen durch:

```twig
            <div class="surface-card card border-0">
                <div class="card-body">
                    {{ include("roles/_overview.twig") }}
                </div>
            </div>
```

Den Untertitel ändern: `Rollen, Rechte und Hierarchiestufen im direkten Vergleich.` → `Rolle wählen, um ihre Rechte zu sehen. Ein Klick auf ein Recht zeigt, welche Rollen es haben.` und die Überschrift `Berechtigungsmatrix` → `Rollen und ihre Rechte`. Die Lösch-Modals, das Anlege- und das Bearbeiten-Modal bleiben unverändert. Im Block `scripts` ergänzen:

```twig
    {% block scripts %}
        <script src="{{ asset_path("/js/role-overview.js") }}"></script>
        <script src="/js/roles.js"></script>
    {% endblock scripts %}
```

- [ ] **Step 6: CSS.** In `public/css/style.css` die Regel `.roles-matrix-label { … }` ersetzen durch:

```css
.role-overview {
    container-type: inline-size;
}

.role-overview-inner {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1.25rem;
    align-items: start;
}

.role-overview-list {
    display: none;
}

.role-overview-who {
    display: none;
}

.role-overview-role {
    all: unset;
    box-sizing: border-box;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0.1rem 0.5rem;
    width: 100%;
    padding: 0.5rem 0.65rem;
    border: 1px solid transparent;
    border-radius: 0.5rem;
    cursor: pointer;
}

.role-overview-role:hover {
    background: var(--bs-tertiary-bg);
}

.role-overview-role:focus-visible,
.role-overview-permission:focus-visible,
.role-overview-chip:focus-visible {
    outline: 2px solid var(--bs-warning);
    outline-offset: 2px;
}

.role-overview-role[aria-pressed="true"] {
    background: var(--bs-warning-bg-subtle);
    border-color: var(--bs-warning);
}

.role-overview-role.is-lacking {
    opacity: 0.45;
}

.role-overview-role-name {
    font-weight: 600;
}

.role-overview-role-meta {
    grid-column: 1 / -1;
    font-size: 0.8rem;
    color: var(--bs-secondary-color);
}

.role-overview-share {
    grid-column: 1 / -1;
    height: 4px;
    border-radius: 2px;
    background: linear-gradient(var(--bs-success), var(--bs-success)) no-repeat left / var(--role-share, 0%) 100%,
        var(--bs-tertiary-bg);
}

.role-overview-search {
    margin-bottom: 0.75rem;
}

.role-overview-detail + .role-overview-detail {
    margin-top: 2rem;
}

.role-overview-detail-head {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem 1rem;
    align-items: flex-start;
    justify-content: space-between;
    padding-bottom: 0.65rem;
    border-bottom: 1px solid var(--bs-border-color);
}

.role-overview-groups {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1rem 1.5rem;
    margin-top: 0.85rem;
}

.role-overview-group-title {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--bs-secondary-color);
    margin-bottom: 0.25rem;
}

.role-overview-permission {
    all: unset;
    box-sizing: border-box;
    display: flex;
    gap: 0.5rem;
    align-items: center;
    width: 100%;
    padding: 0.2rem 0.3rem;
    border-radius: 0.375rem;
    cursor: pointer;
}

.role-overview-permission:hover,
.role-overview-permission[aria-expanded="true"] {
    background: var(--bs-warning-bg-subtle);
}

.role-overview-permission.is-denied {
    color: var(--bs-secondary-color);
}

.role-overview-permission-label {
    flex: 1;
}

.role-overview-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    margin: 0 5px;
    border-radius: 50%;
    background: var(--bs-border-color);
}

.role-overview-count {
    flex: none;
    font-size: 0.72rem;
    color: var(--bs-secondary-color);
    border: 1px solid var(--bs-border-color);
    border-radius: 999px;
    padding: 0 0.45rem;
}

.role-overview-holders {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    padding: 0.25rem 0.3rem 0.5rem 1.85rem;
}

.role-overview-chip {
    border: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
    color: var(--bs-body-color);
    border-radius: 999px;
    font-size: 0.8rem;
    padding: 0.05rem 0.6rem;
    cursor: pointer;
}

.role-overview-chip.is-current {
    background: var(--bs-warning);
    border-color: var(--bs-warning);
    color: var(--bs-dark);
    font-weight: 600;
}

@container (max-width: 719.98px) {
    .role-overview-select-label,
    .role-overview-select {
        display: block;
    }
}

@container (min-width: 720px) {
    .role-overview-inner {
        grid-template-columns: 260px minmax(0, 1fr);
    }

    .role-overview-list {
        display: grid;
        gap: 0.25rem;
    }

    .role-overview-who:not([hidden]) {
        display: block;
        padding: 0.35rem 0.65rem;
        border-radius: 0.5rem;
        background: var(--bs-warning-bg-subtle);
    }
}
```

Ohne JavaScript ist die Rollenliste nur auf breiten Bereichen sichtbar und ohne Wirkung, das Select nur auf schmalen. Das ist gewollt (Design, Punkt 6).

- [ ] **Step 7: Alte Matrix-Tests umstellen.** Die Prüfung „Matrixzeile existiert / hängt am Modul“ wandert auf den Katalog. In jedem der folgenden Tests den Regex-Block auf `roles-matrix-label` ersetzen:

  - `EventManagementPermissionFeatureTest::testRolesUiOffersEventPermission` (Z. 154–158),
  - `RoleManagementPermissionFeatureTest::testRolesUiOffersRolePermission` (Z. 141–145),
  - `RoleAssignOwnVoiceGroupProjectUiFeatureTest::testRolesTemplateShowsAssignOwnVoiceGroupProjectMatrixRow` (Methode umbenennen in `testCatalogListsAssignOwnVoiceGroupProjectPermission`),
  - `RoleOwnVoiceGroupUiFeatureTest` (Regex ab Z. 54, Methode analog umbenennen),

  jeweils durch (Schlüssel und Label anpassen):

```php
        $this->assertContains(
            ['key' => 'can_manage_events', 'label' => 'Termine verwalten'],
            array_merge(...array_column(RolePermissionCatalog::groupsForModules([]), 'permissions'))
        );
```

  Und die Modul-Tests `RoleFilesPermissionFeatureTest::testPermissionMatrixRowIsGatedByModuleFlag` und `RoleSheetArchiveModuleGateFeatureTest::testPermissionMatrixRowIsGatedByModuleFlag` durch:

```php
    public function testOverviewHidesPermissionWhileModuleIsOff(): void
    {
        $this->assertSame('files', RolePermissionCatalog::moduleGates()['can_manage_files'] ?? null);
    }
```

  (bzw. `'sheet_archive'` / `can_manage_sheet_archive`), jeweils mit `use App\Services\RolePermissionCatalog;`. Die Prüfungen auf Checkboxen in den Modals, auf `data-*`-Attribute und auf `roles.js` bleiben unverändert.

- [ ] **Step 8: TableUxFeatureTest.** In `testAllTableEngineContainersDeclareDefaultPageSize100`, `testAllTableEngineContainersIncludeViewToggle` und `testAllTableEngineContainersHaveDefaultSortKey` die Zeile `'templates/roles/index.twig',` streichen. Die Rollenseite ist keine Tabelle mehr.

- [ ] **Step 9:** `ddev php vendor/bin/phpunit --filter "RoleOverviewTemplateFeatureTest|RoleOverviewTest|RolePermissionCatalogTest|Role|EventManagementPermissionFeatureTest|TableUxFeatureTest|ActionButtonsConsistencyFeatureTest|NavigationPageTitlesFeatureTest"` → PASS. Danach `ddev composer twigcs` und `ddev composer phpcs` → ohne Befund.

- [ ] **Step 10: Commit**

```bash
git add templates/roles/_overview.twig templates/roles/index.twig src/Controllers/RoleController.php public/css/style.css tests/Feature
git commit -m "feat(roles): Rollenübersicht ersetzt die breite Berechtigungsmatrix"
```

### Task 4: Verhalten im Browser (`role-overview.js`)

**Files:**
- Create: `public/js/role-overview.js`
- Test: `tests/js/role-overview.test.mjs`

**Interfaces:**
- Consumes: DOM-Vertrag aus Task 3
- Produces (für `node --test`): `RoleOverviewLogic.normalizeQuery(string): string`, `RoleOverviewLogic.matchPermissions(list<{key,label}>, string): list<string>`, `RoleOverviewLogic.autoExpandKeys(list<string> matches, string query): list<string>`, `RoleOverviewLogic.resolveInitialRoleId(list<string> roleIds, string hash, ?string storedId): ?string`

- [ ] **Step 1: Failing test schreiben**

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const {
    normalizeQuery,
    matchPermissions,
    autoExpandKeys,
    resolveInitialRoleId,
} = require('../../public/js/role-overview.js');

const permissions = [
    { key: 'can_manage_budget', label: 'Budget verwalten' },
    { key: 'can_manage_own_voice_group', label: 'Eigene Stimmgruppe verwalten' },
    { key: 'can_assign_own_voice_group_to_project', label: 'Eigene Stimmgruppe ins Projekt zuweisen' },
    { key: 'can_manage_tasks', label: 'Projektplanung (Aufgaben)' },
    { key: 'can_manage_files', label: 'Dateiverwaltung verwalten' },
    { key: 'can_manage_users', label: 'Mitgliederverwaltung erlauben' },
];

test('normalizeQuery: Leerzeichen, Groß/klein und Umlaute spielen keine Rolle', () => {
    assert.equal(normalizeQuery('  Führ '), 'fuhr');
    assert.equal(normalizeQuery('STIMMGRUPPE'), 'stimmgruppe');
    assert.equal(normalizeQuery('   '), '');
});

test('leere Suche liefert alle Rechte in Originalreihenfolge', () => {
    assert.deepEqual(matchPermissions(permissions, '  '), permissions.map((p) => p.key));
});

test('Suche trifft Teilwörter im Label', () => {
    assert.deepEqual(matchPermissions(permissions, 'stimmgruppe'), [
        'can_manage_own_voice_group',
        'can_assign_own_voice_group_to_project',
    ]);
    assert.deepEqual(matchPermissions(permissions, 'verwalt'), [
        'can_manage_budget', 'can_manage_own_voice_group', 'can_manage_files',
    ]);
    assert.deepEqual(matchPermissions(permissions, 'gibtsnicht'), []);
});

test('höchstens drei Treffer klappen automatisch auf, leere Suche nie', () => {
    assert.deepEqual(autoExpandKeys(['a', 'b', 'c'], 'x'), ['a', 'b', 'c']);
    assert.deepEqual(autoExpandKeys(['a', 'b', 'c', 'd'], 'x'), []);
    assert.deepEqual(autoExpandKeys(['a'], '   '), []);
    assert.deepEqual(autoExpandKeys([], 'x'), []);
});

test('Startrolle: Hash vor gemerkter Rolle vor erster Rolle', () => {
    const ids = ['1', '5', '9'];
    assert.equal(resolveInitialRoleId(ids, '#role-9', '5'), '9');
    assert.equal(resolveInitialRoleId(ids, '', '5'), '5');
    assert.equal(resolveInitialRoleId(ids, '', null), '1');
});

test('Startrolle: veralteter Hash oder gemerkte gelöschte Rolle fallen auf die erste zurück', () => {
    const ids = ['1', '5'];
    assert.equal(resolveInitialRoleId(ids, '#role-42', '77'), '1');
    assert.equal(resolveInitialRoleId(ids, '#role-abc', null), '1');
    assert.equal(resolveInitialRoleId(ids, '#other', '5'), '5');
    assert.equal(resolveInitialRoleId([], '#role-1', '1'), null);
});
```

- [ ] **Step 2:** `npm run test:js` → FAIL („Cannot find module …/role-overview.js“).

- [ ] **Step 3: Implementierung** (`public/js/role-overview.js`)

```js
/**
 * Rollenübersicht unter /roles: Rolle wählen, Rechte durchsuchen und zu einem Recht
 * anzeigen, welche Rollen es haben. Die reine Logik oben ist ohne DOM unter
 * `node --test` prüfbar; der DOM-Teil läuft nur im Browser.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'roles.selectedRoleId';
    var AUTO_EXPAND_LIMIT = 3;

    function normalizeQuery(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '');
    }

    function matchPermissions(permissions, query) {
        var needle = normalizeQuery(query);
        return permissions
            .filter(function (permission) {
                return needle === '' || normalizeQuery(permission.label).indexOf(needle) !== -1;
            })
            .map(function (permission) {
                return permission.key;
            });
    }

    function autoExpandKeys(matches, query) {
        if (normalizeQuery(query) === '' || matches.length === 0 || matches.length > AUTO_EXPAND_LIMIT) {
            return [];
        }
        return matches.slice();
    }

    function resolveInitialRoleId(roleIds, hash, storedId) {
        var match = /^#role-(\d+)$/.exec(hash || '');
        if (match && roleIds.indexOf(match[1]) !== -1) {
            return match[1];
        }
        if (storedId && roleIds.indexOf(storedId) !== -1) {
            return storedId;
        }
        return roleIds.length > 0 ? roleIds[0] : null;
    }

    function readStoredRoleId() {
        try {
            return global.sessionStorage.getItem(STORAGE_KEY);
        } catch (error) {
            return null;
        }
    }

    function storeRoleId(roleId) {
        try {
            global.sessionStorage.setItem(STORAGE_KEY, roleId);
        } catch (error) {
            // Ohne Speicher startet die Seite beim nächsten Laden mit der ersten Rolle.
        }
    }

    function initRoleOverview(root) {
        var select = root.querySelector('[data-role-select]');
        var roleButtons = Array.prototype.slice.call(root.querySelectorAll('[data-role-id]'));
        var details = Array.prototype.slice.call(root.querySelectorAll('[data-role-detail]'));
        var search = root.querySelector('[data-permission-search]');
        var empty = root.querySelector('[data-permission-empty]');
        var who = root.querySelector('[data-role-who]');
        var whoLabel = root.querySelector('[data-role-who-label]');
        var source = root.querySelector('[data-permission-holders-source]');
        var roleIds = details.map(function (detail) { return detail.getAttribute('data-role-detail'); });
        var permissions = [];
        var state = { roleId: null, activeKey: null, expandedKeys: [] };

        if (details.length > 0) {
            Array.prototype.forEach.call(details[0].querySelectorAll('[data-permission-key]'), function (item) {
                permissions.push({ key: item.getAttribute('data-permission-key'), label: item.getAttribute('data-permission-label') });
            });
        }

        function holdersFor(key) {
            return source.querySelector('[data-permission-holders="' + key + '"]');
        }

        function labelFor(key) {
            for (var i = 0; i < permissions.length; i++) {
                if (permissions[i].key === key) {
                    return permissions[i].label;
                }
            }
            return '';
        }

        function renderHolders(detail) {
            Array.prototype.forEach.call(detail.querySelectorAll('[data-permission-key]'), function (item) {
                var key = item.getAttribute('data-permission-key');
                var open = key === state.activeKey || state.expandedKeys.indexOf(key) !== -1;
                var toggle = item.querySelector('[data-permission-toggle]');
                var slot = item.querySelector('[data-permission-holders-slot]');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                slot.replaceChildren();
                slot.hidden = !open;
                if (!open) {
                    return;
                }
                var copy = holdersFor(key).cloneNode(true);
                Array.prototype.forEach.call(copy.querySelectorAll('[data-role-jump]'), function (chip) {
                    chip.classList.toggle('is-current', chip.getAttribute('data-role-jump') === state.roleId);
                });
                while (copy.firstChild) {
                    slot.appendChild(copy.firstChild);
                }
            });
        }

        function renderRoleMarkers() {
            var holderIds = state.activeKey ? holdersFor(state.activeKey).getAttribute('data-role-ids').split(',') : null;
            who.hidden = holderIds === null;
            whoLabel.textContent = holderIds === null ? '' : labelFor(state.activeKey);
            roleButtons.forEach(function (button) {
                var id = button.getAttribute('data-role-id');
                var marker = button.querySelector('[data-role-marker]');
                var has = holderIds !== null && holderIds.indexOf(id) !== -1;
                button.setAttribute('aria-pressed', id === state.roleId ? 'true' : 'false');
                button.classList.toggle('is-lacking', holderIds !== null && !has);
                marker.className = 'role-overview-role-marker' + (has ? ' bi bi-check-circle-fill text-success' : '');
            });
        }

        function render() {
            details.forEach(function (detail) {
                var visible = detail.getAttribute('data-role-detail') === state.roleId;
                detail.hidden = !visible;
                if (visible) {
                    renderHolders(detail);
                }
            });
            select.value = state.roleId;
            renderRoleMarkers();
        }

        function selectRole(roleId) {
            if (roleIds.indexOf(roleId) === -1) {
                return;
            }
            state.roleId = roleId;
            storeRoleId(roleId);
            if (global.history && global.history.replaceState) {
                global.history.replaceState(null, '', '#role-' + roleId);
            }
            render();
        }

        function applySearch() {
            var matches = matchPermissions(permissions, search.value);
            details.forEach(function (detail) {
                Array.prototype.forEach.call(detail.querySelectorAll('[data-permission-group]'), function (group) {
                    var anyVisible = false;
                    Array.prototype.forEach.call(group.querySelectorAll('[data-permission-key]'), function (item) {
                        var visible = matches.indexOf(item.getAttribute('data-permission-key')) !== -1;
                        item.hidden = !visible;
                        anyVisible = anyVisible || visible;
                    });
                    group.hidden = !anyVisible;
                });
            });
            empty.hidden = matches.length > 0;
            state.expandedKeys = autoExpandKeys(matches, search.value);
            if (state.expandedKeys.length > 0) {
                state.activeKey = null;
            }
            render();
        }

        roleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                selectRole(button.getAttribute('data-role-id'));
            });
        });

        select.addEventListener('change', function () {
            selectRole(select.value);
        });

        search.addEventListener('input', applySearch);

        root.addEventListener('click', function (event) {
            var jump = event.target.closest('[data-role-jump]');
            if (jump && root.contains(jump)) {
                selectRole(jump.getAttribute('data-role-jump'));
                return;
            }
            var toggle = event.target.closest('[data-permission-toggle]');
            if (toggle && root.contains(toggle)) {
                var key = toggle.closest('[data-permission-key]').getAttribute('data-permission-key');
                state.activeKey = state.activeKey === key ? null : key;
                state.expandedKeys = [];
                render();
            }
        });

        state.roleId = resolveInitialRoleId(roleIds, global.location ? global.location.hash : '', readStoredRoleId());
        if (state.roleId !== null) {
            render();
        }
    }

    var api = {
        normalizeQuery: normalizeQuery,
        matchPermissions: matchPermissions,
        autoExpandKeys: autoExpandKeys,
        resolveInitialRoleId: resolveInitialRoleId,
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        global.RoleOverviewLogic = api;
        global.document.addEventListener('DOMContentLoaded', function () {
            Array.prototype.forEach.call(global.document.querySelectorAll('[data-role-overview]'), initRoleOverview);
        });
    }
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 4:** `npm run test:js` → PASS (inkl. der bestehenden Tests).

- [ ] **Step 5: Commit**

```bash
git add public/js/role-overview.js tests/js/role-overview.test.mjs
git commit -m "feat(roles): Rollenwahl, Rechtesuche und „Wer hat dieses Recht?“ im Browser"
```

### Task 5: Abschluss

- [ ] **Step 1:** Volle Suite einmal: `ddev composer test:parallel` → grün. Dazu `npm run test:js`, `ddev composer phpcs` und `ddev composer twigcs`, alle ohne Befund.
- [ ] **Step 2:** Prüfen, dass nichts mehr auf die alte Matrix verweist: `grep -rn "roles-matrix\|rolesTable\|roles.index" src templates public tests` → keine Treffer.
- [ ] **Step 3:** Hilfe prüfen: `grep -rln "Berechtigungsmatrix" docs/*.md`. Bei Treffern nicht selbst umschreiben, sondern im Bericht nennen (Hilfethemen nur auf Anforderung).
- [ ] **Step 4:** Optional, nur auf ausdrücklichen Wunsch: ein e2e-Szenario über `/e2e-scenario` (Rolle wählen, Recht aufklappen, Chip-Sprung, Suche, mobile Breite).
- [ ] **Step 5:** `/git-commit`: Squash auf einen Commit, Rebase auf `main`, Fast-Forward-Merge, Branch löschen. Erst danach einmal fragen: „Nach `origin/main` pushen?“

# Seitenleiste mit Schnellsuche – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Navbar-Dropdowns werden durch eine Seitenleiste mit Aufgaben-Abschnitten ersetzt (mobil als Offcanvas), die Einträge bekommen eindeutige Namen, und eine Schnellsuche (Strg+K) findet jede für die Rolle sichtbare Seite.

**Architecture:** `App\Navigation\NavigationBuilder` bleibt einzige Quelle und liefert statt Gruppen eine flache Liste aus Start-Link, Abschnitten und Hilfe-Link, jeder Eintrag mit Stichwörtern. `templates/partials/navigation/sidebar.twig` rendert sie als `offcanvas-lg`; Einklappen und gemerkte Zustände laufen über `localStorage` in zwei kleinen Skripten. Die Schnellsuche liest die gerenderten Leistenlinks (`data-nav-*`) und rankt sie mit einer reinen Funktion, die unter `node --test` geprüft wird.

**Tech Stack:** PHP 8 / Slim / Twig, Bootstrap 5.3.8 (`offcanvas-lg`, Modal), Bootstrap Icons, Vanilla-JS im Stil von `public/js/mail-badge.js` (`var`, `function`), PHPUnit, Node 25 (`node --test`), Playwright.

**Spec:** `docs/superpowers/specs/2026-10-02-sidebar-navigation-design.md`

## Global Constraints

- URLs, Route-Namen und `navKey`s bleiben unverändert; keine neuen oder geänderten Rechte.
- Sichtbarkeitsprädikate der Einträge bleiben wortgleich (mehrere Tests lesen den Builder-Quelltext, siehe Task 1).
- Bezeichner englisch, Inhalte deutsch mit echten Umlauten (`instructions/naming.md`).
- Kein Inline-JavaScript, kein Inline-CSS in Templates; nur lokal ausgelieferte Assets (CSP `script-src 'self'`).
- Twig: doppelte Anführungszeichen, `twigcs`-konform; PHP: PSR-12, Zeilenlänge 130.
- Alle neuen und geänderten Textdateien mit LF.
- Zustand nur im `localStorage`, jeder Zugriff in `try/catch`; Schlüssel: `chormanager.nav.collapsed`, `chormanager.nav.open.administration`, `chormanager.nav.recent`.
- Breakpoint: Bootstrap `lg` (992 px).
- In `help/*/docs/*.md` keine Rollennamen nennen.

## Review Focus

1. **Strg+K bei offenem anderen Modal** (z. B. Newsletter-Editor): die Suche darf sich nicht über ein offenes Modal legen – das Kürzel wird dann ignoriert. Test: Task 4, e2e-Schritt „Strg+K bei offenem Modal".
2. **Fenster wird bei offenem Offcanvas über 992 px vergrößert**: Bootstrap räumt `offcanvas-lg` nur auf, solange das Element nicht `position: fixed` ist – unsere Desktop-Regel setzt genau das. Erwartung: kein hängender Backdrop. Test: Task 5, Mobil-Schritt „Resize auf Desktop".
3. **`localStorage` gesperrt oder mit Unsinn befüllt** (z. B. `chormanager.nav.recent = "{"`): Leiste und Suche funktionieren im Standardzustand. Test: Task 4 (`rankNavigationEntries` bekommt nur saubere Daten; `readRecentUrls` in Task 4 wird mit kaputtem JSON geprüft).
4. **Verlorenes Recht und „Zuletzt besucht"**: ein gemerkter Pfad, der nicht mehr in der Leiste steht, wird nicht angezeigt. Test: Task 4 (`resolveRecentEntries`).
5. **Eingeklappte Leiste und aktive Seite in Administration**: in der Symbolleiste gibt es keine Abschnittsüberschrift zum Aufklappen – die Administration-Einträge müssen dort immer sichtbar sein. Test: Task 2 Render-Test prüft Markup; e2e Task 5 prüft Sichtbarkeit von `/backups` im eingeklappten Zustand.

---

## File Structure

| Datei | Verantwortung |
|---|---|
| `src/Navigation/NavigationBuilder.php` (ändern) | Definition und Aufbau: Start, Abschnitte, Hilfe; Stichwörter |
| `templates/partials/navigation/sidebar.twig` (neu) | Markup der Leiste, ersetzt `menu.twig` |
| `templates/partials/navigation/menu.twig` (löschen) | – |
| `templates/partials/navigation/search_modal.twig` (neu) | Markup des Such-Modals |
| `templates/layout.twig` (ändern) | Kopfleiste ohne Menü, Leiste, Modal, Skripte |
| `public/css/style.css` (ändern) | Abschnitt „Seitenleiste" und „Schnellsuche", alte Navbar-Collapse-Regeln raus |
| `public/js/navigation-state.js` (neu) | im `<head>`: gemerkte Klassen am `<html>` setzen |
| `public/js/navigation.js` (neu) | Einklappen, Abschnitt öffnen, Offcanvas, „Zuletzt besucht" merken |
| `public/js/navigation-search-rank.js` (neu) | reine Funktionen: Normalisieren, Ranking, „Zuletzt besucht" auflösen |
| `public/js/navigation-search.js` (neu) | Modal, Tastatur, Rendering der Treffer |
| `tests/js/navigation-search-rank.test.mjs` (neu) | `node --test` für die reinen Funktionen |
| `package.json` (ändern) | Skript `test:js` |
| `tests/Feature/NavigationBuilderFeatureTest.php` (neu schreiben) | Struktur, Sichtbarkeit, Stichwörter |
| `tests/Feature/NavigationMenuRenderFeatureTest.php` (neu schreiben) | Markup der Leiste |
| `tests/Feature/NavigationLayoutSeamFeatureTest.php` (erweitern) | Leiste, Modal, Skripte im echten Layout |
| `tests/Feature/RoleAccessConsistencyFeatureTest.php`, `tests/Feature/NewsletterFeatureTest.php` (anpassen) | neue Labels/Titel |
| 10 Seiten-Templates (ändern, Task 3) | Seitentitel und Überschrift |
| `help/*/docs/*.md` (ändern, Task 6) | Klickpfade |
| `tests/e2e/steps/navigation.mjs` (ändern), `tests/e2e/scenarios/navigation-sidebar.e2e.test.mjs` (neu) | Browser-Abdeckung |

---

### Task 1: NavigationBuilder liefert Abschnitte, neue Namen und Stichwörter

**Files:**
- Modify: `src/Navigation/NavigationBuilder.php` (komplett ersetzen)
- Test: `tests/Feature/NavigationBuilderFeatureTest.php` (komplett ersetzen)
- Modify: `tests/Feature/RoleAccessConsistencyFeatureTest.php:73-76`

**Interfaces:**
- Produces: `NavigationBuilder::build(NavigationContext $ctx): array` liefert eine Liste von Knoten:
  - Link: `['type' => 'link', 'label' => string, 'url' => string, 'icon' => string, 'keywords' => list<string>, 'active' => bool, 'position' => 'top'|'bottom']`
  - Abschnitt: `['type' => 'section', 'key' => string, 'label' => string, 'foldable' => bool, 'active' => bool, 'items' => list<Item>]`
  - Item: `['label' => string, 'url' => string, 'icon' => string, 'keywords' => list<string>, 'active' => bool]`
  - Abschnitt-Keys in dieser Reihenfolge: `events`, `material`, `people`, `finance`, `communication`, `administration`. Nur `administration` ist `foldable`.
- Bestehende Konsumenten (`EventManagementPermissionFeatureTest`, `RoleManagementPermissionFeatureTest`) iterieren „`type === 'link'` → `url`, sonst `items`" und bleiben damit gültig.

- [ ] **Step 1: Test komplett ersetzen**

`tests/Feature/NavigationBuilderFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use PHPUnit\Framework\TestCase;

class NavigationBuilderFeatureTest extends TestCase
{
    private const ADMIN_PERMISSIONS = [
        'can_manage_users' => true,
        'can_manage_roles' => true,
        'can_manage_master_data' => true,
        'can_manage_mail_queue' => true,
        'can_manage_backups' => true,
        'can_manage_events' => true,
        'can_manage_song_library' => true,
        'can_manage_newsletters' => true,
        'can_manage_finances' => true,
        'can_manage_sponsoring' => true,
        'can_manage_attendance_all' => true,
    ];

    private const ALL_MODULES = [
        'registration' => true,
        'finance' => true,
        'budget' => true,
        'sponsoring' => true,
        'newsletter' => true,
        'files' => true,
    ];

    /**
     * @param array<string,bool> $permissions
     * @param array<string,bool> $modules
     * @return array<int,array<string,mixed>>
     */
    private function build(array $permissions, array $modules = [], string $path = '/dashboard'): array
    {
        $ctx = new NavigationContext($permissions, $modules, $path);
        return (new NavigationBuilder())->build($ctx);
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     */
    private function section(array $tree, string $key): ?array
    {
        foreach ($tree as $node) {
            if ($node['type'] === 'section' && $node['key'] === $key) {
                return $node;
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     * @return list<string>
     */
    private function sectionKeys(array $tree): array
    {
        $keys = [];
        foreach ($tree as $node) {
            if ($node['type'] === 'section') {
                $keys[] = $node['key'];
            }
        }
        return $keys;
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private function entries(array $tree): array
    {
        $entries = [];
        foreach ($tree as $node) {
            if ($node['type'] === 'link') {
                $entries[] = $node;
            } else {
                foreach ($node['items'] as $item) {
                    $entries[] = $item;
                }
            }
        }
        return $entries;
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     * @return list<string>
     */
    private function urls(array $tree): array
    {
        return array_column($this->entries($tree), 'url');
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     */
    private function entry(array $tree, string $url): ?array
    {
        foreach ($this->entries($tree) as $entry) {
            if ($entry['url'] === $url) {
                return $entry;
            }
        }
        return null;
    }

    public function testSectionsAppearInTaskOrderBetweenStartAndHelp(): void
    {
        $tree = $this->build(self::ADMIN_PERMISSIONS, self::ALL_MODULES);

        $first = $tree[0];
        $last = $tree[array_key_last($tree)];
        $this->assertSame(['link', '/dashboard', 'top'], [$first['type'], $first['url'], $first['position']]);
        $this->assertSame(['link', '/help', 'bottom'], [$last['type'], $last['url'], $last['position']]);

        $this->assertSame(
            ['events', 'material', 'people', 'finance', 'communication', 'administration'],
            $this->sectionKeys($tree)
        );
        $titles = [];
        foreach ($tree as $node) {
            if ($node['type'] === 'section') {
                $titles[$node['key']] = $node['label'];
            }
        }
        $this->assertSame([
            'events' => 'Termine',
            'material' => 'Noten & Dateien',
            'people' => 'Mitglieder & Projekte',
            'finance' => 'Finanzen',
            'communication' => 'Kommunikation',
            'administration' => 'Administration',
        ], $titles);
    }

    public function testEntriesCarryTheNewNamesInTheirSections(): void
    {
        $tree = $this->build(self::ADMIN_PERMISSIONS, self::ALL_MODULES);

        $expected = [
            'events' => [
                '/events' => 'Termine',
                '/registrations' => 'Anmeldungen',
                '/attendance' => 'Anwesenheit erfassen',
                '/evaluations' => 'Anwesenheitsquoten',
                '/evaluations/registrations' => 'Anmelde-Auswertung',
            ],
            'material' => [
                '/downloads' => 'Probenmaterial',
                '/files' => 'Dateien',
                '/song-library' => 'Repertoire',
            ],
            'people' => [
                '/users' => 'Mitglieder',
                '/projects' => 'Projekte',
                '/evaluations/project-members' => 'Projektübersicht',
            ],
            'finance' => [
                '/finances' => 'Kassa',
                '/budget' => 'Budget',
                '/sponsoring' => 'Sponsoring',
            ],
            'communication' => [
                '/newsletters' => 'Newsletter versenden',
                '/newsletters/archive' => 'Newsletter-Archiv',
            ],
            'administration' => [
                '/roles' => 'Rollen & Rechte',
                '/voice-groups' => 'Stimmgruppen',
                '/event-types' => 'Termin-Typen',
                '/settings' => 'App-Einstellungen',
                '/admin/mail-queue' => 'Mailversand',
                '/backups' => 'Backups',
            ],
        ];

        foreach ($expected as $key => $labelsByUrl) {
            $section = $this->section($tree, $key);
            $this->assertNotNull($section, "Abschnitt {$key} fehlt.");
            $this->assertSame(
                $labelsByUrl,
                array_combine(array_column($section['items'], 'url'), array_column($section['items'], 'label')),
                "Einträge im Abschnitt {$key} stimmen nicht."
            );
        }
        $this->assertSame('Start', $tree[0]['label']);
    }

    /**
     * "Projektbesetzung" ist die Seite, auf der eine Stimmgruppe ihre Leute einem Projekt
     * zuteilt. Wer Stammdaten verwaltet, arbeitet stattdessen über "Projekte" - der
     * Eintrag bleibt für diese Personen ausgeblendet, wie bisher "Meine Projekte".
     */
    public function testProjectStaffingIsForAssignersWithoutMasterData(): void
    {
        $assigner = $this->build(['can_assign_own_voice_group_to_project' => true]);
        $staffing = $this->entry($assigner, '/projects/members');
        $this->assertNotNull($staffing);
        $this->assertSame('Projektbesetzung', $staffing['label']);
        $this->assertContains('/projects/members', array_column($this->section($assigner, 'people')['items'], 'url'));

        $masterData = $this->urls($this->build([
            'can_manage_project_members' => true,
            'can_manage_master_data' => true,
        ]));
        $this->assertNotContains('/projects/members', $masterData);
        $this->assertContains('/projects', $masterData);
    }

    public function testPlainMemberSeesOnlyPublicSections(): void
    {
        $tree = $this->build([], ['registration' => false]);

        $this->assertSame(['events', 'material', 'people'], $this->sectionKeys($tree));
        $this->assertSame(['/events', '/evaluations'], array_column($this->section($tree, 'events')['items'], 'url'));
        $this->assertSame(['/downloads'], array_column($this->section($tree, 'material')['items'], 'url'));
        $this->assertSame(
            ['/evaluations/project-members'],
            array_column($this->section($tree, 'people')['items'], 'url')
        );
        $this->assertNull($this->section($tree, 'administration'), 'Leere Abschnitte erscheinen nicht.');
        $this->assertNull($this->section($tree, 'finance'));
    }

    public function testFilesModuleMovesFilesIntoMaterialForEveryone(): void
    {
        $tree = $this->build([], ['files' => true]);

        $this->assertContains('/files', array_column($this->section($tree, 'material')['items'], 'url'));
        $this->assertNull($this->section($tree, 'administration'));
    }

    public function testRegistrationModuleTogglesRegistrationLinks(): void
    {
        $on = $this->urls($this->build([], ['registration' => true]));
        $this->assertContains('/registrations', $on);
        $this->assertContains('/evaluations/registrations', $on);

        $off = $this->urls($this->build([], ['registration' => false]));
        $this->assertNotContains('/registrations', $off);
        $this->assertNotContains('/evaluations/registrations', $off);
    }

    public function testVoiceRepSeesScopedItems(): void
    {
        $urls = $this->urls($this->build([
            'can_manage_own_voice_group' => true,
        ]));

        $this->assertContains('/users', $urls);
        $this->assertContains('/evaluations', $urls);
        // Seit dem Wegfall von can_manage_attendance (Migration 20260902120000) öffnet
        // can_manage_own_voice_group die Anwesenheitsliste selbst - der Link gehört dazu.
        $this->assertContains('/attendance', $urls);
    }

    /**
     * Hält die Invariante zwischen Navigation und Route für '/attendance' fest: Die
     * Bedingung im Menü muss genau dem Gate requiresAttendanceManagement in
     * RoleMiddleware entsprechen - can_manage_own_voice_group oder
     * can_manage_attendance_all. Laufen die beiden auseinander, sieht jemand den
     * Eintrag und bekommt beim Klick einen 403.
     */
    public function testAttendanceLinkMatchesTheRouteGate(): void
    {
        $this->assertContains('/attendance', $this->urls($this->build(['can_manage_own_voice_group' => true])));
        $this->assertContains('/attendance', $this->urls($this->build(['can_manage_attendance_all' => true])));
        $this->assertNotContains('/attendance', $this->urls($this->build(['can_manage_events' => true])));
    }

    public function testBackupOnlyRoleSeesAdministrationWithBackupItem(): void
    {
        $tree = $this->build(['can_manage_backups' => true]);
        $administration = $this->section($tree, 'administration');

        $this->assertNotNull($administration, 'Administration muss für das Backup-Recht erscheinen.');
        $this->assertSame(['/backups'], array_column($administration['items'], 'url'));
    }

    public function testAdminSeesFullStructure(): void
    {
        $urls = $this->urls($this->build(self::ADMIN_PERMISSIONS, self::ALL_MODULES));

        foreach (['/users', '/roles', '/voice-groups', '/settings', '/admin/mail-queue', '/backups', '/files'] as $u) {
            $this->assertContains($u, $urls, "Admin muss {$u} sehen.");
        }
    }

    public function testOnlyAdministrationIsFoldable(): void
    {
        $tree = $this->build(self::ADMIN_PERMISSIONS, self::ALL_MODULES);

        foreach ($tree as $node) {
            if ($node['type'] === 'section') {
                $this->assertSame($node['key'] === 'administration', $node['foldable'], "foldable bei {$node['key']}");
            }
        }
    }

    public function testActiveStatePropagatesToSection(): void
    {
        $tree = $this->build(['can_manage_users' => true], ['registration' => true], '/registrations');

        $events = $this->section($tree, 'events');
        $this->assertTrue($events['active'], 'Abschnitt Termine muss bei /registrations aktiv sein.');
        $this->assertTrue($this->entry($tree, '/registrations')['active']);
        $this->assertFalse($this->entry($tree, '/evaluations/registrations')['active']);
        $this->assertFalse($this->section($tree, 'people')['active']);
    }

    public function testEveryEntryCarriesUsableKeywords(): void
    {
        $tree = $this->build(self::ADMIN_PERMISSIONS + ['can_assign_own_voice_group_to_project' => true], self::ALL_MODULES);

        foreach ($this->entries($tree) as $entry) {
            $this->assertNotEmpty($entry['keywords'], "Keine Stichwörter bei {$entry['url']}.");
            foreach ($entry['keywords'] as $keyword) {
                $this->assertMatchesRegularExpression(
                    '/^[a-zäöüß0-9][a-zäöüß0-9 -]*$/u',
                    $keyword,
                    "Stichwort '{$keyword}' bei {$entry['url']} muss klein geschrieben sein und darf kein '|' enthalten."
                );
            }
        }
    }

    public function testKeywordsCoverEverydaySynonyms(): void
    {
        $tree = $this->build(self::ADMIN_PERMISSIONS, self::ALL_MODULES);

        $this->assertContains('kassabuch', $this->entry($tree, '/finances')['keywords']);
        $this->assertContains('sopran', $this->entry($tree, '/voice-groups')['keywords']);
        $this->assertContains('noten', $this->entry($tree, '/downloads')['keywords']);
    }

    public function testFinanceReaderSeesFinancesAndBudgetWhenModulesEnabled(): void
    {
        $urls = $this->urls($this->build(['can_read_finances' => true], ['finance' => true, 'budget' => true]));

        $this->assertContains('/finances', $urls);
        $this->assertContains('/budget', $urls);
    }

    public function testFinanceReaderDoesNotSeeFinancesOrBudgetWhenModulesDisabled(): void
    {
        $urls = $this->urls($this->build(['can_read_finances' => true], ['finance' => false, 'budget' => false]));

        $this->assertNotContains('/finances', $urls);
        $this->assertNotContains('/budget', $urls);
    }

    public function testFinanceManagerSeesFinancesWithoutReadPermission(): void
    {
        $this->assertContains('/finances', $this->urls($this->build(['can_manage_finances' => true], ['finance' => true])));
    }

    public function testUserManagerDoesNotSeeFinancesWithoutFinancePermissions(): void
    {
        $this->assertNotContains('/finances', $this->urls($this->build(['can_manage_users' => true], ['finance' => true])));
    }

    /**
     * Controller wie DownloadController setzen active_nav='downloads', damit der Eintrag
     * auch auf Seiten leuchtet, deren Pfad nicht mit '/downloads' beginnt.
     */
    public function testActiveNavKeyHighlightsProbenmaterialRegardlessOfCurrentPath(): void
    {
        $tree = (new NavigationBuilder())->build(new NavigationContext([], [], '/dashboard', 'downloads'));

        $this->assertTrue($this->entry($tree, '/downloads')['active']);
        $this->assertTrue($this->section($tree, 'material')['active']);
        $this->assertFalse($tree[0]['active'], 'Start darf nicht zusätzlich aktiv sein.');
    }

    public function testFromSessionBuildsExpectedMenuFromSessionArrayAndSettings(): void
    {
        $session = [
            'user_id' => 42,
            'can_manage_users' => true,
            'can_manage_backups' => true,
            'can_manage_finances' => true,
        ];
        $settings = ['modules' => ['finance' => true, 'newsletter' => true]];

        $tree = (new NavigationBuilder())->build(NavigationContext::fromSession($session, $settings, '/backups'));
        $urls = $this->urls($tree);

        $this->assertContains('/users', $urls);
        $this->assertContains('/backups', $urls);
        $this->assertContains('/finances', $urls);
        $this->assertTrue($this->section($tree, 'administration')['active'], 'Administration muss bei /backups aktiv sein.');
    }

    /**
     * fromSession() muss jedes "can_"-Flag übernehmen statt einer gepflegten Liste - sonst
     * läse ein neues Prädikat im Builder still false.
     */
    public function testFromSessionCopiesAnyCanPrefixedFlagWithoutAnAllowlist(): void
    {
        $context = NavigationContext::fromSession(
            ['can_manage_totally_new_capability' => true, 'user_id' => 7],
            [],
            '/dashboard'
        );

        $this->assertTrue($context->can('can_manage_totally_new_capability'));
        $this->assertFalse($context->can('user_id'));
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss rot sein**

Run: `ddev php vendor/bin/phpunit --filter NavigationBuilderFeatureTest 2>&1 | tail -15`
Expected: FAIL (z. B. `Undefined array key "position"` bzw. Abschnitt-Assertions).

- [ ] **Step 3: Builder komplett ersetzen**

`src/Navigation/NavigationBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Navigation;

/**
 * Builds the sidebar navigation as a flat list of visible nodes: the start link,
 * the task sections and the help link.
 * Section visibility is derived automatically: a section appears iff at least one
 * of its entries is visible. Active state is precomputed from the context path /
 * nav key. Twig renders the resulting list without any logic.
 */
final class NavigationBuilder
{
    /**
     * Abschnitte, die sich in der Leiste zuklappen lassen. Nur die selten gebrauchten
     * Verwaltungsseiten - alles, was im Chor-Alltag gebraucht wird, bleibt immer sichtbar.
     */
    private const FOLDABLE_SECTIONS = ['administration'];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function build(NavigationContext $ctx): array
    {
        $definition = $this->definition();
        $navKeyKnown = $this->isKnownNavKey($definition, $ctx->navKey);
        $nodes = [];

        foreach ($definition['top'] as $entry) {
            if (($entry['visible'])($ctx)) {
                $nodes[] = $this->link($entry, $ctx, $navKeyKnown, 'top');
            }
        }

        foreach ($definition['sections'] as $section) {
            $items = [];
            foreach ($section['entries'] as $entry) {
                if (($entry['visible'])($ctx)) {
                    $items[] = $this->item($entry, $ctx, $navKeyKnown);
                }
            }

            if ($items === []) {
                continue;
            }

            $nodes[] = [
                'type' => 'section',
                'key' => $section['key'],
                'label' => $section['title'],
                'foldable' => in_array($section['key'], self::FOLDABLE_SECTIONS, true),
                'active' => in_array(true, array_column($items, 'active'), true),
                'items' => $items,
            ];
        }

        foreach ($definition['bottom'] as $entry) {
            if (($entry['visible'])($ctx)) {
                $nodes[] = $this->link($entry, $ctx, $navKeyKnown, 'bottom');
            }
        }

        return $nodes;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function item(array $entry, NavigationContext $ctx, bool $navKeyKnown): array
    {
        return [
            'label' => $entry['label'],
            'url' => $entry['url'],
            'icon' => $entry['icon'],
            'keywords' => $entry['keywords'],
            'active' => $this->matchesActive($entry, $ctx, $navKeyKnown),
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function link(array $entry, NavigationContext $ctx, bool $navKeyKnown, string $position): array
    {
        return ['type' => 'link'] + $this->item($entry, $ctx, $navKeyKnown) + ['position' => $position];
    }

    /**
     * @param array{top: list<array<string,mixed>>, sections: list<array<string,mixed>>, bottom: list<array<string,mixed>>} $definition
     */
    private function isKnownNavKey(array $definition, string $navKey): bool
    {
        if ($navKey === '') {
            return false;
        }

        $entries = array_merge($definition['top'], $definition['bottom']);
        foreach ($definition['sections'] as $section) {
            $entries = array_merge($entries, $section['entries']);
        }

        foreach ($entries as $entry) {
            if (in_array($navKey, $entry['navKeys'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function matchesActive(array $node, NavigationContext $ctx, bool $navKeyKnown): bool
    {
        foreach (($node['excl'] ?? []) as $exclude) {
            if ($exclude !== '' && str_starts_with($ctx->path, $exclude)) {
                return false;
            }
        }

        // Ein bekannter navKey entscheidet allein. Sonst leuchteten in der Leiste zwei
        // Einträge zugleich - etwa "Start" über den Pfad und "Probenmaterial" über den
        // navKey, wenn eine Download-Route unter /dashboard liegt.
        if ($navKeyKnown) {
            return in_array($ctx->navKey, $node['navKeys'] ?? [], true);
        }

        foreach (($node['prefixes'] ?? []) as $prefix) {
            if ($prefix === '/') {
                if ($ctx->path === '/') {
                    return true;
                }
                continue;
            }
            if ($prefix !== '' && str_starts_with($ctx->path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die einzige deklarative Menüdefinition. Jeder Eintrag trägt Label, Icon, URL,
     * Stichwörter für die Schnellsuche, ein Sichtbarkeitsprädikat und die Angaben für
     * die Aktiv-Markierung. Abschnitte heißen bewusst 'title' statt 'label': mehrere
     * Tests schneiden einzelne Einträge an "'label' =>" aus diesem Quelltext aus.
     *
     * @return array{top: list<array<string,mixed>>, sections: list<array<string,mixed>>, bottom: list<array<string,mixed>>}
     */
    private function definition(): array
    {
        $always = static fn(NavigationContext $c): bool => true;

        return [
            'top' => [
                [
                    'label' => 'Start',
                    'url' => '/dashboard',
                    'icon' => 'bi-house',
                    'keywords' => ['dashboard', 'übersicht', 'startseite'],
                    'prefixes' => ['/', '/dashboard'],
                    'navKeys' => ['dashboard'],
                    'visible' => $always,
                ],
            ],
            'sections' => [
                [
                    'key' => 'events',
                    'title' => 'Termine',
                    'entries' => [
                        [
                            'label' => 'Termine',
                            'url' => '/events',
                            'icon' => 'bi-calendar-event',
                            'keywords' => ['proben', 'konzerte', 'auftritte', 'kalender'],
                            'prefixes' => ['/events'],
                            'navKeys' => ['events'],
                            'visible' => $always,
                        ],
                        [
                            'label' => 'Anmeldungen',
                            'url' => '/registrations',
                            'icon' => 'bi-calendar-check',
                            'keywords' => ['zusage', 'absage', 'teilnahme'],
                            'prefixes' => ['/registrations'],
                            'navKeys' => ['registrations'],
                            'visible' => static fn(NavigationContext $c): bool => $c->module('registration'),
                        ],
                        [
                            'label' => 'Anwesenheit erfassen',
                            'url' => '/attendance',
                            'icon' => 'bi-person-check',
                            'keywords' => ['anwesenheit', 'präsenz', 'abwesend', 'fehlen'],
                            'prefixes' => ['/attendance'],
                            'navKeys' => ['attendance'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_own_voice_group') || $c->can('can_manage_attendance_all'),
                        ],
                        [
                            'label' => 'Anwesenheitsquoten',
                            'url' => '/evaluations',
                            'icon' => 'bi-bar-chart-line-fill',
                            'keywords' => ['statistik', 'auswertung', 'quote'],
                            'prefixes' => ['/evaluations'],
                            'navKeys' => ['evaluations'],
                            'excl' => ['/evaluations/project-members', '/evaluations/registrations'],
                            // Auswertungen sind bewusst für alle angemeldeten Mitglieder offen -
                            // das Menü bildet genau das ab, was die Route zulässt.
                            'visible' => $always,
                        ],
                        [
                            'label' => 'Anmelde-Auswertung',
                            'url' => '/evaluations/registrations',
                            'icon' => 'bi-clipboard-data',
                            'keywords' => ['statistik', 'auswertung', 'anmeldungen'],
                            'prefixes' => ['/evaluations/registrations'],
                            'navKeys' => ['evaluations_registrations'],
                            'visible' => static fn(NavigationContext $c): bool => $c->module('registration'),
                        ],
                    ],
                ],
                [
                    'key' => 'material',
                    'title' => 'Noten & Dateien',
                    'entries' => [
                        [
                            'label' => 'Probenmaterial',
                            'url' => '/downloads',
                            'icon' => 'bi-download',
                            'keywords' => ['noten', 'downloads', 'übedateien', 'mp3', 'pdf'],
                            'prefixes' => ['/downloads'],
                            'navKeys' => ['downloads'],
                            'visible' => $always,
                        ],
                        [
                            'label' => 'Dateien',
                            'url' => '/files',
                            'icon' => 'bi-folder2-open',
                            'keywords' => ['teamordner', 'dokumente', 'freigabe', 'papierkorb'],
                            'prefixes' => ['/files'],
                            'navKeys' => ['files'],
                            'visible' => static fn(NavigationContext $c): bool => $c->module('files'),
                        ],
                        [
                            'label' => 'Repertoire',
                            'url' => '/song-library',
                            'icon' => 'bi-music-note-list',
                            'keywords' => ['lieder', 'stücke', 'songs', 'notenarchiv'],
                            'prefixes' => ['/song-library'],
                            'navKeys' => ['song_library'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_song_library'),
                        ],
                    ],
                ],
                [
                    'key' => 'people',
                    'title' => 'Mitglieder & Projekte',
                    'entries' => [
                        [
                            'label' => 'Mitglieder',
                            'url' => '/users',
                            'icon' => 'bi-people-fill',
                            'keywords' => ['mitgliederverwaltung', 'sänger', 'personen', 'adressen', 'kontakte'],
                            'prefixes' => ['/users'],
                            'navKeys' => ['users'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_users') || $c->can('can_manage_own_voice_group'),
                        ],
                        [
                            'label' => 'Projekte',
                            'url' => '/projects',
                            'icon' => 'bi-folder-fill',
                            'keywords' => ['konzertprojekt', 'planung', 'projekt anlegen'],
                            'prefixes' => ['/projects'],
                            'navKeys' => ['projects'],
                            'excl' => ['/projects/members'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_master_data'),
                        ],
                        [
                            'label' => 'Projektbesetzung',
                            'url' => '/projects/members',
                            'icon' => 'bi-person-plus',
                            'keywords' => ['meine projekte', 'stimmgruppe zuteilen', 'projekt'],
                            'prefixes' => ['/projects/members'],
                            'navKeys' => ['project_members'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                ($c->can('can_manage_project_members')
                                    || $c->can('can_assign_own_voice_group_to_project'))
                                && !$c->can('can_manage_master_data'),
                        ],
                        [
                            'label' => 'Projektübersicht',
                            'url' => '/evaluations/project-members',
                            'icon' => 'bi-person-lines-fill',
                            'keywords' => ['projektmitglieder', 'wer singt mit', 'besetzung'],
                            'prefixes' => ['/evaluations/project-members'],
                            'navKeys' => ['evaluations_project_members'],
                            'visible' => $always,
                        ],
                    ],
                ],
                [
                    'key' => 'finance',
                    'title' => 'Finanzen',
                    'entries' => [
                        [
                            'label' => 'Kassa',
                            'url' => '/finances',
                            'icon' => 'bi-bank',
                            'keywords' => ['kassabuch', 'geld', 'buchung', 'einnahmen', 'ausgaben', 'konten'],
                            'prefixes' => ['/finances'],
                            'navKeys' => ['finances'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->module('finance')
                                && ($c->can('can_read_finances') || $c->can('can_manage_finances')),
                        ],
                        [
                            'label' => 'Budget',
                            'url' => '/budget',
                            'icon' => 'bi-calculator',
                            'keywords' => ['planung', 'kosten', 'voranschlag'],
                            'prefixes' => ['/budget'],
                            'navKeys' => ['budget'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->module('budget')
                                && ($c->can('can_read_finances') || $c->can('can_manage_finances')
                                    || $c->can('can_manage_budget')),
                        ],
                        [
                            'label' => 'Sponsoring',
                            'url' => '/sponsoring',
                            'icon' => 'bi-briefcase',
                            'keywords' => ['sponsoren', 'pakete', 'förderer', 'spenden'],
                            'prefixes' => ['/sponsoring'],
                            'navKeys' => ['sponsoring'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->module('sponsoring')
                                && ($c->can('can_manage_sponsoring') || $c->can('can_create_own_sponsorships')),
                        ],
                    ],
                ],
                [
                    'key' => 'communication',
                    'title' => 'Kommunikation',
                    'entries' => [
                        [
                            'label' => 'Newsletter versenden',
                            'url' => '/newsletters',
                            'icon' => 'bi-send',
                            'keywords' => ['newsletter', 'rundschreiben', 'mail schreiben', 'vorlagen'],
                            'prefixes' => ['/newsletters'],
                            'navKeys' => ['newsletters'],
                            'excl' => ['/newsletters/archive'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->module('newsletter') && $c->can('can_manage_newsletters'),
                        ],
                        [
                            'label' => 'Newsletter-Archiv',
                            'url' => '/newsletters/archive',
                            'icon' => 'bi-envelope',
                            'keywords' => ['meine newsletter', 'rundschreiben', 'infos'],
                            'prefixes' => ['/newsletters/archive'],
                            'navKeys' => ['newsletters_archive'],
                            'visible' => static fn(NavigationContext $c): bool => $c->module('newsletter'),
                        ],
                    ],
                ],
                [
                    'key' => 'administration',
                    'title' => 'Administration',
                    'entries' => [
                        [
                            'label' => 'Rollen & Rechte',
                            'url' => '/roles',
                            'icon' => 'bi-shield-lock-fill',
                            'keywords' => ['rollen', 'berechtigung', 'zugriff'],
                            'prefixes' => ['/roles'],
                            'navKeys' => ['roles'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_roles'),
                        ],
                        [
                            'label' => 'Stimmgruppen',
                            'url' => '/voice-groups',
                            'icon' => 'bi-music-note-beamed',
                            'keywords' => ['sopran', 'alt', 'tenor', 'bass', 'stimmen'],
                            'prefixes' => ['/voice-groups'],
                            'navKeys' => ['voice_groups'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_master_data'),
                        ],
                        [
                            'label' => 'Termin-Typen',
                            'url' => '/event-types',
                            'icon' => 'bi-tag',
                            'keywords' => ['probe', 'auftritt', 'kategorie', 'farbe'],
                            'prefixes' => ['/event-types'],
                            'navKeys' => ['event_types'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_events'),
                        ],
                        [
                            'label' => 'App-Einstellungen',
                            'url' => '/settings',
                            'icon' => 'bi-sliders',
                            'keywords' => ['module', 'konfiguration', 'logo', 'farbe'],
                            'prefixes' => ['/settings'],
                            'navKeys' => ['settings'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_master_data'),
                        ],
                        [
                            'label' => 'Mailversand',
                            'url' => '/admin/mail-queue',
                            'icon' => 'bi-mailbox',
                            'keywords' => ['warteschlange', 'mail-queue', 'zustellung'],
                            'prefixes' => ['/admin/mail-queue', '/mail-queue'],
                            'navKeys' => ['mail_queue'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_mail_queue'),
                        ],
                        [
                            'label' => 'Backups',
                            'url' => '/backups',
                            'icon' => 'bi-database-down',
                            'keywords' => ['sicherung', 'wiederherstellen', 'export'],
                            'prefixes' => ['/backups'],
                            'navKeys' => ['backups'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_backups'),
                        ],
                    ],
                ],
            ],
            'bottom' => [
                [
                    'label' => 'Hilfe',
                    'url' => '/help',
                    'icon' => 'bi-question-circle',
                    'keywords' => ['anleitung', 'dokumentation', 'support'],
                    'prefixes' => ['/help'],
                    'navKeys' => ['help'],
                    'visible' => $always,
                ],
            ],
        ];
    }
}
```

Hinweis zu `isKnownNavKey()`: Die alte Logik markierte bei `path='/dashboard'` und `navKey='downloads'` sowohl Downloads als auch Dashboard aktiv – in der Leiste wären das zwei hervorgehobene Einträge. Jetzt gilt: Gehört der gesetzte `navKey` zu einem Eintrag, entscheidet er allein; ein unbekannter oder leerer `navKey` lässt den Pfad entscheiden wie bisher. Alle heute gesetzten Werte (`downloads`, `help`, `newsletters_archive`, `song_library`, `sponsoring`) gehören zu einem Eintrag. Ergänze im Test einen Fall für den unbekannten Schlüssel:

```php
    public function testUnknownNavKeyFallsBackToThePath(): void
    {
        $tree = (new NavigationBuilder())->build(new NavigationContext([], [], '/events', 'not_a_nav_key'));

        $this->assertTrue($this->entry($tree, '/events')['active']);
    }
```

- [ ] **Step 4: Label im Rollen-Konsistenztest nachziehen**

`tests/Feature/RoleAccessConsistencyFeatureTest.php`, im Test `testAdminNavHidesRoleLinkBehindRoleManagementPermission`:

```php
        $this->assertMatchesRegularExpression(
            "/'label' => 'Rollen & Rechte',.*?'url' => '\/roles',.*?\\\$c->can\('can_manage_roles'\)/s",
            $nav
        );
```

- [ ] **Step 5: Builder-Test und alle Quelltext-Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "NavigationBuilderFeatureTest|RoleAccessConsistency|FinanceFeature|BudgetFeature|SponsoringFeatureFlag|SongLibraryFeatureTest|NewsletterFeatureFlag|ProjectFeatureTest|RegistrationFeatureTest|RegistrationEvaluation|AttendanceFeatureTest|HelpFeatureTest|EventManagementPermission|RoleManagementPermission" 2>&1 | tail -15`
Expected: PASS. (Die Render-Tests der alten `menu.twig` sind hier bewusst nicht dabei – Task 2.)

- [ ] **Step 6: phpcs und Commit**

```bash
ddev composer phpcs -- src/Navigation tests/Feature/NavigationBuilderFeatureTest.php tests/Feature/RoleAccessConsistencyFeatureTest.php
git add src/Navigation/NavigationBuilder.php tests/Feature/NavigationBuilderFeatureTest.php tests/Feature/RoleAccessConsistencyFeatureTest.php
git commit -m "feat(navigation): Abschnitte, neue Namen und Stichwörter im NavigationBuilder"
```

---

### Task 2: Seitenleiste im Layout (Desktop fest, mobil Offcanvas, Einklappen)

**Files:**
- Create: `templates/partials/navigation/sidebar.twig`
- Delete: `templates/partials/navigation/menu.twig`
- Modify: `templates/layout.twig:1-69` (Kopf und Navbar)
- Modify: `public/css/style.css` (Navbar-Abschnitt ab Zeile ~601; Regeln `.navbar-toggler` und `.navbar .dropdown-menu .dropdown-item.active` entfernen; neuer Abschnitt)
- Create: `public/js/navigation-state.js`, `public/js/navigation.js`
- Test: `tests/Feature/NavigationMenuRenderFeatureTest.php` (komplett ersetzen), `tests/Feature/NavigationLayoutSeamFeatureTest.php` (erweitern)

**Interfaces:**
- Consumes: Knotenliste aus Task 1.
- Produces (für Task 4 und 5):
  - `#app-sidebar` (`aside.offcanvas-lg.offcanvas-start.app-sidebar`)
  - Links: `a.app-sidebar__link` mit `href`, `data-nav-icon="bi-…"`, `data-nav-keywords="a|b|c"`, Label in `span.app-sidebar__label`, aktiver Link mit Klasse `active` und `aria-current="page"`
  - Abschnitt: `div.app-sidebar__section[data-nav-section="<key>"][data-nav-section-title="<Titel>"]`, bei `foldable` zusätzlich Klasse `app-sidebar__section--foldable`, bei aktiv Klasse `is-active`; Faltknopf `button[data-nav-fold="<key>"]`
  - Kopfleisten-Knopf `button[data-nav-toggle]` (`aria-controls="app-sidebar"`)
  - Klassen am `<html>`: `nav-collapsed`, `nav-open-administration`
  - `localStorage`-Schlüssel siehe Global Constraints; `chormanager.nav.recent` = JSON-Array von bis zu 5 URLs, neueste zuerst

- [ ] **Step 1: Render-Test komplett ersetzen**

`tests/Feature/NavigationMenuRenderFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class NavigationMenuRenderFeatureTest extends TestCase
{
    private function render(array $permissions, array $modules, string $path): string
    {
        $tree = (new NavigationBuilder())->build(
            new NavigationContext($permissions, $modules, $path)
        );

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));

        return $twig->render('partials/navigation/sidebar.twig', ['navigation' => $tree]);
    }

    public function testPlainMemberSidebarHasPublicLinksOnly(): void
    {
        $html = $this->render([], ['registration' => true], '/dashboard');

        $this->assertStringContainsString('href="/registrations"', $html);
        $this->assertStringContainsString('href="/downloads"', $html);
        $this->assertStringContainsString('href="/evaluations/project-members"', $html);
        $this->assertStringNotContainsString('href="/roles"', $html);
        $this->assertStringNotContainsString('href="/backups"', $html);
        $this->assertStringNotContainsString('data-nav-section="administration"', $html);
    }

    public function testSidebarIsAnOffcanvasBelowLg(): void
    {
        $html = $this->render([], [], '/dashboard');

        $this->assertMatchesRegularExpression('/<aside[^>]*class="offcanvas-lg offcanvas-start app-sidebar"[^>]*id="app-sidebar"/', $html);
        $this->assertStringContainsString('data-bs-dismiss="offcanvas"', $html);
        $this->assertStringContainsString('aria-label="Hauptnavigation"', $html);
    }

    public function testActiveLinkIsMarkedForStylingAndScreenReaders(): void
    {
        $html = $this->render(['can_manage_users' => true], ['registration' => true], '/registrations');

        $this->assertMatchesRegularExpression(
            '/<a class="app-sidebar__link active"\s+href="\/registrations"[^>]*aria-current="page"/',
            $html
        );
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function testLinksCarrySearchData(): void
    {
        $html = $this->render(['can_read_finances' => true], ['finance' => true], '/dashboard');

        $this->assertMatchesRegularExpression(
            '/href="\/finances"[^>]*data-nav-icon="bi-bank"[^>]*data-nav-keywords="kassabuch\|geld\|[^"]*"/',
            $html
        );
        $this->assertStringContainsString('data-nav-section-title="Finanzen"', $html);
    }

    public function testAdministrationIsFoldableAndOpenWhenActive(): void
    {
        $closed = $this->render(['can_manage_backups' => true], [], '/dashboard');
        $this->assertMatchesRegularExpression(
            '/<button type="button"\s+class="app-sidebar__heading app-sidebar__toggle"'
                . '[^>]*aria-expanded="false"[^>]*data-nav-fold="administration"/',
            $closed
        );
        $this->assertStringNotContainsString('app-sidebar__section--foldable is-active', $closed);

        $open = $this->render(['can_manage_backups' => true], [], '/backups');
        $this->assertStringContainsString('app-sidebar__section--foldable is-active', $open);
        $this->assertMatchesRegularExpression('/aria-expanded="true"[^>]*data-nav-fold="administration"/', $open);
    }

    public function testHelpSitsBehindASpacerAtTheBottom(): void
    {
        $html = $this->render([], [], '/dashboard');

        $this->assertMatchesRegularExpression(
            '/<div class="app-sidebar__spacer"><\/div>\s*<a class="app-sidebar__link"\s+href="\/help"/',
            $html
        );
    }

    public function testWiringAndLayoutUseBuilder(): void
    {
        $deps = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');
        $this->assertIsString($deps);
        $this->assertStringContainsString("'navigation'", $deps);
        $this->assertStringContainsString('NavigationBuilder', $deps);

        $layout = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');
        $this->assertIsString($layout);
        $this->assertStringContainsString('include("partials/navigation/sidebar.twig"', $layout);
        $this->assertStringNotContainsString('navbarsExampleDefault', $layout);
        $this->assertStringNotContainsString('can_show_events', $layout);
        $this->assertStringNotContainsString('can_show_admin', $layout);
    }

    public function testOldNavPartialsRemoved(): void
    {
        foreach (['menu', 'events', 'areas', 'admin', 'evaluations', 'dashboard'] as $partial) {
            $this->assertFileDoesNotExist(
                dirname(__DIR__) . '/../templates/partials/navigation/' . $partial . '.twig'
            );
        }
    }
}
```

- [ ] **Step 2: Layout-Nahtstelle erweitern**

In `tests/Feature/NavigationLayoutSeamFeatureTest.php` den bestehenden Test um diese Assertions ergänzen (nach `assertStringContainsString('href="/registrations"', $body);`):

```php
        $this->assertStringContainsString('id="app-sidebar"', $body);
        $this->assertStringContainsString('data-nav-toggle', $body);
        $this->assertStringContainsString('class="app-shell app-shell--with-sidebar"', $body);
        $this->assertMatchesRegularExpression(
            '/<head>.*<script src="\/js\/navigation-state\.js"><\/script>.*<\/head>/s',
            $body
        );
        $this->assertStringContainsString('<script src="/js/navigation.js"></script>', $body);
```

und einen zweiten Test anhängen:

```php
    public function testLoggedOutPagesRenderWithoutSidebar(): void
    {
        $_SESSION = [];
        $twig = $this->createTwig([]);

        $html = $twig->getEnvironment()->render('layout.twig', []);

        $this->assertStringNotContainsString('id="app-sidebar"', $html);
        $this->assertStringContainsString('<body class="app-shell">', $html);
    }
```

- [ ] **Step 3: Tests laufen lassen – müssen rot sein**

Run: `ddev php vendor/bin/phpunit --filter "NavigationMenuRenderFeatureTest|NavigationLayoutSeamFeatureTest" 2>&1 | tail -15`
Expected: FAIL (`sidebar.twig` fehlt, `app-sidebar` nicht im Layout).

- [ ] **Step 4: `sidebar.twig` anlegen**

`templates/partials/navigation/sidebar.twig`:

```twig
{% import _self as sidebar_ui %}

{# Ein Eintrag der Seitenleiste. Die data-nav-*-Attribute liest die Schnellsuche
   (navigation-search.js) - sie durchsucht damit genau die Links, die die Rolle sieht. #}
{% macro link(item) %}
    {% set _active_class = item.active ? " active" : "" %}
    <a class="app-sidebar__link{{ _active_class }}"
       href="{{ item.url }}"
       title="{{ item.label }}"
       data-nav-icon="{{ item.icon }}"
       data-nav-keywords="{{ item.keywords|join("|") }}"
       {% if item.active %}aria-current="page"{% endif %}>
        <i class="bi {{ item.icon }}" aria-hidden="true"></i>
        <span class="app-sidebar__label">{{ item.label }}</span>
    </a>
{% endmacro %}

{# Ab lg fest links neben dem Inhalt, darunter ein Offcanvas, das der Menüknopf in der
   Kopfleiste öffnet (navigation.js). Ohne JavaScript bleibt die Leiste am Desktop nutzbar. #}
<aside class="offcanvas-lg offcanvas-start app-sidebar"
       tabindex="-1"
       id="app-sidebar"
       aria-labelledby="app-sidebar-title">
    <div class="offcanvas-header app-sidebar__header">
        <span class="offcanvas-title app-sidebar__title" id="app-sidebar-title">Menü</span>
        <button type="button"
                class="btn-close btn-close-white"
                data-bs-dismiss="offcanvas"
                data-bs-target="#app-sidebar"
                aria-label="Menü schließen"></button>
    </div>
    <nav class="offcanvas-body app-sidebar__body" aria-label="Hauptnavigation">
        {% for node in navigation %}
            {% if node.type == "link" %}
                {% if node.position == "bottom" %}
                    <div class="app-sidebar__spacer"></div>
                {% endif %}
                {{ sidebar_ui.link(node) }}
            {% else %}
                {% set _foldable_class = node.foldable ? " app-sidebar__section--foldable" : "" %}
                {% set _active_class = node.active ? " is-active" : "" %}
                <div class="app-sidebar__section{{ _foldable_class }}{{ _active_class }}"
                     data-nav-section="{{ node.key }}"
                     data-nav-section-title="{{ node.label }}">
                    {% if node.foldable %}
                        {% set _expanded = node.active ? "true" : "false" %}
                        <button type="button"
                                class="app-sidebar__heading app-sidebar__toggle"
                                aria-expanded="{{ _expanded }}"
                                aria-controls="app-sidebar-{{ node.key }}"
                                data-nav-fold="{{ node.key }}">
                            <span>{{ node.label }}</span>
                            <i class="bi bi-chevron-down app-sidebar__chevron" aria-hidden="true"></i>
                        </button>
                    {% else %}
                        <div class="app-sidebar__heading"><span>{{ node.label }}</span></div>
                    {% endif %}
                    <ul class="app-sidebar__items" id="app-sidebar-{{ node.key }}">
                        {% for item in node.items %}
                            <li>{{ sidebar_ui.link(item) }}</li>
                        {% endfor %}
                    </ul>
                </div>
            {% endif %}
        {% endfor %}
    </nav>
</aside>
```

Hinweis: Die Regex-Tests verlangen die Attributreihenfolge `class`, `href`, …, `aria-current` am Link und `class`, `aria-expanded`, `aria-controls`, `data-nav-fold` am Faltknopf. Zeilenumbrüche zwischen Attributen sind erlaubt, die Reihenfolge nicht.

- [ ] **Step 5: `menu.twig` löschen**

```bash
git rm templates/partials/navigation/menu.twig
```

- [ ] **Step 6: Layout umbauen**

In `templates/layout.twig`:

1. Im `<head>` direkt nach `<meta name="csrf-token" …>` (vor den Stylesheets):

```twig
        {# Setzt den gemerkten Zustand der Seitenleiste, bevor gezeichnet wird - sonst springt die Leiste beim Laden. #}
        <script src="{{ asset_path('/js/navigation-state.js') }}"></script>
```

2. `<body class="app-shell">` bis einschließlich `</nav>` (heute Zeilen 37–69) ersetzen durch:

```twig
    {% set _sidebar_class = session.user_id ? " app-shell--with-sidebar" : "" %}
    <body class="app-shell{{ _sidebar_class }}">
        {% if session.user_id %}
            {% set navigation = navigation(active_nav|default("")) %}
            <header class="navbar navbar-dark bg-dark fixed-top app-topbar">
                <div class="container-fluid app-topbar__inner">
                    <button type="button"
                            class="app-topbar__menu-toggle"
                            data-nav-toggle
                            aria-controls="app-sidebar"
                            aria-expanded="true"
                            aria-label="Menü ein- und ausklappen">
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>
                    <a class="navbar-brand d-flex align-items-center" href="/dashboard">
                        <img src="/logo"
                             alt="Logo"
                             width="200"
                             height="64"
                             class="me-2 navbar-logo">
                        <span class="app-topbar__brand-name">{{ app_settings.app_name|default("Chor-Manager") }}</span></a>
                    <div class="app-topbar__actions">
                        {{ include("partials/navigation/user_menu.twig", [], false) }}
                    </div>
                </div>
            </header>
            {{ include("partials/navigation/sidebar.twig", {navigation: navigation}, false) }}
        {% endif %}
```

3. Nach `<script src="{{ asset_path('/js/common.js') }}"></script>` innerhalb des bestehenden `{% if session.user_id %}`-Blocks (bei `mail-badge.js`) ergänzen:

```twig
            <script src="{{ asset_path('/js/navigation.js') }}"></script>
```

- [ ] **Step 7: `navigation-state.js` anlegen**

`public/js/navigation-state.js`:

```js
/**
 * Läuft im <head>, bevor die Seite gezeichnet wird: setzt die gemerkten Zustände der
 * Seitenleiste als Klassen am <html>-Element. Würde das erst navigation.js am Ende der
 * Seite tun, spränge die Leiste bei jedem Seitenwechsel sichtbar von breit auf schmal.
 *
 * Gesperrter oder geleerter localStorage (privates Fenster) ist kein Fehler - dann
 * gilt der Standard: breite Leiste, Administration zu.
 */
(function () {
    'use strict';

    var root = document.documentElement;

    try {
        if (window.localStorage.getItem('chormanager.nav.collapsed') === '1') {
            root.classList.add('nav-collapsed');
        }
        if (window.localStorage.getItem('chormanager.nav.open.administration') === '1') {
            root.classList.add('nav-open-administration');
        }
    } catch (e) {
        // Standardzustand behalten.
    }
})();
```

- [ ] **Step 8: `navigation.js` anlegen**

`public/js/navigation.js`:

```js
/**
 * Seitenleiste: Einklappen am Desktop, Offcanvas am Handy, aufklappbare Abschnitte und
 * das Merken der zuletzt besuchten Seite für die Schnellsuche.
 *
 * Der Menüknopf hat zwei Bedeutungen je nach Breite. Ab lg schaltet er zwischen breiter
 * Leiste und Symbolleiste um, darunter öffnet er das Offcanvas. Deshalb trägt er kein
 * data-bs-toggle: Bootstrap würde sonst auch am Desktop ein Offcanvas samt Backdrop öffnen.
 */
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('app-sidebar');
    if (!sidebar) {
        return;
    }

    var root = document.documentElement;
    var desktop = window.matchMedia('(min-width: 992px)');
    var COLLAPSED_KEY = 'chormanager.nav.collapsed';
    var OPEN_KEY_PREFIX = 'chormanager.nav.open.';
    var RECENT_KEY = 'chormanager.nav.recent';
    var RECENT_LIMIT = 5;

    function store(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            // Ohne localStorage gilt der Zustand nur bis zum nächsten Seitenwechsel.
        }
    }

    var toggles = document.querySelectorAll('[data-nav-toggle]');

    function syncToggles() {
        var expanded = desktop.matches
            ? !root.classList.contains('nav-collapsed')
            : sidebar.classList.contains('show');
        toggles.forEach(function (toggle) {
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

    toggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            if (desktop.matches) {
                var collapsed = root.classList.toggle('nav-collapsed');
                store(COLLAPSED_KEY, collapsed ? '1' : '0');
            } else {
                bootstrap.Offcanvas.getOrCreateInstance(sidebar).toggle();
            }
            syncToggles();
        });
    });

    sidebar.addEventListener('shown.bs.offcanvas', syncToggles);
    sidebar.addEventListener('hidden.bs.offcanvas', syncToggles);

    // Bootstrap räumt ein offenes offcanvas-lg beim Vergrößern nur auf, solange es nicht
    // position: fixed hat - unsere Desktop-Leiste hat genau das. Ohne dieses Schließen
    // bliebe nach dem Drehen eines Tablets ein grauer Backdrop über der Seite stehen.
    desktop.addEventListener('change', function () {
        if (desktop.matches) {
            var instance = bootstrap.Offcanvas.getInstance(sidebar);
            if (instance) {
                instance.hide();
            }
        }
        syncToggles();
    });

    sidebar.querySelectorAll('[data-nav-fold]').forEach(function (button) {
        var key = button.getAttribute('data-nav-fold');
        var section = button.closest('[data-nav-section]');
        var openClass = 'nav-open-' + key;

        function syncFold() {
            var open = section.classList.contains('is-active') || root.classList.contains(openClass);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        button.addEventListener('click', function () {
            // Liegt die aktuelle Seite im Abschnitt, bleibt er offen - sonst verschwände
            // der hervorgehobene Eintrag.
            if (section.classList.contains('is-active')) {
                return;
            }
            var open = root.classList.toggle(openClass);
            store(OPEN_KEY_PREFIX + key, open ? '1' : '0');
            syncFold();
        });

        syncFold();
    });

    var current = sidebar.querySelector('.app-sidebar__link[aria-current="page"]');
    if (current) {
        try {
            var url = current.getAttribute('href');
            var stored = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
            var recent = (Array.isArray(stored) ? stored : []).filter(function (entry) {
                return typeof entry === 'string' && entry !== url;
            });
            recent.unshift(url);
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(recent.slice(0, RECENT_LIMIT)));
        } catch (e) {
            // Kaputter oder gesperrter Speicher: dann eben ohne "Zuletzt besucht".
        }
    }

    syncToggles();
});
```

Hinweis: `navigation-state.js` kennt nur den Schlüssel `administration`. Kommt je ein weiterer faltbarer Abschnitt dazu, muss er dort ebenfalls eingetragen werden – das steht deshalb im Kommentar von `FOLDABLE_SECTIONS` (Task 1) nachzutragen:

```php
    /**
     * Abschnitte, die sich in der Leiste zuklappen lassen. Nur die selten gebrauchten
     * Verwaltungsseiten - alles, was im Chor-Alltag gebraucht wird, bleibt immer sichtbar.
     * Ein neuer Eintrag hier braucht auch eine Zeile in public/js/navigation-state.js,
     * sonst springt sein gemerkter Zustand beim Laden.
     */
```

- [ ] **Step 9: CSS**

In `public/css/style.css`:

1. Im `:root`-Block (Zeile ~1–29) ergänzen:

```css
    --app-topbar-height: 3.75rem;
    --app-sidebar-width: 15rem;
    --app-sidebar-rail-width: 4rem;
    --app-sidebar-bg: linear-gradient(180deg, rgba(36, 50, 68, 0.98) 0%, rgba(24, 33, 43, 0.98) 100%);
```

2. `body.app-shell { … padding-top: 5.5rem; }` und `.app-main { min-height: calc(100vh - 5.5rem); }` ersetzen durch:

```css
body.app-shell {
    background: linear-gradient(180deg, #f7f8fb 0%, #eef2f7 100%);
    color: var(--theme-text);
    padding-top: calc(var(--app-topbar-height) + 1.75rem);
}

.app-main {
    min-height: calc(100vh - var(--app-topbar-height) - 1.75rem);
}
```

3. Die Regeln `.navbar.bg-dark.app-topbar .navbar-toggler`, `… .navbar-toggler .toggler-icon`, `… .toggler-icon-close`, beide `…[aria-expanded="true"] …`-Regeln sowie `.navbar .dropdown-menu .dropdown-item.active, .navbar .dropdown-menu .dropdown-item:active` und `.navbar-dark .nav-link…`-Regeln löschen (keine Verwendung mehr). Danach prüfen:

```bash
grep -rn "navbar-toggler\|nav-link\.active\|toggler-icon" templates public/css public/js
```

Expected: keine Treffer außer `templates/profile/index.twig` (Tabs nutzen `nav-link`, die gelöschten Regeln waren auf `.navbar-dark` beschränkt – nichts davon betrifft die Tabs).

4. In der Regel `.navbar.bg-dark.app-topbar { … }` die Höhe festlegen (ergänzen):

```css
    height: var(--app-topbar-height);
    padding-top: 0;
    padding-bottom: 0;
```

5. Am Ende des Navbar-Abschnitts den neuen Abschnitt einfügen:

```css
/* ============================================================
   Kopfleiste: Menüknopf, Aktionen
   ============================================================ */
.app-topbar__inner {
    gap: 0.5rem;
}

.app-topbar__menu-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    flex-shrink: 0;
    border: 0;
    border-radius: 0.375rem;
    background: transparent;
    color: #ffffff;
    font-size: 1.5rem;
}

.app-topbar__menu-toggle:hover,
.app-topbar__menu-toggle:focus-visible {
    background: rgba(255, 255, 255, 0.1);
}

.app-topbar__actions {
    display: flex;
    align-items: center;
    margin-left: auto;
    flex-shrink: 0;
}

/* ============================================================
   Seitenleiste
   ============================================================ */
.app-sidebar.offcanvas-lg {
    --bs-offcanvas-width: min(18rem, 85vw);
    background: var(--app-sidebar-bg);
    color: #ffffff;
}

.app-sidebar__header {
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}

.app-sidebar__title {
    font-weight: 600;
}

.app-sidebar__body {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    padding: 0.5rem;
}

.app-sidebar__heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 0.9rem 0.75rem 0.3rem;
    border: 0;
    background: none;
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-align: left;
    text-transform: uppercase;
}

.app-sidebar__toggle:hover,
.app-sidebar__toggle:focus-visible {
    color: #ffffff;
}

.app-sidebar__chevron {
    transition: transform 0.15s ease;
}

.app-sidebar__toggle[aria-expanded="true"] .app-sidebar__chevron {
    transform: rotate(180deg);
}

.app-sidebar__items {
    margin: 0;
    padding: 0;
    list-style: none;
}

.app-sidebar__section--foldable .app-sidebar__items {
    display: none;
}

.app-sidebar__section--foldable.is-active .app-sidebar__items,
html.nav-open-administration [data-nav-section="administration"] .app-sidebar__items {
    display: block;
}

.app-sidebar__link {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.45rem 0.75rem;
    border-radius: 0.375rem;
    color: rgba(255, 255, 255, 0.75);
    font-weight: 500;
    text-decoration: none;
    white-space: nowrap;
}

.app-sidebar__link:hover,
.app-sidebar__link:focus-visible {
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}

.app-sidebar__link.active {
    background: rgba(var(--theme-primary-rgb), 0.16);
    color: var(--theme-primary, #E8A817);
    font-weight: 600;
}

.app-sidebar__link .bi {
    flex-shrink: 0;
    width: 1.1rem;
    text-align: center;
}

.app-sidebar__label {
    overflow: hidden;
    text-overflow: ellipsis;
}

.app-sidebar__spacer {
    flex: 1 1 auto;
    min-height: 1rem;
}

@media (min-width: 992px) {
    .app-sidebar.offcanvas-lg {
        position: fixed;
        top: var(--app-topbar-height);
        bottom: 0;
        left: 0;
        z-index: 1020;
        width: var(--app-sidebar-width);
        background: var(--app-sidebar-bg) !important;
        transition: width 0.15s ease;
    }

    .app-sidebar.offcanvas-lg .offcanvas-body {
        flex-direction: column;
        flex-grow: 1;
        padding: 0.5rem;
        overflow-y: auto;
    }

    .app-shell--with-sidebar .app-main {
        margin-left: var(--app-sidebar-width);
        transition: margin-left 0.15s ease;
    }

    /* Symbolleiste: nur Icons, Abschnittsüberschriften werden zu Trennlinien. Weil es
       dort keine Überschrift zum Aufklappen gibt, ist Administration immer sichtbar. */
    html.nav-collapsed .app-sidebar.offcanvas-lg {
        width: var(--app-sidebar-rail-width);
    }

    html.nav-collapsed .app-shell--with-sidebar .app-main {
        margin-left: var(--app-sidebar-rail-width);
    }

    html.nav-collapsed .app-sidebar__label,
    html.nav-collapsed .app-sidebar__heading span,
    html.nav-collapsed .app-sidebar__chevron {
        display: none;
    }

    html.nav-collapsed .app-sidebar__heading {
        height: 0;
        margin: 0.4rem 0.25rem;
        padding: 0;
        overflow: hidden;
        border-top: 1px solid rgba(255, 255, 255, 0.14);
        pointer-events: none;
    }

    html.nav-collapsed .app-sidebar__link {
        justify-content: center;
        padding: 0.55rem 0;
    }

    html.nav-collapsed .app-sidebar__section--foldable .app-sidebar__items {
        display: block;
    }
}

@media (prefers-reduced-motion: reduce) {
    .app-sidebar.offcanvas-lg,
    .app-shell--with-sidebar .app-main,
    .app-sidebar__chevron {
        transition: none;
    }
}

@media print {
    .app-topbar,
    .app-sidebar {
        display: none !important;
    }

    body.app-shell {
        padding-top: 0;
    }

    .app-shell--with-sidebar .app-main {
        margin-left: 0 !important;
    }
}
```

Hinweis: `user_menu.twig` gibt Brief-Badge und Profil-Dropdown aus und bleibt unverändert; die Klasse `me-3` am Badge sorgt weiter für den Abstand.

- [ ] **Step 10: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "NavigationMenuRenderFeatureTest|NavigationLayoutSeamFeatureTest|HelpFeatureTest|DashboardFeatureTest" 2>&1 | tail -15`
Expected: PASS.

- [ ] **Step 11: Twig-Lint, LF, Commit**

```bash
ddev composer twigcs
git add -A templates/partials/navigation templates/layout.twig public/css/style.css public/js/navigation-state.js public/js/navigation.js tests/Feature/NavigationMenuRenderFeatureTest.php tests/Feature/NavigationLayoutSeamFeatureTest.php src/Navigation/NavigationBuilder.php
git commit -m "feat(navigation): Seitenleiste mit Offcanvas, Einklappen und gemerktem Zustand"
```

Expected: `twigcs` ohne Fehler; der Commit-Hook normalisiert LF.

---

### Task 3: Seitentitel und Überschriften folgen den neuen Namen

**Files:**
- Modify: `templates/dashboard/index.twig:4,10,39,65`
- Modify: `templates/users/manage.twig:7,14`
- Modify: `templates/songs/downloads.twig:4,11`
- Modify: `templates/projects/member_projects.twig:4,11`
- Modify: `templates/evaluations/project_members.twig:4,11`
- Modify: `templates/newsletters/archive.twig:4,11`
- Modify: `templates/newsletters/index.twig:4,11`
- Modify: `templates/attendance/show.twig:4,11`
- Modify: `templates/roles/index.twig:4,11`
- Modify: `templates/backups/index.twig:4,11`
- Test: `tests/Feature/NavigationPageTitlesFeatureTest.php` (neu), `tests/Feature/NewsletterFeatureTest.php:172-178`

**Interfaces:**
- Consumes: Labels aus Task 1.
- Produces: –

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/NavigationPageTitlesFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Menü und Seite müssen denselben Namen tragen: wer in der Leiste "Mitglieder" anklickt,
 * soll nicht auf einer Seite namens "Mitgliederverwaltung" landen. Geprüft werden
 * <title> und Hauptüberschrift jeder Seite, deren Menüname sich geändert hat.
 */
class NavigationPageTitlesFeatureTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function renamedPages(): array
    {
        return [
            'Mitglieder' => ['users/manage.twig', 'Mitglieder', 'Mitglieder'],
            'Probenmaterial' => ['songs/downloads.twig', 'Probenmaterial', 'Probenmaterial'],
            'Projektbesetzung' => ['projects/member_projects.twig', 'Projektbesetzung', 'Projektbesetzung'],
            'Projektübersicht' => ['evaluations/project_members.twig', 'Projektübersicht', 'Projektübersicht'],
            'Newsletter-Archiv' => ['newsletters/archive.twig', 'Newsletter-Archiv', 'Newsletter-Archiv'],
            'Newsletter versenden' => ['newsletters/index.twig', 'Newsletter versenden', 'Newsletter versenden'],
            'Anwesenheit erfassen' => ['attendance/show.twig', 'Anwesenheit erfassen', 'Anwesenheit erfassen'],
            'Rollen & Rechte' => ['roles/index.twig', 'Rollen &amp; Rechte', 'Rollen &amp; Rechte'],
            'Backups' => ['backups/index.twig', 'Backups', 'Backups'],
        ];
    }

    /**
     * @dataProvider renamedPages
     */
    public function testPageTitleAndHeadingMatchTheMenuName(string $template, string $title, string $heading): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            '/\{% block title %\}\s*' . preg_quote($title, '/') . ' - /',
            $source,
            "Seitentitel in {$template}"
        );
        $this->assertMatchesRegularExpression(
            '/<h1 class="h2 mb-1">' . preg_quote($heading, '/') . '<\/h1>/',
            $source,
            "Überschrift in {$template}"
        );
    }

    public function testStartPageUsesTheNewName(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/dashboard/index.twig');
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/\{% block title %\}\s*Start - /', $source);
        $this->assertStringNotContainsString('>Dashboard</p>', $source);
        $this->assertStringContainsString('>Anwesenheit erfassen</a>', $source);
        $this->assertStringNotContainsString('Mitgliederverwaltung', $source);
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss rot sein**

Run: `ddev php vendor/bin/phpunit --filter NavigationPageTitlesFeatureTest 2>&1 | tail -15`
Expected: FAIL in allen zehn Fällen.

- [ ] **Step 3: Templates anpassen**

Je Datei Titelzeile und `<h1>` ersetzen (der Rest der Zeile – `- {{ app_settings… }}` – bleibt):

| Datei | Titel alt → neu | `<h1>` alt → neu |
|---|---|---|
| `users/manage.twig` | `Mitgliederverwaltung` → `Mitglieder` | `Mitgliederverwaltung` → `Mitglieder` |
| `songs/downloads.twig` | `Downloads` → `Probenmaterial` | `Downloads` → `Probenmaterial` |
| `projects/member_projects.twig` | `Meine Projekte` → `Projektbesetzung` | `Meine Projekte` → `Projektbesetzung` |
| `evaluations/project_members.twig` | `Projektmitglieder` → `Projektübersicht` | `Projektmitglieder` → `Projektübersicht` |
| `newsletters/archive.twig` | `Meine Newsletter` → `Newsletter-Archiv` | `Meine Newsletter` → `Newsletter-Archiv` |
| `newsletters/index.twig` | `Newsletter-Übersicht` → `Newsletter versenden` | `Newsletter` → `Newsletter versenden` |
| `attendance/show.twig` | `Anwesenheitsliste` → `Anwesenheit erfassen` | `Anwesenheiten eintragen` → `Anwesenheit erfassen` |
| `roles/index.twig` | `Rollen verwalten` → `Rollen &amp; Rechte` | `Rollen verwalten` → `Rollen &amp; Rechte` |
| `backups/index.twig` | `Backup-Verwaltung` → `Backups` | `Backup-Verwaltung` → `Backups` |

`templates/dashboard/index.twig`:
- Zeile 4: `Dashboard - {{ … }}` → `Start - {{ … }}`
- Zeile 10: `<p class="text-uppercase text-muted small mb-1">Dashboard</p>` → `<p class="text-uppercase text-muted small mb-1">Start</p>`
- Zeile 39: Linktext `Anwesenheiten eintragen` → `Anwesenheit erfassen`
- Zeile 65: `Mitgliederverwaltung` → `Mitglieder`

Nicht ändern: Rechte-Labels in `templates/roles/index.twig` (z. B. „Mitgliederverwaltung erlauben", „Backup-Verwaltung") – die benennen Rechte, nicht Seiten, und die Hilfe verweist auf sie.

- [ ] **Step 4: Newsletter-Test nachziehen**

In `tests/Feature/NewsletterFeatureTest.php` den Test umbenennen und den Text anpassen:

```php
    public function testNewsletterArchiveTemplateExistsAndMentionsArchiveTitle(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/archive.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('Newsletter-Archiv', $template);
        $this->assertStringContainsString('an dich versendet', $template);
    }
```

Den Docblock darüber, falls er „Meine Newsletter" nennt, ebenfalls auf „Newsletter-Archiv" umstellen.

- [ ] **Step 5: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "NavigationPageTitlesFeatureTest|NewsletterFeatureTest|DashboardFeatureTest|Attendance|Backup|Role|Evaluation|ProjectFeatureTest|SongLibrary|UserFeature" 2>&1 | tail -15`
Expected: PASS. Bricht ein Test an einem alten Seitentitel, ihn auf den neuen Namen umstellen.

- [ ] **Step 6: twigcs und Commit**

```bash
ddev composer twigcs
git add templates tests/Feature/NavigationPageTitlesFeatureTest.php tests/Feature/NewsletterFeatureTest.php
git commit -m "feat(navigation): Seitentitel folgen den neuen Menünamen"
```

---

### Task 4: Schnellsuche (Strg+K)

**Files:**
- Create: `public/js/navigation-search-rank.js`
- Create: `public/js/navigation-search.js`
- Create: `templates/partials/navigation/search_modal.twig`
- Modify: `templates/layout.twig` (Suchknopf in `.app-topbar__actions`, Modal-Include, zwei Skripte)
- Modify: `public/css/style.css` (Abschnitt „Schnellsuche")
- Modify: `package.json` (Skript `test:js`)
- Test: `tests/js/navigation-search-rank.test.mjs` (neu), `tests/Feature/NavigationLayoutSeamFeatureTest.php` (erweitern)

**Interfaces:**
- Consumes: Markup aus Task 2 (`#app-sidebar`, `a.app-sidebar__link`, `data-nav-icon`, `data-nav-keywords` mit `|`, `data-nav-section-title`, `span.app-sidebar__label`), `localStorage['chormanager.nav.recent']`.
- Produces: `window.NavigationSearchRank` mit
  - `normalize(text: string): string`
  - `rankNavigationEntries(entries: Entry[], query: string): Array<{entry: Entry, keyword: string|null}>`
  - `readRecentUrls(raw: string|null): string[]`
  - `resolveRecentEntries(entries: Entry[], urls: string[]): Entry[]`
  - `Entry = {label: string, url: string, icon: string, section: string, keywords: string[]}`
  - Knopf `[data-nav-search-open]`, Modal `#nav-search-modal`, Eingabe `#nav-search-input`, Liste `#nav-search-results` mit `li[role="option"]`.

- [ ] **Step 1: Failing Test für die reinen Funktionen**

`tests/js/navigation-search-rank.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const {
    normalize,
    rankNavigationEntries,
    readRecentUrls,
    resolveRecentEntries,
} = require('../../public/js/navigation-search-rank.js');

const entries = [
    { label: 'Start', url: '/dashboard', icon: 'bi-house', section: '', keywords: ['dashboard'] },
    { label: 'Kassa', url: '/finances', icon: 'bi-bank', section: 'Finanzen', keywords: ['kassabuch', 'buchung'] },
    { label: 'Budget', url: '/budget', icon: 'bi-calculator', section: 'Finanzen', keywords: ['planung'] },
    { label: 'Projektübersicht', url: '/evaluations/project-members', icon: 'bi-person-lines-fill', section: 'Mitglieder & Projekte', keywords: ['projektmitglieder'] },
    { label: 'Projekte', url: '/projects', icon: 'bi-folder-fill', section: 'Mitglieder & Projekte', keywords: ['planung'] },
];

test('normalize: Groß/klein und Akzente spielen keine Rolle', () => {
    assert.equal(normalize('  Projektübersicht '), 'projektubersicht');
    assert.equal(normalize('ÄÖÜ'), 'aou');
});

test('leere Eingabe liefert keine Treffer', () => {
    assert.deepEqual(rankNavigationEntries(entries, '   '), []);
});

test('Name am Anfang vor Name enthalten vor Abschnitt vor Stichwort', () => {
    const hits = rankNavigationEntries(entries, 'pro');
    assert.deepEqual(hits.map((h) => h.entry.url), ['/evaluations/project-members', '/projects']);

    const mixed = rankNavigationEntries(entries, 'planung');
    assert.deepEqual(mixed.map((h) => [h.entry.url, h.keyword]), [['/budget', 'planung'], ['/projects', 'planung']]);

    const order = rankNavigationEntries(
        [
            { label: 'Alpha', url: '/a', icon: '', section: 'Kasse', keywords: [] },
            { label: 'Beta', url: '/b', icon: '', section: '', keywords: ['kasse'] },
            { label: 'Kassenbericht', url: '/c', icon: '', section: '', keywords: [] },
            { label: 'Die Kasse', url: '/d', icon: '', section: '', keywords: [] },
        ],
        'kasse'
    );
    assert.deepEqual(order.map((h) => h.entry.url), ['/c', '/d', '/a', '/b']);
});

test('Stichwort-Treffer nennen das Stichwort, Namenstreffer nicht', () => {
    const [hit] = rankNavigationEntries(entries, 'kassab');
    assert.equal(hit.entry.url, '/finances');
    assert.equal(hit.keyword, 'kassabuch');

    const [byName] = rankNavigationEntries(entries, 'kas');
    assert.equal(byName.keyword, null);
});

test('Umlaute ohne Punkte finden', () => {
    const [hit] = rankNavigationEntries(entries, 'ubersicht');
    assert.equal(hit.entry.url, '/evaluations/project-members');
});

test('readRecentUrls verträgt kaputten Speicher', () => {
    assert.deepEqual(readRecentUrls(null), []);
    assert.deepEqual(readRecentUrls('{'), []);
    assert.deepEqual(readRecentUrls('{"a":1}'), []);
    assert.deepEqual(readRecentUrls('["/a", 3, null, "/b"]'), ['/a', '/b']);
});

test('resolveRecentEntries zeigt nur, was noch in der Leiste steht', () => {
    const resolved = resolveRecentEntries(entries, ['/roles', '/budget', '/finances']);
    assert.deepEqual(resolved.map((e) => e.url), ['/budget', '/finances']);
});
```

`package.json` – in `scripts` ergänzen:

```json
    "test:js": "node --test tests/js/",
```

- [ ] **Step 2: Test laufen lassen – muss rot sein**

Run (auf dem Host): `npm run test:js`
Expected: FAIL mit `Cannot find module '../../public/js/navigation-search-rank.js'`.

- [ ] **Step 3: Rank-Modul anlegen**

`public/js/navigation-search-rank.js`:

```js
/**
 * Reine Suchlogik der Schnellsuche - ohne DOM, damit sie unter `node --test` prüfbar ist
 * (tests/js/navigation-search-rank.test.mjs). Im Browser hängt sie an
 * window.NavigationSearchRank, in Node an module.exports.
 */
(function (global) {
    'use strict';

    function normalize(text) {
        return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }

    /**
     * Reihenfolge: Name beginnt mit der Eingabe, Name enthält sie, Abschnitt enthält sie,
     * ein Stichwort enthält sie. Innerhalb einer Stufe bleibt die Reihenfolge der Leiste.
     */
    function rankNavigationEntries(entries, query) {
        var needle = normalize(query);
        if (needle === '') {
            return [];
        }

        var hits = [];
        entries.forEach(function (entry, index) {
            var label = normalize(entry.label);
            var score = null;
            var keyword = null;

            if (label.indexOf(needle) === 0) {
                score = 0;
            } else if (label.indexOf(needle) !== -1) {
                score = 1;
            } else if (normalize(entry.section).indexOf(needle) !== -1) {
                score = 2;
            } else {
                for (var i = 0; i < entry.keywords.length; i++) {
                    if (normalize(entry.keywords[i]).indexOf(needle) !== -1) {
                        score = 3;
                        keyword = entry.keywords[i];
                        break;
                    }
                }
            }

            if (score !== null) {
                hits.push({ entry: entry, keyword: keyword, score: score, index: index });
            }
        });

        hits.sort(function (a, b) {
            return a.score - b.score || a.index - b.index;
        });

        return hits.map(function (hit) {
            return { entry: hit.entry, keyword: hit.keyword };
        });
    }

    function readRecentUrls(raw) {
        try {
            var parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed)
                ? parsed.filter(function (url) { return typeof url === 'string'; })
                : [];
        } catch (e) {
            return [];
        }
    }

    /** Ein gemerkter Pfad erscheint nur, solange die Leiste ihn noch für diese Rolle zeigt. */
    function resolveRecentEntries(entries, urls) {
        var result = [];
        urls.forEach(function (url) {
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].url === url) {
                    result.push(entries[i]);
                    return;
                }
            }
        });
        return result;
    }

    var api = {
        normalize: normalize,
        rankNavigationEntries: rankNavigationEntries,
        readRecentUrls: readRecentUrls,
        resolveRecentEntries: resolveRecentEntries,
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        global.NavigationSearchRank = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 4: JS-Test laufen lassen**

Run: `npm run test:js`
Expected: PASS (7 Tests).

- [ ] **Step 5: Layout-Nahtstelle für die Suche erweitern (failing)**

In `tests/Feature/NavigationLayoutSeamFeatureTest.php` im ersten Test ergänzen:

```php
        $this->assertStringContainsString('id="nav-search-modal"', $body);
        $this->assertMatchesRegularExpression('/<button[^>]*data-nav-search-open[^>]*aria-controls="nav-search-modal"/', $body);
        $this->assertMatchesRegularExpression(
            '/<script src="\/js\/navigation-search-rank\.js"><\/script>\s*<script src="\/js\/navigation-search\.js"><\/script>/',
            $body
        );
```

Run: `ddev php vendor/bin/phpunit --filter NavigationLayoutSeamFeatureTest 2>&1 | tail -8`
Expected: FAIL.

- [ ] **Step 6: Modal-Template**

`templates/partials/navigation/search_modal.twig`:

```twig
{# Schnellsuche (Strg+K). Die Trefferliste baut navigation-search.js aus den Links der
   Seitenleiste - gefunden wird damit nur, was die Rolle ohnehin sehen darf. #}
<div class="modal fade nav-search"
     id="nav-search-modal"
     tabindex="-1"
     aria-labelledby="nav-search-label"
     aria-hidden="true">
    <div class="modal-dialog nav-search__dialog">
        <div class="modal-content">
            <div class="nav-search__field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="nav-search-input" id="nav-search-label">Seite suchen</label>
                <input type="text"
                       class="nav-search__input"
                       id="nav-search-input"
                       placeholder="Seite oder Stichwort suchen …"
                       autocomplete="off"
                       spellcheck="false"
                       role="combobox"
                       aria-expanded="true"
                       aria-controls="nav-search-results"
                       aria-autocomplete="list">
                <kbd class="nav-search__kbd">Esc</kbd>
            </div>
            <div class="nav-search__body">
                <ul class="nav-search__results"
                    id="nav-search-results"
                    role="listbox"
                    aria-label="Suchergebnisse"></ul>
                <p class="nav-search__empty d-none" data-nav-search-empty>Keine Seite gefunden.</p>
            </div>
            <div class="nav-search__footer">
                <span><kbd>↑</kbd> <kbd>↓</kbd> auswählen</span>
                <span><kbd>Enter</kbd> öffnen</span>
                <span><kbd>Esc</kbd> schließen</span>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 7: Layout ergänzen**

In `templates/layout.twig`:

1. In `<div class="app-topbar__actions">` **vor** dem `user_menu`-Include:

```twig
                        <button type="button"
                                class="app-topbar__search"
                                data-nav-search-open
                                aria-haspopup="dialog"
                                aria-controls="nav-search-modal"
                                title="Seite suchen (Strg+K)">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <span class="app-topbar__search-label">Seite suchen …</span>
                            <kbd class="app-topbar__search-kbd">Strg K</kbd>
                        </button>
```

2. Direkt nach dem `sidebar.twig`-Include:

```twig
            {{ include("partials/navigation/search_modal.twig", [], false) }}
```

3. Hinter `navigation.js`:

```twig
            <script src="{{ asset_path('/js/navigation-search-rank.js') }}"></script>
            <script src="{{ asset_path('/js/navigation-search.js') }}"></script>
```

- [ ] **Step 8: Such-Skript**

`public/js/navigation-search.js`:

```js
/**
 * Schnellsuche über die Seiten der Seitenleiste (Strg+K / ⌘+K oder Suchknopf).
 *
 * Datenquelle sind die gerenderten Leistenlinks: was der NavigationBuilder für diese
 * Rolle nicht ausgibt, steht nicht im HTML und kann deshalb auch nicht gefunden werden.
 * Die Suchlogik selbst liegt in navigation-search-rank.js.
 */
document.addEventListener('DOMContentLoaded', function () {
    var modalElement = document.getElementById('nav-search-modal');
    var sidebar = document.getElementById('app-sidebar');
    var rank = window.NavigationSearchRank;
    if (!modalElement || !sidebar || !rank) {
        return;
    }

    var input = document.getElementById('nav-search-input');
    var list = document.getElementById('nav-search-results');
    var empty = modalElement.querySelector('[data-nav-search-empty]');
    var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    var RECENT_KEY = 'chormanager.nav.recent';

    var entries = [];
    var shown = [];
    var selected = 0;

    function readEntries() {
        return Array.prototype.map.call(sidebar.querySelectorAll('a.app-sidebar__link'), function (link) {
            var section = link.closest('[data-nav-section-title]');
            var label = link.querySelector('.app-sidebar__label');
            return {
                label: label ? label.textContent.trim() : '',
                url: link.getAttribute('href'),
                icon: link.getAttribute('data-nav-icon') || 'bi-link-45deg',
                section: section ? section.getAttribute('data-nav-section-title') : '',
                keywords: (link.getAttribute('data-nav-keywords') || '').split('|').filter(Boolean),
            };
        });
    }

    function recentEntries() {
        var raw = null;
        try {
            raw = window.localStorage.getItem(RECENT_KEY);
        } catch (e) {
            raw = null;
        }
        return rank.resolveRecentEntries(entries, rank.readRecentUrls(raw));
    }

    function asHits(list) {
        return list.map(function (entry) {
            return { entry: entry, keyword: null };
        });
    }

    function groups() {
        if (input.value.trim() !== '') {
            return [{ title: 'Treffer', hits: rank.rankNavigationEntries(entries, input.value) }];
        }
        var result = [];
        var recent = recentEntries();
        if (recent.length > 0) {
            result.push({ title: 'Zuletzt besucht', hits: asHits(recent) });
        }
        result.push({ title: 'Alle Seiten', hits: asHits(entries) });
        return result;
    }

    function option(hit, index) {
        var item = document.createElement('li');
        item.id = 'nav-search-option-' + index;
        item.className = 'nav-search__option';
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', index === selected ? 'true' : 'false');
        item.setAttribute('data-index', String(index));

        var icon = document.createElement('i');
        icon.className = 'bi ' + hit.entry.icon;
        icon.setAttribute('aria-hidden', 'true');

        var label = document.createElement('span');
        label.className = 'nav-search__label';
        label.textContent = hit.entry.label;
        if (hit.keyword) {
            var keyword = document.createElement('span');
            keyword.className = 'nav-search__keyword';
            keyword.textContent = 'Stichwort: ' + hit.keyword;
            label.appendChild(document.createTextNode(' '));
            label.appendChild(keyword);
        }

        var section = document.createElement('span');
        section.className = 'nav-search__section';
        section.textContent = hit.entry.section;

        item.appendChild(icon);
        item.appendChild(label);
        item.appendChild(section);
        return item;
    }

    function render() {
        var visibleGroups = groups();
        shown = [];
        visibleGroups.forEach(function (group) {
            shown = shown.concat(group.hits);
        });
        selected = Math.min(selected, Math.max(shown.length - 1, 0));

        list.replaceChildren();
        var index = 0;
        visibleGroups.forEach(function (group) {
            if (group.hits.length === 0) {
                return;
            }
            var heading = document.createElement('li');
            heading.className = 'nav-search__group';
            heading.setAttribute('role', 'presentation');
            heading.textContent = group.title;
            list.appendChild(heading);
            group.hits.forEach(function (hit) {
                list.appendChild(option(hit, index));
                index++;
            });
        });

        empty.classList.toggle('d-none', shown.length > 0);
        if (shown.length > 0) {
            input.setAttribute('aria-activedescendant', 'nav-search-option-' + selected);
            var current = document.getElementById('nav-search-option-' + selected);
            if (current) {
                current.scrollIntoView({ block: 'nearest' });
            }
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function open() {
        // Über einem anderen offenen Dialog (z. B. dem Newsletter-Editor) stapelt Bootstrap
        // Modals nicht sauber - dann bleibt das Kürzel wirkungslos.
        if (document.querySelector('.modal.show:not(#nav-search-modal)')) {
            return;
        }
        var offcanvas = bootstrap.Offcanvas.getInstance(sidebar);
        if (offcanvas) {
            offcanvas.hide();
        }
        entries = readEntries();
        input.value = '';
        selected = 0;
        render();
        modal.show();
    }

    function go(index) {
        var hit = shown[index];
        if (hit) {
            window.location.assign(hit.entry.url);
        }
    }

    modalElement.addEventListener('shown.bs.modal', function () {
        input.focus();
    });

    input.addEventListener('input', function () {
        selected = 0;
        render();
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (shown.length === 0) {
                return;
            }
            var step = event.key === 'ArrowDown' ? 1 : -1;
            selected = (selected + step + shown.length) % shown.length;
            render();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(selected);
        }
    });

    list.addEventListener('click', function (event) {
        var item = event.target.closest('[role="option"]');
        if (item) {
            go(Number(item.getAttribute('data-index')));
        }
    });

    document.querySelectorAll('[data-nav-search-open]').forEach(function (button) {
        button.addEventListener('click', open);
    });

    document.addEventListener('keydown', function (event) {
        var isShortcut = (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey
            && event.key.toLowerCase() === 'k';
        if (!isShortcut) {
            return;
        }
        if (modalElement.classList.contains('show')) {
            event.preventDefault();
            modal.hide();
            return;
        }
        if (document.querySelector('.modal.show')) {
            return;
        }
        event.preventDefault();
        open();
    });
});
```

- [ ] **Step 9: CSS für Suchknopf und Modal**

Am Ende des Abschnitts „Seitenleiste" in `public/css/style.css`:

```css
/* ============================================================
   Schnellsuche
   ============================================================ */
.app-topbar__search {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin-right: 0.75rem;
    padding: 0.35rem 0.75rem;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 0.375rem;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.8);
}

.app-topbar__search:hover,
.app-topbar__search:focus-visible {
    background: rgba(255, 255, 255, 0.14);
    color: #ffffff;
}

.app-topbar__search-label,
.app-topbar__search-kbd {
    display: none;
}

.app-topbar__search-kbd {
    padding: 0 0.3rem;
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 0.25rem;
    background: transparent;
    color: inherit;
    font-size: 0.7rem;
}

@media (min-width: 992px) {
    .app-topbar__search {
        min-width: 15rem;
    }

    .app-topbar__search-label {
        display: inline;
        flex: 1;
        text-align: left;
    }

    .app-topbar__search-kbd {
        display: inline;
    }
}

.nav-search__dialog {
    max-width: 36rem;
    margin-top: 10vh;
}

.nav-search__field {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--bs-border-color);
}

.nav-search__input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    font-size: 1.05rem;
}

.nav-search__body {
    max-height: min(60vh, 26rem);
    overflow-y: auto;
    padding: 0.4rem;
}

.nav-search__results {
    margin: 0;
    padding: 0;
    list-style: none;
}

.nav-search__group {
    padding: 0.6rem 0.6rem 0.25rem;
    color: var(--bs-secondary-color);
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.nav-search__option {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.5rem 0.6rem;
    border-radius: 0.375rem;
    cursor: pointer;
}

.nav-search__option:hover,
.nav-search__option[aria-selected="true"] {
    background: rgba(var(--theme-primary-rgb), 0.14);
}

.nav-search__label {
    flex: 1;
    min-width: 0;
}

.nav-search__keyword,
.nav-search__section {
    color: var(--bs-secondary-color);
    font-size: 0.82rem;
}

.nav-search__keyword {
    font-style: italic;
}

.nav-search__empty {
    margin: 0;
    padding: 1rem;
    color: var(--bs-secondary-color);
}

.nav-search__footer {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 0.5rem 1rem;
    border-top: 1px solid var(--bs-border-color);
    color: var(--bs-secondary-color);
    font-size: 0.78rem;
}

.nav-search kbd {
    padding: 0 0.3rem;
    border: 1px solid var(--bs-border-color);
    background: transparent;
    color: inherit;
    font-size: 0.72rem;
}
```

- [ ] **Step 10: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "NavigationLayoutSeamFeatureTest|NavigationMenuRenderFeatureTest" 2>&1 | tail -8 && npm run test:js`
Expected: PASS.

- [ ] **Step 11: twigcs und Commit**

```bash
ddev composer twigcs
git add public/js/navigation-search-rank.js public/js/navigation-search.js templates/partials/navigation/search_modal.twig templates/layout.twig public/css/style.css package.json tests/js/navigation-search-rank.test.mjs tests/Feature/NavigationLayoutSeamFeatureTest.php
git commit -m "feat(navigation): Schnellsuche über die Seiten der Leiste (Strg+K)"
```

---

### Task 5: Browser-Abdeckung (e2e)

**Files:**
- Modify: `tests/e2e/steps/navigation.mjs` (komplett ersetzen)
- Create: `tests/e2e/scenarios/navigation-sidebar.e2e.test.mjs`

**Interfaces:**
- Consumes: Selektoren aus Task 2 und 4.
- Produces: `openMainNavigation(page)`, `visibleNavLinkCount(page)` (Signatur unverändert – `role-authorization` nutzt sie).

Vor dem Schreiben den Skill `/e2e-scenario` laden und seinen Ablauf befolgen.

- [ ] **Step 1: Navigations-Bausteine umstellen**

`tests/e2e/steps/navigation.mjs`:

```js
// Bausteine für die Seitenleiste (templates/partials/navigation/sidebar.twig).
//
// Selektoren:
//  - Menüknopf in der Kopfleiste: button[data-nav-toggle]
//  - Leiste: aside#app-sidebar (ab 992 px fest sichtbar, darunter Bootstrap-Offcanvas)

import { expect } from '@playwright/test';

const TOGGLE = 'button[data-nav-toggle]';
const SIDEBAR = '#app-sidebar';

/**
 * Sorgt dafür, dass die Leistenlinks sichtbar sind.
 *
 * Unterhalb von Bootstraps lg-Breakpoint (mobiler Lauf, E2E_VIEWPORT=mobile) steckt die
 * Leiste im Offcanvas: die Links sind im DOM, aber unsichtbar. Prüfungen auf ":visible"
 * wären dort sonst still wirkungslos - ein Test, der "kein verbotener Link sichtbar"
 * erwartet, wäre grün, ohne irgendetwas zu prüfen.
 *
 * Am Desktop ist die Leiste immer sichtbar; die Funktion tut dann nichts.
 */
export async function openMainNavigation(page) {
    const sidebar = page.locator(SIDEBAR);
    if (await sidebar.isVisible()) {
        return;
    }

    await page.locator(TOGGLE).click();
    // Bootstrap animiert das Hereinfahren; erst mit "show" stimmen die Sichtbarkeiten.
    await expect(sidebar).toHaveClass(/\bshow\b/);
    await expect(sidebar).toBeVisible();
}

/** Anzahl sichtbarer Leistenlinks - Absicherung gegen still leere Navigations-Prüfungen. */
export async function visibleNavLinkCount(page) {
    return page.locator(`${SIDEBAR} a:visible`).count();
}
```

- [ ] **Step 2: Szenario schreiben**

`tests/e2e/scenarios/navigation-sidebar.e2e.test.mjs`:

```js
import { test, expect } from '@playwright/test';
import { createMember } from '../steps/members.mjs';
import { login } from '../steps/auth.mjs';
import { setMemberPassword } from '../steps/authz.mjs';
import { newBrowserContext, MOBILE } from '../steps/browser.mjs';
import { openMainNavigation } from '../steps/navigation.mjs';

const PLAIN_MEMBER = {
    firstName: 'Leiste',
    lastName: 'Mitglied',
    email: 'nav.mitglied@chor.local', // naming:ascii
    role: 'Mitglied',
    group: 'Alt',
    sub: 'Alt 1',
};
const PLAIN_PASSWORD = 'LeistePass1234!';

const isMac = process.platform === 'darwin';
const SHORTCUT = isMac ? 'Meta+k' : 'Control+k';

test.describe('Seitenleiste und Schnellsuche', () => {
    test('Admin: Strg+K findet Stimmgruppen über das Stichwort "sopran"', async ({ page }) => {
        await page.goto('/dashboard');
        await page.keyboard.press(SHORTCUT);
        const input = page.locator('#nav-search-input');
        await expect(input).toBeFocused();

        await input.fill('sopran');
        const first = page.locator('#nav-search-results [role="option"]').first();
        await expect(first).toContainText('Stimmgruppen');
        await expect(first).toContainText('Stichwort: sopran');

        await input.press('Enter');
        await expect(page).toHaveURL(/\/voice-groups$/);
    });

    test('Admin: leeres Suchfeld zeigt zuletzt besuchte Seiten', async ({ page }) => {
        await page.goto('/events');
        await page.goto('/dashboard');
        await page.locator('[data-nav-search-open]').click();

        const options = page.locator('#nav-search-results');
        await expect(options).toContainText('Zuletzt besucht');
        await expect(page.locator('#nav-search-results [role="option"]').first()).toContainText('Start');
        await page.keyboard.press('Escape');
        await expect(page.locator('#nav-search-modal')).toBeHidden();
    });

    test('Mitglied findet keine Seite, für die das Recht fehlt', async ({ page, browser }) => {
        await createMember(page, PLAIN_MEMBER);
        setMemberPassword(PLAIN_MEMBER.email, PLAIN_PASSWORD);

        const context = await newBrowserContext(browser);
        await context.clearCookies();
        const memberPage = await context.newPage();
        try {
            await login(memberPage, { email: PLAIN_MEMBER.email, password: PLAIN_PASSWORD });
            await memberPage.goto('/dashboard');
            await memberPage.locator('[data-nav-search-open]').click();
            await memberPage.locator('#nav-search-input').fill('sopran');
            await expect(memberPage.locator('[data-nav-search-empty]')).toBeVisible();
            await expect(memberPage.locator('#nav-search-results [role="option"]')).toHaveCount(0);
        } finally {
            await context.close();
        }
    });

    test('Strg+K bei offenem anderen Modal öffnet keine Suche', async ({ page }) => {
        await page.goto('/users');
        await page.click('[data-bs-target="#addUserModal"]');
        await expect(page.locator('#addUserModal')).toBeVisible();

        await page.keyboard.press(SHORTCUT);
        await expect(page.locator('#nav-search-modal')).toBeHidden();
    });

    test('Desktop: Einklappen und Administration überleben ein Neuladen', async ({ page }) => {
        test.skip(MOBILE, 'Einklappen zur Symbolleiste gibt es nur am Desktop.');

        await page.goto('/dashboard');
        const administration = page.locator('[data-nav-section="administration"] .app-sidebar__items');
        await expect(administration).toBeHidden();

        await page.locator('[data-nav-fold="administration"]').click();
        await expect(administration).toBeVisible();

        await page.locator('button[data-nav-toggle]').click();
        await expect(page.locator('html')).toHaveClass(/\bnav-collapsed\b/);
        await expect(page.locator('#app-sidebar .app-sidebar__label').first()).toBeHidden();

        await page.reload();
        await expect(page.locator('html')).toHaveClass(/\bnav-collapsed\b/);
        // In der Symbolleiste gibt es keine Überschrift zum Aufklappen - Backups muss sichtbar sein.
        await expect(page.locator('#app-sidebar a[href="/backups"]')).toBeVisible();

        await page.locator('button[data-nav-toggle]').click();
        await page.reload();
        await expect(page.locator('html')).not.toHaveClass(/\bnav-collapsed\b/);
        await expect(administration).toBeVisible();

        // Zustand für die anderen Szenarien zurücksetzen.
        await page.locator('[data-nav-fold="administration"]').click();
        await expect(administration).toBeHidden();
    });

    test('Handy: Menüknopf öffnet die Leiste, ein Link navigiert', async ({ page }) => {
        test.skip(!MOBILE, 'Nur im mobilen Lauf.');

        await page.goto('/dashboard');
        await expect(page.locator('#app-sidebar')).toBeHidden();
        await openMainNavigation(page);

        await page.locator('#app-sidebar a[href="/events"]').click();
        await expect(page).toHaveURL(/\/events$/);
        await expect(page.locator('#app-sidebar')).toBeHidden();
    });

    test('Handy: Vergrößern bei offener Leiste hinterlässt keinen Backdrop', async ({ page }) => {
        test.skip(!MOBILE, 'Nur im mobilen Lauf.');

        await page.goto('/dashboard');
        await openMainNavigation(page);
        await page.setViewportSize({ width: 1280, height: 900 });

        await expect(page.locator('.offcanvas-backdrop')).toHaveCount(0);
        await expect(page.locator('#app-sidebar a[href="/events"]')).toBeVisible();
    });
});
```

Hinweis: Prüfe vor dem Lauf in `tests/e2e/steps/members.mjs`, dass `createMember` die Felder `role`, `group`, `sub` so erwartet wie in `ROLE_MEMBERS` – das Objekt oben folgt genau diesem Format. Gibt es die Untergruppe „Alt 1" nicht, aus `tests/e2e/data/fixtures.mjs` (`SUB_VOICES`) eine vorhandene wählen.

- [ ] **Step 3: Desktop-Lauf**

Run (auf dem Host, leert die Dev-DB): `npx playwright test --config tests/e2e/playwright.config.mjs scenarios/navigation-sidebar.e2e.test.mjs scenarios/role-authorization.e2e.test.mjs`
Expected: PASS (Mobil-Tests als skipped).

- [ ] **Step 4: Mobil-Lauf**

Run: `npm run e2e:mobile -- scenarios/navigation-sidebar.e2e.test.mjs scenarios/role-authorization.e2e.test.mjs`
Expected: PASS (Desktop-Einklappen als skipped). Falls `run-mobile.mjs` keine Dateiargumente durchreicht: `E2E_VIEWPORT=mobile npx playwright test --config tests/e2e/playwright.config.mjs scenarios/navigation-sidebar.e2e.test.mjs scenarios/role-authorization.e2e.test.mjs` (PowerShell: `$env:E2E_VIEWPORT='mobile'; npx …`).

- [ ] **Step 5: Dev-Daten zurückholen und Commit**

```bash
ddev composer seed:dev
git add tests/e2e/steps/navigation.mjs tests/e2e/scenarios/navigation-sidebar.e2e.test.mjs
git commit -m "test(navigation): e2e für Seitenleiste, Einklappen und Schnellsuche"
```

---

### Task 6: Klickpfade in der Hilfe

**Files:**
- Modify: `help/*/docs/*.md` (alle Treffer der Ersetzungstabelle)
- Test: `tests/Feature/HelpNavigationPathsFeatureTest.php` (neu)

**Interfaces:** –

- [ ] **Step 1: Failing Test**

`tests/Feature/HelpNavigationPathsFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die Hilfe beschreibt Klickpfade durch das Menü. Nach dem Umbau auf die Seitenleiste
 * gibt es die Gruppen "Bereiche", "Verwaltung" und "Auswertungen" nicht mehr - ein
 * Pfad dorthin schickt Leserinnen und Leser ins Leere.
 */
class HelpNavigationPathsFeatureTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function helpDocs(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/help/*/docs/*.md');
        $this->assertNotEmpty($files);

        return $files;
    }

    public function testNoHelpDocNamesARemovedMenuGroup(): void
    {
        foreach ($this->helpDocs() as $file) {
            $content = (string) file_get_contents($file);
            // "Verwaltung → OpenID Connect" in sso.md ist Nextclouds eigenes Menü, nicht unseres.
            $content = str_replace('**Verwaltung → OpenID Connect**', '', $content);

            $this->assertDoesNotMatchRegularExpression(
                '/\*\*(Bereiche|Verwaltung|Auswertungen) →/u',
                $content,
                basename($file) . ' nennt eine entfernte Menügruppe.'
            );
        }
    }

    public function testNoHelpDocUsesARenamedMenuEntry(): void
    {
        $renamed = [
            'Mitgliederverwaltung**', 'Meine Newsletter**', 'Meine Projekte**', 'Projektmitglieder**',
            'Backup-Verwaltung**', '→ Downloads**', '→ Rollen**', 'Termine → Anwesenheit**',
        ];

        foreach ($this->helpDocs() as $file) {
            $content = (string) file_get_contents($file);
            foreach ($renamed as $needle) {
                $this->assertStringNotContainsString($needle, $content, basename($file) . " nennt noch {$needle}");
            }
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen – muss rot sein**

Run: `ddev php vendor/bin/phpunit --filter HelpNavigationPathsFeatureTest 2>&1 | tail -15`
Expected: FAIL mit den Dateinamen.

- [ ] **Step 3: Pfade ersetzen**

Ersetzungstabelle (jeweils innerhalb `**…**`, auch Fortsetzungen wie `→ Auswertung` oder `→ Details` bleiben erhalten):

| alt | neu |
|---|---|
| `Auswertungen → Anmeldungen` | `Termine → Anmelde-Auswertung` |
| `Auswertungen → Anwesenheitsquoten` | `Termine → Anwesenheitsquoten` |
| `Bereiche → Budget` | `Finanzen → Budget` |
| `Bereiche → Downloads` | `Noten & Dateien → Probenmaterial` |
| `Bereiche → Kassa` | `Finanzen → Kassa` |
| `Bereiche → Meine Newsletter` | `Kommunikation → Newsletter-Archiv` |
| `Bereiche → Meine Projekte` | `Mitglieder & Projekte → Projektbesetzung` |
| `Bereiche → Mitgliederverwaltung` | `Mitglieder & Projekte → Mitglieder` |
| `Bereiche → Newsletter` | `Kommunikation → Newsletter versenden` |
| `Bereiche → Projektmitglieder` | `Mitglieder & Projekte → Projektübersicht` |
| `Bereiche → Repertoire` | `Noten & Dateien → Repertoire` |
| `Bereiche → Sponsoring` | `Finanzen → Sponsoring` |
| `Termine → Anwesenheit**` | `Termine → Anwesenheit erfassen**` |
| `Verwaltung → App-Einstellungen` | `Administration → App-Einstellungen` |
| `Verwaltung → Dateien` | `Noten & Dateien → Dateien` |
| `Verwaltung → Projekte` | `Mitglieder & Projekte → Projekte` |
| `Verwaltung → Rollen` | `Administration → Rollen & Rechte` |
| `Verwaltung → Termin-Typen` | `Administration → Termin-Typen` |

Nicht ersetzen: `**Verwaltung → OpenID Connect**` in `help/sso/docs/sso.md` (Nextcloud).

Ersetzen per Skript (auf dem Host, Git Bash), danach jeden Diff lesen:

```bash
cd /d/Proggen/ChorManager
for f in help/*/docs/*.md; do
  sed -i \
    -e 's/Auswertungen → Anmeldungen/Termine → Anmelde-Auswertung/g' \
    -e 's/Auswertungen → Anwesenheitsquoten/Termine → Anwesenheitsquoten/g' \
    -e 's/Bereiche → Budget/Finanzen → Budget/g' \
    -e 's/Bereiche → Downloads/Noten \& Dateien → Probenmaterial/g' \
    -e 's/Bereiche → Kassa/Finanzen → Kassa/g' \
    -e 's/Bereiche → Meine Newsletter/Kommunikation → Newsletter-Archiv/g' \
    -e 's/Bereiche → Meine Projekte/Mitglieder \& Projekte → Projektbesetzung/g' \
    -e 's/Bereiche → Mitgliederverwaltung/Mitglieder \& Projekte → Mitglieder/g' \
    -e 's/Bereiche → Newsletter/Kommunikation → Newsletter versenden/g' \
    -e 's/Bereiche → Projektmitglieder/Mitglieder \& Projekte → Projektübersicht/g' \
    -e 's/Bereiche → Repertoire/Noten \& Dateien → Repertoire/g' \
    -e 's/Bereiche → Sponsoring/Finanzen → Sponsoring/g' \
    -e 's/Termine → Anwesenheit\*\*/Termine → Anwesenheit erfassen**/g' \
    -e 's/Verwaltung → App-Einstellungen/Administration → App-Einstellungen/g' \
    -e 's/Verwaltung → Dateien/Noten \& Dateien → Dateien/g' \
    -e 's/Verwaltung → Projekte/Mitglieder \& Projekte → Projekte/g' \
    -e 's/Verwaltung → Rollen/Administration → Rollen \& Rechte/g' \
    -e 's/Verwaltung → Termin-Typen/Administration → Termin-Typen/g' \
    "$f"
done
git diff --stat help
```

Danach von Hand nachziehen:
- `help/newsletter/docs/newsletter.md:14` – „Klicke in der Navigation auf" → „Klicke in der Seitenleiste auf".
- `help/sponsoring/docs/sponsoring.md:61` – „Klicke oben in der Navigation auf" → „Klicke in der Seitenleiste auf".
- `help/repertoire/docs/repertoire.md:6,13` – Menüpunkt **Downloads** → **Probenmaterial**.
- Sätze, die einen umbenannten Menüpunkt allein nennen (z. B. „Siehst du den Menüpunkt "Newsletter" nicht"), auf den neuen Namen umstellen. Finden mit:

```bash
grep -rnE "Menüpunkt[e]? (\*\*|\")?(Mitgliederverwaltung|Downloads|Meine Projekte|Projektmitglieder|Meine Newsletter|Newsletter|Backup-Verwaltung|Rollen|Anwesenheit)(\*\*|\")?" help
```

Rechte-Namen in Anführungszeichen (z. B. **"Newsletter verwalten"**) bleiben – sie benennen Rechte, keine Menüpunkte. Keine Rollennamen einführen.

- [ ] **Step 4: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "HelpNavigationPathsFeatureTest|HelpFeatureTest|NewsletterHelpDocFeatureTest" 2>&1 | tail -10`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add help tests/Feature/HelpNavigationPathsFeatureTest.php
git commit -m "docs(help): Klickpfade auf die Seitenleiste umgestellt"
```

---

### Task 7: Abschluss

- [ ] **Step 1: Volle Suite parallel**

Run: `ddev composer test:parallel 2>&1 | tail -20`
Expected: alle grün. Ein Test, der parallel rot und allein grün ist, hängt an `$_SESSION` oder einer Twig-Funktion – im eigenen `setUp()` reparieren (siehe `/test-runs`).

- [ ] **Step 2: Linter**

Run: `ddev composer phpcs && ddev composer twigcs && npm run test:js`
Expected: keine Befunde.

- [ ] **Step 3: Restverweise suchen**

```bash
grep -rn "menu.twig\|navbarsExampleDefault\|divider_before\|'type' => 'group'" src templates public tests --include=*.php --include=*.twig --include=*.js --include=*.mjs
```

Expected: keine Treffer.

- [ ] **Step 4: Squash und Übergabe**

Den Skill `/git-commit` laden und befolgen (Squash der Branch-Commits inkl. Spec und Plan, deutsche Commit-Nachricht mit Begründung und Belegen, Fast-Forward nach `main`). Push nur nach ausdrücklichem Ja.

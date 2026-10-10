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
                '/evaluations/project-members' => 'Besetzung',
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

    public function testStorageItemFollowsTheStorageRight(): void
    {
        $administration = $this->section($this->build(['can_manage_storage' => true]), 'administration');

        $this->assertNotNull($administration);
        $this->assertSame(['/storage'], array_column($administration['items'], 'url'));
        $this->assertNotContains('/storage', $this->urls($this->build(['can_manage_backups' => true])));
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

    public function testUnknownNavKeyFallsBackToThePath(): void
    {
        $tree = (new NavigationBuilder())->build(new NavigationContext([], [], '/events', 'not_a_nav_key'));

        $this->assertTrue($this->entry($tree, '/events')['active']);
    }
}

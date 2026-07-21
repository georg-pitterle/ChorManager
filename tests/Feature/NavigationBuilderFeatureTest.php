<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use PHPUnit\Framework\TestCase;

class NavigationBuilderFeatureTest extends TestCase
{
    /**
     * @param array<string,bool> $permissions
     * @param array<string,bool> $modules
     */
    private function build(array $permissions, array $modules = [], string $path = '/dashboard'): array
    {
        $ctx = new NavigationContext($permissions, $modules, $path);
        return (new NavigationBuilder())->build($ctx);
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     */
    private function group(array $tree, string $label): ?array
    {
        foreach ($tree as $node) {
            if ($node['type'] === 'group' && $node['label'] === $label) {
                return $node;
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     */
    private function urls(array $tree): array
    {
        $urls = [];
        foreach ($tree as $node) {
            if ($node['type'] === 'link') {
                $urls[] = $node['url'];
            } else {
                foreach ($node['items'] as $item) {
                    $urls[] = $item['url'];
                }
            }
        }
        return $urls;
    }

    public function testPlainMemberSeesOnlyPublicItems(): void
    {
        $tree = $this->build([], ['registration' => false]);
        $urls = $this->urls($tree);

        $this->assertContains('/dashboard', $urls);
        $this->assertContains('/events', $urls);
        $this->assertContains('/downloads', $urls);
        $this->assertContains('/evaluations/project-members', $urls);

        $this->assertNotContains('/attendance', $urls);
        $this->assertNotContains('/registrations', $urls);
        $this->assertNotContains('/users', $urls);
        $this->assertNotContains('/roles', $urls);
        $this->assertNotContains('/backups', $urls);

        // Keine leeren Admin-Gruppen im Baum.
        $this->assertNull($this->group($tree, 'Verwaltung'));
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
        // '/attendance' is gated on can_manage_attendance/can_manage_users only (RoleMiddleware),
        // so a voice-rep-only context must not see it.
        $this->assertNotContains('/attendance', $urls);
    }

    /**
     * Pins the nav<->route invariant for '/attendance': the nav predicate must match the
     * RoleMiddleware gate (can_manage_attendance || can_manage_users) exactly and must not
     * be widened to admit can_manage_own_voice_group. The seeded Kassier role (hierarchy_level 60)
     * has can_manage_own_voice_group = 1 but can_manage_attendance = 0; if this predicate were
     * widened again, Kassier would see the "Anwesenheit" link and get a 403 on click.
     */
    public function testAttendanceLinkMatchesRouteGateNotVoiceGroupFlag(): void
    {
        $voiceGroupOnly = $this->urls($this->build([
            'can_manage_own_voice_group' => true,
            'can_manage_attendance' => false,
        ]));
        $this->assertNotContains('/attendance', $voiceGroupOnly);

        $attendanceManager = $this->urls($this->build([
            'can_manage_attendance' => true,
        ]));
        $this->assertContains('/attendance', $attendanceManager);
    }

    public function testBackupOnlyRoleSeesVerwaltungWithBackupItem(): void
    {
        $tree = $this->build(['can_manage_backups' => true]);
        $verwaltung = $this->group($tree, 'Verwaltung');

        $this->assertNotNull($verwaltung, 'Verwaltung-Gruppe muss fuer Backup-Recht erscheinen.');
        $itemUrls = array_column($verwaltung['items'], 'url');
        $this->assertContains('/backups', $itemUrls);
        $this->assertNotContains('/roles', $itemUrls);
    }

    public function testAdminSeesFullStructure(): void
    {
        $tree = $this->build([
            'can_manage_users' => true,
            'can_manage_master_data' => true,
            'can_manage_mail_queue' => true,
            'can_manage_backups' => true,
        ], [
            'finance' => true,
            'newsletter' => true,
        ]);
        $urls = $this->urls($tree);

        foreach (['/users', '/roles', '/voice-groups', '/settings', '/admin/mail-queue', '/backups'] as $u) {
            $this->assertContains($u, $urls, "Admin muss {$u} sehen.");
        }
        $this->assertNotNull($this->group($tree, 'Verwaltung'));
    }

    public function testActiveStatePropagatesToGroup(): void
    {
        $tree = $this->build(['can_manage_users' => true], ['registration' => true], '/registrations');
        $termine = $this->group($tree, 'Termine');

        $this->assertNotNull($termine);
        $this->assertTrue($termine['active'], 'Gruppe Termine muss aktiv sein bei /registrations.');

        $anmeldung = null;
        foreach ($termine['items'] as $item) {
            if ($item['url'] === '/registrations') {
                $anmeldung = $item;
            }
        }
        $this->assertNotNull($anmeldung);
        $this->assertTrue($anmeldung['active']);
    }

    public function testDividerOnlyBetweenVisibleAdminSections(): void
    {
        // Nur Backup-Recht: Verwaltung hat genau ein sichtbares Item, kein fuehrender Divider.
        $tree = $this->build(['can_manage_backups' => true]);
        $verwaltung = $this->group($tree, 'Verwaltung');
        $this->assertFalse($verwaltung['items'][0]['divider_before']);
    }

    /**
     * Pins the Kassa/Budget visibility rules behaviorally rather than via source-text
     * matching. A finance reader with both modules enabled must see both '/finances'
     * and '/budget'; with the modules disabled, neither may appear even though the
     * permission flag stays true.
     */
    public function testFinanceReaderSeesFinancesAndBudgetWhenModulesEnabled(): void
    {
        $urls = $this->urls($this->build(
            ['can_read_finances' => true],
            ['finance' => true, 'budget' => true]
        ));

        $this->assertContains('/finances', $urls);
        $this->assertContains('/budget', $urls);
    }

    public function testFinanceReaderDoesNotSeeFinancesOrBudgetWhenModulesDisabled(): void
    {
        $urls = $this->urls($this->build(
            ['can_read_finances' => true],
            ['finance' => false, 'budget' => false]
        ));

        $this->assertNotContains('/finances', $urls);
        $this->assertNotContains('/budget', $urls);
    }

    /**
     * Pins the Kassa entry's remaining permission paths (currently uncovered by the tests
     * above, which only exercise can_read_finances): can_manage_finances alone, and
     * can_manage_users alone, must each be sufficient to see '/finances' when the finance
     * module is on.
     */
    public function testFinanceManagerSeesFinancesWithoutReadPermission(): void
    {
        $urls = $this->urls($this->build(
            ['can_manage_finances' => true],
            ['finance' => true]
        ));

        $this->assertContains('/finances', $urls);
    }

    public function testUserManagerSeesFinancesWithoutFinancePermissions(): void
    {
        $urls = $this->urls($this->build(
            ['can_manage_users' => true],
            ['finance' => true]
        ));

        $this->assertContains('/finances', $urls);
    }

    /**
     * Pins the navKey branch of NavigationBuilder::matchesActive(): controllers such as
     * DownloadController pass active_nav='downloads' to highlight the Downloads item on
     * pages whose path (e.g. a download-serving route) does not start with '/downloads'.
     * Level chosen: NavigationContext/NavigationBuilder directly, not a rendered template.
     * This is the exact seam that needs pinning (the navKey-vs-prefix precedence inside
     * matchesActive()); driving it through a full controller+layout render would add DB/
     * routing setup without exercising anything this narrower test does not already cover.
     */
    public function testActiveNavKeyHighlightsDownloadsRegardlessOfCurrentPath(): void
    {
        $ctx = new NavigationContext([], [], '/dashboard', 'downloads');
        $tree = (new NavigationBuilder())->build($ctx);

        $bereiche = $this->group($tree, 'Bereiche');
        $this->assertNotNull($bereiche);

        $downloads = null;
        foreach ($bereiche['items'] as $item) {
            if ($item['url'] === '/downloads') {
                $downloads = $item;
            }
        }

        $this->assertNotNull($downloads, 'Downloads-Item muss im Baum vorhanden sein.');
        $this->assertTrue(
            $downloads['active'],
            'Downloads-Item muss aktiv sein, wenn active_nav=downloads gesetzt ist, ' .
                'unabhängig vom aktuellen Pfad.'
        );
    }

    /**
     * Closes the "no test touches fromSession()" gap: every other test in this class builds
     * NavigationContext directly, so a bug in fromSession() itself (e.g. its former hardcoded
     * flag allowlist) would go completely unnoticed. This drives a real session array and real
     * settings array through fromSession() and NavigationBuilder::build() end-to-end.
     */
    public function testFromSessionBuildsExpectedMenuFromSessionArrayAndSettings(): void
    {
        $session = [
            'user_id' => 42,
            'can_manage_users' => true,
            'can_manage_backups' => true,
        ];
        $settings = ['modules' => ['finance' => true, 'newsletter' => true]];

        $context = NavigationContext::fromSession($session, $settings, '/backups');
        $tree = (new NavigationBuilder())->build($context);
        $urls = $this->urls($tree);

        $this->assertContains('/users', $urls);
        $this->assertContains('/backups', $urls);
        $this->assertContains('/finances', $urls);

        $backupsGroup = $this->group($tree, 'Verwaltung');
        $this->assertNotNull($backupsGroup);
        $this->assertTrue($backupsGroup['active'], 'Verwaltung muss aktiv sein bei /backups.');
    }

    /**
     * Pins Finding 2's fix: fromSession() must copy every "can_"-prefixed session key present,
     * not filter through an explicit, hand-maintained allowlist. A brand-new capability flag
     * referenced by a future NavigationBuilder predicate must work through fromSession()
     * without this class needing a matching update in lockstep — otherwise the exact
     * gate-in-one-file/flag-in-another desync bug class this branch removes from the builder
     * would simply relocate here, undetected.
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

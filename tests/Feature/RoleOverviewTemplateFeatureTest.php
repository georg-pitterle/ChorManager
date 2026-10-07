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

    /**
     * @param list<Role> $roles
     * @param array<string,bool> $modules
     */
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

    /** @return list<Role> */
    private function sampleRoles(): array
    {
        return [
            $this->role([
                'id' => 1,
                'name' => 'Admin',
                'hierarchy_level' => 100,
                'can_manage_events' => 1,
                'can_manage_files' => 1,
                'active_users_count' => 2,
                'assigned_users_count' => 2,
            ]),
            $this->role(['id' => 5, 'name' => 'Kassier', 'hierarchy_level' => 80, 'can_read_finances' => 1]),
        ];
    }

    public function testRendersOneDetailSectionAndOneOptionPerRole(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertStringContainsString('data-role-detail="1"', $html);
        $this->assertStringContainsString('data-role-detail="5"', $html);
        $this->assertMatchesRegularExpression('#<option value="5">\s*Kassier · Level 80 · 1/\d+\s*</option>#u', $html);
        $this->assertStringContainsString('data-role-id="1"', $html);
    }

    public function testEditButtonCarriesPermissionDataAndDeleteOnlyWithoutAssignments(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertMatchesRegularExpression(
            '#class="[^"]*edit-role-btn[^"]*"[^>]*data-id="1"[^>]*data-events="1"#s',
            $html
        );
        $this->assertStringNotContainsString('data-bs-target="#deleteRoleModal1"', $html);
        $this->assertStringContainsString('data-bs-target="#deleteRoleModal5"', $html);
    }

    public function testHoldersSourceListsRolesWithThePermission(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertMatchesRegularExpression(
            '#data-permission-holders="can_read_finances" data-role-ids="5">\s*'
            . '<button[^>]*data-role-jump="5"[^>]*>Kassier</button>#',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#data-permission-holders="can_manage_backups" data-role-ids="">\s*'
            . '<p[^>]*>Keine Rolle hat dieses Recht\.</p>#',
            $html
        );
    }

    public function testPermissionRowShowsHolderCountAndGrantedState(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertMatchesRegularExpression(
            '#data-permission-key="can_manage_events"\s+data-permission-label="Termine verwalten"'
            . '.*?bi-check-circle-fill.*?Termine verwalten.*?1 Rolle<#s',
            $html
        );
    }

    public function testGrantedStateIsReadableForScreenReaders(): void
    {
        $html = $this->render($this->sampleRoles());

        $this->assertMatchesRegularExpression(
            '#data-permission-key="can_manage_events".*?<span class="visually-hidden">erteilt</span>#s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#data-permission-key="can_manage_users".*?<span class="visually-hidden">nicht erteilt</span>#s',
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

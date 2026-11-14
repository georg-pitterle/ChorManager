<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Role;
use App\Policies\RoleAssignmentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Wer Rollen vergibt, gibt nur weiter, was er selbst hat.
 *
 * Bisher reichte die Stufe: Jede Rolle bis zur eigenen Stufe ließ sich vergeben,
 * auch eine gleichrangige mit Rechten, die der Handelnde selbst nicht besitzt.
 * Zwei Vorstände mit verschiedenen Zuständigkeiten konnten einander - oder einem
 * Dritten - damit Rechte zuschanzen, die keiner von beiden vergeben durfte.
 */
final class RoleAssignmentPolicyTest extends TestCase
{
    public function testRoleWithinLevelAndRightsIsAssignable(): void
    {
        $policy = new RoleAssignmentPolicy(['role_level' => 50, 'can_manage_events' => true]);

        $this->assertTrue($policy->canAssign($this->role(40, ['can_manage_events'])));
        $this->assertTrue($policy->canAssign($this->role(50, ['can_manage_events'])));
        $this->assertTrue($policy->canAssign($this->role(10, [])));
    }

    public function testRoleAboveOwnLevelIsNotAssignable(): void
    {
        $policy = new RoleAssignmentPolicy(['role_level' => 50, 'can_manage_events' => true]);

        $this->assertFalse($policy->canAssign($this->role(51, [])));
    }

    /**
     * Die eigentliche Lücke: gleiche Stufe, fremdes Recht.
     */
    public function testRoleWithARightTheActorLacksIsNotAssignable(): void
    {
        $policy = new RoleAssignmentPolicy(['role_level' => 50, 'can_manage_events' => true]);

        $this->assertFalse($policy->canAssign($this->role(50, ['can_manage_events', 'can_manage_finances'])));
        $this->assertFalse($policy->canAssign($this->role(10, ['can_manage_roles'])));
    }

    /**
     * Abgeleitete Rechte zählen wie eigene: Wer die Finanzen verwaltet, darf sie
     * auch lesen - und eine Rolle mit dem Leserecht vergeben.
     */
    public function testImpliedRightsCountAsHeld(): void
    {
        $policy = new RoleAssignmentPolicy([
            'role_level' => 50,
            'can_manage_finances' => true,
            'can_read_finances' => true,
        ]);

        $this->assertTrue($policy->canAssign($this->role(20, ['can_read_finances'])));
    }

    public function testAssignableIdsFilterAList(): void
    {
        $policy = new RoleAssignmentPolicy(['role_level' => 50, 'can_manage_events' => true]);

        $roles = [
            $this->role(10, [], 1),
            $this->role(50, ['can_manage_finances'], 2),
            $this->role(60, [], 3),
            $this->role(40, ['can_manage_events'], 4),
        ];

        $this->assertSame([1, 4], $policy->assignableIds($roles));
    }

    /**
     * @param list<string> $permissions
     */
    private function role(int $level, array $permissions, int $id = 1): Role
    {
        $attributes = ['id' => $id, 'hierarchy_level' => $level];
        foreach (Role::PERMISSIONS as $permission) {
            $attributes[$permission] = in_array($permission, $permissions, true) ? 1 : 0;
        }

        $role = new Role();
        $role->forceFill($attributes);

        return $role;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Policies\UserEditPolicy;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class UserEditPolicyTest extends TestCase
{
    /**
     * @param array<int> $voiceGroupIds
     * @param array<int> $roleLevels
     */
    private function makeUser(
        int $id,
        array $voiceGroupIds,
        int $isActive = 1,
        array $roleLevels = []
    ): User {
        $user = new User();
        $user->forceFill(['id' => $id, 'is_active' => $isActive]);

        $groups = array_map(static function (int $groupId): VoiceGroup {
            $group = new VoiceGroup();
            $group->forceFill(['id' => $groupId]);

            return $group;
        }, $voiceGroupIds);

        $roles = array_map(static function (int $level): Role {
            $role = new Role();
            $role->forceFill(['hierarchy_level' => $level]);

            return $role;
        }, $roleLevels);

        $user->setRelation('voiceGroups', new Collection($groups));
        $user->setRelation('roles', new Collection($roles));

        return $user;
    }

    public function testGlobalEditPermissionAllowsEditing(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [])));
    }

    public function testManagerWithoutEditPermissionCannotEdit(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => false, 'can_manage_users' => true];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1])));
    }

    public function testVoiceGroupRepresentativeCanEditOwnVoiceGroupMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2, 3],
        ];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [3])));
    }

    public function testVoiceGroupRepresentativeCannotEditForeignMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
        ];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [5])));
    }

    /**
     * Die gemeinsame Stimmgruppe allein darf keinen Bearbeiten-Einstieg oeffnen:
     * UserController::update() verlangt zusaetzlich can_manage_own_voice_group.
     */
    public function testSharedVoiceGroupWithoutCapabilityFlagCannotEdit(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_users' => true,
            'can_manage_own_voice_group' => false,
            'voice_group_ids' => [2],
        ];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [2])));
    }

    public function testArchivedMemberIsNeverEditable(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1], 0)));
    }

    public function testEmptySessionCannotEdit(): void
    {
        $policy = new UserEditPolicy();

        $this->assertFalse($policy->canEdit([], $this->makeUser(7, [1])));
    }

    public function testHigherRankedMemberIsNotEditableDespiteGlobalEditPermission(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true, 'role_level' => 80];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1], 1, [100])));
    }

    public function testSameRankedMemberStaysEditable(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true, 'role_level' => 100];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [1], 1, [100, 50])));
    }

    public function testVoiceGroupRepresentativeCannotEditHigherRankedOwnGroupMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
            'role_level' => 10,
        ];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [2], 1, [50])));
    }
}

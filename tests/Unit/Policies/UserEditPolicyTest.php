<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Models\VoiceGroup;
use App\Policies\UserEditPolicy;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class UserEditPolicyTest extends TestCase
{
    private function makeUser(int $id, array $voiceGroupIds, int $isActive = 1): User
    {
        $user = new User();
        $user->forceFill(['id' => $id, 'is_active' => $isActive]);

        $groups = array_map(static function (int $groupId): VoiceGroup {
            $group = new VoiceGroup();
            $group->forceFill(['id' => $groupId]);

            return $group;
        }, $voiceGroupIds);

        $user->setRelation('voiceGroups', new Collection($groups));

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
        $session = ['can_edit_users' => false, 'voice_group_ids' => [2, 3]];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [3])));
    }

    public function testVoiceGroupRepresentativeCannotEditForeignMember(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => false, 'voice_group_ids' => [2]];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [5])));
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
}

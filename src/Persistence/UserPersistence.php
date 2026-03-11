<?php
declare(strict_types = 1)
;

namespace App\Persistence;

use App\Models\User;

class UserPersistence
{
    public function save(User $user): bool
    {
        return $user->save();
    }

    public function delete(User $user): bool
    {
        return $user->delete() === true;
    }

    public function syncRoles(User $user, array $roleIds): void
    {
        $user->roles()->sync($roleIds);
    }

    public function syncVoiceGroups(User $user, array $voiceGroupData): void
    {
        // Eloquent sync with pivot data
        // $voiceGroupData format: [ voice_group_id => ['sub_voice_id' => $subId], ... ]
        $user->voiceGroups()->sync($voiceGroupData);
    }
}

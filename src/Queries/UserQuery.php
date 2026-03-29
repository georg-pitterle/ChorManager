<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class UserQuery
{
    public function findByEmail(string $email): ?User
    {
        return User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup'])
            ->where('email', $email)
            ->where('is_active', 1)
            ->first();
    }

    public function findById(int $id): ?User
    {
        return User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup'])
            ->find($id);
    }

    public function getAllUsers(): Collection
    {
        return User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup'])
            ->where('is_active', 1)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function getArchivedUsers(): Collection
    {
        return User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup'])
            ->where('is_active', 0)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function getRole(int $roleId): ?Role
    {
        return Role::find($roleId);
    }
}

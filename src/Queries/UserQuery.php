<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Models\Role;
use App\Services\NameFormatterService;
use Illuminate\Database\Eloquent\Collection;

class UserQuery
{
    private NameFormatterService $nameFormatter;

    public function __construct(NameFormatterService $nameFormatter)
    {
        $this->nameFormatter = $nameFormatter;
    }

    public function findByEmail(string $email): ?User
    {
        return User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup'])
            ->where('email', $email)
            ->where('is_active', 1)
            ->first();
    }

    /**
     * Used only to distinguish the audit-log reason for a failed login: does an
     * inactive (deactivated) account exist for this address? Never used to
     * authenticate - findByEmail() above stays the only login-relevant lookup,
     * and its is_active=1 filter is untouched.
     */
    public function existsInactiveByEmail(string $email): bool
    {
        return User::where('email', $email)
            ->where('is_active', 0)
            ->exists();
    }

    public function findById(int $id): ?User
    {
        return User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup', 'mailAccount'])
            ->find($id);
    }

    public function getAllUsers(): Collection
    {
        $query = User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup', 'projects'])
            ->where('is_active', 1);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    public function getArchivedUsers(): Collection
    {
        $query = User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup', 'projects'])
            ->where('is_active', 0);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    public function getRole(int $roleId): ?Role
    {
        return Role::find($roleId);
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Role;

class RoleQuery
{
    public function findById(int $id): ?Role
    {
        return Role::find($id);
    }
}

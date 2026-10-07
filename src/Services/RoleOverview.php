<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Role;

/**
 * Kennzahlen für die Rollenübersicht: wie viele der sichtbaren Rechte jede Rolle
 * hält und welche Rollen ein Recht halten. Nur die übergebenen Gruppen zählen,
 * Rechte abgeschalteter Module bleiben also draußen.
 */
final class RoleOverview
{
    /**
     * @param iterable<Role> $roles
     * @param list<array{label: string, permissions: list<array{key: string, label: string}>}> $groups
     * @return array{total: int, granted_counts: array<int,int>, share_percent: array<int,int>,
     *     holders: array<string, list<int>>}
     */
    public static function build(iterable $roles, array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ($group['permissions'] as $permission) {
                $keys[] = $permission['key'];
            }
        }

        $total = count($keys);
        $holders = array_fill_keys($keys, []);
        $grantedCounts = [];
        $sharePercent = [];

        foreach ($roles as $role) {
            $roleId = (int) $role->getAttribute('id');
            $granted = 0;
            foreach ($keys as $key) {
                if ((bool) $role->getAttribute($key)) {
                    $holders[$key][] = $roleId;
                    $granted++;
                }
            }
            $grantedCounts[$roleId] = $granted;
            $sharePercent[$roleId] = $total === 0 ? 0 : (int) round($granted / $total * 100);
        }

        return [
            'total' => $total,
            'granted_counts' => $grantedCounts,
            'share_percent' => $sharePercent,
            'holders' => $holders,
        ];
    }
}

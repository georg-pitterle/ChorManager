<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Role;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Hält `Role::PERMISSIONS` mit den Stellen gleich, die dieselben Spalten schon
 * aufzählen.
 *
 * Ohne diese Klammer könnte ein neues Recht in `$fillable` stehen, aber nicht in
 * der Liste - und käme dann beim Anmelden nie in der Sitzung an. Der Fehler wäre
 * kein Absturz, sondern ein Recht, das sich setzen lässt und trotzdem nicht
 * wirkt.
 */
final class RolePermissionListTest extends TestCase
{
    public function testEveryPermissionIsFillable(): void
    {
        $fillable = $this->modelProperty('fillable');

        foreach (Role::PERMISSIONS as $permission) {
            $this->assertContains($permission, $fillable, $permission . ' fehlt in $fillable.');
        }
    }

    public function testEveryFillablePermissionIsListed(): void
    {
        foreach ($this->modelProperty('fillable') as $column) {
            if (!str_starts_with((string) $column, 'can_')) {
                continue;
            }

            $this->assertContains(
                $column,
                Role::PERMISSIONS,
                $column . ' steht in $fillable, aber nicht in Role::PERMISSIONS.'
            );
        }
    }

    /**
     * Ohne Cast kommt ein Recht als 1/0 zurück statt als Wahrheitswert.
     */
    public function testEveryPermissionIsCastToBoolean(): void
    {
        $casts = $this->modelProperty('casts');

        foreach (Role::PERMISSIONS as $permission) {
            $this->assertArrayHasKey($permission, $casts, $permission . ' fehlt in $casts.');
            $this->assertSame('boolean', $casts[$permission], $permission . ' muss als boolean gelesen werden.');
        }
    }

    /**
     * Ein eingeschlossenes Recht muss selbst ein Recht sein - sonst schriebe der
     * Anmeldevorgang einen Sitzungsschlüssel, den niemand kennt.
     */
    public function testImpliedPermissionsPointAtRealPermissions(): void
    {
        foreach (Role::IMPLIED_PERMISSIONS as $full => $implied) {
            $this->assertContains($full, Role::PERMISSIONS, $full . ' ist kein bekanntes Recht.');

            foreach ($implied as $smaller) {
                $this->assertContains($smaller, Role::PERMISSIONS, $smaller . ' ist kein bekanntes Recht.');
                $this->assertNotSame($full, $smaller, 'Ein Recht darf sich nicht selbst einschließen.');
            }
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private function modelProperty(string $name): array
    {
        $property = (new ReflectionClass(Role::class))->getProperty($name);

        /** @var array<int|string, mixed> $value */
        $value = $property->getValue(new Role());

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Role;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * `tinyint(1)` liefert ohne Cast 1/0. Ein `=== true` darauf ist still falsch,
 * obwohl das Recht gesetzt ist - deshalb müssen diese Spalten als Wahrheitswert
 * aus dem Modell kommen.
 */
final class BooleanColumnCastsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function rolePermissionProvider(): array
    {
        $cases = [];

        foreach ((new Role())->getFillable() as $attribute) {
            if (str_starts_with($attribute, 'can_')) {
                $cases[$attribute] = [$attribute];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rolePermissionProvider')]
    public function testRechteKommenAlsWahrheitswert(string $attribute): void
    {
        $role = new Role();
        $role->setRawAttributes([$attribute => 1]);
        self::assertTrue($role->getAttribute($attribute));

        $role->setRawAttributes([$attribute => 0]);
        self::assertFalse($role->getAttribute($attribute));
    }

    public function testAlleRechteSindErfasst(): void
    {
        self::assertCount(21, self::rolePermissionProvider(), 'Ein neues Recht braucht auch einen Cast.');
    }

    public function testHierarchieEbeneKommtAlsGanzzahl(): void
    {
        $role = new Role();
        $role->setRawAttributes(['hierarchy_level' => '30']);

        self::assertSame(30, $role->hierarchy_level);
    }

    public function testAktivKennzeichenDesMitgliedsKommtAlsWahrheitswert(): void
    {
        $user = new User();

        $user->setRawAttributes(['is_active' => 1]);
        self::assertTrue($user->is_active);

        $user->setRawAttributes(['is_active' => 0]);
        self::assertFalse($user->is_active);
    }
}

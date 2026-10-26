<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Services\DevSeedService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Die Admin-Rolle des Dev-Seeds hält jedes Recht - auch jedes künftige.
 *
 * Ihre Rechte standen einzeln ausgeschrieben im Seed. Zwei neuere fehlten
 * darin (can_assign_own_voice_group_to_project, can_keep_voice_group_lists),
 * und wer sich im Dev-Stand als Admin anmeldete, sah die zugehörigen Seiten
 * nicht. Jetzt leitet der Seed die Admin-Rechte aus Role::PERMISSIONS ab.
 */
final class DevSeedAdminRoleFeatureTest extends TestCase
{
    public function testTheAdminRoleHoldsEveryPermission(): void
    {
        $admin = $this->definition('Admin');

        foreach (Role::PERMISSIONS as $permission) {
            $this->assertSame(1, $admin[$permission] ?? null, $permission);
        }
    }

    public function testListsAreKeptOnlyByAdminAndTheRepresentatives(): void
    {
        $holders = [];
        foreach ($this->definitions() as $definition) {
            if (($definition['can_keep_voice_group_lists'] ?? 0) === 1) {
                $holders[] = $definition['name'];
            }
        }

        $this->assertSame(['Admin', 'Stimmvertretung', 'Ersatzvertretung'], $holders);
    }

    /** @return array<string, mixed> */
    private function definition(string $name): array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        $this->fail('Keine Seed-Rolle ' . $name);
    }

    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        $reflection = new ReflectionClass(DevSeedService::class);
        $method = $reflection->getMethod('roleDefinitions');

        return $method->invoke($reflection->newInstanceWithoutConstructor());
    }
}

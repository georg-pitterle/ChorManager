<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class RoleHierarchyProtectionFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** Hängt an jeden Rollennamen, damit er in der geteilten Datenbank eindeutig bleibt. */
    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function roleName(string $label): string
    {
        return $label . ' ' . $this->suffix;
    }

    private function controller(): RoleController
    {
        return new RoleController($this->createStub(Twig::class));
    }

    public function testCreateRejectsRoleAboveActorLevel(): void
    {
        $_SESSION['role_level'] = 80;

        $name = $this->roleName('Superadmin');
        $result = $this->controller()->create(
            $this->makeRequest('POST', '/roles', ['name' => $name, 'hierarchy_level' => '100']),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame(
            'Du kannst keine Rolle oberhalb deines eigenen Levels anlegen.',
            $_SESSION['error'] ?? null
        );
        // Gegen die echte Datenbank zählt nur, dass GENAU DIESE Rolle nicht entstanden ist -
        // die Bestandsrollen der Anwendung sind hier nicht Gegenstand der Prüfung.
        $this->assertSame(0, Role::query()->where('name', $name)->count());
    }

    public function testCreateAllowsRoleAtActorLevel(): void
    {
        $_SESSION['role_level'] = 80;

        $name = $this->roleName('Vorstand');
        $result = $this->controller()->create(
            $this->makeRequest('POST', '/roles', ['name' => $name, 'hierarchy_level' => '80']),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame('Rolle erfolgreich angelegt.', $_SESSION['success'] ?? null);
        $this->assertSame(1, Role::query()->where('name', $name)->count());
    }

    public function testUpdateRejectsEditingRoleAboveActorLevel(): void
    {
        $_SESSION['role_level'] = 80;
        $name = $this->roleName('Admin');
        $role = Role::create(['name' => $name, 'hierarchy_level' => 100]);

        $result = $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, [
                'name' => $this->roleName('Gekapert'),
                'hierarchy_level' => '80',
            ]),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame(
            'Du kannst keine Rolle oberhalb deines eigenen Levels bearbeiten.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame($name, Role::find($role->id)->name);
        $this->assertSame(100, (int) Role::find($role->id)->hierarchy_level);
    }

    public function testUpdateRejectsElevatingRoleAboveActorLevel(): void
    {
        $_SESSION['role_level'] = 80;
        $role = Role::create(['name' => $this->roleName('Helfer'), 'hierarchy_level' => 50]);

        $result = $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, [
                'name' => $this->roleName('Helfer'),
                'hierarchy_level' => '100',
            ]),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame(
            'Du kannst keine Rolle oberhalb deines eigenen Levels bearbeiten.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(50, (int) Role::find($role->id)->hierarchy_level);
    }

    public function testUpdateAllowsEditingRoleAtOrBelowActorLevel(): void
    {
        $_SESSION['role_level'] = 80;
        $role = Role::create(['name' => $this->roleName('Helfer'), 'hierarchy_level' => 50]);
        $newName = $this->roleName('Helfer neu');

        $result = $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, ['name' => $newName, 'hierarchy_level' => '60']),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame('Rolle erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
        $this->assertSame($newName, Role::find($role->id)->name);
        $this->assertSame(60, (int) Role::find($role->id)->hierarchy_level);
    }
}

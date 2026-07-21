<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class RoleHierarchyProtectionFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('hierarchy_level')->default(0);
            $table->boolean('can_manage_users')->default(false);
            $table->boolean('can_edit_users')->default(false);
            $table->boolean('can_manage_attendance')->default(false);
            $table->boolean('can_manage_project_members')->default(false);
            $table->boolean('can_read_finances')->default(false);
            $table->boolean('can_manage_finances')->default(false);
            $table->boolean('can_manage_master_data')->default(false);
            $table->boolean('can_manage_sponsoring')->default(false);
            $table->boolean('can_manage_song_library')->default(false);
            $table->boolean('can_manage_newsletters')->default(false);
            $table->boolean('can_manage_mail_queue')->default(false);
            $table->boolean('can_manage_sheet_archive')->default(false);
            $table->boolean('can_manage_budget')->default(false);
            $table->boolean('can_manage_tasks')->default(false);
            $table->boolean('can_manage_backups')->default(false);
            $table->boolean('can_manage_own_voice_group')->default(false);
        });
    }

    private function controller(): RoleController
    {
        return new RoleController($this->createStub(Twig::class));
    }

    public function testCreateRejectsRoleAboveActorLevel(): void
    {
        $_SESSION['role_level'] = 80;

        $result = $this->controller()->create(
            $this->makeRequest('POST', '/roles', ['name' => 'Superadmin', 'hierarchy_level' => '100']),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame(
            'Du kannst keine Rolle oberhalb deines eigenen Levels anlegen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(0, Role::query()->count());
    }

    public function testCreateAllowsRoleAtActorLevel(): void
    {
        $_SESSION['role_level'] = 80;

        $result = $this->controller()->create(
            $this->makeRequest('POST', '/roles', ['name' => 'Vorstand', 'hierarchy_level' => '80']),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame('Rolle erfolgreich angelegt.', $_SESSION['success'] ?? null);
        $this->assertSame(1, Role::query()->where('name', 'Vorstand')->count());
    }

    public function testUpdateRejectsEditingRoleAboveActorLevel(): void
    {
        $_SESSION['role_level'] = 80;
        $role = Role::create(['name' => 'Admin', 'hierarchy_level' => 100]);

        $result = $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, ['name' => 'Gekapert', 'hierarchy_level' => '80']),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame(
            'Du kannst keine Rolle oberhalb deines eigenen Levels bearbeiten.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame('Admin', Role::find($role->id)->name);
        $this->assertSame(100, (int) Role::find($role->id)->hierarchy_level);
    }

    public function testUpdateRejectsElevatingRoleAboveActorLevel(): void
    {
        $_SESSION['role_level'] = 80;
        $role = Role::create(['name' => 'Helfer', 'hierarchy_level' => 50]);

        $result = $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, ['name' => 'Helfer', 'hierarchy_level' => '100']),
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
        $role = Role::create(['name' => 'Helfer', 'hierarchy_level' => 50]);

        $result = $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, ['name' => 'Helfer neu', 'hierarchy_level' => '60']),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertRedirect($result, '/roles');
        $this->assertSame('Rolle erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
        $this->assertSame('Helfer neu', Role::find($role->id)->name);
        $this->assertSame(60, (int) Role::find($role->id)->hierarchy_level);
    }
}

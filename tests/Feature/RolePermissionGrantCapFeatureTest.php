<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Wer Rollen verwalten darf, darf nur die Rechte vergeben, die er selbst hält.
 *
 * Begrenzt war bisher allein das Hierarchie-Level, also *welche* Rolle bearbeitet
 * werden darf - nicht, *was* hineingeschrieben wird. Damit konnte jemand mit
 * can_manage_roles seine eigene Rolle auf seinem Level bearbeiten und ihr Kassa,
 * Backups und Newsletter zuschalten. Das Level vergibt bewusst kein Recht, und
 * genau deshalb taugt es nicht als Freibrief für die Rechtevergabe.
 *
 * Die Rolle auf Level 100 bleibt ausgenommen: Ein neu eingeführtes Recht steht
 * anfangs auf keiner Rolle, hält also niemand, dürfte also niemand vergeben.
 */
final class RolePermissionGrantCapFeatureTest extends TestCase
{
    use TestHttpHelpers;

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

    /**
     * Ein Rollenverwalter auf mittlerem Level, der selbst weder Kassa noch
     * Backups hält.
     */
    private function actorWithoutFinanceOrBackups(int $level = 50): void
    {
        $_SESSION['role_level'] = $level;
        $_SESSION['can_manage_roles'] = true;
        $_SESSION['can_manage_events'] = true;
        $_SESSION['can_manage_finances'] = false;
        $_SESSION['can_read_finances'] = false;
        $_SESSION['can_manage_backups'] = false;
    }

    public function testCreateDoesNotGrantARightTheActorLacks(): void
    {
        $this->actorWithoutFinanceOrBackups();

        $response = $this->controller()->create(
            $this->makeRequest('POST', '/roles', [
                'name' => $this->roleName('Selbstbedienung'),
                'hierarchy_level' => '50',
                'can_manage_events' => '1',
                'can_manage_finances' => '1',
                'can_manage_backups' => '1',
            ]),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/roles');

        $role = Role::where('name', $this->roleName('Selbstbedienung'))->firstOrFail();
        $this->assertSame(0, (int) $role->can_manage_finances, 'Kassa war nicht zu vergeben.');
        $this->assertSame(0, (int) $role->can_read_finances, 'Das eingeschlossene Leserecht ebenso.');
        $this->assertSame(0, (int) $role->can_manage_backups, 'Backups waren nicht zu vergeben.');
        $this->assertSame(1, (int) $role->can_manage_events, 'Das eigene Recht bleibt vergebbar.');
        $this->assertNotNull($_SESSION['error'] ?? null, 'Das Streichen wird gemeldet.');
    }

    public function testUpdateDoesNotAddARightTheActorLacks(): void
    {
        $this->actorWithoutFinanceOrBackups();
        $role = Role::create([
            'name' => $this->roleName('Bestand'),
            'hierarchy_level' => 50,
            'can_manage_events' => 1,
        ]);

        $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, [
                'name' => (string) $role->name,
                'hierarchy_level' => '50',
                'can_manage_events' => '1',
                'can_manage_backups' => '1',
            ]),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $fresh = $role->fresh();
        $this->assertSame(0, (int) $fresh->can_manage_backups);
        $this->assertSame(1, (int) $fresh->can_manage_events);
    }

    /**
     * Sonst wäre eine Rolle nach dem ersten Speichern nie wieder zu bearbeiten,
     * ohne ihre übrigen Rechte zu verlieren.
     */
    public function testUpdateKeepsARightTheRoleAlreadyCarries(): void
    {
        $this->actorWithoutFinanceOrBackups();
        $role = Role::create([
            'name' => $this->roleName('Kassier'),
            'hierarchy_level' => 50,
            'can_manage_finances' => 1,
            'can_read_finances' => 1,
        ]);

        $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, [
                'name' => 'Kassier neu ' . $this->suffix,
                'hierarchy_level' => '50',
                'can_manage_finances' => '1',
                'can_read_finances' => '1',
            ]),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $fresh = $role->fresh();
        $this->assertSame('Kassier neu ' . $this->suffix, (string) $fresh->name);
        $this->assertSame(1, (int) $fresh->can_manage_finances, 'Bestehendes Recht bleibt stehen.');
    }

    /** Entziehen ist keine Rechteausweitung und bleibt erlaubt. */
    public function testUpdateMayRevokeARightTheActorLacks(): void
    {
        $this->actorWithoutFinanceOrBackups();
        $role = Role::create([
            'name' => $this->roleName('Abbau'),
            'hierarchy_level' => 50,
            'can_manage_backups' => 1,
        ]);

        $this->controller()->update(
            $this->makeRequest('POST', '/roles/' . $role->id, [
                'name' => (string) $role->name,
                'hierarchy_level' => '50',
            ]),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertSame(0, (int) $role->fresh()->can_manage_backups);
        $this->assertNull($_SESSION['error'] ?? null, 'Ein Entzug ist keine Beanstandung.');
    }

    public function testTheTopLevelMayGrantEverything(): void
    {
        $_SESSION['role_level'] = 100;
        $_SESSION['can_manage_roles'] = true;
        $_SESSION['can_manage_backups'] = false;

        $this->controller()->create(
            $this->makeRequest('POST', '/roles', [
                'name' => $this->roleName('Neues Recht'),
                'hierarchy_level' => '50',
                'can_manage_backups' => '1',
            ]),
            $this->makeResponse()
        );

        $role = Role::where('name', $this->roleName('Neues Recht'))->firstOrFail();
        $this->assertSame(1, (int) $role->can_manage_backups);
        $this->assertNull($_SESSION['error'] ?? null);
    }
}

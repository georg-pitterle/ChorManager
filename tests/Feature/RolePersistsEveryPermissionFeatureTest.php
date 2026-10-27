<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use App\Services\RolePermissionCatalog;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Anlegen und Bearbeiten einer Rolle schreiben jedes Recht aus
 * `Role::PERMISSIONS` - nicht die Teilmenge, die gerade in einer Liste im
 * Controller steht.
 *
 * Der Grund für diesen Wächter: `Role::PERMISSIONS` ist die eine Quelle der
 * Rechte, aus der sich `$fillable` und `casts()` ableiten. Schrieben `create()`
 * und `update()` ihre Spalten von Hand auf, war ein neues Recht nach dem
 * Eintrag in der Modellklasse immer noch nicht gespeichert - das Häkchen stand
 * im Formular, die Spalte blieb auf 0, und auffallen konnte das erst im
 * Betrieb.
 */
final class RolePersistsEveryPermissionFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];
        // Oberste Stufe: Nur dort darf jedes Recht vergeben werden, auch eines,
        // das die handelnde Person selbst nicht hält (RoleController).
        $_SESSION['role_level'] = Role::MAX_HIERARCHY_LEVEL;
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * Mit allen Modulen an: Ein Recht eines abgeschalteten Moduls hat kein
     * Häkchen im Formular und behält absichtlich seinen gespeicherten Wert
     * (RoleController::buildPermissionFlags). Geprüft wird hier die Liste der
     * geschriebenen Spalten, nicht das Modul-Tor.
     */
    private function controller(): RoleController
    {
        $modules = array_fill_keys(array_values(RolePermissionCatalog::moduleGates()), true);

        return new RoleController(
            $this->createStub(Twig::class),
            ['modules' => $modules]
        );
    }

    /**
     * @return array<string, string>
     */
    private function everyPermissionChecked(): array
    {
        return array_fill_keys(Role::PERMISSIONS, '1');
    }

    public function testCreateStoresEveryKnownPermission(): void
    {
        $request = $this->makeRequest('POST', '/roles', [
            'name' => 'Vollrechte ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => '50',
        ] + $this->everyPermissionChecked());

        $response = $this->controller()->create($request, $this->makeResponse());
        $this->assertSame(302, $response->getStatusCode());

        $role = Role::orderByDesc('id')->firstOrFail();
        foreach (Role::PERMISSIONS as $permission) {
            $this->assertTrue(
                (bool) $role->{$permission},
                $permission . ' wurde beim Anlegen nicht gespeichert.'
            );
        }
    }

    public function testUpdateStoresEveryKnownPermission(): void
    {
        $role = Role::create([
            'name' => 'Leerrolle ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 50,
        ]);

        $request = $this->makeRequest('POST', '/roles/' . $role->id, [
            'name' => (string) $role->name,
            'hierarchy_level' => '50',
        ] + $this->everyPermissionChecked());

        $response = $this->controller()->update($request, $this->makeResponse(), [
            'id' => (string) $role->id,
        ]);
        $this->assertSame(302, $response->getStatusCode());

        $role->refresh();
        foreach (Role::PERMISSIONS as $permission) {
            $this->assertTrue(
                (bool) $role->{$permission},
                $permission . ' wurde beim Bearbeiten nicht gespeichert.'
            );
        }
    }

    /**
     * Die Gegenrichtung: Ohne Häkchen bleibt jedes Recht aus. Sonst bestünde der
     * Wächter oben auch mit einer Spalte, die immer auf 1 steht.
     */
    public function testUpdateClearsEveryKnownPermission(): void
    {
        $role = Role::create([
            'name' => 'Vollrolle ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 50,
        ] + array_fill_keys(Role::PERMISSIONS, 1));

        $request = $this->makeRequest('POST', '/roles/' . $role->id, [
            'name' => (string) $role->name,
            'hierarchy_level' => '50',
        ]);

        $this->controller()->update($request, $this->makeResponse(), ['id' => (string) $role->id]);

        $role->refresh();
        foreach (Role::PERMISSIONS as $permission) {
            $this->assertFalse(
                (bool) $role->{$permission},
                $permission . ' blieb beim Bearbeiten gesetzt.'
            );
        }
    }
}

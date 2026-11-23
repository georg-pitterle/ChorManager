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
 * Zwei Rollen tragen nie denselben Namen.
 *
 * `RoleController::create()` und `::update()` fangen seit jeher den
 * Schlüsselkonflikt (SQLSTATE 23000) ab und melden "Eine Rolle mit diesem Namen
 * existiert bereits." - die Spalte `roles.name` trug aber keinen eindeutigen
 * Index. Der Zweig war damit toter Code: Das zweite Anlegen lief durch, und die
 * Rollenliste zeigte zwei gleich benannte Zeilen. Weil die Rolle der Träger der
 * Rechte ist, sind die beiden in der Mitgliederverwaltung nicht
 * auseinanderzuhalten - wer "Vorstand" zuteilt, weiß nicht, welchen Rechtesatz
 * er vergibt. Gefunden im Review-Lauf 83 (db/migrations).
 */
final class RoleNameIsUniqueFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];
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

    private function controller(): RoleController
    {
        $modules = array_fill_keys(array_values(RolePermissionCatalog::moduleGates()), true);

        return new RoleController($this->createStub(Twig::class), ['modules' => $modules]);
    }

    private function postRole(string $name): void
    {
        $request = $this->makeRequest('POST', '/roles', [
            'name' => $name,
            'hierarchy_level' => '10',
        ]);

        $this->controller()->create($request, $this->makeResponse());
    }

    public function testCreateRefusesANameAnotherRoleAlreadyCarries(): void
    {
        $name = 'Stimmvertretung ' . bin2hex(random_bytes(4));

        $this->postRole($name);
        $this->postRole($name);

        $this->assertSame(1, Role::where('name', $name)->count());
        $this->assertSame('Eine Rolle mit diesem Namen existiert bereits.', $_SESSION['error'] ?? null);
    }

    public function testUpdateRefusesTheNameOfAnotherRole(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $taken = 'Vorstand ' . $suffix;
        $own = 'Chorleitung ' . $suffix;

        $this->postRole($taken);
        $this->postRole($own);

        $role = Role::where('name', $own)->firstOrFail();

        $request = $this->makeRequest('POST', '/roles/' . $role->id, [
            'name' => $taken,
            'hierarchy_level' => '10',
        ]);
        $this->controller()->update($request, $this->makeResponse(), ['id' => (string) $role->id]);

        $this->assertSame($own, Role::findOrFail($role->id)->name);
        $this->assertSame('Eine andere Rolle mit diesem Namen existiert bereits.', $_SESSION['error'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use App\Models\User;
use App\Services\RolePermissionCatalog;
use App\Logging\RequestContext;
use App\Services\NameFormatterService;
use App\Services\SessionAuthService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein Vollrecht zieht sein kleineres Recht mit - in der Sitzung und in der Spalte.
 *
 * `Role::IMPLIED_PERMISSIONS` nennt zwei Paare: Finanzen verwalten zieht Finanzen
 * lesen mit, Sponsoring verwalten zieht eigene Patenschaften anlegen mit.
 * `SessionAuthService` setzt beide zur Anmeldung. Gespeichert wurde bisher nur das
 * erste: In `RoleController::buildPermissionFlags()` stand die Finanz-Regel
 * handgeschrieben, die Sponsoring-Regel nirgends.
 *
 * Sichtbar war das in der Rechte-Matrix und im Bearbeiten-Dialog der
 * Rollenverwaltung. Beide lesen die Spalten der Rolle (RoleOverview, roles.js), und
 * eine Rolle mit "Sponsoring verwalten" sah dort aus, als hätte sie "Eigene
 * Patenschaften anlegen" nicht - im Betrieb hatte sie es. Die Anzeige widersprach
 * damit dem, was gilt, und zwar nur bei einem der beiden Paare.
 */
final class RoleImpliedPermissionsFeatureTest extends TestCase
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

    /**
     * Beide Paare, aus der Modellklasse gelesen statt hier aufgezählt: Kommt ein
     * drittes Paar dazu, gilt die Regel ohne Zutun auch für das.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function impliedPairs(): array
    {
        $cases = [];
        foreach (Role::IMPLIED_PERMISSIONS as $full => $impliedList) {
            foreach ($impliedList as $implied) {
                $cases[$full . ' zieht ' . $implied] = [$full, $implied];
            }
        }

        return $cases;
    }

    #[DataProvider('impliedPairs')]
    public function testTheFullRightAloneAlsoStoresTheSmallerOne(string $full, string $implied): void
    {
        $flags = RoleController::buildPermissionFlags([$full => '1']);

        $this->assertSame(1, $flags[$full]);
        $this->assertSame(1, $flags[$implied], $implied . ' muss mit ' . $full . ' gesetzt werden.');
    }

    /**
     * Die Gegenrichtung gilt nicht: Das kleinere Recht allein schaltet das
     * Vollrecht nicht frei.
     *
     */
    #[DataProvider('impliedPairs')]
    public function testTheSmallerRightDoesNotStoreTheFullOne(string $full, string $implied): void
    {
        $flags = RoleController::buildPermissionFlags([$implied => '1']);

        $this->assertSame(1, $flags[$implied]);
        $this->assertSame(0, $flags[$full]);
    }

    #[DataProvider('impliedPairs')]
    public function testSavingARoleWritesTheSmallerRightIntoItsColumn(string $full, string $implied): void
    {
        $request = $this->makeRequest('POST', '/roles', [
            'name' => 'Vollrecht ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => '50',
            $full => '1',
        ]);

        $response = $this->controller()->create($request, $this->makeResponse());
        $this->assertSame(302, $response->getStatusCode());

        $role = Role::orderByDesc('id')->firstOrFail();
        $this->assertTrue((bool) $role->{$full});
        $this->assertTrue(
            (bool) $role->{$implied},
            $implied . ' steht nicht in der Spalte, die Rechte-Matrix zeigt es damit nicht.'
        );
    }

    /**
     * Der Nachtrag für den Bestand: Rollen, die vor Lauf 56 gespeichert wurden,
     * tragen das kleinere Recht nicht in ihrer Spalte - und die Rechte-Matrix liest
     * die Spalte. Die Migration 20261027120000 holt sie nach; geprüft wird hier
     * ihre Anweisung gegen genau so eine Zeile.
     */
    #[DataProvider('impliedPairs')]
    public function testTheBackfillCompletesARoleSavedBeforeTheRule(string $full, string $implied): void
    {
        $role = Role::create([
            'name' => 'Altbestand ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 50,
            $full => 1,
            $implied => 0,
        ]);

        $this->assertFalse((bool) $role->fresh()->{$implied}, 'Ausgangslage: Die Spalte ist leer.');

        $this->runBackfill($full, $implied);

        $this->assertTrue((bool) $role->fresh()->{$implied});
    }

    /**
     * Die Gegenrichtung des Nachtrags: Eine Rolle ohne das Vollrecht bekommt
     * nichts dazu.
     */
    #[DataProvider('impliedPairs')]
    public function testTheBackfillLeavesARoleWithoutTheFullRightAlone(string $full, string $implied): void
    {
        $role = Role::create([
            'name' => 'Ohne Vollrecht ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 50,
            $full => 0,
            $implied => 0,
        ]);

        $this->runBackfill($full, $implied);

        $this->assertFalse((bool) $role->fresh()->{$implied});
    }

    /**
     * Die Maske koppelt beide Paare, nicht nur die Finanzen: Wer das Vollrecht
     * setzt, bekommt das kleinere mit; wer das kleinere wegnimmt, verliert das
     * Vollrecht. Ohne diese Kopplung ließ sich beim Sponsoring das kleinere Recht
     * abwählen, obwohl das Speichern es gleich wieder setzt - die Maske hätte das
     * Gegenteil von dem gezeigt, was gilt.
     *
     * Geprüft wird die Datei, wie in RoleKeepVoiceGroupListsFeatureTest: roles.js
     * ist reiner DOM-Code ohne Export, für den es keinen Node-Test gibt.
     */
    public function testTheMaskCouplesEveryImpliedPair(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/roles.js');

        $this->assertStringContainsString('syncImpliedPermissionPair', $js);

        foreach (Role::IMPLIED_PERMISSIONS as $full => $impliedPermissions) {
            foreach ($impliedPermissions as $implied) {
                $this->assertStringContainsString(
                    "['" . $implied . "', '" . $full . "']",
                    $js,
                    'Das Paar ' . $full . '/' . $implied . ' ist in roles.js nicht gekoppelt.'
                );
            }
        }
    }

    /**
     * Führt die Anweisung der Migration aus - die aus ihrer Datei gelesene, nicht
     * eine hier nachgeschriebene. Sonst prüfte der Test seine eigene Kopie und
     * bliebe grün, wenn die Migration daneben kaputtgeht.
     */
    private function runBackfill(string $full, string $implied): void
    {
        require_once dirname(__DIR__, 2) . '/db/migrations/20261027120000_store_implied_role_permissions.php';

        $statement = sprintf(\StoreImpliedRolePermissions::BACKFILL_SQL, $full, $implied);
        $this->assertContains(
            $statement,
            \StoreImpliedRolePermissions::statements(),
            'Die Migration führt diese Anweisung wirklich aus.'
        );

        Capsule::connection()->statement($statement);
    }

    /**
     * Die Spalte sagt jetzt dasselbe wie die Sitzung. Vorher wichen sie beim
     * Sponsoring-Paar auseinander, und die Matrix zeigte die Spalte.
     *
     */
    #[DataProvider('impliedPairs')]
    public function testColumnAndSessionAgree(string $full, string $implied): void
    {
        $role = Role::create([
            'name' => 'Sitzungsprobe ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 50,
        ] + RoleController::buildPermissionFlags([$full => '1']));

        $user = User::create([
            'email' => 'implied_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Ida',
            'last_name' => 'Implikation',
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);

        (new SessionAuthService(new NameFormatterService(), new RequestContext()))
            ->setAuthenticatedUser($user->load(['roles', 'voiceGroups']));

        $this->assertTrue((bool) ($_SESSION[$implied] ?? false), 'Die Sitzung hält ' . $implied . '.');
        $this->assertTrue((bool) $role->fresh()->{$implied}, 'Die Spalte muss dasselbe sagen.');
    }
}

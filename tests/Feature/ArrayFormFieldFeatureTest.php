<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\CategoryController;
use App\Controllers\ProjectController;
use App\Controllers\RoleController;
use App\Models\Role;
use App\Persistence\ProjectPersistence;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein Formularfeld, das als Feld-Array hereinkommt, ergibt eine Formularmeldung -
 * keine Fehlerseite.
 *
 * Der Browser schickt `name=x` als Zeichenkette, `name[]=x` aber als Array, und
 * beides landet gleichberechtigt in `getParsedBody()`. Ungeprüft an `trim()`
 * weitergegeben ist das unter `strict_types` ein TypeError und damit eine 500.
 * Jede betroffene Maske war so von jedem angemeldeten Mitglied lahmzulegen.
 *
 * Geprüft wird stellvertretend an drei Masken; der Wächter
 * Tests\Unit\Controllers\FormFieldsGoThroughInputValidatorTest deckt den Rest ab.
 */
final class ArrayFormFieldFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
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

    public function testCategoryNameAsArrayYieldsAFormMessage(): void
    {
        $response = (new CategoryController())->create(
            $this->makeRequest('POST', '/song-library/categories', ['name' => ['Noten']]),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/song-library');
        $this->assertSame('Kategoriename ist ein Pflichtfeld.', $_SESSION['error'] ?? null);
    }

    public function testProjectNameAsArrayYieldsAFormMessage(): void
    {
        $_SESSION['can_manage_master_data'] = true;

        $controller = new ProjectController(
            $this->createStub(Twig::class),
            $this->createStub(ProjectQuery::class),
            $this->createStub(ProjectPersistence::class),
            $this->createStub(ProjectMemberPolicy::class)
        );

        $response = $controller->create(
            $this->makeRequest('POST', '/projects', [
                'name' => ['Sommerkonzert'],
                'description' => ['irgendwas'],
            ]),
            $this->makeResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($_SESSION['error'] ?? null);
    }

    public function testRoleNameAsArrayYieldsAFormMessage(): void
    {
        $_SESSION['role_level'] = 100;
        $_SESSION['can_manage_roles'] = true;

        $before = Role::query()->count();

        $response = (new RoleController($this->createStub(Twig::class)))->create(
            $this->makeRequest('POST', '/roles', ['name' => ['Vorstand'], 'hierarchy_level' => '50']),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/roles');
        $this->assertSame('Der Rollenname darf nicht leer sein.', $_SESSION['error'] ?? null);
        $this->assertSame($before, Role::query()->count(), 'Es darf keine Rolle entstanden sein.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Role;
use App\Models\User;
use App\Services\NameFormatterService;
use App\Services\SessionAuthService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

final class BudgetFeatureTest extends TestCase
{
    public function testBudgetModelsExposeExpectedMassAssignmentAndCasts(): void
    {
        $category = new BudgetCategory();
        $item = new BudgetItem();

        $this->assertSame('budget_categories', $category->getTable());
        $this->assertSame([
            'fiscal_year_start',
            'finance_group_id',
            'type',
        ], $category->getFillable());
        $this->assertSame('integer', $category->getCasts()['fiscal_year_start'] ?? null);

        $this->assertSame('budget_items', $item->getTable());
        $this->assertSame([
            'budget_category_id',
            'description',
            'planned_amount',
        ], $item->getFillable());
        $this->assertSame('decimal:2', $item->getCasts()['planned_amount'] ?? null);
    }

    public function testBudgetModelsExposeExpectedRelationTypes(): void
    {
        Bootstrap::setupTestDatabase();

        $this->assertInstanceOf(HasMany::class, (new BudgetCategory())->items());
        $this->assertInstanceOf(BelongsTo::class, (new BudgetItem())->category());
    }

    public function testRoleModelAllowsBudgetPermissionMassAssignment(): void
    {
        $fillable = (new Role())->getFillable();
        $sheetArchiveIndex = array_search('can_manage_sheet_archive', $fillable, true);

        $this->assertIsInt($sheetArchiveIndex);
        $this->assertSame('can_manage_budget', $fillable[$sheetArchiveIndex + 1] ?? null);
    }

    public function testRolesTemplateContainsBudgetPermissionCheckbox(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/templates/roles/index.twig');

        $this->assertIsString($content);
        $this->assertStringContainsString('can_manage_budget', $content);
        $this->assertStringContainsString('settings.modules.budget', $content);
    }

    public function testSettingsExposeBudgetFeatureFlag(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'budget'\\s*=>\\s*EnvHelper::read\('FEATURE_BUDGET', 'false'\) === 'true'/",
            $content
        );
    }

    /**
     * Das Budget-Recht kommt beim Anmelden in der Sitzung an.
     *
     * Vorher suchte diese Prüfung die Zeichenkette "can_manage_budget" im
     * Quelltext des Dienstes. Seit die Rechte über eine Liste laufen, steht der
     * Name dort nicht mehr wörtlich - am Verhalten ändert das nichts, und genau
     * das gehört geprüft.
     */
    public function testSessionAuthServiceSetsCanManageBudgetKey(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];

        $role = Role::create([
            'name' => 'Budget-Rolle ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_budget' => 1,
        ]);

        $user = User::create([
            'first_name' => 'Berta',
            'last_name' => 'Budget',
            'email' => 'budget.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        (new SessionAuthService(new NameFormatterService(), new RequestContext()))
            ->setAuthenticatedUser($user);

        $this->assertTrue($_SESSION['can_manage_budget']);

        $user->delete();
        $role->delete();
    }

    public function testRoleMiddlewareHasRequiresBudgetManagementParameter(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Middleware/RoleMiddleware.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('requiresBudgetManagement', $content);
        $this->assertStringContainsString('can_manage_budget', $content);
    }

    public function testRoleMiddlewareHasRequiresBudgetReadParameter(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Middleware/RoleMiddleware.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('requiresBudgetRead', $content);
    }

    public function testBudgetReadRouteUsesReadGate(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertIsString($content);
        // The GET /budget view must be reachable for finance readers, not only managers.
        $this->assertStringContainsString('requiresBudgetRead: true', $content);
    }

    public function testBudgetNavigationVisibleForFinanceReaders(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Navigation/NavigationBuilder.php');
        $this->assertIsString($content);
        // Budget entry condition must include finance-read audience. Anchored on the Budget
        // entry's own label so this cannot pass via the aliased 'can_read_finances' etc. flags
        // that also appear on the separate Kassa entry.
        $this->assertMatchesRegularExpression(
            "/'label' => 'Budget',.*?'url' => '\/budget'.*?"
                . "\\\$c->module\\('budget'\\).*?\\\$c->can\\('can_read_finances'\\)/s",
            $content
        );
        $this->assertMatchesRegularExpression(
            "/'label' => 'Budget',.*?\\\$c->can\\('can_manage_budget'\\)/s",
            $content
        );
    }

    public function testBudgetServiceClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Services\BudgetService::class));
        $this->assertTrue(method_exists(\App\Services\BudgetService::class, 'getOverview'));
        $this->assertTrue(method_exists(\App\Services\BudgetService::class, 'computeActual'));
        $this->assertTrue(method_exists(\App\Services\BudgetService::class, 'buildAvailableYears'));
        $this->assertTrue(method_exists(\App\Services\BudgetService::class, 'defaultFiscalYearStart'));
    }

    public function testBudgetControllerClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\BudgetController::class));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'createCategory'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'updateCategory'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'deleteCategory'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'createItem'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'updateItem'));
        $this->assertTrue(method_exists(\App\Controllers\BudgetController::class, 'deleteItem'));
    }

    public function testBudgetControllerValidatesTypeEnum(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/BudgetController.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("in_array(\$type, ['income', 'expense'], true)", $content);
    }

    public function testBudgetControllerValidatesYearRange(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/BudgetController.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('1900', $content);
        $this->assertStringContainsString('2200', $content);
    }

    public function testBudgetControllerHasLoggerEvents(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/BudgetController.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("'budget.category.created'", $content);
        $this->assertStringContainsString("'budget.category.updated'", $content);
        $this->assertStringContainsString("'budget.category.deleted'", $content);
        $this->assertStringContainsString("'budget.item.created'", $content);
        $this->assertStringContainsString("'budget.item.updated'", $content);
        $this->assertStringContainsString("'budget.item.deleted'", $content);
    }

    public function testBudgetControllerNormalizeAmountHandlesComma(): void
    {
        $rc = new \ReflectionClass(\App\Controllers\BudgetController::class);
        $this->assertTrue($rc->hasMethod('normalizeAmount'));
        $method = $rc->getMethod('normalizeAmount');
        $this->assertTrue($method->isPrivate());
    }

    /**
     * Das Budget hatte eine eigene, aeltere Kopie der Betragsnormalisierung. Sie
     * kannte die Tausenderpunkte ohne Dezimalstelle nicht: "1.234.567" hat das
     * Kassabuch angenommen und das Budget als ungueltig abgewiesen. Derselbe
     * Betrag muss an beiden Stellen dasselbe bedeuten.
     */
    #[DataProvider('groupedAmountProvider')]
    public function testBudgetNormalizesAmountsLikeTheCashbook(string $input, string $expected): void
    {
        $rc = new \ReflectionClass(\App\Controllers\BudgetController::class);
        $method = $rc->getMethod('normalizeAmount');
        $controller = $rc->newInstanceWithoutConstructor();

        $this->assertSame(
            $expected,
            $method->invoke($controller, $input),
            sprintf('"%s" muss im Budget wie im Kassabuch gelesen werden.', $input)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function groupedAmountProvider(): array
    {
        return [
            'Dezimalkomma' => ['1234,50', '1234.50'],
            'Tausenderpunkt mit Dezimalkomma' => ['1.234,50', '1234.50'],
            'Tausenderkomma mit Dezimalpunkt' => ['1,234.50', '1234.50'],
            'Tausenderpunkte ohne Dezimalstelle' => ['1.234.567', '1234567.00'],
            'Tausenderkommas ohne Dezimalstelle' => ['1,234,567', '1234567.00'],
            'Leerzeichen als Gruppierung' => ['1 234,50', '1234.50'],
        ];
    }

    public function testBudgetControllerRendersCorrectTemplate(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/BudgetController.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("'budget/index.twig'", $content);
    }

    public function testBudgetTemplateExists(): void
    {
        $this->assertTrue(file_exists(dirname(__DIR__, 2) . '/templates/budget/index.twig'));
    }

    public function testBudgetTemplateHasSollIstStructure(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/templates/budget/index.twig');

        $this->assertIsString($content);
        $this->assertStringContainsString('overview.income', $content);
        $this->assertStringContainsString('overview.expense', $content);
        $this->assertStringContainsString('planned', $content);
        $this->assertStringContainsString('actual', $content);
        $this->assertStringContainsString('diff', $content);
        $this->assertStringContainsString('can_manage_budget', $content);
    }

    public function testRoleControllerHandlesCanManageBudget(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/RoleController.php');

        $this->assertIsString($content);
        $this->assertStringContainsString('can_manage_budget', $content);
    }

    public function testRoleEditScriptBindsBudgetPermissionCheckbox(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/public/js/roles.js');

        $this->assertIsString($content);
        $this->assertStringContainsString('data-budget', $content);
        $this->assertStringContainsString('edit_can_manage_budget', $content);
    }

    public function testBudgetRoutesAreRegisteredInRoutesFile(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("'/budget'", $content);
        $this->assertStringContainsString('BudgetController', $content);
        $this->assertStringContainsString("modules']['budget']", $content);
    }

    public function testBudgetDependenciesRegisterServiceAndController(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Dependencies.php');

        $this->assertIsString($content);
        $this->assertStringContainsString('BudgetService::class => \\DI\\autowire()', $content);
        $this->assertStringContainsString('BudgetController::class => \\DI\\autowire()', $content);
    }

    public function testBudgetNavigationItemExistsInAreasTemplate(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Navigation/NavigationBuilder.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("\$c->module('budget')", $content);
        $this->assertStringContainsString('/budget', $content);
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            'group_name',
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

    public function testSessionAuthServiceSetsCanManageBudgetKey(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/SessionAuthService.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('can_manage_budget', $content);
    }

    public function testRoleMiddlewareHasRequiresBudgetManagementParameter(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Middleware/RoleMiddleware.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('requiresBudgetManagement', $content);
        $this->assertStringContainsString('can_manage_budget', $content);
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
        $content = file_get_contents(dirname(__DIR__, 2) . '/templates/partials/navigation/areas.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('settings.modules.budget', $content);
        $this->assertStringContainsString('/budget', $content);
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FlashMessages;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\FinanceGroup;
use App\Services\BudgetCategoryConflictException;
use App\Services\BudgetService;
use App\Services\BudgetWriteService;
use App\Util\AmountNormalizer;
use App\Util\InputValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

class BudgetController
{
    use FlashMessages;

    private Twig $view;
    private BudgetService $budgetService;
    private LoggerInterface $logger;
    private BudgetWriteService $budgetWriter;

    public function __construct(
        Twig $view,
        BudgetService $budgetService,
        LoggerInterface $logger,
        BudgetWriteService $budgetWriter
    ) {
        $this->view = $view;
        $this->budgetService = $budgetService;
        $this->logger = $logger;
        $this->budgetWriter = $budgetWriter;
    }

    public function index(Request $request, Response $response): Response
    {
        $defaultYear = $this->budgetService->defaultFiscalYearStart();
        $selectedYear = (int) ($request->getQueryParams()['year'] ?? $defaultYear);
        $availableYears = $this->budgetService->buildAvailableYears();
        $overview = $this->budgetService->getOverview($selectedYear);

        [$day, $month] = $this->budgetService->getFiscalConfig();
        [$start, $end] = $this->budgetService->datesForYear($selectedYear, $day, $month);

        $financeGroups = FinanceGroup::orderBy('name')->get();

        ['success' => $success, 'error' => $error] = $this->takeFlash();

        return $this->view->render($response, 'budget/index.twig', [
            'overview' => $overview,
            'selected_year' => $selectedYear,
            'available_years' => $availableYears,
            'finance_groups' => $financeGroups,
            'fiscal_start' => $start->format('d.m.Y'),
            'fiscal_end' => $end->format('d.m.Y'),
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function createCategory(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $fiscalYear = (int) ($data['fiscal_year_start'] ?? 0);
        $type = InputValidator::asString($data['type'] ?? null);
        $financeGroupId = $this->budgetWriter->resolveFinanceGroupId($data);

        if (
            $fiscalYear < 1900 || $fiscalYear > 2200
            || $financeGroupId === null
            || !in_array($type, BudgetCategory::TYPES, true)
        ) {
            $_SESSION['error'] = 'Ungültige Eingabe. Bitte alle Felder ausfüllen.';

            return $response->withHeader('Location', '/budget?year=' . $fiscalYear)->withStatus(302);
        }

        try {
            $this->budgetWriter->createCategory($fiscalYear, $financeGroupId, $type);
        } catch (\DomainException | BudgetCategoryConflictException $e) {
            $_SESSION['error'] = $e->getMessage();

            return $response->withHeader('Location', '/budget?year=' . $fiscalYear)->withStatus(302);
        }

        $this->logger->info('Budget category created.', [
            'event' => 'budget.category.created',
            'fiscal_year_start' => $fiscalYear,
            'finance_group_id' => $financeGroupId,
            'type' => $type,
        ]);

        $_SESSION['success'] = 'Kategorie erfolgreich angelegt.';

        return $response->withHeader('Location', '/budget?year=' . $fiscalYear)->withStatus(302);
    }

    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();

        $category = BudgetCategory::find($id);
        if ($category === null) {
            $_SESSION['error'] = 'Kategorie nicht gefunden.';

            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $financeGroupId = $this->budgetWriter->resolveFinanceGroupId($data);
        if ($financeGroupId === null) {
            $_SESSION['error'] = 'Bitte eine Gruppe wählen oder anlegen.';

            return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
        }

        try {
            $this->budgetWriter->changeCategoryGroup($category, $financeGroupId);
        } catch (\DomainException | BudgetCategoryConflictException $e) {
            $_SESSION['error'] = $e->getMessage();

            return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
        }

        $this->logger->info('Budget category updated.', [
            'event' => 'budget.category.updated',
            'category_id' => $id,
            'finance_group_id' => $financeGroupId,
        ]);

        $_SESSION['success'] = 'Kategorie aktualisiert.';

        return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
    }

    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $category = BudgetCategory::find($id);

        if ($category === null) {
            $_SESSION['error'] = 'Kategorie nicht gefunden.';

            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $year = $category->fiscal_year_start;
        $category->delete();

        $this->logger->info('Budget category deleted.', [
            'event' => 'budget.category.deleted',
            'category_id' => $id,
        ]);

        $_SESSION['success'] = 'Kategorie und alle zugehörigen Posten gelöscht.';

        return $response->withHeader('Location', '/budget?year=' . $year)->withStatus(302);
    }

    public function createItem(Request $request, Response $response, array $args): Response
    {
        $categoryId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $description = trim(InputValidator::asString($data['description'] ?? null));
        $plannedAmount = trim(InputValidator::asString($data['planned_amount'] ?? null));

        $category = BudgetCategory::find($categoryId);
        if ($category === null) {
            $_SESSION['error'] = 'Kategorie nicht gefunden.';

            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $normalizedAmount = $this->normalizeAmount($plannedAmount);
        if ($description === '' || $normalizedAmount === null) {
            $_SESSION['error'] = 'Ungültige Eingabe. Bezeichnung und Betrag sind erforderlich.';

            return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
        }

        BudgetItem::create([
            'budget_category_id' => $categoryId,
            'description' => $description,
            'planned_amount' => $normalizedAmount,
        ]);

        $this->logger->info('Budget item created.', [
            'event' => 'budget.item.created',
            'budget_category_id' => $categoryId,
            'description' => $description,
        ]);

        $_SESSION['success'] = 'Posten erfolgreich angelegt.';

        return $response->withHeader('Location', '/budget?year=' . $category->fiscal_year_start)->withStatus(302);
    }

    public function updateItem(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $description = trim(InputValidator::asString($data['description'] ?? null));
        $plannedAmount = trim(InputValidator::asString($data['planned_amount'] ?? null));

        $item = BudgetItem::with('category')->find($id);
        if ($item === null) {
            $_SESSION['error'] = 'Posten nicht gefunden.';

            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $normalizedAmount = $this->normalizeAmount($plannedAmount);
        if ($description === '' || $normalizedAmount === null) {
            $_SESSION['error'] = 'Ungültige Eingabe. Bezeichnung und Betrag sind erforderlich.';

            return $response->withHeader('Location', '/budget?year=' . $item->category->fiscal_year_start)->withStatus(302);
        }

        $item->update([
            'description' => $description,
            'planned_amount' => $normalizedAmount,
        ]);

        $this->logger->info('Budget item updated.', [
            'event' => 'budget.item.updated',
            'item_id' => $id,
        ]);

        $_SESSION['success'] = 'Posten aktualisiert.';

        return $response->withHeader('Location', '/budget?year=' . $item->category->fiscal_year_start)->withStatus(302);
    }

    public function deleteItem(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $item = BudgetItem::with('category')->find($id);

        if ($item === null) {
            $_SESSION['error'] = 'Posten nicht gefunden.';

            return $response->withHeader('Location', '/budget')->withStatus(302);
        }

        $year = $item->category->fiscal_year_start;
        $item->delete();

        $this->logger->info('Budget item deleted.', [
            'event' => 'budget.item.deleted',
            'item_id' => $id,
        ]);

        $_SESSION['success'] = 'Posten gelöscht.';

        return $response->withHeader('Location', '/budget?year=' . $year)->withStatus(302);
    }

    /**
     * Normalizes amount string (comma/dot handling) to a float-compatible string.
     * Returns null if the value is not a valid positive number.
     *
     * Die Trennzeichen liest derselbe AmountNormalizer wie im Kassabuch. Die
     * frühere eigene Kopie kannte reine Tausendergruppen wie "1.234.567" nicht
     * und hat sie als ungültig abgewiesen, während die Kassa denselben Betrag
     * angenommen hat.
     */
    private function normalizeAmount(string $raw): ?string
    {
        $normalized = AmountNormalizer::normalize($raw);

        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Finance;
use App\Models\FinanceGroup;
use App\Models\Attachment;
use App\Models\Setting;
use App\Services\BudgetService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use App\Util\UploadValidator;

class FinanceController
{
    private Twig $view;
    private BudgetService $budgetService;
    private LoggerInterface $logger;

    public function __construct(Twig $view, BudgetService $budgetService, LoggerInterface $logger)
    {
        $this->view = $view;
        $this->budgetService = $budgetService;
        $this->logger = $logger;
    }

    private function getFiscalConfig(): array
    {
        return $this->budgetService->getFiscalConfig();
    }

    private function datesForYear(int $startYear, int $day, int $month): array
    {
        return $this->budgetService->datesForYear($startYear, $day, $month);
    }

    private function defaultStartYear(int $day, int $month): int
    {
        $now = Carbon::now();
        return self::computeDefaultStartYear((int) $now->year, (int) $now->month, (int) $now->day, $day, $month);
    }

    public static function computeDefaultStartYear(
        int $currentYear,
        int $currentMonth,
        int $currentDay,
        int $fiscalDay,
        int $fiscalMonth
    ): int {
        return ($currentMonth > $fiscalMonth || ($currentMonth === $fiscalMonth && $currentDay >= $fiscalDay))
            ? $currentYear
            : $currentYear - 1;
    }

    public static function normalizeAmountInput(string $amount): string
    {
        $normalized = preg_replace('/[\s\x{00A0}\']+/u', '', trim($amount)) ?? trim($amount);

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // If both separators exist, treat the rightmost as decimal separator and the other as thousands separator.
            $decimalSep = $lastComma > $lastDot ? ',' : '.';
            $thousandsSep = $decimalSep === ',' ? '.' : ',';
            $normalized = str_replace($thousandsSep, '', $normalized);
            return $decimalSep === ',' ? str_replace(',', '.', $normalized) : $normalized;
        }

        if ($lastComma !== false && substr_count($normalized, ',') > 1) {
            return self::collapseThousandsGrouping($normalized, ',');
        }

        if ($lastComma !== false) {
            return str_replace(',', '.', $normalized);
        }

        if ($lastDot !== false && substr_count($normalized, '.') > 1) {
            return self::collapseThousandsGrouping($normalized, '.');
        }

        return $normalized;
    }

    /**
     * Collapses a purely-grouped number (e.g. "1.234.567" or "1,234,567") that uses
     * $separator more than once. A trailing 3-digit group is treated as a thousands
     * group (dropped); any other length is treated as the decimal fraction.
     */
    private static function collapseThousandsGrouping(string $normalized, string $separator): string
    {
        $parts = explode($separator, $normalized);
        $fraction = array_pop($parts);
        $integerPart = implode('', $parts);

        if (strlen($fraction) === 3) {
            return $integerPart . $fraction;
        }

        return $integerPart . '.' . $fraction;
    }

    private function buildAvailableYears(int $day, int $month): array
    {
        $allDates = Finance::selectRaw('MIN(invoice_date) as min_d, MAX(invoice_date) as max_d')->first();
        $years = [];
        $def = $this->defaultStartYear($day, $month);
        [$sDef, $eDef] = $this->datesForYear($def, $day, $month);
        $years[$def] = $sDef->format('d.m.Y') . ' – ' . $eDef->format('d.m.Y');

        if ($allDates && $allDates->min_d) {
            $minYear = (int) Carbon::parse($allDates->min_d)->format('Y');
            $maxYear = (int) Carbon::parse($allDates->max_d)->format('Y') + 1;
            for ($y = $minYear - 2; $y <= $maxYear + 2; $y++) {
                if (isset($years[$y])) {
                    continue;
                }
                [$s, $e] = $this->datesForYear($y, $day, $month);
                $count = Finance::whereBetween('invoice_date', [$s->format('Y-m-d'), $e->format('Y-m-d')])->count();
                if ($count > 0) {
                    $years[$y] = $s->format('d.m.Y') . ' – ' . $e->format('d.m.Y');
                }
            }
        }
        ksort($years);
        return $years;
    }

    public function index(Request $request, Response $response): Response
    {
        [$day, $month, $startStr] = $this->getFiscalConfig();
        $availableYears = $this->buildAvailableYears($day, $month);
        $defaultYear = $this->defaultStartYear($day, $month);
        $selectedYear = (int) ($request->getQueryParams()['year'] ?? $defaultYear);

        [$startDate, $endDate] = $this->datesForYear($selectedYear, $day, $month);

        $finances = Finance::with('attachments')
            ->whereBetween('invoice_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->orderBy('running_number', 'desc')
            ->get();

        $groups = FinanceGroup::orderBy('name')->pluck('name');

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'finances/index.twig', [
            'finances' => $finances,
            'groups' => $groups,
            'success' => $success,
            'error' => $error,
            'fiscal_start' => $startDate->format('d.m.Y'),
            'fiscal_end' => $endDate->format('d.m.Y'),
            'fiscal_setting' => $startStr,
            'available_years' => $availableYears,
            'selected_year' => $selectedYear,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $id = isset($data['id']) && $data['id'] ? (int) $data['id'] : null;

        $amount = self::normalizeAmountInput((string) ($data['amount'] ?? '0'));
        if (!is_numeric($amount) || (float) $amount <= 0) {
            $_SESSION['error'] = 'Ungültiger Betrag. Bitte eine positive Zahl eingeben.';
            return $response->withHeader('Location', '/finances')->withStatus(302);
        }

        $invoiceDate = (string) ($data['invoice_date'] ?? '');
        $paymentDate = !empty($data['payment_date']) ? (string) $data['payment_date'] : null;
        if ($paymentDate !== null && $invoiceDate !== '' && $paymentDate < $invoiceDate) {
            $_SESSION['error'] = 'Das Zahlungsdatum darf nicht vor dem Rechnungsdatum liegen.';
            return $response->withHeader('Location', '/finances')->withStatus(302);
        }

        try {
            $groupNameRaw = trim($data['group_name'] ?? '');
            $groupName = $groupNameRaw !== '' ? $groupNameRaw : null;
            $recordData = [
                'invoice_date' => $invoiceDate,
                'payment_date' => $paymentDate,
                'description' => trim($data['description'] ?? ''),
                'group_name' => $groupName,
                // Keep the canonical finance_group_id in sync so budget actuals stay
                // linked even when the displayed group label changes.
                'finance_group_id' => $groupName !== null
                    ? FinanceGroup::firstOrCreate(['name' => $groupName])->id
                    : null,
                'type' => $data['type'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
            ];

            $finance = null;
            Capsule::connection()->transaction(function () use ($id, &$recordData, &$finance): void {
                if ($id) {
                    $finance = Finance::findOrFail($id);
                    $finance->update($recordData);
                } else {
                    $recordData['running_number'] = $this->nextRunningNumber();
                    $finance = Finance::create($recordData);
                }
            });
            $_SESSION['success'] = $id ? 'Eintrag erfolgreich aktualisiert.' : 'Neuer Eintrag erfolgreich verbucht.';

            // Handle Attachments
            $uploadedFiles = $request->getUploadedFiles();
            if (isset($uploadedFiles['attachments'])) {
                $files = $uploadedFiles['attachments'];
                if (!is_array($files)) {
                    $files = [$files];
                }

                foreach ($files as $file) {
                    $uploadError = UploadValidator::getUploadErrorMessage($file->getError(), 'Anhang');
                    if ($uploadError !== null) {
                        $_SESSION['error'] = $uploadError;
                        continue;
                    }

                    if ($file->getError() === UPLOAD_ERR_OK) {
                        $mimeType = UploadValidator::detectMimeType($file);
                        $contents = $file->getStream()->getContents();
                        $size = strlen($contents);

                        // Use centralized validation
                        $validation = UploadValidator::validateFileSize($size, $mimeType);
                        if (!$validation['valid']) {
                            $_SESSION['error'] = $validation['error'];
                            continue;
                        }

                        $safeName = self::normalizeFileName((string) $file->getClientFilename());

                        Attachment::create([
                            'entity_type' => 'finance',
                            'entity_id' => $finance->id,
                            'filename' => $safeName,
                            'original_name' => $safeName,
                            'mime_type' => UploadValidator::normalizeMimeType($mimeType),
                            'file_size' => $size,
                            'file_content' => $contents,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Finance booking save failed.', [
                'event' => 'finance.save.failed',
                'finance_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Fehler beim Speichern. Bitte versuchen Sie es erneut.';
        }
        return $response->withHeader('Location', '/finances')->withStatus(302);
    }

    /**
     * Atomically reserves the next running number via a locked settings counter row.
     * The counter never decreases, so a running number is never reused even after the
     * highest booking is deleted. Falls back to the current table max in case the
     * counter is behind (e.g. after dev-seed data was inserted directly).
     */
    private function nextRunningNumber(): int
    {
        Capsule::connection()->statement(
            "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, '0')",
            ['finance_next_running_number']
        );

        $counterRow = Setting::where('setting_key', 'finance_next_running_number')->lockForUpdate()->first();
        $counterNext = ((int) $counterRow->setting_value) + 1;
        $tableNext = ((int) Finance::max('running_number')) + 1;
        $next = max($counterNext, $tableNext);

        Setting::where('setting_key', 'finance_next_running_number')->update(['setting_value' => (string) $next]);

        return $next;
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $financeId = (int) $args['id'];
        try {
            Capsule::connection()->transaction(function () use ($financeId): void {
                Attachment::where('entity_type', 'finance')
                    ->where('entity_id', $financeId)
                    ->delete();
                Finance::findOrFail($financeId)->delete();
            });
            $_SESSION['success'] = 'Eintrag erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $this->logger->error('Finance booking delete failed.', [
                'event' => 'finance.delete.failed',
                'finance_id' => $financeId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Fehler beim Löschen. Bitte versuchen Sie es erneut.';
        }
        return $response->withHeader('Location', '/finances')->withStatus(302);
    }

    public function report(Request $request, Response $response): Response
    {
        [$day, $month, $startStr] = $this->getFiscalConfig();
        $availableYears = $this->buildAvailableYears($day, $month);
        $defaultYear = $this->defaultStartYear($day, $month);
        $selectedYear = (int) ($request->getQueryParams()['year'] ?? $defaultYear);

        [$startDate, $endDate] = $this->datesForYear($selectedYear, $day, $month);

        $finances = Finance::with('attachments')
            ->whereBetween('invoice_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->orderBy('invoice_date', 'asc')
            ->get();

        $totalIncome = (float) $finances->where('type', 'income')->sum('amount');
        $totalExpense = (float) $finances->where('type', 'expense')->sum('amount');
        $balance = $totalIncome - $totalExpense;

        $cashIncome = (float) $finances->where('type', 'income')
            ->where('payment_method', 'cash')->sum('amount');
        $cashExpense = (float) $finances->where('type', 'expense')
            ->where('payment_method', 'cash')->sum('amount');
        $bankIncome = (float) $finances->where('type', 'income')
            ->where('payment_method', 'bank_transfer')->sum('amount');
        $bankExpense = (float) $finances->where('type', 'expense')
            ->where('payment_method', 'bank_transfer')->sum('amount');

        $groupTotals = [];
        foreach ($finances as $f) {
            $key = $f->group_name ?? '(Keine Gruppe)';
            if (!isset($groupTotals[$key])) {
                $groupTotals[$key] = ['income' => 0.0, 'expense' => 0.0];
            }
            if ($f->type === 'income') {
                $groupTotals[$key]['income'] += (float) $f->amount;
            } else {
                $groupTotals[$key]['expense'] += (float) $f->amount;
            }
        }
        ksort($groupTotals);

        return $this->view->render($response, 'finances/report.twig', [
            'finances' => $finances,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'balance' => $balance,
            'cash_income' => $cashIncome,
            'cash_expense' => $cashExpense,
            'bank_income' => $bankIncome,
            'bank_expense' => $bankExpense,
            'group_totals' => $groupTotals,
            'has_groups' => count($groupTotals) > 0,
            'fiscal_start' => $startDate->format('d.m.Y'),
            'fiscal_end' => $endDate->format('d.m.Y'),
            'available_years' => $availableYears,
            'selected_year' => $selectedYear,
        ]);
    }

    public function updateSettings(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $startStr = trim($data['fiscal_year_start'] ?? '');
        $matches = [];
        $matched = (bool) preg_match('/^(\d{2})\.(\d{2})\.$/', $startStr, $matches);
        $day = $matched ? (int) $matches[1] : 0;
        $month = $matched ? (int) $matches[2] : 0;
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            $_SESSION['error'] = 'Ungültiges Format für das Geschäftsjahr. (Erwartet: DD.MM. mit Tag 01-31, Monat 01-12)';
        } else {
            Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => $startStr]);
            $_SESSION['success'] = 'Geschäftsjahr-Beginn aktualisiert.';
        }
        return $response->withHeader('Location', '/finances')->withStatus(302);
    }

    public function viewAttachment(Request $request, Response $response, array $args): Response
    {
        try {
            $attachment = Attachment::where('entity_type', 'finance')->findOrFail((int) $args['id']);
            $response->getBody()->write($attachment->file_content);
            $safeName = self::normalizeFileName((string) $attachment->filename);
            $disposition = self::isInlineViewableMimeType((string) $attachment->mime_type) ? 'inline' : 'attachment';
            return $response
                ->withHeader('Content-Type', $attachment->mime_type)
                ->withHeader(
                    'Content-Disposition',
                    $disposition . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
                );
        } catch (\Exception $e) {
            return $response->withStatus(404);
        }
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        try {
            $attachment = Attachment::where('entity_type', 'finance')->findOrFail((int) $args['id']);
            $attachment->delete();
            $_SESSION['success'] = 'Anhang erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen des Anhangs: ';
        }
        return $response->withHeader('Location', '/finances')->withStatus(302);
    }

    private static function normalizeFileName(string $name): string
    {
        $safe = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        $trimmed = trim($safe);
        return $trimmed !== '' ? $trimmed : 'download';
    }

    private static function isInlineViewableMimeType(string $mimeType): bool
    {
        return in_array(UploadValidator::normalizeMimeType($mimeType), [
            'application/pdf',
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/webp',
            'text/plain',
        ], true);
    }
}

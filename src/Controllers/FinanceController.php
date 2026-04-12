<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Finance;
use App\Models\Attachment;
use App\Models\Setting;
use Carbon\Carbon;
use Psr\Http\Message\UploadedFileInterface;
use App\Util\UploadValidator;

class FinanceController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    private function getFiscalConfig(): array
    {
        $setting = Setting::find('fiscal_year_start');
        $startStr = $setting ? $setting->setting_value : '01.09.';
        $parts = explode('.', $startStr);
        $day = (int) ($parts[0] ?? 1);
        $month = (int) ($parts[1] ?? 9);
        return [$day, $month, $startStr];
    }

    private function datesForYear(int $startYear, int $day, int $month): array
    {
        $start = Carbon::create($startYear, $month, $day, 0, 0, 0);
        $end = Carbon::create($startYear + 1, $month, $day, 0, 0, 0)->subDay();
        return [$start, $end];
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

        if ($lastComma !== false) {
            return str_replace(',', '.', $normalized);
        }

        if ($lastDot !== false && substr_count($normalized, '.') > 1) {
            $parts = explode('.', $normalized);
            $fraction = array_pop($parts);
            $integerPart = implode('', $parts);

            if (strlen($fraction) === 3) {
                // Likely pure thousands grouping, e.g. 1.234.567
                return $integerPart . $fraction;
            }

            return $integerPart . '.' . $fraction;
        }

        return $normalized;
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

        $groups = Finance::whereNotNull('group_name')->distinct()->pluck('group_name')->sort()->values();

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
        try {
            $recordData = [
                'invoice_date' => $data['invoice_date'],
                'payment_date' => !empty($data['payment_date']) ? $data['payment_date'] : null,
                'description' => trim($data['description'] ?? ''),
                'group_name' => !empty(trim($data['group_name'] ?? '')) ? trim($data['group_name']) : null,
                'type' => $data['type'],
                'amount' => self::normalizeAmountInput((string) ($data['amount'] ?? '0')),
                'payment_method' => $data['payment_method'],
            ];
            if ($id) {
                $finance = Finance::findOrFail($id);
                $finance->update($recordData);
                $_SESSION['success'] = 'Eintrag erfolgreich aktualisiert.';
            } else {
                $maxRunningNumber = Finance::max('running_number') ?? 0;
                $recordData['running_number'] = $maxRunningNumber + 1;
                $finance = Finance::create($recordData);
                $_SESSION['success'] = 'Neuer Eintrag erfolgreich verbucht.';
            }

            // Handle Attachments
            $uploadedFiles = $request->getUploadedFiles();
            if (isset($uploadedFiles['attachments'])) {
                $files = $uploadedFiles['attachments'];
                if (!is_array($files)) {
                    $files = [$files];
                }

                foreach ($files as $file) {
                    if ($file->getError() === UPLOAD_ERR_OK) {
                        $size = (int) $file->getSize();
                        $mimeType = trim((string) $file->getClientMediaType());

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
                            'mime_type' => $mimeType,
                            'file_content' => $file->getStream()->getContents(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Speichern: ' . $e->getMessage();
        }
        return $response->withHeader('Location', '/finances')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $financeId = (int) $args['id'];
            Attachment::where('entity_type', 'finance')
                ->where('entity_id', $financeId)
                ->delete();
            Finance::findOrFail($financeId)->delete();
            $_SESSION['success'] = 'Eintrag erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ' . $e->getMessage();
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
        if (!preg_match('/^\d{2}\.\d{2}\.$/', $startStr)) {
            $_SESSION['error'] = 'Ungültiges Format für das Geschäftsjahr. (Erwartet: DD.MM.)';
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
            return $response
                ->withHeader('Content-Type', $attachment->mime_type)
                ->withHeader(
                    'Content-Disposition',
                    'inline; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
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
            $_SESSION['error'] = 'Fehler beim Löschen des Anhangs: ' . $e->getMessage();
        }
        return $response->withHeader('Location', '/finances')->withStatus(302);
    }

    private static function normalizeFileName(string $name): string
    {
        $safe = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        $trimmed = trim($safe);
        return $trimmed !== '' ? $trimmed : 'download';
    }
}

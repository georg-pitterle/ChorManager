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
use App\Services\BankStatementImportService;
use App\Services\BudgetService;
use App\Services\FinanceReportPdfService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use App\Util\AmountNormalizer;
use App\Util\UploadValidator;

class FinanceController
{
    /** Seconds a parsed statement stays available for confirmation. */
    private const IMPORT_TTL = 3600;
    private const IMPORT_SESSION_KEY = 'finance_import';

    private Twig $view;
    private BudgetService $budgetService;
    private LoggerInterface $logger;
    private FinanceReportPdfService $pdfService;
    private BankStatementImportService $importService;

    public function __construct(
        Twig $view,
        BudgetService $budgetService,
        LoggerInterface $logger,
        FinanceReportPdfService $pdfService,
        BankStatementImportService $importService
    ) {
        $this->view = $view;
        $this->budgetService = $budgetService;
        $this->logger = $logger;
        $this->pdfService = $pdfService;
        $this->importService = $importService;
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
        return AmountNormalizer::normalize($amount);
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
                            $this->logger->warning('File upload rejected.', [
                                'event' => 'security.upload.rejected',
                                'reason' => $validation['reason'],
                            ]);
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
        return $this->reserveRunningNumbers(1);
    }

    /**
     * Reserves a contiguous block of running numbers in a single locked round trip
     * and returns the first number of that block. Bulk imports would otherwise have
     * to lock the counter row once per booking.
     */
    private function reserveRunningNumbers(int $count): int
    {
        $count = max(1, $count);

        Capsule::connection()->statement(
            "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, '0')",
            ['finance_next_running_number']
        );

        $counterRow = Setting::where('setting_key', 'finance_next_running_number')->lockForUpdate()->first();
        $counterNext = ((int) $counterRow->setting_value) + 1;
        $tableNext = ((int) Finance::max('running_number')) + 1;
        $first = max($counterNext, $tableNext);

        Setting::where('setting_key', 'finance_next_running_number')
            ->update(['setting_value' => (string) ($first + $count - 1)]);

        return $first;
    }

    /**
     * Step 1 of the bank statement import: validate and parse the upload, then show
     * the parsed rows for review. Nothing is persisted here.
     */
    public function importPreview(Request $request, Response $response): Response
    {
        $uploadedFile = $request->getUploadedFiles()['statement'] ?? null;
        if (!$uploadedFile instanceof UploadedFileInterface) {
            $_SESSION['error'] = 'Bitte eine CSV-Datei auswählen.';
            return $response->withHeader('Location', '/finances')->withStatus(302);
        }

        $uploadError = UploadValidator::getUploadErrorMessage($uploadedFile->getError(), 'Kontoauszug');
        if ($uploadError !== null || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = $uploadError ?? 'Der Kontoauszug konnte nicht hochgeladen werden.';
            return $response->withHeader('Location', '/finances')->withStatus(302);
        }

        $filename = self::normalizeFileName((string) $uploadedFile->getClientFilename());
        $validationError = BankStatementImportService::validateUpload(
            $filename,
            (int) $uploadedFile->getSize(),
            UploadValidator::detectMimeType($uploadedFile)
        );
        if ($validationError !== null) {
            $this->logger->warning('Bank statement upload rejected.', [
                'event' => 'security.upload.rejected',
                'reason' => 'bank_statement_invalid_file',
            ]);
            $_SESSION['error'] = $validationError;
            return $response->withHeader('Location', '/finances')->withStatus(302);
        }

        $parsed = $this->importService->parse($uploadedFile->getStream()->getContents());
        if ($parsed['errors'] !== []) {
            $_SESSION['error'] = implode(' ', $parsed['errors']);
            return $response->withHeader('Location', '/finances')->withStatus(302);
        }

        $rows = $this->flagKnownRows($parsed['rows']);
        $_SESSION[self::IMPORT_SESSION_KEY] = [
            'filename' => $filename,
            'created_at' => time(),
            'rows' => $rows,
        ];

        return $this->view->render($response, 'finances/import.twig', [
            'filename' => $filename,
            'rows' => $rows,
            'groups' => FinanceGroup::orderBy('name')->pluck('name'),
            'importable_count' => count(array_filter($rows, static fn(array $r): bool => $r['importable'])),
            'duplicate_count' => count(array_filter($rows, static fn(array $r): bool => $r['duplicate'])),
            'error_count' => count(array_filter($rows, static fn(array $r): bool => $r['error'] !== null)),
        ]);
    }

    /**
     * Step 2 of the bank statement import: persist the rows the user selected.
     * Amounts and dates are read from the stashed session payload only, never from
     * the posted form, so the review step cannot be tampered with in the browser.
     */
    public function importConfirm(Request $request, Response $response): Response
    {
        $payload = $_SESSION[self::IMPORT_SESSION_KEY] ?? null;
        $createdAt = is_array($payload) ? (int) ($payload['created_at'] ?? 0) : 0;
        if (!is_array($payload) || !is_array($payload['rows'] ?? null) || time() - $createdAt > self::IMPORT_TTL) {
            unset($_SESSION[self::IMPORT_SESSION_KEY]);
            $_SESSION['error'] = 'Der Import ist abgelaufen. Bitte die Datei erneut hochladen.';
            return $response->withHeader('Location', '/finances')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $selected = array_map('intval', (array) ($data['selected'] ?? []));
        $postedGroups = (array) ($data['group'] ?? []);

        // Erneut gegen die Datenbank prüfen: zwischen Vorschau und Übernahme kann
        // jemand anderes dieselben Zeilen importiert haben.
        $rows = $this->flagKnownRows($payload['rows']);

        $queued = [];
        $skipped = 0;
        foreach ($selected as $index) {
            $row = $rows[$index] ?? null;
            if (!is_array($row)) {
                continue;
            }
            if (!$row['importable']) {
                $skipped++;
                continue;
            }

            $groupName = trim((string) ($postedGroups[$index] ?? ''));
            $row['group_name'] = $groupName !== '' ? $groupName : null;
            $queued[] = $row;
        }

        $imported = 0;
        try {
            if ($queued !== []) {
                Capsule::connection()->transaction(function () use ($queued, &$imported): void {
                    $runningNumber = $this->reserveRunningNumbers(count($queued));
                    foreach ($queued as $row) {
                        $groupName = $row['group_name'];
                        Finance::create([
                            'running_number' => $runningNumber,
                            'invoice_date' => $row['invoice_date'],
                            'payment_date' => $row['payment_date'],
                            'description' => $row['description'],
                            'group_name' => $groupName,
                            // Kanonische Gruppen-ID mitschreiben, sonst fehlen die
                            // importierten Buchungen in den Budget-Ist-Werten.
                            'finance_group_id' => $groupName !== null
                                ? FinanceGroup::firstOrCreate(['name' => $groupName])->id
                                : null,
                            'type' => $row['type'],
                            'amount' => $row['amount'],
                            'payment_method' => 'bank_transfer',
                            'import_hash' => $row['import_hash'],
                        ]);
                        $runningNumber++;
                        $imported++;
                    }
                });
            }
        } catch (\Exception $e) {
            $this->logger->error('Bank statement import failed.', [
                'event' => 'finance.import.failed',
                'exception' => $e,
            ]);
            unset($_SESSION[self::IMPORT_SESSION_KEY]);
            $_SESSION['error'] = 'Fehler beim Import. Es wurde keine Buchung angelegt.';
            return $response->withHeader('Location', '/finances')->withStatus(302);
        }

        unset($_SESSION[self::IMPORT_SESSION_KEY]);
        $this->logger->info('Bank statement import completed.', [
            'event' => 'finance.import.completed',
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
        $_SESSION['success'] = sprintf('%d Buchungen importiert, %d übersprungen.', $imported, $skipped);

        return $response->withHeader('Location', '/finances')->withStatus(302);
    }

    public function importCancel(Request $request, Response $response): Response
    {
        unset($_SESSION[self::IMPORT_SESSION_KEY]);
        $_SESSION['success'] = 'Import abgebrochen. Es wurde keine Buchung angelegt.';

        return $response->withHeader('Location', '/finances')->withStatus(302);
    }

    /**
     * Marks rows whose import hash is already stored, so neither the preview nor the
     * confirmation step can create the same booking twice.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function flagKnownRows(array $rows): array
    {
        $hashes = array_values(array_filter(array_column($rows, 'import_hash')));
        $known = $hashes === []
            ? []
            : Finance::whereIn('import_hash', $hashes)->pluck('import_hash')->all();

        foreach ($rows as $index => $row) {
            $hash = $row['import_hash'] ?? null;
            $duplicate = $hash !== null && in_array($hash, $known, true);
            $rows[$index]['duplicate'] = $duplicate;
            $rows[$index]['importable'] = !$duplicate && $hash !== null && ($row['error'] ?? null) === null;
        }

        return $rows;
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
        [$day, $month] = $this->getFiscalConfig();
        $selectedYear = (int) ($request->getQueryParams()['year'] ?? $this->defaultStartYear($day, $month));

        return $this->view->render($response, 'finances/report.twig', $this->buildReportData($selectedYear));
    }

    public function reportPdf(Request $request, Response $response): Response
    {
        [$day, $month] = $this->getFiscalConfig();
        $selectedYear = (int) ($request->getQueryParams()['year'] ?? $this->defaultStartYear($day, $month));

        $reportData = $this->buildReportData($selectedYear);
        $pdf = $this->pdfService->render($reportData);
        $filename = $this->pdfService->filename($reportData);

        $response->getBody()->write($pdf);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . self::normalizeFileName($filename) . '"'
                    . '; filename*=UTF-8\'\'' . rawurlencode($filename)
            );
    }

    private function buildReportData(int $selectedYear): array
    {
        [$day, $month, $startStr] = $this->getFiscalConfig();
        $availableYears = $this->buildAvailableYears($day, $month);
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

        return [
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
        ];
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

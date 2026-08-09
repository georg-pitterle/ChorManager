# Finanzen Geschäftsjahr-PDF Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aus dem Finanzbereich ein PDF eines Geschäftsjahres (Kennzahlen + Bewegungen, mit Übertrag/Carry-forward bei jedem Seitenwechsel) als Download erzeugen.

**Architecture:** Die Datensammlung wird aus `FinanceController::report()` in `buildReportData()` extrahiert und von Screen + PDF geteilt. Die gesamte Carry-forward-Logik (Textumbruch → Zeilenhöhe, Seiten-Aufteilung, kumulierte Überträge) liegt in **reinen, tc-lib-pdf-freien** Klassen und wird gegen ein selbst definiertes `PdfCanvas`-Interface (Fake im Test) geprüft. tc-lib-pdf steckt ausschließlich im dünnen Adapter `TcLibPdfCanvas`.

**Tech Stack:** PHP 8.5, Slim 4, PHP-DI (Autowiring), Eloquent (Capsule), Twig, PHPUnit, `tecnickcom/tc-lib-pdf ^8`.

## Global Constraints

- PHP: PSR-12, 4 Spaces, keine Tabs, Soft-Limit 120 / Hard-Limit 130 Zeichen.
- Logging: `Psr\Log\LoggerInterface`, strukturierte JSON-Logs, stabiler `event`-Key; kein `error_log()` in `src/`.
- Kein `git push` (manuell durch den Entwickler).
- Neue Textdateien mit **LF** (außer `.bat`/`.cmd`/`.ps1`); nach jedem Schreiben normalisieren:
  `$f="<path>"; perl -i -pe 's/\r\n/\n/g' "$f"`
- Deutsche Texte mit echten Umlauten (ä/ö/ü/ß), nie ae/oe/ue/ss.
- Route zum Ausdrucken gehört in die **Read**-Gruppe (`requiresFinanceRead`) — Ausdrucken ist Lesen.
- Hilfetexte: keine konkreten Rollennamen; auf das Recht „Finanzen lesen" verweisen.
- Kein neues Seed nötig (keine neue Persistenz) — im Abschluss bewusst als „nicht zutreffend" quittieren.
- Betrags-/Zahlenformat deutsch: `number_format(2, ',', '.')`.
- Coordinate-Konvention im PDF-Code: Y wächst **nach unten**, Einheit Punkte; `contentTop` < `contentBottom`.

---

### Task 1: Dependency + PdfCanvas-Interface + tc-lib-pdf-Adapter

**Files:**
- Modify: `composer.json` / `composer.lock` (via `ddev composer require`)
- Create: `src/Services/Pdf/PdfCanvas.php`
- Create: `src/Services/Pdf/TcLibPdfCanvas.php`
- Test: `tests/Feature/Pdf/TcLibPdfCanvasSmokeTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces: `App\Services\Pdf\PdfCanvas` mit exakt diesen Methoden (alle anderen Tasks rendern nur gegen dieses Interface):
  ```php
  public function addPage(): void;
  public function contentTop(): float;
  public function contentBottom(): float;
  public function contentLeft(): float;
  public function contentWidth(): float;
  public function stringWidth(string $text, float $fontSize): float;
  public function text(float $x, float $y, string $text, float $fontSize, string $style = ''): void; // style '' | 'B'
  public function line(float $x1, float $y1, float $x2, float $y2): void;
  public function pageCount(): int;
  public function output(): string; // rohe PDF-Bytes, beginnend mit "%PDF"
  ```
  Und die konkrete Implementierung `App\Services\Pdf\TcLibPdfCanvas` (Konstruktor ohne Pflichtargumente, autowirebar).

- [ ] **Step 1: Dependency installieren**

Run:
```bash
ddev composer require tecnickcom/tc-lib-pdf:^8
```
Expected: composer schreibt `tecnickcom/tc-lib-pdf` (+ tc-lib-* Abhängigkeiten) in `composer.json`/`composer.lock`, Exit 0.

- [ ] **Step 2: Interface schreiben**

Create `src/Services/Pdf/PdfCanvas.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * Minimale Zeichenfläche für das Finanz-PDF. Kapselt tc-lib-pdf, damit die
 * Umbruch-/Übertrag-Logik rein und ohne PDF-Engine testbar bleibt.
 * Koordinaten in Punkten, Y wächst nach unten, contentTop < contentBottom.
 */
interface PdfCanvas
{
    public function addPage(): void;

    public function contentTop(): float;

    public function contentBottom(): float;

    public function contentLeft(): float;

    public function contentWidth(): float;

    public function stringWidth(string $text, float $fontSize): float;

    /** @param string $style '' für normal, 'B' für fett */
    public function text(float $x, float $y, string $text, float $fontSize, string $style = ''): void;

    public function line(float $x1, float $y1, float $x2, float $y2): void;

    public function pageCount(): int;

    /** @return string rohe PDF-Bytes, beginnend mit "%PDF" */
    public function output(): string;
}
```

- [ ] **Step 3: Smoke-Test schreiben (pinnt die reale tc-lib-pdf-API)**

Create `tests/Feature/Pdf/TcLibPdfCanvasSmokeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\PdfCanvas;
use App\Services\Pdf\TcLibPdfCanvas;
use PHPUnit\Framework\TestCase;

final class TcLibPdfCanvasSmokeTest extends TestCase
{
    public function testProducesMultiPagePdfBytes(): void
    {
        $canvas = new TcLibPdfCanvas();
        $this->assertInstanceOf(PdfCanvas::class, $canvas);

        $canvas->addPage();
        $canvas->text($canvas->contentLeft(), $canvas->contentTop(), 'Übertrag-Test äöüß', 10.0, 'B');
        $canvas->line($canvas->contentLeft(), $canvas->contentTop() + 4, $canvas->contentLeft() + 100, $canvas->contentTop() + 4);
        $canvas->addPage();
        $canvas->text($canvas->contentLeft(), $canvas->contentTop(), 'Seite 2', 10.0);

        $bytes = $canvas->output();

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(2, $canvas->pageCount());
        $this->assertGreaterThan(0.0, $canvas->stringWidth('abc', 10.0));
    }
}
```

- [ ] **Step 4: Test rot laufen lassen**

Run: `ddev exec ./vendor/bin/phpunit --filter TcLibPdfCanvasSmokeTest`
Expected: FAIL — `TcLibPdfCanvas` existiert noch nicht.

- [ ] **Step 5: Adapter implementieren**

Create `src/Services/Pdf/TcLibPdfCanvas.php`. tc-lib-pdf-Hauptklasse ist `\Com\Tecnick\Pdf\Tcpdf`. **Beim Umsetzen die exakten Methodennamen an der installierten Version (≥ 8.68) gegen `vendor/tecnickcom/tc-lib-pdf/example/` und die `$pdf->page`/`$pdf->font`/`$pdf->graph`-Komponenten verifizieren** und hier einsetzen. Zielverhalten pro Methode:

```php
<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Com\Tecnick\Pdf\Tcpdf;

/**
 * tc-lib-pdf-Adapter. A4 Hochformat, feste Ränder. Übersetzt die neutrale
 * PdfCanvas-Geometrie (Y nach unten, Punkte) auf die tc-lib-pdf-API.
 */
final class TcLibPdfCanvas implements PdfCanvas
{
    private const MARGIN = 40.0;          // Punkte
    private const PAGE_WIDTH = 595.28;    // A4 Breite in pt
    private const PAGE_HEIGHT = 841.89;   // A4 Höhe in pt
    private const FOOTER_RESERVE = 24.0;  // Platz unten für Seitenfuß

    private Tcpdf $pdf;
    private int $fontId;
    private int $fontBoldId;
    private int $pages = 0;

    public function __construct()
    {
        // K_PATH_FONTS wird von tc-lib-pdf-font erwartet; Standard-Fonts von
        // tc-lib-pdf nutzen (dejavusans deckt Umlaute vollständig ab).
        $this->pdf = new Tcpdf('pt', 'A4', true, 'UTF-8');
        $this->fontId = $this->pdf->font->insert($this->pdf->getCurrentStyleArray(), 'dejavusans', '', 10);
        $this->fontBoldId = $this->pdf->font->insert($this->pdf->getCurrentStyleArray(), 'dejavusans', 'B', 10);
    }

    public function addPage(): void
    {
        $this->pdf->page->add();
        $this->pages++;
    }

    public function contentTop(): float
    {
        return self::MARGIN;
    }

    public function contentBottom(): float
    {
        return self::PAGE_HEIGHT - self::MARGIN - self::FOOTER_RESERVE;
    }

    public function contentLeft(): float
    {
        return self::MARGIN;
    }

    public function contentWidth(): float
    {
        return self::PAGE_WIDTH - (2 * self::MARGIN);
    }

    public function stringWidth(string $text, float $fontSize): float
    {
        return $this->pdf->font->getCharsWidth($text) * ($fontSize / 10.0);
    }

    public function text(float $x, float $y, string $text, float $fontSize, string $style = ''): void
    {
        $this->pdf->setFont('dejavusans', $style, $fontSize);
        // tc-lib-pdf misst Y vom oberen Rand; Baseline-Offset über Fonthöhe.
        $this->pdf->addTextCell($text, 0, $x, $y, 0, $fontSize + 2, 0, 'T', 'L');
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->pdf->graph->getLine($x1, $y1, $x2, $y2);
    }

    public function pageCount(): int
    {
        return $this->pages;
    }

    public function output(): string
    {
        return $this->pdf->getOutPDFString();
    }
}
```
Hinweis: `addTextCell`, `getLine`, `getCharsWidth`, `font->insert`, `page->add`, `getOutPDFString` sind die dokumentierten tc-lib-pdf-Bausteine — falls eine Signatur in 8.68 abweicht, an den Beispielen unter `vendor/tecnickcom/tc-lib-pdf/example/` ausrichten, bis der Smoke-Test grün ist. Das Interface bleibt unverändert.

- [ ] **Step 6: Test grün laufen lassen**

Run: `ddev exec ./vendor/bin/phpunit --filter TcLibPdfCanvasSmokeTest`
Expected: PASS.

- [ ] **Step 7: LF normalisieren + committen**

```bash
for f in src/Services/Pdf/PdfCanvas.php src/Services/Pdf/TcLibPdfCanvas.php tests/Feature/Pdf/TcLibPdfCanvasSmokeTest.php; do perl -i -pe 's/\r\n/\n/g' "$f"; done
git add composer.json composer.lock src/Services/Pdf/ tests/Feature/Pdf/
git commit -m "feat(finance): PdfCanvas-Interface und tc-lib-pdf-Adapter"
```

---

### Task 2: `buildReportData()` aus `report()` extrahieren

**Files:**
- Modify: `src/Controllers/FinanceController.php:318-375`
- Test: `tests/Feature/FinanceBusinessLogicTest.php`

**Interfaces:**
- Consumes: bestehende private Helfer `getFiscalConfig()`, `datesForYear()`, `defaultStartYear()`, `buildAvailableYears()`.
- Produces: `private function buildReportData(int $selectedYear): array` mit denselben Keys, die `report()` heute ans Template gibt: `finances, total_income, total_expense, balance, cash_income, cash_expense, bank_income, bank_expense, group_totals, has_groups, fiscal_start, fiscal_end, available_years, selected_year`. Wird von Task 5 genutzt.

- [ ] **Step 1: Failing-Test schreiben**

In `tests/Feature/FinanceBusinessLogicTest.php` folgende Methode ergänzen (nutzt vorhandenes `makeController()` + Reflection, weil `buildReportData` privat ist):
```php
public function testBuildReportDataAggregatesIncomeExpenseAndBalance(): void
{
    $controller = $this->makeController();

    Finance::create([
        'running_number' => 9001, 'invoice_date' => '2025-10-05', 'payment_date' => null,
        'description' => 'Einnahme A', 'group_name' => null, 'finance_group_id' => null,
        'type' => 'income', 'amount' => '300.00', 'payment_method' => 'cash',
    ]);
    Finance::create([
        'running_number' => 9002, 'invoice_date' => '2025-11-05', 'payment_date' => null,
        'description' => 'Ausgabe B', 'group_name' => null, 'finance_group_id' => null,
        'type' => 'expense', 'amount' => '120.00', 'payment_method' => 'bank_transfer',
    ]);

    $method = new \ReflectionMethod($controller, 'buildReportData');
    $data = $method->invoke($controller, 2025);

    $this->assertEqualsWithDelta(300.0, $data['total_income'], 0.001);
    $this->assertEqualsWithDelta(120.0, $data['total_expense'], 0.001);
    $this->assertEqualsWithDelta(180.0, $data['balance'], 0.001);
    $this->assertGreaterThanOrEqual(2, $data['finances']->count());
    unset($_SESSION['success'], $_SESSION['error']);
}
```
(Fiskaljahr 2025 = Default-Konfig; Buchungsdaten liegen im Herbst 2025 und damit sicher im Jahr 2025 unabhängig vom konfigurierten Fiskal-Startdatum. Falls die Default-Fiskalkonfig eng ist, Datumswerte an den vom Test gelesenen `data['fiscal_start']`-Bereich anpassen.)

- [ ] **Step 2: Test rot laufen lassen**

Run: `ddev exec ./vendor/bin/phpunit --filter testBuildReportDataAggregatesIncomeExpenseAndBalance`
Expected: FAIL — `buildReportData` existiert nicht.

- [ ] **Step 3: Methode extrahieren**

In `src/Controllers/FinanceController.php` den Sammel-Code aus `report()` in eine neue private Methode ziehen und `report()` sie nutzen lassen:
```php
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

    $cashIncome = (float) $finances->where('type', 'income')->where('payment_method', 'cash')->sum('amount');
    $cashExpense = (float) $finances->where('type', 'expense')->where('payment_method', 'cash')->sum('amount');
    $bankIncome = (float) $finances->where('type', 'income')->where('payment_method', 'bank_transfer')->sum('amount');
    $bankExpense = (float) $finances->where('type', 'expense')->where('payment_method', 'bank_transfer')->sum('amount');

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
```
`report()` danach auf:
```php
public function report(Request $request, Response $response): Response
{
    [$day, $month] = $this->getFiscalConfig();
    $selectedYear = (int) ($request->getQueryParams()['year'] ?? $this->defaultStartYear($day, $month));

    return $this->view->render($response, 'finances/report.twig', $this->buildReportData($selectedYear));
}
```

- [ ] **Step 4: Test grün + Regressionslauf**

Run: `ddev exec ./vendor/bin/phpunit --filter FinanceBusinessLogicTest`
Expected: PASS (neuer Test + alle bestehenden Finance-Tests).

- [ ] **Step 5: Commit**

```bash
perl -i -pe 's/\r\n/\n/g' src/Controllers/FinanceController.php
git add src/Controllers/FinanceController.php tests/Feature/FinanceBusinessLogicTest.php
git commit -m "refactor(finance): buildReportData aus report() extrahiert"
```

---

### Task 3: Reine Umbruch- + Übertrag-Logik

**Files:**
- Create: `src/Services/Pdf/CarryTotals.php`
- Create: `src/Services/Pdf/FinanceReportRow.php`
- Create: `src/Services/Pdf/FinanceReportPage.php`
- Create: `src/Services/Pdf/TextWrapper.php`
- Create: `src/Services/Pdf/FinanceReportPaginator.php`
- Test: `tests/Feature/Pdf/FinanceReportPaginatorTest.php`
- Test: `tests/Feature/Pdf/TextWrapperTest.php`

**Interfaces:**
- Consumes: nichts (rein).
- Produces:
  - `CarryTotals` (immutable): `__construct(float $income = 0, float $expense = 0)`, `balance(): float`, `add(float $income, float $expense): self`.
  - `FinanceReportRow` (immutable): `__construct(string $date, int $runningNumber, string $description, string $method, float $income, float $expense)`.
  - `FinanceReportPage` (immutable): `__construct(CarryTotals $openingCarry, array $rows, CarryTotals $closingCarry, bool $isFirst, bool $isLast)`; `$rows` = `FinanceReportRow[]`.
  - `TextWrapper::wrap(string $text, float $maxWidth, float $fontSize, callable $stringWidth): array` → `string[]` (Zeilen).
  - `FinanceReportPaginator::paginate(array $rowsWithHeights, float $firstPageTop, float $otherPageTop, float $contentBottom, float $carryRowHeight): array` → `FinanceReportPage[]`. `$rowsWithHeights` = Liste von `array{0: FinanceReportRow, 1: float}` (Zeile + gemessene Höhe).

- [ ] **Step 1: Value Objects schreiben**

Create `src/Services/Pdf/CarryTotals.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class CarryTotals
{
    public function __construct(
        public readonly float $income = 0.0,
        public readonly float $expense = 0.0,
    ) {
    }

    public function balance(): float
    {
        return $this->income - $this->expense;
    }

    public function add(float $income, float $expense): self
    {
        return new self($this->income + $income, $this->expense + $expense);
    }
}
```

Create `src/Services/Pdf/FinanceReportRow.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class FinanceReportRow
{
    public function __construct(
        public readonly string $date,
        public readonly int $runningNumber,
        public readonly string $description,
        public readonly string $method,
        public readonly float $income,
        public readonly float $expense,
    ) {
    }
}
```

Create `src/Services/Pdf/FinanceReportPage.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class FinanceReportPage
{
    /** @param FinanceReportRow[] $rows */
    public function __construct(
        public readonly CarryTotals $openingCarry,
        public readonly array $rows,
        public readonly CarryTotals $closingCarry,
        public readonly bool $isFirst,
        public readonly bool $isLast,
    ) {
    }
}
```

- [ ] **Step 2: TextWrapper-Test schreiben**

Create `tests/Feature/Pdf/TextWrapperTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\TextWrapper;
use PHPUnit\Framework\TestCase;

final class TextWrapperTest extends TestCase
{
    /** Fake-Metrik: jedes Zeichen 1 Einheit breit bei fontSize 10. */
    private function fakeWidth(): callable
    {
        return static fn (string $t, float $size): float => strlen($t) * ($size / 10.0);
    }

    public function testShortTextStaysOnOneLine(): void
    {
        $lines = TextWrapper::wrap('kurz', 100.0, 10.0, $this->fakeWidth());
        $this->assertSame(['kurz'], $lines);
    }

    public function testLongTextWrapsByWords(): void
    {
        $lines = TextWrapper::wrap('aaaa bbbb cccc dddd', 9.0, 10.0, $this->fakeWidth());
        $this->assertSame(['aaaa', 'bbbb', 'cccc', 'dddd'], $lines);
    }

    public function testWordLongerThanWidthGetsOwnLine(): void
    {
        $lines = TextWrapper::wrap('ab cdefghij k', 5.0, 10.0, $this->fakeWidth());
        $this->assertContains('cdefghij', $lines);
    }

    public function testEmptyTextYieldsSingleEmptyLine(): void
    {
        $this->assertSame([''], TextWrapper::wrap('', 100.0, 10.0, $this->fakeWidth()));
    }
}
```

- [ ] **Step 3: TextWrapper-Test rot**

Run: `ddev exec ./vendor/bin/phpunit --filter TextWrapperTest`
Expected: FAIL — Klasse fehlt.

- [ ] **Step 4: TextWrapper implementieren**

Create `src/Services/Pdf/TextWrapper.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class TextWrapper
{
    /**
     * Greedy Wort-Umbruch. Ein Wort, das breiter als $maxWidth ist, steht allein
     * in seiner Zeile (kein Zeichen-Splitting). Liefert immer mindestens [''].
     *
     * @param callable(string,float):float $stringWidth
     * @return string[]
     */
    public static function wrap(string $text, float $maxWidth, float $fontSize, callable $stringWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if ($words === [] || $words === ['']) {
            return [''];
        }

        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $stringWidth($candidate, $fontSize) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }
}
```

- [ ] **Step 5: TextWrapper-Test grün**

Run: `ddev exec ./vendor/bin/phpunit --filter TextWrapperTest`
Expected: PASS.

- [ ] **Step 6: Paginator-Test schreiben**

Create `tests/Feature/Pdf/FinanceReportPaginatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\FinanceReportPaginator;
use App\Services\Pdf\FinanceReportRow;
use PHPUnit\Framework\TestCase;

final class FinanceReportPaginatorTest extends TestCase
{
    /** @return array{0: FinanceReportRow, 1: float} */
    private function row(int $n, float $income, float $expense, float $height = 10.0): array
    {
        return [new FinanceReportRow('01.01.2025', $n, "Zeile $n", 'Bar', $income, $expense), $height];
    }

    public function testSinglePageWhenEverythingFits(): void
    {
        $rows = [$this->row(1, 100, 0), $this->row(2, 0, 40)];
        // firstPageTop=0, otherPageTop=0, bottom=100, carryHeight=10 -> Kapazität 90pt, 20pt Inhalt
        $pages = FinanceReportPaginator::paginate($rows, 0.0, 0.0, 100.0, 10.0);

        $this->assertCount(1, $pages);
        $this->assertTrue($pages[0]->isFirst);
        $this->assertTrue($pages[0]->isLast);
        $this->assertSame(0.0, $pages[0]->openingCarry->income);
        $this->assertEqualsWithDelta(100.0, $pages[0]->closingCarry->income, 0.001);
        $this->assertEqualsWithDelta(40.0, $pages[0]->closingCarry->expense, 0.001);
    }

    public function testBreaksToSecondPageAndCarriesForward(): void
    {
        // Kapazität pro Seite bis bottom-carry = 20pt -> je 2 Zeilen à 10pt.
        $rows = [
            $this->row(1, 100, 0),
            $this->row(2, 50, 0),
            $this->row(3, 0, 30),
        ];
        $pages = FinanceReportPaginator::paginate($rows, 0.0, 0.0, 30.0, 10.0);

        $this->assertCount(2, $pages);

        // Seite 1: Zeilen 1+2, schließt mit Übertrag 150/0.
        $this->assertCount(2, $pages[0]->rows);
        $this->assertTrue($pages[0]->isFirst);
        $this->assertFalse($pages[0]->isLast);
        $this->assertEqualsWithDelta(150.0, $pages[0]->closingCarry->income, 0.001);

        // Seite 2: öffnet mit Übertrag 150/0, Zeile 3, schließt 150/30.
        $this->assertFalse($pages[1]->isFirst);
        $this->assertTrue($pages[1]->isLast);
        $this->assertEqualsWithDelta(150.0, $pages[1]->openingCarry->income, 0.001);
        $this->assertEqualsWithDelta(30.0, $pages[1]->closingCarry->expense, 0.001);
        $this->assertEqualsWithDelta(150.0, $pages[1]->closingCarry->income, 0.001);
    }

    public function testFinalClosingCarryEqualsGrandTotals(): void
    {
        $rows = [$this->row(1, 10, 0), $this->row(2, 0, 4), $this->row(3, 6, 0)];
        $pages = FinanceReportPaginator::paginate($rows, 0.0, 0.0, 15.0, 5.0);
        $last = $pages[array_key_last($pages)];

        $this->assertEqualsWithDelta(16.0, $last->closingCarry->income, 0.001);
        $this->assertEqualsWithDelta(4.0, $last->closingCarry->expense, 0.001);
        $this->assertEqualsWithDelta(12.0, $last->closingCarry->balance(), 0.001);
    }

    public function testEmptyRowsProduceSingleEmptyLastPage(): void
    {
        $pages = FinanceReportPaginator::paginate([], 0.0, 0.0, 100.0, 10.0);
        $this->assertCount(1, $pages);
        $this->assertTrue($pages[0]->isFirst);
        $this->assertTrue($pages[0]->isLast);
        $this->assertSame([], $pages[0]->rows);
    }
}
```

- [ ] **Step 7: Paginator-Test rot**

Run: `ddev exec ./vendor/bin/phpunit --filter FinanceReportPaginatorTest`
Expected: FAIL — Klasse fehlt.

- [ ] **Step 8: Paginator implementieren**

Create `src/Services/Pdf/FinanceReportPaginator.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class FinanceReportPaginator
{
    /**
     * Teilt Bewegungszeilen auf Seiten auf und berechnet je Seite Eröffnungs-
     * und Abschluss-Übertrag (kumuliert). Auf jeder Seite bleibt unten
     * $carryRowHeight für die Übertrag-/Gesamtsaldo-Zeile reserviert.
     *
     * @param array{0: FinanceReportRow, 1: float}[] $rowsWithHeights
     * @return FinanceReportPage[]
     */
    public static function paginate(
        array $rowsWithHeights,
        float $firstPageTop,
        float $otherPageTop,
        float $contentBottom,
        float $carryRowHeight
    ): array {
        $limit = $contentBottom - $carryRowHeight;

        // Sonderfall: keine Bewegungen -> eine leere Seite.
        if ($rowsWithHeights === []) {
            $zero = new CarryTotals();
            return [new FinanceReportPage($zero, [], $zero, true, true)];
        }

        $pages = [];
        $running = new CarryTotals();
        $isFirst = true;
        $cursor = $firstPageTop;
        $opening = $running;
        $currentRows = [];

        foreach ($rowsWithHeights as [$row, $height]) {
            if ($currentRows !== [] && ($cursor + $height) > $limit) {
                // Seite abschließen (Abschluss-Übertrag = aktueller Stand).
                $pages[] = new FinanceReportPage($opening, $currentRows, $running, $isFirst, false);
                $isFirst = false;
                $opening = $running;
                $currentRows = [];
                $cursor = $otherPageTop;
            }

            $currentRows[] = $row;
            $running = $running->add($row->income, $row->expense);
            $cursor += $height;
        }

        // Letzte (nicht leere) Seite abschließen.
        $pages[] = new FinanceReportPage($opening, $currentRows, $running, $isFirst, true);

        return $pages;
    }
}
```

- [ ] **Step 9: Paginator-Test grün**

Run: `ddev exec ./vendor/bin/phpunit --filter FinanceReportPaginatorTest`
Expected: PASS.

- [ ] **Step 10: LF + Commit**

```bash
for f in src/Services/Pdf/CarryTotals.php src/Services/Pdf/FinanceReportRow.php src/Services/Pdf/FinanceReportPage.php src/Services/Pdf/TextWrapper.php src/Services/Pdf/FinanceReportPaginator.php tests/Feature/Pdf/TextWrapperTest.php tests/Feature/Pdf/FinanceReportPaginatorTest.php; do perl -i -pe 's/\r\n/\n/g' "$f"; done
git add src/Services/Pdf/ tests/Feature/Pdf/
git commit -m "feat(finance): reine Umbruch- und Übertrag-Logik fürs Jahres-PDF"
```

---

### Task 4: `FinanceReportPdfService` (Zusammenbau + Zeichnen)

**Files:**
- Create: `src/Services/FinanceReportPdfService.php`
- Test: `tests/Feature/Pdf/FinanceReportPdfServiceTest.php`

**Interfaces:**
- Consumes: `PdfCanvas`, `FinanceReportRow`, `FinanceReportPaginator`, `TextWrapper`, `CarryTotals`, `App\Models\AppSetting`.
- Produces: `App\Services\FinanceReportPdfService`
  - `__construct(PdfCanvas $canvas)`
  - `render(array $reportData): string` — nimmt das Array aus `buildReportData()`, gibt PDF-Bytes zurück.
  - `filename(array $reportData): string` — z. B. `Kassabuch_Geschäftsjahr_2025-2026.pdf`, abgeleitet aus `fiscal_start`/`fiscal_end`.

**Hinweis Testbarkeit:** Der Konstruktor nimmt das `PdfCanvas`-**Interface**, damit der Test einen Fake einsetzen kann. Für den echten Container wird in Task 5 `TcLibPdfCanvas` injiziert.

- [ ] **Step 1: Test mit Fake-Canvas schreiben**

Create `tests/Feature/Pdf/FinanceReportPdfServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\FinanceReportPdfService;
use App\Services\Pdf\PdfCanvas;
use PHPUnit\Framework\TestCase;

final class FinanceReportPdfServiceTest extends TestCase
{
    private function fakeCanvas(): PdfCanvas
    {
        return new class implements PdfCanvas {
            public int $pages = 0;
            public array $texts = [];
            public function addPage(): void
            {
                $this->pages++;
            }
            public function contentTop(): float
            {
                return 0.0;
            }
            public function contentBottom(): float
            {
                return 100.0;
            }
            public function contentLeft(): float
            {
                return 0.0;
            }
            public function contentWidth(): float
            {
                return 500.0;
            }
            public function stringWidth(string $text, float $fontSize): float
            {
                return strlen($text) * ($fontSize / 10.0);
            }
            public function text(float $x, float $y, string $text, float $fontSize, string $style = ''): void
            {
                $this->texts[] = $text;
            }
            public function line(float $x1, float $y1, float $x2, float $y2): void
            {
            }
            public function pageCount(): int
            {
                return $this->pages;
            }
            public function output(): string
            {
                return '%PDF-fake';
            }
        };
    }

    private function reportData(int $rowCount): array
    {
        $finances = [];
        for ($i = 1; $i <= $rowCount; $i++) {
            $finances[] = (object) [
                'invoice_date' => \Carbon\Carbon::parse('2025-10-01'),
                'running_number' => $i,
                'description' => "Buchung Nummer $i mit einer etwas laengeren Beschreibung",
                'payment_method' => $i % 2 === 0 ? 'bank_transfer' : 'cash',
                'type' => $i % 3 === 0 ? 'expense' : 'income',
                'amount' => 10.0 + $i,
            ];
        }

        return [
            'finances' => collect($finances),
            'total_income' => 1000.0,
            'total_expense' => 200.0,
            'balance' => 800.0,
            'fiscal_start' => '01.09.2025',
            'fiscal_end' => '31.08.2026',
            'selected_year' => 2025,
        ];
    }

    public function testRendersMultiplePagesForManyRows(): void
    {
        $canvas = $this->fakeCanvas();
        $service = new FinanceReportPdfService($canvas);

        $bytes = $service->render($this->reportData(60));

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1, $canvas->pageCount(), 'Viele Zeilen müssen mehrere Seiten erzeugen.');
    }

    public function testWritesUebertragLabelOnPageBreak(): void
    {
        $canvas = $this->fakeCanvas();
        $service = new FinanceReportPdfService($canvas);

        $service->render($this->reportData(60));

        $joined = implode('|', $canvas->texts);
        $this->assertStringContainsString('Übertrag', $joined);
    }

    public function testFilenameUsesFiscalYears(): void
    {
        $service = new FinanceReportPdfService($this->fakeCanvas());
        $name = $service->filename($this->reportData(1));
        $this->assertSame('Kassabuch_Geschäftsjahr_2025-2026.pdf', $name);
    }
}
```

- [ ] **Step 2: Test rot**

Run: `ddev exec ./vendor/bin/phpunit --filter FinanceReportPdfServiceTest`
Expected: FAIL — Service fehlt.

- [ ] **Step 3: Service implementieren**

Create `src/Services/FinanceReportPdfService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Services\Pdf\CarryTotals;
use App\Services\Pdf\FinanceReportPage;
use App\Services\Pdf\FinanceReportPaginator;
use App\Services\Pdf\FinanceReportRow;
use App\Services\Pdf\PdfCanvas;
use App\Services\Pdf\TextWrapper;

final class FinanceReportPdfService
{
    private const FONT = 10.0;
    private const LINE_HEIGHT = 14.0;
    private const HEADER_HEIGHT = 18.0;
    private const CARRY_HEIGHT = 18.0;
    private const KENNZAHLEN_HEIGHT = 70.0;
    private const TITLE_HEIGHT = 40.0;

    // Spaltenanteile (Summe der Nicht-Beschreibung-Spalten wird von der Breite abgezogen).
    private const COL_DATE = 60.0;
    private const COL_NUMBER = 40.0;
    private const COL_METHOD = 45.0;
    private const COL_AMOUNT = 70.0; // je Einnahme/Ausgabe/Saldo

    public function __construct(private readonly PdfCanvas $canvas)
    {
    }

    public function render(array $reportData): string
    {
        $rows = $this->buildRows($reportData);
        $descWidth = $this->descriptionWidth();

        $rowsWithHeights = [];
        foreach ($rows as $row) {
            $rowsWithHeights[] = [$row, $this->rowHeight($row, $descWidth)];
        }

        $firstPageTop = $this->canvas->contentTop()
            + self::TITLE_HEIGHT + self::KENNZAHLEN_HEIGHT + self::HEADER_HEIGHT;
        $otherPageTop = $this->canvas->contentTop() + self::CARRY_HEIGHT + self::HEADER_HEIGHT;

        $pages = FinanceReportPaginator::paginate(
            $rowsWithHeights,
            $firstPageTop,
            $otherPageTop,
            $this->canvas->contentBottom(),
            self::CARRY_HEIGHT
        );

        $pageNumber = 0;
        $total = count($pages);
        foreach ($pages as $page) {
            $pageNumber++;
            $this->canvas->addPage();
            $this->drawPage($reportData, $page, $descWidth, $pageNumber, $total);
        }

        return $this->canvas->output();
    }

    public function filename(array $reportData): string
    {
        $startYear = substr((string) $reportData['fiscal_start'], -4);
        $endYear = substr((string) $reportData['fiscal_end'], -4);

        return "Kassabuch_Geschäftsjahr_{$startYear}-{$endYear}.pdf";
    }

    /** @return FinanceReportRow[] */
    private function buildRows(array $reportData): array
    {
        $rows = [];
        foreach ($reportData['finances'] as $f) {
            $isIncome = $f->type === 'income';
            $rows[] = new FinanceReportRow(
                $f->invoice_date->format('d.m.Y'),
                (int) $f->running_number,
                (string) $f->description,
                $f->payment_method === 'cash' ? 'Bar' : 'Bank',
                $isIncome ? (float) $f->amount : 0.0,
                $isIncome ? 0.0 : (float) $f->amount,
            );
        }

        return $rows;
    }

    private function descriptionWidth(): float
    {
        $fixed = self::COL_DATE + self::COL_NUMBER + self::COL_METHOD + (3 * self::COL_AMOUNT);

        return max(60.0, $this->canvas->contentWidth() - $fixed);
    }

    private function rowHeight(FinanceReportRow $row, float $descWidth): float
    {
        $lines = TextWrapper::wrap(
            $row->description,
            $descWidth,
            self::FONT,
            fn (string $t, float $s): float => $this->canvas->stringWidth($t, $s)
        );

        return max(1, count($lines)) * self::LINE_HEIGHT;
    }

    private function drawPage(
        array $reportData,
        FinanceReportPage $page,
        float $descWidth,
        int $pageNumber,
        int $totalPages
    ): void {
        $left = $this->canvas->contentLeft();
        $y = $this->canvas->contentTop();

        if ($page->isFirst) {
            $chorName = $this->appName();
            $this->canvas->text($left, $y, $chorName, 14.0, 'B');
            $this->canvas->text(
                $left,
                $y + 18.0,
                "Kassabuch Geschäftsjahr {$reportData['fiscal_start']} – {$reportData['fiscal_end']}",
                11.0,
                'B'
            );
            $y += self::TITLE_HEIGHT;
            $y = $this->drawKennzahlen($left, $y, $reportData);
        } else {
            $y = $this->drawCarryRow($left, $y, 'Übertrag', $page->openingCarry, $descWidth);
        }

        $y = $this->drawTableHeader($left, $y, $descWidth);

        foreach ($page->rows as $row) {
            $y = $this->drawRow($left, $y, $row, $descWidth);
        }

        if ($page->isLast) {
            $this->drawGesamtsaldo($left, $this->canvas->contentBottom() - self::CARRY_HEIGHT, $page->closingCarry, $descWidth);
        } else {
            $this->drawCarryRow($left, $this->canvas->contentBottom() - self::CARRY_HEIGHT, 'Übertrag', $page->closingCarry, $descWidth);
        }

        $this->drawFooter($left, $pageNumber, $totalPages);
    }

    private function drawKennzahlen(float $left, float $y, array $reportData): float
    {
        $this->canvas->text($left, $y, 'Einnahmen: ' . $this->money($reportData['total_income']) . ' €', self::FONT, 'B');
        $this->canvas->text($left, $y + 16.0, 'Ausgaben: ' . $this->money($reportData['total_expense']) . ' €', self::FONT, 'B');
        $this->canvas->text($left, $y + 32.0, 'Saldo: ' . $this->money($reportData['balance']) . ' €', self::FONT, 'B');

        return $y + self::KENNZAHLEN_HEIGHT;
    }

    private function drawTableHeader(float $left, float $y, float $descWidth): float
    {
        $x = $left;
        foreach ([['Datum', self::COL_DATE], ['Nr.', self::COL_NUMBER], ['Beschreibung', $descWidth], ['Art', self::COL_METHOD], ['Einnahme', self::COL_AMOUNT], ['Ausgabe', self::COL_AMOUNT], ['Saldo', self::COL_AMOUNT]] as [$label, $width]) {
            $this->canvas->text($x, $y, $label, self::FONT, 'B');
            $x += $width;
        }
        $this->canvas->line($left, $y + self::HEADER_HEIGHT - 4, $left + $this->canvas->contentWidth(), $y + self::HEADER_HEIGHT - 4);

        return $y + self::HEADER_HEIGHT;
    }

    private function drawRow(float $left, float $y, FinanceReportRow $row, float $descWidth): float
    {
        $lines = TextWrapper::wrap(
            $row->description,
            $descWidth,
            self::FONT,
            fn (string $t, float $s): float => $this->canvas->stringWidth($t, $s)
        );
        $x = $left;
        $this->canvas->text($x, $y, $row->date, self::FONT);
        $x += self::COL_DATE;
        $this->canvas->text($x, $y, '#' . $row->runningNumber, self::FONT);
        $x += self::COL_NUMBER;
        foreach ($lines as $i => $line) {
            $this->canvas->text($x, $y + ($i * self::LINE_HEIGHT), $line, self::FONT);
        }
        $x += $descWidth;
        $this->canvas->text($x, $y, $row->method, self::FONT);
        $x += self::COL_METHOD;
        $this->canvas->text($x, $y, $row->income > 0 ? $this->money($row->income) : '', self::FONT);
        $x += self::COL_AMOUNT;
        $this->canvas->text($x, $y, $row->expense > 0 ? $this->money($row->expense) : '', self::FONT);

        return $y + (max(1, count($lines)) * self::LINE_HEIGHT);
    }

    private function drawCarryRow(float $left, float $y, string $label, CarryTotals $carry, float $descWidth): float
    {
        $this->canvas->text($left, $y, $label . ':', self::FONT, 'B');
        $amountX = $left + self::COL_DATE + self::COL_NUMBER + $descWidth + self::COL_METHOD;
        $this->canvas->text($amountX, $y, $this->money($carry->income), self::FONT, 'B');
        $this->canvas->text($amountX + self::COL_AMOUNT, $y, $this->money($carry->expense), self::FONT, 'B');
        $this->canvas->text($amountX + (2 * self::COL_AMOUNT), $y, $this->money($carry->balance()), self::FONT, 'B');

        return $y + self::CARRY_HEIGHT;
    }

    private function drawGesamtsaldo(float $left, float $y, CarryTotals $carry, float $descWidth): float
    {
        return $this->drawCarryRow($left, $y, 'Gesamtsaldo', $carry, $descWidth);
    }

    private function drawFooter(float $left, int $pageNumber, int $totalPages): void
    {
        $y = $this->canvas->contentBottom() + 6.0;
        $this->canvas->text($left, $y, 'Erstellt am ' . date('d.m.Y'), 8.0);
        $this->canvas->text(
            $left + $this->canvas->contentWidth() - 80.0,
            $y,
            "Seite {$pageNumber} von {$totalPages}",
            8.0
        );
    }

    private function appName(): string
    {
        try {
            $name = AppSetting::query()->find('app_name')?->setting_value;
        } catch (\Throwable) {
            $name = null;
        }

        return $name !== null && $name !== '' ? (string) $name : 'Chor-Manager';
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
```

- [ ] **Step 4: Test grün**

Run: `ddev exec ./vendor/bin/phpunit --filter FinanceReportPdfServiceTest`
Expected: PASS (mehrseitig, „Übertrag" vorhanden, Dateiname korrekt).

- [ ] **Step 5: LF + Commit**

```bash
for f in src/Services/FinanceReportPdfService.php tests/Feature/Pdf/FinanceReportPdfServiceTest.php; do perl -i -pe 's/\r\n/\n/g' "$f"; done
git add src/Services/FinanceReportPdfService.php tests/Feature/Pdf/FinanceReportPdfServiceTest.php
git commit -m "feat(finance): FinanceReportPdfService mit Übertrag-Layout"
```

---

### Task 5: Controller-Action, Route, DI-Verdrahtung

**Files:**
- Modify: `src/Controllers/FinanceController.php` (Konstruktor + neue `reportPdf`)
- Modify: `src/Routes.php:273-281` (Route in Read-Gruppe)
- Modify: `src/Dependencies.php` (FinanceController explizit verdrahten)
- Test: `tests/Feature/FinanceReportPdfControllerTest.php`
- Test: `tests/Feature/DependenciesContainerWiringTest.php` (bestehender Finance-Wiring-Test deckt es ab; ggf. Assertion auf Service)

**Interfaces:**
- Consumes: `FinanceReportPdfService::render()`, `::filename()`, `buildReportData()` (Task 2).
- Produces: `FinanceController::reportPdf(Request $request, Response $response): Response`.

- [ ] **Step 1: Feature-Test schreiben**

Create `tests/Feature/FinanceReportPdfControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use App\Services\BudgetService;
use App\Services\FinanceReportPdfService;
use App\Services\Pdf\TcLibPdfCanvas;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;

final class FinanceReportPdfControllerTest extends TestCase
{
    use TestHttpHelpers;

    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$capsule !== null) {
            return;
        }
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $c = self::$capsule?->connection();
        if ($c !== null && $c->transactionLevel() > 0) {
            $c->rollBack();
        }
        parent::tearDown();
    }

    private function controller(): FinanceController
    {
        return new FinanceController(
            $this->createStub(Twig::class),
            new BudgetService(),
            new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas())
        );
    }

    public function testReportPdfReturnsPdfDownload(): void
    {
        Finance::create([
            'running_number' => 8001, 'invoice_date' => '2025-10-05', 'payment_date' => null,
            'description' => 'Prüf-Einnahme', 'group_name' => null, 'finance_group_id' => null,
            'type' => 'income', 'amount' => '250.00', 'payment_method' => 'cash',
        ]);

        $response = $this->controller()->reportPdf(
            $this->makeRequest('GET', '/finances/report/pdf', [], ['year' => '2025']),
            $this->makeResponse()
        );

        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('Kassabuch', $disposition);

        $body = (string) $response->getBody();
        $this->assertStringStartsWith('%PDF', $body);
    }
}
```

- [ ] **Step 2: Test rot**

Run: `ddev exec ./vendor/bin/phpunit --filter FinanceReportPdfControllerTest`
Expected: FAIL — Konstruktor-Arity / `reportPdf` fehlt.

- [ ] **Step 3: Controller erweitern**

In `src/Controllers/FinanceController.php`:
- `use App\Services\FinanceReportPdfService;` ergänzen.
- Property + Konstruktor um den Service erweitern:
```php
private FinanceReportPdfService $pdfService;

public function __construct(
    Twig $view,
    BudgetService $budgetService,
    LoggerInterface $logger,
    FinanceReportPdfService $pdfService
) {
    $this->view = $view;
    $this->budgetService = $budgetService;
    $this->logger = $logger;
    $this->pdfService = $pdfService;
}
```
- Neue Action:
```php
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
```

- [ ] **Step 4: Route ergänzen**

In `src/Routes.php` in der `requiresFinanceRead`-Gruppe (nach `/finances/report`):
```php
$financeReadGroup->get('/finances/report/pdf', [FinanceController::class, 'reportPdf']);
```

- [ ] **Step 5: DI verdrahten**

In `src/Dependencies.php` FinanceController explizit definieren (analog RoleController), damit der zusätzliche Service sicher aufgelöst wird. `use App\Controllers\FinanceController;` und `use App\Services\FinanceReportPdfService;` sowie `use App\Services\Pdf\TcLibPdfCanvas;` und `use App\Services\Pdf\PdfCanvas;` oben ergänzen, dann in den Definitionen:
```php
PdfCanvas::class => \DI\autowire(TcLibPdfCanvas::class),
FinanceReportPdfService::class => \DI\autowire(),
FinanceController::class => function (ContainerInterface $c) {
    return new FinanceController(
        $c->get(Twig::class),
        $c->get(BudgetService::class),
        $c->get(LoggerInterface::class),
        $c->get(FinanceReportPdfService::class)
    );
},
```

- [ ] **Step 6: Tests grün (Feature + Wiring)**

Run: `ddev exec ./vendor/bin/phpunit --filter "FinanceReportPdfControllerTest|testFinanceControllerResolvesWithRealLogger"`
Expected: PASS — PDF-Download korrekt, Container löst FinanceController samt Service auf.

- [ ] **Step 7: LF + Commit**

```bash
for f in src/Controllers/FinanceController.php src/Routes.php src/Dependencies.php tests/Feature/FinanceReportPdfControllerTest.php; do perl -i -pe 's/\r\n/\n/g' "$f"; done
git add src/Controllers/FinanceController.php src/Routes.php src/Dependencies.php tests/Feature/FinanceReportPdfControllerTest.php
git commit -m "feat(finance): reportPdf-Action, Route und DI-Verdrahtung"
```

---

### Task 6: Download-Button im Bildschirm-Report

**Files:**
- Modify: `templates/finances/report.twig:16-33`
- Test: `tests/Feature/FinanceFeatureTest.php`

**Interfaces:**
- Consumes: Route `/finances/report/pdf` (Task 5).
- Produces: nichts (UI).

- [ ] **Step 1: Template-Test schreiben**

In `tests/Feature/FinanceFeatureTest.php` ergänzen:
```php
public function testFinanceReportOffersPdfDownloadLink(): void
{
    $template = file_get_contents(dirname(__DIR__) . '/../templates/finances/report.twig');
    $this->assertIsString($template);
    $this->assertStringContainsString('/finances/report/pdf?year=', $template);
    $this->assertStringContainsString('PDF herunterladen', $template);
}
```
Und in `testFinanceStructureExists()` die Zeile ergänzen:
```php
$this->assertTrue(method_exists(FinanceController::class, 'reportPdf'));
$this->assertStringContainsString("'/finances/report/pdf'", $routesContent);
```

- [ ] **Step 2: Test rot**

Run: `ddev exec ./vendor/bin/phpunit --filter FinanceFeatureTest`
Expected: FAIL — Link/Method/Route-Assertions.

- [ ] **Step 3: Button einfügen**

In `templates/finances/report.twig` in den `page-actions` (vor dem „Zurück zum Kassabuch"-Link):
```twig
<a href="/finances/report/pdf?year={{ selected_year }}"
   class="btn btn-outline-primary btn-sm">
    <i class="bi bi-file-earmark-pdf"></i> PDF herunterladen
</a>
```

- [ ] **Step 4: Test grün**

Run: `ddev exec ./vendor/bin/phpunit --filter FinanceFeatureTest`
Expected: PASS.

- [ ] **Step 5: Twig-Lint + Commit**

```bash
ddev composer twigcs || ddev composer twigcbf
perl -i -pe 's/\r\n/\n/g' templates/finances/report.twig
git add templates/finances/report.twig tests/Feature/FinanceFeatureTest.php
git commit -m "feat(finance): PDF-Download-Button im Jahres-Report"
```

---

### Task 7: Hilfethema Finanzen-PDF

**Files:**
- Create: `help/finance/…` (Struktur legt die Skill an)

**Interfaces:**
- Consumes: nichts.
- Produces: neues `/help`-Thema.

- [ ] **Step 1: Skill nutzen**

Die `create-help-topic`-Skill invoken und ein Finanzen-Hilfethema erstellen. Inhalt: „Geschäftsjahr-PDF erzeugen" — im Kassabuch/Report das Geschäftsjahr wählen, „PDF herunterladen" klicken; das PDF enthält Kennzahlen und alle Bewegungen mit Übertrag pro Seite, keine Anhänge. Rollen nicht namentlich nennen — auf das Recht „Finanzen lesen" (Label aus der Rollen-Verwaltung) verweisen; bei fehlendem Recht generisch auf den Administrator.

- [ ] **Step 2: Screenshots/Prüfung laut Skill**

Der Skill-Anleitung folgen (Screenshots nur, falls der Nutzer das möchte / die Skill es vorsieht).

- [ ] **Step 3: LF + Commit**

```bash
# neue .md-Dateien normalisieren
git add help/
git commit -m "docs(help): Hilfethema Geschäftsjahr-PDF in Finanzen"
```

---

## Abschluss (nach allen Tasks)

- [ ] **Gesamter Testlauf:** `ddev exec ./vendor/bin/phpunit`
- [ ] **PHP-Lint:** `ddev composer phpcs` (bei Bedarf `ddev composer phpcbf`)
- [ ] **Twig-Lint:** `ddev composer twigcs`
- [ ] **Seed:** nicht zutreffend (keine neue Persistenz) — bewusst quittieren.
- [ ] **Manuelle Sichtprüfung (optional, nur auf Wunsch):** `/finances/report?year=…` → „PDF herunterladen" → mehrseitiges PDF mit Übertrag prüfen.
- [ ] **Bericht an den Entwickler:** geänderte Dateien, ausgeführte Befehle, Ergebnisse. Kein `git push`.

## Self-Review-Notiz (Spec-Abdeckung)

- Kennzahlen + Bewegungen → Task 4 (drawKennzahlen + Tabelle).
- Zahlungsart-Spalte → Task 4 (`method`, `drawRow`/`drawTableHeader`).
- Übertrag bei Seitenwechsel → Task 3 (Paginator) + Task 4 (drawCarryRow/Gesamtsaldo).
- tc-lib-pdf v8 → Task 1.
- Download + Read-Recht-Route → Task 5.
- Button → Task 6.
- Hilfetext → Task 7.
- Keine Anhänge im PDF → `buildRows` nutzt nur Buchungsfelder, nie `attachments`.
- Kein Seed → Abschluss.

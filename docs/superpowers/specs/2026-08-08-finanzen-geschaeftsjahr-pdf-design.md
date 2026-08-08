# Finanzen: PDF-Ausdruck eines Geschäftsjahres

**Datum:** 2026-08-08
**Status:** Entwurf zur Umsetzung

## Ziel & Kontext

Für Rechnungsprüfer und zur Ablage soll aus dem Finanzbereich (Kassabuch)
ein PDF-Dokument eines Geschäftsjahres erzeugt werden können. Das PDF enthält
die Bewegungen des gewählten Geschäftsjahres sowie eine kurze Kennzahlen-
Zusammenfassung. Anhänge werden **nicht** ins PDF aufgenommen.

„Gedruckt" bedeutet hier: ein PDF erzeugen und als Download ausliefern.

Der bestehende Bildschirm-Report (`GET /finances/report`,
[report.twig](../../../templates/finances/report.twig)) baut bereits alle
Jahresdaten zusammen (Kennzahlen, Zahlungsart-Salden, Gruppensalden,
Bewegungsliste). Das neue Feature setzt darauf auf.

## Nicht-Ziele (YAGNI)

- Keine Anhänge im PDF.
- Keine Gruppen-Detailtabellen und keine Zahlungsart-Saldotabellen im PDF
  (bewusst schlank gehalten; per-Zeile-Zahlungsart wird aber in der
  Bewegungstabelle geführt).
- Keine neue Persistenz, keine Schema-Änderung.
- Kein neues Recht — Ausdrucken ist Lesen.

## Bibliothek

- `dompdf/dompdf ^3` via `ddev composer require dompdf/dompdf`.
- Begründung: aktiv gepflegt (Releases 2024/2025), pures PHP, keine externen
  Binaries im Container, rendert HTML+CSS → ein Twig-Template kann als Vorlage
  dienen. (mPDF und Headless-Chrome bewusst verworfen: mPDF-Pflegezustand,
  Chromium/Node = schweres Container-Setup.)

## Komponenten

### 1. Datenaufbereitung (Refactor in FinanceController)

Die Sammel-Logik aus `report()` wird in eine private Methode extrahiert:

```
private function buildReportData(int $selectedYear): array
```

Rückgabe: dasselbe Array, das `report()` heute an das Template gibt (finances,
total_income, total_expense, balance, cash_*, bank_*, group_totals, has_groups,
fiscal_start, fiscal_end, available_years, selected_year).

`report()` und die neue `reportPdf()` nutzen beide `buildReportData()` →
keine Duplikat-Logik, gut testbar.

### 2. Neue Action + Route

- Methode `reportPdf(Request $request, Response $response): Response` im
  [FinanceController](../../../src/Controllers/FinanceController.php).
- Route `GET /finances/report/pdf` in der **`requiresFinanceRead`**-Gruppe
  in [Routes.php](../../../src/Routes.php) (neben `/finances/report`).
- Query-Parameter `year` wie beim bestehenden Report; Default = laufendes
  Geschäftsjahr.
- Response:
  - `Content-Type: application/pdf`
  - `Content-Disposition: attachment; filename="Kassabuch_Geschäftsjahr_2025-2026.pdf"`
    inkl. `filename*=UTF-8''…` (rawurlencode), analog zu `viewAttachment()`.
  - Dateiname aus den Start-/End-Jahren des Geschäftsjahres.

### 3. PDF-Service

`src/Services/FinanceReportPdfService.php`

- Verantwortung: Report-Daten → PDF-Bytes.
- Rendert das Twig-Template `finances/report_pdf.twig` zu HTML, übergibt es an
  Dompdf, konfiguriert A4 Hochformat, gibt den PDF-String (`$dompdf->output()`)
  zurück.
- Konstruktor bekommt `Twig` (und ggf. App-Settings/Logger nach Bedarf) per DI.
- Controller bleibt dünn: Daten holen via `buildReportData()`, an den Service
  geben, Response-Header setzen.
- DI-Verdrahtung in der Container-Definition ergänzen (siehe
  `DependenciesContainerWiringTest`-Muster).

### 4. PDF-Template `templates/finances/report_pdf.twig`

- Eigenständiges HTML-Dokument (kein `extends layout.twig`), da Dompdf ohne
  Bootstrap/externe Assets rendert.
- **Eigener `<style>`-Block** im Template: dokumentierte Ausnahme zur
  Template-Hygiene (wie E-Mail-Templates in `templates/emails/`). Dompdf braucht
  self-contained CSS; keine CDN-/externen Assets.
- Aufbau:
  - **Kopf:** App-/Chorname (`app_settings.app_name`) + Titel „Kassabuch
    Geschäftsjahr DD.MM.YYYY – DD.MM.YYYY".
  - **Kennzahlen:** Einnahmen / Ausgaben / Saldo (nur diese drei; keine
    Gruppen-/Zahlungsart-Detailtabellen).
  - **Bewegungstabelle** (chronologisch, invoice_date aufsteigend), Spalten:
    **Datum · Lfd. Nr. · Beschreibung · Zahlungsart (Bar/Bank) · Einnahme ·
    Ausgabe · Laufsaldo**.
  - **Abschluss:** Gesamtsaldo-Zeile.
  - **Seitenfuß** (Dompdf `@page`/Script-Fallback): Seitenzahl + Erstelldatum.
- Beträge im deutschen Format (`number_format(2, ',', '.')`), echte Umlaute.

### 5. Button im Bildschirm-Report

In [report.twig](../../../templates/finances/report.twig), Header-Actions:

```twig
<a href="/finances/report/pdf?year={{ selected_year }}" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-file-earmark-pdf"></i> PDF herunterladen
</a>
```

## Tests (TDD)

Vor der Implementierung schreiben, zunächst rot:

- **Feature-Test** (Erweiterung `FinanceFeatureTest` bzw. neuer Test):
  - `reportPdf`-Methode existiert, Route `'/finances/report/pdf'` in Routes.php.
  - Aufruf liefert `Content-Type: application/pdf` und der Body beginnt mit
    `%PDF`.
  - `Content-Disposition`-Header enthält `attachment` und den Jahres-Dateinamen.
- **Business-Logic-Test:** `buildReportData()` liefert korrekte Kennzahlen
  (Einnahmen/Ausgaben/Saldo) und die erwartete Bewegungsanzahl für ein
  bekanntes Jahr.
- Relevante Tests vor Abschluss ausführen und Ergebnis berichten.

## Seed

Keine neue Persistenz/Tabelle → **kein Seed-Update nötig**. Der bestehende
Finance-Seed erzeugt Buchungen über ein Geschäftsjahr und deckt den PDF-Export
ab. (Bewusst vermerkt, damit die Seed-Vollständigkeitsprüfung bewusst als
„nicht zutreffend" quittiert ist.)

## Hilfetext

Neues Finanzen-Hilfethema unter `help/finance/` (via `create-help-topic`-Skill),
mit kurzem Abschnitt zum Geschäftsjahr-PDF: Geschäftsjahr wählen → „PDF
herunterladen". Rollen nicht namentlich nennen — auf das Recht „Finanzen lesen"
(Label aus der Rollen-Verwaltung) verweisen; bei fehlendem Recht generisch auf
den Administrator.

## Qualitäts-Gates vor Abschluss

- `ddev composer phpcs` (PHP), ggf. `phpcbf`.
- `ddev composer twigcs` (Twig), ggf. `twigcbf`.
- Feature-/Unit-Tests grün.
- Neue Textdateien mit LF (außer .bat/.cmd/.ps1).
- Kein `git push` (manuell durch Entwickler).

## Betroffene/neue Dateien

- `composer.json` / `composer.lock` (dompdf)
- `src/Controllers/FinanceController.php` (Refactor + `reportPdf`)
- `src/Services/FinanceReportPdfService.php` (neu)
- `src/Routes.php` (neue Route)
- Container-/DI-Definition (Service-Wiring)
- `templates/finances/report_pdf.twig` (neu)
- `templates/finances/report.twig` (Button)
- `tests/Feature/FinanceFeatureTest.php` (+ ggf. `FinanceBusinessLogicTest.php`)
- `help/finance/…` (neues Hilfethema)

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

- `tecnickcom/tc-lib-pdf ^8` via `ddev composer require tecnickcom/tc-lib-pdf`.
- Begründung: TCPDF ist Stand 2026 **deprecated**; tc-lib-pdf ist der aktiv
  gepflegte, production-ready Nachfolger (SemVer, pures PHP, keine Container-
  Binaries). Unterstützt automatische Zeilen-/Seitenumbrüche, MultiCell-
  Äquivalent und Positionierung (posx/posy) — nötig für inkrementelles Rendern
  mit echtem Übertrag.
- Verworfen:
  - **Dompdf** — kann keine per-Seite-Zwischensummen: keine API „welche
    Tabellenzeile liegt auf welcher Seite" nach dem Layout; Seiten-Callbacks
    kennen die Zeilensummen nicht.
  - **mPDF** — Pflegezustand.
  - **Headless-Chrome** — Chromium/Node = schweres Container-Setup.
- Risiko: neuere API, weniger Alt-Beispiele als TCPDF → kleiner Spike für die
  Übertrag-/Y-Tracking-Logik einplanen.

## Übertrag (Carry-forward) — Kernanforderung

Wie im Geschäftsbericht/Kassabuch stehen bei Seitenwechsel Zwischensummen:

- **Am Seitenende:** „Übertrag" = kumulierte Summen Einnahmen / Ausgaben /
  Saldo bis zur letzten Zeile dieser Seite.
- **Am Kopf der Folgeseite:** „Übertrag" wird als Startwert wiederholt.
- Auf der letzten Seite endet die Tabelle mit dem Gesamtsaldo (kein Übertrag).

Umsetzung: **inkrementelles Rendern** — die Bewegungstabelle wird **zeilenweise
in PHP** aufgebaut. Vor jeder Zeile wird geprüft, ob sie noch auf die Seite
passt (aktuelle Y-Position + benötigte Zeilenhöhe vs. nutzbare Seitenhöhe minus
Platz für die Übertrag-Fußzeile). Passt sie nicht: Übertrag-Fußzeile schreiben,
neue Seite, Übertrag-Kopfzeile schreiben, dann die Zeile. Beschreibung darf
umbrechen (variable Zeilenhöhe → echte Messung, kein Zeilen-Raten).

Kein Twig-Template für den PDF-Tabellenkörper — das Layout (Kopf, Kennzahlen,
Tabelle, Überträge, Fuß) wird im Service programmatisch mit tc-lib-pdf erzeugt.

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

- Verantwortung: Report-Daten → PDF-Bytes (tc-lib-pdf).
- Baut das Dokument programmatisch: A4 Hochformat, Kopf, Kennzahlen, dann die
  Bewegungstabelle zeilenweise mit Übertrag-Logik (siehe Abschnitt Übertrag).
- Öffentliche Methode z. B. `render(array $reportData): string` gibt die
  PDF-Bytes zurück.
- Konstruktor bekommt nach Bedarf App-Settings (Chorname) und Logger per DI.
- Controller bleibt dünn: Daten holen via `buildReportData()`, an den Service
  geben, Response-Header setzen.
- DI-Verdrahtung in der Container-Definition ergänzen (siehe
  `DependenciesContainerWiringTest`-Muster) — Service auflösbar machen und
  FinanceController-Konstruktor um den Service erweitern.
- Interne Struktur klar trennen (kleine, testbare Einheiten):
  - Seiten-/Spalten-Geometrie (Konstanten: Ränder, Spaltenbreiten, Zeilenhöhe,
    reservierte Höhe der Übertrag-Fußzeile).
  - Kopf-/Kennzahlen-Block.
  - Zeilen-Renderer + Übertrag-Umbruchlogik (Y-Tracking).
  - Fußzeile (Seitenzahl + Erstelldatum).

### 4. PDF-Layout (im Service, kein Twig)

Da der Tabellenkörper inkrementell mit Übertrag gerendert wird, gibt es **kein**
`report_pdf.twig`. Das Layout entsteht im Service:

- **Kopf:** App-/Chorname (App-Settings) + Titel „Kassabuch Geschäftsjahr
  DD.MM.YYYY – DD.MM.YYYY".
- **Kennzahlen:** Einnahmen / Ausgaben / Saldo (nur diese drei; keine
  Gruppen-/Zahlungsart-Detailtabellen).
- **Bewegungstabelle** (chronologisch, invoice_date aufsteigend), Spalten:
  **Datum · Lfd. Nr. · Beschreibung · Zahlungsart (Bar/Bank) · Einnahme ·
  Ausgabe · Laufsaldo**. Übertrag-Zeilen am Seitenende/-kopf.
- **Abschluss:** Gesamtsaldo-Zeile auf der letzten Seite.
- **Seitenfuß:** Seitenzahl + Erstelldatum.
- Beträge im deutschen Format (`number_format(2, ',', '.')`), echte Umlaute
  (Font mit vollständiger Latin-1-/UTF-8-Abdeckung wählen).

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
- **Übertrag-Test:** mit genug Buchungen für ≥2 Seiten liefert der Service ein
  mehrseitiges PDF (Seitenzahl > 1) und der finale Gesamtsaldo stimmt. Wo mit
  vertretbarem Aufwand möglich, die Übertrag-/Umbruch-Rechenlogik als reine,
  von tc-lib-pdf entkoppelte Einheit prüfen (Seiten-Aufteilung + kumulierte
  Überträge für eine gegebene Zeilenliste und Kapazität).
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

- `composer.json` / `composer.lock` (tc-lib-pdf)
- `src/Controllers/FinanceController.php` (Refactor + `reportPdf`)
- `src/Services/FinanceReportPdfService.php` (neu)
- `src/Routes.php` (neue Route)
- Container-/DI-Definition (Service-Wiring)
- `templates/finances/report.twig` (Button)
- `tests/Feature/FinanceFeatureTest.php` (+ ggf. `FinanceBusinessLogicTest.php`)
- `help/finance/…` (neues Hilfethema)

_(Kein `report_pdf.twig` — PDF-Layout entsteht programmatisch im Service.)_

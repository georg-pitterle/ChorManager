# Kontoauszug-Import im Kassabuch

## Context

Buchungen im Kassabuch (`/finances`) werden derzeit ausschließlich manuell über das Modal `#financeModal` erfasst. Für ein Vereinskonto bedeutet das, jede Bankbewegung doppelt zu tippen — fehleranfällig und zeitaufwendig.

Unter `var/bank_statement_import/Umsatzübersicht_20260811_AT911600000100629615.csv` liegt ein echter Beispiel-Kontoauszug (BTV/Austrian-Bank-Export). Ziel: dieser CSV-Export lässt sich unter Finanzen hochladen, wird geparst, in einer Vorschau geprüft und dann als Buchungen übernommen. Bereits importierte Zeilen werden erkannt und übersprungen, damit ein wiederholter Import derselben Datei keine Dubletten erzeugt.

Es gibt bisher **keinerlei CSV-/Import-Code** im Projekt — das ist das erste Import-Feature.

### Format der Beispieldatei (maßgeblich für den Parser)

```
Buchungsdatum;Valutadatum;Betrag;Währung;Auftraggebername;Auftraggeber IBAN/Kto.Nr.;Auftraggeber BIC/BLZ;Empfängername;Empfänger IBAN/Kto.Nr.;Empfänger BIC/BLZ;Text;Verwendungszweck
04.08.2026;04.08.2026;32,96;EUR;STRIPE;NL41CITI2032304805;CITINL2X   ;CHORKUMA;AT911600000100629615;BTVAAT22XXX;STRIPE, KUPF SERV-…, SEPA-Überweisung;KUPF SERVICES GMBH
27.07.2026;27.07.2026;-3605,70;EUR;Chorkuma;AT911600000100629615;BTVAAT22XXX;Tiroler Landestheater und Orchester;AT425700030055434325;HYPTAT22XXX;…;SR.260107, HDM.600023
```

- UTF-8 **mit BOM**, Trennzeichen `;`, Datum `dd.mm.yyyy`, Dezimalkomma mit Vorzeichen
- Vorzeichen bestimmt die Art: negativ → `expense`, positiv → `income`; `amount` wird als Betrag ohne Vorzeichen gespeichert (Schema-Konvention)
- Freitextfelder enthalten Kommas — Parser muss feldweise über den Header mappen, nicht positionsbasiert

### Entschiedene Abbildung

| CSV | Finance-Feld |
|---|---|
| Buchungsdatum | `invoice_date` |
| Valutadatum | `payment_date` |
| Betrag (Vorzeichen) | `type` (`income`/`expense`) |
| Betrag (Absolutwert) | `amount` |
| Gegenpartei + `Verwendungszweck` | `description` (auf 255 Zeichen gekürzt) |
| — | `payment_method` = `bank_transfer` (fix) |
| in der Vorschau wählbar | `group_name` + `finance_group_id` |

Gegenpartei = `Empfängername` bei Ausgang, `Auftraggebername` bei Eingang.

---

## 1. Migration: Dublettenschutz

Neue Phinx-Migration `db/migrations/<ts>_add_import_hash_to_finances.php` (über `/phinx-migration` erstellen):

```sql
ALTER TABLE finances
    ADD COLUMN import_hash CHAR(64) NULL DEFAULT NULL AFTER payment_method,
    ADD UNIQUE KEY uq_finances_import_hash (import_hash);
```

MySQL erlaubt beliebig viele `NULL`-Werte in einem UNIQUE-Index — manuell erfasste Buchungen bleiben also unberührt. `down()` entfernt Index und Spalte.

Danach `import_hash` in `src/Models/Finance.php` zu `$fillable` ergänzen.

---

## 2. Parser: `src/Services/BankStatementImportService.php` (neu)

Reiner Parser ohne DB-Zugriff, damit er direkt unit-testbar ist. Stil nach `src/Services/BackupService.php`: `declare(strict_types=1)`, `namespace App\Services`, `private readonly LoggerInterface $logger` per Constructor Property Promotion.

**Öffentliche API**

- `public function parse(string $rawContent): array` — liefert
  `['rows' => ParsedRow[], 'errors' => string[]]`.
  Jede `ParsedRow` ist ein Array mit `invoice_date`, `payment_date`, `amount` (positiv, String), `type`, `description`, `counterparty`, `counterparty_iban`, `purpose`, `import_hash`, `error` (`?string`).
- `public static function validateUpload(UploadedFileInterface $file): ?string` — Dateiendung `.csv`, Größe ≤ 2 MB, erkannter MIME in `text/csv`, `text/plain`, `application/csv`, `application/vnd.ms-excel`, `application/octet-stream`. Gibt deutsche Fehlermeldung oder `null` zurück.

**Verarbeitungsschritte**

1. UTF-8-BOM (`\xEF\xBB\xBF`) entfernen.
2. Wenn `mb_check_encoding($content, 'UTF-8')` fehlschlägt: `mb_convert_encoding(..., 'UTF-8', 'Windows-1252')` (viele Bank-Exporte sind CP1252).
3. Zeilenweise mit `str_getcsv($line, ';', '"')`; Kopfzeile auf ein Spaltenindex-Map normalisieren (getrimmt, kleingeschrieben) — fehlende Pflichtspalten (`Buchungsdatum`, `Betrag`) → globaler Fehler, Abbruch.
4. Pro Datenzeile:
   - Datum via `DateTimeImmutable::createFromFormat('d.m.Y', …)` + Rückvalidierung; ungültig → Zeilenfehler.
   - Betrag über die vorhandene, bereits getestete Helper-Methode `FinanceController::normalizeAmountInput()` ([FinanceController.php:69](src/Controllers/FinanceController.php#L69)) — sie behandelt `1.234,56`, `1,234.56` und Vorzeichen korrekt. Nicht neu implementieren. `0` oder nicht-numerisch → Zeilenfehler.
   - Währung ≠ `EUR` → Zeilenfehler (keine Umrechnung).
   - `description` = Gegenpartei + `" - "` + Verwendungszweck, Leerteile weglassen, `mb_substr(…, 0, 255)`.
5. `import_hash` = `hash('sha256', …)` über `invoice_date|payment_date|signierter Betrag|Gegenpartei-IBAN|Verwendungszweck|Text|Vorkommen-Nr`.
   Die **Vorkommen-Nr.** ist der laufende Index innerhalb einer Gruppe identischer Basisschlüssel in derselben Datei (0, 1, 2 …). Damit erzeugen zwei echte, identische Buchungen am selben Tag zwei unterschiedliche Hashes, während ein erneuter Import derselben Datei deterministisch dieselben Hashes liefert.
6. Zeilen aufsteigend nach `invoice_date` sortiert zurückgeben, damit die laufenden Nummern chronologisch vergeben werden (entspricht der Annahme in `DevSeedService::seedFinances()`).

Logging: `finance.import.parsed` (Zeilen gesamt / fehlerhaft), `finance.import.rejected` (Upload abgelehnt, mit `reason`).

**Bewusst nicht** `UploadValidator::validateFileSize()` verwenden: dessen Whitelist ([UploadValidator.php:24](src/Util/UploadValidator.php#L24)) enthält `text/csv` nicht, und sie global zu erweitern würde auch alle Anhang-Uploads aufweichen. `UploadValidator::getUploadErrorMessage()` wird jedoch für die PHP-Upload-Fehlercodes wiederverwendet.

---

## 3. Controller: `src/Controllers/FinanceController.php`

Drei neue Actions; der `BankStatementImportService` wird injiziert.

| Action | Route | Verhalten |
|---|---|---|
| `importPreview()` | `POST /finances/import/preview` | Upload validieren → parsen → bekannte Hashes über `Finance::whereIn('import_hash', …)->pluck('import_hash')` markieren → Ergebnis in `$_SESSION['finance_import']` (`rows`, `filename`, `created_at`) ablegen → `finances/import.twig` rendern. Fehler → Flash + Redirect `/finances`. |
| `importConfirm()` | `POST /finances/import/confirm` | Session-Payload lesen (fehlt/älter als 30 Min → Flash „Import abgelaufen" + Redirect). Nur die per `selected[]` gewählten Indizes übernehmen, Gruppe je Index aus `group[<i>]`. Beträge/Daten kommen **ausschließlich aus der Session**, nie aus dem POST — so ist keine Manipulation der Zahlen im Browser möglich. Insert in einer `Capsule::connection()->transaction()`, Session-Key danach löschen, Flash „X Buchungen importiert, Y übersprungen." |
| `importCancel()` | `POST /finances/import/cancel` | Session-Key löschen, Redirect `/finances`. |

Pro Zeile beim Insert:
- `finance_group_id` via `FinanceGroup::firstOrCreate(['name' => …])`, `group_name` als denormalisiertes Spiegelfeld mitschreiben — beide Felder sind Pflicht, sonst brechen die Budget-Ist-Werte in [BudgetService.php:111](src/Services/BudgetService.php#L111).
- Zeilen mit Fehler oder bereits bekanntem Hash werden serverseitig hart übersprungen, auch wenn sie angehakt wurden.
- `import_hash` mitschreiben.

**Refactoring laufende Nummern:** `nextRunningNumber()` ([FinanceController.php:286](src/Controllers/FinanceController.php#L286)) sperrt pro Aufruf die `settings`-Zeile `finance_next_running_number`. Für einen Massenimport wird daraus `reserveRunningNumbers(int $count): int`, das einen zusammenhängenden Block reserviert und die erste Nummer zurückgibt; `nextRunningNumber()` delegiert mit `count = 1`. Bestehendes Verhalten (`max(counterNext, tableNext)`, `INSERT IGNORE` + `lockForUpdate()`) bleibt unverändert.

**DI:** Der Controller wird in [Dependencies.php:187](src/Dependencies.php#L187) explizit verdrahtet (kein Autowiring). Die Factory muss um `$c->get(BankStatementImportService::class)` erweitert werden, sonst schlägt die Auflösung fehl. `BankStatementImportService::class => \DI\autowire()` ergänzen.

**Routen** in [src/Routes.php:281](src/Routes.php#L281) in die bestehende **Write**-Gruppe (`RoleMiddleware(requiresFinanceManagement: true)`). CSRF läuft global über `CsrfMiddleware` und funktioniert auch bei `multipart/form-data`.

---

## 4. Templates & Assets

**[templates/finances/index.twig](templates/finances/index.twig)**
- Import-Button in die `btn-group` bei Zeile 32–45, innerhalb `{% if can_write_finances %}`: `<i class="bi bi-upload"></i> Import`, öffnet `#financeImportModal`.
- Neues Modal `#financeImportModal` bei den übrigen Modals: `<form action="/finances/import/preview" method="post" enctype="multipart/form-data">` mit `<input type="file" name="statement" accept=".csv">` und einem kurzen Hinweis auf das erwartete Format. **Kein** `data-upload-compress="true"` — das ist der Bild-Kompressor aus `public/js/common.js` und würde eine CSV beschädigen.

**templates/finances/import.twig (neu)**
- Kopfbereich: Dateiname, Anzahl erkannter Zeilen, Anzahl Dubletten, Anzahl Fehler.
- Ein `<form action="/finances/import/confirm" method="post">` mit Tabelle: Checkbox (`selected[]`), Buchungsdatum, Valutadatum, Beschreibung, Art (Badge Eingang/Ausgang wie in `index.twig`), Betrag rechtsbündig, Gruppen-`<select name="group[<i>]">` aus den vorhandenen `FinanceGroup`-Namen plus Leereintrag.
- Dubletten- und Fehlerzeilen: Checkbox `disabled`, Zeile ausgegraut, Badge „bereits importiert" bzw. Fehlertext.
- Buttons: „Abbrechen" (POST auf `/finances/import/cancel`) und „N Zeilen übernehmen".
- Twig-Regeln beachten: doppelte Anführungszeichen, mehrteilige Boolean-Ausdrücke vorher in `{% set %}` auslagern, keine Zeile über 130 Zeichen.

**public/js/finance-import.js (neu)** — nur „alle aus-/abwählen" und die Live-Zählung im Übernehmen-Button. Kein Inline-JS, Einbindung über `{% block scripts %}`. Etwaige neue Klassen nach `public/css/style.css`, kein `style="…"`.

---

## 5. Seed-Daten

Kein neues Table, daher bleibt `resetSeedData()` unverändert (`finances` wird bereits geleert). In [src/Services/DevSeedService.php](src/Services/DevSeedService.php):

- In `seedFinances()` bei rund 20 der `bank_transfer`-Buchungen einen deterministischen `import_hash` setzen (`hash('sha256', 'seed-import-' . $runningNumber)`), damit der Dubletten-Pfad in Dev sichtbar ist.
- Neuen Report-Zähler `finances_imported` im `counts`-Block bei Zeile ~133 ergänzen und hochzählen.
- Beispiel-CSV zusätzlich als Test-Fixture nach `tests/Fixtures/bank_statement_sample.csv` kopieren (`var/` ist gitignored, die Datei muss für Tests versioniert sein).

Abschluss laut `/dev-seed-completeness`: echten Seed-Lauf ausführen und den Report auf den neuen Zähler prüfen.

---

## 6. Tests (TDD — zuerst schreiben, dann implementieren)

**tests/Unit/Services/BankStatementImportServiceTest.php (neu)** gegen `tests/Fixtures/bank_statement_sample.csv`:
- 4 Zeilen erkannt, keine globalen Fehler
- `04.08.2026` → `invoice_date = 2026-08-04`, `payment_date = 2026-08-04`
- `-3605,70` → `type = expense`, `amount = 3605.70` (positiv)
- `32,96` → `type = income`
- Beschreibung: `"Tiroler Landestheater und Orchester - SR.260107, HDM.600023"`
- BOM wird entfernt (erste Spaltenüberschrift ist exakt `Buchungsdatum`)
- CP1252-kodierter Inhalt wird korrekt nach UTF-8 konvertiert (Umlauttest)
- ungültiges Datum / nicht-numerischer Betrag / Währung `USD` → Zeilenfehler, restliche Zeilen weiterhin gültig
- fehlende Pflichtspalte im Header → globaler Fehler
- Hash-Stabilität: zweimaliges Parsen liefert identische Hashes; zwei identische Buchungen in einer Datei liefern zwei verschiedene Hashes
- `validateUpload()`: `.txt` und Übergröße werden abgelehnt

**tests/Feature/FinanceImportFeatureTest.php (neu)**, Muster aus `tests/Feature/BackupControllerHttpTest.php` (Controller direkt instanziieren, `Twig`-Stub, `NullLogger`, `$_SESSION = []` in `setUp`, manuelles Aufräumen in `tearDown`):
- `importPreview()` mit gültigem Upload (`Slim\Psr7\UploadedFile`) füllt `$_SESSION['finance_import']` mit 4 Zeilen
- `importPreview()` mit `.txt` → Flash-Fehler + Redirect `/finances`
- `importConfirm()` legt die gewählten Zeilen an: `payment_method = bank_transfer`, fortlaufende `running_number`, gesetzter `import_hash`, gesetzte `finance_group_id` **und** `group_name`
- zweiter Durchlauf derselben Datei: Zeilen als Dublette markiert, keine neuen Datensätze
- fehlende/abgelaufene Session → Flash + Redirect, kein Insert
- Routen-Pin analog `FinanceFeatureTest.php:24`: `src/Routes.php` enthält `'/finances/import/preview'` und `requiresFinanceManagement`
- Template-Pin: `templates/finances/index.twig` enthält den Import-Button

---

## 7. Hilfetext

`help/finance/docs/finance.md` um einen Abschnitt „Kontoauszug importieren" ergänzen (Ablauf, unterstütztes Format, Dublettenerkennung, Gruppenzuweisung). Screenshots über die Skill `/create-help-topic`. Kein Rollenname im Text — auf das Recht **„Finanzen lesen und schreiben"** verweisen und bei fehlender Berechtigung generisch auf „den Administrator".

---

## Verifikation

```powershell
ddev exec ./vendor/bin/phinx migrate      # import_hash + UNIQUE-Index angelegt
ddev composer test                        # Unit- + Feature-Tests grün
ddev composer phpcs                       # ggf. ddev composer phpcbf
ddev composer twigcs                      # ggf. ddev composer twigcbf
ddev composer seed:dev                    # Report enthält finances_imported > 0
```

Manuell in Dev (`/finances` als Benutzer mit „Finanzen lesen und schreiben"):
1. Import → `var/bank_statement_import/Umsatzübersicht_20260811_AT911600000100629615.csv` hochladen
2. Vorschau zeigt 4 Zeilen, 1 Eingang / 3 Ausgänge, Beträge ohne Vorzeichen, Beschreibungen befüllt
3. Eine Zeile abwählen, zwei Gruppen zuweisen, übernehmen → Flash „3 Buchungen importiert, 0 übersprungen."
4. Buchungen erscheinen mit fortlaufenden Nummern und Zahlungsart „Überweisung" in der Tabelle
5. Dieselbe Datei erneut hochladen → alle bereits übernommenen Zeilen als „bereits importiert" gesperrt
6. `/budget` prüfen: die zugewiesenen Gruppen erscheinen in den Ist-Werten

Alle angefassten Textdateien nach dem Schreiben auf LF normalisieren (`.ps1`/`.bat`/`.cmd` ausgenommen). Kein `git push`.

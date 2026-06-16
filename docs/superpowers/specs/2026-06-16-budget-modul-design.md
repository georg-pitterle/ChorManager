# Design: Budget-Modul

**Datum:** 2026-06-16  
**Status:** Genehmigt

---

## Überblick

Das Budget-Modul ermöglicht die Haushaltsjahresplanung für den Chorverein. Nutzer legen geplante Budgetkategorien mit zugehörigen Einzelposten an. Die geplanten Beträge werden mit den tatsächlichen Buchungen aus dem Finance-Modul verglichen (Soll/Ist-Vergleich). Das Modul ist über ein `.env`-Flag freischaltbar und benötigt eine eigene Rollenberechtigung.

---

## Feature-Flag

```
FEATURE_BUDGET=true
```

In `src/Settings.php` (analog zu `sheet_archive`):

```php
'modules' => [
    'sheet_archive' => EnvHelper::read('FEATURE_SHEET_ARCHIVE', 'false') === 'true',
    'budget'        => EnvHelper::read('FEATURE_BUDGET', 'false') === 'true',
],
```

Ist das Flag nicht gesetzt, werden alle Budget-Routen nicht registriert und der Menüpunkt wird ausgeblendet.

---

## Rollenberechtigung

Neue Spalte `can_manage_budget` in der `roles`-Tabelle (Typ: `tinyint(1) NOT NULL DEFAULT 0`).

- Nur Nutzer mit `can_manage_budget = 1` dürfen auf das Budget-Modul zugreifen (Lesen + Schreiben).
- Admins (`can_manage_users`) erhalten wie bei anderen Modulen implizit Zugriff.
- `can_manage_finances` gibt keinen Zugriff auf das Budget-Modul.

---

## Datenmodell

### Tabelle `budget_categories`

| Spalte              | Typ                        | Beschreibung                                                          |
|---------------------|----------------------------|-----------------------------------------------------------------------|
| `id`                | INT PK AUTO_INCREMENT      |                                                                       |
| `fiscal_year_start` | INT NOT NULL               | Startjahr des Haushaltsjahres (z. B. 2026 für HJ 2026/27)            |
| `group_name`        | VARCHAR(255) NOT NULL      | Kategoriename; entspricht `finances.group_name` für Ist-Verknüpfung |
| `type`              | ENUM('income','expense')   | Einnahme oder Ausgabe                                                 |
| `created_at`        | TIMESTAMP                  |                                                                       |
| `updated_at`        | TIMESTAMP                  |                                                                       |

**Unique Constraint:** `(fiscal_year_start, group_name, type)`

### Tabelle `budget_items`

| Spalte                | Typ                   | Beschreibung                                    |
|-----------------------|-----------------------|-------------------------------------------------|
| `id`                  | INT PK AUTO_INCREMENT |                                                 |
| `budget_category_id`  | INT NOT NULL FK       | → `budget_categories.id` (CASCADE DELETE)       |
| `description`         | VARCHAR(255) NOT NULL | Bezeichnung des Postens (z. B. „Notenkauf Alt") |
| `planned_amount`      | DECIMAL(10,2) NOT NULL| Geplanter Betrag                                |
| `created_at`          | TIMESTAMP             |                                                 |
| `updated_at`          | TIMESTAMP             |                                                 |

Die **geplante Gesamtsumme** einer Kategorie ergibt sich aus `SUM(budget_items.planned_amount)` für alle Posten der Kategorie.

---

## Soll/Ist-Vergleich

Die Ist-Summe pro Kategorie wird aus der `finances`-Tabelle aggregiert:

- Filterbedingung: `group_name = category.group_name` AND `type = category.type` AND `invoice_date` liegt im Haushaltsjahr
- Das Haushaltsjahresintervall wird über `FinanceController::getFiscalConfig()` und `datesForYear()` berechnet (identische Logik wie im Finance-Modul).
- Vergleich: `geplant - ist = Differenz` (positiv = unter Budget, negativ = überschritten).

---

## Architektur & Komponenten

### Controller: `src/Controllers/BudgetController.php`

| Methode           | Route                                        | Beschreibung                              |
|-------------------|----------------------------------------------|-------------------------------------------|
| `index`           | `GET /budget`                                | Jahresübersicht (Soll/Ist je Kategorie), `?year=` als Query-Parameter |
| `createCategory`  | `POST /budget/categories`                    | Neue Kategorie anlegen                    |
| `updateCategory`  | `POST /budget/categories/{id}/update`        | Kategorie bearbeiten                      |
| `deleteCategory`  | `POST /budget/categories/{id}/delete`        | Kategorie + Posten löschen (Cascade)      |
| `createItem`      | `POST /budget/categories/{id}/items`         | Neuen Posten anlegen                      |
| `updateItem`      | `POST /budget/items/{id}/update`             | Posten bearbeiten                         |
| `deleteItem`      | `POST /budget/items/{id}/delete`             | Posten löschen                            |

### Service: `src/Services/BudgetService.php`

- `getOverview(int $fiscalYearStart): array` — gibt alle Kategorien mit Posten, Soll-Summe und Ist-Summe für das Haushaltsjahr zurück.
- `computeActual(string $groupName, string $type, Carbon $from, Carbon $to): string` — aggregiert Ist-Beträge aus `finances`.
- Nutzt intern `FinanceController::getFiscalConfig()` und `datesForYear()` (oder extrahiert diese Logik in eine gemeinsam nutzbare Utility-Methode).

### Models

- `src/Models/BudgetCategory.php` — Eloquent-Model für `budget_categories`
- `src/Models/BudgetItem.php` — Eloquent-Model für `budget_items`

### RoleMiddleware

Neue Parameter-Erweiterung in `RoleMiddleware`:

```php
bool $requiresBudgetManagement = false
```

Prüflogik analog zu `requiresSheetArchiveManagement`. Session-Key: `can_manage_budget`.

### Routen (`src/Routes.php`)

Alle Budget-Routen werden nur registriert wenn `$settings['modules']['budget'] ?? false`:

```php
if ($settings['modules']['budget'] ?? false) {
    $group->group('/budget', function (...) {
        // Routes
    })->add(new RoleMiddleware(requiresBudgetManagement: true));
}
```

---

## Templates

### `templates/budget/index.twig`

Aufbau der Übersichtsseite:

- Jahres-Selector (Links für verfügbare Haushaltsjahre + aktuelles)
- Je Abschnitt „Einnahmen" / „Ausgaben":
  - Tabelle der Kategorien mit Spalten: Kategorie | Posten (aufklappbar) | Geplant (Summe) | Ist | Differenz
  - Aufgeklappte Posten-Zeilen (Einrückung, kein eigenes Ist — Ist nur auf Kategorieebene)
  - „Kategorie hinzufügen"-Button (nur wenn `can_manage_budget`)
  - Modal-Formulare für Anlegen und Bearbeiten von Kategorien und Posten (analog zum Finance-Modul)
- Summenzeile je Abschnitt

### Navigation

In `templates/partials/nav.twig` (o. ä.):

```twig
{% if settings.modules.budget and session.can_manage_budget %}
    <li><a href="/budget">Budget</a></li>
{% endif %}
```

---

## Migrationen

Drei aufeinanderfolgende Migrationen:

1. **`create_budget_tables`** — legt `budget_categories` und `budget_items` an
2. **`add_can_manage_budget_to_roles`** — fügt `can_manage_budget` zur `roles`-Tabelle hinzu
3. **`backfill_budget_permission_for_default_roles`** — setzt `can_manage_budget = 1` für Rollen mit `can_manage_finances = 1` (sinnvoller Default-Backfill)

---

## Rollenverwaltung (UI)

Im `templates/roles/index.twig` wird `can_manage_budget` als Checkbox ergänzt, analog zu `can_manage_sheet_archive`. Das Feld erscheint nur wenn `settings.modules.budget`.

---

## Tests

Datei: `tests/Feature/BudgetTest.php`

| Testfall | Beschreibung |
|---|---|
| Feature-Flag deaktiviert | `GET /budget` → 404 |
| Zugriff ohne Berechtigung | `GET /budget` als User ohne `can_manage_budget` → 403 |
| Kategorie anlegen | POST → Kategorie in DB, korrekte Felder |
| Doppelte Kategorie | Unique Constraint → Fehlermeldung |
| Posten anlegen | POST → Item in DB, verknüpft mit Kategorie |
| Kategorie löschen | Cascade → Posten werden mitgelöscht |
| Soll/Ist-Vergleich | Finance-Buchungen im Haushaltsjahr werden korrekt aggregiert |
| Jahreswechsel | `?year=2025` zeigt anderes Haushaltsjahr |

---

## Seed-Daten

In `src/Services/DevSeedService.php`:

- `resetSeedData()` bereinigt `budget_categories` und `budget_items`
- Neue Methode `seedBudget()`, eingebunden in `run()` nach Finance-Seed
- Beispielkategorien (Einnahmen: „Mitgliedsbeiträge", „Konzerteinnahmen"; Ausgaben: „Honorare", „Notenmaterial", „Proberaummiete")
- Je 2–3 Posten pro Kategorie mit realistischen Beträgen
- Passende Finance-Buchungen mit gleichen `group_name`-Werten im selben Haushaltsjahr (für sichtbaren Soll/Ist-Vergleich)
- Seed-Report: `"Budget-Kategorien: X, Budget-Posten: Y"`

---

## Nicht im Scope

- Export (CSV, PDF)
- Mehrjahresvergleich
- Kommentarfelder auf Kategorien/Posten
- Öffentliche (nicht-authentifizierte) Budget-Ansicht
- Budgets pro Projekt (nur pro Haushaltsjahr)

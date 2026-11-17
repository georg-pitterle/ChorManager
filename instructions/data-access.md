# Datenzugriff: Lesen und Schreiben getrennt

Applies to: `src/Controllers/**/*.php`

1. **Ein Controller schreibt nie selbst.** Verboten sind Schreibaufrufe (`save`, `update`,
   `delete`, `create`, `sync`, `attach`, `detach`, `upsert`, `updateOrCreate`,
   `firstOrCreate` und verwandte) auf Modellen, Abfragen und Relationen. Erlaubt ist
   derselbe Methodenname auf einer injizierten Abhängigkeit
   (`$this->taskWriter->update(...)`).
2. **Wohin Schreibwege gehen:**
   - `src/Persistence/<Bereich>Persistence` für Schreibwege ohne Fachregel: anlegen, ändern,
     löschen, Zuordnungen setzen, auch mehrere Schritte in einer Transaktion.
   - `src/Services/<Bereich>WriteService` für Schreibwege mit Fachregel: Sperren,
     Nummernkreis, Verlaufseinträge, Zustandsübergänge.
   - Bestehende Ausnahmen im Namen bleiben: `FinanceBookingService`, `ParticipationWriter`.
3. **Ein Controller liest Listen nicht selbst.** Verboten sind statische Aufrufe auf einer
   Modellklasse (`Song::with(...)`, `Category::orderBy(...)`, `Sponsorship::where(...)`).
   Erlaubt bleiben:
   - `Model::find($id)` und `Model::findOrFail($id)` für den einen Datensatz einer Route,
   - dasselbe mit vorgeladenen Beziehungen: `Model::with([...])->find($id)` bzw. `findOrFail`,
   - Zugriffe über ein schon geladenes Modell (`$list->columns()->get()`).
   Alles andere geht in `src/Queries/<Bereich>Query`.
4. Dienste in `src/Services` und die Abfrageschicht selbst sind von der Regel nicht betroffen.

## Warum

Die Schichtung vom 16.11.2026 hat gezeigt, dass die Schreibwege mit Regeln (Terminserie,
Mitglied) im Controller nicht atomar waren und sich ohne Controller nicht prüfen ließen. Die
Lesezugriffe im Controller sind dagegen vor allem Unordnung: dieselbe Abfrage steht an
mehreren Stellen, und keine ist ohne Controller testbar.

## Durchsetzung

`tests/Unit/Controllers/ControllerDataAccessTest.php` zählt je Controller die direkten
Zugriffe und vergleicht mit den Listen `KNOWN_DIRECT_WRITES` und `KNOWN_DIRECT_READS`
(Sperrklinke): Eine neue Stelle macht den Test rot, eine abgebaute ebenfalls, bis die Liste
nachgezogen ist. Wer einen Controller ohnehin anfasst, zieht seine Zugriffe um und streicht
den Eintrag.

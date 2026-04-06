# Alte Termine ausblenden â€” Design

## Kontext

Die Events-Seite zeigt derzeit alle Termine, unabhÃ¤ngig davon, wie lange sie in der Vergangenheit liegen. Das fÃ¼hrt zu UnÃ¼bersichtlichkeit, besonders bei aktiv genutzten Systemen mit Jahren von Daten. Benutzer mÃ¼ssen durch lange Listen blÃ¤ttern oder filtern, um relevante, aktuelle Termine zu finden.

Dieses Design beschreibt die EinfÃ¼hrung eines intelligenten Filters, der alte Termine (Ã¤lter als 14 Tage) standardmÃ¤ÃŸig ausblendet, aber dem Benutzer jederzeit erlaubt, die vollstÃ¤ndige Historie wieder einzublenden.

## Ziele

- Termine Ã¤lter als 14 Tage standardmÃ¤ÃŸig ausblenden, um die Tabelle Ã¼bersichtlich zu halten.
- Ein einfaches Kontrollmittel (Checkbox) bereitstellen, um alte Termine jederzeit wieder anzuzeigen.
- Das Verhalten in der bestehenden Filterarchitektur integrieren (kein neues Modul).
- URL-Persistierung ermÃ¶glichen, damit Benutzer den Zustand speichern/teilen kÃ¶nnen.
- Auf Desktop und Mobile responsive und intuitiv sein.

## Nicht-Ziele

- Keine Ã„nderungen am Event-Datenmodell oder der Datenbankschema.
- Keine Archivierungs- oder LÃ¶sch-Logik.
- Keine umfassende Historien-Timeline oder Recherchefunktion.

## Architektur und Verantwortlichkeiten

### Controller

**Datei:** `src/Controllers/EventController.php`

Der Controller extrahiert den neuen Query-Parameter `show_old_events` (Standard: `0`) und wendet einen zusÃ¤tzlichen `whereDate()`-Filter an, wenn dieser Parameter nicht gesetzt ist:

```php
$showOldEvents = !empty($queryParams['show_old_events']) ? (int)$queryParams['show_old_events'] : 0;

// ...existing code...

if (!$showOldEvents) {
    $query->whereDate('event_date', '>=', now()->subDays(14));
}
```

Der Filter wird **vor** der Sortierung ausgefÃ¼hrt, damit die Datenmenge frÃ¼h reduziert wird.

Der Parameter wird in das `$filters` Array Ã¼bergeben, das an das Template weitergeleitet wird.

### Template

**Datei:** `templates/events/index.twig`

Im bestehenden Filter-Panel wird eine neue Checkbox hinzugefÃ¼gt:

```twig
<div class="col-12 col-md-2 mb-2">
    <div class="form-check">
        <input type="checkbox" class="form-check-input" id="show_old_events" 
               name="show_old_events" value="1" 
               {% if filters.show_old_events %}checked{% endif %}>
        <label class="form-check-label small" for="show_old_events">
            Alte Termine anzeigen
        </label>
    </div>
</div>
```

Die Checkbox wird als drittes Steuerelement im `row g-3 align-items-end`-Grid platziert, nach Projekt und Event-Typ.

Das `<form>` wird mit `method="get"` so konfiguriert, dass die Checkbox-Ã„nderung automatisch das Formular absendet (entweder via `.change()` Event oder HTML5 `form.submit()`).

### Query-Parameter

- **Name:** `show_old_events`
- **Werte:** `0` (ausgeblendet) oder `1` (angezeigt)
- **Default:** `0` (nicht in URL, bedeutet ausgeblendet)
- **Kombinierbarkeit:** Funktioniert mit `project_id`, `event_type_id`, `sort`, `direction`

Beispiel-URLs:
- `/events` â€” Nur aktuelle Termine (Ã¤lter als 14d ausgeblendet)
- `/events?show_old_events=1` â€” Alle Termine
- `/events?show_old_events=1&project_id=2` â€” Alle Termine von Projekt 2
- `/events` (nach Reset) â€” Zum Standard zurÃ¼ck

## Datenfilter und GrenzfÃ¤lle

### Filter-Berechnung

Der Filter ist definiert als:

```
IF show_old_events = 0:
  event_date >= NOW() - 14 DAYS
ELSE:
  (no filter, show all)
```

### GrenzfÃ¤lle

1. **Event von exakt 14 Tagen:** Wird angezeigt (>= Vergleich)
2. **Event von 14 Tagen + 1 Minute:** Wird ausgeblendet (Ã¤lter als Grenzwert)
3. **Event in Zukunft:** Wird immer angezeigt (in Zukunft liegt auÃŸerhalb des Filters)
4. **Event heute:** Wird angezeigt
5. **Keine Events:** Leere Tabelle mit Meldung â€žKeine Termine geplant"

## Interaktion und Navigation

- **Checkbox Ã¤ndern:** Formular wird abgesendet, Seite lÃ¤dt neu mit neuem `show_old_events`-Wert
- **Reset klicken:** Navigiert zu `/events` (setzt alle Parameter zurÃ¼ck, einschlieÃŸlich `show_old_events`)
- **Andere Filter Ã¤ndern:** Der `show_old_events`-Wert wird in der URL mitgetragen (nicht zurÃ¼ckgesetzt)
- **Seite neuladen:** Checkbox bleibt im Zustand der URL (persistent Ã¼ber Reload)
- **Lesezeichen:** Benutzer kann z.B. `/events?show_old_events=1` als Lesezeichen speichern

## UI- und UX-Regeln

- Checkbox-Label ist kurz und verstÃ¤ndlich: â€žAlte Termine anzeigen"
- Checkbox sitzt **nach** den Dropdown-Filtern (Projekt, Event-Typ), aber **vor** dem Reset-Button
- Im 3-Spalten-Grid: Projekt (col-12 col-md-5), Event-Typ (col-12 col-md-5), Checkbox (col-12 col-md-2)
- Auf Mobile: Checkbox nimmt volle Breite an (`col-12`), ist aber leicht zu klicken
- Der Reset-Button setzt auch diese Checkbox zurÃ¼ck (keine Spezialfalldefinitionen nÃ¶tig)
- Checkbox ist weder vorausgewÃ¤hlt noch verborgen, sondern neutral und deutlich sichtbar
- Optional: Eine kleine Badge neben dem Label (z.B. â€ž14d+") zur ErklÃ¤rung, was â€žalt" bedeutet

## Tests

**Feature-Tests zu schreiben:**

1. **Alte Termine standardmÃ¤ÃŸig ausgeblendet**
   - Event vor 20 Tagen wird NICHT angezeigt bei `/events`
   - Event vor 5 Tagen wird angezeigt bei `/events`

2. **Mit Checkbox alte Termine anzeigen**
   - Event vor 20 Tagen wird angezeigt bei `/events?show_old_events=1`

3. **Grenzfall 14 Tage**
   - Event von exakt 14 Tagen wird angezeigt (>= Vergleich)

4. **Checkbox Status persistiert**
   - Nach POST mit `show_old_events=1`, Checkbox ist checked auf nÃ¤chstem GET

5. **Kombination mit anderen Filtern**
   - `/events?project_id=2&show_old_events=1` zeigt alte Termine von Projekt 2

6. **Reset-Button**
   - `/events` (nach Reset) hat keine Parameter und zeigt nur aktuelle Termine

7. **Checkbox ist Bestandteil des Templates**
   - HTML enthÃ¤lt `id="show_old_events" name="show_old_events" value="1"`

## QualitÃ¤ts- und Abnahmekriterien

- âœ… Termine Ã¤lter als 14 Tage werden standardmÃ¤ÃŸig ausgeblendet
- âœ… Checkbox â€žAlte Termine anzeigen" ist im Filter-Panel sichtbar
- âœ… Mit aktivierter Checkbox werden alle Termine angezeigt
- âœ… Grenzfall (exakt 14 Tage) wird als angezeigt behandelt
- âœ… URL-Parameter `show_old_events` wird korrekt verarbeitet
- âœ… Checkbox-Status persistiert Ã¼ber URL (Reload, Lesezeichen)
- âœ… Filter kombiniert sich mit Projekt- und Event-Typ-Filtern ohne Konflikt
- âœ… Reset setzt diesen Filter mit zurÃ¼ck
- âœ… Mobile Responsive: Checkbox ist auf kleinen Bildschirmen gut nutzbar
- âœ… Alle Feature-Tests schreiben grÃ¼n
- âœ… FÃ¼r Twig-Ã„nderungen: `ddev composer twigcs` lÃ¤uft ohne Fehler; ggf. `ddev composer twigcbf`
- âœ… Keine Datenbankmigrationen erforderlich

## Risiken und GegenmaÃŸnahmen

| Risiko | GegenmaÃŸnahme |
|--------|---------------|
| User vergisst, dass Checkbox aktiviert ist, und denkt, dass Termine gelÃ¶scht sind | Checkbox bleibt sichtbar, Label ist klar. Bei Bedarf Hilfetext hinzufÃ¼gen. |
| Falsche Datumsberechnung (Zeitzone, Licht- vs. Sommerzeit) | `now()->subDays(14)` nutzt PHP Carbon, die Anwendung nutzt bereits Carbon Ã¼berall. Keine neuen AbhÃ¤ngigkeiten. |
| Checkbox wird nicht abgesendet | HTML5: `<form method="get">` mit `.change()` Event-Listener fÃ¼r automatisches Submit. Fallback: manuelles Submit via Button. |
| Andere Filterlogik kollidiert | Der Filter wird **nach** project_id und event_type_id, aber **vor** Sortierung eingebaut. Keine Konflikte zu erwarten. |

## Umsetzungsumfang

- **EventController::index():** Query-Parameter extrahieren, whereDate-Filter hinzufÃ¼gen, Parameter ins Template-Array
- **events/index.twig:** Checkbox im Filter-Panel hinzufÃ¼gen, `.change()` Event-Listener anhÃ¤ngen
- **Feature-Tests:** 7 neue oder aktualisierte Tests fÃ¼r obige FÃ¤lle
- **Twig-Linting:** Nach Template-Ã„nderung `ddev composer twigcs` ausfÃ¼hren
- **Event-Tests durchfÃ¼hren:** Existierende Tests sollten weiterhin grÃ¼n sein

## Nicht im Scope

- Separate Admin-Einstellung fÃ¼r die â€ž14 Tage"-Grenze (hart codiert in dieser Version)
- Externe Konfigurationsdatei fÃ¼r diese Grenze
- Dashboard-Widgets oder spezielle Vorschau-Listen
- Archivierungs- oder Soft-Delete-FunktionalitÃ¤t

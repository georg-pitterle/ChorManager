# Design Spec: Mitgliederverwaltung — Gruppierung nach Stimme/Unterstimme

## Kontext

- Bereich: Mitgliederverwaltung `templates/users/manage.twig` mit Plugin `public/js/table-plugins/users-manage-plugin.js`.
- Aktuelles Verhalten: Mitglieder erscheinen als flache, sortierbare Tabelle.
- Ziel: Optionaler Gruppier-Modus, der Mitglieder zweistufig nach Stimmgruppe → Unterstimme in einem Bootstrap-Akkordeon anzeigt. Der Modus ist per Toggle-Button aktivierbar und wird in `localStorage` persistiert.

## Ziele

1. Toggle-Button in der Toolbar der Mitgliederverwaltung schaltet zwischen flacher Tabelle und Akkordeon-Gruppierung um.
2. Gruppierungshierarchie: Stimmgruppe → Unterstimme (zweistufiges Akkordeon).
3. Mitglieder ohne Stimmgruppe erscheinen am Ende in einem "Ohne Zuordnung"-Block.
4. Mitglieder mit Stimmgruppe, aber ohne Unterstimme, erscheinen in einem "Ohne Unterstimme"-Sub-Block.
5. Suche und Filter der Table-Engine wirken auch im Gruppier-Modus.
6. Toggle-Zustand wird in `localStorage` gespeichert; der bestehende "Zurücksetzen"-Button löscht ihn.
7. Im Archiv-Modus (`show_archived=1`) wird der Toggle nicht angeboten.

## Nicht-Ziele

- Kein neuer API-Endpunkt oder Server-seitiger Code (außer einem neuen `data-sub-voice-options`-Attribut im Template).
- Keine Änderung an `table-engine.js`.
- Keine Persistierung des Akkordeon-Auf/Zu-Zustands.
- Keine Gruppierung auf anderen Seiten.

## Gewählter Ansatz

Neues Table-Engine-Plugin (`usersGroup`) analog zum bestehenden `usersManage`-Plugin. Das Plugin rendert den Toggle-Button und baut bei Aktivierung das Akkordeon-DOM aus den vorhandenen `data-*`-Attributen der `<tr>`-Zeilen auf. Kein Server-Code nötig außer dem neuen Template-Attribut.

Begründung: Passt vollständig ins bestehende Plugin-Architektur-Muster, Reset-Integration ist kostenlos über `plugin.reset()`, kein HTML wird doppelt gerendert.

## Architektur und Komponenten

### 1. Template-Änderung (`templates/users/manage.twig`)

- Neues Attribut `data-sub-voice-options` am Table-Shell, analog zu `data-voice-options`:
  ```
  data-sub-voice-options="voiceGroupId:subVoiceId::SubVoiceName||..."
  ```
  Format pro Eintrag: `voiceGroupId:subVoiceId::Name`, getrennt durch `||`.
  Ermöglicht dem Plugin, Unterstimmen eindeutig ihrer Stimmgruppe zuzuordnen.

- Plugin-Liste erweitern:
  ```
  data-table-plugins="usersManage,usersGroup"
  ```

- `show_archived`-Flag als `data-show-archived="1"` am Table-Shell, damit das Plugin den Toggle unterdrücken kann.

### 2. Neues Plugin (`public/js/table-plugins/users-group-plugin.js`)

Registriert sich als `usersGroup` via `ChorTableEngine.registerFilterPlugin`.

**Zustand:**
```js
let groupActive = false; // aus localStorage beim Mount geladen
```
`localStorage`-Key: `chorte.users.manage.groupByVoice`

**Plugin-API:**
```js
{
  mount()        // Toggle-Button rendern, localStorage laden, ggf. Akkordeon aufbauen
  getPredicate() // gibt null zurück (kein eigener Row-Filter)
  getState()     // { groupActive: bool }
  setState()     // setzt groupActive aus persistiertem Zustand
  reset()        // groupActive = false, localStorage-Key entfernen, Akkordeon abbauen
}
```

**Toggle-Button:**
- Platziert im `data-table-plugin-slot`.
- Text: "Nach Stimme gruppieren" (inaktiv) / "Listenansicht" (aktiv).
- Bootstrap-Klassen: `btn btn-sm btn-outline-secondary`.
- Bei `show_archived=1`: Button wird nicht gerendert.

**Akkordeon-Aufbau (`buildAccordion()`):**

1. `data-voice-options` und `data-sub-voice-options` vom Table-Shell parsen → Map von Stimmgruppen und Unterstimmen.
2. Alle sichtbaren (nicht `hidden`) `<tr>`-Zeilen sammeln (= bereits durch Suche+Filter gefiltert).
3. Zeilen nach Stimmgruppe gruppieren (via `data-voice`-Attribut der `<tr>`).
4. Innerhalb jeder Stimmgruppe nach Unterstimme unterteilen (via Text-Match von `data-sort-voice` gegen bekannte Unterstimm-Namen).
5. Bootstrap-Akkordeon-DOM generieren:
   - Äußere Ebene: je Stimmgruppe ein `accordion-item` (standardmäßig zugeklappt).
   - Innere Ebene: je Unterstimme ein verschachteltes `accordion-item` (standardmäßig zugeklappt).
   - Inhalt je Sub-Item: Tabelle mit denselben Spalten wie die Haupt-Tabelle (Zeilen werden geklont).
   - Stimmgruppe/Unterstimme ohne sichtbare Mitglieder: `hidden`.
   - Mitglieder ohne Stimmgruppe: Block "Ohne Zuordnung" am Ende.
   - Mitglieder mit Stimmgruppe, ohne Unterstimme: Sub-Block "Ohne Unterstimme" innerhalb der Stimmgruppe.
   - Alle Mitglieder weggefiltert: leere Meldung "Keine Mitglieder gefunden."
6. Akkordeon direkt nach `<table>` ins DOM einfügen; `<tbody>` der Originaltabelle ausblenden.

**Akkordeon-Abbau (`destroyAccordion()`):**
- Akkordeon-Element aus DOM entfernen.
- `<tbody>` der Originaltabelle wieder einblenden.

**Reaktion auf Suche/Filter:**
Das Plugin setzt beim Aktivieren des Gruppier-Modus einen `MutationObserver` auf den `<tbody>` der Originaltabelle. Da die Table-Engine `hidden`-Attribute auf `<tr>`-Zeilen setzt wenn Suche oder Filter sich ändern, löst jede solche Änderung eine MutationObserver-Callback aus. Das Plugin baut daraufhin das Akkordeon neu auf (debounced mit `requestAnimationFrame`, um Massen-Mutations zu einer einzigen Neuaufbau-Operation zusammenzufassen). Der Observer wird beim Deaktivieren des Gruppier-Modus disconnected.

### 3. Datenbindung — `data-sub-voice-options` Format

Beispiel (Sopran mit Sopran 1 und Sopran 2, VoiceGroup-ID=1, SubVoice-IDs=1,2):
```
1:1::Sopran 1||1:2::Sopran 2||2:3::Alt 1||...
```
Das Plugin parst dies in eine Map:
```js
{
  "1": [{ id: "1", name: "Sopran 1" }, { id: "2", name: "Sopran 2" }],
  "2": [{ id: "3", name: "Alt 1" }],
  ...
}
```

## Datenfluss

```
manage.twig
  data-voice-options="1::Sopran||2::Alt||..."
  data-sub-voice-options="1:1::Sopran 1||1:2::Sopran 2||..."
  data-show-archived="0|1"
        │
        ▼
users-group-plugin.js (mount)
  ├── localStorage lesen → groupActive
  ├── Toggle-Button rendern
  └── if groupActive → buildAccordion()

Toggle-Button click
  ├── groupActive = !groupActive
  ├── localStorage schreiben
  └── buildAccordion() | destroyAccordion()

Table-Engine applyRows() (Suche/Filter geändert)
  └── if groupActive → MutationObserver triggert → buildAccordion() neu aufbauen (rAF-debounced)

plugin.reset()
  ├── groupActive = false
  ├── localStorage-Key entfernen
  └── destroyAccordion()
```

## Fehlerbehandlung & Edge Cases

| Fall | Verhalten |
|---|---|
| Mitglied ohne Stimmgruppe | "Ohne Zuordnung"-Block am Ende |
| Mitglied mit Stimmgruppe, ohne Unterstimme | Sub-Block "Ohne Unterstimme" in der Stimmgruppe |
| Stimmgruppe/Unterstimme ohne Mitglieder (nach Filter) | `hidden` |
| Alle Mitglieder weggefiltert | Meldung "Keine Mitglieder gefunden." |
| `data-sub-voice-options` fehlt im DOM | Nur 1-stufige Gruppierung nach Stimmgruppe, kein Absturz |
| `show_archived=1` | Toggle-Button wird nicht gerendert |
| Akkordeon-Auf/Zu-Zustand | Nicht persistiert, startet immer zugeklappt |

## Testing

**Datei:** `tests/js/users-group-plugin.test.mjs`

Testfälle:
1. Plugin registriert sich korrekt bei `ChorTableEngine`.
2. Toggle-Button wird gerendert wenn `show_archived=0`.
3. Toggle-Button wird nicht gerendert wenn `show_archived=1`.
4. Aktivieren setzt `localStorage`-Key und baut Akkordeon auf.
5. Deaktivieren entfernt `localStorage`-Key und baut Akkordeon ab.
6. `plugin.reset()` entfernt `localStorage`-Key und baut Akkordeon ab.
7. Mitglied mit Stimmgruppe und Unterstimme landet im richtigen Sub-Block.
8. Mitglied ohne Stimmgruppe landet im "Ohne Zuordnung"-Block.
9. Mitglied mit Stimmgruppe ohne Unterstimme landet in "Ohne Unterstimme"-Sub-Block.
10. Nach vollständiger Filterung (keine sichtbaren `<tr>`) erscheint Leermeldung.
11. `data-sub-voice-options` fehlt → 1-stufige Gruppierung ohne Absturz.
12. Akkordeon-Neuaufbau bei `hidden`-Attribut-Änderung auf `<tr>` (MutationObserver-Mechanismus).

## Betroffene Dateien

| Datei | Änderungsart |
|---|---|
| `templates/users/manage.twig` | `data-sub-voice-options`, `data-show-archived`, Plugin-Liste erweitern |
| `public/js/table-plugins/users-group-plugin.js` | Neu erstellen |
| `tests/js/users-group-plugin.test.mjs` | Neu erstellen |

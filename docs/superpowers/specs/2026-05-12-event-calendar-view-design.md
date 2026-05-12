# Event-Kalenderansicht – Design

Datum: 2026-05-12
Status: Freigegeben für Umsetzung

## 1. Ziel

Ergänzung der bestehenden Terminliste (`/events`) um eine responsive Kalenderansicht
(Monat/Woche) auf Basis von FullCalendar v6. Umschalter zwischen Listen- und
Kalenderansicht im Seiten-Header. Terminanlage über Kalender-Slot-Klick für Admins.

## 2. Bibliothek

**FullCalendar v6** (MIT) – Basis-Bundle, keine Premium-Plugins.

Benötigte npm-Pakete:
- `@fullcalendar/core`
- `@fullcalendar/daygrid` (Monatsansicht)
- `@fullcalendar/timegrid` (Wochenansicht)
- `@fullcalendar/interaction` (Slot-Klick)

Asset-Auslieferung: Pre-built-Bundles per `copy-assets.php` aus `node_modules/` nach
`public/vendor/fullcalendar/` kopieren. Kein Build-Tool nötig.

## 3. Architektur

### Ansichten

| Ansicht | FullCalendar-View |
|---|---|
| Monat | `dayGridMonth` |
| Woche | `timeGridWeek` |

Umschaltbar über FullCalendars eingebaute Header-Toolbar (prev/next/today + viewSwitch).

### Ansicht-Umschalter im ChorManager-Header

Im `page_header`-Block von `events/index.twig` werden zwei Buttons ergänzt:
- **Liste** → `/events?view=list`
- **Kalender** → `/events?view=calendar`

Der `EventController::index()` liest den `view`-Parameter (`list` | `calendar`, Default: `list`).
Er übergibt `view_mode` ans Template; Liste und Kalender-Container sind alternativ sichtbar.

### Daten für den Kalender

Kein neuer API-Endpoint. Die bereits serverseitig geladenen `$events` werden zusätzlich als
JSON serialisiert und als `data-calendar-events`-Attribut auf den Kalender-Container-`<div>`
geschrieben. FullCalendar liest das Array direkt beim Init.

Event-Objekt-Format:
```json
{
  "id": 42,
  "title": "Probe",
  "start": "2026-05-15T19:00:00",
  "end": "2026-05-15T21:00:00",
  "color": "#0d6efd",
  "url": "/events/42"
}
```

`color` wird aus `event.type_color` (Bootstrap-Name) über ein JS-Mapping auf Hex-Werte
abgebildet.

### Termin-Anlage über Kalender

Nur für Admins (`can_manage_users`). Das Template rendert den `dateClick`-Listener
nur, wenn die Session-Variable gesetzt ist.

Ablauf:
1. FullCalendar feuert `dateClick` mit Datum + Uhrzeit.
2. JS befüllt die Felder `starts_at` / `ends_at` im vorhandenen `#addEventModal`.
3. Modal öffnet sich – normaler POST-Flow wie bisher, kein neuer Endpoint.

### Termin-Klick

Klick auf Event im Kalender → `url`-Feld im Event-Objekt → FullCalendar navigiert zu
`/events/{id}`. Kein zusätzlicher JS-Handler nötig.

### Filterkompatibilität

Die bestehenden Filter (Projekt, Typ, vergangene Termine) steuern den PHP-Query, der
sowohl Tabelle als auch Kalender-JSON befüllt. Der `view`-Parameter wird beim
Filter-Submit als Hidden-Input mitgegeben, damit die gewählte Ansicht erhalten bleibt.

## 4. Fehlerbehandlung

| Szenario | Verhalten |
|---|---|
| Kein JavaScript | Kalender-Container bleibt verborgen; nur Listenansicht verfügbar |
| Keine Events | FullCalendar zeigt leeren Kalender, kein Fehler |
| Ungültiger `view`-Parameter | Controller fällt auf `list` zurück |

## 5. Tests

### Feature-Tests (PHPUnit)

- `GET /events?view=calendar` → HTTP 200, Kalender-Container im HTML vorhanden
- `GET /events?view=list` → HTTP 200, Tabelle vorhanden
- `GET /events?view=invalid` → HTTP 200, Listenansicht (Fallback)
- Kalender-Container enthält gültiges JSON mit den Feldern `id`, `title`, `start`, `end`
- Als Nicht-Admin: kein `data-calendar-admin`-Marker im HTML

## 6. Asset-Workflow

1. `npm install @fullcalendar/core @fullcalendar/daygrid @fullcalendar/timegrid @fullcalendar/interaction`
2. `copy-assets.php` um FullCalendar-Bundles erweitern
3. `ddev exec php bin/copy-assets.php` nach Änderung ausführen
4. Einbindung nur auf der Events-Seite (Twig-Block `javascripts` / `stylesheets`)

## 7. Nicht im Scope

- Drag & Drop von Terminen im Kalender
- Inline-Bearbeitung im Kalender
- Ressourcen- oder Timeline-Ansicht (FullCalendar Premium)
- AJAX-Nachladen von Events

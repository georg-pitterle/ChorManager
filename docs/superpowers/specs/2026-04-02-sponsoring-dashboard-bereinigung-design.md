# Sponsoring Dashboard Bereinigung - Design

## Kontext

Das Sponsoring-Dashboard hat aktuell mehrere fachliche und UX-seitige Ungereimtheiten. Besonders sichtbar ist die Spalte "Vereinbarung", die technische IDs statt nutzbarer Information anzeigt. Zusätzlich sind Sortierung, Rücksprung-Verhalten und Datenaufbereitung inkonsistent.

Dieses Design beschreibt eine gezielte, aber umfassende Bereinigung des bestehenden Dashboards in bestehender Architektur.

## Ziele

- Das Dashboard als verlässliche Arbeitsoberfläche für Sponsoring etablieren.
- Die Spalte "Vereinbarung" fachlich korrekt als Paketname + Betrag + Status darstellen.
- Sortierung und Tabellenverhalten konsistent und erwartbar machen.
- Den Nutzerfluss bei Aktion "Erledigt" auf dem Dashboard halten.
- Leere Zustände klar und verständlich darstellen.
- Das bestehende Layout modernisieren, ohne neue Seitenstruktur einzuführen.

## Nicht-Ziele

- Kein kompletter Modul-Neubau.
- Keine Änderungen an Datenbank-Schema oder Migrationen.
- Keine Einführung externer UI-Bibliotheken oder CDN-Assets.

## Architektur und Verantwortlichkeiten

### Controller

Datei: src/Controllers/SponsoringDashboardController.php

Der Controller liefert ein stabiles Dashboard-View-Model statt impliziter Rohobjekt-Darstellung in der View. Dafür werden die bestehenden Relationen genutzt und pro Zeile explizite Anzeige- und Sortfelder aufbereitet.

### Template

Datei: templates/sponsoring/dashboard.twig

Das Template rendert nur noch klar benannte Felder. Komplexe Formatierungs- und Fallback-Logik wird reduziert und auf klaren Ausgabezustand fokussiert.

### Contact-Action

Datei: src/Controllers/SponsoringContactController.php

Die Aktion "markDone" unterstützt den Rücksprung auf das Dashboard, damit der Arbeitskontext erhalten bleibt.

## Informationsarchitektur

1. KPI-Zeile bleibt oberhalb des Arbeitsbereichs.
2. "Wiedervorlagen" ist die priorisierte Hauptsektion.
3. "Letzte Kontakte" ist die sekundäre Kontextsektion.
4. Leere Zustände bleiben sichtbar und erklären den Zustand statt Inhalte komplett auszublenden.

## Datenmodell und Darstellung

### Wiedervorlagen

Pro Zeile werden folgende Felder bereitgestellt:

- follow_up_date_display
- follow_up_date_sort (ISO)
- sponsor_name
- sponsor_url
- agreement_package_name
- agreement_amount_display
- agreement_status_label
- agreement_amount_sort (numerisch)
- owner_name
- owner_name_sort
- is_overdue
- mark_done_url

Vereinbarung wird in einer Zelle als Paketname + Betrag + Status dargestellt.

Fallbacks:

- kein Sponsorship: "-"
- Sponsorship ohne Paket: "Ohne Paket"
- unbekannter Status: "Sonstiges"
- fehlender Nutzer: "-"

### Letzte Kontakte

Pro Zeile werden folgende Felder bereitgestellt:

- contact_date_display
- contact_date_sort (ISO)
- sponsor_name
- sponsor_url
- contact_type_label
- contact_type_sort
- summary
- owner_name
- owner_name_sort

## Query- und Sortierverhalten

### Wiedervorlagen

- Selektionslogik: follow_up_done = 0, follow_up_date gesetzt, follow_up_date <= heute + 7 Tage.
- Standardsortierung: follow_up_date aufsteigend.

### Letzte Kontakte

- Standardsortierung: contact_date absteigend.
- Sekundärsortierung: created_at absteigend für stabile Reihenfolge bei gleichen Kontaktdaten.

## Interaktion und Navigation

- Aktion "Erledigt" aus dem Dashboard führt bei Erfolg zurück auf /sponsoring.
- Bei Fehlern bleibt der Nutzer im Dashboard-Kontext und erhält eine klare Fehlermeldung.

## UI- und UX-Regeln

- Letzte Kontakte standardmäßig in Tabellenansicht statt Kartenansicht.
- Table-Engine Sort-Keys im Header sind mit maschinenlesbaren Sortwerten in den Zeilen konsistent.
- Overdue-Wiedervorlagen bleiben visuell hervorgehoben.
- Responsive Verhalten auf Desktop und Mobile bleibt erhalten.

## Tests

Es werden Feature-Tests ergänzt oder aktualisiert, um folgende Fälle abzudecken:

1. Vereinbarung in Wiedervorlagen zeigt Paketname + Betrag + Status statt Sponsorship-ID.
2. Overdue-Markierung wird für fällige Wiedervorlagen korrekt gesetzt.
3. Mark-Done aktualisiert follow_up_done und leitet auf /sponsoring zurück.
4. Letzte Kontakte sind nach contact_date absteigend geordnet (mit stabilem Fallback).
5. Leere Zustände für Wiedervorlagen und Kontakte werden korrekt angezeigt.

## Qualitäts- und Abnahmekriterien

- Keine technische ID in der Vereinbarungs-Spalte.
- Sortierung in beiden Tabellen ist konsistent nutzbar.
- Dashboard bleibt auf Mobil und Desktop benutzbar.
- Relevante Feature-Tests laufen grün.
- Für substanzielle Twig-Änderungen wird ddev composer twigcs ausgeführt; falls nötig ddev composer twigcbf.

## Risiken und Gegenmaßnahmen

- Risiko: Inkonsistente Sortierung bei gemischten Datentypen.
  Gegenmaßnahme: explizite Sortwerte als Strings oder numerische Felder pro Zeile.
- Risiko: Rücksprunglogik beeinflusst Sponsor-Detail-Workflows.
  Gegenmaßnahme: Redirect-Verhalten nur für Dashboard-Flow anpassen, bestehende Sponsor-Flow-Pfade beibehalten.
- Risiko: Fehlende Relationen erzeugen unklare Anzeige.
  Gegenmaßnahme: feste, getestete Fallbacks pro Feld.

## Umsetzungsumfang

- Anpassung Dashboard-Controller.
- Anpassung Dashboard-Twig.
- Kleine Anpassung Contact-Controller für Redirect-Verhalten.
- Ergänzung/Aktualisierung Feature-Tests für Dashboard- und Mark-Done-Flows.

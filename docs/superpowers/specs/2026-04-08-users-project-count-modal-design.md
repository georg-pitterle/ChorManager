# Design Spec: Users Projekte-Spalte als Anzahl mit Detail-Modal

## Kontext
- Bereich: Benutzerverwaltung in `templates/users/manage.twig` mit Daten aus `App\Controllers\UserController::index`.
- Aktuelles Verhalten: In der Spalte "Projekte" werden Projektnamen direkt als Badges angezeigt.
- Ziel: Statt Namen nur die Anzahl anzeigen, numerisch danach sortieren und beim Klick ein Modal mit allen Teilnahmen anzeigen.

## Ziele
1. In der Projekte-Spalte je Nutzer nur die Anzahl der zugeordneten Projekte anzeigen.
2. Sortierung nach Anzahl muss numerisch korrekt funktionieren.
3. Klick auf die Anzahl öffnet ein Modal mit der vollständigen Teilnahmeliste.
4. In der Modalliste müssen archivierte Projekte sichtbar und klar als archiviert gekennzeichnet sein.
5. Die Anzahl soll für alle sichtbar und klickbar sein, die diese Spalte sehen dürfen.

## Nicht-Ziele
- Kein neuer API-Endpunkt für das Laden von Projektdetails.
- Keine Änderung des bestehenden Projekt-Filters in der Tabelle.
- Keine Änderung an Rollen- oder Berechtigungsmodell außerhalb der bestehenden Sichtbarkeitslogik der Spalte.

## Gewählter Ansatz
Serverseitige Aufbereitung der Projektdaten pro Nutzer und statisches Bootstrap-Modal pro Nutzer in der bestehenden Seite.

Begründung:
- Passt zum vorhandenen Twig/Bootstrap-Muster.
- Kein zusätzlicher Request beim Klick.
- Geringeres Implementierungsrisiko als clientseitige JSON- oder Fetch-Variante.

## Architektur und Komponenten
1. Controller-Datenaufbereitung (`UserController::index`)
- Pro Nutzer zusätzlich setzen:
  - `project_count` als Integer.
  - `project_participations` als Liste strukturierter Einträge:
    - `name` (Projektname)
    - `is_archived` (Bool)
    - `status_label` ("Aktiv" oder "Archiviert")

2. Tabellenrendering (`templates/users/manage.twig`)
- Header der Projekte-Spalte bleibt sortierbar, wird aber auf numerische Sortierung umgestellt:
  - `data-sort-key="project_count"`
  - `data-sort-type="number"`
- Zeilen erhalten numerischen Sortwert:
  - `data-sort-project_count="{{ user.project_count }}"`
- Bestehendes Filterattribut für Projekte (`data-project`) bleibt unverändert für Filter-Kompatibilität.
- Zellinhalt in "Projekte":
  - Anzeige der Anzahl als klickbarer Trigger.
  - Trigger öffnet das nutzerspezifische Modal.

3. Modals (`templates/users/manage.twig`)
- Pro Nutzer ein statisches Modal mit:
  - Titel: Nutzername.
  - Liste aller Projektteilnahmen.
  - Je Eintrag: Projektname und Statusanzeige.
  - Archivierte Projekte mit klarer Kennzeichnung "Archiviert".
- Leerer Zustand:
  - Falls keine Teilnahmen: "Keine Projektteilnahmen vorhanden."

4. JavaScript (`public/js/users.js`)
- Keine neue Datenbeschaffung per JS.
- Modal-Öffnung über bestehende Bootstrap-Mechanik (`data-bs-toggle`, `data-bs-target`).
- Bestehende Bulk-Selection-Logik bleibt unverändert.

## Datenfluss
1. Seite lädt Nutzer wie bisher.
2. Controller berechnet pro Nutzer `project_count` und `project_participations`.
3. Twig rendert:
- Zahlen-Trigger in der Tabelle.
- Modalliste aus serverseitig bereitgestellten Daten.
4. Beim Klick wird das bereits vorhandene Modal im DOM geöffnet.

## Sortier- und Filterverhalten
- Sortierung der Projekte-Spalte erfolgt numerisch über `project_count`.
- Initiale Richtung bei erstem Klick auf den Header: absteigend (gemäß Anforderung).
- Weitere Klicks toggeln aufsteigend/absteigend mit bestehender Table-Engine-Logik.
- Projektfilter bleibt textbasiert auf Projektnamen und funktioniert weiterhin parallel zur Sortierung.

## Fehlerfälle und Randbedingungen
1. Nutzer ohne Projekte
- Tabellenwert: `0`.
- Modal zeigt leeren Zustandstext.

2. Archivierte Projekte
- Werden mitgezählt.
- Werden im Modal explizit als "Archiviert" gekennzeichnet.

3. Berechtigungen
- Die Spalte wird weiterhin nur unter den bestehenden Bedingungen angezeigt.
- Innerhalb dieser Sichtbarkeit ist die Anzahl für alle klickbar.

## Teststrategie
Feature-Arbeiten gelten erst als abgeschlossen, wenn Tests ergänzt/aktualisiert und ausgeführt wurden.

1. `tests/Feature/TableUxFeatureTest.php`
- Erwartet `data-sort-key="project_count" data-sort-type="number"` in der Projekte-Spalte.
- Erwartet `data-sort-project_count` auf Zeilen.
- Erwartet Modal-Trigger-Markup für die Anzahl.

2. `tests/Feature/UserManagementFeatureTest.php`
- Erwartet Modal-Struktur für Projektteilnahmen.
- Erwartet Archiv-Kennzeichnung im Modal.
- Erwartet Leerzustands-Text für Nutzer ohne Teilnahmen.

3. Testausführung
- Relevante Tests werden per DDEV ausgeführt und Ergebnis berichtet.

## Sicherheits- und Qualitätsaspekte
- Keine neuen externen Assets oder CDNs.
- Keine Erweiterung der Angriffsfläche durch neue API-Endpunkte.
- Twig-Ausgabe bleibt escaped.
- Änderungen bleiben auf bestehende Komponenten begrenzt.

## Abnahmekriterien
1. In der User-Tabelle zeigt die Projekte-Spalte nur Zahlen statt Projektbadges.
2. Sortierung nach dieser Spalte ist numerisch korrekt.
3. Klick auf eine Zahl öffnet ein Modal mit allen Projektteilnahmen des Nutzers.
4. Archivierte Projekte sind in der Liste sichtbar und klar markiert.
5. Für Nutzer ohne Projekte zeigt das Modal einen eindeutigen Leerzustand.
6. Relevante Feature-Tests sind aktualisiert und grün.
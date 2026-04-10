# Design Spec: Entfernen der Projekt-Archivstatus-Anzeige in der Benutzerverwaltung

## Kontext
- Bereich: Benutzerverwaltung in `templates/users/manage.twig` mit Daten aus `App\Controllers\UserController::index`.
- Aktuelles Verhalten: Projektteilnahmen im Nutzer-Modal zeigen neben dem Projektnamen einen Statusbadge ("Aktiv"/"Archiviert").
- Ziel: Diese Archivstatus-Anzeige vollständig entfernen, ohne andere Archiv-Funktionen (Mitglieder/Newsletter) zu verändern.

## Ziele
1. Im Projekte-Modal der Benutzerverwaltung wird pro Teilnahme nur noch der Projektname angezeigt.
2. Die serverseitige Ableitung des Projekt-Archivstatus (`is_archived`, `status_label`) entfällt.
3. Die Funktionalität des Modals (Öffnen, Liste, Leerzustand) bleibt erhalten.
4. Andere Archiv-Funktionen im System bleiben unverändert.

## Nicht-Ziele
- Keine Änderungen an Mitglieder-Archivierung in der User-Verwaltung.
- Keine Änderungen am Newsletter-Archiv.
- Keine Änderung am Projektmodul (Projektanlage, Bearbeitung, Mitgliederzuordnung).

## Gewählter Ansatz
Bereinigung von UI und Controller-Datenmodell in einem kleinen, fokussierten Scope.

Begründung:
- Entfernt unnötige Logik statt nur Anzeige zu verstecken.
- Reduziert künftigen Wartungsaufwand durch klare, schlankere Datenstruktur.
- Geringes Risiko, da Datenfluss und Berechtigungslogik erhalten bleiben.

## Architektur und Komponenten
1. Controller-Datenaufbereitung (`UserController::index`)
- `project_participations` bleibt bestehen.
- Jedes Element enthält nur noch:
  - `name` (Projektname)
- Entfernt:
  - `is_archived`
  - `status_label`
  - Hilfsmethode zur Archivbestimmung über `end_date`.

2. Template-Rendering (`templates/users/manage.twig`)
- Projektliste im Modal rendert nur den Projektnamen.
- Statusbadge-Markup und Zugriffe auf `participation.is_archived` / `participation.status_label` werden entfernt.
- Leerer Zustand bleibt unverändert.

3. Tests (`tests/Feature/UserManagementFeatureTest.php`)
- Assertions auf alte Archivstatus-Strings/Strukturen werden entfernt oder angepasst.
- Assertions auf weiterhin vorhandene Projektteilnahme-Daten und Modal-Markup bleiben erhalten.

## Datenfluss
1. Nutzerdaten werden wie bisher geladen.
2. Controller baut pro Nutzer `project_participations` mit Projektnamen auf.
3. Twig rendert die Liste der Namen im bestehenden Modal.

## Fehlerfälle und Randbedingungen
1. Nutzer ohne Projekte
- Verhalten unverändert: bestehender Leerzustand im Modal.

2. Historische Projekte mit abgelaufenem `end_date`
- Werden weiterhin in der Liste gezeigt, aber ohne gesonderte Statuskennzeichnung.

3. Berechtigungen
- Sichtbarkeit der Projekte-Spalte und des Modals bleibt unverändert.

## Teststrategie
Feature-Arbeiten gelten erst als abgeschlossen, wenn Tests ergänzt/aktualisiert und ausgeführt wurden.

1. `tests/Feature/UserManagementFeatureTest.php`
- Erwartet weiterhin Aufbau von `project_participations` im Controller.
- Erwartet Modal-Verwendung von `user.project_participations` im Template.
- Erwartet keine Archivstatus-spezifischen Daten (`status_label`, `is_archived`) mehr.

2. Testausführung
- Relevante Tests werden per DDEV ausgeführt und Ergebnis berichtet.

## Sicherheits- und Qualitätsaspekte
- Keine neuen Endpunkte, keine neuen externen Abhängigkeiten.
- Keine Änderungen an Auth-/Rechteprüfung.
- Ausgabe bleibt über Twig escaped.

## Abnahmekriterien
1. Im Projekte-Modal der Benutzerverwaltung werden nur Projektnamen angezeigt.
2. Es gibt keine Projekt-Statusbadges "Aktiv"/"Archiviert" mehr.
3. Der Controller enthält keine dedizierte Projekt-Archivstatus-Berechnung für diese Darstellung.
4. Relevante Feature-Tests sind aktualisiert und grün.

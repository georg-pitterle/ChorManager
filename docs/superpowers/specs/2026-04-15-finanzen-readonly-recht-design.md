# Design Spec: Finanzberechtigung in Read-Only und Read-Write aufteilen

Datum: 2026-04-15
Status: Abgestimmt

## Ziel

Im Finanzbereich wird das bisherige kombinierte Recht in zwei Modi aufgeteilt:

- Finanzen nur lesen
- Finanzen lesen und schreiben

Nutzer im Nur-Lesen-Modus sehen weiterhin alle Finanzinformationen, koennen jedoch nichts erstellen, bearbeiten oder loeschen.

## Umfang

### Im Scope

- Neues Rollenrecht `can_read_finances` einfuehren.
- Bestehendes Rollenrecht `can_manage_finances` als Schreiben (mit implizitem Lesen) beibehalten.
- Finanzrouten in Read- und Write-Aktionen mit passender Autorisierung absichern.
- Rollenverwaltung (UI + Verarbeitung) um zwei getrennte Finanzrechte erweitern.
- Session-Rechteaggregation um `can_read_finances` erweitern.
- Nur-Lesen-UI im Finanzmodul so anpassen, dass Schreibaktionen nicht verfuegbar sind.
- Tests fuer Rechte-Mapping, Session-Aggregation und Routing-Verhalten erweitern.

### Out of Scope

- Änderungen an Berechtigungen ausserhalb des Finanzbereichs.
- Generelle Neugestaltung des Rollen- oder Rechtesystems.

## Berechtigungsmodell

### Rechte

- `can_read_finances`: erlaubt Lesezugriff auf Finanzen.
- `can_manage_finances`: erlaubt Schreibzugriff auf Finanzen.

### Effektive Modikombinationen

- Kein Zugriff: `can_read_finances = 0`, `can_manage_finances = 0`
- Nur lesen: `can_read_finances = 1`, `can_manage_finances = 0`
- Lesen und schreiben: `can_read_finances = 1`, `can_manage_finances = 1`

### Invarianten

- Schreibzugriff impliziert Lesezugriff.
- Inkonsistente Kombination (`can_manage_finances = 1`, `can_read_finances = 0`) wird in Verarbeitung und UI nicht zugelassen.

## Technisches Design

### Datenmodell

- Schema-Erweiterung der Tabelle `roles` um Spalte `can_read_finances` (tinyint(1), default 0).
- Anpassung der Role-Model-Fillables und Rollen-Create/Update-Logik.
- Dev-Seed-Definitionen um `can_read_finances` erweitern:
  - Rollen mit Finanzschreibrecht erhalten auch Leserecht.
  - Reine Lesefaelle koennen explizit gesetzt werden.

### Session-Aggregation

- `SessionAuthService` berechnet zusaetzlich `$_SESSION['can_read_finances']`.
- Aggregation ueber alle Nutzerrollen (OR-Logik).
- Wenn `can_manage_finances` aktiv ist, wird `can_read_finances` effektiv ebenfalls aktiv gesetzt.
- Bestehende Admin-Abkuerzungen bleiben erhalten; globale Verwaltungsrechte blockieren keine Finanzsicht.

### Routing und Middleware

- Read-Endpunkte brauchen Finanz-Leserecht:
  - `GET /finances`
  - `GET /finances/report`
  - `GET /finances/attachments/{id}`
- Write-Endpunkte brauchen Finanz-Schreibrecht:
  - `POST /finances/save`
  - `POST /finances/{id}/delete`
  - `POST /finances/settings`
  - `POST /finances/attachments/{id}/delete`

Autorisierungsvorgaben:

- Nicht autorisierte Write-Aufrufe liefern `403 Forbidden`.
- Kein Redirect fuer verbotene Schreibaktionen.

### UI-Verhalten im Finanzmodul

Im Nur-Lesen-Modus:

- Sichtbar und nutzbar:
  - Finanzliste inklusive Jahrwahl und Filterfunktionen
  - Auswertung
  - Anhaenge oeffnen/herunterladen
- Nicht verfuegbar:
  - Neuer Eintrag
  - Bearbeiten
  - Loeschen (Eintraege/Anhaenge)
  - Speichern von Konfigurationen

Die UI blendet Schreibaktionen aus oder deaktiviert sie klar, ersetzt aber keine serverseitige Autorisierung.

### Rollenverwaltung (UI + Verarbeitung)

- Neue Bezeichnungen in der Rollenmaske:
  - "Finanzen nur lesen"
  - "Finanzen lesen und schreiben"
- Formularlogik erzwingt die Invariante:
  - Aktiviertes Schreibrecht setzt Leserecht implizit.
  - Ohne Leserecht kann Schreibrecht nicht aktiv bleiben.

## Datenfluss

1. Rollen werden in der Rollenverwaltung mit Lese-/Schreibrecht fuer Finanzen gepflegt.
2. Beim Login werden Rechte in Session-Flags aggregiert.
3. Middleware prueft pro Route das benoetigte Finanzrecht.
4. Controller/Twig nutzen Session-Flags fuer read-only UI-Steuerung.
5. Write-Aktionen bleiben serverseitig durch Route/Middleware abgesichert.

## Fehlerbehandlung und Sicherheit

- Schreibzugriffe ohne Recht werden mit `403` abgewiesen.
- UI-Restriktionen gelten als Komfort, nicht als Sicherheitsgrenze.
- Direkte POST-Aufrufe, manipulierte Formulare und manuelle Requests werden weiterhin korrekt blockiert.
- Kein Speichern von sensiblen Zusatzdaten oder Geheimnissen.

## Teststrategie

### Feature-Tests (Pflicht)

- Rollen-Rechte-Mapping:
  - neues Flag `can_read_finances` wird korrekt aus Formularen gemappt.
- Session-Aggregation:
  - `can_read_finances` wird aus Rollen korrekt gesetzt.
  - `can_manage_finances` impliziert effektives Lesen.
- Routing/Autorisierung:
  - Read-Endpunkte erreichbar mit Read-Only-Recht.
  - Write-Endpunkte geben `403` mit Read-Only-Recht.
  - Write-Endpunkte erlaubt mit Read+Write-Recht.

### UI-nahe Regressionstests (string/structure-basiert wie bestehend)

- Finanztemplate enthaelt bedingte Darstellung der Schreibaktionen in Abhaengigkeit der Rechte.
- Rollen-Template enthaelt beide Finanzrechte mit korrekter Bezeichnung.

## Abnahmekriterien

- Ein Nutzer mit nur `can_read_finances` kann alle Finanzinformationen sehen, aber keine Schreibaktion ausfuehren.
- Ein Nutzer mit `can_manage_finances` kann weiterhin lesen und schreiben.
- Ein Nutzer ohne Finanzrechte kann keine Finanzseiten aufrufen.
- Direkte Schreibrequests ohne Schreibrecht liefern `403`.
- Alle aktualisierten relevanten Tests laufen erfolgreich.

## Rollout-Hinweise

- Migration ausfuehren, damit bestehende Rollen initial `can_read_finances = 0` erhalten.
- Bestehende Rollen mit `can_manage_finances = 1` sollten in der Migrationslogik oder per Nachlaufskript auf `can_read_finances = 1` gesetzt werden, um bestehendes Verhalten nicht unbeabsichtigt einzuschraenken.
- Danach reguliere Rechte fein granular ueber die Rollenverwaltung.
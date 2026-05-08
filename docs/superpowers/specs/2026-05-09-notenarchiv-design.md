# Design: Notenarchiv als Repertoire-Erweiterung

**Datum:** 2026-05-09  
**Status:** Freigegeben

---

## 1. Zielbild

Das Notenarchiv erweitert das bestehende Repertoire um bibliothekarische Archivinformationen, ohne Kernfelder des Repertoires zu duplizieren.

Kernprinzipien:
- Repertoire bleibt die führende Quelle für Liedstammdaten.
- Das Archiv speichert nur ergänzende Felder und Bestandsinformationen.
- Die Gesamtanzahl wird immer automatisch aus Einzelstimmen/Kategorien berechnet.

---

## 2. Fachlicher Scope

### 2.1 Bestehende Repertoire-Kernfelder
Titel, Komponist, Arrangeur und weitere bestehende Liedstammdaten bleiben unverändert im Repertoire.

### 2.2 Archiv-Ergänzungsfelder je Lied
- Archivnummer
- Standort
- Einzelstimmen-/Kategorien-Bestände mit Anzahl
- Berechnete Gesamtanzahl (read-only)

### 2.3 Stimmen/Kategorien
- Typische Kategorien wie Sopran, Alt, Tenor, Bass, Partitur und Bläserstimmen werden unterstützt.
- Zusätzlich sind freie Kategorien erlaubt.

---

## 3. UX-Design für Bestände

### 3.1 In-place erweiterbares Dropdown
Die Erfassung der Stimmen erfolgt pro Zeile über ein creatable Dropdown:
- Vorschläge zeigen bereits bekannte Kategorien.
- Neue Kategorien können direkt im Dropdown eingegeben und ausgewählt werden.
- Kategorien sind nicht auf einen festen Katalog begrenzt.

### 3.2 Erfassungszeilen
Pro Zeile werden erfasst:
- Kategorie/Stimmentyp
- Anzahl

Mehrere Zeilen sind möglich, Zeilen können hinzugefügt und entfernt werden.

### 3.3 Gesamtanzahl
Die Gesamtanzahl ist nicht editierbar und wird live berechnet:

$$
\text{Gesamtanzahl} = \sum \text{Anzahl je Kategorie}
$$

Die Summe wird bei jeder Änderung sofort aktualisiert.

---

## 4. Modulschalter über .env

### 4.1 Feature-Flag
Neues Flag:
- `FEATURE_SHEET_ARCHIVE=true|false`

Das Flag wird zentral über die bestehende Settings/Config-Struktur bereitgestellt.

### 4.2 Verhalten bei Deaktivierung
Wenn `FEATURE_SHEET_ARCHIVE=false` gilt:
- kein Archivbereich im UI
- keine Archiv-bezogenen Navigationseinträge
- keine Archiv-Routen
- keine Archiv-Endpunkte

Das Modul ist damit vollständig unsichtbar und technisch nicht erreichbar.

---

## 5. Rechtekonzept

Neues dediziertes Recht:
- `can_manage_sheet_archive`

Regeln:
- Modul aktiv + Recht vorhanden: Archiv nutzbar
- Modul aktiv + Recht fehlt: Archiv nicht sichtbar/nicht nutzbar
- Modul deaktiviert: Archiv grundsätzlich nicht verfügbar, unabhängig vom Recht

---

## 6. Technisches Design

### 6.1 Architekturgrenzen
- Repertoire-Modul bleibt Owner der Liedstammdaten.
- Notenarchiv wird als separates Submodul mit klarer Verantwortlichkeit umgesetzt.
- Trennung nach Schichten:
  - Controller: HTTP, Rechte, ViewModel
  - Service: Validierung, Summenbildung, Duplikatbehandlung
  - Persistence: Speichern/Laden von Archivkopf und Stimmenpositionen

### 6.2 Persistenzmodell
Für ein Lied wird ein Archiv-Datensatz mit ergänzenden Feldern geführt. Stimmenbestände werden als zugeordnete Positionen je Kategorie gespeichert.

Empfohlene Regeln:
- Anzahl als Integer >= 0
- Leere Kategorien werden verworfen
- Doppelte Kategorien je Lied werden beim Speichern zusammengeführt (Mengen werden addiert)

### 6.3 Transaktionsgrenze
Speichern von Archivkopf und Stimmenpositionen erfolgt atomar (Transaktion), um Teilpersistenz zu verhindern.

---

## 7. Sicherheit und Fehlverhalten

- Serverseitige Gates: Feature-Flag und Recht
- Serverseitige Eingabevalidierung für alle Archivfelder
- Template-Ausgaben werden escaped
- Kein Zugriff auf deaktivierte Archivrouten
- Bei Validierungsfehlern:
  - Rückgabe mit Feldfehlermeldungen
  - Formulardaten bleiben erhalten

---

## 8. Teststrategie

### 8.1 Feature-Tests
- Modul deaktiviert: Archiv nicht sichtbar, keine Archivrouten
- Modul aktiv + ohne Recht: kein Zugriff
- Modul aktiv + mit Recht: Archivdaten können erfasst und geändert werden
- Neue Kategorie per Freitext im Dropdown wird gespeichert
- Gesamtanzahl entspricht der Summe aller Kategorien
- Doppelte Kategorie in einem Speichervorgang wird zusammengeführt

### 8.2 Edge-Tests
- Anzahl 0 ist zulässig
- Negative oder nicht-numerische Werte werden abgewiesen
- Leere Kategorie wird abgewiesen
- Entfernen aller Zeilen ergibt Gesamtanzahl 0

---

## 9. Nicht-Ziele

- Keine Änderung bestehender Repertoire-Kernfelder
- Kein globaler Pflichtkatalog für Stimmenkategorien in Version 1
- Keine zusätzlichen Auswertungs-/Reporting-Funktionen im ersten Schritt

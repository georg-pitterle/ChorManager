# Design: Liedbibliothek-Links und Download-Anzeige

## Ziel

In der Liedbibliothek soll es moeglich sein, pro Lied mehrere externe Links zu hinterlegen. Diese Links sollen nicht nur in der Verwaltungsansicht sichtbar und pflegbar sein, sondern auch im Download-Bereich fuer Projektmitglieder als klickbare Eintraege erscheinen.

## Ergebnisrahmen

- Mehrere eigenstaendige Links pro Lied
- Pro Link: Titel, URL, Beschreibung
- Erlaubte URL-Schemas: `http`, `https`
- Links sind hinzufuegbar, bearbeitbar und loeschbar
- Im Download-Bereich erscheinen Links in einem separaten Bereich unter der bestehenden Dateitabelle
- Die Beschreibung wird dort direkt unter dem Linktitel angezeigt
- Klicks auf Links oeffnen das Ziel direkt in einem neuen Tab
- Sortierung erfolgt standardmaessig alphabetisch nach Titel

## Architektur

Fuer Lieder wird eine allgemeine Ressourcen-Ebene eingefuehrt, die sowohl bestehende Dateien als auch neue externe Links fachlich unter dem Lied buendelt. Ressourcen haben mindestens die Typen `file` und `link`.

Der Scope bleibt bewusst auf die Liedbibliothek begrenzt. Es wird keine moduluebergreifende Ressourcenplattform gebaut. Die Verallgemeinerung dient nur dazu, Dateien und Links innerhalb eines Liedes sauber unter einer gemeinsamen Fachlogik abzubilden.

Die bestehende Datei-Funktionalitaet im Repertoire und im Download-Bereich bleibt erhalten. Fuer die erste Ausbaustufe werden externe Links als neue Ressourcenart ergaenzt, ohne bestehende Download- und Streaming-Pfade fuer Dateien zu ersetzen.

## Datenmodell

Es wird eine Lied-Ressourcen-Struktur eingefuehrt, die mindestens folgende gemeinsame Felder vorsieht:

- `song_id`
- `resource_type`
- `title`
- `description`
- Zeitfelder fuer Nachvollziehbarkeit

Fuer Ressourcen vom Typ `link` kommt mindestens hinzu:

- `url`

Fuer Ressourcen vom Typ `file` bleiben dateispezifische Informationen erforderlich, zum Beispiel:

- Anzeigename oder Originalname
- MIME-Type
- Groesse
- Dateiinhalt oder bestehender Dateispeicherbezug

Die Standardsortierung fuer Links erfolgt alphabetisch nach `title`.

## Fachliche Regeln

- `title` ist fuer Links ein Pflichtfeld.
- `url` ist fuer Links ein Pflichtfeld.
- Es sind nur `http`- und `https`-URLs erlaubt.
- `description` ist optional, wird aber in Bibliothek und Download-Bereich angezeigt, wenn vorhanden.
- Links erhalten keinen Download-Endpunkt.
- Externe Ziele werden nicht serverseitig auf Erreichbarkeit vorab geprueft.

## Liedbibliothek

In der Lied-Detailansicht wird der bestehende Bereich fuer Dateien um einen eigenen Bereich fuer Link-Ressourcen ergaenzt.

Dieser Bereich erlaubt berechtigten Benutzern:

- Link anlegen
- Link bearbeiten
- Link loeschen

Jeder Link besteht aus Titel, URL und Beschreibung. Die Eingaben werden serverseitig validiert. Ungueltige Eingaben fuehren zu einer klaren Fehlermeldung auf derselben Liedseite.

Die bestehende Dateiverwaltung bleibt in Verhalten und Struktur unveraendert.

## Download-Bereich

Die bestehende Dateitabelle pro Lied bleibt unveraendert bestehen.

Unterhalb dieser Tabelle wird ein separater Bereich fuer Links gerendert. Dieser Bereich:

- listet alle Links des Liedes alphabetisch nach Titel
- zeigt den Titel als klickbaren Link
- oeffnet das Ziel in einem neuen Tab
- zeigt die Beschreibung direkt unter dem Titel

Wenn ein Lied keine Links besitzt, wird ein knapper Leerzustand angezeigt, damit die neue Faehigkeit im Download-Bereich sichtbar bleibt.

## Datenfluss

Beim Laden der Liedbibliothek werden Lieddaten, Dateianhaenge und Link-Ressourcen gemeinsam fuer die Detaildarstellung bereitgestellt.

Beim Laden des Download-Bereichs werden fuer die dem Benutzer zugeordneten Projekte weiterhin die zugewiesenen Lieder und deren Dateien geladen. Zusaetzlich werden die Link-Ressourcen pro Lied geladen und unterhalb der Dateitabelle dargestellt.

Dateien behalten ihre bisherigen Download- und Streaming-Pfade. Links werden direkt als externe Ziele gerendert.

## Fehlerbehandlung

- Leerer Titel bei einem Link fuehrt zu einer Validierungsfehlermeldung.
- Fehlende oder ungueltige URL fuehrt zu einer Validierungsfehlermeldung.
- Nicht erlaubte Schemas fuehren zu einer Validierungsfehlermeldung.
- Nicht gefundene Link-Ressourcen beim Bearbeiten oder Loeschen verhalten sich konsistent zur bestehenden Liedbibliothek: Redirect mit Fehlermeldung.
- Es gibt kein stilles Scheitern bei fehlgeschlagenen Aenderungen.

## Sicherheit

- Es werden nur `http`- und `https`-URLs akzeptiert.
- Externe Links werden nur als normale HTML-Links gerendert, nicht ueber einen serverseitigen Redirect- oder Proxy-Endpunkt.
- Die Ausgabe in Twig bleibt escaped; nur das `href`-Attribut erhaelt den validierten URL-Wert.
- Links, die in einem neuen Tab geoeffnet werden, sollen mit passenden Schutzattributen wie `rel` fuer externe Navigation gerendert werden.

## Tests

Die Umsetzung ist erst vollstaendig, wenn automatisierte Tests mindestens folgende Faelle abdecken:

- Strukturtest fuer die neue Lied-Ressourcen- oder Link-Logik
- Validierung erlaubter Schemas (`http`, `https`)
- Ablehnung ungueltiger oder unerwuenschter Schemas
- Anzeige der Links in der Liedbibliothek
- Anzeige der Links im Download-Bereich unterhalb der Dateitabelle
- Bearbeiten bestehender Links
- Loeschen bestehender Links
- Absicherung, dass bestehende Datei-Downloads und Streaming-Pfade unveraendert bleiben

## Nicht-Ziele

- Keine allgemeine Ressourcenverwaltung fuer andere Module
- Kein serverseitiger Link-Health-Check
- Keine Vereinheitlichung aller bestehenden Datei-Anhaenge im gesamten System in dieser Aenderung
- Keine neue Sortierlogik ausser der alphabetischen Standardsortierung nach Titel

## Offene Umsetzungsleitlinie

Die Architektur ist absichtlich allgemein genug fuer Lied-Ressourcen, die konkrete erste Fachfunktion ist aber ausschliesslich die Pflege und Anzeige externer Links in der Liedbibliothek und im Download-Bereich.
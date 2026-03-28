# UI Modernization Design

## Ziel

Die Anwendung soll ein moderneres, klareres und produktiveres Erscheinungsbild erhalten, ohne ihre bestehende Bedienlogik grundlegend zu verändern. Die Oberfläche soll übersichtlich bleiben, sich weniger nach Standard-Bootstrap anfühlen und gleichzeitig nicht an eine einzelne Marke gebunden sein. Die Standard-Farbwelt bleibt gelb, die Primärfarbe soll jedoch einfach konfigurierbar werden.

## Umfang

Die erste Phase umfasst:

- die globale App-Shell für eingeloggte Nutzer
- das Dashboard
- Listen- und Verwaltungsseiten
- Formulare und Detailseiten in den Bereichen wie Termine, Benutzer, Rollen und Finanzen
- Auth-Seiten wie Login, Setup und Passwort-Reset

Nicht Teil dieser Phase sind:

- eine neue Frontend-Architektur
- ein vollständiges Design-Token-System mit beliebig vielen Themes
- eine Umstellung auf Sidebar-Navigation

## Ausgangslage

Die aktuelle Oberfläche basiert auf Bootstrap mit einer topbasierten Navigation in [templates/layout.twig](templates/layout.twig), einer gelb-anthrazitfarbenen Grundgestaltung in [public/css/style.css](public/css/style.css) und seitenweise unterschiedlich ausgeprägten Layoutmustern. Das Dashboard in [templates/dashboard/index.twig](templates/dashboard/index.twig) nutzt einzelne Karten als Einstieg. Seiten wie [templates/events/index.twig](templates/events/index.twig) kombinieren Filter, Tabellen und Modals bereits funktional, aber noch ohne einheitliches Layoutsystem. App-Konfiguration ist über `app_settings` bereits global in Twig verfügbar, siehe [src/Dependencies.php](src/Dependencies.php), und kann über [templates/settings/index.twig](templates/settings/index.twig) erweitert werden.

## Leitprinzipien

- Orientierung vor Dekoration
- bestehende Navigationslogik erhalten, visuell und strukturell aber verbessern
- konsistente Seitenmuster statt individueller Einzellösungen
- produktive Verwaltungsoberfläche statt marketingartiger Präsentationsfläche
- mobile und Desktop-Nutzung gleichwertig behandeln
- standardmäßig gelbes Theme, aber ohne harte Markenbindung

## Gewählter Ansatz

Es wird ein strukturierter Admin-Refresh umgesetzt.

Dieser Ansatz modernisiert die bestehende Topbar-Architektur, führt ein konsistentes Seitenlayout ein und vereinheitlicht Dashboard, Listen, Tabellen, Formulare und Statusdarstellungen. Die bestehende Twig- und Bootstrap-Basis bleibt erhalten, damit das Redesign kontrolliert und inkrementell umgesetzt werden kann.

Verworfene Alternativen:

- rein kosmetischer Refresh: zu geringer Nutzen für Orientierung und Arbeitsabläufe
- starkes Rebranding: höheres Risiko, die produktive Verwaltungsnutzung visuell zu überladen

## Informationsarchitektur

### App-Shell

Die bisherige Top-Navigation in [templates/layout.twig](templates/layout.twig) bleibt das Grundmodell. Sie wird zu einer ruhigeren, moderneren App-Bar weiterentwickelt.

Zielstruktur der oberen Leiste:

- linker Bereich: Logo und App-Name
- mittlerer Bereich: Hauptnavigation mit klarerer visueller Gewichtung aktiver Bereiche
- rechter Bereich: Nutzerzugriff, Profil und Logout

Dropdowns bleiben erhalten, werden aber weniger wie Standard-Bootstrap-Menüs inszeniert. Die erste Navigationsebene soll schneller scanbar sein und weniger dicht wirken.

### Seitenkopf

Jede eingeloggte Seite erhält einen einheitlichen Seitenkopf direkt unter der Topbar. Dieser enthält je nach Seite:

- Titel
- kurze Kontextbeschreibung
- Primäraktion
- optionale Sekundäraktionen
- optionalen Filter- oder Statuskontext

Dadurch entsteht eine konsistente Shell für Dashboard, Listen, Formulare und Detailseiten.

## Komponentenmodell

### Dashboard

Das Dashboard in [templates/dashboard/index.twig](templates/dashboard/index.twig) wird von einzelnen, gleichartigen Karten zu einem Arbeitsbereich weiterentwickelt.

Zielbild:

- Begrüßung mit klarer Priorisierung statt generischem Einstieg
- 1 bis 3 sichtbare Hauptaktionen je nach Rolle
- modulare Bereiche für anstehende Termine, Aufgaben, Schnellzugriffe oder Auswertungen
- jede Kachel oder jedes Modul führt zu einer konkreten nächsten Handlung

Das Dashboard soll nicht dekorativer, sondern nützlicher werden.

### Listen- und Verwaltungsseiten

Seiten wie [templates/events/index.twig](templates/events/index.twig) werden auf ein festes Muster gebracht:

- Seitenkopf
- Aktionsleiste
- Filter- oder Suchbereich
- Ergebnisbereich
- definierte Empty States und Statushinweise

Tabellen werden ruhiger und klarer gestaltet:

- bessere Lesbarkeit von Zeilen und Spalten
- klarere Trennung zwischen Daten und Aktionen
- definierte Platzierung für Primäraktionen und Zeilenaktionen
- bessere visuelle Zustände für leer, gefiltert und erfolgreich aktualisiert

Vorhandene Inline-Stile oder lokale Sonderbehandlungen sollen nach Möglichkeit in zentrale Styles überführt werden, damit keine Seite eigene Layoutregeln mitführt.

### Formulare und Detailseiten

Formulare erhalten eine einheitliche Struktur mit:

- logisch gruppierten Abschnitten
- klarer Label-Hierarchie
- sichtbaren Hilfetexten
- konsistenten Pflichtfeld- und Fehlermarkierungen
- stabiler Aktionszone für Speichern, Abbrechen und Sekundäraktionen

Statt vieler gleich gewichteter Eingaben soll klarer erkennbar sein, was wichtig, optional oder folgenreich ist.

### Auth-Seiten

Die Auth-Templates in [templates/auth/login.twig](templates/auth/login.twig), [templates/auth/forgot_password.twig](templates/auth/forgot_password.twig), [templates/auth/reset_password.twig](templates/auth/reset_password.twig) und [templates/auth/setup.twig](templates/auth/setup.twig) werden an die neue Designsprache angebunden.

Ziel:

- gleiche visuelle Familie wie der eingeloggte Bereich
- ruhigeres, hochwertigeres Einstiegsgefühl
- klare Formularführung ohne dekorativen Überschuss

## Visuelles System

### Grundcharakter

Die Oberfläche soll sachlich, modern und ruhig wirken, aber mehr Identität besitzen als eine Standard-Admin-Oberfläche. Die visuelle Sprache setzt auf:

- helle, saubere Flächen
- feinere Trennungen statt harter Blockkontraste
- mehr räumliche Ordnung durch Abstände, Gruppierung und Hierarchie
- zurückhaltende Tiefe über Schatten, Layer und Akzentflächen

### Farbe

Die gelbe Standardrichtung aus [public/css/style.css](public/css/style.css) bleibt der Default. Gleichzeitig wird die Oberfläche so entkoppelt, dass sie nicht mehr direkt an eine spezifische Markenfarbe gebunden ist.

Umfang des Theming-Systems:

- einfache Konfiguration der Primärfarbe
- Ableitung zentraler UI-Akzente aus dieser Primärfarbe
- neutraler Unterbau für Flächen, Typografie und Trennlinien

Nicht vorgesehen ist ein frei parametrierbares Voll-Theming mit separater Konfiguration für alle Status- und Flächenfarben.

### Konfigurationsanbindung

Die Primärfarbe soll an den bestehenden App-Settings-Pfad anschließen, da `app_settings` bereits global verfügbar ist und die Einstellungen in [templates/settings/index.twig](templates/settings/index.twig) bearbeitet werden.

Daraus folgt für die spätere Implementierung:

- Erweiterung der App-Einstellungen um eine Primärfarbe
- sichere Validierung des Farbwerts
- Ausgabe der konfigurierten Primärfarbe in die globale Shell
- Fallback auf das bestehende gelbe Standardtheme

## Responsives Verhalten

- Desktop bleibt topbar-orientiert
- Mobile nutzt weiterhin ein topbasiertes Navigationsmodell mit sauberem Collapse- oder Offcanvas-Verhalten
- Seitenkopf, Filterleisten, Tabellenaktionen und Formularaktionen müssen auch auf kleinen Breiten stabil und verständlich bleiben
- responsive Tabellenlösungen bleiben erlaubt, sollen aber stärker in das zentrale UI-Muster eingebunden werden

## Zustände und Fehlerszenarien

Statusdarstellungen werden vereinheitlicht. Dazu gehören:

- Erfolgsmeldungen
- Fehlermeldungen
- Hinweise
- leere Ergebnisse
- gesperrte oder nicht verfügbare Inhalte
- Formularvalidierung

Diese Zustände sollen nicht länger wie zufällige Bootstrap-Standardbausteine wirken, sondern zentral definierte Bestandteile des UI-Systems sein. Platzierung, Gewichtung, Abstände und mobile Darstellung müssen seitenübergreifend konsistent sein.

## Technische Leitplanken

- Twig bleibt das Render-Modell
- Bootstrap bleibt Grundlage, wird aber stärker über zentrale Styles und Komponentenregeln überformt
- keine Inline-CSS-Neulösungen in Templates
- keine Inline-JavaScript-Neulösungen in Templates
- Änderungen sollen sich bevorzugt in zentralen Layout- und CSS-Dateien bündeln und von dort in die Templates ausstrahlen

## Rollout-Empfehlung

Die Umsetzung soll in kontrollierten Schritten erfolgen:

1. globale Shell und Design-Basis modernisieren
2. Dashboard umstellen
3. Listen- und Tabellenmuster für zentrale Verwaltungsseiten vereinheitlichen
4. Formulare und Detailseiten angleichen
5. Auth-Seiten an die neue Designsprache anbinden
6. Primärfarben-Konfiguration in App-Einstellungen ergänzen

Diese Reihenfolge minimiert visuelle Brüche und erlaubt wiederverwendbare Muster, bevor breite Template-Anpassungen beginnen.

## Tests und Validierung

Die Qualitätssicherung erfolgt über drei Ebenen:

1. Bestehende Feature-Tests bleiben als strukturelles Sicherheitsnetz erhalten.
2. Relevante Tests werden ergänzt, wenn Template-Struktur oder Settings-Verhalten gezielt abgesichert werden müssen.
3. Manuelle Sichtprüfung der Kernseiten auf Desktop und Mobile ist verpflichtend, insbesondere für Topbar, Seitenkopf, Tabellen, Filterleisten, Formulare und Auth-Seiten.

Da das Redesign primär die Präsentationsschicht betrifft, ist das Ziel keine neue Frontend-Testinfrastruktur, sondern kontrollierte Regressionserkennung plus Sichtprüfung der Kernflows.

## Erfolgskriterien

Das Redesign ist erfolgreich, wenn:

- die Anwendung moderner wirkt, ohne ihre Bedienlogik unnötig zu verändern
- Navigation und Seitenaufbau schneller erfassbar sind
- Listen, Tabellen und Formulare produktiver nutzbar sind
- Auth- und Verwaltungsbereiche visuell konsistent wirken
- die Primärfarbe konfigurierbar ist und gelb der sichere Standard bleibt
- zentrale Templates und Styles ein wiederverwendbares UI-System bilden statt Einzellösungen zu fördern
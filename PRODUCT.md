# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

Installierbar als PWA (`public/manifest.webmanifest`, `display: standalone`); Telefon, Tablet und Laptop
nutzen dieselbe Weboberfläche.

## Users

Alle vier Gruppen nutzen die App regelmäßig, jede in einer anderen Lage:

- **Vorstand und Kassa** – verwalten am Laptop, in längeren Sitzungen: Mitglieder, Finanzen, Budget,
  Sponsoring, Einstellungen – und vor allem **Newsletter schreiben**: Inhalt im Editor, Vorlagen,
  Platzhalter, Zielgruppe, Vorschau und Versand.
- **Chorleitung** – oft in der Probe, nebenbei, am Handy oder Tablet: Anwesenheit, Repertoire,
  Termine.
- **Stimmvertretungen** – kümmern sich um ihre Stimmgruppe: Anwesenheit und Anmeldungen der eigenen
  Leute.
- **Mitglieder** – kurz, mobil und eher selten: Termine, Zu- und Absagen, Noten, Downloads – und
  **Newsletter lesen**, per E-Mail und im eigenen Archiv in der App (`/newsletters/archive`).

Fast alle Funktionsträger arbeiten ehrenamtlich und nebenbei. Viele Mitglieder haben wenig
Technikerfahrung oder öffnen die App nur gelegentlich.

Die Rollen sind pro Installation frei konfigurierbar; die Gruppen oben beschreiben typische Aufgaben,
keine festen Rollennamen.

## Product Purpose

ChorManager ist die Verwaltungsplattform für Chöre: Mitglieder, Rollen und Rechte, Termine,
Anwesenheit, Projekte und Aufgaben, Repertoire und Notenarchiv, Dateien, Kassa und Budget, Newsletter,
Sponsoring und Auswertungen in einer Anwendung.

Der Newsletter ist eine der wichtigsten Funktionen: Er ist der Hauptkanal vom Chor zu seinen
Mitgliedern. Schreiben muss für Ehrenamtliche leicht gehen, Lesen muss auf jedem Gerät und in jedem
Mailprogramm funktionieren.

Erfolg heißt: Ein Chor organisiert sich mit diesem einen Werkzeug, und Ehrenamtliche erledigen ihre
Aufgaben schnell, ohne Schulung und ohne zwischen Programmen zu wechseln.

## Positioning

- **Alles in einem** statt mehrerer Einzelwerkzeuge für Termine, Kasse, Mails und Dateien.
- **Datenhoheit:** jeder Chor hat seine eigene Installation; die Daten liegen nicht bei einem großen
  Plattformanbieter (EU, DSGVO).
- **Open Source und günstig:** MIT-Lizenz, kein teures Abo.
- **Modular über Features:** jeder Chor schaltet nur die Bereiche ein, die er braucht (Kassa, Budget,
  Sponsoring, Newsletter, Anmeldungen, Aufgaben, Notenarchiv, Webmail). Ein kleiner Chor bekommt ein
  schlankes Werkzeug, ein großer Verein das volle Programm – aus derselben Anwendung.

ChorManager wird als Angebot für andere Chöre betrieben: Installationen werden für mehrere Chöre
bereitgestellt, jede eigenständig.

## Operating Context

- Probenalltag: Anwesenheit und Repertoire werden während oder direkt nach der Probe gepflegt, am
  Handy oder Tablet.
- Vereinsverwaltung: Kassabuch, Kontoauszug-Import, Budget, Sponsoring und Newsletter am Schreibtisch.
- Kommunikation: Newsletter (Editor mit Vorlagen und Platzhaltern, Zielgruppen, Versand über die
  Mail-Queue, Archiv für Mitglieder; die Mail selbst wird mit Inline-Styles für Mailprogramme gebaut),
  Benachrichtigungen per E-Mail und Glocke, Kalender-Abonnement (iCal), optional eingebettete Webmail.
- Dateien: Downloads und Notenordner, Office-Dokumente im Browser (Collabora), WebDAV-Zugang für Noten.
- Optionale Module werden pro Installation über Feature-Flags (`FEATURE_*` in `.env`) zugeschaltet;
  Budget setzt Kassa voraus. Die Oberfläche muss mit jeder Kombination vollständig wirken: Navigation,
  Dashboard, Suche und Hilfe zeigen nur, was aktiv ist, ohne Lücken oder tote Verweise.

## Capabilities and Constraints

- Sichtbarkeit von Modulen und Menüpunkten folgt den Rechten der Rolle; Rollen sind frei konfigurierbar.
- Pro Installation einstellbar: App-Name, Logo, Primärfarbe. Diese Einstellbarkeit muss erhalten
  bleiben.
- Sprache: nur Deutsch, österreichische Begriffe (z. B. „Kassa“); keine weiteren Sprachen geplant.
- Alle Frontend-Bibliotheken werden lokal ausgeliefert; keine CDNs oder externen Laufzeit-Ressourcen.
- Server-gerenderte Twig-Seiten mit Bootstrap 5; kein Inline-JavaScript, kein Inline-CSS in Templates.
- Hilfe unter `/help` (Quellen in `help/`) verweist auf Rechte, nie auf konkrete Rollennamen.

## Brand Commitments

- **White Label:** Wenn ein Chor die App öffnet, steht der Chor im Vordergrund – sein Name, sein Logo,
  seine Farbe. ChorManager selbst tritt kaum in Erscheinung.
- Die Oberfläche muss deshalb mit beliebigen Vereinsfarben und Logos funktionieren, auch mit hellen
  Farben, ohne an Lesbarkeit zu verlieren.

## Evidence on Hand

- Hilfethemen mit Screenshots unter `help/` (Termine, Mitglieder, Projekte, Repertoire, Finanzen,
  Newsletter, Sponsoring, Rollen, Navigation u. a.).
- Dev-Seed-Daten (`bin/dev_seed.php`) mit Demo-Zugängen je typischer Rolle, nur für Entwicklung.
- Keine Referenzkunden, Testimonials, Nutzerzahlen oder Preise vorhanden; solche Angaben dürfen nicht
  erfunden werden.

## Product Principles

1. **Ohne Erklärung bedienbar.** Wer die App alle paar Wochen öffnet, findet sich trotzdem sofort
   zurecht.
2. **Ehrenamt-Tempo.** Häufige Aufgaben – Anwesenheit, Zu- und Absage, Buchung – brauchen so wenige
   Schritte wie möglich.
3. **Der Chor ist die Marke.** Gestaltung trägt jede Vereinsfarbe und jedes Logo; nichts darf an einer
   bestimmten Farbe hängen.
4. **Ein Werkzeug, modular zugeschnitten.** Neue Funktionen fügen sich in bestehende Bereiche ein oder
   kommen als eigenes Feature-Modul, das sich sauber ab- und zuschalten lässt – nie als Insel, die
   ausgeschaltet Spuren hinterlässt.
5. **Daten bleiben beim Chor.** Keine externen Dienste zur Laufzeit, keine Daten bei Dritten.

## Accessibility & Inclusion

- Zielgruppe mit wenig Technikerfahrung und viel mobiler Nutzung: große, klar beschriftete
  Bedienelemente und gut lesbarer Text sind Pflicht.
- **Barrierefreiheit hat niedrige Priorität.** Chorsänger sind fast nie eingeschränkt (Entscheidung
  des Betreibers, Oktober 2026). Barrierefreiheit ist kein Kriterium für Design-Entscheidungen, Audits
  und Reviews; keine zusätzlichen Maßnahmen oder Aufwand dafür vorschlagen, und Gestaltung nicht
  zugunsten von Screenreader-, Tastatur- oder WCAG-Anforderungen verbiegen. Bedienbarkeit für
  Ungeübte bleibt davon unberührt (siehe oben).
- Eine formale WCAG-Stufe ist nicht festgelegt und nicht geplant. Bereits Umgesetztes (Kontrast 4,5:1,
  Feldbeschriftungen, Touch-Ziele aus dem UI-Audit vom Oktober 2026, abgesichert in
  `tests/Feature/UiAccessibilityFeatureTest.php`) bleibt bestehen, wird aber nicht weiter ausgebaut.

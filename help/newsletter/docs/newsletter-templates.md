# Newsletter-Vorlagen verwalten

Vorlagen sind fertige Bausteine für wiederkehrende Rundschreiben – etwa eine Konzertankündigung, ein Nachbericht oder das monatliche Standard-Rundschreiben. Eine Vorlage hält nicht nur den Inhalt fest, sondern auch die Newsletter-Einstellungen: Kontext, Titelvorschlag und Empfängerquellen. Beim Anlegen eines Newsletters lädst du eine Vorlage und passt nur noch die konkreten Angaben an.

> **Berechtigung:** Vorlagen verwalten darf nur, wessen Rolle das Recht **"Newsletter verwalten"** hat. Fehlt dir der Zugang, frag den Administrator nach diesem Recht.

## 1. Vorlagenübersicht öffnen

Klicke auf **Bereiche → Newsletter** und dort oben rechts auf **Vorlagen verwalten**.

![Vorlagenübersicht](images/newsletter/08-templates.png)

Die Spalte **Kontext** ordnet jede Vorlage ein:

- **Global** – nicht an ein Projekt gebunden.
- **Projekt** – inhaltlich zu einem bestimmten Projekt gehörig.

Der Kontext ist eine Einordnung, keine Sperre: Mit dem Recht **„Newsletter verwalten"** kannst du jede Vorlage öffnen, bearbeiten, klonen und in jedem Newsletter laden.

## 2. Vorlage erstellen

Klicke auf **Vorlage erstellen** und fülle den Dialog aus.

![Dialog "Neue Vorlage erstellen"](images/newsletter/09-template-create-modal.png)

- **Name** – unter diesem Namen erscheint die Vorlage später in der Auswahl "Vorlage laden". Pflichtfeld.
- **Kontext** – **Global** für alle Projekte, oder ein einzelnes Projekt.
- **Titelvorschlag** – optionaler Titel, den ein Newsletter aus dieser Vorlage bekommt. Bleibt das Feld leer, wird beim Laden der Vorlagenname als Titel eingesetzt.
- **Beschreibung** – optionale interne Notiz, wofür die Vorlage gedacht ist.
- **Inhalt** – der Vorlagentext im Editor. Pflichtfeld.

Nach dem Speichern landest du direkt im Bearbeiten-Dialog der neuen Vorlage.

Ein Tipp für Platzhalter: Schreib Stellen, die pro Newsletter wechseln, gut erkennbar in den Text (zum Beispiel `[Datum]`, `[Uhrzeit]`, `[Ort]`). Sie werden nicht automatisch ersetzt, sind so aber beim Ausfüllen nicht zu übersehen.

## 3. Vorlage bearbeiten oder klonen

In der Übersicht öffnest du eine Vorlage über **Bearbeiten**. Neben Name, Beschreibung und Inhalt änderst du dort auch die Newsletter-Einstellungen:

- **Kontext** – Global oder ein einzelnes Projekt.
- **Titelvorschlag** – der Titel, den ein Newsletter aus dieser Vorlage erhält.
- **Empfängerquellen** – Projektmitglieder, Veranstaltungsteilnehmer, Rollen und einzelne Mitglieder. Genau diese Auswahl steht später im neuen Newsletter.

![Dialog "Vorlage bearbeiten"](images/newsletter/10-template-edit-modal.png)

Über den kleinen Pfeil neben **Bearbeiten** erreichst du **Klonen**: Damit entsteht eine Kopie der Vorlage, die du unabhängig vom Original weiterbearbeiten kannst. Das ist der bequemste Weg für Varianten – etwa eine Ankündigung für Proben und eine für Konzerte.

## 4. Vorlage aus einem Newsletter erzeugen

Hat sich ein bereits geschriebener Newsletter bewährt, musst du ihn nicht abtippen: Öffne den Entwurf über **Bearbeiten** und klicke unten auf **Als Vorlage speichern**. Nach Eingabe von Name und Beschreibung entsteht daraus eine Vorlage im Kontext des Projekts, zu dem der Newsletter gehört. Gehört der Newsletter zu keinem Projekt, entsteht eine globale Vorlage.

Übernommen werden dabei auch die Einstellungen des Newsletters: sein Titel wird zum Titelvorschlag der Vorlage, seine Empfängerquellen werden mitgespeichert. Der Stand beim Speichern zählt – ungespeicherte Änderungen am Entwurf landen nicht in der Vorlage.

## 5. Vorlage verwenden

Beim Anlegen eines Newsletters wählst du sie unter **Vorlage laden (optional)** aus. Übernommen werden Inhalt, Titel, Kontext und Empfängerquellen – alles bleibt im Formular frei bearbeitbar. Die Vorlage **ersetzt** dabei eine bereits getroffene Auswahl: Wähle sie also zuerst und stelle erst danach den Verteiler fein ein. Was die Vorlage offenlässt, bleibt unangetastet – eine globale Vorlage ändert dein gewähltes Projekt nicht, und eine Vorlage ohne Empfängerquellen lässt deinen Verteiler stehen. Angeboten werden alle Vorlagen, im Auswahlfeld nach Kontext gruppiert – zuerst die globalen, danach die Vorlagen je Projekt. Näheres dazu unter [Newsletter schreiben und versenden](newsletter-compose).

## Häufige Stolperfallen

- **Änderungen wirken nicht rückwirkend**: Eine Vorlage wird beim Laden einmalig in den Newsletter kopiert. Bearbeitest du die Vorlage später, ändern sich bereits erstellte Newsletter nicht.
- **Die Vorlage überschreibt den Verteiler**: Hat die Vorlage eigene Empfängerquellen, ersetzen sie deine bisherige Auswahl vollständig. Prüfe die Empfängerzahl nach dem Laden.
- **Gelöschte Bezüge fallen weg**: Verweist eine Vorlage auf ein gelöschtes Projekt, eine gelöschte Veranstaltung oder eine gelöschte Rolle, wird diese Quelle beim Laden stillschweigend übersprungen.
- **Vorlagen lassen sich nicht löschen**: In der Verwaltung gibt es nur Bearbeiten und Klonen. Wird eine Vorlage nicht mehr gebraucht, benenne sie entsprechend um (zum Beispiel mit dem Zusatz "veraltet") oder überschreibe ihren Inhalt.

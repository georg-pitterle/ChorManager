# Mitgliederverwaltung

In der Mitgliederverwaltung pflegst du alle Personen des Chors: Stammdaten, Rollen,
Stimmgruppen und Projektzuordnungen. Von hier aus lädst du neue Mitglieder per E-Mail
ein und archivierst Personen, die den Chor verlassen haben.

> **Berechtigung:** Der Menüpunkt erscheint, wenn deine Rolle das Recht
> **"Mitgliederverwaltung erlauben"** oder das Recht **"Eigene Stimmgruppe verwalten"** hat.
>
> - Mit **"Mitgliederverwaltung erlauben"** siehst und bearbeitest du alle Mitglieder,
>   das Archiv und die Einladungs-E-Mails.
> - Mit **"Eigene Stimmgruppe verwalten"** siehst du nur die Mitglieder deiner eigenen
>   Stimmgruppen. Rollen kannst du dort nur unterhalb deiner eigenen Rollenstufe vergeben,
>   und das Archiv bleibt zu.
> - Die **E-Mail-Adresse** eines Mitglieds ändert nur, wer zusätzlich das Recht
>   **"Mitglieder editieren erlauben"** hat.
>
> Siehst du den Menüpunkt nicht, frag den Administrator unter **Verwaltung → Rollen**.

## 1. Mitgliederübersicht

Klickpfad: **Bereiche → Mitgliederverwaltung**.

Die Liste zeigt Name, E-Mail-Adresse, Rolle, Stimme (mit Teilstimme in Klammern) und die
Anzahl der Projekte. Über die Tabellenleiste durchsuchst, filterst und sortierst du die
Liste: **Suche**, die Auswahlfelder **Rolle**, **Stimme** und **Projekt**, **Sortierung**,
die Seitengröße und die Ansicht (**Auto**, **Karten**, **Tabelle**). **Zurücksetzen**
räumt alle Filter wieder weg.

![Mitgliederübersicht mit Tabellenleiste](images/members/01-list.png)

Ein gesetzter Filter bleibt erhalten, bis du ihn zurücksetzt — auch nach einem
Seitenwechsel. Die Zeile über der Tabelle nennt immer die Anzahl der Einträge im
aktuellen Ergebnis.

![Nach Stimme gefilterte Mitgliederliste](images/members/02-filter.png)

## 2. Nach Stimme gruppieren

**Nach Stimme gruppieren** stellt die Liste auf Stimmgruppen um. Jede Gruppe zeigt die
Anzahl ihrer Mitglieder und lässt sich aufklappen; Stimmgruppen mit Teilstimmen
(z. B. Sopran 1 und Sopran 2) bekommen eine zweite Ebene. Mitglieder ohne Stimmgruppe
sammelt der Eintrag **Ohne Zuordnung**. **Listenansicht** schaltet zurück.

![Mitglieder nach Stimmgruppen gruppiert](images/members/03-grouped.png)

## 3. Neues Mitglied anlegen

**Mitglied hinzufügen** öffnet das Formular.

![Formular "Neues Mitglied anlegen"](images/members/04-new-member.png)

- **Vorname**, **Nachname** und **E-Mail-Adresse** sind Pflicht. Die Adresse muss gültig
  und im Chor noch nicht vergeben sein.
- Mindestens eine **Rolle** ist Pflicht. Mehrfachauswahl ist erlaubt.
- **Stimmgruppen** sind optional; hakst du eine an, kannst du darunter die **Teilstimme**
  wählen.
- **Speichern** legt das Mitglied an, ohne dass es etwas erfährt.
- **Speichern und Einladungs-E-Mail senden** legt es an und schickt sofort den
  Einladungslink, über den sich die Person ihr eigenes Passwort setzt.

Du vergibst nie selbst ein Passwort — das Konto bekommt seines erst über die Einladung
oder über "Passwort vergessen".

## 4. Mitglied bearbeiten

**Bearbeiten** in der Zeile öffnet dasselbe Formular mit den bestehenden Daten, ergänzt um
die **Projekte** des Mitglieds.

![Formular "Mitglied bearbeiten"](images/members/05-edit-member.png)

- **Einladungs-E-Mail** unten links schickt den Einladungslink erneut — nützlich, wenn die
  erste Mail nie ankam oder der Link abgelaufen ist. Die Schaltfläche steht nur mit dem
  Recht "Mitgliederverwaltung erlauben" zur Verfügung.
- Mitglieder mit einer **höheren Rollenstufe** als deiner eigenen lassen sich nicht
  bearbeiten; bei ihnen fehlt die Schaltfläche.
- Verwaltest du nur Projektmitglieder, öffnet dasselbe Formular als
  **Projektzuordnung bearbeiten** — dann sind nur die Projekt-Häkchen änderbar.

## 5. Projektteilnahmen ansehen

Die Zahl in der Spalte **Projekte** öffnet die Liste der Projekte, in denen das Mitglied
mitsingt. Das ist der schnelle Blick, ohne das Bearbeiten-Formular zu öffnen.

![Projektteilnahmen eines Mitglieds](images/members/06-projects.png)

## 6. Mitglied archivieren

Wer den Chor verlässt, wird **archiviert** (deaktiviert), nicht gelöscht — so bleiben
Anwesenheiten, Beiträge und Buchungen der Vergangenheit nachvollziehbar.

- Einzeln: **Deaktivieren** im Aufklappmenü neben **Bearbeiten**.
- Mehrere auf einmal: Zeilen links ankreuzen, dann **Auswahl archivieren**.

Zwei Sperren greifen dabei:

- Den **eigenen Account** kannst du nicht archivieren.
- Das **letzte Mitglied mit dem Recht "Mitgliederverwaltung erlauben"** bleibt aktiv,
  sonst käme niemand mehr in diesen Bereich. Vergib das Recht vorher an jemand anderen.

## 7. Archiv und Wiederherstellen

**Archivierte anzeigen** wechselt in die Liste der archivierten Mitglieder;
**Aktive anzeigen** führt zurück. Das Archiv ist nur mit dem Recht
"Mitgliederverwaltung erlauben" sichtbar.

![Archivierte Mitglieder mit Schaltfläche "Wiederherstellen"](images/members/07-archive.png)

**Wiederherstellen** setzt ein Mitglied wieder aktiv. Erst danach kann es sich wieder
anmelden und erst danach lässt sich ihm eine Einladung schicken.

## Häufige Stolperfallen

- **"Die Liste ist leer."** Prüf die Filter in der Tabellenleiste und klick auf
  **Zurücksetzen** — ein Filter überlebt den Seitenwechsel. Verwaltest du nur deine
  eigene Stimmgruppe und hast selbst keine zugeordnet, bleibt die Liste ebenfalls leer.
- **E-Mail-Feld lässt sich nicht ändern.** Dafür braucht deine Rolle zusätzlich
  **"Mitglieder editieren erlauben"**.
- **Einladung an ein archiviertes Mitglied.** Der Versand wird abgewiesen. Erst
  wiederherstellen, dann einladen.
- **Adresse schon vergeben.** Jede E-Mail-Adresse darf es nur einmal geben — meist ist die
  Person bereits angelegt und nur archiviert.
- **Rollenliste kürzer als erwartet.** Ohne "Mitgliederverwaltung erlauben" siehst du nur
  Rollen unterhalb deiner eigenen Stufe.

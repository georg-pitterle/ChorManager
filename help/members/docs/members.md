# Mitgliederverwaltung

In der Mitgliederverwaltung pflegst du alle Personen des Chors: Stammdaten, Rollen,
Stimmgruppen und Projektzuordnungen. Von hier aus lädst du neue Mitglieder per E-Mail
ein und archivierst Personen, die den Chor verlassen haben.

> **Berechtigung:** Der Menüpunkt erscheint, wenn deine Rolle das Recht
> **"Mitgliederverwaltung erlauben"** oder das Recht **"Eigene Stimmgruppe verwalten"** hat.
>
> - Mit **"Mitgliederverwaltung erlauben"** siehst du alle Mitglieder, das Archiv und die
>   Einladungsfunktionen (Einladung senden, Einladungsstand, Filter **Einladung**).
> - Mit **"Eigene Stimmgruppe verwalten"** siehst du nur die Mitglieder deiner eigenen
>   Stimmgruppen. Rollen kannst du dort nur unterhalb deiner eigenen Rollenstufe vergeben,
>   das Archiv und die Einladungsfunktionen bleiben zu.
> - Der Knopf **Bearbeiten** und die **E-Mail-Adresse** eines Mitglieds hängen bei
>   **"Mitgliederverwaltung erlauben"** zusätzlich am Recht **"Mitglieder editieren erlauben"**.
>
> Siehst du den Menüpunkt nicht, frag den Administrator unter **Administration → Rollen & Rechte**.

## 1. Mitgliederübersicht

Klickpfad: **Mitglieder & Projekte → Mitglieder**.

Oben rechts im Seitenkopf stehen drei Knöpfe: **Hilfe** öffnet diese Seite, **Archiv**
wechselt zu den archivierten Mitgliedern (nur mit dem Recht "Mitgliederverwaltung erlauben")
und **Mitglied hinzufügen** öffnet das Formular für eine neue Person.

Darunter liegt die Tabellenleiste. In der ersten Zeile stehen links die **Suche** und das
Menü **Ansicht**, rechts der Zähler: "81 Mitglieder", bei gesetztem Filter "25 von 81
Mitgliedern" mit dem Link **Filter zurücksetzen**. In der zweiten Zeile folgen die Filter
**Rolle**, **Stimme**, **Projekt** und **Einladung** (gesendet / nicht gesendet / offen). Den
Filter **Einladung** gibt es nur mit dem Recht "Mitgliederverwaltung erlauben" und nicht im
Archiv. Die Auswahl steht in der gewohnten Reihenfolge: Stimmen von Sopran bis Bass, Rollen
von der einfachen Mitgliedschaft aufwärts.

![Mitgliederübersicht mit Seitenkopf, Tabellenleiste und Filtern](images/members/01-list.png)

Jede Zeile zeigt:

- **Stimme** als genaueste Angabe: die Teilstimme (z. B. "Tenor 2"), sonst die Stimmgruppe.
  Wer keiner Stimmgruppe zugeordnet ist, bekommt "Keine Stimme".
- **Rollen** als Schlagwort unter dem Namen, aber nur, wenn es nicht die einfache Grundrolle
  ist. Als Grundrolle gilt die Rolle auf der niedrigsten Stufe, solange nur eine Rolle auf
  dieser Stufe liegt. Bei einfachen Mitgliedern steht deshalb kein Schlagwort.
- **Laufendes Projekt**: **dabei**, wenn die Person in allen laufenden Projekten mitsingt,
  sonst gelb **nicht dabei** (bei mehreren laufenden Projekten steht stattdessen das, in dem
  sie mitsingt). Alle früheren Projekte stehen im Bearbeiten-Formular. Die Spalte gibt es mit
  den Rechten "Mitglieder editieren erlauben", "Eigene Stimmgruppe verwalten" oder
  "Projektmitglieder verwalten", nur wenn gerade ein Projekt läuft, und nicht im Archiv.
- Unter dem Namen kann **Einladung offen** stehen. Das heißt: Die Einladung wurde verschickt,
  und seitdem hat sich die Person nicht angemeldet. Der Hinweis erscheint nur mit dem Recht
  "Mitgliederverwaltung erlauben".

Der Name ist ein Link, der das Bearbeiten-Formular öffnet (siehe Abschnitt 5), wenn du das
Mitglied bearbeiten darfst.

Die Filter bleiben im Browser gemerkt, bis du sie zurücksetzt — auch nach einem Seitenwechsel.

![Nach Stimme gefilterte Mitgliederliste mit Zähler "25 von 81 Mitgliedern"](images/members/02-filter.png)

Am Telefon stehen die Filter hinter dem Knopf **Filter**; sind Filter aktiv, zeigt er ihre
Anzahl, z. B. "Filter (2)". Die Liste erscheint dort automatisch als Karten.

![Filter am Telefon](images/members/11-mobile-filters.png)

## 2. Menü "Ansicht"

Alles, was nur die Darstellung ändert und nicht, wer in der Liste steht, liegt im Menü
**Ansicht**:

- **Darstellung**: **Auto** wählt je nach Bildschirmbreite, **Karten** und **Tabelle**
  erzwingen eine der beiden Formen.
- **Sortierung**: Die Liste ist zunächst nach Name sortiert. Weitere Spalten lassen sich
  hinzufügen, **Sortierung zurücksetzen** räumt auf.
- **Pro Seite**: 25, 50, 100 oder 200 Einträge.
- **Zurücksetzen** setzt Suche, Filter, Sortierung und Ansicht auf den Ausgangszustand.

![Das Menü "Ansicht"](images/members/03-view-menu.png)

## 3. Nach Stimme gruppieren

Der Umschalter **Liste | Nach Stimme** neben **Ansicht** stellt die Liste auf Stimmgruppen um.
Jede Gruppe zeigt die Anzahl ihrer Mitglieder und ist zunächst offen; zuklappen lässt sie sich
per Klick auf ihren Namen. Die Ansicht merkt sich, was du zugeklappt hast; Stimmgruppen mit
Teilstimmen (z. B. Sopran 1 und Sopran 2) bekommen eine zweite Ebene, Mitglieder ohne
Teilstimme stehen dort unter **Ohne Unterstimme**. Mitglieder ohne Stimmgruppe sammelt der
Eintrag **Ohne Zuordnung**. **Liste** schaltet zurück. Im Archiv
gibt es die Gruppierung nicht.

![Mitglieder nach Stimmgruppen gruppiert](images/members/04-grouped.png)

## 4. Mehrere Mitglieder auswählen

Kreuze links in der Liste Zeilen an. Sobald mindestens eine Zeile gewählt ist, erscheint
die **Auswahlleiste**; sie läuft beim Scrollen mit, sodass die Knöpfe immer erreichbar
bleiben. Das Kästchen im Tabellenkopf wählt alle Treffer des aktuellen Filters, auch die
auf anderen Seiten. Die Leiste nennt dann, wie viele davon auf anderen Seiten liegen.

![Auswahlleiste mit drei gewählten Mitgliedern](images/members/05-selection.png)

Die Leiste enthält:

- den Zähler ("3 ausgewählt") und **Auswahl aufheben**,
- **Einladung senden** (nur mit dem Recht "Mitgliederverwaltung erlauben"),
- **Archivieren**.

Beide Aktionen fragen vorher nach und sagen dabei, was passieren wird: wie viele Mitglieder
es tatsächlich trifft, wie viele übersprungen werden und wie viele schon eine Einladung
bekommen haben (deren alter Link verliert dann seine Gültigkeit). Trifft eine Aktion
niemanden – etwa **Einladung senden**, wenn alle Ausgewählten die App schon nutzen –, ist ihr
Knopf gesperrt. Dein **eigenes Konto** lässt sich nicht ankreuzen.

Nicht jedes gewählte Mitglied lässt sich bearbeiten. Solche Mitglieder werden übersprungen,
und die Rückmeldung nennt sie mit Namen und Grund, z. B. "Übersprungen: Anna Muster (hält
noch eine erweiterte Rolle)". Bei mehr als fünf Fällen folgen die ersten fünf, dann
"und N weitere". Mögliche Gründe:

- beim Archivieren: "keine Berechtigung", "letzte Person mit Mitgliederverwaltung" und
  "hält noch eine erweiterte Rolle",
- beim Einladen: "nutzt die App schon" (hat sich seit der letzten Einladung angemeldet),
  "archiviert", "keine Berechtigung" und "Versand fehlgeschlagen".

## 5. Neues Mitglied anlegen und bearbeiten

### Neues Mitglied anlegen

**Mitglied hinzufügen** öffnet das Formular.

![Formular "Neues Mitglied anlegen"](images/members/07-new-member.png)

- **Vorname**, **Nachname** und **E-Mail-Adresse** sind Pflicht. Die Adresse muss gültig
  und im Chor noch nicht vergeben sein.
- Mindestens eine **Rolle** ist Pflicht. Die Grundrolle ist vorausgewählt, sofern eindeutig
  feststeht, welche das ist (siehe Abschnitt 1). Alle Rollen mit zusätzlichen Rechten stehen
  unter **Weitere Rollen** — ein bewusster Klick, damit niemand versehentlich mehr Rechte
  vergibt.
- **Stimmgruppen** sind optional; hakst du eine an, kannst du darunter die **Teilstimme**
  wählen. Mit dem Recht "Eigene Stimmgruppe verwalten" (ohne "Mitgliederverwaltung erlauben")
  musst du mindestens eine deiner eigenen Stimmgruppen zuweisen.
- **Projekte**: Laufende Projekte kannst du gleich ankreuzen. Das geht mit den Rechten
  "Mitglieder editieren erlauben" oder "Projektmitglieder verwalten".
- **Abbrechen** schließt das Formular.
- **Nur speichern** legt das Mitglied an, ohne dass es etwas erfährt.
- **Speichern und einladen** (Hauptaktion) legt es an und schickt sofort den Einladungslink,
  über den sich die Person ihr eigenes Passwort setzt. Senden lässt sich die Einladung auch
  später noch.
- Einladen darf nur, wer das Recht "Mitgliederverwaltung erlauben" hat. Ohne dieses Recht
  gibt es im Formular nur **Speichern**.

Du vergibst nie selbst ein Passwort — das Konto bekommt seines erst über die Einladung
oder über "Passwort vergessen".

### Mitglied bearbeiten

**Bearbeiten** in der Zeile öffnet dasselbe Formular mit den bestehenden Daten; der Titel
nennt die Person. Die Stimmgruppen stehen als flache Liste, die Teilstimme direkt darunter.
**Weitere Rollen** ist aufgeklappt, sobald das Mitglied eine davon hat.

![Formular "Mitglied bearbeiten" mit Projekten und aufgeklappter Kontoaktivität](images/members/08-edit-member.png)

- **Projekte**: Laufende und kommende Projekte stehen direkt zur Auswahl. Abgelaufene Projekte
  sind unter **Frühere Projekte (N)** eingeklappt, werden aber beim Speichern mitgeschickt.
  Den Abschnitt gibt es mit den Rechten "Mitglieder editieren erlauben",
  "Eigene Stimmgruppe verwalten" oder "Projektmitglieder verwalten".
- **Kontoaktivität** (zugeklappter Abschnitt, nur mit dem Recht "Mitgliederverwaltung
  erlauben") zeigt **Letzte Anmeldung** und **Einladung gesendet**. Hier steht auch der Knopf
  **Einladung senden**, bei bereits verschickter Einladung **Einladung erneut senden** —
  nützlich, wenn die erste Mail nie ankam oder der Link abgelaufen ist. Eine erneute
  Einladung fragt vorher nach. Der Knopf steht bewusst nicht im Dialogfuß, damit ein
  Fehlklick neben **Abbrechen** keine Mail auslöst.
- Mitglieder mit einer **höheren Rollenstufe** als deiner eigenen und archivierte Mitglieder
  lassen sich nicht bearbeiten; bei ihnen fehlt der Knopf.
- Verwaltest du nur Projektmitglieder (Recht "Projektmitglieder verwalten"), öffnet dasselbe
  Formular als **Projektzuordnung bearbeiten** — dann sind nur die Projekt-Häkchen änderbar.
- Die **E-Mail-Adresse** ist nur mit dem Recht "Mitglieder editieren erlauben" änderbar,
  sonst ist das Feld gesperrt.

## 6. Mitglied archivieren

Wer den Chor verlässt, wird **archiviert**, nicht gelöscht — so bleiben Anwesenheiten,
Beiträge und Buchungen der Vergangenheit nachvollziehbar. Archivierte Mitglieder können sich
nicht anmelden.

- Einzeln: **Archivieren** im Menü hinter dem Pfeil neben **Bearbeiten**. Die Rückfrage nennt
  den Namen.
- Mehrere auf einmal: Zeilen ankreuzen, dann **Archivieren** in der Auswahlleiste
  (Abschnitt 4).

Ist **Archivieren** im Menü grau, steht der Grund direkt darunter, und bei fremden Konten
öffnet **Rollen bearbeiten …** das Bearbeiten-Formular, um die Rollen abzunehmen.

Mit dem Recht "Mitgliederverwaltung erlauben" steht im selben Menü auch **Einladung senden**
(bzw. **Einladung erneut senden**), ohne das Formular zu öffnen. Eine erste Einladung geht
sofort hinaus. Eine erneute fragt vorher nach, weil der Link aus der früheren Einladung damit
seine Gültigkeit verliert.

![Zeilenmenü mit gesperrtem "Archivieren", Grund und "Rollen bearbeiten …"](images/members/06-row-menu.png)

Diese Sperren greifen:

- Dein **eigenes Konto** kannst du nicht archivieren.
- Ein Mitglied, das noch eine Rolle über der einfachen Grundrolle hält, muss erst alle
  erweiterten Rollen abgeben ("Erst alle Rollen bis auf die einfache Mitgliedschaft
  abnehmen").
- Die **letzte Person mit dem Recht "Mitgliederverwaltung erlauben"** bleibt aktiv, sonst
  käme niemand mehr in diesen Bereich. Vergib das Recht vorher an jemand anderen. Diese
  Sperre zeigt die Liste nicht vorab; du bekommst die Meldung beim Klick.
- Mitglieder mit höherer Rollenstufe als deiner eigenen kannst du nicht archivieren.

## 7. Archiv und Wiederherstellen

**Archiv** (im Seitenkopf, nur mit dem Recht "Mitgliederverwaltung erlauben") öffnet die
Seite **Archivierte Mitglieder**. Eine Erklärung steht direkt unter der Überschrift;
**Zurück zu den Mitgliedern** führt zur aktiven Liste. Im Archiv gibt es keine Projekte-Spalte,
keine Auswahlkästchen und keinen Filter **Einladung**.

![Archivierte Mitglieder mit Schaltfläche "Wiederherstellen"](images/members/10-archive.png)

**Wiederherstellen** setzt ein Mitglied wieder aktiv; vorher fragt das Programm nach. Erst
danach kann sich die Person wieder anmelden, und erst danach lässt sich ihr eine Einladung
schicken.

## Häufige Stolperfallen

- **"Die Liste ist leer."** Prüf die Filter in der Tabellenleiste und klick auf
  **Filter zurücksetzen** oder im Menü **Ansicht** auf **Zurücksetzen** — ein Filter überlebt
  den Seitenwechsel. Verwaltest du nur deine eigene Stimmgruppe und hast selbst keine
  zugeordnet, bleibt die Liste ebenfalls leer.
- **"Letzte Anmeldung: nicht erfasst" heißt nicht "nie angemeldet".** Anmeldungen werden erst
  seit Oktober 2026 erfasst. Bei Mitgliedern, die sich davor zuletzt angemeldet haben, bleibt
  das Feld leer. Aus demselben Grund steht **Einladung offen** nur bei Einladungen, die nach
  Beginn der Erfassung verschickt wurden; ältere gelten nie als offen.
- **E-Mail-Feld lässt sich nicht ändern.** Dafür braucht deine Rolle das Recht
  **"Mitglieder editieren erlauben"**.
- **Kein Bearbeiten-Knopf in der Zeile.** Mit dem Recht "Mitgliederverwaltung erlauben" allein
  gibt es in der Zeile nur **Archivieren**; **Bearbeiten** kommt mit **"Mitglieder editieren
  erlauben"**. Bei Mitgliedern mit höherer Rollenstufe fehlt er immer.
- **Archivieren ist grau.** Der Grund steht im Menü darunter, meist hält das Mitglied noch
  eine erweiterte Rolle. Über **Rollen bearbeiten …** nimmst du sie ab.
- **Einladung an ein archiviertes Mitglied.** Der Versand wird abgewiesen. Erst
  wiederherstellen, dann einladen.
- **Adresse schon vergeben.** Jede E-Mail-Adresse darf es nur einmal geben — meist ist die
  Person bereits angelegt und nur archiviert.
- **Rollenliste kürzer als erwartet.** Ohne "Mitgliederverwaltung erlauben" siehst du nur
  Rollen unterhalb deiner eigenen Stufe.

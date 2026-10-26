# Stimmgruppen-Listen

In den Stimmgruppen-Listen hakst du für deine Stimmgruppe ab, wer etwas erledigt hat, und
hältst Notizen zu jedem Mitglied fest. Du formulierst die Fragen selbst, zum Beispiel
"Beitrag bezahlt", "Noten erhalten" oder eine Notiz "Kleidergröße". Jede Liste hat eine Zeile
je Mitglied und eine Spalte je Frage. Die Mitglieder selbst sehen diese Listen nicht.

> **Berechtigung:** Der Menüpunkt erscheint, wenn deine Rolle das Recht
> **"Stimmgruppen-Listen führen"** hat. Zusätzlich musst du selbst Mitglied der Stimmgruppe
> sein, zu der eine Liste gehört: Du siehst und bearbeitest nur die Listen deiner eigenen
> Stimmgruppe. Kein anderes Recht öffnet fremde Listen, auch nicht die Rechte zur
> Mitglieder- oder Stimmgruppenverwaltung. Eine Liste einer anderen Stimmgruppe meldet
> "Liste nicht gefunden". Lesen und Bearbeiten sind dasselbe Recht, ein reines Leserecht
> gibt es nicht. Siehst du den Menüpunkt nicht, frag den Administrator unter
> **Administration → Rollen & Rechte**.

Die Liste gehört der Stimmgruppe, nicht der einzelnen Person: Wer das Recht hat und in der
Stimmgruppe ist, arbeitet in denselben Listen, und eine Nachfolge findet alles vor.

## 1. Übersicht

Klickpfad: **Mitglieder & Projekte → Stimmgruppen-Listen**.

Die Übersicht zeigt alle aktiven Listen deiner Stimmgruppe, sortiert nach Titel. Unter dem
Titel steht, ob die Liste zu einem Projekt gehört ("Projekt: …", bei gleichem Namen wie der
Titel "Liste zum Projekt") oder "Ohne Projekt". Rechts steht der Stand der **ersten
Häkchen-Spalte**, zum Beispiel "Beitrag bezahlt 12 / 24". Sind alle erledigt, steht dort
"alle erledigt"; eine Liste ohne Häkchen-Spalte zeigt "Noch keine Häkchen-Spalte". Ein Klick
auf die Zeile öffnet die Liste.

Archivierte Listen stehen darunter in einem eingeklappten Bereich **Archiviert**. Gehörst du
mehreren Stimmgruppen an, gibt es je Stimmgruppe einen eigenen Abschnitt.

![Übersicht mit aktiven und archivierten Listen](images/voice-group-lists/01-overview.png)

Im Seitenkopf stehen **Hilfe** und **Neue Liste**.

## 2. Neue Liste anlegen

**Neue Liste** öffnet einen Dialog:

- **Titel** ist Pflicht.
- **Projekt** ist vorausgewählt mit dem laufenden Projekt. Du kannst ein anderes Projekt
  oder **Ohne Projekt** wählen. Zur Auswahl stehen Projekte, in denen mindestens ein
  Mitglied deiner Stimmgruppe mitwirkt.
- Gehörst du mehreren Stimmgruppen an, fragt der Dialog zusätzlich nach der
  **Stimmgruppe**.

![Dialog "Neue Liste" mit vorausgewähltem Projekt](images/voice-group-lists/02-new-list-modal.png)

Mit **Anlegen** öffnet sich die neue, noch leere Liste. Der Hinweis im Dialog sagt, wer in
der Liste steht: mit Projekt nur die Projektmitglieder der Stimmgruppe, ohne Projekt alle
Mitglieder der Stimmgruppe (siehe Abschnitt 6).

## 3. Die Listenseite

Unter dem Titel steht das **Projekt** (oder "Ohne Projekt – alle Mitglieder der
Stimmgruppe"), darunter die Stimmgruppe und die Zahl der Mitglieder. Im Seitenkopf liegen
**Hilfe**, **Alle Listen** (zurück zur Übersicht) und das Menü **Liste verwalten**.

![Listenseite mit Fortschrittsleiste und Tabelle](images/voice-group-lists/03-list.png)

Die Tabelle zeigt je Mitglied den Namen, die Teilstimme und je Spalte eine Zelle. Du kannst
nach Name und Teilstimme sortieren, über **Suchen** nach Namen filtern und mit **Ansicht**
die Darstellung wechseln. Am Handy wird jedes Mitglied zur Karte.

### Häkchen setzen und Notizen speichern

- **Häkchen-Spalte:** Ein Klick auf das Kästchen hakt ab, ein weiterer nimmt das Häkchen
  zurück. Am Handy genügt ein Tipp auf die Zeile der Karte. Ein Tooltip am Kästchen sagt
  "Abgehakt von … am …"; am Handy steht das unter dem Kästchen.
- **Notiz-Spalte:** Tipp den Text ins Feld. Er wird gespeichert, sobald du das Feld
  verlässt oder Enter drückst. Ein Knopf zum Speichern erscheint nur bei ungespeicherter
  Änderung, danach kurz "Gespeichert". Eine Notiz darf höchstens 1000 Zeichen lang sein.
  Verlässt du die Seite mit einer ungespeicherten Änderung, warnt dich der Browser.

Beides speichert ohne Neuladen der Seite; Zähler und Fortschrittsleiste ziehen mit. Gelingt
das Speichern nicht, bleibt der alte Stand stehen, eine Meldung erklärt es, und ein
getippter Notiztext bleibt im Feld.

### Fortschritt und "Nur offene zeigen"

Über der Tabelle zeigt eine Leiste je Häkchen-Spalte, wie weit die Spalte ist, zum Beispiel
"12 / 24" mit Balken. **Nur offene zeigen** blendet alle aus, die in dieser Spalte schon
abgehakt sind; die Leiste zeigt dann "12 von 24 offen", und **Alle zeigen** hebt den Filter
wieder auf. Ist die Spalte vollständig, steht stattdessen "Alle erledigt", und der Knopf
entfällt. Am Handy bleibt die Leiste beim Scrollen oben stehen.

![Fortschrittsleiste einer Häkchen-Spalte](images/voice-group-lists/06-progress.png)

![Liste, gefiltert auf die offenen Mitglieder](images/voice-group-lists/07-open-only.png)

## 4. Spalten verwalten

Unter der Tabelle liegt der eingeklappte Bereich **Spalten verwalten**. Hat die Liste noch
keine Spalte, steht er offen.

![Bereich "Spalten verwalten" mit einer geöffneten Spalte](images/voice-group-lists/08-manage-columns.png)

- **Spalte anlegen:** Unter **Neue Frage** gibst du den Namen ein, wählst die **Art** und
  klickst auf **Hinzufügen**. Zur Wahl stehen **Häkchen (erledigt / offen)** und **Notiz
  (freier Text)**. Die Art lässt sich später nicht mehr ändern.
- **Umbenennen:** Klick auf die Zeile der Spalte oder auf **Bearbeiten**, neuen Namen
  eintragen, **Umbenennen**.
- **Verschieben:** In der geöffneten Spalte steht "Reihenfolge: Position 1 von 2" mit den
  Knöpfen **Nach vorne** und **Nach hinten**. Vorne heißt in der Tabelle links, in der
  Karte am Handy oben. Die Knöpfe erscheinen nur, wenn es etwas zu verschieben gibt, und
  nur bei mindestens zwei Spalten.
- **Löschen:** **Spalte löschen** entfernt die Spalte samt allen Einträgen, nach einer
  Rückfrage.

## 5. Liste verwalten: ändern, archivieren, löschen

Das Menü **Liste verwalten** im Seitenkopf enthält:

![Menü "Liste verwalten"](images/voice-group-lists/04-manage-menu.png)

- **Titel und Projekt ändern** öffnet einen Dialog, in dem du beides änderst. Das bisherige
  Projekt der Liste bleibt wählbar, auch wenn kein Mitglied deiner Stimmgruppe mehr darin
  ist. Ein Wechsel des Projekts ändert, wer in der Liste steht (Abschnitt 6).
- **Archivieren** legt die Liste in den Bereich **Archiviert** der Übersicht.
- **Liste löschen** entfernt die Liste samt allen Häkchen und Notizen, nach einer Rückfrage.
  Das lässt sich nicht rückgängig machen. Wer nur Ruhe in der Übersicht will, archiviert.

![Dialog "Titel und Projekt ändern"](images/voice-group-lists/05-edit-list-modal.png)

Eine **archivierte Liste** ist nur lesbar: Häkchen und Notizen lassen sich nicht mehr ändern,
und es gibt weder Spaltenverwaltung noch Titeldialog. Im Seitenkopf steht stattdessen
**Zurückholen**, das die Liste wieder aktiv macht. Das Menü **Liste verwalten** bietet dort
nur **Liste löschen**.

![Archivierte Liste mit Hinweis und Knopf "Zurückholen"](images/voice-group-lists/09-archived.png)

## 6. Wer in den Zeilen steht

Die Zeilen werden nicht gespeichert, sondern bei jedem Öffnen berechnet:

- **Ohne Projekt:** alle aktiven Mitglieder der Stimmgruppe, gleich in welcher Teilstimme
  (zum Beispiel Sopran 1 und Sopran 2). Sortiert wird nach Teilstimme, Mitglieder ohne
  Teilstimme stehen zuletzt, darin nach Name.
- **Mit Projekt:** nur die aktiven Mitglieder der Stimmgruppe, die auch Projektmitglied
  sind.

Ein neues Mitglied steht daher ohne Zutun in der Liste. Wer herausfällt, weil er
archiviert, aus der Stimmgruppe oder aus dem Projekt genommen wird, verschwindet aus der
Tabelle und aus dem Zähler, **behält aber seine Einträge**. Kommt er zurück, sind Häkchen
und Notizen wieder da. Speichern lässt sich nur für Mitglieder, die gerade in der Liste
stehen.

Wird ein Projekt gelöscht, bleibt die Liste samt Notizen bestehen und gilt danach als
"Ohne Projekt".

## Häufige Stolperfallen

- **Der Menüpunkt fehlt.** Deine Rolle braucht das Recht **"Stimmgruppen-Listen führen"**.
  Der Administrator vergibt es unter **Administration → Rollen & Rechte**.
- **"Liste nicht gefunden".** Die Liste gehört zu einer anderen Stimmgruppe, oder es gibt
  sie nicht (mehr). Beides meldet dasselbe.
- **Die Übersicht sagt, du gehörst keiner Stimmgruppe an.** Listen gibt es nur für die
  eigene Stimmgruppe; ist dir keine zugeordnet, bleibt die Übersicht leer und **Neue
  Liste** fehlt. Frag den Administrator.
- **Ein Mitglied fehlt in der Liste.** Es ist archiviert, nicht in der Stimmgruppe oder,
  bei einer Liste mit Projekt, nicht im Projekt. Die Einträge bleiben erhalten.
- **Häkchen oder Notizen lassen sich nicht ändern.** Die Liste ist archiviert. Mit
  **Zurückholen** arbeitest du weiter.
- **Die Art einer Spalte ändern.** Das geht nicht. Lege eine neue Spalte mit der richtigen
  Art an und lösche die alte.

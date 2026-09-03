# Newsletter schreiben und versenden

Diese Anleitung führt vom leeren Entwurf bis zum abgeschickten Rundschreiben.

> **Berechtigung:** Newsletter anlegen, bearbeiten und versenden darf nur, wessen Rolle das Recht **"Newsletter verwalten"** hat. Fehlt dir der Menüpunkt **Bereiche → Newsletter**, frag den Administrator nach diesem Recht.

## 1. Entwurf anlegen

Öffne **Bereiche → Newsletter**, stelle den Status auf **Entwürfe** und klicke oben rechts auf **Neuer Newsletter**. Der Dialog öffnet sich direkt über der Übersicht.

![Newsletter-Einstellungen im Dialog "Neuer Newsletter"](images/newsletter/03-create-modal-settings.png)

Pflichtfelder sind:

- **Titel** – wird zugleich als Betreff der E-Mail verwendet (höchstens 255 Zeichen).
- **Inhalt** – der Text des Newsletters.

Freiwillig sind:

- **Projekt** – ordnet den Newsletter einem Projekt zu. Ohne Auswahl bleibt er projektlos, was für Rundschreiben an eine Rolle oder an einzelne Personen der Normalfall ist.
- **Empfängerquellen** – ein Entwurf lässt sich auch ohne Empfänger speichern und später ergänzen. Versendet werden kann er dann allerdings noch nicht.

## 2. Empfänger zusammenstellen

Die Empfänger stellst du nicht als feste Adressliste zusammen, sondern über **Empfängerquellen**. Vier Quellen stehen zur Verfügung und lassen sich beliebig kombinieren:

| Quelle | Wer wird angeschrieben |
|--------|------------------------|
| **Projektmitglieder** | alle Mitglieder der ausgewählten Projekte (mehrere Projekte möglich) |
| **Veranstaltungsteilnehmer** | alle Personen, die bei der gewählten Veranstaltung als **anwesend erfasst** sind – also der Nachbericht, nicht die Anmeldung |
| **Rollen** | alle Personen, denen die gewählte Rolle zugewiesen ist |
| **Einzelne Mitglieder** | gezielt ausgewählte einzelne Personen |

Wählst du beim Anlegen ein Projekt aus, ist es zugleich als Quelle **Projektmitglieder** vorbelegt. Die Zahl neben jeder Quelle zeigt, wie viele Einträge du dort ausgewählt hast; das blaue Feld **Empfänger** darunter zeigt die tatsächliche Personenzahl und aktualisiert sich bei jeder Änderung.

Wichtig dabei:

- **Veranstaltungsteilnehmer blicken zurück**: Die Quelle liest die erfasste **Anwesenheit**, und die trägt jemand erst *nach* der Veranstaltung ein. Wählst du einen Termin aus, der noch bevorsteht, bleibt die Empfängerzahl deshalb bei 0 – die Anmeldungen (Ja/Nein/Vielleicht) sieht diese Quelle nicht an. Für ein Rundschreiben *vor* einem Auftritt nimmst du **Projektmitglieder**, **Rollen** oder **Einzelne Mitglieder**; für den Nachbericht an alle, die tatsächlich da waren, ist **Veranstaltungsteilnehmer** die richtige Wahl.
- **Doppelte werden zusammengeführt**: Wer über mehrere Quellen erfasst ist, bekommt den Newsletter trotzdem nur einmal.
- **Nur aktive Mitglieder** werden angeschrieben; deaktivierte Konten bleiben außen vor.
- **Ohne Empfänger kein Versand**: Die Schaltfläche „Versenden" ist gesperrt, solange die Empfängerzahl 0 ist. Das gilt auch, wenn zwar eine Quelle gewählt ist, sich daraus aber keine aktive Person ergibt.

## 3. Inhalt schreiben

Weiter unten im selben Dialog liegen die Vorlagenauswahl und der Editor.

![Vorlage laden und Inhaltseditor](images/newsletter/04-create-modal-content.png)

Über **Vorlage laden (optional)** übernimmst du den Inhalt einer vorhandenen Vorlage in den Editor und passt ihn anschließend an. Angeboten werden alle Vorlagen, im Auswahlfeld nach Kontext gruppiert – zuerst die globalen, danach die Vorlagen je Projekt (siehe [Newsletter-Vorlagen verwalten](newsletter-templates)).

Im Editor formatierst du den Text wie in einer Textverarbeitung – Überschriften, Listen, Links, Bilder und Tabellen sind möglich.

Ein Klick auf **Erstellen als Entwurf** speichert den Newsletter. Er ist damit noch nicht verschickt, sondern wird direkt zum Bearbeiten geöffnet.

## 4. Entwurf bearbeiten und versenden

Einen bestehenden Entwurf öffnest du in der Übersicht über **Bearbeiten**. Titel, Projekt, Empfängerquellen und Inhalt lassen sich hier jederzeit ändern.

![Aktionen im Bearbeiten-Dialog](images/newsletter/05-edit-modal-actions.png)

Am Fuß des Dialogs stehen die Aktionen:

- **Speichern** – sichert den Zwischenstand, ohne zu versenden. Zusätzlich wird der Entwurf etwa alle 30 Sekunden automatisch gespeichert.
- **Vorschau** – zeigt den Inhalt so, wie ihn die Empfänger sehen.
- **Versenden** – schickt den Newsletter ab.
- **Löschen** – verwirft den Entwurf endgültig.
- **Als Vorlage speichern** – legt den aktuellen Inhalt als wiederverwendbare Vorlage ab.

Versenden, Vorschau und Löschen erreichst du auch direkt aus der Übersicht über den kleinen Pfeil neben **Bearbeiten**:

![Aktionsmenü eines Entwurfs](images/newsletter/02-draft-actions-menu.png)

Beim Versand passiert Folgendes:

1. Die Empfänger werden **neu aufgelöst** – maßgeblich ist der Stand von Projektmitgliedschaften, Rollen und aktiven Konten *im Moment des Versands*, nicht der beim letzten Speichern.
2. Für jede Empfängerin und jeden Empfänger wird eine E-Mail in die Warteschlange gestellt und von dort im Hintergrund verschickt. Es kann also einen Moment dauern, bis die Mails ankommen.
3. Der Newsletter wechselt in den Status **Versendet** und erscheint zusätzlich im persönlichen Archiv aller Empfänger unter **Bereiche → Meine Newsletter**.

## 5. Gleichzeitiges Bearbeiten

Ein Entwurf kann immer nur von einer Person zugleich bearbeitet werden. Sobald du ihn öffnest, wird er für dich gesperrt; alle anderen sehen einen Hinweis, wer gerade daran arbeitet, und können ihn währenddessen weder bearbeiten noch versenden. Verlässt du die Seite, wird die Sperre sofort wieder freigegeben; spätestens 30 Minuten nach dem Öffnen läuft sie ohnehin ab. Wird ein Entwurf, den du geöffnet hast, zwischenzeitlich von jemand anderem übernommen, bekommst du das gemeldet und die Ansicht wird neu geladen.

## Häufige Stolperfallen

- **Versand abgelehnt („Newsletter hat keine Empfänger.")**: Es ist keine Quelle gewählt, oder die gewählten Quellen ergeben keine aktive Person – etwa eine Rolle ohne aktive Mitglieder oder – der häufigste Fall – eine Veranstaltung ohne erfasste Anwesenheit, weil sie noch bevorsteht.
- **Titel und Inhalt sind Pflicht**: Ein leerer Editor wird nicht gespeichert. Ein Newsletter, der nur aus einem Bild oder einer Tabelle besteht, ist dagegen erlaubt.
- **Versand ist endgültig**: Ein versendeter Newsletter lässt sich nicht mehr bearbeiten, zurückholen oder löschen.

## Platzhalter

Platzhalter werden beim Versand für jede empfangende Person einzeln ersetzt. Im Inhalt fügst du sie
über den Knopf **Platzhalter** in der Editor-Leiste ein. Im Betreff gibt es keine Editor-Leiste —
dort schreibst du sie von Hand in doppelten geschweiften Klammern, genau so, wie sie in der
Tabelle stehen.

| Platzhalter | Ergebnis | Wenn der Wert fehlt |
|---|---|---|
| `{{anrede}}` | Hallo Georg | Hallo |
| `{{vorname}}` | Georg | bleibt leer |
| `{{nachname}}` | Pitterle | bleibt leer |
| `{{name}}` | Georg Pitterle | die E-Mail-Adresse |
| `{{email}}` | georg@example.at | — |
| `{{stimmgruppe}}` | Sopran (Sopran 1) | „ohne Stimmgruppe" |
| `{{titel}}` | Betreff des Newsletters | — |
| `{{projekt}}` | Name des Projekts | bleibt leer |
| `{{datum}}` | Versanddatum, in der Vorschau das heutige Datum | — |
| `{{absender}}` | Person, die den Newsletter angelegt hat | bleibt leer |
| `{{app_name}}` | Name dieser Anwendung | — |
| `{{login_url}}` | Adresse der Anwendung | — |
| `{{archiv_link}}` | Link „Im Browser ansehen" | bleibt leer |

**Vorher prüfen.** Über der Vorschau wählst du eine empfangende Person aus; angeboten werden nur
Personen, die für diesen Newsletter tatsächlich als Empfänger aufgelöst wurden. Die Vorschau zeigt
dann deren Werte. Ohne Auswahl siehst du deine eigenen Daten. Der Knopf **Testmail** schickt den
aktuellen Stand an deine eigene Adresse, ohne die Empfängerliste zu berühren.

## Textgestaltung

Farben und Schriftgrößen frei zu setzen ist bewusst nicht möglich — sonst sähe jede Aussendung
anders aus. Stattdessen gibt es fünf fertige Formate unter **Format → Formate**. Markiere den
Absatz und wähle eines davon:

| Format | Wofür |
|---|---|
| Einleitung (hervorgehoben) | Der erste Absatz, etwas größer und kräftiger |
| Nebentext (gedämpft) | Anmerkungen, die nicht ins Auge springen sollen |
| Zwischenüberschrift in Markenfarbe | Gliedert lange Aussendungen, in der Farbe des Chores |
| Zentriert | Für einzelne Zeilen, etwa einen Termin oder einen Aufruf |
| Hinweiskasten | Hebt Wichtiges auf ruhiger Fläche hervor |

Kopierst du Text aus einem anderen Programm hinein, bleiben dessen Farben und Schriftarten nicht
erhalten. Das ist Absicht: In der Mail zählt, was auch in älteren E-Mail-Programmen ankommt.

**Tippfehler.** Ein unbekannter Platzhalter wie `{{vorrname}}` wird nicht ersetzt, sondern bleibt
im Text stehen. Beim Speichern erscheint dazu ein Hinweis mit den betroffenen Platzhaltern.

**Formatierung.** Setze innerhalb der Klammern keine Formatierung wie Fettdruck — der Platzhalter
wird dann nicht mehr erkannt. Am sichersten ist der Knopf **Platzhalter** in der Editor-Leiste.

**E-Mail-Adressen.** `{{email}}` schreibt die Adresse der empfangenden Person in den Text. Wird
die Mail weitergeleitet, ist die Adresse mit unterwegs.

Fehlt dir der Knopf **Platzhalter**, fehlt dir das Recht „Newsletter verwalten". Wende dich
an den Administrator.

# Umfragen verwalten

Hier erfährst du, wie du Umfragen anlegst, bearbeitest, vorzeitig beendest und löschst. Wie Mitglieder abstimmen und Ergebnisse lesen, steht unter [Umfragen](polls).

> **Berechtigung:** Umfragen anlegen, bearbeiten, beenden und löschen darf nur, wessen Rolle das Recht **"Umfragen verwalten"** hat. Fehlt dir der Knopf **Neue Umfrage** oder das Menü **Umfrage verwalten**, frag den Administrator, ob dieses Recht bei deiner Rolle unter **Administration → Rollen & Rechte** aktiviert ist. Zusätzlich muss das Umfragen-Modul in dieser Installation aktiviert sein.

## 1. Neue Umfrage anlegen

Öffne **Kommunikation → Umfragen** und klicke oben rechts auf **Neue Umfrage**.

![Formular für eine neue Umfrage](images/polls/10-create-form.png)

Das Formular fragt:

- **Frage** (Pflicht): die Frage, die die Mitglieder sehen, höchstens 255 Zeichen.
- **Erklärung** (optional): ein Hinweistext über den Antworten, höchstens 2000 Zeichen.
- **Art der Antwort**: **Antworten zur Auswahl** oder **Freie Antwort**, bei der jede Person selbst einen Text schreibt (bis 500 Zeichen). Die Art steht nach dem Anlegen fest.
- **Antworten** (nur bei Auswahl): mindestens 2, höchstens 20 Antworten mit je bis zu 200 Zeichen. Mit **Antwort hinzufügen** kommt eine Zeile dazu, das Kreuz entfernt sie. Jede Antwort darf nur einmal vorkommen.
- **Mehrere Antworten erlauben**: Schalter für Mehrfachauswahl statt Einfachauswahl.
- **Anonym abstimmen**: Die Einstellung lässt sich nach dem Start nicht mehr ändern. Bei sehr kleinen Zielgruppen verrät ein einstimmiges Ergebnis trotzdem, wie jemand abgestimmt hat.
- **Zielgruppe**: wer die Umfrage sieht und abstimmen darf. Vorgegeben ist **Alle Mitglieder**. Du kannst stattdessen Zeilen nach Rolle, Stimmgruppe, Untergruppe, Projekt oder einzelnen Mitgliedern zusammenstellen; die Anzeige "trifft derzeit … Mitglieder" zählt mit. Mindestens eine Zeile ist nötig. Die Zielgruppe wird laufend ausgewertet: Wer später in die Gruppe kommt, sieht die Umfrage ebenfalls.
- **Abstimmen bis einschließlich** (Pflicht): das Fristdatum. Die Umfrage läuft bis 23:59:59 Uhr dieses Tages. Das Datum darf beim Anlegen nicht in der Vergangenheit liegen. Vorgegeben ist ein Datum in sieben Tagen.
- **Mitglieder benachrichtigen**: Vorgegeben angeschaltet. Schickt allen aus der Zielgruppe eine Mail und eine Meldung in der Glocke. Schalte es aus, wenn die Umfrage leise starten soll.

Mit **Umfrage starten** legst du die Umfrage an.

## 2. Umfrage bearbeiten, beenden und löschen

Öffne die Umfrage in der Übersicht. Oben rechts liegt das Menü **Umfrage verwalten**; es erscheint nur mit dem Recht **"Umfragen verwalten"**.

![Menü "Umfrage verwalten" mit Bearbeiten, Jetzt beenden und Löschen](images/polls/09-manage-menu.png)

### Bearbeiten

**Bearbeiten** öffnet dasselbe Formular. Änderbar sind Frage, Erklärung, Zielgruppe und Frist. Art der Antwort und Anonymität stehen fest und erscheinen nur als Text.

Hat schon jemand abgestimmt, sind zusätzlich **die Antworten und die Auswahlart gesperrt** (eine oder mehrere Antworten). Sie lassen sich dann nicht mehr ändern, damit abgegebene Stimmen nicht plötzlich etwas anderes bedeuten.

Bei einer beendeten Umfrage weist das Formular darauf hin, wann sie beendet wurde: **Ein späteres Datum öffnet die Abstimmung wieder.** Lässt du das Datum unverändert, bleibt die Frist exakt wie sie war. Eine erneute Benachrichtigung gibt es beim Bearbeiten nicht.

### Jetzt beenden

**Jetzt beenden** setzt die Frist auf den jetzigen Zeitpunkt. Die Umfrage steht danach unter **Beendet**, und das Ergebnis ist für die Zielgruppe sichtbar, bei anonymen Umfragen ebenfalls erst jetzt. Der Eintrag fehlt im Menü, wenn die Umfrage schon beendet ist. Willst du sie wieder öffnen, bearbeite sie und setze ein späteres Fristdatum.

### Umfrage löschen

**Umfrage löschen** entfernt die Umfrage samt allen Stimmen und Antworten nach einer Rückfrage. Das lässt sich nicht rückgängig machen.

## 3. Was Verwaltende sehen

- In der Übersicht siehst du alle Umfragen, auch solche, deren Zielgruppe dich nicht trifft. Gehörst du nicht dazu, weist die Umfrage darauf hin ("Du gehörst nicht zur Zielgruppe.") und du kannst nicht mitstimmen.
- Namentliche Umfragen kannst du schon laufend auswerten, auch ohne selbst abgestimmt zu haben. Gehörst du zur Zielgruppe und hast noch nicht abgestimmt, führt bei namentlichen Umfragen der Knopf **Zwischenstand** vom Stimmzettel zum Ergebnis.
- Bei anonymen Umfragen gibt es für niemanden einen Zwischenstand, auch nicht für dich. Das Ergebnis erscheint nach dem Fristende.

## Häufige Stolperfallen

- **Anonym lässt sich nicht nachträglich ändern**: Entscheide beim Anlegen, ob die Umfrage anonym sein soll. Wer später Namen braucht, legt eine neue Umfrage an.
- **Antworten sind gesperrt**: Sobald jemand abgestimmt hat, bleiben Antworten und Auswahlart fest. Ändere nur die Frage oder Erklärung, oder lege die Umfrage neu an.
- **Tippfehler im Fristdatum**: Ein späteres Datum öffnet eine beendete Umfrage wieder. Prüfe das Datum, bevor du speicherst.
- **Benachrichtigung vergessen**: Sie lässt sich nur beim Anlegen auslösen. Danach hilft ein Hinweis auf anderem Weg, etwa ein Newsletter, siehe [Newsletter](newsletter).

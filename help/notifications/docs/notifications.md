# Benachrichtigungen

Der Chor-Manager sagt dir Bescheid, wenn etwas passiert, das dich betrifft: eine Aufgabe wird dir zugewiesen, ein Termin verschiebt sich, jemand kommentiert deine Aufgabe. So musst du nicht selbst nachsehen, ob sich etwas getan hat.

Jede Benachrichtigung kommt auf zwei Wegen: als **E-Mail** und in der **Glocke** oben rechts in der Kopfzeile. Zu Beginn ist beides eingeschaltet. Was du nicht bekommen willst, schaltest du in deinem Profil ab – jeden Anlass und jeden Weg einzeln.

> **Berechtigung:** Die Glocke und den Reiter **Benachrichtigungen** im Profil hat jedes Mitglied. Die installationsweiten Schalter unter **Administration → App-Einstellungen** sieht nur, wer das Recht **"Stammdaten verwalten"** hat. Siehst du den Menüpunkt nicht, frag den Administrator unter **Administration → Rollen & Rechte**.

## 1. Die Glocke

Die **Glocke** steht in der Kopfzeile links neben dem Personen-Symbol. Eine rote Zahl zeigt, wie viele Benachrichtigungen du noch nicht gelesen hast. Die Zahl aktualisiert sich von selbst, solange die Seite offen ist, und spätestens dann, wenn du in das Browserfenster zurückwechselst.

Ein Klick auf die Glocke klappt die letzten Benachrichtigungen auf. Ungelesene sind farbig hinterlegt; das Symbol links zeigt den Bereich – Aufgabe, Termin, Projekt oder Sponsoring.

![Die aufgeklappte Glocke mit den letzten Benachrichtigungen](images/notifications/04-bell-dropdown.png)

- **Ein Klick auf einen Eintrag** öffnet die Aufgabe, den Termin, das Projekt oder den Sponsor und markiert den Eintrag als gelesen.
- **Öffnest du das Ziel auf anderem Weg** – etwa die Aufgabe über die Projektplanung –, gelten die Einträge dazu ebenfalls als gelesen.
- **Alle als gelesen markieren** setzt die Zahl auf null, ohne etwas zu öffnen. Das bloße Aufklappen der Glocke markiert nichts.
- **Alle anzeigen** führt zur vollständigen Liste.

## 2. Alle Benachrichtigungen

Die Seite **Benachrichtigungen** listet alles, neueste oben. Mit **Nur ungelesene** blendest du Gelesenes aus.

![Die Seite mit allen Benachrichtigungen](images/notifications/05-notifications-page.png)

Gelesene Einträge verschwinden nach 30 Tagen von selbst, ungelesene nach 180 Tagen.

## 3. Die eigenen Benachrichtigungen einstellen

Du findest die Einstellung unter **Profil → Benachrichtigungen**. Das Profil erreichst du oben rechts über das Personen-Symbol.

![Der Profil-Reiter mit den einzelnen Benachrichtigungen](images/notifications/01-profile-notifications.png)

Jede Zeile steht für einen Anlass, mit je einem Schalter für **E-Mail** und **Glocke**. Schaltest du einen davon aus, bekommst du diesen Anlass auf diesem Weg nicht mehr – der andere Weg und alle anderen Anlässe bleiben davon unberührt. So kannst du etwa Kommentare nur in der Glocke sehen, ohne eine Mail dafür zu bekommen.

| Anlass | Wann er eintritt |
| --- | --- |
| Aufgabe zugewiesen | Jemand trägt dich für eine Aufgabe ein |
| Kommentar zu einer Aufgabe | Jemand kommentiert eine Aufgabe, die dir gehört oder die du angelegt hast |
| Aufgabe wird bald fällig | Kurz vor dem Fälligkeitsdatum einer offenen Aufgabe |
| Neuer Termin | Ein Termin wird angelegt, der dich betrifft |
| Termin verschoben oder verlegt | Zeit oder Ort haben sich geändert |
| Termin abgesagt | Ein Termin oder eine ganze Serie wird gelöscht |
| Neue Bemerkung zu einem Termin | Jemand schreibt eine öffentliche Bemerkung |
| Zu einem Projekt hinzugefügt | Jemand ordnet dich einem Projekt zu |
| Wiedervorlage wird fällig | Kurz vor dem Wiedervorlage-Datum eines Kontakts, den du protokolliert hast |

Die Aufgaben-Anlässe erscheinen nur, wenn das Aufgaben-Modul aktiv ist; die Wiedervorlage nur bei aktivem Sponsoring-Modul. Fehlt ein Anlass in deiner Liste, ist entweder das Modul aus oder die Verwaltung hat ihn abgeschaltet.

Am Ende jeder Mail steht, welcher Anlass sie ausgelöst hat, mit einem Link zurück auf diese Seite.

## 4. Was du selbst auslöst, kommt nicht zurück

Weist du dir selbst eine Aufgabe zu oder schreibst du einen Kommentar, bekommst du dafür weder eine Mail noch einen Eintrag in der Glocke – du weißt es ja bereits. Benachrichtigt werden nur die anderen Beteiligten.

## 5. Beim Anlegen eines Termins entscheiden

Im Formular für einen Termin sitzt unten das Häkchen **Mitglieder benachrichtigen**. Es ist vorbelegt.

![Das Häkchen im Formular für einen neuen Termin](images/notifications/03-event-notify-checkbox.png)

Nimm es heraus, wenn du nur eine Kleinigkeit korrigierst. Sonst bekommt für einen berichtigten Tippfehler die ganze Zielgruppe Bescheid. Beim **Bearbeiten** eines Termins gilt dasselbe Häkchen.

Eine **Serie** löst je Empfänger genau **eine** Mail und **einen** Eintrag in der Glocke aus, in der alle Termine stehen – nicht einen pro Termin.

## 6. Installationsweit steuern

Unter **Administration → App-Einstellungen** steht ein Abschnitt **Benachrichtigungen**. Was dort abgeschaltet ist, meldet der Chor-Manager niemandem – weder per Mail noch in der Glocke, auch nicht Mitgliedern, die den Anlass in ihrem Profil eingeschaltet haben.

![Die installationsweiten Schalter in den App-Einstellungen](images/notifications/02-settings-notifications.png)

Darunter stehen zwei Vorlaufzeiten:

- **Aufgaben-Erinnerung: Tage vor Fälligkeit** – wie früh vor dem Fälligkeitsdatum erinnert wird.
- **Wiedervorlage-Erinnerung: Tage vorher** – dasselbe für Sponsoring-Wiedervorlagen.

Eine **0** schaltet die jeweilige Erinnerung ganz ab.

## Häufige Stolperfallen

- **Ein abgeschalteter Anlass verschwindet aus dem Profil** – wer ihn dort sucht, findet ihn nicht mehr. Das ist Absicht: Ein Schalter für etwas, das ohnehin nie kommt, wäre irreführend.
- **Keine E-Mail-Adresse hinterlegt** – dann kommt nur der Eintrag in der Glocke.
- **Nur neu Hinzugekommene werden benachrichtigt** – änderst du die Zuweisung einer Aufgabe, bekommen nur die neuen Personen Bescheid. Wer schon eingetragen war, weiß es bereits.
- **Private Bemerkungen bleiben privat** – eine Bemerkung mit dem Häkchen "Privat" löst keine Benachrichtigung aus.
- **Abgesagte Termine kommen ohne Link zum Termin** – er ist gelöscht. Mail und Glocke nennen Titel und Datum, damit du ihn im eigenen Kalender findest; der Eintrag in der Glocke führt zur Terminliste.
- **Erinnerungen kommen nur vorher, nicht nachher** – eine bereits überfällige Aufgabe wird nicht täglich angemahnt.

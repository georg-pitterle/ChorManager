# Termine

Im Bereich **Termine** planst du alle Proben, Auftritte, Sitzungen und sonstigen Ereignisse deines Chors. Du siehst Termine als Liste oder im Kalender, kannst wiederkehrende Serien anlegen, die Zielgruppe eines Termins einschränken und einen persönlichen Kalenderlink zum Abonnieren erzeugen.

> **Berechtigung:** Termine ansehen kann jedes eingeloggte Mitglied. Termine anlegen, bearbeiten oder löschen sowie Termin-Typen verwalten darf nur, wer das Recht **"Termine verwalten"** hat. Siehst du die entsprechenden Buttons nicht, frag den Administrator unter **Verwaltung → Rollen**.

## 1. Terminliste

Unter **Termine → Termine** siehst du standardmäßig die Liste aller Termine. Über die Filter oben kannst du nach Projekt und Termin-Typ einschränken und vergangene Termine ein- oder ausblenden.

![Terminliste mit Filtern](images/events/01-list.png)

Jede Zeile zeigt Datum/Zeit, Titel, ob Bemerkungen vorhanden sind, Ort, Typ und Zielgruppe. Ein Wiederholungssymbol neben der Uhrzeit kennzeichnet Serientermine. Über den Button **Bemerkungen** gelangst du zur Detailseite, über **Anwesenheit** (falls für dich sichtbar) zur Anwesenheitsliste dieses Termins.

## 2. Kalenderansicht

Über den Umschalter **Liste / Kalender** oben rechts wechselst du zur Monats-, Wochen- oder Terminübersichtsansicht.

![Kalenderansicht der Termine](images/events/02-calendar.png)

Ein Klick auf einen Termin im Kalender öffnet dessen Detailseite. Mit dem Recht "Termine verwalten" öffnet ein Klick auf einen freien Tag direkt das Formular für einen neuen Termin an diesem Datum.

## 3. Termin anlegen

Über **Termin erstellen** öffnest du das Formular für einen neuen Termin: Titel, Datum, Start-/Endzeit, Typ und optional ein Ort sind Pflicht bzw. gängige Angaben.

![Formular zum Anlegen eines Termins inkl. Zielgruppe und Wiederholung](images/events/03-new-event-modal.png)

Zusätzlich kannst du festlegen:

- **Zielgruppe**: Ohne Auswahl gilt der Termin für alle aktiven Mitglieder. Du kannst ihn stattdessen auf bestimmte Projektmitglieder, Rollen, Stimmgruppen oder einzelne Personen einschränken. Diese Zielgruppe bestimmt, wer in der Anwesenheitsliste, bei der Anmeldung und im persönlichen Kalenderabo erscheint.
- **Anwesenheitsliste führen**: Aktiviert die Anwesenheitserfassung für diesen Termin (siehe [Anwesenheit erfassen](events-attendance)).
- **Anmeldung freischalten** (wenn das Anmeldungsmodul aktiv ist): Mitglieder können zu-/absagen (siehe [Anmeldungen](events-registrations)). Der Anmeldeschluss ist frei wählbar, sonst gilt der Terminbeginn.
- **Termin wiederholen**: Legt eine Serie an – Intervall (täglich/wöchentlich/monatlich/jährlich), bei wöchentlicher Wiederholung zusätzlich die Wochentage, sowie ein Enddatum der Serie.

## 4. Termin bearbeiten und löschen

Mit dem Recht "Termine verwalten" öffnest du über das Dropdown-Menü am Zeilenende einer Terminzeile **Termin bearbeiten** oder **Termin löschen**.

![Formular zum Bearbeiten eines Termins](images/events/04-edit-event.png)

Bei Serienterminen erscheint zusätzlich die Option **"Änderungen auf zukünftige Termine der Serie anwenden?"** – ist sie aktiviert, werden deine Änderungen auf alle noch bevorstehenden Termine der Serie übertragen, nicht nur auf den aktuellen.

Darunter wählst du unter **"Was wird übertragen?"** die Bereiche aus, die tatsächlich auf die Folgetermine wirken: Titel und Terminart, Ort, Uhrzeit, Anmeldung und Anmeldeschluss, Anwesenheitspflicht sowie Zielgruppe. Standardmäßig sind alle angehakt. Nimm den Haken weg, wenn einzelne Termine der Serie bewusst abweichen – etwa eine Generalprobe an einem anderen Ort oder mit engerer Zielgruppe. Nicht angehakte Bereiche bleiben bei den Folgeterminen unverändert.

Der Anmeldeschluss wird bei Serien nie als fester Zeitpunkt übernommen, sondern als **Vorlauf** zum jeweiligen Terminbeginn: Trägst du beim bearbeiteten Termin zwei Tage vorher ein, gilt bei jedem Folgetermin ebenfalls "zwei Tage vorher". Das gilt genauso beim Anlegen einer Serie.

Über **Serie löschen** im Dropdown-Menü der Liste entfernst du alle Termine der Serie ab dem gewählten Termin.

## 5. Termin-Detail und Bemerkungen

Ein Klick auf einen Termin öffnet die Detailseite mit Datum, Ort und den Bemerkungen zu diesem Termin.

![Termin-Detailseite mit Bemerkungen](images/events/05-event-detail.png)

Jedes Mitglied kann Bemerkungen hinzufügen und dabei wählen, ob sie **öffentlich** (für alle mit Zugriff auf den Termin sichtbar) oder **privat** (nur für dich selbst) sind. Eigene private Bemerkungen kannst du jederzeit bearbeiten oder löschen; öffentliche Bemerkungen können nur Personen mit dem Recht "Termine verwalten" moderieren.

## 6. Kalender abonnieren

Über den Button **Kalender abonnieren** (auf der Terminliste und auf der Detailseite) erhältst du einen persönlichen, dauerhaften Link im iCal-Format.

![Modal mit dem persönlichen Kalenderabo-Link](images/events/06-calendar-subscription-modal.png)

Kopiere den Link in deine Kalender-App (z. B. als "URL-Kalender abonnieren"). Der Kalender zeigt automatisch nur die für dich relevanten, zukünftigen Termine und aktualisiert sich von selbst, sobald sich Termine ändern.

### Aufgaben mit im Kalender

Ist das Aufgaben-Modul aktiv, können deine Aufgaben im selben Abo mitkommen. Unter **Profil → Kalender** entscheidest du:

- **Zusammen mit den Terminen in einem Abo** (Voreinstellung) – ein Link genügt, Termine und Aufgaben stehen darin nebeneinander.
- **In einem eigenen Abo** – der Termin-Link bleibt frei von Aufgaben; im Abo-Fenster erscheint zusätzlich ein zweiter Link nur für die Aufgaben.
- **Gar nicht** – Aufgaben bleiben aus beiden Links draußen.

Dazu wählst du die Darstellung: als **ganztägiger Termin** am Fälligkeitstag oder als **Aufgabe mit Fälligkeit**. Apple Kalender und Google Kalender zeigen echte Aufgaben aus abonnierten Kalendern nicht an – wer einen davon nutzt, bleibt besser beim ganztägigen Termin.

Aufgenommen werden nur Aufgaben, die dir zugewiesen sind, ein Fälligkeitsdatum tragen und noch nicht abgeschlossen sind. Beide Links nutzen denselben Zugang: Erzeugst du eine neue Adresse, gilt die alte für Termine **und** Aufgaben nicht mehr.

## 7. Termin-Typen verwalten

Unter **Verwaltung → Termin-Typen** legst du die Kategorien fest, mit denen Termine eingefärbt und gefiltert werden (z. B. Probe, Auftritt, Sitzung).

![Übersicht der Termin-Typen mit Farbcodierung](images/events/07-event-types.png)

Über **Termin-Typ hinzufügen** legst du einen neuen Typ mit Name und Farbe an. Beim Löschen eines Typs verlieren bestehende Termine mit diesem Typ lediglich die Zuordnung – sie bleiben erhalten.

## Anleitungen

- [Anwesenheit erfassen](events-attendance) – Anwesenheitslisten je Termin führen und auswerten.
- [Anmeldungen (Zu-/Absagen)](events-registrations) – Wie Mitglieder sich zu Terminen an- und abmelden und wie Vertretungen funktionieren.

## Häufige Stolperfallen

- **Serie löschen ist endgültig** – es löscht alle Termine der Serie ab dem gewählten Termin, nicht nur den aktuellen. Liegt der gewählte Termin in der Vergangenheit, trifft es auch die vergangenen Termine der Serie. Mit ihnen verschwinden ihre Anwesenheitslisten und Anmeldungen; die Erfolgsmeldung nennt deshalb Datum und Anzahl der gelöschten Termine.
- **Termin löschen nimmt die Historie mit** – Anwesenheiten und Anmeldungen hängen am Termin und werden zusammen mit ihm entfernt. Soll die Historie erhalten bleiben, verschiebe den Termin, statt ihn zu löschen.
- **Wochentage gelten nur bei wöchentlicher Wiederholung** – bei täglich, monatlich und jährlich haben sie keine Wirkung. Eine monatliche Serie hängt am Tag im Monat des ersten Termins; gibt es diesen Tag in einem Monat nicht (etwa den 31. im Februar), rückt der Termin auf den Monatsletzten und kehrt im nächsten Monat auf den ursprünglichen Tag zurück.
- **Keine Zielgruppe gewählt** bedeutet nicht "niemand", sondern **alle aktiven Mitglieder** – schränke die Zielgruppe bewusst ein, wenn ein Termin nur für einen Teil des Chors gilt.
- **Anmeldeschluss ohne Angabe** fällt automatisch auf den Terminbeginn zurück.
- **Aufgaben als "Aufgabe mit Fälligkeit" bleiben in manchen Kalendern unsichtbar** – Apple und Google verwerfen Aufgaben aus abonnierten Kalendern stillschweigend. Fehlen sie, stelle im Profil auf den ganztägigen Termin um.

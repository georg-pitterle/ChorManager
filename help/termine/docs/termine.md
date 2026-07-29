# Termine

Im Bereich **Termine** planst du alle Proben, Auftritte, Sitzungen und sonstigen Ereignisse deines Chors. Du siehst Termine als Liste oder im Kalender, kannst wiederkehrende Serien anlegen, die Zielgruppe eines Termins einschränken und einen persönlichen Kalenderlink zum Abonnieren erzeugen.

> **Berechtigung:** Termine ansehen kann jedes eingeloggte Mitglied. Termine anlegen, bearbeiten oder löschen sowie Termin-Typen verwalten darf nur, wer das Recht **"Termine verwalten"** hat. Siehst du die entsprechenden Buttons nicht, frag den Administrator unter **Verwaltung → Rollen**.

## 1. Terminliste

Unter **Termine → Termine** siehst du standardmäßig die Liste aller Termine. Über die Filter oben kannst du nach Projekt und Termin-Typ einschränken und vergangene Termine ein- oder ausblenden.

![Terminliste mit Filtern](images/termine/01-liste.png)

Jede Zeile zeigt Datum/Zeit, Titel, ob Bemerkungen vorhanden sind, Ort, Typ und Zielgruppe. Ein Wiederholungssymbol neben der Uhrzeit kennzeichnet Serientermine. Über den Button **Bemerkungen** gelangst du zur Detailseite, über **Anwesenheit** (falls für dich sichtbar) zur Anwesenheitsliste dieses Termins.

## 2. Kalenderansicht

Über den Umschalter **Liste / Kalender** oben rechts wechselst du zur Monats-, Wochen- oder Terminübersichtsansicht.

![Kalenderansicht der Termine](images/termine/02-kalender.png)

Ein Klick auf einen Termin im Kalender öffnet dessen Detailseite. Mit dem Recht "Termine verwalten" öffnet ein Klick auf einen freien Tag direkt das Formular für einen neuen Termin an diesem Datum.

## 3. Termin anlegen

Über **Termin erstellen** öffnest du das Formular für einen neuen Termin: Titel, Datum, Start-/Endzeit, Typ und optional ein Ort sind Pflicht bzw. gängige Angaben.

![Formular zum Anlegen eines Termins inkl. Zielgruppe und Wiederholung](images/termine/03-neuer-termin-modal.png)

Zusätzlich kannst du festlegen:

- **Zielgruppe**: Ohne Auswahl gilt der Termin für alle aktiven Mitglieder. Du kannst ihn stattdessen auf bestimmte Projektmitglieder, Rollen, Stimmgruppen oder einzelne Personen einschränken. Diese Zielgruppe bestimmt, wer in der Anwesenheitsliste, bei der Anmeldung und im persönlichen Kalenderabo erscheint.
- **Anwesenheitsliste führen**: Aktiviert die Anwesenheitserfassung für diesen Termin (siehe [Anwesenheit erfassen](termine-anwesenheit)).
- **Anmeldung freischalten** (wenn das Anmeldungsmodul aktiv ist): Mitglieder können zu-/absagen (siehe [Anmeldungen](termine-anmeldung)). Der Anmeldeschluss ist frei wählbar, sonst gilt der Terminbeginn.
- **Termin wiederholen**: Legt eine Serie an – Intervall (täglich/wöchentlich/monatlich/jährlich), bei wöchentlicher Wiederholung zusätzlich die Wochentage, sowie ein Enddatum der Serie.

## 4. Termin bearbeiten und löschen

Mit dem Recht "Termine verwalten" öffnest du über das Dropdown-Menü am Zeilenende einer Terminzeile **Termin bearbeiten** oder **Termin löschen**.

![Formular zum Bearbeiten eines Termins](images/termine/04-termin-bearbeiten.png)

Bei Serienterminen erscheint zusätzlich die Option **"Änderungen auf zukünftige Termine der Serie anwenden?"** – ist sie aktiviert, werden deine Änderungen auf alle noch bevorstehenden Termine der Serie übertragen, nicht nur auf den aktuellen. Über **Serie löschen** im Dropdown-Menü der Liste kannst du die gesamte Serie ab dem gewählten Termin entfernen.

## 5. Termin-Detail und Bemerkungen

Ein Klick auf einen Termin öffnet die Detailseite mit Datum, Ort und den Bemerkungen zu diesem Termin.

![Termin-Detailseite mit Bemerkungen](images/termine/05-termin-detail.png)

Jedes Mitglied kann Bemerkungen hinzufügen und dabei wählen, ob sie **öffentlich** (für alle mit Zugriff auf den Termin sichtbar) oder **privat** (nur für dich selbst) sind. Eigene private Bemerkungen kannst du jederzeit bearbeiten oder löschen; öffentliche Bemerkungen können nur Personen mit dem Recht "Termine verwalten" moderieren.

## 6. Kalender abonnieren

Über den Button **Kalender abonnieren** (auf der Terminliste und auf der Detailseite) erhältst du einen persönlichen, dauerhaften Link im iCal-Format.

![Modal mit dem persönlichen Kalenderabo-Link](images/termine/06-kalender-abo-modal.png)

Kopiere den Link in deine Kalender-App (z. B. als "URL-Kalender abonnieren"). Der Kalender zeigt automatisch nur die für dich relevanten, zukünftigen Termine und aktualisiert sich von selbst, sobald sich Termine ändern.

## 7. Termin-Typen verwalten

Unter **Verwaltung → Termin-Typen** legst du die Kategorien fest, mit denen Termine eingefärbt und gefiltert werden (z. B. Probe, Auftritt, Sitzung).

![Übersicht der Termin-Typen mit Farbcodierung](images/termine/07-termin-typen.png)

Über **Termin-Typ hinzufügen** legst du einen neuen Typ mit Name und Farbe an. Beim Löschen eines Typs verlieren bestehende Termine mit diesem Typ lediglich die Zuordnung – sie bleiben erhalten.

## Anleitungen

- [Anwesenheit erfassen](termine-anwesenheit) – Anwesenheitslisten je Termin führen und auswerten.
- [Anmeldungen (Zu-/Absagen)](termine-anmeldung) – Wie Mitglieder sich zu Terminen an- und abmelden und wie Vertretungen funktionieren.

## Häufige Stolperfallen

- **Serie löschen ist endgültig** – es löscht auch alle noch bevorstehenden Termine der Serie ab dem gewählten Termin, nicht nur den aktuellen.
- **Keine Zielgruppe gewählt** bedeutet nicht "niemand", sondern **alle aktiven Mitglieder** – schränke die Zielgruppe bewusst ein, wenn ein Termin nur für einen Teil des Chors gilt.
- **Anmeldeschluss ohne Angabe** fällt automatisch auf den Terminbeginn zurück.

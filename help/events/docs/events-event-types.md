# Termin-Typen

Termin-Typen sind die Kategorien, mit denen Termine eingeordnet werden — Probe,
Auftritt, Registerprobe, Sitzung. Jeder Typ hat eine Farbe; sie färbt den Termin in
Plan, Liste und Kalender ein und macht auf einen Blick sichtbar, worum es geht. Typen,
deren Termine aus der Probenroutine herausstechen sollen, lassen sich zusätzlich
hervorheben.

> **Berechtigung:** Der Menüpunkt **Administration → Termin-Typen** erscheint nur, wenn deine
> Rolle das Recht **"Termine verwalten"** hat. Wer Termine anlegen darf, darf also auch
> die Typen pflegen. Alle anderen sehen die Typen nur als Farbe und Filter bei den
> Terminen.
>
> Siehst du den Menüpunkt nicht, frag den Administrator unter **Administration → Rollen & Rechte**.

## 1. Übersicht

Klickpfad: **Administration → Termin-Typen**.

Die Seite zeigt alle Typen als farbige Kacheln, jede mit einem Stift zum Bearbeiten und
einem Papierkorb zum Löschen. Hervorgehobene Typen tragen neben dem Namen einen Stern
und den Hinweis **Hervorgehoben**.

![Übersicht der Termin-Typen mit Farbcodierung](images/events/07-event-types.png)

## 2. Typ anlegen

**Termin-Typ hinzufügen** öffnet das Formular:

- **Name** — Pflichtfeld, der Text, der später am Termin steht (z. B. "Generalprobe").
- **Farbe** — eine Auswahl aus sieben festen Farben: Blau, Grau, Grün, Rot, Gelb,
  Hellblau und Schwarz.
- **Termine dieser Art hervorheben** — für Konzerte, Auftritte und andere Termine, die
  nicht zwischen den Proben untergehen sollen. Ihre Termine tragen im Terminplan, in der
  Terminliste und bei den Anmeldungen einen farbigen Balken am Rand. Die Entscheidung
  fällt einmal pro Typ und gilt für alle seine Termine; neue Typen starten ohne
  Hervorhebung.

![Formular für einen neuen Termin-Typ](images/events/11-event-type-new-modal.png)

Die Farbauswahl ist bewusst begrenzt: Die Farben stammen aus dem Farbschema der
Anwendung und bleiben dadurch in hellen wie dunklen Ansichten lesbar.

## 3. Typ bearbeiten

Der Stift öffnet dasselbe Formular mit den bestehenden Werten. Änderst du den Namen, die
Farbe oder die Hervorhebung, wirkt das sofort auf **alle** Termine dieses Typs — auch auf vergangene, denn
Termine speichern den Typ als Verweis, nicht als Kopie.

![Formular zum Bearbeiten eines Termin-Typs](images/events/12-event-type-edit-modal.png)

## 4. Typ löschen

Der Papierkorb fragt nach und weist dabei auf die Folge hin: Bestehende Termine dieses
Typs bleiben erhalten, verlieren aber ihre Typ-Zuordnung und damit Farbe und Filterbarkeit.

![Rückfrage vor dem Löschen eines Termin-Typs](images/events/13-event-type-delete-modal.png)

Willst du nur umbenennen, nimm das Bearbeiten-Formular — dann bleiben die Zuordnungen
bestehen.

## Häufige Stolperfallen

- **Termine ohne Farbe nach dem Löschen.** Sie haben ihren Typ verloren. Die Zuordnung
  muss an jedem Termin einzeln neu gesetzt werden; ein Wiederherstellen des Typs holt sie
  nicht zurück.
- **Name ist Pflicht.** Ein leeres Namensfeld bricht das Speichern mit einer Meldung ab.
- **Zwei Typen gleicher Farbe** sind erlaubt, machen die Farbcodierung aber wertlos —
  vergib je Farbe möglichst nur einen Typ.

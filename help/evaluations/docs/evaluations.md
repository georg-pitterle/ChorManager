# Anwesenheitsquoten

Die Anwesenheitsquote zeigt je Projekt, wer wie oft bei den Terminen war. Sie beantwortet
die Frage, die vor jedem Auftritt kommt: Wie verlässlich ist die Besetzung, und wo fehlt
regelmäßig jemand?

> **Berechtigung:** Der Menüpunkt **Termine → Anwesenheitsquoten** ist für jedes
> angemeldete Mitglied sichtbar. Welche Projekte in der Auswahl stehen, hängt allerdings
> vom Recht **"Anwesenheit/Anmeldung verwalten (alle Mitglieder)"** ab: Mit diesem Recht siehst du alle
> Projekte, ohne es nur die eigenen. Ein fremdes Projekt lässt sich auch nicht über die
> Adresszeile öffnen.

## 1. Projekt wählen

Klickpfad: **Termine → Anwesenheitsquoten**.

Oben rechts wählst du das Projekt; die Seite lädt sofort neu. Ohne eigene Wahl steht dort
das laufende Projekt, sonst das zuletzt passende. Unter dem Seitentitel steht, wie viele
Pflichttermine im Projekt bisher stattgefunden haben, und der Stand der Auswertung. Über
**Besetzung** wechselst du zur Besetzung desselben Projekts.

![Anwesenheitsquoten eines Projekts](images/evaluations/01-attendance-rates.png)

## 2. Nach Stimmgruppe

Der Block **Nach Stimmgruppe** beantwortet die Frage vor dem Konzert: Steht die
Besetzung, und wo fehlt regelmäßig jemand? Je Stimmgruppe siehst du den Durchschnitt der
Quoten als Balken, die Zahl der Personen und – falls es welche gibt – wie viele davon
unter 50 % liegen. Die Stimmgruppen stehen in Chor-Reihenfolge (Sopran, Alt, Tenor,
Bass), Personen ohne Stimmgruppe zuletzt.

Als gut gilt eine Quote ab 75 %, als niedrig unter 50 %. Die Quote ist farbig markiert und
trägt zusätzlich ein Symbol: Häkchen für gut, Strich für mittel, Warndreieck für niedrig.

## 3. Nach Person: die Spalten lesen

| Spalte | Bedeutung |
|---|---|
| **Anwesend** | Termine, bei denen die Person als anwesend eingetragen ist |
| **Entschuldigt** | abgemeldet mit Grund |
| **Unentschuldigt** | gefehlt ohne Abmeldung |
| **Erfasst** | für wie viele der Termine überhaupt eine Anwesenheit eingetragen wurde |
| **Quote** | Anwesend geteilt durch alle stattgefundenen Pflichttermine, **die für die Person gelten** |

Die Zahl unter dem Seitentitel zählt alle Pflichttermine des Projekts, eine Zeile nur die,
deren Zielgruppe die Person einschließt. Steht oben „17 Pflichttermine“ und in einer
Zeile „16 / 16“, galt ein Termin – etwa eine Registerprobe – für diese Person nicht. Gilt
für eine Person überhaupt kein Termin, steht statt einer Quote ein Strich; sie zählt dann
auch nicht in den Durchschnitt ihrer Stimmgruppe.

Der wichtige Punkt steckt in den letzten beiden Spalten: Die Quote rechnet gegen jeden
geltenden, stattgefundenen Pflichttermin, nicht nur gegen die erfassten. Eine nicht geführte
Anwesenheitsliste ist eine fehlende Angabe — sie darf niemanden als abwesend zählen, aber
auch niemandes Quote schönrechnen. Wie viel tatsächlich erfasst wurde, steht deshalb
daneben in **Erfasst** (z. B. "3 / 4"). Stehen dort durchgehend niedrige Werte, sagt die
Quote wenig aus; dann fehlen Anwesenheitslisten, nicht Sängerinnen und Sänger.

Über die Tabellenleiste durchsuchst und sortierst du die Liste, etwa nach Quote
aufsteigend, um die Ausreißer nach oben zu holen. Die Spalte **Unentschuldigt** ist nur
rot, wenn dort mehr als 0 steht. Am Handy erscheint jede Person als kompakte Karte mit
Name und Quote oben und den vier Zählern darunter.

## Anleitungen

- [Anmelde-Auswertung](evaluations-registrations) – Besetzung je Stimmgruppe und Rücklauf
  der Zu- und Absagen, Termin für Termin
- [Besetzung](evaluations-project-members) – wer im Projekt singt, gruppiert nach
  Stimmgruppe und Teilstimme

## Häufige Stolperfallen

- **Quote 0 % bei allen.** Für das Projekt wurde noch keine Anwesenheit erfasst. Liegen
  dagegen alle Pflichttermine noch in der Zukunft, zeigt die Seite statt der Tabelle einen
  Hinweis – die Auswertung zählt nur stattgefundene Termine.
- **Oben eine andere Terminzahl als in der Zeile.** Kein Fehler: Die Zeile zählt nur die
  Termine, die für die Person gelten (siehe oben).
- **Jemand fehlt in der Liste.** Gezählt werden die Mitglieder des gewählten Projekts.
  Wer dem Projekt nicht zugeordnet ist, taucht auch nicht auf.
- **Zahlen wirken zu gut.** Erst in die Spalte **Erfasst** schauen: Ohne geführte Listen
  bleibt die Quote niedrig, weil der Nenner alle Termine umfasst — umgekehrt heißt eine
  hohe Quote bei wenigen erfassten Terminen nichts.

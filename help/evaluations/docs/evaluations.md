# Anwesenheitsquoten

Die Anwesenheitsquote zeigt je Projekt, wer wie oft bei den Terminen war. Sie beantwortet
die Frage, die vor jedem Auftritt kommt: Wie verlässlich ist die Besetzung, und wo fehlt
regelmäßig jemand?

> **Berechtigung:** Der Menüpunkt **Auswertungen → Anwesenheitsquoten** ist für jedes
> angemeldete Mitglied sichtbar. Welche Projekte in der Auswahl stehen, hängt allerdings
> vom Recht **"Anwesenheit/Anmeldung verwalten (alle Mitglieder)"** ab: Mit diesem Recht siehst du alle
> Projekte, ohne es nur die eigenen. Ein fremdes Projekt lässt sich auch nicht über die
> Adresszeile öffnen.

## 1. Projekt wählen

Klickpfad: **Auswertungen → Anwesenheitsquoten**.

Oben rechts wählst du das Projekt; die Seite lädt sofort neu. Ohne eigene Wahl steht dort
das laufende Projekt, sonst das zuletzt passende. Direkt unter der Auswahl steht, wie
viele Termine das Projekt hat — das ist die Bezugsgröße der ganzen Tabelle.

![Anwesenheitsquoten eines Projekts](images/evaluations/01-attendance-rates.png)

## 2. Die Spalten lesen

| Spalte | Bedeutung |
|---|---|
| **Anwesend** | Termine, bei denen die Person als anwesend eingetragen ist |
| **Entschuldigt** | abgemeldet mit Grund |
| **Unentschuldigt** | gefehlt ohne Abmeldung |
| **Erfasst** | für wie viele der Termine überhaupt eine Anwesenheit eingetragen wurde |
| **Quote** | Anwesend geteilt durch **alle** stattgefundenen Pflichttermine |

Der wichtige Punkt steckt in den letzten beiden Spalten: Die Quote rechnet gegen jeden
stattgefundenen Pflichttermin, nicht nur gegen die erfassten. Eine nicht geführte
Anwesenheitsliste ist eine fehlende Angabe — sie darf niemanden als abwesend zählen, aber
auch niemandes Quote schönrechnen. Wie viel tatsächlich erfasst wurde, steht deshalb
daneben in **Erfasst** (z. B. "3 / 4"). Stehen dort durchgehend niedrige Werte, sagt die
Quote wenig aus; dann fehlen Anwesenheitslisten, nicht Sängerinnen und Sänger.

Über die Tabellenleiste durchsuchst und sortierst du die Liste, etwa nach Quote
aufsteigend, um die Ausreißer nach oben zu holen.

## Anleitungen

- [Anmelde-Auswertung](evaluations-registrations) – Besetzung je Stimmgruppe und Rücklauf
  der Zu- und Absagen, Termin für Termin
- [Projektmitglieder](evaluations-project-members) – wer im Projekt singt, gruppiert nach
  Stimmgruppe und Teilstimme

## Häufige Stolperfallen

- **Quote 0 % bei allen.** Für das Projekt wurde noch keine Anwesenheit erfasst, oder
  seine Termine liegen alle in der Zukunft — die Auswertung zählt nur stattgefundene
  Termine.
- **Jemand fehlt in der Liste.** Gezählt werden die Mitglieder des gewählten Projekts.
  Wer dem Projekt nicht zugeordnet ist, taucht auch nicht auf.
- **Zahlen wirken zu gut.** Erst in die Spalte **Erfasst** schauen: Ohne geführte Listen
  bleibt die Quote niedrig, weil der Nenner alle Termine umfasst — umgekehrt heißt eine
  hohe Quote bei wenigen erfassten Terminen nichts.

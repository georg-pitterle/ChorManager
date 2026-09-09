# Anmelde-Auswertung

Die Anmelde-Auswertung stellt Termin für Termin dar, wie die Besetzung aussieht: wie viele
Zusagen je Stimmgruppe vorliegen und wie viele der eingeladenen Mitglieder überhaupt
geantwortet haben. Sie ist das Werkzeug für die Frage, ob die Besetzung für den Auftritt
reicht.

> **Berechtigung:** Der Menüpunkt **Auswertungen → Anmeldungen** erscheint nur, wenn das
> Anmelde-Modul in dieser Installation freigeschaltet ist. Ein eigenes Recht braucht er
> nicht, der Umfang hängt aber am Recht **"Anwesenheit/Anmeldung verwalten (alle Mitglieder)"**: Damit siehst du
> alle Termine mit Anmeldung, ohne es nur die, für die du selbst eingeladen bist.

## 1. Die Matrix

Klickpfad: **Auswertungen → Anmeldungen**.

Jede Zeile ist ein Termin mit freigeschalteter Anmeldung, jede Stimmgruppen-Spalte zeigt
die Zusagen. Die Zahl in Klammern dahinter sind die Vielleicht-Antworten — sie zählen
nicht als Zusage, sind aber die Reserve, um die es sich zu kümmern lohnt.

![Anmelde-Auswertung mit kommenden Terminen](images/evaluations/02-registrations.png)

- **Zusagen gesamt** — alle Zusagen des Termins über alle Stimmgruppen.
- **Rücklauf** — wie viel Prozent der eingeladenen Mitglieder überhaupt geantwortet haben,
  egal ob zu- oder abgesagt. Ein niedriger Rücklauf heißt: Die Zusagenzahl ist noch nicht
  belastbar.
- **Ohne Stimmgruppe** — Mitglieder, denen keine Stimmgruppe zugeordnet ist. Steht dort
  dauerhaft eine Zahl, fehlt in der Mitgliederverwaltung eine Zuordnung.

Zähler und Bezugsgröße stammen aus derselben Menge: Gezählt wird nur, wer für diesen
Termin eingeladen ist. Ein Termin, der nur eine Stimmgruppe betrifft, verwässert seine
Quote also nicht mit dem Rest des Chors.

## 2. Vergangene Termine einbeziehen

Voreingestellt zeigt die Seite nur kommende Termine. **Auch vergangene** nimmt die
zurückliegenden dazu, **Nur kommende** blendet sie wieder aus.

![Anmelde-Auswertung inklusive vergangener Termine](images/evaluations/03-registrations-past.png)

Erst in dieser Ansicht ist die Spalte **Anwesend (Ist)** gefüllt: Sie steht nur bei
vergangenen Terminen mit Anwesenheitspflicht und nennt die tatsächlich erfasste
Anwesenheit. Der Vergleich mit den Zusagen derselben Zeile zeigt, wie verlässlich die
Anmeldungen im Chor sind. Bei allen übrigen Zeilen steht ein Strich.

## Häufige Stolperfallen

- **"Keine Termine mit freigeschalteter Anmeldung gefunden."** Die Anmeldung wird je
  Termin aktiviert. Ohne diesen Haken taucht ein Termin hier nie auf.
- **Rücklauf 100 %, aber wenige Zusagen.** Alle haben geantwortet, viele davon mit Absage
  — das ist ein belastbares Ergebnis, kein Erfassungsproblem.
- **Vielleicht-Antworten zählen nicht mit.** Die Klammerzahl steht bewusst getrennt und
  fließt weder in "Zusagen gesamt" noch in die Besetzungsplanung ein.

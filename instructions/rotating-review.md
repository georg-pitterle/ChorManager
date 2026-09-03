# Rotierender Code-Review

Ein geplanter Lauf prüft täglich einen Abschnitt der Anwendung. Welcher an der Reihe
ist, entscheidet die Lauf-Nummer in `.claude/rotating-review-state.json` — nicht das
Datum, damit ein ausgefallener Tag oder ein zusätzlicher Handlauf die Reihenfolge
nicht verschiebt.

## Zählerstand nie von Hand schreiben

Lesen und Fortschreiben laufen über `bin/rotating_review_state.php`:

```bash
ddev php bin/rotating_review_state.php section   # Abschnitt des anstehenden Laufs
ddev php bin/rotating_review_state.php read      # ganzer Zustand als JSON
ddev php bin/rotating_review_state.php advance   # Lauf verbuchen, Zähler +1
```

`advance` schreibt den gerade gelaufenen Abschnitt nach `lastRun` und erhöht
`runNumber`. Es gehört an das Ende des Laufs, in denselben Commit wie die Fixes —
oder allein, wenn es nichts zu beheben gab.

Der Grund für das Skript: Das Fortschreiben über das Datei-Werkzeug des Agenten blieb
wiederholt an einer Rückfrage hängen. Bei einem unbeaufsichtigten Lauf ist das ein
Abbruch, und der Zähler bleibt stehen — der nächste Lauf prüft dann denselben
Abschnitt noch einmal, und die Reihe kommt nie durch.

## Abschnitte ändern

Die Liste steht in der Datei unter `sections`, nicht im Skript. Die Vorgabe im Skript
greift nur, solange es die Datei noch nicht gibt. Wird die Liste geändert, richtet
sich die Reihe ab dem nächsten Lauf danach.

---
name: git-commit
description: >
  The complete way work lands in git in ChorManager: which checks must have run, what gets
  staged, how the German commit message is built (subject rule, reasoning body, evidence
  section, trailers), and how a feature branch reaches main (squash, fetch/rebase,
  fast-forward merge, branch cleanup, push only after asking). Use this skill whenever a
  commit, squash, rebase or merge is asked for or is about to happen — "commit", "commit
  auf main", "committe das", "einchecken", "commit message", "Commit schreiben",
  "Änderungen festhalten", "zusammenfassen", "squash", "nach main bringen", "mergen",
  "mach das fertig", "abschließen" — and also when unsure whether a change is ready.
  A short instruction like "commit auf main" means the whole workflow below, not just
  `git commit`. This skill defines that gate; do not write a commit message or move work
  onto main in this repository without it.
---

# Commit, Squash und Merge in ChorManager

Eine Commit-Nachricht ist hier kein Protokoll der Änderung — das steht im Diff. Sie hält
fest, was der Diff nicht zeigen kann: warum es überhaupt kaputt war, warum dieser Weg und
nicht der naheliegende daneben, und womit belegt ist, dass es jetzt stimmt. Wer das in
einem Jahr liest, hat nur diesen Text.

`main` ist linear. Ein abgeschlossenes Stück Arbeit ist dort ein Commit, keine Kette aus
Zwischenständen und kein Merge-Knoten. Deshalb wird vorher zusammengefasst und obendrauf
gesetzt statt gemergt.

## Wo stehe ich?

Erster Schritt ist immer `git status` und `git branch --show-current`. Daraus ergibt sich
der Ablauf:

| Lage | Ablauf |
|---|---|
| auf `main`, Arbeit im Baum | **A** — Prüfläufe, committen, Abgleich, Push-Rückfrage |
| auf `feature/…` oder `claude/…`, Ziel ist `main` | **B** — Prüfläufe, committen, squashen, rebasen, per Fast-Forward auf `main`, Push-Rückfrage |
| auf einem Branch, der Branch bleibt | nur Abschnitt 1–5, danach stehenbleiben |

"commit auf main" heißt Ablauf **A** oder **B**, je nachdem wo der Kopf steht — nicht
`git commit` allein. Steht der Kopf auf einem Branch, ist damit **B** gemeint.

---

## 1. Was gelaufen sein muss

| Berührt | Befehl |
|---|---|
| PHP in `src/` | `ddev composer phpcs` (bei Verstößen `phpcbf`, dann erneut) |
| Templates | `ddev composer twigcs` und `ddev composer twig:eol` |
| beliebiger Code | während der Arbeit `ddev php vendor/bin/phpunit --filter "<Muster>"`, einmal am Schluss `ddev composer test:parallel` (enthält `eol:check`) |
| Schema | `ddev exec ./vendor/bin/phinx migrate` |
| neue persistierte Entität | `ddev php bin/dev_seed.php`, danach die neuen Zähler im Bericht prüfen |

Gefiltert laufen lassen spart Nutzung; die volle Suite genau einmal am Ende. Wichtig ist
nur, dass die Zahlen in der Nachricht aus einem echten Lauf stammen — "grün" aus dem
Gedächtnis ist eine Behauptung, kein Beleg.

Die Zeilenenden macht `.githooks/pre-commit` selbst (`normalize_lf_staged.php`,
`check_lf_repo.php`). Bricht der Hook mit "Neither 'php' nor 'ddev' is available" ab, lag
es an der Shell, nicht an den Dateien — aus einer Umgebung mit `ddev` im Pfad erneut
committen, den Hook nie mit `--no-verify` umgehen.

**Schärfeprobe.** Wacht ein neuer Test über eine Regel, wird der Produktivcode gezielt
sabotiert: Der Test muss rot werden und nach der Rücknahme wieder grün. Ein Test, der auch
mit kaputtem Code grün bleibt, bewacht nichts — und das fällt nur so auf. Das Ergebnis
gehört in die Nachricht.

## 2. Staging

`git status` und `git diff --cached` ansehen, bevor committet wird. Nicht blind
`git add -A`: Im Arbeitsbaum liegt oft Fremdes — `settings.local.json`, Notizen, Reste
eines anderen Versuchs. `vendor/`, `public/vendor/` und `node_modules/` sind ignoriert;
tauchen sie auf, stimmt etwas nicht.

Ein Commit trägt einen Gedanken. Review-Befunde und Feature-Arbeit gehören auseinander,
auch wenn sie im selben Lauf entstanden sind.

## 3. Betreffzeile

Deutsch, höchstens 72 Zeichen, kein Punkt am Ende, echte Umlaute.

**Mit Präfix**, wenn die Änderung in einem klar abgegrenzten Bereich sitzt —
`feat`, `fix`, `refactor`, `perf`, `build`, `docs`, `chore`, `test`:

```
fix(attachments): PDF-Vorschau am Handy und Dateiname im Anhang-Dropdown
refactor(queries): Projektlisten einheitlich sortiert, toten Stimmgruppen-Filter entfernt
build(docker): Frontend-Pakete einmal bauen statt je Architektur
```

**Als freier deutscher Satz**, wenn die Arbeit quer über Bereiche läuft oder aus einem
Review-Lauf stammt — dort trägt der Betreff Abschnitt und Lauf-Nummer:

```
Code-Review src/Queries (Lauf 11): Mitgliederliste ohne ungenutzte Eager-Loads
Antworten aus dem Review-Lauf 10: Wiedervorlage darf auch die zuständige Person abhaken
Private Notizen bei Aufgabe und Projekt verbergen, Rechteliste und Casts aufgeräumt
```

Der Betreff benennt das Ergebnis, nicht die Tätigkeit: "Mitgliederliste ohne ungenutzte
Eager-Loads", nicht "Eager-Loads angepasst".

## 4. Körper

Fließtext, umgebrochen bei ~78 Zeichen, Leerzeile zwischen Absätzen. Mehrere unabhängige
Punkte als `1)`, `2)` … oder `-`. Bei größeren Änderungen Zwischenüberschriften mit einer
`---`-Unterstreichung.

Die Reihenfolge folgt dem, was der Leser wissen will:

1. **Anlass** — was war falsch, wie zeigte es sich, warum fiel es bisher nicht auf
2. **Entscheidung** — was jetzt passiert und warum so
3. **Verworfene Alternative** — lag ein anderer Weg nahe, wird er benannt und begründet
   abgelehnt. Genau das lässt sich später aus keinem Diff zurückholen.
4. **Nebenbei** — Kleinigkeiten, die mitgingen
5. **Tests** — neue oder geänderte Dateien beim Namen, und was sie festhalten
6. **Seed** — was ergänzt wurde, oder warum nichts nötig war
7. **Beleg** — Läufe mit Zahlen
8. **Hinweis für den Betrieb** — wenn beim Ausrollen ein Handgriff nötig ist

Zahlen statt Adjektiven. "Knopfpaar 335px zu 63px, Dateiname 0px zu 110px" trägt, "sieht
jetzt richtig aus" trägt nicht. Wo gemessen wurde, wird die Messung gezeigt.

### Beleg-Abschnitt

Am Ende, eingeleitet mit `Ausgeführt:`, `Geprüft:`, `Belegt:` oder als Abschnitt `Läufe`:

```
Ausgeführt: phpunit (2416 Tests, 11632 Assertions, grün), phpcs (216 Dateien, keine
Beanstandung), twigcs (keine Verletzung), bin/check_lf_repo.php, dev_seed
(mode=append, status=ok, user_voice_groups 140).
```

Was **nicht** lief, gehört dazu, mit Grund: "Keine Twig-Änderung, daher kein twigcs. Kein
neues Schema und keine neue Entität, daher keine Seed-Daten." Eine fehlende Zeile liest
sich sonst wie ein übersprungener Schritt.

### Trailer

Leerzeile, dann die `Co-Authored-By:`-Zeile in der Fassung, die diese Sitzung vorgegeben
bekommen hat. Keine Emoji, keine Werbezeile. Ist die Sitzungs-URL bekannt, darf
`Claude-Session: <url>` daruntergesetzt werden.

### Gerüst

```
fix(<bereich>): <was jetzt stimmt, deutsch, höchstens 72 Zeichen>

<Was falsch war und wie es sich zeigte. Warum es unbemerkt blieb.>

<Was jetzt passiert und warum dieser Weg. Welcher naheliegende Weg
verworfen wurde und weshalb.>

Neu: tests/Feature/<Name>FeatureTest hält <Regel> fest.

Seed: <Ergänzung> — oder: keine Änderung nötig, weil <Grund>.

Ausgeführt: phpunit (<n> Tests, <n> Assertions, grün), composer phpcs
(<n> Dateien, keine Verstöße), <weitere Läufe>. <Was nicht lief und warum.>

Schärfeprobe: <Sabotage> läuft rot, nach der Rücknahme wieder grün.

Co-Authored-By: <Vorgabe der Sitzung>
```

## 5. Committen

```bash
git commit -F <datei>
```

Die Nachricht über eine Datei übergeben, nicht über mehrere `-m`. Nur so bleiben Absätze,
Einrückung und Umlaute unversehrt; aneinandergereihte `-m` erzeugen Absatzbrei.

Der Umlaut-Hook prüft die Nachricht mit. Weist er ab, stehen transliterierte Wortstämme
darin — sie werden durch echte Umlaute ersetzt, nicht umgangen.

---

## 6. Abgleich mit `origin/main`

Vor jedem Landen auf `main`:

```bash
git fetch origin
git status -sb          # zeigt "ahead/behind"
```

Ist `origin/main` voraus, wird die eigene Arbeit per Rebase daraufgesetzt
(`git rebase origin/main`), nicht gemergt. Ein `git pull` ohne `--rebase` hinterlässt die
"Merge branch 'main' of github.com…"-Knoten, die die Historie unlesbar machen — davon
stehen schon genug drin.

Zwei Regeln dazu:

- **Konflikt heißt anhalten.** Konflikte auflösen, wenn die Absicht beider Seiten klar
  ist; sonst `git rebase --abort` und den Stand melden. Nie raten, nie eine Seite pauschal
  bevorzugen.
- **Nach einem Rebase mit neuen fremden Commits läuft die Suite erneut.** Die Kombination
  aus fremder und eigener Änderung hat vorher niemand getestet; der grüne Lauf von vorhin
  gilt für einen Stand, den es nicht mehr gibt. Das Ergebnis kommt in die Meldung.

## 7. Feature-Branch nach `main` (Ablauf B)

```bash
git fetch origin
git rebase origin/main                                 # eigene Commits obendrauf
git reset --soft $(git merge-base HEAD origin/main)    # alles zu einem Stand
git commit -F <datei>                                  # eine Nachricht nach Abschnitt 3-4
git switch main
git merge --ff-only <branch>
git branch -d <branch>
```

Alternativ macht `/squash-branch` das Zusammenfassen; der Rest bleibt gleich.

Warum so:

- **`--ff-only`** ist die Sicherung. Läuft es durch, war `main` wirklich ein Vorfahre und
  die Historie bleibt linear. Bricht es ab, ist `main` inzwischen weitergezogen — dann
  zurück zu Abschnitt 6 und erneut rebasen, **nie** auf einen Merge-Commit ausweichen.
- **`git branch -d`**, nicht `-D`. Das kleine `d` verweigert das Löschen, solange etwas
  nicht in `main` steckt — genau die Warnung, die man an dieser Stelle will.
- **Eine Nachricht für die ganze Arbeit.** Die Zwischenstände waren Arbeitsschritte, keine
  Aussagen. Waren es mehrere unabhängige Punkte, werden sie im Körper zu `1)`, `2)`, `3)`
  statt zu drei Commits.

**Umgeschrieben wird nur, was noch niemand hat.** Vor dem Squash prüfen, ob der Branch
einen Upstream hat (`git rev-parse --abbrev-ref "@{upstream}"`). Ist er gepusht — die
`claude/…`-Zweige sind es —, erst fragen. Gepushte Historie umzuschreiben bricht jedem
anderen Checkout das Genick, und `--force` bleibt in jedem Fall aus dem Spiel.

## 8. Push

Nach dem Landen einmal fragen: **"Nach `origin/main` pushen?"** Bei Ja:

```bash
git push origin main
```

Nur diese Form. Kein `--force`, kein `--force-with-lease`, kein Push eines Zweigs, den
niemand angefordert hat, und kein Push, solange irgendein Prüflauf rot oder ungelaufen
ist. Bei Nein oder ohne Antwort: stehenbleiben und melden.

Die Rückfrage ist die einzige Stelle, an der ein Push überhaupt zur Debatte steht — die
Voraussetzungen stehen in `instructions/git-push-guard.md`. Der unbeaufsichtigte
Review-Lauf hat dort seine eigene Ausnahme und fragt niemanden.

## 9. Melden

Nach dem Commit: Branch, Kurz-Hash, Betreff, was geprüft wurde.
Nach einem Merge zusätzlich: welcher Zweig zusammengefasst wurde, wie viele Commits daraus
einer wurden, ob `main` vorher rebasiert werden musste, ob der Zweig gelöscht ist und ob
gepusht wurde.

## Häufige Fehler

| Fehler | Warum er weh tut |
|---|---|
| transliterierte Umlaute in Nachricht oder Code | Der Hook `.claude/hooks/check-german-umlauts.sh` weist den Commit ab. Echte `ä ö ü ß`; muss ein Wert technisch ASCII bleiben, trägt **dieselbe** Zeile den Marker `naming:ascii`. |
| "alle Tests grün" ohne Zahlen | Nicht nachprüfbar, und meist aus dem Gedächtnis geschrieben statt aus einem Lauf. |
| Beleg-Abschnitt aus der Erinnerung gefüllt | Der Lauf gehört **vor** die Nachricht. |
| `git merge` ohne `--ff-only` | Erzeugt still den Merge-Knoten, den `--ff-only` gemeldet hätte. |
| `git pull` statt `fetch` + `rebase` | Hinterlässt "Merge branch 'main' of github.com…" in der Historie. |
| Squash eines gepushten Zweigs ohne Rückfrage | Schreibt Historie um, die andere schon haben. |
| `--no-verify` | Umgeht den LF-Wächter; die falschen Zeilenenden fallen erst später jemand anderem auf. |
| Englischer Betreff | Nachrichten sind Inhalt, also deutsch — englisch bleiben nur Bezeichner im Text. |
| Ein Commit für Feature und Review-Befunde | Später nicht mehr einzeln zurückzunehmen. |
| Push ohne Rückfrage | Der Schritt gehört dem Entwickler. |

---
name: writing-tests
description: >
  How a test for ChorManager is chosen, placed and written so that it guards a rule, stays
  fast and survives the parallel run: which tests are not worth writing (existence checks,
  framework behaviour, duplicates), which level fits (Unit, Feature, tests/js, guard over
  files, E2E), the database and environment isolation every test needs, time budgets, naming
  and the sabotage check. Use this skill whenever a test is about to be written, extended or
  reviewed — "schreib Tests", "Test dazu", "TDD", "teste das", "Testabdeckung", "fehlt da ein
  Test", "Test ist langsam", "braucht es den Test", "Tests aufräumen" — and before the first
  test of any new feature or bugfix, also when nobody asked for tests explicitly, because
  AGENT.md requires them. Not for running tests (`/test-runs`) and not for Playwright
  scenarios (`/e2e-scenario`).
---

# Passende Tests für ChorManager

Ein Test ist hier ein Wächter über eine Regel. Er ist so viel wert, wie er rot wird, wenn
jemand die Regel bricht — und er kostet bei jedem Lauf Zeit, bei jeder Umbenennung Pflege.
Die Suite hat rund 5800 Tests und läuft parallel in etwa 15 Sekunden; das bleibt nur so,
wenn jeder neue Test seinen Platz verdient und billig ist.

## Die eine Frage vor jedem Test

> Welche falsche Änderung am Produktivcode macht diesen Test rot — und macht sie nicht
> schon ein anderer Test rot?

Fällt als Antwort nur „jemand löscht die Klasse“ ein, wird der Test nicht geschrieben.
Lässt sich die Regel nicht in einem Satz sagen, ist noch nicht klar, was geprüft wird.

## Was nicht geschrieben wird

| Nicht schreiben | Warum | Stattdessen |
|---|---|---|
| `assertTrue(class_exists(…))`, `method_exists(…)`, `file_exists(…)` auf feste Namen, „…StructureExists“ | Jeder Test, der die Sache benutzt, deckt das mit ab. 35 solcher Methoden wurden im November 2026 gestrichen. | Die Sache aufrufen und das Ergebnis prüfen. |
| Tests für Eloquent, Twig, Slim, PHP selbst (Getter liefert gesetzten Wert, Relation ist eine Relation) | Prüft fremden Code. | Nur das eigene Verhalten darauf: Reihenfolge, Scope, Cast, Sichtbarkeit. |
| Dieselbe Regel auf drei Ebenen (Policy, Controller, Template) | Dreifache Pflege, kein zusätzlicher Schutz. | Die Regel dort prüfen, wo sie entschieden wird; darüber höchstens ein Test, dass die Stelle angeschlossen ist. |
| Quelltext nach Zeichenketten durchsuchen, wo sich Verhalten prüfen lässt | Bricht bei jeder Umformulierung, bleibt grün bei falschem Verhalten. | Rendern oder aufrufen und die Ausgabe prüfen. |
| Tests, die nach ihrem Anlass heißen (`ReviewAnswersRun35Test`) | Niemand findet die Regel wieder. | Nach der Regel benennen und zur passenden Klasse legen. |
| Je Eingabewert eine eigene Methode | Aufblähung. | `#[DataProvider]` mit benannten Fällen. |

Ein `assertFalse(method_exists(…))` für etwas bewusst Entferntes ist dagegen ein echter
Wächter und bleibt — mit Kommentar, warum es nicht zurückkommen darf. Über die
Existenz-Zusicherungen auf feste Klassen- und Methodennamen wacht
`tests/Unit/TestSuite/NoExistenceAssertionsTest`.

**Quelltext-Wächter sind erlaubt**, wenn die Regel über viele Dateien gilt und sich nicht
durch Aufrufen prüfen lässt: „jede Migration endet mit `create()`/`save()`/`update()`“,
„kein `password_hash()` außerhalb des Hashers“. Sie liegen unter `tests/Unit/` (etwa
`Migrations/`, `TestSuite/`), lesen nur Dateien, laufen über einen DataProvider und
erklären im Klassenkommentar, welcher Vorfall sie ausgelöst hat.

## Welche Ebene

| Was geprüft wird | Wohin | Datenbank |
|---|---|---|
| Reine Logik: Util, Policy, Formatter, Wertobjekt | `tests/Unit/<Bereich>/…Test.php` | nein |
| Dienst, Query, Controller, gerenderte Seite, Migrationsschutz | `tests/Feature/…FeatureTest.php` | ja, in Transaktion |
| Logik in `public/js/*.js` | `tests/js/<name>.test.mjs` (`node --test`, läuft über `npm run test:js`) | nein |
| Regel über viele Dateien | Quelltext-Wächter, siehe oben | nein |
| Ablauf durch die echte Oberfläche | `/e2e-scenario` — nur auf ausdrückliche Anforderung | eigene |

Die niedrigste Ebene nehmen, auf der die Regel sichtbar wird. Eine Berechnung braucht keine
Seite, eine Rechteprüfung keinen Browser.

## Schnell bleiben

Richtwerte je Test: Unit unter 5 ms, Feature unter 50 ms. Ab 100 ms braucht ein Test eine
Begründung im Kommentar. Was die Suite bisher teuer gemacht hat:

- **Kein DDL je Test.** `CREATE`/`DROP TABLE` kostet rund 10 ms pro Anweisung. Braucht eine
  Klasse Wegwerf-Tabellen, entstehen sie in `setUpBeforeClass()` und werden zwischen den
  Tests geleert (Vorbild: `DestructiveMigrationGuardTest`).
- **Kein Netz.** Kein HTTP, kein SMTP, kein IMAP gegen echte Adressen. Dienste nehmen ihren
  Abruf oder Verbinder als Closure entgegen (`OfficeDiscovery`, `MailBadgeService`) — der
  Test reicht eine eigene herein.
- **Nicht an der `.env` der Entwicklung hängen.** Ein Test, der ein Feature-Flag braucht,
  setzt es in seinem `setUp()` selbst und stellt es im `tearDown()` zurück — in `$_ENV`,
  `$_SERVER` und per `putenv()`, alle drei.
- **Nicht warten.** Kein `sleep()`, kein echter Timeout. Zeit über `Carbon::setTestNow()`,
  Fristen über einen kleinen Parameter. Lässt sich ein echter Timeout nicht vermeiden,
  genau ein Test dafür, mit dem kleinsten zulässigen Wert.
- **Subprozess nur, wenn die Prozessgrenze das Thema ist** (CLI-Skript, Node für JS). Jeder
  Start kostet 50–100 ms; die Aufrufe im Test zählen.
- **Passwörter über `PasswordHasher::hash()`**, nie `password_hash()`.
- **Nur aufbauen, was der Test braucht.** Den Controller von Hand mit den nötigen Diensten
  bauen; den ganzen Container (`ContainerBuilder` + `Dependencies.php`) nur, wenn die
  Verdrahtung selbst geprüft wird. Zwei Personen statt zwanzig.
- **Controller über `Tests\Feature\BuildsControllers` bauen**, nie mit `new`, wenn er in
  `ControllersAreBuiltThroughBuildersTest::MIGRATED` steht:
  `$this->eventController(['view' => $twig, 'logger' => $logger])`. Die Schlüssel sind die
  Parameternamen des Konstruktors, Pflichtparameter ohne Eintrag bekommen eine Vorgabe, `view`
  nennt jeder Test selbst. Ein neuer Pflichtparameter im Konstruktor ist dort eine Zeile,
  nicht eine Änderung an jeder Testdatei. Ein weiterer Controller wird beim nächsten Anfassen
  umgestellt und in die Liste aufgenommen. Eine gleichnamige private Hilfsmethode in der
  Testklasse überdeckt den Baustein - dann die Hilfsmethode streichen oder umbenennen.

## Isolation

Die Suite läuft parallel in vier Prozessen mit je eigener Datenbank; am Ende zählt ein
Wächter, ob Zeilen übrig sind. Daraus folgt für jeden Test mit Datenbank:

```php
final class TaskReminderSkipsArchivedMembersFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $_SESSION = [];
        parent::tearDown();
    }
}
```

- Verbindung nur über `Tests\Unit\Bootstrap`; kein eigenes Schema, kein SQLite
  (`NoHandBuiltSchemaTest` wacht darüber).
- Eindeutige Werte (Name, E-Mail) mit `bin2hex(random_bytes(4))` als Anhängsel.
- Alles, was der Test voraussetzt — `$_SESSION`, Umgebungsvariable, Twig-Funktion —, setzt
  er selbst. Was eine andere Klasse „schon gesetzt hat“, gilt im parallelen Lauf nicht.
- Dateien nur unter `sys_get_temp_dir()`, im `tearDown()` wieder weg. Nie nach `var/`.
- Vorhandene Bausteine nutzen statt neu bauen: `TestHttpHelpers`, `TwigViewStubs`,
  `FileFixtures`, `AudienceFixtures`, `PollFixtures`, `OfficeFixtures`, `BuildsControllers`.

## Schreiben

- **Erst rot.** Test vor der Umsetzung, und er muss aus dem richtigen Grund scheitern.
- **Hauptweg, Fehlerweg, Rand.** Bei Rechten immer auch: wer es *nicht* darf.
- **Beobachtbares prüfen:** Zeile in der Datenbank, Statuscode und `Location`, das eine
  Stück HTML, die Protokollzeile. `assertSame` vor `assertTrue`; nie die ganze Seite
  vergleichen.
- **Namen:** Datei und Methode englisch und als Aussage der Regel
  (`testRoleWithRightsTheActorLacksIsNotAssigned`), Klassenkommentar deutsch: die Regel in
  einem Satz, dann der Vorfall oder Grund. Kommentare mit echten Umlauten.
- **Ein Gedanke je Methode.** Mehrere Zusicherungen sind in Ordnung, solange sie dieselbe
  Regel belegen.

## Schärfeprobe

Bevor der Test als fertig gilt: den Produktivcode gezielt sabotieren (Bedingung umdrehen,
Zeile auskommentieren). Der Test muss rot werden und nach der Rücknahme wieder grün. Bleibt
er grün, bewacht er nichts und wird umgeschrieben oder gestrichen. Das Ergebnis gehört in
die Commit-Nachricht.

## Vor dem Abschluss

1. Regel in einem Satz im Klassenkommentar?
2. Niedrigste passende Ebene, kein Doppel zu einem vorhandenen Test (`Grep` nach der
   Methode oder Route unter `tests/`)?
3. `ddev php vendor/bin/phpunit --filter "<Klasse>"` grün — und die Zeit durch die Zahl der
   Tests liegt im Richtwert?
4. Schärfeprobe gemacht?
5. Einmal `ddev composer test:parallel` am Schluss (`/test-runs`).

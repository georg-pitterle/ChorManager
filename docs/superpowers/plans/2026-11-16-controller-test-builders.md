# Controller in Tests über Bausteine bauen – Umsetzungsplan

> **Für agentische Bearbeiter:** REQUIRED SUB-SKILL: `superpowers:subagent-driven-development`
> (empfohlen) oder `superpowers:executing-plans`. Der Plan wird Task für Task abgearbeitet.
> Schritte nutzen Checkbox-Syntax (`- [ ]`); nach jedem Task werden seine Kästchen im selben
> Commit abgehakt (Regel aus `AGENT.md`, Abschnitt "Pläne").

**Ziel:** Tests bauen zwölf Controller nicht mehr mit `new XController(...)` und festen
Positionsargumenten, sondern über eine Methode je Controller, die Abhängigkeiten beim Namen
nimmt und für den Rest Vorgaben einsetzt. Danach lässt sich ein Konstruktor an genau einer
Stelle in den Tests nachziehen.

**Architektur:** Ein Trait `Tests\Feature\BuildsControllers` mit einer Methode je Controller
(`eventController(array $with = [])`). Die Schlüssel von `$with` sind die Parameternamen des
Konstruktors; übergeben wird per benannten Argumenten. Ein Quelltext-Wächter verbietet
`new XController(` in Tests für jeden schon umgestellten Controller; seine Liste wächst Task
für Task.

**Tech-Stack:** PHP 8.5, PHPUnit über DDEV.

**Spec:** Keine eigene Spec-Datei. Anlass ist die Schichtprüfung vom 16.11.2026: 179 Stellen
in 109 Testdateien bauen diese zwölf Controller mit Positionsargumenten. Dadurch ist die
Parameterreihenfolge eine Schnittstelle, neue Abhängigkeiten müssen optional sein und hinten
stehen, bekommen einen Rückfall `?? new Foo()` im Konstruktor, und weil PHP-DI optionale
Parameter nicht füllt, braucht jeder davon einen Handeintrag in `src/Dependencies.php`. Dieser
Plan beseitigt die Ursache in den Tests. Er ist die Voraussetzung für
`docs/superpowers/plans/2026-11-16-controller-layering.md`.

| Controller | Dateien | Baustellen | Task |
|---|---|---|---|
| `EventController` | 14 | 35 | 1 |
| `UserController` | 16 | 21 | 2 |
| `SponsorController` | 14 | 22 | 3 |
| `TaskController` | 10 | 15 | 4 |
| `FinanceController` | 11 | 12 | 4 |
| `RegistrationController` | 9 | 15 | 5 |
| `AttendanceController` | 4 | 8 | 5 |
| `EvaluationController` | 8 | 14 | 5 |
| `ProfileController` | 11 | 15 | 6 |
| `PasswordResetController` | 6 | 11 | 6 |
| `DashboardController` | 3 | 6 | 6 |
| `VoiceGroupController` | 3 | 5 | 6 |

Die Zahlen gelten für Commit `71471740`; maßgeblich ist, was die Suche in Schritt 1 des
jeweiligen Tasks findet.

## Globale Vorgaben

- **Kein Produktivcode.** Dieser Plan ändert nur Dateien unter `tests/` und eine Skill-Datei.
  Kein Konstruktor, kein Eintrag in `src/Dependencies.php` wird angefasst.
- **Kein Test ändert seine Aussage.** Jeder Controller bekommt nach der Umstellung genau die
  Objekte, die er vorher bekam. Die Zahl der Tests und Zusicherungen der vollen Suite ist am
  Ende um genau die neuen Tests dieses Plans größer (Task 1 hält den Ausgangswert fest).
- **Umstellregel je Baustelle:**
  1. Jedes Positionsargument wird zum benannten Eintrag in `$with`, mit dem Parameternamen aus
     dem Konstruktor.
  2. Ein Argument entfällt nur, wenn es **buchstäblich** der Vorgabe des Bausteins entspricht
     und frisch gebaut wird (`new NameFormatterService()`, `new NullLogger()`). Variablen
     (`$logger`, `$this->policy`), Stubs und alles andere bleiben als Eintrag stehen – auch
     wenn der Wert gleich aussieht, denn der Test nutzt die Instanz womöglich weiter.
  3. Ein ausdrücklich übergebenes `null` bleibt als `'name' => null` stehen.
  4. Argumente, die der Test bisher wegließ, bleiben weggelassen.
- **Vorgaben nur für Pflichtparameter.** Ein Parameter mit PHP-Vorgabewert bekommt keine
  Vorgabe im Baustein; lässt der Test ihn weg, gilt der PHP-Vorgabewert wie bisher. `view`
  bekommt nie eine Vorgabe: Jeder Test entscheidet selbst, welches Twig er braucht.
- **Vorgabe = häufigster Ausdruck.** Für einen Pflichtparameter gilt als Vorgabe der
  Ausdruck, der an den meisten Baustellen steht, sofern er ohne Variablen des Tests auskommt.
  Gibt es keinen solchen, bekommt der Parameter keine Vorgabe und jeder Test nennt ihn.
- **Testläufe** (`/test-runs`): nach jedem Task die geänderten Testklassen gefiltert, die
  volle Suite `ddev composer test:parallel` nur in Task 1 (Ausgangswert) und Task 7.
- **Bezeichner englisch, Texte deutsch mit echten Umlauten**, Zeilenenden LF
  (`instructions/naming.md`, `instructions/line-endings.md`).
- **Git:** Branch `test/controller-builders` (in Task 1 von `main` abzweigen). Ein Commit je
  Task nach `/git-commit`, Abschnitte 1 bis 5. Kein Squash, kein Merge, kein `git push` – das
  entscheidet Georg nach Task 7.

## Review-Schwerpunkte

1. **Vertippter Schlüssel.** `['loger' => $handler]` darf nicht still zur Vorgabe werden –
   der Test liefe grün und prüfte nichts. Erwartet: Ausnahme mit dem falschen Namen. Test in
   Task 1.
2. **Ausdrückliches `null`.** `['notificationService' => null]` muss `null` bleiben und darf
   nicht durch eine Vorgabe ersetzt werden. Test in Task 1.
3. **Geteilte Instanz geht verloren.** Ein Test reicht denselben `$logger` an Controller und
   Dienst und liest danach dessen Einträge; wird das Argument als "gleich der Vorgabe"
   gestrichen, bekommt der Controller einen anderen. Dagegen steht Umstellregel 2; geprüft
   wird es durch den Lauf der betroffenen Tests in jedem Task.
4. **Baustelle im Hilfsmethoden-Rumpf oder als Ausdruck.** `return new EventController(...)`
   in einer privaten Methode und `(new EventController(...))->index(...)` sind genauso
   Baustellen wie die Zuweisung im `setUp()`. Der Wächter findet alle drei Formen.
5. **Vollqualifizierter Name.** `new \App\Controllers\EventController(` entgeht einer Suche
   nach `new EventController(`. Der Wächter sucht beide Schreibweisen.

---

### Task 1: Baustein, Wächter und `EventController`

**Dateien:**
- Anlegen: `tests/Feature/BuildsControllers.php`
- Anlegen: `tests/Feature/BuildsControllersTest.php`
- Anlegen: `tests/Unit/TestSuite/ControllersAreBuiltThroughBuildersTest.php`
- Ändern: 14 Testdateien mit `new EventController(`

**Schnittstellen:**
- Liefert den Trait, die private Methode `buildController()` und `eventController()`. Jeder
  weitere Task ergänzt Methoden nach demselben Muster und hängt seinen Controller an
  `ControllersAreBuiltThroughBuildersTest::MIGRATED` an.

- [x] **Schritt 1: Branch und Ausgangswert.**

```bash
git switch -c test/controller-builders
ddev composer test:parallel
```

Zahl der Tests und Zusicherungen unter diesem Schritt notieren.

- [x] **Schritt 2: Trait schreiben.**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Baut Controller für Tests: Abhängigkeiten beim Namen, der Rest aus Vorgaben.
 *
 * Bis November 2026 bauten 109 Testdateien zwölf Controller mit festen
 * Positionsargumenten. Die Parameterreihenfolge war damit eine Schnittstelle:
 * Eine neue Abhängigkeit musste optional sein und hinten stehen, bekam einen
 * Rückfall `?? new Foo()` im Konstruktor und - weil PHP-DI optionale Parameter
 * nicht füllt - einen Handeintrag in Dependencies.php.
 *
 * Die Schlüssel von `$with` sind die Parameternamen des Konstruktors. Eine
 * Vorgabe gibt es nur für Pflichtparameter und nie für `view`. Bekommt ein
 * Konstruktor einen neuen Pflichtparameter, wird hier eine Zeile ergänzt -
 * nicht jede Testdatei.
 */
trait BuildsControllers
{
    /** @param array<string, mixed> $with */
    protected function eventController(array $with = []): EventController
    {
        return $this->buildController(EventController::class, $with, [
            'nameFormatter' => static fn (): NameFormatterService => new NameFormatterService(),
            'logger' => static fn (): LoggerInterface => new NullLogger(),
            'projectQuery' => static fn (): ProjectQuery => new ProjectQuery(new NameFormatterService()),
        ]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $with
     * @param array<string, \Closure(): mixed> $defaults
     * @return T
     */
    private function buildController(string $class, array $with, array $defaults): object
    {
        $known = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod($class, '__construct'))->getParameters()
        );

        $unknown = array_diff(array_keys($with), $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s kennt keinen Konstruktor-Parameter %s.',
                $class,
                implode(', ', $unknown)
            ));
        }

        $arguments = $with;
        foreach ($defaults as $name => $default) {
            if (!array_key_exists($name, $arguments)) {
                $arguments[$name] = $default();
            }
        }

        return new $class(...$arguments);
    }
}
```

Die drei Vorgaben des `EventController` sind geprüft: 33 bis 35 der 35 Baustellen übergeben
genau diese Ausdrücke.

- [x] **Schritt 3: Tests für den Baustein schreiben** (`BuildsControllersTest`, ohne
  Datenbank, nutzt den Trait und einen Twig-Stub über `$this->createStub(Twig::class)`):
  - `testAMistypedParameterNameIsRejected` (Review-Schwerpunkt 1):
    `$this->eventController(['view' => $twig, 'loger' => new NullLogger()])` wirft
    `InvalidArgumentException`, deren Text `loger` enthält.
  - `testAnExplicitNullIsNotReplacedByADefault` (Review-Schwerpunkt 2): Aufruf mit
    `'notificationService' => null`; die per `ReflectionProperty` gelesene Eigenschaft
    `notificationService` ist `null`.
  - `testAGivenCollaboratorIsTheOneTheControllerHolds`: ein eigener `NullLogger` als
    `'logger'`; die Eigenschaft `logger` ist `assertSame` mit dieser Instanz.

- [x] **Schritt 4: Wächter schreiben.**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\TestSuite;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tests bauen die unten genannten Controller über Tests\Feature\BuildsControllers,
 * nie mit `new`.
 *
 * Jede Baustelle mit Positionsargumenten macht die Parameterreihenfolge des
 * Konstruktors wieder zur Schnittstelle - mit den Folgen, die im Kommentar des
 * Traits stehen. Die Liste nennt die umgestellten Controller; für alle anderen
 * gilt die Regel noch nicht.
 */
final class ControllersAreBuiltThroughBuildersTest extends TestCase
{
    /** @var list<string> */
    private const MIGRATED = [
        'EventController',
    ];

    public function testMigratedControllersAreNotConstructedByHand(): void
    {
        $tests = dirname(__DIR__, 2);
        $pattern = '/new\s+\\\\?(?:App\\\\Controllers\\\\)?(' . implode('|', self::MIGRATED) . ')\s*\(/';
        $found = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tests, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($tests) + 1));
            $isHere = $relative === 'Unit/TestSuite/ControllersAreBuiltThroughBuildersTest.php';
            if ($file->getExtension() !== 'php' || $isHere || str_starts_with($relative, 'e2e/')) {
                continue;
            }

            if (preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches) > 0) {
                $found[] = $relative . ': ' . implode(', ', array_unique($matches[1]));
            }
        }

        sort($found);
        self::assertSame([], $found, "Von Hand gebaute Controller:\n" . implode("\n", $found));
    }
}
```

`BuildsControllers.php` ist nicht ausgenommen und muss es nicht sein: Der Trait baut über
`new $class(...)`, nie über einen ausgeschriebenen Klassennamen.

- [x] **Schritt 5: Rotlauf.**
  `ddev php vendor/bin/phpunit --filter "BuildsControllersTest|ControllersAreBuiltThroughBuildersTest"`
  → die drei Baustein-Tests grün, der Wächter rot mit 14 Dateien.

- [x] **Schritt 6: Baustellen umstellen.** Suche:

```bash
grep -rnE "new \\\\?(App\\\\Controllers\\\\)?EventController[(]" tests
```

  Jede betroffene Testklasse bekommt `use BuildsControllers;` (neben `use TestHttpHelpers;`,
  gleicher Namensraum) und baut nach der Umstellregel. Aus

```php
$this->controller = new EventController(
    $twig,
    new NameFormatterService(),
    new NullLogger(),
    new ProjectQuery(new NameFormatterService())
);
```

  wird `$this->controller = $this->eventController(['view' => $twig]);`. Aus einer Baustelle
  mit eigenem Logger und fünftem Argument wird
  `$this->eventController(['view' => $twig, 'logger' => $logger, 'notificationService' => $service])`.
  Werden `use`-Importe dadurch überflüssig (`NullLogger`, `ProjectQuery`,
  `NameFormatterService`), entfallen sie, sofern die Datei sie sonst nicht braucht.

- [x] **Schritt 7: Läufe.**

```bash
ddev php vendor/bin/phpunit --filter "$(git diff --name-only -- tests | sed -E 's#.*/##; s#\.php$##' | paste -sd'|')"
ddev php vendor/bin/phpunit --filter "BuildsControllersTest|ControllersAreBuiltThroughBuildersTest"
```

  Beide grün.

- [x] **Schritt 8: Schärfeprobe.** In `buildController()` die Prüfung auf unbekannte
  Schlüssel auskommentieren → `testAMistypedParameterNameIsRejected` rot (PHP meldet dann
  einen unbekannten benannten Parameter als `Error`, nicht als `InvalidArgumentException`).
  Zurücknehmen. In einer umgestellten Testdatei testweise `new EventController($twig, ...)`
  zurückschreiben → Wächter rot. Zurücknehmen.

- [x] **Schritt 9: Commit.** Betreff:
  `test(controllers): EventController in Tests über Baustein statt Positionsargumente`

---

### Tasks 2 bis 6: die übrigen elf Controller

Jeder dieser Tasks läuft nach demselben Ablauf; unterschiedlich sind nur Controller und
Methodenname.

| Task | Controller | Methoden im Trait |
|---|---|---|
| 2 | `UserController` | `userController()` |
| 3 | `SponsorController` | `sponsorController()` |
| 4 | `TaskController`, `FinanceController` | `taskController()`, `financeController()` |
| 5 | `RegistrationController`, `AttendanceController`, `EvaluationController` | `registrationController()`, `attendanceController()`, `evaluationController()` |
| 6 | `ProfileController`, `PasswordResetController`, `DashboardController`, `VoiceGroupController` | `profileController()`, `passwordResetController()`, `dashboardController()`, `voiceGroupController()` |

**Ablauf je Controller** (für `<X>` den Controller einsetzen):

- **a) Baustellen sammeln.**

```bash
grep -rnE -A12 "new \\\\?(App\\\\Controllers\\\\)?<X>Controller[(]" tests
```

- **a2) Namenskollisionen prüfen** (in Task 1 erst bei den Testläufen aufgefallen):

```bash
grep -rn "function <x>Controller" tests
```

  Hat eine Testklasse schon eine gleichnamige Hilfsmethode (`private function taskController()`
  und ähnlich), überdeckt sie die Baustein-Methode, und `$this-><x>Controller([...])` ruft die
  falsche. Die Hilfsmethode wird gestrichen, ihre Aufrufstellen rufen den Baustein mit den
  Einträgen, die der Rumpf der Hilfsmethode gesetzt hat. Gebrauchte Argumente der
  Hilfsmethode (etwa ein `$logger`-Parameter) werden an der Aufrufstelle zum Eintrag.
- **b) Vorgaben bestimmen.** Konstruktor in `src/Controllers/<X>Controller.php` lesen. Für
  jeden Pflichtparameter außer `view` den häufigsten Ausdruck der Baustellen nehmen, sofern
  er ohne Variablen des Tests auskommt (Globale Vorgaben). Parameter mit PHP-Vorgabewert
  bekommen keine Vorgabe.
- **c) Methode im Trait ergänzen**, gebaut wie `eventController()`.
- **d) `MIGRATED` im Wächter um den Controller erweitern** → Wächter rot.
- **e) Baustellen nach der Umstellregel umstellen**, `use BuildsControllers;` in jeder
  betroffenen Klasse.
- **f) Läufe** wie Task 1, Schritt 7 → grün.

Besonderheiten, die aus den Konstruktoren schon feststehen:

- `EvaluationController` nimmt `?ProjectMemberPolicy $memberPolicy = null` mit Rückfall auf
  `new ProjectMemberPolicy($_SESSION)`. Tests, die das Argument weglassen, verlassen sich auf
  die Sitzung zum Bauzeitpunkt – weggelassen bleibt weggelassen.
- `DashboardController` nimmt `array $settings = []`; ein übergebenes Array wird als
  `'settings' => [...]` benannt.
- `PasswordResetController` hat außer `view` nur optionale Parameter; seine Methode im Trait
  hat eine leere Vorgabenliste.
- `RegistrationController` nutzt Konstruktor-Promotion; die Parameternamen sind `view`,
  `scopeService`, `logger`, `nameFormatter`.

### Task 2: `UserController`

- [x] **Schritt 1:** Ablauf a) bis f) für `UserController`.
- [x] **Schritt 2: Commit.** Betreff:
  `test(controllers): UserController in Tests über Baustein`

### Task 3: `SponsorController`

- [x] **Schritt 1:** Ablauf a) bis f) für `SponsorController`.
- [x] **Schritt 2: Commit.** Betreff:
  `test(controllers): SponsorController in Tests über Baustein`

### Task 4: `TaskController` und `FinanceController`

- [x] **Schritt 1:** Ablauf a) bis f) für `TaskController`.
- [x] **Schritt 2:** Ablauf a) bis f) für `FinanceController`.
- [x] **Schritt 3: Commit.** Betreff:
  `test(controllers): Task- und FinanceController in Tests über Baustein`

### Task 5: `RegistrationController`, `AttendanceController`, `EvaluationController`

- [x] **Schritt 1:** Ablauf a) bis f) für `RegistrationController`.
- [x] **Schritt 2:** Ablauf a) bis f) für `AttendanceController`.
- [x] **Schritt 3:** Ablauf a) bis f) für `EvaluationController`.
- [x] **Schritt 4: Commit.** Betreff:
  `test(controllers): Anmelde-, Anwesenheits- und Auswertungs-Controller über Baustein`

### Task 6: `ProfileController`, `PasswordResetController`, `DashboardController`, `VoiceGroupController`

- [x] **Schritt 1:** Ablauf a) bis f) für `ProfileController`.
- [x] **Schritt 2:** Ablauf a) bis f) für `PasswordResetController`.
- [x] **Schritt 3:** Ablauf a) bis f) für `DashboardController`.
- [x] **Schritt 4:** Ablauf a) bis f) für `VoiceGroupController`.
- [x] **Schritt 5: Commit.** Betreff:
  `test(controllers): Profil-, Passwort-, Dashboard- und Stimmgruppen-Controller über Baustein`

---

### Task 7: Regel festhalten und Abschluss

**Dateien:**
- Ändern: `.claude/skills/writing-tests/SKILL.md`

- [x] **Schritt 1: Skill nachziehen.** Im Abschnitt "Schnell bleiben" lautet der letzte
  Punkt bisher: "Den Controller von Hand mit den nötigen Diensten bauen; den ganzen Container
  … nur, wenn die Verdrahtung selbst geprüft wird." Er wird ergänzt um: Für die Controller
  aus `ControllersAreBuiltThroughBuildersTest::MIGRATED` geschieht das über
  `Tests\Feature\BuildsControllers` (`$this->eventController(['view' => $twig])`), nie mit
  `new`; ein weiterer Controller wird beim nächsten Anfassen umgestellt und in die Liste
  aufgenommen. In der Aufzählung der vorhandenen Bausteine unter "Isolation" kommt
  `BuildsControllers` dazu.
- [x] **Schritt 2: Volle Suite.** `ddev composer test:parallel` → grün. Die Zahl der Tests
  ist der Ausgangswert aus Task 1 plus 4 (drei Baustein-Tests, ein Wächter); weicht sie
  anders ab, ist bei der Umstellung ein Test verloren gegangen oder doppelt entstanden – dann
  die Ursache suchen, nicht weitergehen.
- [x] **Schritt 3: Stil.** `ddev composer phpcs` → keine Verstöße. Prüft `phpcs.xml` das
  Verzeichnis `tests/` nicht, das im Bericht vermerken.
- [x] **Schritt 4: Commit.** Betreff:
  `docs(skills): Controller in Tests über BuildsControllers bauen`
- [x] **Schritt 5: Bericht an Georg.** Zahl der umgestellten Baustellen je Controller,
  Testzahlen vorher und nachher, Ergebnis der Schärfeproben. Kein Squash, kein Merge, kein
  Push ohne seine Ansage. Hinweis: Der Schichtungsplan setzt voraus, dass dieser Stand auf
  `main` liegt.

---

## Bewusst nicht Teil dieses Plans

- **Die übrigen Controller.** Insgesamt bauen 176 Testdateien an 362 Stellen Controller von
  Hand; umgestellt werden die zwölf, deren Konstruktor der Schichtungsplan ändert. Der
  Wächter ist so gebaut, dass jeder weitere Controller mit einer Zeile dazukommt.
- **Konstruktoren und `Dependencies.php`.** Optionale Parameter mit Rückfall `?? new` zu
  Pflichtparametern zu machen und Handeinträge zu streichen ist Produktivcode und gehört in
  den Schichtungsplan bzw. dahinter.
- **Twig-Vorgaben.** Die Tests bauen ihr Twig auf mindestens fünf Arten; das zu
  vereinheitlichen wäre ein eigenes Thema.

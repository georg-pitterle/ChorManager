# Saubere Schichtung der Controller – Umsetzungsplan

> **Für agentische Bearbeiter:** REQUIRED SUB-SKILL: `superpowers:subagent-driven-development`
> (empfohlen) oder `superpowers:executing-plans`. Der Plan wird Task für Task abgearbeitet.
> Schritte nutzen Checkbox-Syntax (`- [ ]`); nach jedem Task werden seine Kästchen im selben
> Commit abgehakt (Regel aus `AGENT.md`, Abschnitt "Pläne").

**Ziel:** Controller lesen die Anfrage, prüfen Rechte, rufen Dienste und Abfragen auf und
antworten. Geschäftsregeln, Transaktionen, Rohabfragen, Netzwerkzugriffe und geteilte
Hilfsfunktionen wandern in die Schicht darunter; drei Quelltext-Wächter halten den Stand fest.

**Architektur:** Kein neues Muster, sondern die vorhandenen Schichten konsequent genutzt:
`src/Util` für reine Funktionen, `src/Services` für Schreibwege mit Transaktion,
`src/Queries` für Lesewege, `src/Persistence` für Speichern samt Zuordnungen. Controller
bekommen neue Abhängigkeiten als **Pflichtparameter im Konstruktor**; die Tests bauen sie über
`Tests\Feature\BuildsControllers`, wo jede neue Abhängigkeit genau eine Zeile Vorgabe bekommt.
Ein Wächter mit Sperrklinke (`ControllerLayeringTest`) listet die bekannten Verstöße und
schrumpft Task für Task.

**Tech-Stack:** PHP 8.5, Slim 4, PHP-DI, Eloquent (Capsule), PHPUnit über DDEV.

**Voraussetzung:** `docs/superpowers/plans/2026-11-16-controller-test-builders.md` ist
umgesetzt und liegt auf `main`. Ohne ihn bauen 109 Testdateien die Controller mit
Positionsargumenten, und jeder neue Pflichtparameter bräche sie alle. Gibt es
`tests/Feature/BuildsControllers.php` nicht, wird hier nicht begonnen.

**Spec:** Es gibt keine eigene Spec-Datei. Grundlage ist der Befund der Schichtprüfung vom
16.11.2026, hier vollständig wiedergegeben:

| Nr. | Befund | Task |
|---|---|---|
| 1 | `src/Util/MailBranding.php` ruft Farblogik aus `AppSettingController` | 2 |
| 2 | Controller rufen statische Methoden und Konstanten anderer Controller; die Middleware hängt an Konstanten des `MailBadgeController` | 3 |
| 3 | Mehrschritt-Schreibwege ohne Transaktion: Terminserie anlegen und ändern, Mitglied anlegen und ändern | 4, 5 |
| 4 | Geschäftsregeln samt Transaktion im Controller: Buchung, Import, Storno, Nummernkreis, Aufgaben, Anwesenheiten, Vertretungsanmeldungen | 6, 7, 8 |
| 5 | Rohabfragen am Query-Builder in `EvaluationController`, `VoiceGroupController`, `PollController` | 9 |
| 6 | Dienste per `new` im Methodenrumpf statt aus dem Container | 10 |
| 7 | IMAP-Verbindungstest öffnet den Socket im `ProfileController` | 11 |

Alle Zeilennummern in diesem Plan gelten für Commit `71471740` und verschieben sich mit jedem
Task. Maßgeblich ist immer der Methodenname, die Zeilennummer ist nur die Starthilfe.

## Globale Vorgaben

- **Verhalten bleibt gleich.** Kein Task ändert eine Meldung, eine Weiterleitung, einen
  Statuscode oder einen Log-`event`-Schlüssel. Die einzige gewollte Verhaltensänderung ist die
  Atomarität in Task 4 und 5.
- **Neue Abhängigkeit = Pflichtparameter.** Im Konstruktor steht `Foo $foo` ohne Vorgabewert
  und ohne Rückfall `?? new Foo()`. Der Parameter wird **vor dem ersten optionalen Parameter**
  eingefügt (PHP verlangt Pflichtparameter vor optionalen); hat der Konstruktor keine
  optionalen, kommt er ans Ende. Wo dieser Plan "neuer Parameter" sagt, ist das gemeint.
- **Drei Stellen je neuem Parameter:**
  1. der Konstruktor,
  2. `tests/Feature/BuildsControllers.php`: eine Zeile Vorgabe in der Methode des Controllers
     (`'foo' => static fn (): Foo => new Foo()`), damit die vorhandenen Tests weiterlaufen,
  3. `src/Dependencies.php`: Hat der Controller dort einen ausdrücklichen Eintrag, bekommt
     der Aufruf `$c->get(Foo::class)` an der Stelle, die der Konstruktor vorgibt. Hat er
     keinen, ist nichts zu tun – PHP-DI füllt Pflichtparameter selbst.
- **Vorhandene optionale Parameter bleiben, wie sie sind.** `?NotificationService = null`
  heißt in vielen Tests "ohne Benachrichtigung" und ist gewollt.
- **Neue Klassen sind nicht `final`**, wenn ein Test sie durch eine Unterklasse ersetzt
  (Dienste, Persistence). Reine Util-Klassen sind `final`.
- **Neue Tests bauen Controller über `BuildsControllers`**, etwa
  `$this->financeController(['view' => $twig, 'bookings' => $spy])`. Wo dieser Plan sagt
  "Controller so bauen wie in XTest", ist der Aufruf des Bausteins mit denselben Einträgen
  gemeint.
- **Tests zuerst** (`/writing-tests`): Regel in einem Satz im Klassenkommentar, Datenbanktests
  mit `Bootstrap::setupTestDatabase()` und Transaktion in `setUp()`/`tearDown()`, eindeutige
  Werte mit `bin2hex(random_bytes(4))`. **Keine Doppel:** Wird Code nur verschoben und decken
  vorhandene Feature-Tests die Regel schon ab, kommt genau ein Anschluss-Test dazu, der
  belegt, dass der Controller den neuen Dienst ruft.
- **Schärfeprobe** für jeden neuen Test, der eine Regel bewacht: Produktivcode gezielt
  sabotieren, Test muss rot werden, nach der Rücknahme wieder grün. Ergebnis in die
  Commit-Nachricht.
- **Testläufe** (`/test-runs`): während der Arbeit `ddev php vendor/bin/phpunit --filter
  "<Muster>"`, die volle Suite `ddev composer test:parallel` nur in Task 12. Nach jedem Task
  zusätzlich `ddev composer phpcs` (bei Verstößen `ddev composer phpcbf`, dann erneut).
- **Bezeichner englisch, Texte deutsch mit echten Umlauten** (`instructions/naming.md`). Der
  Hook `.claude/hooks/check-german-umlauts.sh` weist Umschriften ab.
- **Zeilenenden LF.** Nach jedem `Write` auf Windows die Datei normalisieren
  (`instructions/line-endings.md`).
- **Kommentare wandern mit.** Die deutschen Begründungskommentare an verschobenem Code
  gehören zum Code und ziehen mit um. Verweise in Kommentaren auf den alten Ort
  (`SponsorshipController::validateAmount()`) werden auf den neuen umgeschrieben.
- **Git:** Arbeit auf dem Branch `refactor/controller-layering` (in Task 1 von `main`
  abzweigen). Ein Commit je Task nach `/git-commit`, Abschnitte 1 bis 5. Kein Squash, kein
  Merge nach `main`, kein `git push` – das entscheidet Georg nach Task 12.
- **Kein Schema, keine neue Entität:** keine Migration, keine Seed-Daten, kein Twig, kein
  Hilfethema.

## Review-Schwerpunkte

Fünf Lagen, die kein einzelner Task von sich aus prüft und die am ehesten jemanden treffen.
Zu jeder steht der Test in dem Task, dem der Code gehört.

1. **Serie scheitert beim dritten von zehn Terminen.** Erwartet: kein Termin, keine Serie,
   Fehlermeldung. Test in Task 4.
2. **Fehler in einer Benachrichtigung nach dem Speichern.** Erwartet: Die Daten bleiben
   gespeichert; Mails und Glocke laufen wie bisher **nach** der Transaktion. Test in Task 4
   (Benachrichtigung wirft, Termin steht).
3. **Controller lässt sich im Betrieb nicht mehr bauen, weil ein Handeintrag in
   `Dependencies.php` den neuen Pflichtparameter nicht übergibt oder an falscher Stelle.**
   Die Tests merken das nicht von selbst, sie bauen über `BuildsControllers`. Erwartet: Jeder
   angefasste Controller kommt aus dem echten Container. Test in Task 10
   (`DependenciesContainerWiringTest`), ab Task 4 nach jedem Task mit auszuführen.
4. **Storno einer bereits stornierten Buchung nach dem Umzug.** Erwartet: Meldung "Diese
   Buchung wurde bereits storniert.", keine zweite Stornobuchung. Anschluss-Test in Task 6,
   die Regel selbst bewacht weiter `FinanceReversalAndLockFeatureTest`.
5. **Mitglied ändern scheitert bei der Projektzuordnung.** Erwartet: Name und Rollen bleiben
   beim alten Stand, statt halb gespeichert zu sein. Test in Task 5.

---

### Task 1: Wächter mit Sperrklinke

Hält den Ist-Stand fest, bevor etwas umzieht. Der Test ist von Anfang an grün: Er vergleicht
die gefundenen Verstöße mit einer Liste bekannter Verstöße. Verschwindet ein Verstoß, wird der
Test rot, bis der Eintrag aus der Liste gestrichen ist – so kann nichts zurückkommen.

**Dateien:**
- Anlegen: `tests/Unit/Controllers/ControllerLayeringTest.php`

**Schnittstellen:**
- Liefert: die Konstanten `KNOWN_OUTSIDE_IMPORTS` und `KNOWN_CROSS_CONTROLLER_CALLS`, die
  Task 2 und 3 leeren, sowie die Helfer `controllerFiles()` und `codeWithoutComments()`, die
  Task 10 und 12 nutzen.

- [x] **Schritt 1: Branch anlegen**

```bash
git switch -c refactor/controller-layering
```

- [x] **Schritt 2: Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Controller sind die oberste Schicht: Nichts darunter kennt sie, und sie
 * dienen einander nicht als Bibliothek.
 *
 * Anlass war die Schichtprüfung vom 16.11.2026: `MailBranding` holte die
 * Vereinsfarbe aus dem `AppSettingController`, womit jeder Mailversand - auch
 * der aus der Kommandozeile - an einem HTTP-Controller hing, und vier
 * Controller riefen statische Methoden eines anderen.
 *
 * Die Listen unten sind eine Sperrklinke. Sie nennen die beim Anlegen des Tests
 * bekannten Verstöße; verglichen wird auf Gleichheit. Ein neuer Verstoß macht
 * den Test rot, ein behobener ebenfalls - bis sein Eintrag gestrichen ist.
 * Gelesen wird die Quelle ohne Kommentare, weil Verweise im Fließtext erlaubt
 * bleiben.
 */
final class ControllerLayeringTest extends TestCase
{
    /** @var list<string> Pfade relativ zu src/ */
    private const KNOWN_OUTSIDE_IMPORTS = [
        'Middleware/MailBadgeRefreshMiddleware.php',
        'Util/MailBranding.php',
    ];

    /** @var list<string> "Aufrufer -> Aufgerufener" */
    private const KNOWN_CROSS_CONTROLLER_CALLS = [
        'DownloadController -> WebdavController',
        'SponsorController -> SponsorshipController',
        'SponsorPackageController -> SponsorshipController',
        'SponsoringDashboardController -> ProjectController',
        'WebmailController -> MailBadgeController',
    ];

    public function testNothingBelowTheControllersImportsAController(): void
    {
        $src = dirname(__DIR__, 3) . '/src';
        $found = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
            $isWiring = in_array($relative, ['Routes.php', 'Dependencies.php'], true);
            if ($file->getExtension() !== 'php' || $isWiring || str_starts_with($relative, 'Controllers/')) {
                continue;
            }

            if (preg_match('/\bApp\\\\Controllers\\\\/', self::codeWithoutComments($file->getPathname())) === 1) {
                $found[] = $relative;
            }
        }

        sort($found);
        self::assertSame(self::KNOWN_OUTSIDE_IMPORTS, $found);
    }

    public function testNoControllerReachesIntoAnotherController(): void
    {
        $found = [];

        foreach (self::controllerFiles() as $file) {
            $own = basename($file, '.php');
            preg_match_all('/\b([A-Z]\w*Controller)::/', self::codeWithoutComments($file), $matches);
            foreach (array_unique($matches[1]) as $other) {
                if ($other !== $own) {
                    $found[] = $own . ' -> ' . $other;
                }
            }
        }

        sort($found);
        self::assertSame(self::KNOWN_CROSS_CONTROLLER_CALLS, $found);
    }

    /** @return list<string> */
    public static function controllerFiles(): array
    {
        $base = dirname(__DIR__, 3) . '/src/Controllers';
        $files = array_merge(
            glob($base . '/*.php') ?: [],
            glob($base . '/Oidc/*.php') ?: [],
            glob($base . '/Concerns/*.php') ?: []
        );
        self::assertGreaterThan(40, count($files), 'Es wurden kaum Controller gefunden.');

        return $files;
    }

    public static function codeWithoutComments(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
```

- [x] **Schritt 3: Lauf**

Ausführen: `ddev php vendor/bin/phpunit --filter ControllerLayeringTest`
Erwartet: 2 Tests grün.

Weicht die gefundene Menge von den Listen ab, sind das echte weitere Verstöße, die die
Prüfung übersehen hat. Dann: die Liste um genau diese Einträge ergänzen, unter diesem Schritt
eine Notiz mit den Zusatzeinträgen hinterlassen und sie in Task 3 nach demselben Muster
beheben (Funktion nach `src/Util`, Aufrufer umstellen).

- [x] **Schritt 4: Schärfeprobe**

In `src/Util/ByteFormatter.php` testweise `use App\Controllers\AuthController;` einfügen →
Test rot. Zurücknehmen → grün.

- [x] **Schritt 5: Commit**

Betreff: `test(controllers): Wächter über die Abhängigkeitsrichtung der Controller`

---

### Task 2: Vereinsfarbe aus dem Controller lösen (Befund 1)

**Dateien:**
- Anlegen: `src/Util/PrimaryColor.php`
- Anlegen: `tests/Unit/Util/PrimaryColorTest.php`
- Ändern: `src/Controllers/AppSettingController.php` (Konstante Zeile 24, Methoden ab 355,
  411, 430, 457, 468; Aufrufe in `save()` und `themeCss()`)
- Ändern: `src/Util/MailBranding.php` (Zeilen 7, 53, 66, 68, 197)
- Ändern: `tests/Feature/AppSettingFeatureTest.php` (Zeilen 127–136, 185–199)
- Ändern: `tests/Unit/Controllers/ControllerLayeringTest.php`

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Util;

  final class PrimaryColor
  {
      public const DEFAULT = '#E8A817';
      public static function normalize(mixed $value): string;   // bisher normalizePrimaryColor()
      public static function strong(string $hexColor): string;  // bisher strongPrimaryColor()
      public static function toRgb(string $hexColor): string;   // bisher private hexToRgb()
  }
  ```
  `contrastRatio()` und `relativeLuminance()` ziehen als `private static` mit.

- [x] **Schritt 1: Tests umziehen.** Die Testmethoden aus `AppSettingFeatureTest`, die
  `AppSettingController::normalizePrimaryColor()` und `::strongPrimaryColor()` aufrufen,
  wandern mit unveränderten Zusicherungen nach `tests/Unit/Util/PrimaryColorTest.php` und
  rufen `PrimaryColor::normalize()` bzw. `PrimaryColor::strong()`. Ohne Datenbank, also
  schlichter `TestCase` ohne `setUp()`. In `AppSettingFeatureTest` werden sie gestrichen.
- [x] **Schritt 2: Rotlauf.** `ddev php vendor/bin/phpunit --filter PrimaryColorTest` →
  scheitert mit "Class App\Util\PrimaryColor not found".
- [x] **Schritt 3: `PrimaryColor` anlegen**, Rümpfe unverändert aus dem Controller übernehmen.
- [x] **Schritt 4: Aufrufer umstellen.** Im `AppSettingController` die fünf Methoden und die
  Konstante `DEFAULT_PRIMARY_COLOR` entfernen, `save()` und `themeCss()` rufen `PrimaryColor`.
  In `MailBranding` den `use App\Controllers\AppSettingController;` streichen und die vier
  Stellen umstellen.
- [x] **Schritt 5: Sperrklinke.** `'Util/MailBranding.php'` aus `KNOWN_OUTSIDE_IMPORTS`
  streichen.
- [x] **Schritt 6: Läufe.**
  `ddev php vendor/bin/phpunit --filter "PrimaryColorTest|AppSettingFeatureTest|MailBrandingFeatureTest|ControllerLayeringTest"`
  → grün. `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 7: Commit.** Betreff:
  `refactor(util): Vereinsfarbe als PrimaryColor, MailBranding ohne Controller`

---

### Task 3: Geteilte Hilfsfunktionen aus den Controllern lösen (Befund 2)

Fünf kleine Umzüge, ein Commit. Nach dem Task sind beide Listen der Sperrklinke leer.

**Dateien:**
- Ändern: `src/Util/AmountNormalizer.php`
- Anlegen: `src/Util/ProjectDateInput.php`, `src/Util/WebdavUrl.php`,
  `src/Util/MailBadgeRefresh.php`
- Anlegen: `tests/Unit/Util/ProjectDateInputTest.php`; Betragstests in
  `tests/Unit/Util/AmountNormalizerTest.php` (anlegen, falls es die Datei nicht gibt)
- Ändern: `SponsorshipController`, `SponsorPackageController`, `ProjectController`,
  `SponsoringDashboardController`, `WebdavController`, `DownloadController`,
  `SponsorController`, `MailBadgeController`, `WebmailController` (alle unter
  `src/Controllers/`), `src/Middleware/MailBadgeRefreshMiddleware.php`
- Ändern: `tests/Feature/WebdavFeatureTest.php` (ein Aufruf von `WebdavController::baseUrl`)
- Ändern: `tests/Unit/Controllers/ControllerLayeringTest.php`

**Schnittstellen:**
- Liefert:
  ```php
  // AmountNormalizer – Rumpf von SponsorshipController::validateAmount() (Zeile 954)
  public static function normalizeNonNegative(string $amount): ?string;

  // ProjectDateInput – aus ProjectController (Zeilen 30, 101, 132)
  final class ProjectDateInput
  {
      public const INVALID_LOGO_DEADLINE_MESSAGE =
          'Die Logo-Fristen müssen ein Datum sein (JJJJ-MM-TT) oder leer bleiben.';
      public static function normalize(mixed $value): string|false|null;
      /** @return array{logo_deadline_print?: ?string, logo_deadline_online?: ?string}|false */
      public static function logoDeadlines(array $data, bool $isNewProject): array|false;
  }

  // WebdavUrl – aus WebdavController (Zeilen 35, 82)
  final class WebdavUrl
  {
      public const BASE_PATH = '/webdav';
      public static function base(ServerRequestInterface $request): string;
  }

  // MailBadgeRefresh – aus MailBadgeController (Zeilen 33, 40)
  final class MailBadgeRefresh
  {
      public const PATH = '/profile/mail-badge';
      public const FORCE_SESSION_KEY = 'mail_badge_refresh_due';
  }
  ```

  `logoDeadlines()` nimmt statt `?Project $current` ein `bool $isNewProject`, damit `Util`
  kein Modell kennt: `true` entspricht dem bisherigen `$current === null`.

- [x] **Schritt 1: Tests schreiben.**

  `AmountNormalizerTest` (DataProvider `nonNegativeAmounts`):

  | Eingabe | Erwartung |
  |---|---|
  | `''` | `'0.00'` |
  | `'1.500,00'` | `'1500.00'` |
  | `'abc'` | `null` |
  | `'-5'` | `null` |

  `ProjectDateInputTest`:
  - `testMissingDeadlineFieldsBecomeNullOnANewProject`: `logoDeadlines([], true)` ergibt
    `['logo_deadline_print' => null, 'logo_deadline_online' => null]`.
  - `testMissingDeadlineFieldsAreLeftAloneOnAnExistingProject`: `logoDeadlines([], false)`
    ergibt `[]`.
  - `testAnOverflowingDateIsRejected`:
    `logoDeadlines(['logo_deadline_print' => '2026-13-45'], false)` ergibt `false`.
  - `testAnEmptyFieldClearsTheDeadline`: `logoDeadlines(['logo_deadline_online' => ''], false)`
    ergibt `['logo_deadline_online' => null]`.

- [x] **Schritt 2: Rotlauf.**
  `ddev php vendor/bin/phpunit --filter "AmountNormalizerTest|ProjectDateInputTest"` → rot
  (Methode bzw. Klasse fehlt).
- [x] **Schritt 3: Betrag.** `AmountNormalizer::normalizeNonNegative()` anlegen,
  `SponsorshipController::validateAmount()` entfernen. Aufrufer: `SponsorshipController`
  (Zeilen 110, 251), `SponsorPackageController` (Zeilen 67, 127).
- [x] **Schritt 4: Projektdatum.** `ProjectDateInput` anlegen. Aus `ProjectController`
  entfallen `normalizeProjectDate()`, `logoDeadlines()` und die Konstante. Aufrufer:
  `ProjectController` Zeile 196–197 und 238–239 (`normalize`), Zeile 214 (`logoDeadlines($data,
  true)`), Zeile 263 (`logoDeadlines($data, false)`), `SponsoringDashboardController` Zeile 437
  (`false`) und 439 (Konstante).
- [x] **Schritt 5: WebDAV-Adresse.** `WebdavUrl` anlegen. `WebdavController::baseUrl()`
  entfällt; `WebdavController::BASE_PATH` bleibt als Alias
  (`public const BASE_PATH = WebdavUrl::BASE_PATH;`), weil die Klasse sie selbst dreimal
  nutzt. Aufrufer: `DownloadController` Zeile 222, `WebdavFeatureTest`.
- [x] **Schritt 6: Entitätstyp.** `SponsorController` Zeile 477:
  `SponsorshipController::ENTITY_TYPE` → `Sponsorship::ENTITY_TYPE`.
- [x] **Schritt 7: Postfach-Badge.** `MailBadgeRefresh` anlegen. `MailBadgeController` behält
  beide Konstanten als Alias (`public const REFRESH_PATH = MailBadgeRefresh::PATH;`,
  `public const FORCE_SESSION_KEY = MailBadgeRefresh::FORCE_SESSION_KEY;`), damit `Routes.php`
  und 26 Teststellen unberührt bleiben. `MailBadgeRefreshMiddleware` (Zeilen 7, 253, 262, 267)
  und `WebmailController` (Zeile 127) nutzen `MailBadgeRefresh` direkt.
- [x] **Schritt 8: Sperrklinke.** Beide Listen auf `[]` setzen. Falls Task 1 Zusatzeinträge
  notiert hat, diese jetzt nach demselben Muster beheben.
- [x] **Schritt 9: Läufe.**
  `ddev php vendor/bin/phpunit --filter "ControllerLayeringTest|AmountNormalizerTest|ProjectDateInputTest|Sponsor|Project|Webdav|MailBadge|Webmail|Download"`
  → grün. `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 10: Commit.** Betreff:
  `refactor(controllers): geteilte Hilfsfunktionen nach Util, kein Controller ruft einen anderen`

---

### Task 4: Termine atomar schreiben (Befund 3, Termine)

**Dateien:**
- Anlegen: `src/Services/EventWriteService.php`
- Anlegen: `tests/Feature/EventWriteServiceFeatureTest.php`
- Ändern: `src/Controllers/EventController.php` (`__construct` Zeile 109, `create()` Zeile
  994, `update()` Zeile 1305)
- Ändern: `src/Dependencies.php` (Eintrag `EventController::class`, Zeile 416)
- Ändern: `tests/Feature/BuildsControllers.php` (`eventController()`)
- Ändern: `tests/Feature/DependenciesContainerWiringTest.php`

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Services;

  class EventWriteService
  {
      public function __construct(
          ?EventAudienceService $audience = null,
          ?EventRecurrenceService $recurrence = null
      );

      /**
       * @param array<string, mixed> $attributes Spalten für Event::create()
       * @param array<int, mixed> $audienceSets Ergebnis von EventAudienceService::readRows()
       */
      public function createSingle(array $attributes, array $audienceSets): Event;

      /**
       * @param array{title: string, event_type_id: ?int, location: string,
       *     registration_enabled: bool, attendance_required: bool} $shared
       * @param array{frequency: string, interval: int, weekdays: list<int>,
       *     end: \Carbon\CarbonImmutable} $rule
       * @param ?string $registrationDeadline Y-m-d H:i:s zum ersten Termin oder null
       * @return list<Event> frisch geladen, in Reihenfolge der Termine
       */
      public function createSeries(
          array $shared,
          string $firstDay,
          string $startTime,
          string $endTime,
          ?string $registrationDeadline,
          array $rule,
          array $audienceSets
      ): array;

      /** @param array<string, mixed> $updateData */
      public function updateSingle(Event $event, array $updateData, array $audienceSets): void;

      /**
       * @param iterable<Event> $events die zu ändernden Termine der Serie
       * @param list<string> $seriesFields gewählte Feldgruppen
       * @param array<string, mixed> $updateData title, event_type_id, location,
       *     registration_enabled, attendance_required
       */
      public function updateSeries(
          iterable $events,
          array $seriesFields,
          array $updateData,
          string $startsAt,
          string $endsAt,
          ?string $registrationDeadline,
          array $audienceSets
      ): void;
  }
  ```
  Jede der vier Methoden läuft in `Capsule::connection()->transaction(...)`.
- `EventController::__construct` bekommt den neuen Parameter `EventWriteService $eventWriter`
  (vor `?NotificationService $notificationService`); Vorgabe in
  `BuildsControllers::eventController()`: `new EventWriteService()`.

- [x] **Schritt 1: Tests schreiben** (`EventWriteServiceFeatureTest`, Datenbank mit
  Transaktion wie in `EventSeriesCreationFeatureTest`).

  Hilfsmittel im Test: eine anonyme Unterklasse von `EventAudienceService`, deren
  `setAudience()` beim zweiten Aufruf eine `RuntimeException` wirft und sonst
  `parent::setAudience()` ruft. Die Zielgruppe "alle" entsteht über
  `(new EventAudienceService())->readRows(['audience' => [['all' => '1']]])`.

  - `testAFailingOccurrenceLeavesNoSeriesBehind` (Review-Schwerpunkt 1): Zahl der Zeilen in
    `events` und `event_series` vorher merken; `createSeries()` für drei Wochentermine mit dem
    scheiternden Dienst; `expectException` geht hier nicht, weil danach gezählt wird – also
    `try/catch` und `self::fail()` im `try`. Danach `assertSame($eventsBefore, Event::count())`
    und `assertSame($seriesBefore, EventSeries::count())`.
  - `testAFailingSeriesUpdateChangesNoEvent`: drei Termine einer Serie mit Titel `'Alt'`
    direkt per `Event::create()` anlegen; `updateSeries()` mit `$seriesFields = ['title',
    'audience']`, neuem Titel `'Neu'` und dem scheiternden Dienst. Danach tragen alle drei
    weiter `'Alt'`.
  - `testAFailingAudienceLeavesNoSingleEventBehind`: Dienst, dessen `setAudience()` sofort
    wirft; nach `createSingle()` ist `Event::count()` unverändert.
  - `testSeriesCreationReturnsOneFreshEventPerOccurrence`: Montag in zwei Wochen, wöchentlich,
    Serienende 15 Tage später → drei Termine, alle mit derselben `series_id`, Rückgabe in
    aufsteigender Reihenfolge von `starts_at`.

  In `DependenciesContainerWiringTest` zusätzlich (Review-Schwerpunkt 3), mit DataProvider
  `layeredControllers` über die zwölf Controller aus dem Baustein-Plan (`EventController`,
  `UserController`, `SponsorController`, `TaskController`, `FinanceController`,
  `RegistrationController`, `AttendanceController`, `EvaluationController`,
  `ProfileController`, `PasswordResetController`, `DashboardController`,
  `VoiceGroupController`):
  - `testEveryLayeredControllerResolvesFromTheContainer`: `$this->buildContainer()->get($class)`
    liefert eine Instanz von `$class`. Der Test ist von Anfang an grün und wird rot, sobald
    ein Handeintrag in `Dependencies.php` hinter einem Konstruktor zurückbleibt. Er läuft ab
    hier nach jedem Task mit.

  In `EventSeriesCreationFeatureTest` zusätzlich (Review-Schwerpunkt 2):
  - `testAFailingNotificationDoesNotUndoTheEvent`: Controller mit einem
    `NotificationService`-Stub bauen, dessen Aufruf wirft; Einzeltermin über `create()`
    anlegen. Der Termin steht danach in der Datenbank. Scheitert dieser Test schon vor der
    Umsetzung nicht, ist das der bisherige Stand und bleibt als Wächter stehen; scheitert er
    nach der Umsetzung, liegt die Benachrichtigung fälschlich in der Transaktion.

- [x] **Schritt 2: Rotlauf.** `ddev php vendor/bin/phpunit --filter EventWriteServiceFeatureTest`
  → rot, Klasse fehlt.
- [x] **Schritt 3: `EventWriteService` schreiben.** Die Rümpfe kommen aus dem Controller:
  - `createSingle`: `Event::create()` und `setAudience()` aus `create()` (Zeile 1136–1146),
    Rückgabe `$event->fresh()`.
  - `createSeries`: der `else`-Zweig von `create()` (Zeile 1164–1203) ohne Benachrichtigung
    und ohne Flash-Meldung. `EventRecurrenceService` kommt aus dem Konstruktor.
  - `updateSingle`: `$event->update()` und `setAudience()` aus `update()` (Zeile 1509–1510).
  - `updateSeries`: die Berechnung von `$newStartTime`, `$newEndTime` und
    `$deadlineLeadSeconds` (Zeile 1444–1455) und die Schleife (Zeile 1461–1498). Die
    Vorlaufzeit wird nur gerechnet, wenn `$updateData['registration_enabled']` wahr und
    `$registrationDeadline` nicht `null` ist – wie bisher.
- [x] **Schritt 4: Controller umstellen.** `create()` und `update()` rufen den Dienst. Im
  Controller bleiben: Eingaben lesen und prüfen, Rechteprüfung über die Serie
  (`$hasUnauthorizedSeriesEvent`), `describeScheduleChanges()` **vor** dem Schreiben,
  Benachrichtigungen und Flash-Meldungen **nach** dem Dienstaufruf, das umschließende
  `try/catch` mit Log und Formularfehler. Die Meldung `"Serie erfolgreich angelegt ($count
  Termine)."` zählt die Rückgabe von `createSeries()`.
- [x] **Schritt 5: Verdrahtung.** In `Dependencies.php` beim `EventController`
  `$c->get(EventWriteService::class)` an fünfter Stelle einfügen (hinter `ProjectQuery`, vor
  `NotificationService`). Ein eigener Eintrag für `EventWriteService` ist nicht nötig, PHP-DI
  baut die Klasse selbst. Gegenprobe: In `DependenciesContainerWiringTest` gibt es bereits
  Tests, die den `EventController` aus dem Container holen – sie laufen in Schritt 6 mit.
- [x] **Schritt 6: Läufe.**
  `ddev php vendor/bin/phpunit --filter "EventWriteService|EventSeries|EventFeatureTest|EventAudience|EventRegistrationSettings|EventSchedule|NotificationWiring|DependenciesContainerWiring|BuildsControllers"`
  → grün. `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 7: Schärfeprobe.** In `createSeries()` die Transaktionsklammer entfernen →
  `testAFailingOccurrenceLeavesNoSeriesBehind` rot. Zurücknehmen → grün.
- [x] **Schritt 8: Commit.** Betreff:
  `fix(events): Terminserie entsteht und ändert sich ganz oder gar nicht`

---

### Task 5: Mitglied atomar speichern (Befund 3, Mitglieder)

**Dateien:**
- Ändern: `src/Persistence/UserPersistence.php`
- Anlegen: `tests/Feature/UserPersistenceAtomicFeatureTest.php`
- Ändern: `src/Controllers/UserController.php` (`create()` Zeile 299–327, `update()` Zeile
  535–582)
- Ändern: `src/Dependencies.php` (Zeile 245, `UserPersistence::class`)

**Schnittstellen:**
- Liefert in `UserPersistence`:
  ```php
  public function __construct(
      LoggerInterface $logger,
      ?AuthorizationCodeService $authorizationCodeService = null,
      ?ProjectPersistence $projects = null          // neu, Rückfall new ProjectPersistence()
  );

  /**
   * Speichert das Mitglied samt Rollen, Stimmgruppen und Projekten in einer Transaktion.
   *
   * @param list<int|string> $roleIds
   * @param array<int, array{sub_voice_id: ?int}> $voiceGroupPivot wie syncVoiceGroups()
   * @param ?array<int|string> $projectIds null lässt die Projekte unberührt
   */
  public function saveWithAssignments(
      User $user,
      array $roleIds,
      array $voiceGroupPivot,
      ?array $projectIds
  ): void;
  ```
  Die Methode ruft der Reihe nach `save()`, `syncRoles()`, `syncVoiceGroups()` und – wenn
  `$projectIds !== null` – `ProjectPersistence::setUserProjects()`.

- [x] **Schritt 1: Tests schreiben.** Hilfsmittel: anonyme Unterklasse von
  `ProjectPersistence`, deren `setUserProjects()` eine `RuntimeException` wirft. Mitglied und
  Rolle so anlegen wie in `tests/Feature/UserFeatureTest.php` (dort die vorhandenen Helfer
  ansehen und denselben Weg nehmen), E-Mail mit Zufallsanhängsel.
  - `testANewMemberIsNotLeftBehindWhenTheProjectAssignmentFails`: neues, ungespeichertes
    `User`-Objekt; `saveWithAssignments($user, [$roleId], [], [1])` wirft; danach
    `assertFalse(User::where('email', $email)->exists())`.
  - `testAnExistingMemberKeepsNameAndRolesWhenTheProjectAssignmentFails` (Review-Schwerpunkt
    5): gespeichertes Mitglied mit Vorname `'Alt'` und Rolle A; Vorname auf `'Neu'` setzen,
    `saveWithAssignments($user, [$roleB], [], [1])` wirft; frisch geladen ist der Vorname
    `'Alt'` und die Rollenliste `[A]`.
  - `testProjectsStayUntouchedWhenNoProjectListIsGiven`: Dienst mit der werfenden
    `ProjectPersistence`, `$projectIds = null` → kein Wurf, Mitglied gespeichert.
- [x] **Schritt 2: Rotlauf.** `ddev php vendor/bin/phpunit --filter UserPersistenceAtomicFeatureTest`
  → rot, Methode fehlt.
- [x] **Schritt 3: `saveWithAssignments()` und den dritten Konstruktor-Parameter schreiben.**
- [x] **Schritt 4: `UserController::create()` umstellen.** Die vier Einzelaufrufe werden ein
  Aufruf; `$projectIds` ist `(array) ($data['projects'] ?? [])`, wenn
  `$this->userEditPolicy->canAssignProjectsOnCreate()`, sonst `null`. Die Log-Zeilen
  `user.created` und `user.role.assigned` stehen **nach** dem Aufruf – vorher hätte ein
  Rücksetzer ein Mitglied protokolliert, das es nicht gibt. Der Einladungsversand bleibt
  dahinter.
- [x] **Schritt 5: `UserController::update()` umstellen.** Ebenso; `$projectIds` ist
  `$projectIds`, wenn `$canEditProjects`, sonst `null`. Die Log-Zeilen `user.email.changed`,
  `user.role.assigned` und `user.role.revoked` stehen nach dem Aufruf. Der einzelne
  `setUserProjects()`-Aufruf bei Zeile 425 gehört zu einem anderen Zweig und bleibt, wie er
  ist.
- [x] **Schritt 6: Verdrahtung.** `UserPersistence::class => \DI\autowire()` durch einen
  ausdrücklichen Eintrag ersetzen, der `LoggerInterface`, `AuthorizationCodeService` und
  `ProjectPersistence` aus dem Container übergibt. Prüfen, ob
  `DependenciesContainerWiringTest` zu `UserPersistence` etwas zusichert, und es grün halten.
- [x] **Schritt 7: Läufe.**
  `ddev php vendor/bin/phpunit --filter "UserPersistence|UserFeatureTest|UserController|UserEdit|DependenciesContainerWiring"`
  → grün. `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 8: Schärfeprobe.** Transaktionsklammer in `saveWithAssignments()` entfernen →
  die ersten beiden Tests rot. Zurücknehmen → grün.
- [x] **Schritt 9: Commit.** Betreff:
  `fix(users): Mitglied, Rollen, Stimmgruppen und Projekte in einer Transaktion`

---

### Task 6: Buchungen in einen Dienst (Befund 4, Finanzen)

Reiner Umzug. Die Regeln bewachen weiter `FinanceReversalAndLockFeatureTest`,
`FinanceImportFeatureTest`, `FinancePaymentDatePeriodTest` und `FinanceFeatureTest`.

**Dateien:**
- Anlegen: `src/Services/FinanceBookingService.php`
- Anlegen: `tests/Feature/FinanceBookingWiringFeatureTest.php`
- Ändern: `src/Controllers/FinanceController.php` (`__construct` Zeile 73, Speichern Zeile
  409–438, `reserveRunningNumbers()` Zeile 513, Import Zeile 670–697, Storno Zeile 783–821,
  `use ... Capsule` Zeile 26)
- Ändern: `src/Dependencies.php` (Eintrag `FinanceController::class`, Zeile 513)

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Services;

  class FinanceBookingService
  {
      public function __construct(FinanceJournalService $journal);

      /** Erste Nummer eines zusammenhängenden Blocks; Rumpf unverändert. */
      public function reserveRunningNumbers(int $count): int;

      /**
       * Legt an ($id === null) oder ändert. null heißt: Zeitraum gesperrt, nichts geschrieben.
       *
       * @param array<string, mixed> $recordData
       */
      public function save(?int $id, array $recordData, ?int $actorId): ?Finance;

      /**
       * @param list<array<string, mixed>> $rows geprüfte Zeilen mit group_name
       * @return int Zahl der angelegten Buchungen
       */
      public function importRows(array $rows, FinanceAccount $account, ?int $actorId): int;

      /** null heißt: bereits storniert, nichts geschrieben. */
      public function reverse(Finance $original, ?int $actorId): ?Finance;
  }
  ```
- `FinanceController::__construct` bekommt den neuen Parameter
  `FinanceBookingService $bookings` (vor `?EntityAttachmentService $attachments`); Vorgabe in
  `BuildsControllers::financeController()`: `new FinanceBookingService(new FinanceJournalService())`
  – hat `FinanceJournalService` Konstruktor-Argumente, werden sie so gebaut wie an den
  Baustellen der Finanztests.

- [x] **Schritt 1: Anschluss-Test schreiben** (Review-Schwerpunkt 4). Controller so bauen wie
  in `FinanceReversalAndLockFeatureTest`, zusätzlich mit einer anonymen Unterklasse von
  `FinanceBookingService`, deren `reverse()` den Aufruf zählt und `null` liefert.
  - `testReversalGoesThroughTheBookingService`: Storno-Route des Controllers für eine
    vorhandene Buchung aufrufen; `reverse()` wurde genau einmal gerufen, `$_SESSION['error']`
    ist `'Diese Buchung wurde bereits storniert.'`, und es gibt keine Buchung mit
    `reversal_of_id` der Ausgangsbuchung.
- [x] **Schritt 2: Rotlauf.** `--filter FinanceBookingWiringFeatureTest` → rot (Klasse fehlt).
- [x] **Schritt 3: Dienst schreiben.** Die vier Rümpfe unverändert übernehmen;
  `$this->currentUserId()` wird zum Parameter `$actorId`, `$lockError` zur Rückgabe `null`,
  `$alreadyReversed` zur Rückgabe `null`.
- [x] **Schritt 4: Controller umstellen.** Die drei Transaktionsblöcke und
  `reserveRunningNumbers()` entfallen, ebenso der `Capsule`-Import. Nutzen Tests
  `reserveRunningNumbers` am Controller (Suche unter `tests/`), werden sie auf den Dienst
  umgestellt.
- [x] **Schritt 5: Verdrahtung.** In `Dependencies.php` beim `FinanceController`
  `$c->get(FinanceBookingService::class)` an neunter Stelle anhängen (hinter
  `FinanceCsvExportService`; `attachments` wird dort bisher nicht übergeben und bleibt so).
- [x] **Schritt 6: Läufe.**
  `ddev php vendor/bin/phpunit --filter "Finance|Budget|DependenciesContainerWiring"` → grün.
  `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 7: Schärfeprobe.** Im Controller den Dienstaufruf beim Storno durch
  `$reversal = null;` ohne Aufruf ersetzen → Anschluss-Test rot (Zähler 0). Zurücknehmen.
- [x] **Schritt 8: Commit.** Betreff:
  `refactor(finance): Buchen, Importieren und Stornieren im FinanceBookingService`

---

### Task 7: Aufgaben in einen Dienst (Befund 4, Aufgaben)

Reiner Umzug; die Regeln bewachen weiter `TaskFeatureTest` und die übrigen `Task*`-Tests.

**Dateien:**
- Anlegen: `src/Services/TaskWriteService.php`
- Anlegen: `tests/Feature/TaskWriteWiringFeatureTest.php`
- Ändern: `src/Controllers/TaskController.php` (`__construct` Zeile 55, Blöcke Zeile 518,
  605, 712, 878, `use ... Capsule` Zeile 34)
- Ändern: `src/Dependencies.php` (Eintrag `TaskController::class`, Zeile 386)

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Services;

  class TaskWriteService
  {
      /**
       * @param array{name: string, description: ?string, start_date: ?string,
       *     end_date: ?string, status: string, priority: string} $attributes bereits geprüft
       * @param list<int> $assigneeIds
       */
      public function create(int $projectId, array $attributes, array $assigneeIds, int $actorId): Task;

      /**
       * @param \Closure(Task): ?string $describeChanges bekommt die frisch geladene Aufgabe
       *     nach dem Speichern und liefert den Verlaufstext oder null (kein Eintrag)
       */
      public function update(
          Task $task,
          array $attributes,
          array $assigneeIds,
          int $actorId,
          \Closure $describeChanges
      ): void;

      public function addComment(Task $task, string $content, int $actorId): void;

      public function changeStatus(Task $task, string $newStatus, string $activityText, int $actorId): void;
  }
  ```
  Jede Methode läuft in einer Transaktion. Der Verlaufstext entsteht weiter im Controller,
  weil `describeAssigneeChange()` die Namensformatierung des Controllers braucht.
- `TaskController::__construct` bekommt den neuen Parameter `TaskWriteService $taskWriter`
  (vor `?NotificationService $notificationService`); Vorgabe in
  `BuildsControllers::taskController()`: `new TaskWriteService()`.

- [x] **Schritt 1: Anschluss-Test schreiben.** Controller wie in `TaskFeatureTest` bauen, mit
  einer anonymen Unterklasse von `TaskWriteService`, die in `changeStatus()` den Aufruf
  festhält und `parent::changeStatus()` ruft.
  - `testStatusChangeGoesThroughTheWriteService`: `updateStatus()` mit neuem Status; der
    Dienst wurde einmal gerufen, die Aufgabe trägt den neuen Status, und es gibt genau einen
    neuen `Activity`-Eintrag mit `action = 'updated'`.
- [x] **Schritt 2: Rotlauf.** `--filter TaskWriteWiringFeatureTest` → rot.
- [x] **Schritt 3: Dienst schreiben.** Rümpfe aus den vier Closures; `$_SESSION['user_id']`
  wird zu `$actorId`. In `update()` ruft der Dienst nach `update()` und `sync()`
  `$describeChanges($task->fresh())` und schreibt den `Activity`-Eintrag nur bei einem Text.
- [x] **Schritt 4: Controller umstellen.** `validateStatus()` und `validatePriority()` laufen
  im Controller, bevor `$attributes` übergeben wird. In `update()` baut der Controller die
  Closure aus dem bisherigen Änderungsblock (Zeile 632–655) und berechnet
  `$addedAssignedIds` nach dem Dienstaufruf über
  `$this->assigneeDiff($oldAssignedIds, $this->assigneeIds($task->fresh()))['added']`.
  Benachrichtigungen bleiben hinter dem Dienstaufruf. Der `Capsule`-Import entfällt.
- [x] **Schritt 5: Verdrahtung.** Beim `TaskController` `$c->get(TaskWriteService::class)`
  an sechster Stelle einfügen (hinter `LoggerInterface`, vor `NotificationService`).
- [x] **Schritt 6: Läufe.**
  `ddev php vendor/bin/phpunit --filter "Task|DependenciesContainerWiring"` → grün.
  `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 7: Schärfeprobe.** In `changeStatus()` den `Activity`-Eintrag auskommentieren
  → Anschluss-Test rot. Zurücknehmen.
- [x] **Schritt 8: Commit.** Betreff:
  `refactor(tasks): Aufgaben schreiben im TaskWriteService`

---

### Task 8: Anwesenheiten und Vertretungsanmeldungen (Befund 4, Teilnahme)

Reiner Umzug; die Regeln bewachen weiter `AttendanceSaveRobustnessFeatureTest`,
`AttendanceFeatureTest` und die Tests zu Vertretungsanmeldungen.

**Dateien:**
- Anlegen: `src/Services/ParticipationWriter.php`
- Anlegen: `tests/Feature/ParticipationWriterWiringFeatureTest.php`
- Ändern: `src/Controllers/AttendanceController.php` (`__construct` Zeile 37, Block Zeile
  290–322, `use ... Capsule` Zeile 21)
- Ändern: `src/Controllers/RegistrationController.php` (`__construct` Zeile 41, Block Zeile
  495–548, `use ... Capsule` Zeile 24)

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Services;

  class ParticipationWriter
  {
      /**
       * @param list<array{event_id: int, user_id: int, status: string, note: string}> $rowsToWrite
       * @param list<int> $userIdsToClear
       */
      public function saveAttendances(int $eventId, array $rowsToWrite, array $userIdsToClear): void;

      /**
       * @param list<array{user_id: int, status: string, note: ?string}> $entries
       * @param list<int> $userIdsToClear
       */
      public function saveProxyRegistrations(
          int $eventId,
          array $entries,
          array $userIdsToClear,
          int $actorId
      ): void;
  }
  ```
  Beide Methoden laufen in `Capsule::connection()->transaction(...)`; das ersetzt das
  bisherige `beginTransaction`/`commit`/`rollBack` von Hand.
- `AttendanceController::__construct`: neuer Parameter `ParticipationWriter $writer`.
  `RegistrationController::__construct` (Promotion): neuer Parameter
  `private readonly ParticipationWriter $writer`. Vorgabe in
  `BuildsControllers::attendanceController()` und `::registrationController()`:
  `new ParticipationWriter()`. Beide Controller haben keinen Eintrag in `Dependencies.php`;
  PHP-DI füllt den Pflichtparameter selbst.

- [x] **Schritt 1: Anschluss-Tests schreiben.** Je ein Test mit einer Unterklasse, deren
  Methode wirft:
  - `testAFailingAttendanceWriteIsReportedAndLogged`: `AttendanceController::save()` (die
    Methode, die den Block enthält) antwortet mit Weiterleitung auf `/attendance/<id>`, und
    `$_SESSION['error']` beginnt mit `'Fehler beim Speichern der Anwesenheiten.'`.
  - `testAFailingProxyRegistrationWriteIsReported`: die entsprechende Methode des
    `RegistrationController` setzt `$_SESSION['error']` auf
    `'Fehler beim Speichern der Vertretungseinträge.'`.
- [x] **Schritt 2: Rotlauf.** `--filter ParticipationWriterWiringFeatureTest` → rot.
- [x] **Schritt 3: Dienst schreiben.** `saveAttendances`: `upsert` und `delete` aus Zeile
  293–304. `saveProxyRegistrations`: `updateOrCreate` je Eintrag und `delete` aus Zeile
  523–532.
- [x] **Schritt 4: Controller umstellen.** Im `RegistrationController` bleibt die Schleife,
  die Status prüft und Notizen kürzt (Zeile 500–521), baut aber nur noch `$entries` und
  `$userIdsToClear`. Das `try/catch` mit Log-Eintrag und Fehlermeldung bleibt in beiden
  Controllern um den Dienstaufruf. Beide `Capsule`-Importe entfallen.
- [x] **Schritt 5: Läufe.** `ddev php vendor/bin/phpunit --filter "Attendance|Registration|ParticipationWriter"`
  → grün. `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 6: Schärfeprobe.** Im `AttendanceController` das `catch` um den Dienstaufruf
  entfernen → erster Anschluss-Test rot (Ausnahme statt Meldung). Zurücknehmen.
- [x] **Schritt 7: Commit.** Betreff:
  `refactor(attendance): Anwesenheiten und Vertretungsanmeldungen schreibt der ParticipationWriter`

---

### Task 9: Rohabfragen in die Abfrageschicht (Befund 5)

**Dateien:**
- Anlegen: `src/Queries/EvaluationQuery.php`, `src/Queries/VoiceGroupQuery.php`
- Anlegen: `tests/Feature/EvaluationQueryFeatureTest.php`,
  `tests/Feature/VoiceGroupQueryFeatureTest.php`
- Ändern: `src/Services/PollVoteService.php`
- Ändern: `src/Controllers/EvaluationController.php` (`__construct`, Methoden Zeile 678, 714,
  744, `use ... Capsule` Zeile 23)
- Ändern: `src/Controllers/VoiceGroupController.php` (`__construct`, `membershipCounts()`
  Zeile 61, `use ... Capsule` Zeile 17)
- Ändern: `src/Controllers/PollController.php` (Zeile 78–83, `ownOptionIds()` Zeile 605)
- Ändern: `src/Dependencies.php` (Einträge `EvaluationController::class` Zeile 616,
  `VoiceGroupController::class` Zeile 559)

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Queries;

  class EvaluationQuery
  {
      /** @param list<int> $userIds @return array<int, string> Name der Stimmgruppe mit der kleinsten Kennung */
      public function firstVoiceGroupNameByUser(array $userIds): array;

      /** @param list<int> $eventIds @return array<int, array<int, string>> Termin => Mitglied => Status */
      public function registrationStatusByEvent(array $eventIds): array;

      /** @param list<int> $eventIds @return array<int, int> jeder übergebene Termin, auch mit 0 */
      public function presentCountsByEvent(array $eventIds): array;
  }

  class VoiceGroupQuery
  {
      /** @return array{groups: array<int, int>, sub_voices: array<int, int>} nur vorhandene Zuordnungen */
      public function memberCounts(): array;
  }
  ```
  In `PollVoteService`:
  ```php
  /** @param list<int> $pollIds @return list<int> */
  public function votedPollIds(int $userId, array $pollIds): array;

  /** @return list<int> */
  public function ownOptionIds(Poll $poll, int $userId): array;
  ```
- `EvaluationController::__construct`: neuer Parameter `EvaluationQuery $evaluationQuery` (vor
  `?ProjectMemberPolicy $memberPolicy`). `VoiceGroupController::__construct`: neuer Parameter
  `VoiceGroupQuery $voiceGroupQuery` (vor `?LoggerInterface $logger`). Vorgaben in
  `BuildsControllers`: `new EvaluationQuery()` und `new VoiceGroupQuery()`. Leere
  Eingabelisten liefern in allen Methoden `[]` ohne Abfrage.

- [x] **Schritt 1: Tests schreiben.**
  - `EvaluationQueryFeatureTest::testEventsWithoutAnyonePresentCountZero`: Termin ohne
    Anwesenheitszeile → `presentCountsByEvent([$id])` ist `[$id => 0]`.
  - `EvaluationQueryFeatureTest::testOnlyPresentRowsAreCounted`: zwei Zeilen
    `Attendance::STATUS_PRESENT`, eine mit anderem Status → 2.
  - `EvaluationQueryFeatureTest::testTheVoiceGroupWithTheLowestIdWins`: Mitglied in zwei
    Stimmgruppen → Name der Gruppe mit der kleineren Kennung.
  - `VoiceGroupQueryFeatureTest::testMembersAreCountedPerGroupAndPerSubVoice`: zwei
    Mitglieder in einer neu angelegten Gruppe, eines davon mit Unterstimme → `groups[$g]`
    ist 2, `sub_voices[$s]` ist 1.

  Für die beiden `PollVoteService`-Methoden kein neuer Test: `PollVoteFeatureTest` und
  `PollFeatureTest` prüfen Auswahl und Übersicht bereits über den Controller.
- [x] **Schritt 2: Rotlauf.** `--filter "EvaluationQueryFeatureTest|VoiceGroupQueryFeatureTest"`
  → rot.
- [x] **Schritt 3: Abfrageklassen und die zwei Dienstmethoden schreiben.** Rümpfe aus den
  privaten Controller-Methoden; die Kommentare zur Reihenfolge und zur Null ziehen mit.
- [x] **Schritt 4: Controller umstellen.**
  - `EvaluationController`: die drei privaten Methoden entfallen. Der Aufrufer (Zeile
    580–582) sammelt die Kennungen selbst: Mitglieder aus `$eligibleByEvent`, Termine aus
    `$events`, und für die Anwesenheitsspalte nur vergangene Termine mit
    `attendance_required` – diese Auswahl bleibt im Controller.
  - `VoiceGroupController::membershipCounts()` behält die Vorbelegung mit 0 und legt die
    Zahlen aus `memberCounts()` darüber.
  - `PollController`: Zeile 78–83 wird `$this->votes->votedPollIds($userId,
    $polls->pluck('id')->all())`, die private `ownOptionIds()` entfällt zugunsten des
    Dienstes. Der `DB`-Import bleibt, weil `store()` und `update()` ihn noch für ihre
    Transaktionen brauchen (offener Posten, siehe Task 12).
- [x] **Schritt 5: Verdrahtung.** `EvaluationController`: `$c->get(EvaluationQuery::class)`
  an dritter Stelle (hinter `ProjectQuery`, vor dem `null` für die Policy).
  `VoiceGroupController`: `$c->get(VoiceGroupQuery::class)` an zweiter Stelle (hinter `Twig`,
  vor dem Logger).
- [x] **Schritt 6: Läufe.**
  `ddev php vendor/bin/phpunit --filter "Evaluation|VoiceGroup|Poll|DependenciesContainerWiring"`
  → grün. `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 7: Schärfeprobe.** In `presentCountsByEvent()` das Auffüllen mit 0 entfernen
  → erster Test rot. Zurücknehmen.
- [x] **Schritt 8: Commit.** Betreff:
  `refactor(queries): Auswertungs-, Stimmgruppen- und Umfrageabfragen aus den Controllern`

---

### Task 10: Dienste aus dem Container statt per `new` (Befund 6)

**Regel:** In einem Controller steht `new …Service(` nur im Konstruktor – dort als Rückfall
der schon vorhandenen optionalen Parameter (`$cleanup ?? new EntityCleanupService()`), die
dieser Plan nicht anfasst. Zwei Ausnahmen: `ModalFormService` (Wertobjekt mit
Formular-Kennung) und Dienste, die die Sitzung als erstes Argument bekommen
(`new AttendanceScopeService($_SESSION)`).

**Dateien:**
- Ändern: `tests/Unit/Controllers/ControllerLayeringTest.php`
- Ändern: `tests/Feature/BuildsControllers.php`
- Ändern: `src/Dependencies.php`
- Ändern unter `src/Controllers/`: siehe Tabelle

| Controller | Stellen im Methodenrumpf | Neue Pflichtparameter |
|---|---|---|
| `AttendanceController` | 135, 247 | `EventAudienceService $audienceService` |
| `RegistrationController` | 52, 257, 449, 643, 654 | `private readonly EventAudienceService $audienceService` |
| `DashboardController` | 145, 202, 233 | `EventAudienceService $audienceService`, `PollAudienceService $pollAudience`, `NewsletterPlaceholderService $placeholders` |
| `EvaluationController` | 241, 559 | `EventAudienceService $audienceService` |
| `EventController` | 215, 443, 649, 703, 761, 775, 1037, 1357, 1673 | `EventAudienceService $audienceService`, `CalendarSubscriptionService $subscriptions`, `CalendarFeedService $calendarFeed` |
| `TaskController` | 100, 112 | `CalendarSubscriptionService $subscriptions`, `CalendarFeedService $calendarFeed` |
| `PasswordResetController` | 72–74 | `AccountLoginRevocationService $loginRevocation` |
| `ProfileController` | 395–397 | `AccountLoginRevocationService $loginRevocation` |
| `SponsorController` | 474 | `EntityCleanupService $cleanup` |
| `UserController` | 898 (`??=`) | `NameFormatterService $nameFormatter` |

Jeder Parameter steht vor dem ersten optionalen Parameter seines Konstruktors, hinter den
Pflichtparametern aus Task 4 bis 9 (Globale Vorgaben).

Die Vorgabe in `BuildsControllers` baut jeweils genau den Ausdruck, der heute im Methodenrumpf
steht: `new EventAudienceService()`, `new CalendarSubscriptionService()`,
`new CalendarFeedService(new NameFormatterService())`, `new PollAudienceService()`,
`new NewsletterPlaceholderService(new NameFormatterService())`, `new NameFormatterService()`.
Für `EntityCleanupService` im `SponsorController` und für `AccountLoginRevocationService`
gelten die Argumente der heutigen Stelle (Zeile 474 bzw. 72–76 und 395–399), mit einem
`NullLogger`, wo dort `$this->logger` steht.

Kann PHP-DI einen dieser Dienste nicht selbst bauen (Konstruktor mit Argumenten, die keine
Klassen sind), bekommt er in `Dependencies.php` einen Eintrag, der ihn so zusammensetzt wie
die heutige Stelle im Controller – mit den Diensten aus dem Container.

- [x] **Schritt 1: Wächter schreiben.** In `ControllerLayeringTest`:

```php
    public function testControllersBuildServicesOnlyInTheirConstructor(): void
    {
        $found = [];

        foreach (self::controllerFiles() as $file) {
            $code = self::codeWithoutComments($file);
            preg_match_all('/function\s+(\w+)\s*\(/', $code, $functions, PREG_OFFSET_CAPTURE);
            preg_match_all('/new\s+\\\\?[\w\\\\]*Service\s*\(\s*([^,)]*)/', $code, $news, PREG_OFFSET_CAPTURE);

            foreach ($news[0] as $index => [$expression, $offset]) {
                $method = '';
                foreach ($functions[1] as [$name, $functionOffset]) {
                    if ($functionOffset < $offset) {
                        $method = $name;
                    }
                }

                $sessionBound = trim($news[1][$index][0]) === '$_SESSION';
                if ($method === '__construct' || $sessionBound || str_contains($expression, 'ModalFormService')) {
                    continue;
                }

                $found[] = basename($file, '.php') . '::' . $method;
            }
        }

        $found = array_values(array_unique($found));
        sort($found);
        self::assertSame([], $found, "Dienste per new im Methodenrumpf:\n" . implode("\n", $found));
    }
```

- [x] **Schritt 2: Rotlauf.** `--filter ControllerLayeringTest` → der neue Wächter listet die
  Methoden aus der Tabelle.
- [x] **Schritt 3: Vorgaben ergänzen.** In `BuildsControllers` je neuem Parameter eine Zeile
  in der Methode des Controllers.
- [x] **Schritt 4: Controller umstellen**, einen nach dem anderen laut Tabelle. Die lokale
  Variable `$audienceService` in `EventController::create()` und `::update()` wird durch
  `$this->audienceService` ersetzt; im `UserController` entfällt das `??=` in Zeile 898.
- [x] **Schritt 5: `Dependencies.php` nachziehen.** Bei jedem Controller mit ausdrücklichem
  Eintrag die neuen Argumente an der Stelle einfügen, die der Konstruktor vorgibt.
  `AttendanceController` und `RegistrationController` haben keinen Eintrag und brauchen
  keinen. Meldet der Wächter nach der Umstellung eine Methode, die nicht in der Tabelle
  steht, wird sie nach demselben Muster behoben und unter diesem Schritt notiert.
- [x] **Schritt 6: Läufe.**
  `ddev php vendor/bin/phpunit --filter "ControllerLayeringTest|DependenciesContainerWiring|NotificationWiring|Attendance|Registration|Dashboard|Evaluation|Event|Task|PasswordReset|Profile|Sponsor|User"`
  → grün. `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 7: Schärfeprobe.** In `Dependencies.php` beim `EventController` das Argument
  für `EventAudienceService` streichen →
  `testEveryLayeredControllerResolvesFromTheContainer` (aus Task 4) rot. Zurücknehmen. In
  `AttendanceController` testweise `(new EventAudienceService())->…` in einen Methodenrumpf
  zurückschreiben → Wächter rot. Zurücknehmen.
- [x] **Schritt 8: Commit.** Betreff:
  `refactor(controllers): Dienste kommen aus dem Container, nicht aus dem Methodenrumpf`

---

### Task 11: IMAP-Verbindungstest als Dienst (Befund 7)

**Dateien:**
- Anlegen: `src/Services/ImapConnectionProbe.php`
- Anlegen: `tests/Unit/Services/ImapConnectionProbeTest.php`
- Ändern: `src/Controllers/ProfileController.php` (`__construct` Zeile 76,
  `testMailboxConnection()` Zeile 560–662)
- Ändern: `src/Dependencies.php` (Eintrag `ProfileController::class`, Zeile 405)
- Ändern: `tests/Feature/BuildsControllers.php` (`profileController()`)

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Services;

  class ImapConnectionProbe
  {
      public const OK = 'ok';
      public const BLOCKED = 'blocked';          // Host löst nicht auf eine öffentliche Adresse auf
      public const UNREACHABLE = 'unreachable';  // keine Verbindung
      public const NO_GREETING = 'no_greeting';  // verbunden, aber keine IMAP-Begrüßung

      /**
       * @param ?\Closure(string): string $resolveIp wirft BlockedHostException;
       *     Vorgabe OutboundConnectionGuard::resolvePublicIp(...)
       * @param ?\Closure(string, string): ?string $connect bekommt Zieladresse
       *     (z. B. "ssl://203.0.113.5:993") und Hostnamen für die Zertifikatsprüfung; liefert
       *     die erste Zeile der Gegenstelle, '' wenn keine kam, null wenn keine Verbindung
       */
      public function __construct(?\Closure $resolveIp = null, ?\Closure $connect = null);

      public function probe(string $host, int $port, string $encryption): string;
  }
  ```
  `OK` gilt, wenn die Begrüßung mit `'* '` beginnt. Die Vorgabe für `$connect` enthält den
  heutigen Code aus Zeile 613–640 (Stream-Kontext mit `peer_name`, `verify_peer`,
  `verify_peer_name`, `SNI_enabled`; Zeitlimit 5 Sekunden; `fgets` mit 512 Byte).
- `ProfileController::__construct`: neuer Parameter `ImapConnectionProbe $imapProbe` (hinter
  `loginRevocation` aus Task 10, vor `?RememberLoginService $rememberLoginService`); Vorgabe
  in `BuildsControllers::profileController()`: `new ImapConnectionProbe()`.

- [x] **Schritt 1: Tests schreiben** (ohne Netz, beide Closures vom Test gestellt):
  - `testAGreetingStartingWithAnAsteriskIsASuccess`: `$connect` liefert `"* OK ready\r\n"` →
    `OK`.
  - `testAnythingElseIsNotAnImapServer`: `$connect` liefert `"220 smtp"` → `NO_GREETING`;
    liefert `''` → `NO_GREETING` (DataProvider).
  - `testNoConnectionIsUnreachable`: `$connect` liefert `null` → `UNREACHABLE`.
  - `testABlockedHostIsNeverContacted`: `$resolveIp` wirft `BlockedHostException`, `$connect`
    ruft `self::fail()` → `BLOCKED`.
  - `testTheConnectionGoesToTheResolvedAddressNotTheName`: `$resolveIp` liefert
    `'203.0.113.5'`; `probe('mail.example.org', 993, 'ssl')` ruft `$connect` mit
    `'ssl://203.0.113.5:993'` und `'mail.example.org'`. Eine IPv6-Adresse `'2001:db8::1'`
    ergibt `'ssl://[2001:db8::1]:993'`; Verschlüsselung ungleich `'ssl'` ergibt `tcp://`.
- [x] **Schritt 2: Rotlauf.** `--filter ImapConnectionProbeTest` → rot.
- [x] **Schritt 3: Dienst schreiben.**
- [x] **Schritt 4: Controller umstellen.** `testMailboxConnection()` behält Eingabeprüfung,
  Freigabeliste, den Log-Eintrag `mailbox.test.host_blocked` und die vier Meldungen samt
  JSON- und Weiterleitungs-Zweig; es ordnet nur noch das Ergebnis von `probe()` der Meldung
  zu. `BLOCKED` und `UNREACHABLE` führen beide zu `'Verbindung fehlgeschlagen: Host ist nicht
  erreichbar.'` – der Unterschied darf nach außen nicht sichtbar werden, so wie heute.
- [x] **Schritt 5: Verdrahtung.** Beim `ProfileController` `$c->get(ImapConnectionProbe::class)`
  an siebter Stelle einfügen (hinter `AccountLoginRevocationService`, vor
  `RememberLoginService`).
- [x] **Schritt 6: Läufe.**
  `ddev php vendor/bin/phpunit --filter "ImapConnectionProbe|ProfileMailbox|MailboxAllowlist|ReviewAnswersRun29|DependenciesContainerWiring"`
  → grün. `ddev composer phpcs` → keine Verstöße.
- [x] **Schritt 7: Schärfeprobe.** In `probe()` die Zieladresse aus dem Hostnamen statt aus
  der aufgelösten Adresse bauen → `testTheConnectionGoesToTheResolvedAddressNotTheName` rot.
  Zurücknehmen.
- [x] **Schritt 8: Commit.** Betreff:
  `refactor(profile): IMAP-Verbindungstest als ImapConnectionProbe, ohne Netz prüfbar`

---

### Task 12: Datenbankzugriff bewachen und Abschluss

**Dateien:**
- Ändern: `tests/Unit/Controllers/ControllerLayeringTest.php`

- [x] **Schritt 1: Wächter schreiben.**

```php
    /**
     * Controller, die noch selbst Transaktionen öffnen. Offene Posten aus der
     * Schichtprüfung vom 16.11.2026: Ihre Schreibwege sind atomar, stehen aber
     * am falschen Ort. Wer einen davon anfasst, zieht den Block nach dem Muster
     * von FinanceBookingService in einen Dienst und streicht den Eintrag.
     *
     * @var list<string>
     */
    private const KNOWN_DATABASE_ACCESS = [
        'FinanceAccountController',
        'PollController',
        'SponsorPackageController',
        'SponsoringContactController',
        'SponsorshipController',
    ];

    public function testControllersLeaveTheDatabaseConnectionToTheLayersBelow(): void
    {
        $found = [];

        foreach (self::controllerFiles() as $file) {
            $usesCapsule = preg_match(
                '/Illuminate\\\\Database\\\\Capsule\\\\Manager/',
                self::codeWithoutComments($file)
            ) === 1;
            if ($usesCapsule) {
                $found[] = basename($file, '.php');
            }
        }

        sort($found);
        self::assertSame(self::KNOWN_DATABASE_ACCESS, $found);
    }
```

- [x] **Schritt 2: Lauf.** `--filter ControllerLayeringTest` → 4 Tests grün. Steht ein
  Controller aus Task 6 bis 9 noch in der gefundenen Menge, ist dort ein `Capsule`-Rest
  geblieben; der wird im betreffenden Controller entfernt, nicht in die Liste aufgenommen.
- [x] **Schritt 3: Schärfeprobe.** In `VoiceGroupController` testweise
  `use Illuminate\Database\Capsule\Manager as Capsule;` einfügen → rot. Zurücknehmen.
- [x] **Schritt 4: Volle Suite.** `ddev composer test:parallel` → grün; Zahl der Tests und
  Zusicherungen notieren. `ddev composer phpcs` → keine Verstöße; Dateizahl notieren.
- [x] **Schritt 5: Commit.** Betreff:
  `test(controllers): Wächter über Datenbankzugriffe, offene Posten benannt`
- [x] **Schritt 6: Bericht an Georg.** Was sich geändert hat (je Task ein Satz), was lief
  (Befehle und Zahlen), was offen bleibt (die fünf Controller aus `KNOWN_DATABASE_ACCESS`
  und die Liste unten). Kein Squash, kein Merge, kein Push ohne seine Ansage.

---

## Bewusst nicht Teil dieses Plans

- **Transaktionen in den fünf Controllern aus `KNOWN_DATABASE_ACCESS`.** Gleiche Bauart wie
  Task 6 bis 8, aber fünf weitere Dienste; der Wächter hält sie als offene Posten fest.
- **Statische Fachfunktionen, die nur der eigene Controller und Tests rufen:**
  `FinanceController::computeDefaultStartYear`, `DownloadController::splitByEnd`,
  `RoleController::buildPermissionFlags` und `::permissionDiff`,
  `UserNotificationController::relativeTime`, `SponsoringDashboardController::dueLabel`. Sie
  verletzen keine Abhängigkeitsrichtung; ein Umzug würde ein gutes Dutzend Testdateien
  umschreiben, ohne dass sich an der Schichtung etwas ändert.
- **Eloquent-Lesezugriffe in Controllern** (`Model::where(...)` für Listen und Einzelsätze).
  Eine Abfrageklasse je Controller wäre ein eigenes Vorhaben.
- **Aufteilen der großen Controller** (`EventController`, `NewsletterController`,
  `UserController`). Sie werden durch die Tasks kleiner, aber nicht zerlegt.

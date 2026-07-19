# Anmeldemodul (Event Registration) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitglieder (oder stellvertretend Stimmvertretungen) melden sich zu freigeschalteten zukünftigen Terminen an (Zusage/Absage/Vielleicht), mit Auswertung, Erinnerungsmail und pro Termin abschaltbarer Anwesenheitsliste.

**Architecture:** Neue Tabelle `event_registrations` + vier neue `events`-Spalten (Phinx). Neuer `RegistrationController` hinter env-Feature-Flag `FEATURE_REGISTRATION` (bestehendes `modules`-Muster). Gemeinsame Berechtigungslogik wird aus `AttendanceController` in `AttendanceScopeService` extrahiert. Erinnerungsmails laufen über die bestehende Mail-Queue (opportunistische Middleware + CLI-Command). Login-Redirect wird als neue Kern-Fähigkeit ergänzt.

**Tech Stack:** Slim 4 + PHP-DI, Eloquent (Capsule), Twig, Phinx, PHPUnit 13, Bootstrap 5.

**Spec:** `docs/superpowers/specs/2026-07-09-event-registration-design.md`

**Branch:** `feature/event-registration` (bereits ausgecheckt; niemals `git push`).

## Global Constraints

- Alle Projekt-Kommandos via DDEV: `ddev composer …`, `ddev php …`, `ddev exec …`.
- Tests: `ddev exec vendor/bin/phpunit --filter <TestName>` (einzeln) bzw. `ddev composer test` (gesamt).
- Migration: `ddev exec ./vendor/bin/phinx migrate`.
- PHP: PSR-12, 4 Spaces, Zeilenlänge soft 120 / hard 130, `declare(strict_types=1);`. Nach substanziellen PHP-Änderungen `ddev composer phpcs` (fix: `ddev composer phpcbf`).
- Twig: doppelte Anführungszeichen, keine Leerzeichen um `=` bei benannten Argument-Defaults, 1 Leerzeichen um binäre Operatoren, keine mehrzeiligen Boolean-Ausdrücke (Sub-Bedingungen in `{% set %}`), Zeilenlänge soft 120 / hard 130. Nach Template-Änderungen `ddev composer twigcs` (fix: `ddev composer twigcbf`).
- Kein Inline-JS/CSS in Templates (Ausnahme: `templates/emails/` darf Inline-Styles). CSS-Klassen nach `public/css/style.css`.
- Keine externen CDNs.
- UI-Texte deutsch mit echten Umlauten (ä/ö/ü/ß, niemals ae/oe/ue/ss).
- Logging via `Psr\Log\LoggerInterface`, strukturiert mit `event`-Key, Exceptions im `exception`-Kontext. Kein `error_log()`.
- Neue Textdateien mit LF-Zeilenenden; nach Datei-Schreiboperationen auf Windows normalisieren:
  `$f = "<absolute-path>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
  (Der Repo-Hook prüft LF beim Commit — Commit schlägt sonst fehl.)
- Commits enden mit `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Niemals `git push`.

---

### Task 1: Migration + Modelle

**Files:**
- Create: `db/migrations/20260709120000_create_event_registrations.php`
- Create: `src/Models/EventRegistration.php`
- Modify: `src/Models/Event.php` (fillable, casts, Relationen, Helper)
- Modify: `src/Models/User.php` (Relation `eventRegistrations()`)
- Test: `tests/Feature/EventRegistrationModelFeatureTest.php`

**Interfaces:**
- Consumes: bestehende Modelle `Event`, `User`.
- Produces:
  - `App\Models\EventRegistration` — Konstanten `STATUS_YES = 'yes'`, `STATUS_NO = 'no'`, `STATUS_MAYBE = 'maybe'`, `STATUSES = [self::STATUS_YES, self::STATUS_NO, self::STATUS_MAYBE]`; Relationen `event()`, `user()`, `updatedBy()`; Timestamps aktiv.
  - `Event::registrations()` (hasMany), `Event::registrationDeadlineAt(): \Carbon\Carbon`, `Event::isRegistrationOpen(): bool`.
  - `User::eventRegistrations()` (hasMany).
  - Neue `events`-Spalten: `registration_enabled` (bool, default 0), `registration_deadline` (datetime null), `registration_reminder_sent_at` (datetime null), `attendance_required` (bool, default 1).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/EventRegistrationModelFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Bootstrap;

class EventRegistrationModelFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    public function testMigrationDefinesRegistrationSchema(): void
    {
        $migrationPath = dirname(__DIR__)
            . '/../db/migrations/20260709120000_create_event_registrations.php';
        $this->assertFileExists($migrationPath);

        $content = file_get_contents($migrationPath);
        $this->assertIsString($content);
        $this->assertStringContainsString("'event_registrations'", $content);
        $this->assertStringContainsString("'registration_enabled'", $content);
        $this->assertStringContainsString("'registration_deadline'", $content);
        $this->assertStringContainsString("'registration_reminder_sent_at'", $content);
        $this->assertStringContainsString("'attendance_required'", $content);
        $this->assertStringContainsString("['event_id', 'user_id'], ['unique' => true]", $content);
    }

    public function testEventRegistrationModelRoundTrip(): void
    {
        $user = User::where('is_active', 1)->firstOrFail();
        $event = Event::create([
            'title' => 'Testprobe Anmeldung',
            'starts_at' => Carbon::now()->addDays(7)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(7)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => EventRegistration::STATUS_YES,
            'note' => null,
            'updated_by' => $user->id,
        ]);

        $fresh = EventRegistration::find($registration->id);
        $this->assertSame('yes', $fresh->status);
        $this->assertSame((int) $user->id, (int) $fresh->user->id);
        $this->assertSame((int) $event->id, (int) $fresh->event->id);
        $this->assertSame((int) $user->id, (int) $fresh->updatedBy->id);
        $this->assertNotNull($fresh->created_at);
        $this->assertCount(1, $event->registrations()->get());
        $this->assertTrue($user->eventRegistrations()->count() >= 1);

        $registration->delete();
        $event->delete();
    }

    public function testRegistrationDeadlineHelpers(): void
    {
        $event = new Event([
            'starts_at' => Carbon::now()->addDays(3),
            'registration_enabled' => true,
        ]);
        $this->assertTrue($event->isRegistrationOpen());
        $this->assertSame(
            $event->starts_at->toDateTimeString(),
            $event->registrationDeadlineAt()->toDateTimeString()
        );

        $event->registration_deadline = Carbon::now()->subHour();
        $this->assertFalse($event->isRegistrationOpen());

        $event->registration_deadline = Carbon::now()->addHour();
        $this->assertTrue($event->isRegistrationOpen());

        $disabled = new Event([
            'starts_at' => Carbon::now()->addDays(3),
            'registration_enabled' => false,
        ]);
        $this->assertFalse($disabled->isRegistrationOpen());

        $past = new Event([
            'starts_at' => Carbon::now()->subDay(),
            'registration_enabled' => true,
        ]);
        $this->assertFalse($past->isRegistrationOpen());
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter EventRegistrationModelFeatureTest`
Expected: FAIL (Migration fehlt, Klasse `EventRegistration` fehlt).

- [ ] **Step 3: Migration schreiben**

`db/migrations/20260709120000_create_event_registrations.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEventRegistrations extends AbstractMigration
{
    public function up(): void
    {
        $this->table('event_registrations')
            ->addColumn('event_id', 'integer', ['signed' => false])
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('status', 'enum', ['values' => ['yes', 'no', 'maybe']])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('updated_by', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['event_id', 'user_id'], ['unique' => true])
            ->addForeignKey('event_id', 'events', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('updated_by', 'users', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('events')
            ->addColumn('registration_enabled', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('registration_deadline', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('registration_reminder_sent_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('attendance_required', 'boolean', ['default' => true, 'null' => false])
            ->save();
    }

    public function down(): void
    {
        $this->table('event_registrations')->drop()->save();

        $this->table('events')
            ->removeColumn('registration_enabled')
            ->removeColumn('registration_deadline')
            ->removeColumn('registration_reminder_sent_at')
            ->removeColumn('attendance_required')
            ->save();
    }
}
```

Hinweis: Falls `users.id`/`events.id` im Schema signed sind (prüfen in `db/migrations/20260314130000_initial.php`), die `'signed' => false`-Optionen an das Bestandsschema anpassen, sonst schlagen die Foreign Keys fehl.

- [ ] **Step 4: Migration ausführen**

Run: `ddev exec ./vendor/bin/phinx migrate`
Expected: `20260709120000 CreateEventRegistrations: migrated`. Fehler melden, nicht verschlucken.

- [ ] **Step 5: Modelle schreiben**

`src/Models/EventRegistration.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventRegistration extends Model
{
    public const STATUS_YES = 'yes';
    public const STATUS_NO = 'no';
    public const STATUS_MAYBE = 'maybe';
    public const STATUSES = [self::STATUS_YES, self::STATUS_NO, self::STATUS_MAYBE];

    protected $table = 'event_registrations';

    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'note',
        'updated_by'
    ];

    protected $casts = [
        'event_id' => 'integer',
        'user_id' => 'integer',
        'updated_by' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }
}
```

In `src/Models/Event.php` `fillable` erweitern (nach `'location'`):

```php
        'location',
        'registration_enabled',
        'registration_deadline',
        'registration_reminder_sent_at',
        'attendance_required'
```

`casts` erweitern:

```php
        'registration_enabled' => 'boolean',
        'registration_deadline' => 'datetime',
        'registration_reminder_sent_at' => 'datetime',
        'attendance_required' => 'boolean',
```

Neue Methoden in `Event` (nach `attendances()`):

```php
    public function registrations()
    {
        return $this->hasMany(EventRegistration::class, 'event_id', 'id');
    }

    public function registrationDeadlineAt(): \Carbon\Carbon
    {
        $deadline = $this->registration_deadline ?? $this->starts_at;

        return \Carbon\Carbon::parse($deadline);
    }

    public function isRegistrationOpen(): bool
    {
        if (!(bool) $this->registration_enabled) {
            return false;
        }

        return $this->registrationDeadlineAt()->isFuture()
            && \Carbon\Carbon::parse($this->starts_at)->isFuture();
    }
```

In `src/Models/User.php` (neben der bestehenden `attendances()`-Relation):

```php
    public function eventRegistrations()
    {
        return $this->hasMany(EventRegistration::class, 'user_id', 'id');
    }
```

- [ ] **Step 6: Test ausführen — muss bestehen**

Run: `ddev exec vendor/bin/phpunit --filter EventRegistrationModelFeatureTest`
Expected: PASS (3 Tests).

- [ ] **Step 7: phpcs + Commit**

```bash
ddev composer phpcs
git add db/migrations/20260709120000_create_event_registrations.php src/Models/EventRegistration.php src/Models/Event.php src/Models/User.php tests/Feature/EventRegistrationModelFeatureTest.php
git commit -m "feat: Migration und Modelle fuer Termin-Anmeldungen"
```

---

### Task 2: Feature-Flag FEATURE_REGISTRATION

**Files:**
- Modify: `src/Settings.php` (modules-Array)
- Modify: `.env.example`, `.env` (Dev: aktivieren), `dist/.env.example`, `dist/docker-compose.prod.yml`
- Test: `tests/Feature/RegistrationFeatureFlagTest.php`

**Interfaces:**
- Produces: `$settings['modules']['registration']` (bool) — Routes.php und Templates (`settings.modules.registration`) verlassen sich darauf.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/RegistrationFeatureFlagTest.php` (Muster: `TaskFeatureFlagTest`):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class RegistrationFeatureFlagTest extends TestCase
{
    public function testSettingsExposeRegistrationFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'registration'\\s*=>\\s*EnvHelper::read\\('FEATURE_REGISTRATION', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testEnvExamplesAndProdComposeDocumentFeatureRegistration(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_REGISTRATION=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_REGISTRATION=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_REGISTRATION: ${FEATURE_REGISTRATION:-false}', $compose);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationFeatureFlagTest`
Expected: FAIL.

- [ ] **Step 3: Flag implementieren**

`src/Settings.php`, im `modules`-Array nach `'tasks'`:

```php
                'tasks'         => EnvHelper::read('FEATURE_TASKS', 'false') === 'true',
                'registration'  => EnvHelper::read('FEATURE_REGISTRATION', 'false') === 'true',
```

`.env.example` und `.env` (bei den anderen `FEATURE_*`-Zeilen): in `.env.example` `FEATURE_REGISTRATION=false`, in `.env` (Dev) `FEATURE_REGISTRATION=true`. `dist/.env.example`: `FEATURE_REGISTRATION=false`. `dist/docker-compose.prod.yml` (bei den anderen `FEATURE_*`-Einträgen im `environment`-Block): `FEATURE_REGISTRATION: ${FEATURE_REGISTRATION:-false}`.

- [ ] **Step 4: Test ausführen — muss bestehen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationFeatureFlagTest`
Expected: PASS. Danach `ddev restart` ist NICHT nötig; `.env` wird pro Request gelesen (EnvHelper) — falls das Flag im Browser nicht wirkt, `ddev restart` ausführen.

- [ ] **Step 5: Commit**

```bash
git add src/Settings.php .env.example dist/.env.example dist/docker-compose.prod.yml tests/Feature/RegistrationFeatureFlagTest.php
git commit -m "feat: Feature-Flag FEATURE_REGISTRATION"
```

Hinweis: `.env` ist nicht eingecheckt (gitignore) — lokal trotzdem setzen.

---

### Task 3: AttendanceScopeService (Extraktion)

**Files:**
- Create: `src/Services/AttendanceScopeService.php`
- Modify: `src/Controllers/AttendanceController.php` (private Methode `getManageableUserIds()` durch Service ersetzen)
- Modify: `src/Dependencies.php` (nur falls Controller-Wiring dort explizit ist; PHP-DI Autowiring reicht sonst)
- Test: `tests/Feature/AttendanceScopeServiceFeatureTest.php`

**Interfaces:**
- Produces: `App\Services\AttendanceScopeService` mit:
  - `getManageableUserIds(): array` — int-Array; Logik identisch zur bisherigen privaten Methode (Session-basiert: `can_manage_users`, `role_level`, `voice_group_ids`).
  - `canManageOthers(): bool` — true wenn `can_manage_users` oder `role_level >= 40`.
- Consumers: `AttendanceController::save()` (Task 3), `RegistrationController` (Tasks 5–7).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/AttendanceScopeServiceFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AttendanceScopeService;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Bootstrap;

class AttendanceScopeServiceFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAdminManagesAllActiveUsers(): void
    {
        $_SESSION['can_manage_users'] = true;

        $service = new AttendanceScopeService();
        $ids = $service->getManageableUserIds();

        $this->assertSame(User::where('is_active', 1)->count(), count($ids));
        $this->assertTrue($service->canManageOthers());
    }

    public function testVoiceGroupRepManagesOnlyOwnGroups(): void
    {
        $rep = User::where('is_active', 1)
            ->whereHas('voiceGroups')
            ->firstOrFail();
        $groupIds = $rep->voiceGroups->pluck('id')->map(fn ($id) => (int) $id)->all();

        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 50;
        $_SESSION['voice_group_ids'] = $groupIds;

        $service = new AttendanceScopeService();
        $ids = $service->getManageableUserIds();

        $expected = User::whereHas('voiceGroups', function ($q) use ($groupIds) {
            $q->whereIn('voice_group_id', $groupIds);
        })->where('is_active', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();

        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertTrue($service->canManageOthers());
    }

    public function testPlainMemberManagesNobody(): void
    {
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [];

        $service = new AttendanceScopeService();

        $this->assertSame([], $service->getManageableUserIds());
        $this->assertFalse($service->canManageOthers());
    }

    public function testAttendanceControllerUsesService(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AttendanceController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('AttendanceScopeService', $controller);
        $this->assertStringNotContainsString('private function getManageableUserIds', $controller);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter AttendanceScopeServiceFeatureTest`
Expected: FAIL (Klasse fehlt).

- [ ] **Step 3: Service implementieren**

`src/Services/AttendanceScopeService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Session-based scope: which users may the current user manage
 * in attendance and registration contexts.
 */
class AttendanceScopeService
{
    public function canManageOthers(): bool
    {
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $roleLevel = (int) ($_SESSION['role_level'] ?? 0);

        return $canManageUsers || $roleLevel >= 40;
    }

    /**
     * @return array<int>
     */
    public function getManageableUserIds(): array
    {
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $userVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];
        $roleLevel = (int) ($_SESSION['role_level'] ?? 0);

        if (!$canManageUsers && $roleLevel < 40) {
            return [];
        }

        if (!$canManageUsers && $roleLevel < 80) {
            if (empty($userVoiceGroupIds)) {
                return [];
            }

            return User::whereHas('voiceGroups', function ($query) use ($userVoiceGroupIds) {
                $query->whereIn('voice_group_id', $userVoiceGroupIds);
            })
                ->where('is_active', 1)
                ->pluck('id')
                ->map(static fn($id) => (int) $id)
                ->all();
        }

        return User::where('is_active', 1)
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->all();
    }
}
```

WICHTIG — Verhaltensabweichung zur alten privaten Methode: Die alte Methode gab bei `role_level < 80` ohne `can_manage_users` und MIT Stimmgruppen die Gruppen-User zurück, auch bei `role_level < 40`. Der neue frühe Return (`role_level < 40` ⇒ `[]`) ist strenger und korrekt, weil die Attendance-Routen ohnehin `requiresAttendanceManagement` (Level ≥ 40 bzw. `can_manage_attendance`) verlangen. Falls `RoleMiddlewareFeatureTest`/bestehende Attendance-Tests dadurch brechen: frühen Return entfernen und exakt die alte Logik behalten.

`src/Controllers/AttendanceController.php`: Konstruktor erweitern:

```php
    private Twig $view;
    private AttendanceScopeService $scopeService;

    public function __construct(Twig $view, AttendanceScopeService $scopeService)
    {
        $this->view = $view;
        $this->scopeService = $scopeService;
    }
```

`use App\Services\AttendanceScopeService;` ergänzen. In `save()`:

```php
        $allowedUserIds = $this->scopeService->getManageableUserIds();
```

Die private Methode `getManageableUserIds()` komplett löschen. PHP-DI wired `AttendanceScopeService` per Autowiring — prüfen, ob `AttendanceController` in `src/Dependencies.php` manuell definiert ist (`grep -n "AttendanceController" src/Dependencies.php`); falls ja, dort den zweiten Konstruktor-Parameter ergänzen.

- [ ] **Step 4: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "AttendanceScopeServiceFeatureTest|AttendanceFeatureTest"`
Expected: PASS. `AttendanceFeatureTest::testAttendancePermissionMigrationExists` prüft den String `$allowedUserIds = $this->getManageableUserIds();` und `private function getManageableUserIds(): array` im Controller — dieser Test MUSS angepasst werden: beide Assertions ersetzen durch:

```php
        $this->assertStringContainsString('$allowedUserIds = $this->scopeService->getManageableUserIds();', $controllerContent);
        $this->assertStringContainsString('AttendanceScopeService', $controllerContent);
```

- [ ] **Step 5: phpcs + Commit**

```bash
ddev composer phpcs
git add src/Services/AttendanceScopeService.php src/Controllers/AttendanceController.php tests/Feature/AttendanceScopeServiceFeatureTest.php tests/Feature/AttendanceFeatureTest.php
git commit -m "refactor: Anwesenheits-Berechtigungslogik in AttendanceScopeService extrahiert"
```

---

### Task 4: Login-Redirect (SafeRedirect + AuthMiddleware + AuthController)

**Files:**
- Create: `src/Util/SafeRedirect.php`
- Modify: `src/Middleware/AuthMiddleware.php` (Zeilen 70–73: Redirect mit `?redirect=`)
- Modify: `src/Controllers/AuthController.php` (`showLogin`, `processLogin`)
- Modify: `templates/auth/login.twig` (Hidden-Field)
- Test: `tests/Feature/LoginRedirectFeatureTest.php`

**Interfaces:**
- Produces: `App\Util\SafeRedirect::sanitize(?string $target): ?string` — gibt validierten relativen Pfad zurück oder `null`. Regeln: nicht leer, beginnt mit genau einem `/`, kein `//`-Prefix, kein `\`, kein `://`, keine Steuerzeichen, max. 512 Zeichen.
- Consumers: `AuthController::processLogin` (Redirect nach Login), Erinnerungsmail-Link (Task 12/13 verlinkt direkt auf `/registrations/{id}`; AuthMiddleware hängt `redirect` automatisch an).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/LoginRedirectFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\SafeRedirect;
use PHPUnit\Framework\TestCase;

class LoginRedirectFeatureTest extends TestCase
{
    public function testValidRelativePathsPass(): void
    {
        $this->assertSame('/registrations/5', SafeRedirect::sanitize('/registrations/5'));
        $this->assertSame('/events?sort=title', SafeRedirect::sanitize('/events?sort=title'));
    }

    public function testMaliciousTargetsAreRejected(): void
    {
        $this->assertNull(SafeRedirect::sanitize(null));
        $this->assertNull(SafeRedirect::sanitize(''));
        $this->assertNull(SafeRedirect::sanitize('//evil.example'));
        $this->assertNull(SafeRedirect::sanitize('https://evil.example/x'));
        $this->assertNull(SafeRedirect::sanitize('http://evil.example'));
        $this->assertNull(SafeRedirect::sanitize('/valid\\..\\backslash'));
        $this->assertNull(SafeRedirect::sanitize('javascript:alert(1)'));
        $this->assertNull(SafeRedirect::sanitize('relative/path'));
        $this->assertNull(SafeRedirect::sanitize("/line\nbreak"));
        $this->assertNull(SafeRedirect::sanitize('/' . str_repeat('a', 600)));
    }

    public function testAuthMiddlewarePreservesRequestPath(): void
    {
        $middleware = file_get_contents(dirname(__DIR__) . '/../src/Middleware/AuthMiddleware.php');
        $this->assertIsString($middleware);
        $this->assertStringContainsString("'/login?redirect='", $middleware);
    }

    public function testLoginFormCarriesRedirectField(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/auth/login.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('name="redirect"', $template);
    }

    public function testProcessLoginUsesSafeRedirect(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AuthController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('SafeRedirect::sanitize', $controller);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter LoginRedirectFeatureTest`
Expected: FAIL.

- [ ] **Step 3: Implementieren**

`src/Util/SafeRedirect.php`:

```php
<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Validates post-login redirect targets to prevent open redirects.
 * Only same-origin relative paths are allowed.
 */
final class SafeRedirect
{
    public static function sanitize(?string $target): ?string
    {
        if ($target === null || $target === '') {
            return null;
        }

        if (strlen($target) > 512) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return null;
        }

        if (str_contains($target, '\\') || str_contains($target, '://')) {
            return null;
        }

        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        return $target;
    }
}
```

`src/Middleware/AuthMiddleware.php` — die zwei Nicht-eingeloggt-Redirects (Zeile 70–73 und der `session_valid_after`-Fall Zeile 80–85) ersetzen. Neue private Methode am Klassenende:

```php
    private function redirectToLogin(Request $request): Response
    {
        $target = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        $location = '/login';
        if (strtoupper($request->getMethod()) === 'GET' && $target !== '' && $target !== '/dashboard') {
            $location = '/login?redirect=' . rawurlencode($target);
        }

        $response = new SlimResponse();
        return $response->withHeader('Location', $location)->withStatus(302);
    }
```

Aufrufstellen (drei Stück: Zeile 70–73, 80–85, 95–98) ersetzen durch `return $this->redirectToLogin($request);`.

`src/Controllers/AuthController.php`:
- `use App\Util\SafeRedirect;` ergänzen.
- `showLogin`: Query-Param an Template geben:

```php
        $redirect = SafeRedirect::sanitize((string) ($request->getQueryParams()['redirect'] ?? ''));
        // ... im render-Array ergänzen:
        'redirect' => $redirect,
```

- `processLogin`: Erfolgs-Redirect (Zeile 114) ersetzen:

```php
            $redirect = SafeRedirect::sanitize((string) ($data['redirect'] ?? ''));

            return $response->withHeader('Location', $redirect ?? '/dashboard')->withStatus(302);
```

- Fehler-Redirects in `processLogin` (ungültige Zugangsdaten, Rate-Limit): `redirect` beibehalten, damit der Nutzer ihn beim zweiten Versuch nicht verliert:

```php
        $failureLocation = '/login';
        $redirect = SafeRedirect::sanitize((string) ($data['redirect'] ?? ''));
        if ($redirect !== null) {
            $failureLocation = '/login?redirect=' . rawurlencode($redirect);
        }
```

und `'/login'` in den Fehlerpfaden durch `$failureLocation` ersetzen (`$data` wird dafür vor den Guards gelesen — steht bereits in Zeile 76).

`templates/auth/login.twig` — im `<form method="post" action="/login">` ergänzen:

```twig
    {% if redirect %}
        <input type="hidden" name="redirect" value="{{ redirect }}">
    {% endif %}
```

- [ ] **Step 4: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "LoginRedirectFeatureTest|AuthFeatureTest|AuthMiddlewareSessionInvalidationFeatureTest"`
Expected: PASS. Bestehende Auth-Tests, die exakt `Location: /login` asserten, ggf. auf neue Query-Variante anpassen (nur wo GET-Pfade betroffen sind).

- [ ] **Step 5: phpcs, twigcs + Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Util/SafeRedirect.php src/Middleware/AuthMiddleware.php src/Controllers/AuthController.php templates/auth/login.twig tests/Feature/LoginRedirectFeatureTest.php
git commit -m "feat: Login-Redirect mit Open-Redirect-Schutz"
```

---

### Task 5: RegistrationController — Liste + Detail (GET) + Routen + Navigation

**Files:**
- Create: `src/Controllers/RegistrationController.php`
- Create: `templates/registrations/index.twig`, `templates/registrations/detail.twig`
- Modify: `src/Routes.php` (Feature-Gate + Routen), `templates/partials/navigation/events.twig` (Nav-Link)
- Modify: `public/css/style.css` (Status-Badge-Klassen)
- Test: `tests/Feature/RegistrationFeatureTest.php`

**Interfaces:**
- Consumes: `EventRegistration`, `Event::isRegistrationOpen()`, `AttendanceScopeService` (Task 3).
- Produces:
  - `RegistrationController::index(Request, Response): Response` — GET `/registrations`.
  - `RegistrationController::detail(Request, Response, array $args): Response` — GET `/registrations/{event_id}`.
  - Private Helper, die Tasks 6/7 mitnutzen:
    - `findRegistrationEvent(int $eventId): ?Event` — Event mit `registration_enabled`, sonst null.
    - `eligibleUsers(Event $event)` — Collection aktiver User (bei `project_id`: nur Projektmitglieder), mit `voiceGroups` und `eventRegistrations` (für dieses Event) eager-geladen.
  - Twig-Kontext `detail.twig`: `event`, `voice_groups` (Array `Gruppenname => [ ['user_id','first_name','last_name','status','note','updated_by_name','editable'] ]`), `own_registration`, `counts` (`['yes'=>int,'no'=>int,'maybe'=>int,'open'=>int]`), `response_rate` (int Prozent), `registration_open` (bool), `can_manage_others` (bool).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/RegistrationFeatureTest.php` (Struktur- und Routen-Gate-Test, Muster `TaskFeatureFlagTest::registeredTaskRoutePatterns`):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

class RegistrationFeatureTest extends TestCase
{
    public function testRegistrationStructureExists(): void
    {
        $this->assertTrue(class_exists(RegistrationController::class));
        $this->assertTrue(method_exists(RegistrationController::class, 'index'));
        $this->assertTrue(method_exists(RegistrationController::class, 'detail'));

        $this->assertFileExists(dirname(__DIR__) . '/../templates/registrations/index.twig');
        $this->assertFileExists(dirname(__DIR__) . '/../templates/registrations/detail.twig');

        $nav = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/events.twig');
        $this->assertIsString($nav);
        $this->assertStringContainsString('settings.modules.registration', $nav);
        $this->assertStringContainsString('href="/registrations"', $nav);
    }

    public function testRegistrationRoutesRespectFeatureFlag(): void
    {
        $this->assertSame([], $this->registeredRegistrationRoutePatterns(false));

        $enabled = $this->registeredRegistrationRoutePatterns(true);
        $this->assertContains('/registrations', $enabled);
        $this->assertContains('/registrations/{event_id:[0-9]+}', $enabled);
    }

    /**
     * @return string[]
     */
    private function registeredRegistrationRoutePatterns(bool $enabled): array
    {
        $settings = ['modules' => ['registration' => $enabled]];

        $container = new class ($settings) implements \Psr\Container\ContainerInterface {
            public function __construct(private array $settings)
            {
            }

            public function get(string $id): mixed
            {
                return $id === 'settings' ? $this->settings : null;
            }

            public function has(string $id): bool
            {
                return $id === 'settings';
            }
        };

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $routes = require dirname(__DIR__, 2) . '/src/Routes.php';
        $routes($app);

        $patterns = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), '/registrations')) {
                $patterns[] = $route->getPattern();
            }
        }

        return array_values(array_unique($patterns));
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationFeatureTest`
Expected: FAIL.

- [ ] **Step 3: Controller implementieren (GET-Teil)**

`src/Controllers/RegistrationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

class RegistrationController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AttendanceScopeService $scopeService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $events = Event::where('registration_enabled', true)
            ->where('starts_at', '>', Carbon::now())
            ->orderBy('starts_at', 'asc')
            ->withCount([
                'registrations as yes_count' => fn($q) => $q->where('status', EventRegistration::STATUS_YES),
                'registrations as no_count' => fn($q) => $q->where('status', EventRegistration::STATUS_NO),
                'registrations as maybe_count' => fn($q) => $q->where('status', EventRegistration::STATUS_MAYBE),
            ])
            ->with(['registrations' => fn($q) => $q->where('user_id', $userId)])
            ->get();

        $rows = [];
        foreach ($events as $event) {
            $own = $event->registrations->first();
            $rows[] = [
                'event' => $event,
                'own_status' => $own?->status,
                'open' => $event->isRegistrationOpen(),
                'eligible_count' => $this->eligibleUsers($event)->count(),
            ];
        }

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'registrations/index.twig', [
            'rows' => $rows,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $event = $this->findRegistrationEvent((int) $args['event_id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden oder Anmeldung nicht freigeschaltet.';
            return $response->withHeader('Location', '/registrations')->withStatus(302);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $manageableIds = $this->scopeService->getManageableUserIds();
        $users = $this->eligibleUsers($event);

        $voiceGroups = [];
        $counts = ['yes' => 0, 'no' => 0, 'maybe' => 0, 'open' => 0];
        $ownRegistration = null;

        foreach ($users as $user) {
            $registration = $user->eventRegistrations->first();
            $status = $registration?->status;
            $counts[$status ?? 'open']++;

            if ((int) $user->id === $userId) {
                $ownRegistration = $registration;
            }

            $voiceGroup = $user->voiceGroups->first();
            $groupName = $voiceGroup ? $voiceGroup->name : 'Ohne Stimmgruppe';

            $voiceGroups[$groupName][] = [
                'user_id' => (int) $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'status' => $status,
                'note' => $registration?->note,
                'updated_by_name' => $this->proxyName($registration),
                'editable' => in_array((int) $user->id, $manageableIds, true),
            ];
        }

        ksort($voiceGroups);
        if (isset($voiceGroups['Ohne Stimmgruppe'])) {
            $ungrouped = $voiceGroups['Ohne Stimmgruppe'];
            unset($voiceGroups['Ohne Stimmgruppe']);
            $voiceGroups['Ohne Stimmgruppe'] = $ungrouped;
        }

        $total = $users->count();
        $answered = $total - $counts['open'];

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'registrations/detail.twig', [
            'event' => $event,
            'voice_groups' => $voiceGroups,
            'own_registration' => $ownRegistration,
            'counts' => $counts,
            'total_eligible' => $total,
            'answered' => $answered,
            'response_rate' => $total > 0 ? (int) round($answered * 100 / $total) : 0,
            'registration_open' => $event->isRegistrationOpen(),
            'can_manage_others' => $this->scopeService->canManageOthers(),
            'success' => $success,
            'error' => $error
        ]);
    }

    private function findRegistrationEvent(int $eventId): ?Event
    {
        $event = Event::find($eventId);
        if (!$event || !(bool) $event->registration_enabled) {
            return null;
        }

        return $event;
    }

    /**
     * Active users eligible for this event: project members for
     * project-bound events, otherwise all active users.
     */
    private function eligibleUsers(Event $event)
    {
        $query = User::where('is_active', 1);

        if ($event->project_id !== null) {
            $query->whereHas('projects', function ($projectQuery) use ($event) {
                $projectQuery->where('projects.id', (int) $event->project_id);
            });
        }

        return $query
            ->with([
                'voiceGroups',
                'eventRegistrations' => fn($q) => $q->where('event_id', (int) $event->id),
            ])
            ->get()
            ->sortBy(['last_name', 'first_name'])
            ->values();
    }

    private function proxyName(?EventRegistration $registration): ?string
    {
        if (!$registration || !$registration->updated_by) {
            return null;
        }

        if ((int) $registration->updated_by === (int) $registration->user_id) {
            return null;
        }

        $updatedBy = User::find($registration->updated_by);

        return $updatedBy ? trim($updatedBy->first_name . ' ' . $updatedBy->last_name) : null;
    }
}
```

- [ ] **Step 4: Routen registrieren**

`src/Routes.php` — `use App\Controllers\RegistrationController;` ergänzen. Innerhalb der Protected-Group, nach dem Attendance-Block (Zeile 143):

```php
            // Registration Routes (Anmeldung zu zukuenftigen Terminen)
            if ($settings['modules']['registration'] ?? false) {
                $group->get('/registrations', [RegistrationController::class, 'index']);
                $group->get('/registrations/{event_id:[0-9]+}', [RegistrationController::class, 'detail']);
            }
```

(POST-Routen kommen in Task 6/7 in denselben Gate-Block.)

- [ ] **Step 5: Templates schreiben**

`templates/registrations/index.twig`:

```twig
{% extends "layout.twig" %}

{% block title %}
    Anmeldungen - {{ app_settings.app_name|default("Chor-Manager") }}
{% endblock %}

{% block content %}
    <div class="container-fluid py-3">
        <h1 class="h3 mb-3"><i class="bi bi-calendar-check me-2"></i>Anmeldungen</h1>

        {% if success %}<div class="alert alert-success">{{ success }}</div>{% endif %}
        {% if error %}<div class="alert alert-danger">{{ error }}</div>{% endif %}

        {% if rows is empty %}
            <div class="alert alert-info">Aktuell sind keine Termine zur Anmeldung freigeschaltet.</div>
        {% endif %}

        <div class="registration-card-list">
            {% for row in rows %}
                <div class="card mb-2 registration-card">
                    <div class="card-body d-flex flex-wrap align-items-center gap-3">
                        <div class="flex-grow-1">
                            <a href="/registrations/{{ row.event.id }}" class="fw-bold text-decoration-none">
                                {{ row.event.title }}
                            </a>
                            <div class="text-muted small">
                                {{ row.event.starts_at|date("d.m.Y H:i") }}
                                {% if row.event.location %} &middot; {{ row.event.location }}{% endif %}
                                &middot; Anmeldeschluss: {{ row.event.registrationDeadlineAt|date("d.m.Y H:i") }}
                            </div>
                        </div>
                        <div class="registration-counters small">
                            <span class="badge registration-badge-yes">{{ row.event.yes_count }} Zusagen</span>
                            <span class="badge registration-badge-no">{{ row.event.no_count }} Absagen</span>
                            <span class="badge registration-badge-maybe">{{ row.event.maybe_count }} Vielleicht</span>
                            {% set open_count = row.eligible_count - row.event.yes_count - row.event.no_count - row.event.maybe_count %}
                            <span class="badge registration-badge-open">{{ open_count }} Offen</span>
                        </div>
                        <div>
                            {% if row.open %}
                                <form method="post" action="/registrations/{{ row.event.id }}" class="d-inline">
                                    <input type="hidden" name="status" value="yes">
                                    {% set yes_active = row.own_status == "yes" %}
                                    <button type="submit"
                                            class="btn btn-sm {{ yes_active ? "btn-success" : "btn-outline-success" }}">Zusagen</button>
                                </form>
                                <form method="post" action="/registrations/{{ row.event.id }}" class="d-inline">
                                    <input type="hidden" name="status" value="no">
                                    {% set no_active = row.own_status == "no" %}
                                    <button type="submit"
                                            class="btn btn-sm {{ no_active ? "btn-danger" : "btn-outline-danger" }}">Absagen</button>
                                </form>
                                <a href="/registrations/{{ row.event.id }}" class="btn btn-sm btn-outline-secondary">Details</a>
                            {% else %}
                                <span class="badge text-bg-secondary">Anmeldeschluss vorbei</span>
                                <a href="/registrations/{{ row.event.id }}" class="btn btn-sm btn-outline-secondary">Details</a>
                            {% endif %}
                        </div>
                    </div>
                </div>
            {% endfor %}
        </div>
    </div>
{% endblock %}
```

`templates/registrations/detail.twig`:

```twig
{% extends "layout.twig" %}

{% block title %}
    Anmeldung - {{ app_settings.app_name|default("Chor-Manager") }}
{% endblock %}

{% block content %}
    <div class="container-fluid py-3">
        <div class="d-flex align-items-center mb-3">
            <a href="/registrations" class="btn btn-sm btn-outline-secondary me-2"><i class="bi bi-arrow-left"></i></a>
            <h1 class="h3 mb-0">{{ event.title }}</h1>
        </div>

        {% if success %}<div class="alert alert-success">{{ success }}</div>{% endif %}
        {% if error %}<div class="alert alert-danger">{{ error }}</div>{% endif %}

        <div class="card mb-3">
            <div class="card-body">
                <div class="text-muted">
                    {{ event.starts_at|date("d.m.Y H:i") }}
                    {% if event.location %} &middot; {{ event.location }}{% endif %}
                    &middot; Anmeldeschluss: {{ event.registrationDeadlineAt|date("d.m.Y H:i") }}
                </div>
                <div class="mt-2">
                    <span class="badge registration-badge-yes">{{ counts.yes }} Zusagen</span>
                    <span class="badge registration-badge-no">{{ counts.no }} Absagen</span>
                    <span class="badge registration-badge-maybe">{{ counts.maybe }} Vielleicht</span>
                    <span class="badge registration-badge-open">{{ counts.open }} Offen</span>
                    <span class="ms-2 text-muted small">
                        Rücklauf: {{ answered }} von {{ total_eligible }} ({{ response_rate }} %)
                    </span>
                </div>
            </div>
        </div>

        {% if registration_open %}
            <div class="card mb-3">
                <div class="card-header">Meine Anmeldung</div>
                <div class="card-body">
                    <form method="post" action="/registrations/{{ event.id }}" class="registration-own-form">
                        <div class="btn-group mb-2" role="group" aria-label="Eigener Anmeldestatus">
                            {% set own_status = own_registration ? own_registration.status : null %}
                            {% set yes_active = own_status == "yes" %}
                            {% set no_active = own_status == "no" %}
                            {% set maybe_active = own_status == "maybe" %}
                            <input type="radio" class="btn-check" name="status" id="status-yes" value="yes"
                                   {% if yes_active %}checked{% endif %}>
                            <label class="btn btn-outline-success" for="status-yes">Zusage</label>
                            <input type="radio" class="btn-check" name="status" id="status-no" value="no"
                                   {% if no_active %}checked{% endif %}>
                            <label class="btn btn-outline-danger" for="status-no">Absage</label>
                            <input type="radio" class="btn-check" name="status" id="status-maybe" value="maybe"
                                   {% if maybe_active %}checked{% endif %}>
                            <label class="btn btn-outline-warning" for="status-maybe">Vielleicht</label>
                        </div>
                        <div class="mb-2 registration-note-field">
                            <label class="form-label" for="registration-note">Begründung (optional, v. a. bei Absage)</label>
                            <input type="text" class="form-control" id="registration-note" name="note"
                                   maxlength="255" value="{{ own_registration ? own_registration.note : "" }}">
                        </div>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </form>
                </div>
            </div>
        {% else %}
            <div class="alert alert-secondary">Der Anmeldeschluss ist vorbei — Änderungen sind nicht mehr möglich.</div>
        {% endif %}

        {% if can_manage_others and registration_open %}
            <form method="post" action="/registrations/{{ event.id }}/proxy">
        {% endif %}
        {% for group_name, members in voice_groups %}
            <div class="card mb-3">
                <div class="card-header">{{ group_name }}</div>
                <ul class="list-group list-group-flush">
                    {% for member in members %}
                        <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                            <span class="flex-grow-1">{{ member.last_name }}, {{ member.first_name }}</span>
                            {% set member_editable = can_manage_others and registration_open and member.editable %}
                            {% if member_editable %}
                                <select class="form-select form-select-sm registration-proxy-select"
                                        name="registration[{{ member.user_id }}]">
                                    <option value="">— offen —</option>
                                    <option value="yes" {% if member.status == "yes" %}selected{% endif %}>Zusage</option>
                                    <option value="no" {% if member.status == "no" %}selected{% endif %}>Absage</option>
                                    <option value="maybe" {% if member.status == "maybe" %}selected{% endif %}>Vielleicht</option>
                                </select>
                                <input type="text" class="form-control form-control-sm registration-proxy-note"
                                       name="note[{{ member.user_id }}]" maxlength="255"
                                       placeholder="Begründung" value="{{ member.note }}">
                            {% else %}
                                {% if member.status == "yes" %}
                                    <span class="badge registration-badge-yes">Zusage</span>
                                {% elseif member.status == "no" %}
                                    <span class="badge registration-badge-no">Absage</span>
                                {% elseif member.status == "maybe" %}
                                    <span class="badge registration-badge-maybe">Vielleicht</span>
                                {% else %}
                                    <span class="badge registration-badge-open">Offen</span>
                                {% endif %}
                                {% if member.note %}<span class="text-muted small">{{ member.note }}</span>{% endif %}
                            {% endif %}
                            {% if member.updated_by_name %}
                                <span class="text-muted small">eingetragen von {{ member.updated_by_name }}</span>
                            {% endif %}
                        </li>
                    {% endfor %}
                </ul>
            </div>
        {% endfor %}
        {% if can_manage_others and registration_open %}
                <button type="submit" class="btn btn-primary mb-3">Vertretungseinträge speichern</button>
            </form>
        {% endif %}
    </div>
{% endblock %}
```

`templates/partials/navigation/events.twig` — im Dropdown nach dem Anwesenheits-`<li>`:

```twig
            {% if settings.modules.registration %}
                <li>
                    <a class="dropdown-item {% if nav_active(path, nav, ['/registrations'], ['registrations']) %}active{% endif %}"
                       href="/registrations"><i class="bi bi-calendar-check me-2"></i> Anmeldungen</a>
                </li>
            {% endif %}
```

`public/css/style.css` — am Dateiende:

```css
/* Termin-Anmeldung (Registration) */
.registration-badge-yes {
    background-color: var(--bs-success);
}

.registration-badge-no {
    background-color: var(--bs-danger);
}

.registration-badge-maybe {
    background-color: var(--bs-warning);
    color: var(--bs-dark);
}

.registration-badge-open {
    background-color: var(--bs-secondary);
}

.registration-proxy-select {
    width: auto;
    min-width: 8rem;
}

.registration-proxy-note {
    width: 14rem;
    max-width: 100%;
}
```

- [ ] **Step 6: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationFeatureTest`
Expected: PASS.

- [ ] **Step 7: phpcs, twigcs + Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Controllers/RegistrationController.php src/Routes.php templates/registrations templates/partials/navigation/events.twig public/css/style.css tests/Feature/RegistrationFeatureTest.php
git commit -m "feat: Anmeldeliste und Termin-Detailansicht"
```

---

### Task 6: Selbsteintrag speichern (POST /registrations/{event_id})

**Files:**
- Modify: `src/Controllers/RegistrationController.php` (Methode `save`)
- Modify: `src/Routes.php` (POST-Route im Gate-Block)
- Test: `tests/Feature/RegistrationSaveFeatureTest.php`

**Interfaces:**
- Consumes: `findRegistrationEvent()`, `Event::isRegistrationOpen()` (Task 1/5).
- Produces: `RegistrationController::save(Request, Response, array $args): Response` — Body-Felder `status` (`yes|no|maybe`), `note` (optional, max 255).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/RegistrationSaveFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Bootstrap;

class RegistrationSaveFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Event $event;
    private User $user;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];

        $this->user = User::where('is_active', 1)->firstOrFail();
        $this->event = Event::create([
            'title' => 'Konzert Anmeldetest',
            'starts_at' => Carbon::now()->addDays(10)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(10)->setTime(22, 0),
            'type' => 'Konzert',
            'registration_enabled' => true,
        ]);

        $_SESSION['user_id'] = (int) $this->user->id;
    }

    protected function tearDown(): void
    {
        EventRegistration::where('event_id', $this->event->id)->delete();
        $this->event->delete();
        $_SESSION = [];
    }

    private function controller(): RegistrationController
    {
        return new RegistrationController(
            Twig::create(dirname(__DIR__) . '/../templates'),
            new AttendanceScopeService(),
            new NullLogger()
        );
    }

    public function testSelfRegistrationCreateAndUpdate(): void
    {
        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $row = EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $this->user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('yes', $row->status);
        $this->assertSame((int) $this->user->id, (int) $row->updated_by);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'no',
            'note' => 'Bin im Urlaub',
        ]);
        $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $row->refresh();
        $this->assertSame('no', $row->status);
        $this->assertSame('Bin im Urlaub', $row->note);
        $this->assertSame(1, EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $this->user->id)->count());
    }

    public function testInvalidStatusRejected(): void
    {
        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'present',
        ]);
        $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testClosedDeadlineRejectedWith403(): void
    {
        $this->event->update(['registration_deadline' => Carbon::now()->subHour()]);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testPastEventRejectedWith403(): void
    {
        $this->event->update([
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->subDay()->addHours(2),
        ]);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDisabledRegistrationRejected(): void
    {
        $this->event->update(['registration_enabled' => false]);

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'yes',
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationSaveFeatureTest`
Expected: FAIL (`save` existiert nicht).

- [ ] **Step 3: `save` implementieren**

In `RegistrationController`:

```php
    public function save(Request $request, Response $response, array $args): Response
    {
        $event = $this->findRegistrationEvent((int) $args['event_id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden oder Anmeldung nicht freigeschaltet.';
            return $response->withHeader('Location', '/registrations')->withStatus(302);
        }

        if (!$event->isRegistrationOpen()) {
            $_SESSION['error'] = 'Der Anmeldeschluss für diesen Termin ist vorbei.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $status = (string) ($data['status'] ?? '');
        $note = trim((string) ($data['note'] ?? ''));

        if (!in_array($status, EventRegistration::STATUSES, true)) {
            $_SESSION['error'] = 'Ungültiger Anmeldestatus.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(302);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);

        try {
            EventRegistration::updateOrCreate(
                ['event_id' => (int) $event->id, 'user_id' => $userId],
                ['status' => $status, 'note' => $note !== '' ? $note : null, 'updated_by' => $userId]
            );
            $_SESSION['success'] = 'Anmeldung gespeichert.';
        } catch (\Exception $e) {
            $this->logger->error('Saving event registration failed.', [
                'event' => 'registration.save_failed',
                'event_id' => (int) $event->id,
                'user_id' => $userId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Fehler beim Speichern der Anmeldung.';
        }

        return $response
            ->withHeader('Location', '/registrations/' . $event->id)
            ->withStatus(302);
    }
```

`src/Routes.php`, im Registration-Gate-Block ergänzen:

```php
                $group->post('/registrations/{event_id:[0-9]+}', [RegistrationController::class, 'save']);
```

- [ ] **Step 4: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "RegistrationSaveFeatureTest|RegistrationFeatureTest"`
Expected: PASS.

- [ ] **Step 5: phpcs + Commit**

```bash
ddev composer phpcs
git add src/Controllers/RegistrationController.php src/Routes.php tests/Feature/RegistrationSaveFeatureTest.php
git commit -m "feat: Selbsteintrag der Anmeldung speichern"
```

---

### Task 7: Vertretungseinträge (POST /registrations/{event_id}/proxy)

**Files:**
- Modify: `src/Controllers/RegistrationController.php` (Methode `saveProxy`)
- Modify: `src/Routes.php` (POST-Route)
- Test: `tests/Feature/RegistrationProxyFeatureTest.php`

**Interfaces:**
- Consumes: `AttendanceScopeService::getManageableUserIds()` / `canManageOthers()` (Task 3).
- Produces: `RegistrationController::saveProxy(Request, Response, array $args): Response` — Body-Felder `registration[<user_id>] = yes|no|maybe|""` (leer = keine Änderung), `note[<user_id>]`.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/RegistrationProxyFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Bootstrap;

class RegistrationProxyFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Event $event;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];

        $this->event = Event::create([
            'title' => 'Probe Vertretungstest',
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        EventRegistration::where('event_id', $this->event->id)->delete();
        $this->event->delete();
        $_SESSION = [];
    }

    private function controller(): RegistrationController
    {
        return new RegistrationController(
            Twig::create(dirname(__DIR__) . '/../templates'),
            new AttendanceScopeService(),
            new NullLogger()
        );
    }

    public function testAdminCanRegisterForOthersAndUpdatedByIsSet(): void
    {
        $admin = User::where('is_active', 1)->firstOrFail();
        $member = User::where('is_active', 1)
            ->where('id', '!=', $admin->id)->firstOrFail();

        $_SESSION['user_id'] = (int) $admin->id;
        $_SESSION['can_manage_users'] = true;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $member->id => 'maybe'],
            'note' => [(string) $member->id => 'Kommt eventuell später'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $row = EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $member->id)->firstOrFail();
        $this->assertSame('maybe', $row->status);
        $this->assertSame('Kommt eventuell später', $row->note);
        $this->assertSame((int) $admin->id, (int) $row->updated_by);
    }

    public function testForeignVoiceGroupRejectedWith403(): void
    {
        $rep = User::where('is_active', 1)->whereHas('voiceGroups')->firstOrFail();
        $repGroupIds = $rep->voiceGroups->pluck('id')->map(fn ($id) => (int) $id)->all();

        $outsider = User::where('is_active', 1)
            ->whereDoesntHave('voiceGroups', function ($q) use ($repGroupIds) {
                $q->whereIn('voice_group_id', $repGroupIds);
            })
            ->firstOrFail();

        $_SESSION['user_id'] = (int) $rep->id;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 50;
        $_SESSION['voice_group_ids'] = $repGroupIds;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $outsider->id => 'yes'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)->count());
    }

    public function testPlainMemberCannotProxyAtAll(): void
    {
        $member = User::where('is_active', 1)->firstOrFail();
        $other = User::where('is_active', 1)->where('id', '!=', $member->id)->firstOrFail();

        $_SESSION['user_id'] = (int) $member->id;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [];

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $other->id => 'yes'],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationProxyFeatureTest`
Expected: FAIL.

- [ ] **Step 3: `saveProxy` implementieren**

In `RegistrationController`:

```php
    public function saveProxy(Request $request, Response $response, array $args): Response
    {
        $event = $this->findRegistrationEvent((int) $args['event_id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden oder Anmeldung nicht freigeschaltet.';
            return $response->withHeader('Location', '/registrations')->withStatus(302);
        }

        if (!$event->isRegistrationOpen()) {
            $_SESSION['error'] = 'Der Anmeldeschluss für diesen Termin ist vorbei.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(403);
        }

        if (!$this->scopeService->canManageOthers()) {
            $_SESSION['error'] = 'Zugriff verweigert: Keine Berechtigung für Vertretungseinträge.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $registrations = (array) ($data['registration'] ?? []);
        $notes = (array) ($data['note'] ?? []);

        $allowedUserIds = $this->scopeService->getManageableUserIds();
        $submittedUserIds = [];
        foreach ($registrations as $rawUserId => $status) {
            if ((string) $status !== '') {
                $submittedUserIds[] = (int) $rawUserId;
            }
        }
        $unauthorized = array_diff(array_unique($submittedUserIds), $allowedUserIds);

        if (!empty($unauthorized)) {
            $_SESSION['error'] = 'Zugriff verweigert: Unzulässige Personen im Vertretungsformular.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(403);
        }

        $actorId = (int) ($_SESSION['user_id'] ?? 0);

        Capsule::beginTransaction();

        try {
            foreach ($registrations as $rawUserId => $status) {
                $targetUserId = (int) $rawUserId;
                $status = (string) $status;

                if ($status === '' || !in_array($status, EventRegistration::STATUSES, true)) {
                    continue;
                }

                $note = trim((string) ($notes[$rawUserId] ?? ''));

                EventRegistration::updateOrCreate(
                    ['event_id' => (int) $event->id, 'user_id' => $targetUserId],
                    ['status' => $status, 'note' => $note !== '' ? $note : null, 'updated_by' => $actorId]
                );
            }

            Capsule::commit();
            $_SESSION['success'] = 'Vertretungseinträge gespeichert.';
        } catch (\Exception $e) {
            Capsule::rollBack();
            $this->logger->error('Saving proxy registrations failed.', [
                'event' => 'registration.proxy_save_failed',
                'event_id' => (int) $event->id,
                'actor_id' => $actorId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Fehler beim Speichern der Vertretungseinträge.';
        }

        return $response
            ->withHeader('Location', '/registrations/' . $event->id)
            ->withStatus(302);
    }
```

`src/Routes.php`, im Gate-Block:

```php
                $group->post('/registrations/{event_id:[0-9]+}/proxy', [RegistrationController::class, 'saveProxy']);
```

- [ ] **Step 4: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "RegistrationProxyFeatureTest|RegistrationSaveFeatureTest|RegistrationFeatureTest"`
Expected: PASS.

- [ ] **Step 5: phpcs + Commit**

```bash
ddev composer phpcs
git add src/Controllers/RegistrationController.php src/Routes.php tests/Feature/RegistrationProxyFeatureTest.php
git commit -m "feat: Vertretungseintraege fuer Anmeldungen"
```

---

### Task 8: Termin-Formular — Anmeldung + Anwesenheitsliste konfigurieren

**Files:**
- Modify: `src/Controllers/EventController.php` (`create()` ab Zeile 459, `update()` ab Zeile 681, `edit()` ab Zeile 633)
- Modify: `templates/events/edit.twig` und das Create-Modal (in `templates/events/index.twig` bzw. dort eingebundenem Partial — per `grep -n "event_create" templates/events/` lokalisieren)
- Test: `tests/Feature/EventRegistrationSettingsFeatureTest.php`

**Interfaces:**
- Consumes: neue `events`-Spalten (Task 1), Feature-Flag (Task 2 — Twig `settings.modules.registration`).
- Produces: Formularfelder `registration_enabled` (Checkbox), `registration_deadline` (datetime-local, optional), `attendance_required` (Checkbox, Default checked). Serien: `registration_enabled`/`attendance_required` gelten für alle erzeugten/aktualisierten Termine, `registration_deadline` nur für Einzeltermine (bei `update_series` nicht propagiert).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/EventRegistrationSettingsFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class EventRegistrationSettingsFeatureTest extends TestCase
{
    public function testEventControllerHandlesRegistrationFields(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EventController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString("'registration_enabled'", $controller);
        $this->assertStringContainsString("'registration_deadline'", $controller);
        $this->assertStringContainsString("'attendance_required'", $controller);
    }

    public function testEditTemplateOffersRegistrationAndAttendanceToggles(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/events/edit.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('name="attendance_required"', $template);
        $this->assertStringContainsString('{% if settings.modules.registration %}', $template);
        $this->assertStringContainsString('name="registration_enabled"', $template);
        $this->assertStringContainsString('name="registration_deadline"', $template);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter EventRegistrationSettingsFeatureTest`
Expected: FAIL.

- [ ] **Step 3: EventController erweitern**

In `create()` nach Zeile 468 (`$repeat = …`):

```php
        $registrationEnabled = !empty($data['registration_enabled']);
        $attendanceRequired = !empty($data['attendance_required']);
        $registrationDeadlineRaw = trim((string) ($data['registration_deadline'] ?? ''));
        $registrationDeadline = null;
        if ($registrationEnabled && $registrationDeadlineRaw !== '') {
            try {
                $registrationDeadline = Carbon::parse($registrationDeadlineRaw)->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $registrationDeadline = null;
            }
        }
```

Beide `Event::create([...])`-Aufrufe (Einzeltermin Zeile 530 und Serie Zeile 582) ergänzen um:

```php
                    'registration_enabled' => $registrationEnabled,
                    'registration_deadline' => $registrationDeadline,
                    'attendance_required' => $attendanceRequired,
```

Für Serien-Termine `'registration_deadline' => null` setzen (Deadline gilt nur für Einzeltermine).

In `update()` nach Zeile 702 dieselbe Feld-Extraktion; `$updateData` (Zeile 758) ergänzen um `'registration_enabled' => $registrationEnabled, 'attendance_required' => $attendanceRequired`. Im Einzel-Update-Zweig (Zeile 792) zusätzlich `'registration_deadline' => $registrationDeadline`; im Serien-Zweig NICHT (Deadline bleibt pro Termin unverändert).

WICHTIG: Da unchecked Checkboxen nicht im POST landen, setzt `!empty(...)` sie korrekt auf `false` — d. h. das Edit-Formular MUSS beide Checkboxen immer rendern (auch bei ausgeschaltetem Feature-Flag muss `attendance_required` da sein; `registration_enabled` ist bei ausgeschaltetem Flag nicht im Formular ⇒ Update würde es auf `false` zurücksetzen). Deshalb: bei ausgeschaltetem `settings.modules.registration` die Registration-Felder als `<input type="hidden">` mit aktuellem Wert rendern:

```twig
    {% if settings.modules.registration %}
        {# sichtbare Felder, siehe Step 4 #}
    {% else %}
        {% if event.registration_enabled %}
            <input type="hidden" name="registration_enabled" value="1">
        {% endif %}
        {% if event.registration_deadline %}
            <input type="hidden" name="registration_deadline"
                   value="{{ event.registration_deadline|date("Y-m-d\\TH:i") }}">
        {% endif %}
    {% endif %}
```

In `edit()` (Zeile 662, `$formData`-Aufbau) ergänzen:

```php
                'registration_enabled' => (bool) $event->registration_enabled,
                'registration_deadline' => $event->registration_deadline
                    ? Carbon::parse($event->registration_deadline)->format('Y-m-d\TH:i')
                    : '',
                'attendance_required' => (bool) $event->attendance_required,
```

- [ ] **Step 4: Templates erweitern**

`templates/events/edit.twig` — nach dem Ort-Feld (Struktur an vorhandene `mb-3`-Blöcke anlehnen):

```twig
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="attendance-required"
               name="attendance_required" value="1"
               {% if form_data.attendance_required %}checked{% endif %}>
        <label class="form-check-label" for="attendance-required">Anwesenheitsliste führen</label>
    </div>
    {% if settings.modules.registration %}
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="registration-enabled"
                   name="registration_enabled" value="1"
                   {% if form_data.registration_enabled %}checked{% endif %}>
            <label class="form-check-label" for="registration-enabled">Anmeldung freischalten</label>
        </div>
        <div class="mb-3">
            <label class="form-label" for="registration-deadline">Anmeldeschluss (optional, sonst Terminbeginn)</label>
            <input type="datetime-local" class="form-control" id="registration-deadline"
                   name="registration_deadline" value="{{ form_data.registration_deadline }}">
        </div>
    {% else %}
        {% if form_data.registration_enabled %}
            <input type="hidden" name="registration_enabled" value="1">
        {% endif %}
        {% if form_data.registration_deadline %}
            <input type="hidden" name="registration_deadline" value="{{ form_data.registration_deadline }}">
        {% endif %}
    {% endif %}
```

Gleiches Muster im Create-Modal (`attendance_required` dort per Default `checked`, Registration-Felder ohne Hidden-Fallback — beim Anlegen gibt es keinen Bestandswert). Exakte Einfügestelle beim Implementieren anhand des vorhandenen Formularaufbaus wählen; Feldnamen und IDs wie oben.

- [ ] **Step 5: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "EventRegistrationSettingsFeatureTest|EventFeatureTest"`
Expected: PASS.

- [ ] **Step 6: phpcs, twigcs + Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Controllers/EventController.php templates/events tests/Feature/EventRegistrationSettingsFeatureTest.php
git commit -m "feat: Anmeldung und Anwesenheitsliste am Termin konfigurierbar"
```

---

### Task 9: attendance_required durchsetzen (Anwesenheitsseite + Auswertung)

**Files:**
- Modify: `src/Controllers/AttendanceController.php` (`show()` Event-Query Zeile 34, `save()` Guard nach Zeile 149)
- Modify: `src/Controllers/EvaluationController.php` (`index()` — `totalEvents` Zeile 67 und Attendance-Eager-Load Zeile 75–79)
- Modify: `templates/attendance/show.twig` (Hinweis-Fall)
- Test: `tests/Feature/AttendanceRequiredFeatureTest.php`

**Interfaces:**
- Consumes: `events.attendance_required` (Task 1).
- Produces: Anwesenheitsseite und -auswertung ignorieren Termine mit `attendance_required = 0`; POST auf solche Termine ⇒ 403.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/AttendanceRequiredFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AttendanceController;
use App\Models\Event;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Bootstrap;

class AttendanceRequiredFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Event $event;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];

        $this->event = Event::create([
            'title' => 'Fest ohne Anwesenheitsliste',
            'starts_at' => Carbon::now()->addDays(3)->setTime(18, 0),
            'ends_at' => Carbon::now()->addDays(3)->setTime(23, 0),
            'type' => 'Sonstiges',
            'attendance_required' => false,
        ]);

        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_users'] = true;
    }

    protected function tearDown(): void
    {
        $this->event->delete();
        $_SESSION = [];
    }

    public function testAttendanceSaveRejectedWhenNotRequired(): void
    {
        $controller = new AttendanceController(
            Twig::create(dirname(__DIR__) . '/../templates'),
            new AttendanceScopeService()
        );

        $request = $this->makeRequest('POST', '/attendance/' . $this->event->id, [
            'attendance' => ['1' => 'present'],
        ]);
        $response = $controller->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAttendanceEventListExcludesNotRequired(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AttendanceController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString("where('attendance_required', true)", $controller);
    }

    public function testEvaluationCountsOnlyRequiredEvents(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EvaluationController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString("attendance_required", $controller);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter AttendanceRequiredFeatureTest`
Expected: FAIL.

- [ ] **Step 3: Implementieren**

`AttendanceController::show()` Zeile 34:

```php
        $events = Event::where('attendance_required', true)->orderBy('starts_at', 'asc')->get();
```

`AttendanceController::save()` — nach dem `!$event`-Guard (Zeile 149):

```php
        if (!(bool) $event->attendance_required) {
            $_SESSION['error'] = 'Für diesen Termin wird keine Anwesenheitsliste geführt.';
            return $response->withHeader('Location', '/attendance')->withStatus(403);
        }
```

`EvaluationController::index()` Zeile 67:

```php
                $totalEvents = $selectedProject->events()->where('attendance_required', true)->count();
```

Attendance-Eager-Load (Zeile 75–79) einschränken:

```php
                        ->with(['voiceGroups', 'attendances' => function ($q) use ($projectId) {
                            $q->whereHas('event', function ($sq) use ($projectId) {
                                $sq->where('project_id', $projectId)
                                    ->where('attendance_required', true);
                            });
                        }])
```

`templates/attendance/show.twig`: kein struktureller Umbau nötig — Termine ohne Anwesenheitsliste erscheinen durch die Query gar nicht mehr in der Auswahl. Direkte URL-Aufrufe landen via `resolveSelectedEventId` auf einem gültigen Termin (Event nicht in `$events` ⇒ Fallback nächster Termin).

- [ ] **Step 4: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "AttendanceRequiredFeatureTest|AttendanceFeatureTest|EvaluationFeatureTest"`
Expected: PASS.

- [ ] **Step 5: phpcs + Commit**

```bash
ddev composer phpcs
git add src/Controllers/AttendanceController.php src/Controllers/EvaluationController.php tests/Feature/AttendanceRequiredFeatureTest.php
git commit -m "feat: Anwesenheitsliste pro Termin abschaltbar"
```

---

### Task 10: Anmeldungs-Hinweis auf der Anwesenheitsseite

**Files:**
- Modify: `src/Controllers/AttendanceController.php` (`show()` — Registrierungen mitladen)
- Modify: `templates/attendance/show.twig` (read-only Badge-Spalte)
- Test: `tests/Feature/AttendanceRegistrationHintFeatureTest.php`

**Interfaces:**
- Consumes: `EventRegistration` (Task 1), Feature-Flag in Twig (`settings.modules.registration`).
- Produces: pro User-Zeile Schlüssel `registration_status` (`yes|no|maybe|null`) und `registration_note` im `voice_groups`-Array des Anwesenheits-Templates.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/AttendanceRegistrationHintFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AttendanceRegistrationHintFeatureTest extends TestCase
{
    public function testAttendanceControllerLoadsRegistrations(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AttendanceController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('eventRegistrations', $controller);
        $this->assertStringContainsString("'registration_status'", $controller);
    }

    public function testAttendanceTemplateShowsRegistrationBadge(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/attendance/show.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('settings.modules.registration', $template);
        $this->assertStringContainsString('registration_status', $template);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter AttendanceRegistrationHintFeatureTest`
Expected: FAIL.

- [ ] **Step 3: Implementieren**

`AttendanceController::show()` — Eager-Load erweitern (Zeile 73):

```php
                    ->with(['voiceGroups', 'subVoices.voiceGroup', 'attendances' => function ($q) use ($eventId) {
                        $q->where('event_id', $eventId);
                    }, 'eventRegistrations' => function ($q) use ($eventId) {
                        $q->where('event_id', $eventId);
                    }])
```

Im `$voiceGroups[$vgName][]`-Array (Zeile 103) ergänzen:

```php
                    $registration = $u->eventRegistrations->first();
                    // ... im Array:
                    'registration_status' => $registration ? $registration->status : null,
                    'registration_note' => $registration ? $registration->note : null,
```

`templates/attendance/show.twig` — in der Mitglieder-Zeile (neben Name/Status; genaue Stelle an vorhandener Struktur ausrichten):

```twig
    {% set show_registration = settings.modules.registration and current_event.registration_enabled %}
    {% if show_registration %}
        {% if member.registration_status == "yes" %}
            <span class="badge registration-badge-yes" title="{{ member.registration_note }}">Zusage</span>
        {% elseif member.registration_status == "no" %}
            <span class="badge registration-badge-no" title="{{ member.registration_note }}">Absage</span>
        {% elseif member.registration_status == "maybe" %}
            <span class="badge registration-badge-maybe" title="{{ member.registration_note }}">Vielleicht</span>
        {% else %}
            <span class="badge registration-badge-open">Offen</span>
        {% endif %}
    {% endif %}
```

Hinweis: Variablenname der Mitglied-Schleife im Template prüfen (`member` vs. `user`) und angleichen. `{% set show_registration %}` VOR der Schleife platzieren.

- [ ] **Step 4: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "AttendanceRegistrationHintFeatureTest|AttendanceFeatureTest"`
Expected: PASS.

- [ ] **Step 5: phpcs, twigcs + Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Controllers/AttendanceController.php templates/attendance/show.twig tests/Feature/AttendanceRegistrationHintFeatureTest.php
git commit -m "feat: Anmeldestatus als Hinweis in der Anwesenheitserfassung"
```

---

### Task 11: Anmelde-Auswertung (/evaluations/registrations)

**Files:**
- Modify: `src/Controllers/EvaluationController.php` (neue Methode `registrations`)
- Create: `templates/evaluations/registrations.twig`
- Modify: `src/Routes.php` (Route im Registration-Gate), `templates/partials/navigation/evaluations.twig` (Link)
- Test: `tests/Feature/RegistrationEvaluationFeatureTest.php`

**Interfaces:**
- Consumes: `EventRegistration`, `VoiceGroup`-Relationen, Feature-Flag.
- Produces: `EvaluationController::registrations(Request, Response): Response` — GET `/evaluations/registrations`, Query-Param `include_past=1` optional. Twig-Kontext: `voice_group_names` (sortierte Namensliste), `matrix` (Liste pro Event: `['event' => Event, 'cells' => [gruppenname => ['yes' => int, 'maybe' => int]], 'total_yes' => int, 'response_rate' => int, 'attendance_comparison' => ?int]`), `include_past` (bool).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/RegistrationEvaluationFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use PHPUnit\Framework\TestCase;

class RegistrationEvaluationFeatureTest extends TestCase
{
    public function testRegistrationEvaluationStructureExists(): void
    {
        $this->assertTrue(method_exists(EvaluationController::class, 'registrations'));
        $this->assertFileExists(dirname(__DIR__) . '/../templates/evaluations/registrations.twig');

        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString("'/evaluations/registrations'", $routes);

        $nav = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/evaluations.twig');
        $this->assertIsString($nav);
        $this->assertStringContainsString('settings.modules.registration', $nav);
        $this->assertStringContainsString('href="/evaluations/registrations"', $nav);
    }

    public function testRegistrationEvaluationRouteIsFeatureGated(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routes);

        $gatePos = strpos($routes, "if (\$settings['modules']['registration'] ?? false) {");
        $this->assertNotFalse($gatePos);

        $routePos = strpos($routes, "'/evaluations/registrations'");
        $this->assertNotFalse($routePos);
        $this->assertGreaterThan($gatePos, $routePos);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationEvaluationFeatureTest`
Expected: FAIL.

- [ ] **Step 3: Controller-Methode implementieren**

In `EvaluationController` (Imports ergänzen: `App\Models\Event`, `App\Models\EventRegistration`, `App\Models\VoiceGroup`, `App\Models\Attendance`, `Carbon\Carbon`):

```php
    public function registrations(Request $request, Response $response): Response
    {
        $includePast = (string) ($request->getQueryParams()['include_past'] ?? '') === '1';

        $query = Event::where('registration_enabled', true)->orderBy('starts_at', 'asc');
        if (!$includePast) {
            $query->where('starts_at', '>', Carbon::now());
        }
        $events = $query->with(['registrations.user.voiceGroups'])->get();

        $voiceGroupNames = VoiceGroup::orderBy('name')->pluck('name')->all();
        $voiceGroupNames[] = 'Ohne Stimmgruppe';

        $matrix = [];
        foreach ($events as $event) {
            $cells = array_fill_keys($voiceGroupNames, ['yes' => 0, 'maybe' => 0]);
            $totalYes = 0;
            $answered = 0;

            foreach ($event->registrations as $registration) {
                $user = $registration->user;
                if (!$user || !(bool) $user->is_active) {
                    continue;
                }

                $answered++;
                $groupName = $user->voiceGroups->first()->name ?? 'Ohne Stimmgruppe';
                if (!isset($cells[$groupName])) {
                    $groupName = 'Ohne Stimmgruppe';
                }

                if ($registration->status === EventRegistration::STATUS_YES) {
                    $cells[$groupName]['yes']++;
                    $totalYes++;
                } elseif ($registration->status === EventRegistration::STATUS_MAYBE) {
                    $cells[$groupName]['maybe']++;
                }
            }

            $eligibleQuery = User::where('is_active', 1);
            if ($event->project_id !== null) {
                $eligibleQuery->whereHas('projects', function ($projectQuery) use ($event) {
                    $projectQuery->where('projects.id', (int) $event->project_id);
                });
            }
            $eligible = $eligibleQuery->count();

            $attendanceComparison = null;
            $isPast = Carbon::parse($event->starts_at)->isPast();
            if ($isPast && (bool) $event->attendance_required) {
                $attendanceComparison = Attendance::where('event_id', $event->id)
                    ->where('status', 'present')
                    ->count();
            }

            $matrix[] = [
                'event' => $event,
                'cells' => $cells,
                'total_yes' => $totalYes,
                'response_rate' => $eligible > 0 ? (int) round($answered * 100 / $eligible) : 0,
                'attendance_comparison' => $attendanceComparison,
                'is_past' => $isPast,
            ];
        }

        return $this->view->render($response, 'evaluations/registrations.twig', [
            'voice_group_names' => $voiceGroupNames,
            'matrix' => $matrix,
            'include_past' => $includePast
        ]);
    }
```

- [ ] **Step 4: Route + Nav + Template**

`src/Routes.php` — im Registration-Gate-Block:

```php
                $group->get('/evaluations/registrations', [EvaluationController::class, 'registrations']);
```

`templates/partials/navigation/evaluations.twig` — nach dem Projektmitglieder-`<li>`:

```twig
            {% if settings.modules.registration %}
                <li>
                    <a class="dropdown-item {% if nav_active(path, nav, ['/evaluations/registrations'], ['evaluations_registrations']) %}active{% endif %}"
                       href="/evaluations/registrations"><i class="bi bi-calendar-check me-2"></i> Anmeldungen</a>
                </li>
            {% endif %}
```

`templates/evaluations/registrations.twig`:

```twig
{% extends "layout.twig" %}

{% block title %}
    Anmelde-Auswertung - {{ app_settings.app_name|default("Chor-Manager") }}
{% endblock %}

{% block content %}
    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap align-items-center mb-3 gap-2">
            <h1 class="h3 mb-0"><i class="bi bi-calendar-check me-2"></i>Anmelde-Auswertung</h1>
            <div class="ms-auto">
                {% if include_past %}
                    <a class="btn btn-sm btn-outline-secondary" href="/evaluations/registrations">Nur kommende</a>
                {% else %}
                    <a class="btn btn-sm btn-outline-secondary" href="/evaluations/registrations?include_past=1">Auch vergangene</a>
                {% endif %}
            </div>
        </div>

        {% if matrix is empty %}
            <div class="alert alert-info">Keine Termine mit freigeschalteter Anmeldung gefunden.</div>
        {% else %}
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Termin</th>
                            {% for name in voice_group_names %}
                                <th class="text-center">{{ name }}</th>
                            {% endfor %}
                            <th class="text-center">Zusagen gesamt</th>
                            <th class="text-center">Rücklauf</th>
                            <th class="text-center">Anwesend (Ist)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for row in matrix %}
                            <tr>
                                <td>
                                    <a href="/registrations/{{ row.event.id }}" class="text-decoration-none">
                                        {{ row.event.title }}
                                    </a>
                                    <div class="text-muted small">{{ row.event.starts_at|date("d.m.Y H:i") }}</div>
                                </td>
                                {% for name in voice_group_names %}
                                    <td class="text-center">
                                        {{ row.cells[name].yes }}
                                        {% if row.cells[name].maybe > 0 %}
                                            <span class="text-muted">({{ row.cells[name].maybe }})</span>
                                        {% endif %}
                                    </td>
                                {% endfor %}
                                <td class="text-center fw-bold">{{ row.total_yes }}</td>
                                <td class="text-center">{{ row.response_rate }} %</td>
                                <td class="text-center">
                                    {% if row.attendance_comparison is not null %}
                                        {{ row.attendance_comparison }}
                                    {% else %}
                                        &mdash;
                                    {% endif %}
                                </td>
                            </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
            <p class="text-muted small">Zahlen: Zusagen, in Klammern Vielleicht. "Anwesend (Ist)" nur für vergangene Termine mit Anwesenheitsliste.</p>
        {% endif %}
    </div>
{% endblock %}
```

- [ ] **Step 5: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "RegistrationEvaluationFeatureTest|EvaluationFeatureTest"`
Expected: PASS.

- [ ] **Step 6: phpcs, twigcs + Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Controllers/EvaluationController.php src/Routes.php templates/evaluations/registrations.twig templates/partials/navigation/evaluations.twig tests/Feature/RegistrationEvaluationFeatureTest.php
git commit -m "feat: Anmelde-Auswertung mit Besetzung pro Stimmgruppe und Ruecklaufquote"
```

---

### Task 12: Erinnerungsmail — Enqueue-Methode + Mail-Template

**Files:**
- Modify: `src/Services/MailQueueService.php` (neue öffentliche Methode)
- Create: `templates/emails/registration_reminder.twig`
- Test: `tests/Feature/RegistrationReminderMailFeatureTest.php`

**Interfaces:**
- Produces:
  - `MailQueueService::enqueueRegistrationReminderMail(string $recipientEmail, string $subject, string $bodyHtml, int $userId, int $eventId): MailQueue` — `mail_type = 'registration_reminder'`, Payload `['user_id' => …, 'event_id' => …]`.
  - Twig-Template `emails/registration_reminder.twig` mit Variablen `user` (User), `event` (Event), `deadline` (formatiert `d.m.Y H:i`), `link` (absolute URL), `app_name`.
- Consumers: `RegistrationReminderService` (Task 13).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/RegistrationReminderMailFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Services\MailQueueService;
use PHPUnit\Framework\TestCase;
use Tests\Bootstrap;

class RegistrationReminderMailFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    public function testEnqueueRegistrationReminderMail(): void
    {
        $service = new MailQueueService();
        $entry = $service->enqueueRegistrationReminderMail(
            'mitglied@example.org',
            'Erinnerung: Anmeldung zur Probe',
            '<p>Bitte eintragen</p>',
            42,
            7
        );

        $this->assertSame('registration_reminder', $entry->mail_type);
        $this->assertSame('queued', $entry->status);
        $this->assertSame(['user_id' => 42, 'event_id' => 7], $entry->payload_json);

        MailQueue::where('id', $entry->id)->delete();
    }

    public function testReminderTemplateExistsWithDirectLink(): void
    {
        $path = dirname(__DIR__) . '/../templates/emails/registration_reminder.twig';
        $this->assertFileExists($path);

        $template = file_get_contents($path);
        $this->assertIsString($template);
        $this->assertStringContainsString('{{ link }}', $template);
        $this->assertStringContainsString('Anmeldeschluss', $template);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationReminderMailFeatureTest`
Expected: FAIL.

- [ ] **Step 3: Implementieren**

`src/Services/MailQueueService.php` — neue Methode nach `enqueuePasswordResetMail`:

```php
    /**
     * Enqueue a registration reminder mail.
     *
     * @param string $recipientEmail
     * @param string $subject
     * @param string $bodyHtml
     * @param int $userId
     * @param int $eventId
     * @return MailQueue
     * @throws Exception
     */
    public function enqueueRegistrationReminderMail(
        string $recipientEmail,
        string $subject,
        string $bodyHtml,
        int $userId,
        int $eventId
    ): MailQueue {
        return $this->enqueueGenericMail(
            mailType: 'registration_reminder',
            recipientEmail: $recipientEmail,
            subject: $subject,
            bodyHtml: $bodyHtml,
            payload: [
                'user_id' => $userId,
                'event_id' => $eventId,
            ]
        );
    }
```

`templates/emails/registration_reminder.twig` (Inline-Styles erlaubt; Aufbau an `templates/emails/invitation.twig` anlehnen — vorher lesen und Kopf/Fuß übernehmen):

```twig
<div style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #212529;">
    <h2 style="color: #0d6efd;">{{ app_name }}</h2>
    <p>Hallo {{ user.first_name }},</p>
    <p>
        für den Termin <strong>{{ event.title }}</strong> am
        <strong>{{ event.starts_at|date("d.m.Y H:i") }}</strong>
        {% if event.location %} ({{ event.location }}){% endif %}
        liegt noch keine Anmeldung von dir vor.
    </p>
    <p>Anmeldeschluss: <strong>{{ deadline }}</strong></p>
    <p style="margin: 24px 0;">
        <a href="{{ link }}"
           style="background-color: #0d6efd; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 4px;">
            Jetzt eintragen
        </a>
    </p>
    <p style="color: #6c757d; font-size: 12px;">
        Falls der Button nicht funktioniert: {{ link }}
    </p>
</div>
```

- [ ] **Step 4: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "RegistrationReminderMailFeatureTest|MailQueueFeatureTest"`
Expected: PASS.

- [ ] **Step 5: phpcs, twigcs + Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Services/MailQueueService.php templates/emails/registration_reminder.twig tests/Feature/RegistrationReminderMailFeatureTest.php
git commit -m "feat: Erinnerungsmail-Enqueue und E-Mail-Template"
```

---

### Task 13: RegistrationReminderService + Trigger (Middleware, CLI, Einstellung)

**Files:**
- Create: `src/Services/RegistrationReminderService.php`
- Create: `src/Middleware/RegistrationReminderMiddleware.php`
- Create: `src/Commands/SendRegistrationRemindersCommand.php`, `bin/send_registration_reminders.php`
- Modify: `src/Middleware.php` (Middleware registrieren), `src/Controllers/AppSettingController.php` + `templates/settings/index.twig` (Feld `registration_reminder_days_before`; genauen Template-Pfad per `grep -rn "mailqueue_trigger_mode" templates/` lokalisieren)
- Test: `tests/Feature/RegistrationReminderServiceFeatureTest.php`

**Interfaces:**
- Consumes: `MailQueueService::enqueueRegistrationReminderMail` (Task 12), `Event`-Spalten (Task 1), `AppSetting`.
- Produces:
  - `RegistrationReminderService::__construct(MailQueueService $mailQueueService, Twig $view, LoggerInterface $logger)`
  - `RegistrationReminderService::processDue(string $baseUrl): int` — Anzahl enqueueter Mails. Logik: Setting `registration_reminder_days_before` (0/leer ⇒ 0 zurück); Events mit `registration_enabled = 1`, `registration_reminder_sent_at IS NULL`, Deadline in Zukunft und ≤ X Tage entfernt; Empfänger = eligible User (Projekt-Scope wie Task 5) minus User mit Registrierung, nur mit gültiger E-Mail; nach Versand `registration_reminder_sent_at = now` (auch bei 0 Empfängern, damit die Runde abgeschlossen ist).
  - `RegistrationReminderMiddleware` — Drosselung über AppSetting-Key `registration_reminder_last_check_at` (Intervall 3600 s), Muster `MailQueueProcessingMiddleware`; Feature-Flag-Check (`settings['modules']['registration']`) im Konstruktor injiziert.
  - CLI: `php bin/send_registration_reminders.php` (Command-Name `registration:send-reminders`, liest `APP_URL` via `EnvHelper`).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/RegistrationReminderServiceFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\MailQueue;
use App\Models\User;
use App\Services\MailQueueService;
use App\Services\RegistrationReminderService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Bootstrap;

class RegistrationReminderServiceFeatureTest extends TestCase
{
    private Event $event;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();

        AppSetting::updateOrCreate(
            ['setting_key' => 'registration_reminder_days_before'],
            ['setting_value' => '3', 'binary_content' => '', 'mime_type' => 'text/plain']
        );

        $this->event = Event::create([
            'title' => 'Probe Erinnerungstest',
            'starts_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(2)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        MailQueue::where('mail_type', 'registration_reminder')->delete();
        EventRegistration::where('event_id', $this->event->id)->delete();
        $this->event->delete();
        AppSetting::where('setting_key', 'registration_reminder_days_before')->delete();
    }

    private function service(): RegistrationReminderService
    {
        return new RegistrationReminderService(
            new MailQueueService(),
            Twig::create(dirname(__DIR__) . '/../templates'),
            new NullLogger()
        );
    }

    public function testRemindsOnlyUnregisteredUsersAndMarksEvent(): void
    {
        $registered = User::where('is_active', 1)->whereNotNull('email')->firstOrFail();
        EventRegistration::create([
            'event_id' => $this->event->id,
            'user_id' => $registered->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $registered->id,
        ]);

        $count = $this->service()->processDue('https://chor.example');

        $this->assertGreaterThan(0, $count);
        $this->assertSame(0, MailQueue::where('mail_type', 'registration_reminder')
            ->where('recipient_email', $registered->email)->count());

        $mail = MailQueue::where('mail_type', 'registration_reminder')->firstOrFail();
        $this->assertStringContainsString(
            'https://chor.example/registrations/' . $this->event->id,
            $mail->body_html
        );

        $this->event->refresh();
        $this->assertNotNull($this->event->registration_reminder_sent_at);
    }

    public function testSecondRunSendsNothing(): void
    {
        $this->service()->processDue('https://chor.example');
        $firstCount = MailQueue::where('mail_type', 'registration_reminder')->count();

        $second = $this->service()->processDue('https://chor.example');

        $this->assertSame(0, $second);
        $this->assertSame($firstCount, MailQueue::where('mail_type', 'registration_reminder')->count());
    }

    public function testEventOutsideWindowIsSkipped(): void
    {
        $this->event->update([
            'starts_at' => Carbon::now()->addDays(30),
            'ends_at' => Carbon::now()->addDays(30)->addHours(2),
        ]);

        $count = $this->service()->processDue('https://chor.example');

        $this->assertSame(0, $count);
        $this->event->refresh();
        $this->assertNull($this->event->registration_reminder_sent_at);
    }

    public function testDisabledSettingSendsNothing(): void
    {
        AppSetting::where('setting_key', 'registration_reminder_days_before')
            ->update(['setting_value' => '0']);

        $this->assertSame(0, $this->service()->processDue('https://chor.example'));
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationReminderServiceFeatureTest`
Expected: FAIL.

- [ ] **Step 3: Service implementieren**

`src/Services/RegistrationReminderService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

class RegistrationReminderService
{
    public function __construct(
        private readonly MailQueueService $mailQueueService,
        private readonly Twig $view,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Enqueue reminder mails for events whose registration deadline is
     * within the configured window. Returns the number of enqueued mails.
     */
    public function processDue(string $baseUrl): int
    {
        $daysBefore = (int) (AppSetting::query()
            ->where('setting_key', 'registration_reminder_days_before')
            ->value('setting_value') ?? 0);

        if ($daysBefore <= 0) {
            return 0;
        }

        $now = Carbon::now();
        $windowEnd = $now->copy()->addDays($daysBefore);

        $events = Event::where('registration_enabled', true)
            ->whereNull('registration_reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->get()
            ->filter(function (Event $event) use ($now, $windowEnd) {
                $deadline = $event->registrationDeadlineAt();
                return $deadline->greaterThan($now) && $deadline->lessThanOrEqualTo($windowEnd);
            });

        $appName = (string) (AppSetting::query()
            ->where('setting_key', 'app_name')
            ->value('setting_value') ?? 'Chor-Manager');

        $enqueued = 0;

        foreach ($events as $event) {
            $recipients = $this->unregisteredEligibleUsers($event);

            foreach ($recipients as $user) {
                try {
                    $link = rtrim($baseUrl, '/') . '/registrations/' . $event->id;
                    $bodyHtml = $this->view->fetch('emails/registration_reminder.twig', [
                        'user' => $user,
                        'event' => $event,
                        'deadline' => $event->registrationDeadlineAt()->format('d.m.Y H:i'),
                        'link' => $link,
                        'app_name' => $appName,
                    ]);

                    $this->mailQueueService->enqueueRegistrationReminderMail(
                        (string) $user->email,
                        'Erinnerung: Anmeldung zu "' . $event->title . '"',
                        $bodyHtml,
                        (int) $user->id,
                        (int) $event->id
                    );
                    $enqueued++;
                } catch (\Exception $e) {
                    $this->logger->error('Enqueueing registration reminder failed.', [
                        'event' => 'registration_reminder.enqueue_failed',
                        'event_id' => (int) $event->id,
                        'user_id' => (int) $user->id,
                        'exception' => $e,
                    ]);
                }
            }

            $event->update(['registration_reminder_sent_at' => Carbon::now()]);

            $this->logger->info('Registration reminder round completed.', [
                'event' => 'registration_reminder.sent',
                'event_id' => (int) $event->id,
                'recipient_count' => count($recipients),
            ]);
        }

        return $enqueued;
    }

    /**
     * @return array<int, User>
     */
    private function unregisteredEligibleUsers(Event $event): array
    {
        $query = User::where('is_active', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereDoesntHave('eventRegistrations', function ($q) use ($event) {
                $q->where('event_id', (int) $event->id);
            });

        if ($event->project_id !== null) {
            $query->whereHas('projects', function ($projectQuery) use ($event) {
                $projectQuery->where('projects.id', (int) $event->project_id);
            });
        }

        return $query->get()->all();
    }
}
```

- [ ] **Step 4: Service-Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationReminderServiceFeatureTest`
Expected: PASS.

- [ ] **Step 5: Middleware + CLI + Einstellung implementieren**

`src/Middleware/RegistrationReminderMiddleware.php` (Muster `MailQueueProcessingMiddleware`):

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\AppSetting;
use App\Services\RegistrationReminderService;
use App\Util\AppUrlResolver;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

class RegistrationReminderMiddleware implements MiddlewareInterface
{
    private const CHECK_INTERVAL_SECONDS = 3600;

    public function __construct(
        private readonly RegistrationReminderService $reminderService,
        private readonly LoggerInterface $logger,
        private readonly bool $featureEnabled
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->processIfDue($request);

        return $handler->handle($request);
    }

    private function processIfDue(Request $request): void
    {
        if (!$this->featureEnabled) {
            return;
        }

        try {
            $lastRunRaw = AppSetting::query()
                ->where('setting_key', 'registration_reminder_last_check_at')
                ->value('setting_value');

            if ($lastRunRaw !== null && $lastRunRaw !== '') {
                $lastRun = Carbon::parse((string) $lastRunRaw);
                if ($lastRun->addSeconds(self::CHECK_INTERVAL_SECONDS)->isFuture()) {
                    return;
                }
            }

            AppSetting::updateOrCreate(
                ['setting_key' => 'registration_reminder_last_check_at'],
                [
                    'setting_value' => Carbon::now()->format('Y-m-d H:i:s'),
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

            $this->reminderService->processDue(AppUrlResolver::resolveBaseUrl($request));
        } catch (\Throwable $exception) {
            $this->logger->error('Opportunistic registration reminder processing failed.', [
                'event' => 'registration_reminder.opportunistic.failed',
                'exception' => $exception,
            ]);
        }
    }
}
```

`src/Middleware.php` — Registrierung neben `MailQueueProcessingMiddleware` (Zeile 73); Feature-Flag aus dem Container lesen (Muster im File prüfen — `$app->getContainer()`):

```php
    $settings = $app->getContainer()?->get('settings') ?? [];
    if ($settings['modules']['registration'] ?? false) {
        $app->add(RegistrationReminderMiddleware::class);
    }
```

Dazu in `src/Dependencies.php` eine Definition ergänzen (Muster vorhandener Definitionen übernehmen), weil der `bool $featureEnabled`-Parameter nicht autowirebar ist:

```php
        RegistrationReminderMiddleware::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');

            return new RegistrationReminderMiddleware(
                $c->get(RegistrationReminderService::class),
                $c->get(LoggerInterface::class),
                (bool) ($settings['modules']['registration'] ?? false)
            );
        },
```

(Alternativ: Flag-Check nur in `src/Middleware.php` und Konstruktor ohne bool — dann einfacher; beim Implementieren die schlankere Variante wählen, Test prüft nur Registrierung + Gate.)

`src/Commands/SendRegistrationRemindersCommand.php` (Muster `ProcessMailQueueCommand` lesen und übernehmen):

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\RegistrationReminderService;
use App\Util\EnvHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'registration:send-reminders')]
class SendRegistrationRemindersCommand extends Command
{
    public function __construct(private readonly RegistrationReminderService $reminderService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (EnvHelper::read('FEATURE_REGISTRATION', 'false') !== 'true') {
            $output->writeln('FEATURE_REGISTRATION ist deaktiviert - nichts zu tun.');
            return Command::SUCCESS;
        }

        $baseUrl = trim(EnvHelper::read('APP_URL', ''));
        if ($baseUrl === '') {
            $output->writeln('<error>APP_URL ist nicht gesetzt - Erinnerungslinks brauchen eine Basis-URL.</error>');
            return Command::FAILURE;
        }

        $count = $this->reminderService->processDue($baseUrl);
        $output->writeln(sprintf('%d Erinnerungsmails eingereiht.', $count));

        return Command::SUCCESS;
    }
}
```

Hinweis: `#[AsCommand]`-Attribut nur verwenden, wenn `ProcessMailQueueCommand` es auch tut — sonst dessen Namens-Registrierung kopieren.

`bin/send_registration_reminders.php` (Kopie von `bin/process_mail_queue.php`, Command getauscht):

```php
<?php

declare(strict_types=1);

use App\Commands\SendRegistrationRemindersCommand;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../src/Settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/../src/Dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();
$container->get(Capsule::class);

$application = new Application('ChorManager Registration Reminders');
$application->addCommand($container->get(SendRegistrationRemindersCommand::class));
$application->setDefaultCommand('registration:send-reminders', true);

$application->run();
```

Einstellungs-UI: In `src/Controllers/AppSettingController.php` beim Speichern (Muster `mailqueue_batch_size`, Zeile 99) ergänzen:

```php
            AppSetting::updateOrCreate(
                ['setting_key' => 'registration_reminder_days_before'],
                [
                    'setting_value' => (string) max(0, (int) ($data['registration_reminder_days_before'] ?? 0)),
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );
```

Im Settings-Template (per `grep -rn "mailqueue_batch_size" templates/` finden) ein Zahlenfeld ergänzen, gated mit `{% if settings.modules.registration %}`:

```twig
    {% if settings.modules.registration %}
        <div class="mb-3">
            <label class="form-label" for="registration-reminder-days">
                Erinnerungsmail: Tage vor Anmeldeschluss (0 = aus)
            </label>
            <input type="number" min="0" max="30" class="form-control" id="registration-reminder-days"
                   name="registration_reminder_days_before"
                   value="{{ settings_values.registration_reminder_days_before|default(0) }}">
        </div>
    {% endif %}
```

(`settings_values` = Variablenname, unter dem das Template die AppSettings bekommt — beim Implementieren am Bestand ausrichten.)

- [ ] **Step 6: Struktur-Assertions ergänzen + alle Tests ausführen**

In `RegistrationReminderServiceFeatureTest` ergänzen:

```php
    public function testTriggerWiringExists(): void
    {
        $middlewarePipeline = file_get_contents(dirname(__DIR__) . '/../src/Middleware.php');
        $this->assertIsString($middlewarePipeline);
        $this->assertStringContainsString('RegistrationReminderMiddleware', $middlewarePipeline);

        $this->assertFileExists(dirname(__DIR__) . '/../bin/send_registration_reminders.php');
        $this->assertFileExists(dirname(__DIR__) . '/../src/Commands/SendRegistrationRemindersCommand.php');

        $appSettings = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AppSettingController.php');
        $this->assertIsString($appSettings);
        $this->assertStringContainsString('registration_reminder_days_before', $appSettings);
    }
```

Run: `ddev exec vendor/bin/phpunit --filter RegistrationReminderServiceFeatureTest`
Expected: PASS.

- [ ] **Step 7: phpcs, twigcs + Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Services/RegistrationReminderService.php src/Middleware/RegistrationReminderMiddleware.php src/Middleware.php src/Dependencies.php src/Commands/SendRegistrationRemindersCommand.php bin/send_registration_reminders.php src/Controllers/AppSettingController.php templates tests/Feature/RegistrationReminderServiceFeatureTest.php
git commit -m "feat: Erinnerungsmails vor Anmeldeschluss (opportunistisch + CLI)"
```

---

### Task 14: Dev-Seed-Daten

**Files:**
- Modify: `src/Services/DevSeedService.php`
- Test: Seed-Lauf + Report-Inspektion (kein separater PHPUnit-Test nötig; bestehende DevSeed-Coverage-Tests beachten: `tests/Feature/DevSeedBackupCoverageFeatureTest.php` prüft ggf. Vollständigkeit)

**Interfaces:**
- Consumes: `EventRegistration`, Event-Spalten, alle Vorgänger-Tasks.
- Produces: Seed-Report-Zähler `event_registrations`; Termine mit Anmeldekonfiguration.

- [ ] **Step 1: Pflicht-Checkliste umsetzen (`/dev-seed-completeness` beachten)**

In `src/Services/DevSeedService.php`:

1. Import ergänzen: `use App\Models\EventRegistration;`
2. Zähler-Init in `run()` (Zeile ~137, `'app_settings' => 0`-Block): `'event_registrations' => 0,`
3. `resetSeedData()` (Zeile 226): `event_registrations` in die Tabellenliste (Zeile ~273, VOR `events` wegen FK) aufnehmen.
4. `seedAppSettings()` (Zeile 1204): Key ergänzen:

```php
            ['setting_key' => 'registration_reminder_days_before', 'setting_value' => '3'],
```

5. In `seedProjectEvents()` / `seedGlobalEvents()`: einen Teil der ZUKÜNFTIGEN Termine (z. B. jeden zweiten) mit `'registration_enabled' => true` erzeugen; je einen davon mit `'registration_deadline' => <2 Tage vor starts_at>`; mindestens einen zukünftigen Termin mit `'attendance_required' => false, 'registration_enabled' => true`; mindestens einen VERGANGENEN Termin mit `'registration_enabled' => true` (für die Nachbetrachtung); mindestens einen zukünftigen Termin, dessen Deadline im Erinnerungsfenster (≤ 3 Tage) liegt und `registration_reminder_sent_at = null`.
6. Neue Methode `seedEventRegistrations(array $projectMembers, array $projectEvents): void` — Muster `seedAttendance()` (Zeile 907) lesen und übernehmen: für jeden Termin mit `registration_enabled` ca. 60–80 % der zugehörigen Mitglieder eintragen, Statusverteilung ca. 60 % yes / 20 % no / 20 % maybe, bei `no` teilweise `note` („Beruflich verhindert", „Im Urlaub", „Krank gemeldet"), ca. 10 % der Einträge mit `updated_by` = ID eines Stimmvertreters (≠ `user_id`), Rest `updated_by = user_id`. Zähler `$this->report['counts']['event_registrations']++` pro Datensatz.
7. `run()`-Flow: `seedEventRegistrations(...)` direkt nach `seedAttendance(...)` aufrufen (gleiche Argumente).

- [ ] **Step 2: Echten Seed-Lauf ausführen**

Run: `ddev exec php bin/dev_seed.php` (bzw. `ddev composer seed:dev`)
Expected: Report zeigt `event_registrations > 0`. Report-Ausgabe im Ergebnis zitieren.

- [ ] **Step 3: Report inspizieren + Stichprobe**

Run: `ddev exec php -r "require 'vendor/autoload.php'; ..."` oder einfacher via DB:
`ddev exec mysql -e "SELECT status, COUNT(*) FROM event_registrations GROUP BY status; SELECT COUNT(*) FROM events WHERE registration_enabled = 1;" db`
Expected: alle drei Status vertreten; Termine mit Anmeldung vorhanden.

- [ ] **Step 4: Tests + phpcs + Commit**

```bash
ddev exec vendor/bin/phpunit
ddev composer phpcs
git add src/Services/DevSeedService.php
git commit -m "feat: Dev-Seed-Daten fuer Termin-Anmeldungen"
```

---

### Task 15: Abschluss-Verifikation

**Files:** keine neuen — Gesamtprüfung.

- [ ] **Step 1: Volle Testsuite**

Run: `ddev composer test`
Expected: PASS, 0 Failures. (Enthält LF-Check.)

- [ ] **Step 2: Linting komplett**

Run: `ddev composer phpcs && ddev composer twigcs`
Expected: keine Fehler; sonst `ddev composer phpcbf` / `ddev composer twigcbf` und erneut prüfen.

- [ ] **Step 3: Migrations-Status**

Run: `ddev exec ./vendor/bin/phinx status`
Expected: alle Migrationen `up`.

- [ ] **Step 4: Manuelle Smoke-Prüfung (nur Kommandozeile)**

Run: `ddev exec php bin/send_registration_reminders.php`
Expected: `N Erinnerungsmails eingereiht.` ohne Exception (N ≥ 0; nach Seed-Lauf ggf. > 0, zweiter Lauf 0).

- [ ] **Step 5: Ergebnis berichten**

Zusammenfassung an den Nutzer: was geändert, welche Kommandos, Testresultate, Migrationsstatus, Seed-Report-Zahlen. Kein `git push` — Branch `feature/event-registration` bleibt lokal.

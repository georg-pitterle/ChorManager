# Backup-Verwaltung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Backup-Verwaltung" area where users with a new `can_manage_backups` right can create, list, download, delete, and restore MySQL database backups (manual via button, automatic via CLI/Cron), with restore invalidating all active sessions.

**Architecture:** `mysqldump`/`mysql` are shelled out via a `DumpRunnerInterface` abstraction (real impl: `MysqldumpRunner` using `proc_open`). The filesystem is the source of truth for backup metadata (one `.sql.gz` + one sidecar `.json` per backup, no DB table) so the backup list survives a restore unchanged. Session invalidation is a lazy epoch check: `AuthMiddleware` compares a per-session `auth_epoch` against an `AppSetting` key `session_valid_after`, which `BackupService::restore()` bumps to `now()` after a successful import.

**Tech Stack:** PHP 8.5, Slim 4, PHP-DI, Eloquent (`illuminate/database`), Phinx, Twig, PHPUnit 10, Symfony Console, MySQL/MariaDB (`mysqldump`/`mysql` CLI, present in the DDEV web container per `Dockerfile:9`).

## Global Constraints

- PSR-12, 4 spaces, soft line limit 120 / hard 130 (`instructions/php-style.md`).
- Twig: double quotes only, named-arg defaults with no spaces around `=`, multi-line booleans forbidden — extract to `{% set %}` (`instructions/twig-style.md`).
- No inline JS/CSS in templates; only locally served assets (`instructions/template-hygiene.md`).
- `Psr\Log\LoggerInterface` for app logging, structured JSON via Monolog to stderr, every entry has an `event` key, exceptions logged under `exception` (`instructions/logging.md`).
- Never use `error_log()` in `src/`.
- Schema changes only via Phinx migrations; run with `ddev exec ./vendor/bin/phinx migrate` (`instructions/database.md`).
- TDD: failing test before implementation code (`instructions/feature-tests.md`).
- Every new persisted feature needs Dev seed coverage (`instructions/seed.md`).
- LF line endings for all new/edited text files except `.bat`/`.cmd`/`.ps1` (`instructions/line-endings.md`).
- Report what changed, what was executed, and the result after every task (`instructions/change-reporting.md`).
- Never `git push` (`instructions/git-push-guard.md`).

---

## Task 1: Test infra fix + `can_manage_backups` schema column

**Files:**
- Modify: `phpunit.xml`
- Create: `db/migrations/20260622000000_add_can_manage_backups_to_roles.php`
- Create: `db/migrations/20260622000001_backfill_backup_permission_for_admin_role.php`
- Modify: `src/Models/Role.php:14-31` (`$fillable`)
- Test: `tests/Unit/Models/RoleBackupPermissionTest.php`

**Interfaces:**
- Consumes: nothing (foundational task).
- Produces: `roles.can_manage_backups` column (TINYINT(1), default 0); `Role::$fillable` accepts `can_manage_backups`. Later tasks (2, 5, 11) read/write this column via the `Role` model.

`tests/Unit/` currently exists (`tests/Unit/Services/SheetArchiveServiceTest.php`) but `phpunit.xml` only scans `tests/Feature`, so Unit tests never actually run under `composer test`. This task fixes that first so every Unit test added in this plan is actually executed.

- [ ] **Step 1: Add `tests/Unit` to the PHPUnit testsuite**

In `phpunit.xml`, change:

```xml
    <testsuites>
        <testsuite name="Newsletter Tests">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
```

to:

```xml
    <testsuites>
        <testsuite name="Newsletter Tests">
            <directory>tests/Feature</directory>
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
```

- [ ] **Step 2: Run the full suite to confirm the previously-unexecuted Unit test still passes**

Run: `ddev exec php vendor/bin/phpunit`
Expected: `OK` — `SheetArchiveServiceTest` is now included and passes. If it fails, stop and investigate before continuing; do not paper over a pre-existing failure.

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/Models/RoleBackupPermissionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Role;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

final class RoleBackupPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    public function testCanManageBackupsIsMassAssignableAndPersists(): void
    {
        $role = Role::create([
            'name' => 'Backup Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_backups' => 1,
        ]);

        $fresh = Role::find($role->id);

        $this->assertSame(1, (int) $fresh->can_manage_backups);

        $fresh->delete();
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Models/RoleBackupPermissionTest.php`
Expected: FAIL — `SQLSTATE[42S22]: Column not found: can_manage_backups` (column doesn't exist yet).

- [ ] **Step 5: Create the migrations**

Create `db/migrations/20260622000000_add_can_manage_backups_to_roles.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCanManageBackupsToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles ADD COLUMN can_manage_backups TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_budget;"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_backups;");
    }
}
```

Create `db/migrations/20260622000001_backfill_backup_permission_for_admin_role.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BackfillBackupPermissionForAdminRole extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE roles SET can_manage_backups = 1 WHERE LOWER(name) = 'admin';");
    }

    public function down(): void
    {
        $this->execute("UPDATE roles SET can_manage_backups = 0 WHERE LOWER(name) = 'admin';");
    }
}
```

- [ ] **Step 6: Run the migrations**

Run: `ddev exec ./vendor/bin/phinx migrate`
Expected: both migrations report `OK`. Report the exact output (per `instructions/database.md`).

- [ ] **Step 7: Add the column to `Role::$fillable`**

In `src/Models/Role.php`, change:

```php
    protected $fillable = [
        'name',
        'hierarchy_level',
        'can_manage_users',
        'can_edit_users',
        'can_manage_attendance',
        'can_manage_project_members',
        'can_read_finances',
        'can_manage_finances',
        'can_manage_master_data',
        'can_manage_sponsoring',
        'can_manage_song_library',
        'can_manage_newsletters',
        'can_manage_mail_queue',
        'can_manage_sheet_archive',
        'can_manage_budget',
        'can_manage_tasks',
    ];
```

to:

```php
    protected $fillable = [
        'name',
        'hierarchy_level',
        'can_manage_users',
        'can_edit_users',
        'can_manage_attendance',
        'can_manage_project_members',
        'can_read_finances',
        'can_manage_finances',
        'can_manage_master_data',
        'can_manage_sponsoring',
        'can_manage_song_library',
        'can_manage_newsletters',
        'can_manage_mail_queue',
        'can_manage_sheet_archive',
        'can_manage_budget',
        'can_manage_tasks',
        'can_manage_backups',
    ];
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Models/RoleBackupPermissionTest.php`
Expected: `OK (1 test, ...)`.

- [ ] **Step 9: Commit**

```bash
git add phpunit.xml db/migrations/20260622000000_add_can_manage_backups_to_roles.php db/migrations/20260622000001_backfill_backup_permission_for_admin_role.php src/Models/Role.php tests/Unit/Models/RoleBackupPermissionTest.php
git commit -m "feat: add can_manage_backups role permission and enable Unit test suite"
```

---

## Task 2: `SessionAuthService` — session permission flag + login epoch

**Files:**
- Modify: `src/Services/SessionAuthService.php`
- Test: `tests/Unit/Services/SessionAuthServiceBackupPermissionTest.php`

**Interfaces:**
- Consumes: `Role::can_manage_backups` (Task 1).
- Produces: `$_SESSION['can_manage_backups']` (bool, read by `RoleMiddleware` in Task 3 and by templates); `$_SESSION['auth_epoch']` (int unix timestamp, set once per session, read by `AuthMiddleware` in Task 4).

`AuthMiddleware` calls `setAuthenticatedUser()` on every authenticated request to refresh the rights snapshot. `auth_epoch` must therefore be set **only if absent**, not overwritten every request — otherwise it would always read as "now" and the restore-invalidation check in Task 4 could never trigger.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/SessionAuthServiceBackupPermissionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Models\User;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

final class SessionAuthServiceBackupPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    public function testSetAuthenticatedUserExposesBackupPermissionFromRole(): void
    {
        $role = Role::create([
            'name' => 'Backup Manager ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_backups' => 1,
        ]);

        $user = User::create([
            'first_name' => 'Backup',
            'last_name' => 'Tester',
            'email' => 'backup.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        (new SessionAuthService())->setAuthenticatedUser($user);

        $this->assertTrue($_SESSION['can_manage_backups']);

        $user->delete();
        $role->delete();
    }

    public function testSetAuthenticatedUserSetsAuthEpochOnceAndDoesNotOverwriteIt(): void
    {
        $role = Role::create([
            'name' => 'Plain Member ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);

        $user = User::create([
            'first_name' => 'Epoch',
            'last_name' => 'Tester',
            'email' => 'epoch.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        $service = new SessionAuthService();
        $service->setAuthenticatedUser($user);
        $firstEpoch = $_SESSION['auth_epoch'];

        sleep(1);
        $service->setAuthenticatedUser($user);

        $this->assertSame($firstEpoch, $_SESSION['auth_epoch']);

        $user->delete();
        $role->delete();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Services/SessionAuthServiceBackupPermissionTest.php`
Expected: FAIL — undefined array key `can_manage_backups` / `auth_epoch`.

- [ ] **Step 3: Add the permission flag**

In `src/Services/SessionAuthService.php`, change the init block:

```php
        $canManageSheetArchive = false;
        $canManageBudget = false;
        $canManageTasks = false;
        $maxRoleLevel = 0;
```

to:

```php
        $canManageSheetArchive = false;
        $canManageBudget = false;
        $canManageTasks = false;
        $canManageBackups = false;
        $maxRoleLevel = 0;
```

Then change:

```php
            if (($role->can_manage_budget ?? false)) {
                $canManageBudget = true;
            }
            if ($role->can_manage_tasks) {
                $canManageTasks = true;
            }
```

to:

```php
            if (($role->can_manage_budget ?? false)) {
                $canManageBudget = true;
            }
            if ($role->can_manage_tasks) {
                $canManageTasks = true;
            }
            if (($role->can_manage_backups ?? false)) {
                $canManageBackups = true;
            }
```

Then change:

```php
        $_SESSION['can_manage_budget'] = $canManageBudget;
        $_SESSION['can_manage_tasks'] = $canManageTasks;
        $_SESSION['role_level'] = $maxRoleLevel;
        $_SESSION['voice_group_ids'] = $user->voiceGroups->pluck('id')->toArray();
    }
```

to:

```php
        $_SESSION['can_manage_budget'] = $canManageBudget;
        $_SESSION['can_manage_tasks'] = $canManageTasks;
        $_SESSION['can_manage_backups'] = $canManageBackups;
        $_SESSION['role_level'] = $maxRoleLevel;
        $_SESSION['voice_group_ids'] = $user->voiceGroups->pluck('id')->toArray();

        if (!isset($_SESSION['auth_epoch'])) {
            $_SESSION['auth_epoch'] = time();
        }
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Services/SessionAuthServiceBackupPermissionTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/SessionAuthService.php tests/Unit/Services/SessionAuthServiceBackupPermissionTest.php
git commit -m "feat: expose can_manage_backups session flag and set-once login epoch"
```

---

## Task 3: `RoleMiddleware` — `requiresBackupManagement` gate

**Files:**
- Modify: `src/Middleware/RoleMiddleware.php`
- Test: `tests/Feature/RoleMiddlewareBackupFeatureTest.php`

**Interfaces:**
- Consumes: `$_SESSION['can_manage_backups']` (Task 2).
- Produces: `new RoleMiddleware(requiresBackupManagement: true)` constructor option, consumed by `Routes.php` in Task 9.

Per the design decision in the spec, this right is **independent** — it is deliberately NOT satisfied by `can_manage_users` (unlike most other gates in this middleware), because backup/restore is destructive and sensitive.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RoleMiddlewareBackupFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class RoleMiddlewareBackupFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
    }

    public function testUserWithBackupPermissionCanPassBackupGate(): void
    {
        $_SESSION['can_manage_backups'] = true;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(requiresBackupManagement: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/backups'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUserWithoutBackupPermissionGetsForbiddenOnBackupGateEvenAsUserManager(): void
    {
        $_SESSION['can_manage_backups'] = false;
        $_SESSION['can_manage_users'] = true;

        $middleware = new RoleMiddleware(requiresBackupManagement: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/backups'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/RoleMiddlewareBackupFeatureTest.php`
Expected: FAIL — `Unknown named parameter $requiresBackupManagement`.

- [ ] **Step 3: Add the constructor parameter and property**

In `src/Middleware/RoleMiddleware.php`, change the property list:

```php
    private bool $requiresBudgetManagement;
    private bool $requiresBudgetRead;
```

to:

```php
    private bool $requiresBudgetManagement;
    private bool $requiresBudgetRead;
    private bool $requiresBackupManagement;
```

Change the constructor signature:

```php
        bool $requiresSheetArchiveManagement = false,
        bool $requiresBudgetManagement = false,
        bool $requiresBudgetRead = false
    ) {
```

to:

```php
        bool $requiresSheetArchiveManagement = false,
        bool $requiresBudgetManagement = false,
        bool $requiresBudgetRead = false,
        bool $requiresBackupManagement = false
    ) {
```

Change the constructor body:

```php
        $this->requiresBudgetManagement = $requiresBudgetManagement;
        $this->requiresBudgetRead = $requiresBudgetRead;
    }
```

to:

```php
        $this->requiresBudgetManagement = $requiresBudgetManagement;
        $this->requiresBudgetRead = $requiresBudgetRead;
        $this->requiresBackupManagement = $requiresBackupManagement;
    }
```

- [ ] **Step 4: Read the session flag and add the gate check**

Change:

```php
        $canManageBudget = $_SESSION['can_manage_budget'] ?? false;
        $canManageTasks = $_SESSION['can_manage_tasks'] ?? false;
        $canManageAttendance = $_SESSION['can_manage_attendance'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
```

to:

```php
        $canManageBudget = $_SESSION['can_manage_budget'] ?? false;
        $canManageTasks = $_SESSION['can_manage_tasks'] ?? false;
        $canManageAttendance = $_SESSION['can_manage_attendance'] ?? false;
        $canManageBackups = $_SESSION['can_manage_backups'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
```

Then, right after the `requiresBudgetManagement` check block (immediately before the `// Budget is an aggregated view...` comment), add:

```php
        if ($this->requiresBackupManagement && !$canManageBackups) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Backup-Verwaltung.");
            return $response->withStatus(403);
        }

```

So the surrounding code reads:

```php
        if ($this->requiresBudgetManagement && !$canManageBudget && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Budgetverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresBackupManagement && !$canManageBackups) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Backup-Verwaltung.");
            return $response->withStatus(403);
        }

        // Budget is an aggregated view of finance data, so finance readers may view it
        // read-only even without budget management rights.
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/RoleMiddlewareBackupFeatureTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 6: Run the full existing `RoleMiddlewareFeatureTest` to confirm no regression**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/RoleMiddlewareFeatureTest.php`
Expected: `OK` — the new trailing constructor parameter must not break any existing positional-argument call.

- [ ] **Step 7: Commit**

```bash
git add src/Middleware/RoleMiddleware.php tests/Feature/RoleMiddlewareBackupFeatureTest.php
git commit -m "feat: add independent requiresBackupManagement gate to RoleMiddleware"
```

---

## Task 4: `AuthMiddleware` — session invalidation via `session_valid_after`

**Files:**
- Modify: `src/Middleware/AuthMiddleware.php`
- Test: `tests/Feature/AuthMiddlewareSessionInvalidationFeatureTest.php`

**Interfaces:**
- Consumes: `$_SESSION['auth_epoch']` (Task 2); `App\Models\AppSetting` key `session_valid_after` (set by `BackupService::restore()` in Task 6).
- Produces: requests from sessions older than `session_valid_after` are redirected to `/login` with the session cleared. This is the mechanism that makes "Wiederherstellung killt alle Sessions" true even though PHP sessions are file-based and not stored in the DB.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AuthMiddlewareSessionInvalidationFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\AuthMiddleware;
use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AuthMiddlewareSessionInvalidationFeatureTest extends TestCase
{
    private static ?Capsule $capsule = null;
    private AuthMiddleware $middleware;
    private User $user;
    private Role $role;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = Role::create([
            'name' => 'Invalidation Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->user = User::create([
            'first_name' => 'Invalidation',
            'last_name' => 'Tester',
            'email' => 'invalidation.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $this->user->roles()->attach($this->role->id);

        $this->middleware = new AuthMiddleware(
            new UserQuery(),
            new RememberLoginService(),
            new SessionAuthService()
        );

        AppSetting::query()->where('setting_key', 'session_valid_after')->delete();
        $_SESSION = ['user_id' => $this->user->id, 'auth_epoch' => time()];
    }

    protected function tearDown(): void
    {
        AppSetting::query()->where('setting_key', 'session_valid_after')->delete();
        $this->user->delete();
        $this->role->delete();

        parent::tearDown();
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    public function testRequestPassesWhenNoSessionInvalidationIsSet(): void
    {
        $response = $this->middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->handler()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRequestIsRedirectedToLoginWhenSessionPredatesInvalidation(): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => 'session_valid_after'],
            ['setting_value' => (string) (time() + 3600)]
        );

        $response = $this->middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->handler()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }
}
```

- [ ] **Step 2: Run the test to verify the second case fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AuthMiddlewareSessionInvalidationFeatureTest.php`
Expected: `testRequestPassesWhenNoSessionInvalidationIsSet` passes (current code already lets the request through); `testRequestIsRedirectedToLoginWhenSessionPredatesInvalidation` FAILS (expected 302, got 200 — no invalidation logic exists yet).

- [ ] **Step 3: Add the invalidation check**

In `src/Middleware/AuthMiddleware.php`, add the import:

```php
use App\Queries\UserQuery;
```

to:

```php
use App\Models\AppSetting;
use App\Queries\UserQuery;
```

Then change:

```php
        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $currentUser = $this->userQuery->findById((int) $_SESSION['user_id']);
```

to:

```php
        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $sessionValidAfter = (int) (AppSetting::query()
            ->where('setting_key', 'session_valid_after')
            ->value('setting_value') ?? 0);
        $authEpoch = (int) ($_SESSION['auth_epoch'] ?? 0);

        if ($sessionValidAfter > 0 && $authEpoch < $sessionValidAfter) {
            $this->sessionAuthService->clearSession();

            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $currentUser = $this->userQuery->findById((int) $_SESSION['user_id']);
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AuthMiddlewareSessionInvalidationFeatureTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/Middleware/AuthMiddleware.php tests/Feature/AuthMiddlewareSessionInvalidationFeatureTest.php
git commit -m "feat: invalidate sessions older than session_valid_after AppSetting"
```

---

## Task 5: Role admin UI — checkbox in create/edit forms, table column, JS sync

**Files:**
- Modify: `src/Controllers/RoleController.php:24-44,80-97,123-150`
- Modify: `templates/roles/index.twig` (4 insertion points, see steps)
- Modify: `public/js/roles.js:51-53`
- Test: `tests/Feature/RoleBackupPermissionFeatureTest.php`

**Interfaces:**
- Consumes: `Role::$fillable` accepting `can_manage_backups` (Task 1).
- Produces: nothing new consumed by later tasks — this is the admin-facing leaf for granting the right created in Task 1.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RoleBackupPermissionFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use PHPUnit\Framework\TestCase;

final class RoleBackupPermissionFeatureTest extends TestCase
{
    public function testBuildPermissionFlagsMapsBackupCheckboxPresence(): void
    {
        $withFlag = RoleController::buildPermissionFlags(['can_manage_backups' => '1']);
        $this->assertSame(1, $withFlag['can_manage_backups']);

        $withoutFlag = RoleController::buildPermissionFlags([]);
        $this->assertSame(0, $withoutFlag['can_manage_backups']);
    }

    public function testRolesTemplateExposesBackupCheckboxAndTableColumn(): void
    {
        $templateContent = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('name="can_manage_backups"', $templateContent);
        $this->assertStringContainsString('role.can_manage_backups', $templateContent);
        $this->assertStringContainsString('data-backups=', $templateContent);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/RoleBackupPermissionFeatureTest.php`
Expected: FAIL — `Undefined array key "can_manage_backups"` and the template assertions fail.

- [ ] **Step 3: Update `RoleController::buildPermissionFlags()`**

Change:

```php
            'can_manage_sheet_archive' => isset($data['can_manage_sheet_archive']) ? 1 : 0,
            'can_manage_budget' => isset($data['can_manage_budget']) && $data['can_manage_budget'] === '1' ? 1 : 0,
            'can_manage_tasks' => isset($data['can_manage_tasks']) ? 1 : 0,
        ];
    }
```

to:

```php
            'can_manage_sheet_archive' => isset($data['can_manage_sheet_archive']) ? 1 : 0,
            'can_manage_budget' => isset($data['can_manage_budget']) && $data['can_manage_budget'] === '1' ? 1 : 0,
            'can_manage_tasks' => isset($data['can_manage_tasks']) ? 1 : 0,
            'can_manage_backups' => isset($data['can_manage_backups']) ? 1 : 0,
        ];
    }
```

- [ ] **Step 4: Persist the flag in `create()` and `update()`**

In `create()`, change:

```php
                'can_manage_sheet_archive' => $permissions['can_manage_sheet_archive'],
                'can_manage_budget' => $permissions['can_manage_budget'],
                'can_manage_tasks' => $permissions['can_manage_tasks']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich angelegt.';
```

to:

```php
                'can_manage_sheet_archive' => $permissions['can_manage_sheet_archive'],
                'can_manage_budget' => $permissions['can_manage_budget'],
                'can_manage_tasks' => $permissions['can_manage_tasks'],
                'can_manage_backups' => $permissions['can_manage_backups']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich angelegt.';
```

In `update()`, change:

```php
                'can_manage_sheet_archive' => $permissions['can_manage_sheet_archive'],
                'can_manage_budget' => $permissions['can_manage_budget'],
                'can_manage_tasks' => $permissions['can_manage_tasks']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich aktualisiert.';
```

to:

```php
                'can_manage_sheet_archive' => $permissions['can_manage_sheet_archive'],
                'can_manage_budget' => $permissions['can_manage_budget'],
                'can_manage_tasks' => $permissions['can_manage_tasks'],
                'can_manage_backups' => $permissions['can_manage_backups']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich aktualisiert.';
```

- [ ] **Step 5: Add the table column badge in `templates/roles/index.twig`**

Right after the "Notenarchiv verwalten" `<tr>` block (the one testing `role.can_manage_sheet_archive`) and before the `{% if settings.modules.budget %}` budget `<tr>` block, insert:

```twig
                                    <tr>
                                        <th scope="row">Backup-Verwaltung</th>
                                        {% for role in roles %}
                                            <td data-label="{{ role.name }}" class="text-center align-middle">
                                                {% if role.can_manage_backups %}
                                                    <span class="badge rounded-pill bg-success" aria-label="Ja" title="Ja"><i class="bi bi-check-lg text-white"></i></span>
                                                {% else %}
                                                    <span class="badge rounded-pill bg-danger" aria-label="Nein" title="Nein"><i class="bi bi-x-lg text-white"></i></span>
                                                {% endif %}
                                            </td>
                                        {% endfor %}
                                    </tr>
```

- [ ] **Step 6: Add the `data-backups` attribute to the edit button**

Change:

```twig
                                                            data-sheet-archive="{{ role.can_manage_sheet_archive ? '1' : '0' }}"
                                                            data-budget="{{ role.can_manage_budget ? '1' : '0' }}"
                                                            data-tasks="{{ role.can_manage_tasks ? '1' : '0' }}">
```

to:

```twig
                                                            data-sheet-archive="{{ role.can_manage_sheet_archive ? '1' : '0' }}"
                                                            data-budget="{{ role.can_manage_budget ? '1' : '0' }}"
                                                            data-tasks="{{ role.can_manage_tasks ? '1' : '0' }}"
                                                            data-backups="{{ role.can_manage_backups ? '1' : '0' }}">
```

- [ ] **Step 7: Add the create-form checkbox**

Right after the "Notenarchiv verwalten" checkbox block in the create form (the one with `id="can_manage_sheet_archive"`) and before the `{% if settings.modules.budget %}` budget checkbox block, insert:

```twig
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input"
                               type="checkbox"
                               role="switch"
                               id="can_manage_backups"
                               name="can_manage_backups"
                               value="1">
                        <label class="form-check-label fw-bold text-primary"
                               for="can_manage_backups">Backup-Verwaltung</label>
                        <div class="form-text">Wenn aktiv, darf diese Person Datenbank-Backups erstellen, herunterladen, löschen und wiederherstellen.</div>
                    </div>
```

- [ ] **Step 8: Add the edit-form checkbox**

Right after the "Notenarchiv verwalten" checkbox block in the edit form (the one with `id="edit_can_manage_sheet_archive"`) and before the `{% if settings.modules.budget %}` budget checkbox block, insert:

```twig
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input"
                               type="checkbox"
                               role="switch"
                               id="edit_can_manage_backups"
                               name="can_manage_backups"
                               value="1">
                        <label class="form-check-label fw-bold text-primary"
                               for="edit_can_manage_backups">Backup-Verwaltung</label>
                        <div class="form-text">Wenn aktiv, darf diese Person Datenbank-Backups erstellen, herunterladen, löschen und wiederherstellen.</div>
                    </div>
```

- [ ] **Step 9: Sync the edit-modal checkbox in JS**

In `public/js/roles.js`, change:

```js
                document.getElementById('edit_can_manage_sheet_archive').checked = this.getAttribute('data-sheet-archive') === '1';
                document.getElementById('edit_can_manage_budget').checked = this.getAttribute('data-budget') === '1';
                document.getElementById('edit_can_manage_tasks').checked = this.getAttribute('data-tasks') === '1';
```

to:

```js
                document.getElementById('edit_can_manage_sheet_archive').checked = this.getAttribute('data-sheet-archive') === '1';
                document.getElementById('edit_can_manage_budget').checked = this.getAttribute('data-budget') === '1';
                document.getElementById('edit_can_manage_tasks').checked = this.getAttribute('data-tasks') === '1';
                document.getElementById('edit_can_manage_backups').checked = this.getAttribute('data-backups') === '1';
```

- [ ] **Step 10: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/RoleBackupPermissionFeatureTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 11: Run the existing role test suite to confirm no regression**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/RoleFeatureTest.php tests/Feature/RoleMiddlewareFeatureTest.php`
Expected: `OK`.

- [ ] **Step 12: Run Twig lint**

Run: `ddev composer twigcs`
Expected: no new blocking issues on `templates/roles/index.twig`. If formatting issues are reported, run `ddev composer twigcbf` and re-check.

- [ ] **Step 13: Commit**

```bash
git add src/Controllers/RoleController.php templates/roles/index.twig public/js/roles.js tests/Feature/RoleBackupPermissionFeatureTest.php
git commit -m "feat: add can_manage_backups checkbox to role admin UI"
```

---

## Task 6: `BackupService` core (filesystem-backed, dump-runner abstracted)

**Files:**
- Create: `src/Services/DumpRunnerInterface.php`
- Create: `src/Services/BackupLimitReachedException.php`
- Create: `src/Services/BackupService.php`
- Create: `tests/Unit/Services/Fakes/FakeDumpRunner.php`
- Test: `tests/Unit/Services/BackupServiceTest.php`

**Interfaces:**
- Consumes: `DumpRunnerInterface` (defined in this task, real implementation in Task 7), `Psr\Log\LoggerInterface`, `App\Models\AppSetting` (existing).
- Produces:
  - `interface DumpRunnerInterface { public function dump(string $destinationPath, bool $gzip): void; public function restore(string $sourcePath, bool $gzip): void; }`
  - `class BackupLimitReachedException extends \RuntimeException`
  - `class BackupService` with constructor `(DumpRunnerInterface $dumpRunner, LoggerInterface $logger, string $backupDir, int $maxManual, int $maxAuto, bool $gzip, string $dbDatabase, string $appVersion)` and public constants `TYPE_MANUAL = 'manual'`, `TYPE_AUTO = 'auto'`, and methods `list(): array`, `create(string $type, ?int $userId): array`, `restore(string $id): void`, `delete(string $id): void`, `getFile(string $id): array`. Consumed by `CreateBackupCommand` (Task 10), `BackupController` (Task 9), `DevSeedService` (Task 11), and wired in `Dependencies.php` (Task 8).

`restore()` writes the `session_valid_after` `AppSetting` via `updateOrCreate()`. The `app_settings` table (`db/migrations/20260314130000_initial.php:78-83`) has `binary_content longblob NOT NULL` and `mime_type varchar(100) NOT NULL` with no defaults, so every write must supply both fields — the established pattern already used in `src/Middleware/MailQueueProcessingMiddleware.php:51-58` (`'binary_content' => ''`, `'mime_type' => 'text/plain'`). This is already reflected in the `restore()` code below; do not drop those two fields.
  - `tests/Unit/Services/Fakes/FakeDumpRunner.php` — reusable test double, also consumed by Task 9 and Task 10 tests.

- [ ] **Step 1: Create the dump-runner abstraction**

Create `src/Services/DumpRunnerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

interface DumpRunnerInterface
{
    public function dump(string $destinationPath, bool $gzip): void;

    public function restore(string $sourcePath, bool $gzip): void;
}
```

- [ ] **Step 2: Create the limit exception**

Create `src/Services/BackupLimitReachedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class BackupLimitReachedException extends \RuntimeException
{
}
```

- [ ] **Step 3: Create the reusable fake dump runner for tests**

Create `tests/Unit/Services/Fakes/FakeDumpRunner.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fakes;

use App\Services\DumpRunnerInterface;

final class FakeDumpRunner implements DumpRunnerInterface
{
    public int $dumpCallCount = 0;
    public int $restoreCallCount = 0;
    public ?string $lastRestoredPath = null;

    public function dump(string $destinationPath, bool $gzip): void
    {
        $this->dumpCallCount++;
        $content = '-- fake dump --';

        if ($gzip) {
            $handle = gzopen($destinationPath, 'wb9');
            gzwrite($handle, $content);
            gzclose($handle);
        } else {
            file_put_contents($destinationPath, $content);
        }
    }

    public function restore(string $sourcePath, bool $gzip): void
    {
        $this->restoreCallCount++;
        $this->lastRestoredPath = $sourcePath;
    }
}
```

- [ ] **Step 4: Write the failing tests**

Create `tests/Unit/Services/BackupServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BackupLimitReachedException;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

final class BackupServiceTest extends TestCase
{
    private string $backupDir;
    private FakeDumpRunner $dumpRunner;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->backupDir = sys_get_temp_dir() . '/chormanager_backup_test_' . bin2hex(random_bytes(4));
        $this->dumpRunner = new FakeDumpRunner();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }

        parent::tearDown();
    }

    private function makeService(int $maxManual = 5, int $maxAuto = 5): BackupService
    {
        return new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $this->backupDir,
            $maxManual,
            $maxAuto,
            true,
            'chormanager_test',
            'test-version'
        );
    }

    public function testCreateManualBackupWritesDataAndMetadataFiles(): void
    {
        $service = $this->makeService();

        $metadata = $service->create(BackupService::TYPE_MANUAL, 7);

        $this->assertSame('manual', $metadata['type']);
        $this->assertSame(7, $metadata['created_by']);
        $this->assertFileExists($this->backupDir . '/' . $metadata['id'] . '.sql.gz');
        $this->assertFileExists($this->backupDir . '/' . $metadata['id'] . '.json');
        $this->assertSame(1, $this->dumpRunner->dumpCallCount);
    }

    public function testManualBackupBlocksWhenLimitReached(): void
    {
        $service = $this->makeService(maxManual: 1);

        $service->create(BackupService::TYPE_MANUAL, 1);

        $this->expectException(BackupLimitReachedException::class);
        $service->create(BackupService::TYPE_MANUAL, 1);
    }

    public function testAutoBackupRotatesOldestWhenLimitReached(): void
    {
        $service = $this->makeService(maxAuto: 2);

        $first = $service->create(BackupService::TYPE_AUTO, null);
        usleep(1100000);
        $second = $service->create(BackupService::TYPE_AUTO, null);
        usleep(1100000);
        $third = $service->create(BackupService::TYPE_AUTO, null);

        $remainingIds = array_column($service->list(), 'id');

        $this->assertCount(2, $remainingIds);
        $this->assertNotContains($first['id'], $remainingIds);
        $this->assertContains($second['id'], $remainingIds);
        $this->assertContains($third['id'], $remainingIds);
    }

    public function testListReturnsEntriesSortedNewestFirst(): void
    {
        $service = $this->makeService();

        $first = $service->create(BackupService::TYPE_MANUAL, 1);
        usleep(1100000);
        $second = $service->create(BackupService::TYPE_MANUAL, 1);

        $entries = $service->list();

        $this->assertSame($second['id'], $entries[0]['id']);
        $this->assertSame($first['id'], $entries[1]['id']);
    }

    public function testDeleteRemovesDataAndMetadataFiles(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->delete($metadata['id']);

        $this->assertFileDoesNotExist($this->backupDir . '/' . $metadata['id'] . '.sql.gz');
        $this->assertFileDoesNotExist($this->backupDir . '/' . $metadata['id'] . '.json');
        $this->assertCount(0, $service->list());
    }

    public function testRestoreVerifiesChecksumAndInvokesDumpRunner(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->restore($metadata['id']);

        $this->assertSame(1, $this->dumpRunner->restoreCallCount);
        $this->assertSame(
            $this->backupDir . '/' . $metadata['id'] . '.sql.gz',
            $this->dumpRunner->lastRestoredPath
        );
    }

    public function testRestoreThrowsWhenChecksumMismatches(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        file_put_contents($this->backupDir . '/' . $metadata['id'] . '.sql.gz', 'tampered content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $service->restore($metadata['id']);
    }

    public function testRestoreRejectsInvalidId(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->restore('../../etc/passwd');
    }

    public function testGetFileReturnsPathFilenameAndSize(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $file = $service->getFile($metadata['id']);

        $this->assertSame($this->backupDir . '/' . $metadata['id'] . '.sql.gz', $file['path']);
        $this->assertSame($metadata['id'] . '.sql.gz', $file['filename']);
        $this->assertSame($metadata['size'], $file['size']);
    }
}
```

- [ ] **Step 5: Run the tests to verify they fail**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Services/BackupServiceTest.php`
Expected: FAIL — `Class "App\Services\BackupService" not found`.

- [ ] **Step 6: Implement `BackupService`**

Create `src/Services/BackupService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use Psr\Log\LoggerInterface;

class BackupService
{
    public const TYPE_MANUAL = 'manual';
    public const TYPE_AUTO = 'auto';

    private const ID_PATTERN = '/^backup_(manual|auto)_\d{8}T\d{6}Z_[0-9a-f]{8}$/';

    public function __construct(
        private readonly DumpRunnerInterface $dumpRunner,
        private readonly LoggerInterface $logger,
        private readonly string $backupDir,
        private readonly int $maxManual,
        private readonly int $maxAuto,
        private readonly bool $gzip,
        private readonly string $dbDatabase,
        private readonly string $appVersion
    ) {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        $entries = [];

        foreach (glob($this->backupDir . '/*.json') ?: [] as $metaPath) {
            $decoded = json_decode((string) file_get_contents($metaPath), true);
            if (!is_array($decoded) || !isset($decoded['id'], $decoded['type'], $decoded['created_at'])) {
                continue;
            }

            $dataPath = $this->resolveDataPath($decoded);
            if ($dataPath === null || !file_exists($dataPath)) {
                continue;
            }

            $entries[] = $decoded;
        }

        usort($entries, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $entries;
    }

    /**
     * @return array<string,mixed>
     */
    public function create(string $type, ?int $userId): array
    {
        if (!in_array($type, [self::TYPE_MANUAL, self::TYPE_AUTO], true)) {
            throw new \InvalidArgumentException('type must be "manual" or "auto"');
        }

        $existingOfType = array_values(array_filter(
            $this->list(),
            static fn(array $entry): bool => $entry['type'] === $type
        ));

        if ($type === self::TYPE_MANUAL && $this->maxManual > 0 && count($existingOfType) >= $this->maxManual) {
            throw new BackupLimitReachedException(
                'Maximale Anzahl manueller Backups erreicht (' . $this->maxManual . ').'
            );
        }

        if ($type === self::TYPE_AUTO && $this->maxAuto > 0 && count($existingOfType) >= $this->maxAuto) {
            $oldest = end($existingOfType);
            if ($oldest !== false) {
                $this->delete((string) $oldest['id']);
                $this->logger->info('Oldest automatic backup rotated out.', [
                    'event' => 'backup.rotate',
                    'id' => $oldest['id'],
                ]);
            }
        }

        $base = sprintf('backup_%s_%s_%s', $type, gmdate('Ymd\THis\Z'), bin2hex(random_bytes(4)));
        $dataPath = $this->backupDir . '/' . $base . ($this->gzip ? '.sql.gz' : '.sql');
        $metaPath = $this->backupDir . '/' . $base . '.json';

        $this->logger->debug('Starting backup creation.', ['event' => 'backup.create.start', 'type' => $type]);

        try {
            $this->dumpRunner->dump($dataPath, $this->gzip);
        } catch (\Throwable $exception) {
            if (file_exists($dataPath)) {
                unlink($dataPath);
            }
            $this->logger->error('Backup creation failed.', [
                'event' => 'backup.create.failed',
                'type' => $type,
                'exception' => $exception,
            ]);
            throw $exception;
        }

        $metadata = [
            'id' => $base,
            'type' => $type,
            'created_at' => gmdate('c'),
            'created_by' => $userId,
            'size' => filesize($dataPath),
            'sha256' => hash_file('sha256', $dataPath),
            'app_version' => $this->appVersion,
            'db_name' => $this->dbDatabase,
            'gzip' => $this->gzip,
        ];

        file_put_contents($metaPath, (string) json_encode($metadata, JSON_PRETTY_PRINT));

        $this->logger->info('Backup created.', [
            'event' => 'backup.create.completed',
            'type' => $type,
            'id' => $base,
            'size' => $metadata['size'],
        ]);

        return $metadata;
    }

    public function delete(string $id): void
    {
        $this->assertValidId($id);

        $metaPath = $this->backupDir . '/' . $id . '.json';
        if (!file_exists($metaPath)) {
            throw new \RuntimeException('Backup not found: ' . $id);
        }

        $metadata = json_decode((string) file_get_contents($metaPath), true);
        $dataPath = is_array($metadata) ? $this->resolveDataPath($metadata) : null;

        if ($dataPath !== null && file_exists($dataPath)) {
            unlink($dataPath);
        }
        unlink($metaPath);

        $this->logger->info('Backup deleted.', ['event' => 'backup.delete', 'id' => $id]);
    }

    /**
     * @return array{path:string,filename:string,size:int}
     */
    public function getFile(string $id): array
    {
        $this->assertValidId($id);

        $metaPath = $this->backupDir . '/' . $id . '.json';
        if (!file_exists($metaPath)) {
            throw new \RuntimeException('Backup not found: ' . $id);
        }

        $metadata = json_decode((string) file_get_contents($metaPath), true);
        $dataPath = is_array($metadata) ? $this->resolveDataPath($metadata) : null;

        if ($dataPath === null || !file_exists($dataPath)) {
            throw new \RuntimeException('Backup data file missing: ' . $id);
        }

        return [
            'path' => $dataPath,
            'filename' => basename($dataPath),
            'size' => (int) ($metadata['size'] ?? filesize($dataPath)),
        ];
    }

    public function restore(string $id): void
    {
        $this->assertValidId($id);

        $metaPath = $this->backupDir . '/' . $id . '.json';
        if (!file_exists($metaPath)) {
            throw new \RuntimeException('Backup not found: ' . $id);
        }

        $metadata = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($metadata)) {
            throw new \RuntimeException('Backup metadata is corrupt: ' . $id);
        }

        $dataPath = $this->resolveDataPath($metadata);
        if ($dataPath === null || !file_exists($dataPath)) {
            throw new \RuntimeException('Backup data file missing: ' . $id);
        }

        $actualHash = hash_file('sha256', $dataPath);
        if (!isset($metadata['sha256']) || !hash_equals((string) $metadata['sha256'], (string) $actualHash)) {
            $this->logger->error('Backup restore aborted due to checksum mismatch.', [
                'event' => 'backup.restore.failed',
                'id' => $id,
                'reason' => 'checksum_mismatch',
            ]);
            throw new \RuntimeException('Backup file integrity check failed: ' . $id);
        }

        $this->logger->info('Starting backup restore.', ['event' => 'backup.restore.start', 'id' => $id]);

        try {
            $this->dumpRunner->restore($dataPath, (bool) ($metadata['gzip'] ?? true));
        } catch (\Throwable $exception) {
            $this->logger->error('Backup restore failed.', [
                'event' => 'backup.restore.failed',
                'id' => $id,
                'exception' => $exception,
            ]);
            throw $exception;
        }

        AppSetting::updateOrCreate(
            ['setting_key' => 'session_valid_after'],
            [
                'setting_value' => (string) time(),
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ]
        );

        $this->logger->info('Backup restore completed.', ['event' => 'backup.restore.completed', 'id' => $id]);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function resolveDataPath(array $metadata): ?string
    {
        if (!isset($metadata['id'])) {
            return null;
        }

        $extension = ($metadata['gzip'] ?? true) ? '.sql.gz' : '.sql';

        return $this->backupDir . '/' . $metadata['id'] . $extension;
    }

    private function assertValidId(string $id): void
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new \InvalidArgumentException('Invalid backup id: ' . $id);
        }
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Services/BackupServiceTest.php`
Expected: `OK (9 tests, ...)`.

- [ ] **Step 8: Run `phpcs`**

Run: `ddev composer phpcs`
Expected: no new violations in `src/Services/BackupService.php`, `src/Services/DumpRunnerInterface.php`, `src/Services/BackupLimitReachedException.php`. If formatting issues are reported, run `ddev composer phpcbf` and re-check.

- [ ] **Step 9: Commit**

```bash
git add src/Services/DumpRunnerInterface.php src/Services/BackupLimitReachedException.php src/Services/BackupService.php tests/Unit/Services/Fakes/FakeDumpRunner.php tests/Unit/Services/BackupServiceTest.php
git commit -m "feat: add BackupService with filesystem-backed metadata and rotation/limit logic"
```

---

## Task 7: `MysqldumpRunner` — real `mysqldump`/`mysql` implementation

**Files:**
- Create: `src/Services/MysqldumpRunner.php`
- Test: `tests/Feature/MysqldumpRunnerFeatureTest.php`

**Interfaces:**
- Consumes: `DumpRunnerInterface` (Task 6).
- Produces: `class MysqldumpRunner implements DumpRunnerInterface` with constructor `(string $host, string $port, string $database, string $username, string $password)`. Wired into the container in Task 8.

This test runs real `mysqldump`/`mysql` binaries against the actual DDEV test DB (the `mysql-client` package is already installed per `Dockerfile:9`). It is slower than the other tests in this plan because it dumps the entire database. To stay non-destructive to seeded app data, it uses its own disposable probe table rather than asserting on real app tables.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MysqldumpRunnerFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MysqldumpRunner;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

final class MysqldumpRunnerFeatureTest extends TestCase
{
    private static ?Capsule $capsule = null;
    private string $tmpFile;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Capsule::connection()->statement(
            'CREATE TABLE IF NOT EXISTS backup_runner_probe (id INT PRIMARY KEY, marker VARCHAR(64))'
        );
        Capsule::connection()->table('backup_runner_probe')->truncate();
        Capsule::connection()->table('backup_runner_probe')->insert([
            'id' => 1,
            'marker' => 'probe-before-restore',
        ]);

        $this->tmpFile = sys_get_temp_dir() . '/chormanager_mysqldump_test_' . bin2hex(random_bytes(4)) . '.sql.gz';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        Capsule::connection()->statement('DROP TABLE IF EXISTS backup_runner_probe');

        parent::tearDown();
    }

    private function makeRunner(): MysqldumpRunner
    {
        return new MysqldumpRunner(
            (string) ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db'),
            (string) ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? '3306'),
            (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db'),
            (string) ($_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db'),
            (string) ($_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db')
        );
    }

    public function testDumpAndRestoreRoundTripsProbeTable(): void
    {
        $runner = $this->makeRunner();

        $runner->dump($this->tmpFile, true);

        $this->assertFileExists($this->tmpFile);
        $this->assertGreaterThan(0, filesize($this->tmpFile));

        Capsule::connection()->table('backup_runner_probe')->update(['marker' => 'probe-overwritten']);

        $runner->restore($this->tmpFile, true);

        $row = Capsule::connection()->table('backup_runner_probe')->where('id', 1)->first();
        $this->assertSame('probe-before-restore', $row->marker);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/MysqldumpRunnerFeatureTest.php`
Expected: FAIL — `Class "App\Services\MysqldumpRunner" not found`.

- [ ] **Step 3: Implement `MysqldumpRunner`**

Create `src/Services/MysqldumpRunner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class MysqldumpRunner implements DumpRunnerInterface
{
    public function __construct(
        private readonly string $host,
        private readonly string $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password
    ) {
    }

    public function dump(string $destinationPath, bool $gzip): void
    {
        $process = proc_open(
            [
                'mysqldump',
                '--host=' . $this->host,
                '--port=' . $this->port,
                '--user=' . $this->username,
                '--single-transaction',
                '--routines',
                '--triggers',
                $this->database,
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            ['MYSQL_PWD' => $this->password]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start mysqldump process.');
        }

        $out = $gzip ? gzopen($destinationPath, 'wb9') : fopen($destinationPath, 'wb');
        if ($out === false) {
            proc_close($process);
            throw new \RuntimeException('Failed to open backup destination file: ' . $destinationPath);
        }

        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            $gzip ? gzwrite($out, $chunk) : fwrite($out, $chunk);
        }

        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $gzip ? gzclose($out) : fclose($out);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            if (file_exists($destinationPath)) {
                unlink($destinationPath);
            }
            throw new \RuntimeException('mysqldump failed with exit code ' . $exitCode . ': ' . $errorOutput);
        }
    }

    public function restore(string $sourcePath, bool $gzip): void
    {
        $process = proc_open(
            [
                'mysql',
                '--host=' . $this->host,
                '--port=' . $this->port,
                '--user=' . $this->username,
                $this->database,
            ],
            [
                0 => ['pipe', 'r'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            ['MYSQL_PWD' => $this->password]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start mysql restore process.');
        }

        $in = $gzip ? gzopen($sourcePath, 'rb') : fopen($sourcePath, 'rb');
        if ($in === false) {
            proc_close($process);
            throw new \RuntimeException('Failed to open backup source file: ' . $sourcePath);
        }

        while (!($gzip ? gzeof($in) : feof($in))) {
            $chunk = $gzip ? gzread($in, 8192) : fread($in, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            fwrite($pipes[0], $chunk);
        }

        $gzip ? gzclose($in) : fclose($in);
        fclose($pipes[0]);

        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException('mysql restore failed with exit code ' . $exitCode . ': ' . $errorOutput);
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/MysqldumpRunnerFeatureTest.php`
Expected: `OK (1 test, ...)`. Report the actual dump file size observed.

- [ ] **Step 5: Run `phpcs`**

Run: `ddev composer phpcs`
Expected: no new violations in `src/Services/MysqldumpRunner.php`.

- [ ] **Step 6: Commit**

```bash
git add src/Services/MysqldumpRunner.php tests/Feature/MysqldumpRunnerFeatureTest.php
git commit -m "feat: add MysqldumpRunner using proc_open against mysqldump/mysql CLI"
```

---

## Task 8: Wire `BackupService`/`MysqldumpRunner` into the container

**Files:**
- Modify: `src/Settings.php`
- Modify: `src/Dependencies.php`
- Modify: `.env.example`
- Modify: `.gitignore`
- Test: `tests/Feature/BackupDependencyWiringFeatureTest.php`

**Interfaces:**
- Consumes: `BackupService`, `DumpRunnerInterface`, `MysqldumpRunner` (Tasks 6-7).
- Produces: `settings['backup']` array (`dir`, `max_manual`, `max_auto`, `gzip`, `app_version`); container bindings for `DumpRunnerInterface::class`, `BackupService::class`. Consumed by `BackupController`/`Routes.php` (Task 9), `CreateBackupCommand`/`bin/create_backup.php` (Task 10), `DevSeedService` (Task 11).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackupDependencyWiringFeatureTest.php` (mirrors the existing string-assertion pattern used in `BudgetFeatureTest` for `Settings.php`/`Dependencies.php` wiring checks):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class BackupDependencyWiringFeatureTest extends TestCase
{
    public function testSettingsExposeBackupConfiguration(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Settings.php');

        $this->assertIsString($content);
        $this->assertStringContainsString("'backup' =>", $content);
        $this->assertStringContainsString('BACKUP_DIR', $content);
        $this->assertStringContainsString('BACKUP_MAX_MANUAL', $content);
        $this->assertStringContainsString('BACKUP_MAX_AUTO', $content);
    }

    public function testDependenciesWireBackupServiceAndDumpRunner(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Dependencies.php');

        $this->assertIsString($content);
        $this->assertStringContainsString('DumpRunnerInterface::class', $content);
        $this->assertStringContainsString('MysqldumpRunner', $content);
        $this->assertStringContainsString('BackupService::class', $content);
        $this->assertStringContainsString('BackupController::class', $content);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/BackupDependencyWiringFeatureTest.php`
Expected: FAIL — assertions on missing strings.

- [ ] **Step 3: Add the `backup` settings bundle**

In `src/Settings.php`, change:

```php
            'modules' => [
                'sheet_archive' => EnvHelper::read('FEATURE_SHEET_ARCHIVE', 'false') === 'true',
                'budget'        => EnvHelper::read('FEATURE_BUDGET', 'false') === 'true',
            ],
        ],
    ]);
};
```

to:

```php
            'modules' => [
                'sheet_archive' => EnvHelper::read('FEATURE_SHEET_ARCHIVE', 'false') === 'true',
                'budget'        => EnvHelper::read('FEATURE_BUDGET', 'false') === 'true',
            ],
            'backup' => [
                'dir' => EnvHelper::read('BACKUP_DIR', __DIR__ . '/../var/backups'),
                'max_manual' => (int) EnvHelper::read('BACKUP_MAX_MANUAL', '5'),
                'max_auto' => (int) EnvHelper::read('BACKUP_MAX_AUTO', '7'),
                'gzip' => EnvHelper::readBool('BACKUP_GZIP', true),
                'app_version' => EnvHelper::read('APP_VERSION', 'dev'),
            ],
        ],
    ]);
};
```

- [ ] **Step 4: Add container bindings**

In `src/Dependencies.php`, change the imports:

```php
use App\Controllers\BudgetController;
use App\Commands\ProcessMailQueueCommand;
```

to:

```php
use App\Controllers\BudgetController;
use App\Controllers\BackupController;
use App\Commands\ProcessMailQueueCommand;
use App\Commands\CreateBackupCommand;
use App\Services\BackupService;
use App\Services\DumpRunnerInterface;
use App\Services\MysqldumpRunner;
use App\Util\EnvHelper;
```

Then change:

```php
        BudgetService::class => \DI\autowire(),
        BudgetController::class => \DI\autowire(),
```

to:

```php
        BudgetService::class => \DI\autowire(),
        BudgetController::class => \DI\autowire(),
        DumpRunnerInterface::class => function () {
            return new MysqldumpRunner(
                EnvHelper::read('DB_HOST', 'db'),
                EnvHelper::read('DB_PORT', '3306'),
                EnvHelper::read('DB_DATABASE', 'db'),
                EnvHelper::read('DB_USERNAME', 'db'),
                EnvHelper::read('DB_PASSWORD', 'db')
            );
        },
        BackupService::class => function (ContainerInterface $c) {
            $backupSettings = $c->get('settings')['backup'];

            return new BackupService(
                $c->get(DumpRunnerInterface::class),
                $c->get(LoggerInterface::class),
                $backupSettings['dir'],
                $backupSettings['max_manual'],
                $backupSettings['max_auto'],
                $backupSettings['gzip'],
                EnvHelper::read('DB_DATABASE', 'db'),
                $backupSettings['app_version']
            );
        },
        BackupController::class => \DI\autowire(),
        CreateBackupCommand::class => \DI\autowire(),
```

- [ ] **Step 5: Add `.env.example` documentation**

In `.env.example`, after the `FEATURE_SHEET_ARCHIVE`/`FEATURE_BUDGET` block at the end of the file, add:

```
# =========================================
# Backup-Verwaltung
# =========================================
# Verzeichnis fuer DB-Backups (ausserhalb des Webroots)
BACKUP_DIR=/var/backups/chormanager

# Maximale Anzahl manueller Backups (0 = unbegrenzt)
BACKUP_MAX_MANUAL=5

# Maximale Anzahl automatischer Backups (0 = unbegrenzt)
BACKUP_MAX_AUTO=7

# Backup-Dateien gzip-komprimieren
BACKUP_GZIP=true
```

- [ ] **Step 6: Ignore the local backup directory**

In `.gitignore`, add a new line:

```
var/
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/BackupDependencyWiringFeatureTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 8: Commit**

```bash
git add src/Settings.php src/Dependencies.php .env.example .gitignore tests/Feature/BackupDependencyWiringFeatureTest.php
git commit -m "feat: wire BackupService and MysqldumpRunner into the DI container"
```

---

## Task 9: `BackupController` + routes + templates + nav link

**Files:**
- Create: `src/Controllers/BackupController.php`
- Modify: `src/Routes.php`
- Create: `templates/backups/index.twig`
- Create: `public/js/backups.js`
- Modify: `templates/partials/navigation/areas.twig`
- Test: `tests/Feature/BackupControllerHttpTest.php`

**Interfaces:**
- Consumes: `BackupService` (Task 6, wired in Task 8), `RoleMiddleware(requiresBackupManagement:)` (Task 3).
- Produces: routes `GET/POST /backups`, `POST /backups/{id}/restore`, `POST /backups/{id}/delete`, `GET /backups/{id}/download`, all gated by `can_manage_backups`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackupControllerHttpTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\BackupController;
use App\Models\AppSetting;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

final class BackupControllerHttpTest extends TestCase
{
    use TestHttpHelpers;

    private string $backupDir;
    private BackupController $controller;
    private BackupService $backupService;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->backupDir = sys_get_temp_dir() . '/chormanager_backup_http_test_' . bin2hex(random_bytes(4));
        $this->backupService = new BackupService(
            new FakeDumpRunner(),
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version'
        );

        $twig = $this->createMock(Twig::class);
        $this->controller = new BackupController($twig, $this->backupService, new NullLogger());

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
        AppSetting::query()->where('setting_key', 'session_valid_after')->delete();

        parent::tearDown();
    }

    public function testStoreCreatesManualBackupAndRedirectsWithSuccessFlash(): void
    {
        $_SESSION['user_id'] = 1;

        $request = $this->makeRequest('POST', '/backups');
        $response = $this->controller->store($request, $this->makeResponse());

        $this->assertRedirect($response, '/backups');
        $this->assertSame('Backup erfolgreich erstellt.', $_SESSION['success']);
        $this->assertCount(1, $this->backupService->list());
    }

    public function testStoreSetsErrorFlashWhenManualLimitReached(): void
    {
        $_SESSION['user_id'] = 1;
        for ($i = 0; $i < 5; $i++) {
            $this->backupService->create(BackupService::TYPE_MANUAL, 1);
        }

        $request = $this->makeRequest('POST', '/backups');
        $response = $this->controller->store($request, $this->makeResponse());

        $this->assertRedirect($response, '/backups');
        $this->assertStringContainsString('Maximale Anzahl', $_SESSION['error']);
    }

    public function testRestoreClearsSessionAndRedirectsToLogin(): void
    {
        $metadata = $this->backupService->create(BackupService::TYPE_MANUAL, 1);
        $_SESSION['user_id'] = 1;

        $request = $this->makeRequest('POST', '/backups/' . $metadata['id'] . '/restore');
        $response = $this->controller->restore($request, $this->makeResponse(), ['id' => $metadata['id']]);

        $this->assertRedirect($response, '/login');
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testDeleteRemovesBackupAndRedirectsWithSuccessFlash(): void
    {
        $metadata = $this->backupService->create(BackupService::TYPE_MANUAL, 1);

        $request = $this->makeRequest('POST', '/backups/' . $metadata['id'] . '/delete');
        $response = $this->controller->delete($request, $this->makeResponse(), ['id' => $metadata['id']]);

        $this->assertRedirect($response, '/backups');
        $this->assertSame('Backup gelöscht.', $_SESSION['success']);
        $this->assertCount(0, $this->backupService->list());
    }

    public function testDownloadStreamsExistingBackupFile(): void
    {
        $metadata = $this->backupService->create(BackupService::TYPE_MANUAL, 1);

        $request = $this->makeRequest('GET', '/backups/' . $metadata['id'] . '/download');
        $response = $this->controller->download($request, $this->makeResponse(), ['id' => $metadata['id']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/gzip', $response->getHeaderLine('Content-Type'));
    }

    public function testDownloadReturnsNotFoundForUnknownId(): void
    {
        $request = $this->makeRequest('GET', '/backups/does-not-exist/download');
        $response = $this->controller->download($request, $this->makeResponse(), ['id' => 'does-not-exist']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRoutesRegisterBackupEndpointsBehindBackupPermission(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/backups'", $routesContent);
        $this->assertStringContainsString('requiresBackupManagement', $routesContent);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/BackupControllerHttpTest.php`
Expected: FAIL — `Class "App\Controllers\BackupController" not found`.

- [ ] **Step 3: Implement `BackupController`**

Create `src/Controllers/BackupController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\BackupLimitReachedException;
use App\Services\BackupService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response as SlimResponse;
use Slim\Psr7\Stream;
use Slim\Views\Twig;

class BackupController
{
    public function __construct(
        private readonly Twig $view,
        private readonly BackupService $backupService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $backups = $this->backupService->list();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'backups/index.twig', [
            'backups' => $backups,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        try {
            $this->backupService->create(BackupService::TYPE_MANUAL, $userId);
            $_SESSION['success'] = 'Backup erfolgreich erstellt.';
        } catch (BackupLimitReachedException $exception) {
            $_SESSION['error'] = $exception->getMessage();
        } catch (\Throwable $exception) {
            $this->logger->error('Manual backup creation failed.', [
                'event' => 'backup.create.failed',
                'user_id' => $userId,
                'exception' => $exception,
            ]);
            $_SESSION['error'] = 'Backup konnte nicht erstellt werden.';
        }

        return $response->withHeader('Location', '/backups')->withStatus(302);
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        $id = (string) $args['id'];
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        try {
            $this->backupService->restore($id);
        } catch (\Throwable $exception) {
            $this->logger->error('Backup restore failed.', [
                'event' => 'backup.restore.failed',
                'id' => $id,
                'user_id' => $userId,
                'exception' => $exception,
            ]);
            $_SESSION['error'] = 'Wiederherstellung fehlgeschlagen.';

            return $response->withHeader('Location', '/backups')->withStatus(302);
        }

        $this->logger->info('Backup restored, ending own session.', [
            'event' => 'backup.restore.session_cleared',
            'id' => $id,
            'user_id' => $userId,
        ]);

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $redirectResponse = new SlimResponse();
        return $redirectResponse->withHeader('Location', '/login')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (string) $args['id'];

        try {
            $this->backupService->delete($id);
            $_SESSION['success'] = 'Backup gelöscht.';
        } catch (\Throwable $exception) {
            $this->logger->error('Backup delete failed.', [
                'event' => 'backup.delete.failed',
                'id' => $id,
                'exception' => $exception,
            ]);
            $_SESSION['error'] = 'Backup konnte nicht gelöscht werden.';
        }

        return $response->withHeader('Location', '/backups')->withStatus(302);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $id = (string) $args['id'];

        try {
            $file = $this->backupService->getFile($id);
        } catch (\Throwable $exception) {
            $response->getBody()->write('Backup nicht gefunden.');
            return $response->withStatus(404);
        }

        $stream = fopen($file['path'], 'rb');
        $body = new Stream($stream);

        return $response
            ->withBody($body)
            ->withHeader('Content-Type', 'application/gzip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('Content-Length', (string) $file['size']);
    }
}
```

- [ ] **Step 4: Register the routes**

In `src/Routes.php`, add the import after `use App\Controllers\BudgetController;`:

```php
use App\Controllers\BudgetController;
use App\Controllers\BackupController;
```

Then, right after the Mail Queue Management route group (immediately before the `// Dev-only seed endpoint` comment), insert:

```php
            // Backup management
            $group->group(
                '',
                function (RouteCollectorProxy $backupGroup) {
                    $backupGroup->get('/backups', [BackupController::class, 'index']);
                    $backupGroup->post('/backups', [BackupController::class, 'store']);
                    $backupGroup->post('/backups/{id:[A-Za-z0-9_]+}/restore', [BackupController::class, 'restore']);
                    $backupGroup->post('/backups/{id:[A-Za-z0-9_]+}/delete', [BackupController::class, 'delete']);
                    $backupGroup->get('/backups/{id:[A-Za-z0-9_]+}/download', [BackupController::class, 'download']);
                }
            )->add(new RoleMiddleware(requiresBackupManagement: true));

```

So it reads:

```php
            )->add(
                new RoleMiddleware(false, 0, false, false, false, false, false, false, false, false, false, true, false)
            );

            // Backup management
            $group->group(
                '',
                function (RouteCollectorProxy $backupGroup) {
                    $backupGroup->get('/backups', [BackupController::class, 'index']);
                    $backupGroup->post('/backups', [BackupController::class, 'store']);
                    $backupGroup->post('/backups/{id:[A-Za-z0-9_]+}/restore', [BackupController::class, 'restore']);
                    $backupGroup->post('/backups/{id:[A-Za-z0-9_]+}/delete', [BackupController::class, 'delete']);
                    $backupGroup->get('/backups/{id:[A-Za-z0-9_]+}/download', [BackupController::class, 'download']);
                }
            )->add(new RoleMiddleware(requiresBackupManagement: true));

            // Dev-only seed endpoint, still protected by admin permission.
            $group->post('/dev/seed', [DevSeedController::class, 'run'])
                ->add(new RoleMiddleware(true));
```

- [ ] **Step 5: Create the backups template**

Create `templates/backups/index.twig`:

```twig
{% extends "layout.twig" %}

{% block title %}
    Backup-Verwaltung - {{ app_settings.app_name|default('Chor-Manager') }}
{% endblock title %}

{% block page_header %}
    <section class="page-header">
        <div>
            <p class="text-uppercase text-muted small mb-1">Verwaltung</p>
            <h1 class="h2 mb-1">Backup-Verwaltung</h1>
            <p class="text-muted mb-0">Datenbank-Backups erstellen, herunterladen und wiederherstellen.</p>
        </div>
        <div class="page-actions">
            <form method="post" action="/backups">
                <input type="hidden" name="_csrf" value="{{ csrf_token }}">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Backup erstellen
                </button>
            </form>
        </div>
    </section>
{% endblock page_header %}

{% block content %}

    {% if success %}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ success }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {% endif %}
    {% if error %}
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ error }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {% endif %}

    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        Eine Wiederherstellung überschreibt die gesamte Datenbank und beendet alle aktiven Sitzungen, auch Ihre eigene.
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Typ</th>
                    <th scope="col">Erstellt am</th>
                    <th scope="col">Größe</th>
                    <th scope="col">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                {% for backup in backups %}
                    <tr>
                        <td data-label="Typ">
                            {% if backup.type == "manual" %}
                                <span class="badge bg-primary">Manuell</span>
                            {% else %}
                                <span class="badge bg-secondary">Automatisch</span>
                            {% endif %}
                        </td>
                        <td data-label="Erstellt am">{{ backup.created_at }}</td>
                        <td data-label="Größe">{{ (backup.size / 1024 / 1024)|round(2) }} MB</td>
                        <td data-label="Aktionen">
                            <div class="btn-group" role="group" aria-label="Backup-Aktionen">
                                <a class="btn btn-sm btn-outline-secondary" href="/backups/{{ backup.id }}/download">
                                    <i class="bi bi-download"></i> Download
                                </a>
                                <button type="button"
                                        class="btn btn-sm btn-outline-warning restore-backup-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#restoreBackupModal"
                                        data-id="{{ backup.id }}">
                                    <i class="bi bi-arrow-counterclockwise"></i> Wiederherstellen
                                </button>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger delete-backup-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteBackupModal"
                                        data-id="{{ backup.id }}">
                                    <i class="bi bi-trash"></i> Löschen
                                </button>
                            </div>
                        </td>
                    </tr>
                {% else %}
                    <tr>
                        <td colspan="4" class="text-center py-4 text-muted">Keine Backups vorhanden.</td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>

    <div class="modal fade" id="restoreBackupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" id="restoreBackupForm">
                    <input type="hidden" name="_csrf" value="{{ csrf_token }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Backup wiederherstellen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Diese Aktion überschreibt die gesamte Datenbank mit dem Inhalt dieses Backups und beendet <strong>alle</strong> aktiven Sitzungen, einschließlich Ihrer eigenen. Diese Aktion kann nicht widerrufen werden.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-warning">Wiederherstellen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteBackupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" id="deleteBackupForm">
                    <input type="hidden" name="_csrf" value="{{ csrf_token }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Backup löschen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Soll dieses Backup endgültig gelöscht werden?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Löschen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

{% endblock content %}

{% block scripts %}
    <script src="/js/backups.js"></script>
{% endblock scripts %}
```

- [ ] **Step 6: Create the modal-wiring JS**

Create `public/js/backups.js`:

```js
document.addEventListener('DOMContentLoaded', function () {
    const restoreButtons = document.querySelectorAll('.restore-backup-btn');
    const restoreForm = document.getElementById('restoreBackupForm');

    restoreButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            restoreForm.action = '/backups/' + this.getAttribute('data-id') + '/restore';
        });
    });

    const deleteButtons = document.querySelectorAll('.delete-backup-btn');
    const deleteForm = document.getElementById('deleteBackupForm');

    deleteButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            deleteForm.action = '/backups/' + this.getAttribute('data-id') + '/delete';
        });
    });
});
```

- [ ] **Step 7: Add the navigation link**

In `templates/partials/navigation/areas.twig`, change:

```twig
            {% if session.can_manage_song_library %}
                <li>
                    <a class="dropdown-item {% if nav_active(path, nav, ['/song-library'], ['song_library']) %}active{% endif %}"
                       href="/song-library"><i class="bi bi-music-note-list me-2"></i> Repertoire</a>
                </li>
            {% endif %}
```

to:

```twig
            {% if session.can_manage_song_library %}
                <li>
                    <a class="dropdown-item {% if nav_active(path, nav, ['/song-library'], ['song_library']) %}active{% endif %}"
                       href="/song-library"><i class="bi bi-music-note-list me-2"></i> Repertoire</a>
                </li>
            {% endif %}
            {% if session.can_manage_backups %}
                <li>
                    <a class="dropdown-item {% if nav_active(path, nav, ['/backups'], ['backups']) %}active{% endif %}"
                       href="/backups"><i class="bi bi-database-down me-2"></i> Backup-Verwaltung</a>
                </li>
            {% endif %}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/BackupControllerHttpTest.php`
Expected: `OK (7 tests, ...)`.

- [ ] **Step 9: Run `phpcs` and `twigcs`**

Run: `ddev composer phpcs` and `ddev composer twigcs`
Expected: no new violations in `src/Controllers/BackupController.php`, `src/Routes.php`, `templates/backups/index.twig`, `templates/partials/navigation/areas.twig`. Fix with `ddev composer phpcbf` / `ddev composer twigcbf` if needed.

- [ ] **Step 10: Commit**

```bash
git add src/Controllers/BackupController.php src/Routes.php templates/backups/index.twig public/js/backups.js templates/partials/navigation/areas.twig tests/Feature/BackupControllerHttpTest.php
git commit -m "feat: add BackupController, routes, UI and nav link for Backup-Verwaltung"
```

---

## Task 10: `CreateBackupCommand` CLI + `bin/create_backup.php` entrypoint

**Files:**
- Create: `src/Commands/CreateBackupCommand.php`
- Create: `bin/create_backup.php`
- Test: `tests/Feature/CreateBackupCommandFeatureTest.php`

**Interfaces:**
- Consumes: `BackupService` (Task 6, wired in Task 8).
- Produces: CLI command `backup:create [--type=auto|manual]` (default `auto`), runnable via `php bin/create_backup.php` for cron/manual CLI use, per the spec's "externer Cron ruft CLI" decision.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CreateBackupCommandFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CreateBackupCommand;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

final class CreateBackupCommandFeatureTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/chormanager_backup_cli_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }

        parent::tearDown();
    }

    private function makeTester(BackupService $service): CommandTester
    {
        $command = new CreateBackupCommand($service, new NullLogger());
        $application = new Application('Test');
        $application->add($command);

        return new CommandTester($command);
    }

    private function makeService(): BackupService
    {
        return new BackupService(
            new FakeDumpRunner(),
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version'
        );
    }

    public function testCreatesAutoBackupByDefault(): void
    {
        $service = $this->makeService();
        $tester = $this->makeTester($service);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $entries = $service->list();
        $this->assertCount(1, $entries);
        $this->assertSame('auto', $entries[0]['type']);
    }

    public function testCreatesManualBackupWhenTypeOptionGiven(): void
    {
        $service = $this->makeService();
        $tester = $this->makeTester($service);

        $exitCode = $tester->execute(['--type' => 'manual']);

        $this->assertSame(0, $exitCode);
        $entries = $service->list();
        $this->assertSame('manual', $entries[0]['type']);
    }

    public function testRejectsInvalidType(): void
    {
        $service = $this->makeService();
        $tester = $this->makeTester($service);

        $exitCode = $tester->execute(['--type' => 'bogus']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertCount(0, $service->list());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/CreateBackupCommandFeatureTest.php`
Expected: FAIL — `Class "App\Commands\CreateBackupCommand" not found`.

- [ ] **Step 3: Implement `CreateBackupCommand`**

Create `src/Commands/CreateBackupCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\BackupService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateBackupCommand extends Command
{
    protected static string $defaultName = 'backup:create';

    public function __construct(
        private readonly BackupService $backupService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('backup:create');
        $this->setDescription('Create a database backup (manual or automatic).');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Backup type: auto or manual', 'auto');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getOption('type');

        if (!in_array($type, [BackupService::TYPE_MANUAL, BackupService::TYPE_AUTO], true)) {
            $output->writeln('<error>Invalid --type. Allowed values: auto, manual.</error>');
            return Command::INVALID;
        }

        try {
            $metadata = $this->backupService->create($type, null);
            $output->writeln(sprintf('<info>Backup created: %s</info>', $metadata['id']));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->logger->error('CLI backup creation failed.', [
                'event' => 'backup.create.failed',
                'type' => $type,
                'exception' => $exception,
            ]);
            $output->writeln('<error>Backup creation failed: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/CreateBackupCommandFeatureTest.php`
Expected: `OK (3 tests, ...)`.

- [ ] **Step 5: Create the CLI entrypoint**

Create `bin/create_backup.php` (mirrors `bin/process_mail_queue.php`):

```php
<?php

declare(strict_types=1);

use App\Commands\CreateBackupCommand;
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

$application = new Application('ChorManager Backup');
$application->addCommand($container->get(CreateBackupCommand::class));
$application->setDefaultCommand('backup:create', true);

$application->run();
```

- [ ] **Step 6: Manually verify the entrypoint resolves through the real container**

Run: `ddev exec php bin/create_backup.php --type=manual`
Expected: `Backup created: backup_manual_...` printed, and a corresponding `.sql.gz` + `.json` pair appears under the configured `BACKUP_DIR` (default `var/backups/` at the project root if `BACKUP_DIR` is unset in `.env`). Report the created backup id and file size. Then delete this manual verification artifact so it doesn't linger as a stray backup:

Run: `ddev exec rm -f var/backups/backup_manual_*`

- [ ] **Step 7: Run `phpcs`**

Run: `ddev composer phpcs`
Expected: no new violations in `src/Commands/CreateBackupCommand.php`.

- [ ] **Step 8: Commit**

```bash
git add src/Commands/CreateBackupCommand.php bin/create_backup.php tests/Feature/CreateBackupCommandFeatureTest.php
git commit -m "feat: add backup:create CLI command and bin/create_backup.php entrypoint"
```

---

## Task 11: Dev seed coverage

**Files:**
- Modify: `src/Services/DevSeedService.php`
- Test: `tests/Feature/DevSeedBackupCoverageFeatureTest.php`

**Interfaces:**
- Consumes: `BackupService` (Task 6, wired in Task 8), `Role::can_manage_backups` (Task 1).
- Produces: Admin role seeded with `can_manage_backups = 1`; one example manual backup seeded; `report['counts']['backups']`.

Mandatory seed checklist per `instructions/seed.md`: add the permission to the Admin role definition, add a `backups` counter to the report, add the `BackupService` import/constructor wiring, add a dedicated `seedBackups()` method, wire it into `run()` after the transaction (it shells out to `mysqldump`, so it must not run inside the DB transaction), clean up stray backup files on reset, and execute + inspect a real dev seed run.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DevSeedBackupCoverageFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class DevSeedBackupCoverageFeatureTest extends TestCase
{
    public function testDevSeedServiceSeedsBackupPermissionAndExampleBackup(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertIsString($content);
        $this->assertStringContainsString("'can_manage_backups' => 1,", $content);
        $this->assertStringContainsString("'backups' => 0,", $content);
        $this->assertStringContainsString('function seedBackups', $content);
        $this->assertStringContainsString('BackupService', $content);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/DevSeedBackupCoverageFeatureTest.php`
Expected: FAIL — missing strings.

- [ ] **Step 3: Inject `BackupService` and add the report counter**

In `src/Services/DevSeedService.php`, add the import after the existing `use App\Models\Role;` line:

```php
use App\Models\Role;
use App\Services\BackupService;
```

Add a constructor (none currently exists):

```php
class DevSeedService
{
    private const MODE_APPEND = 'append';
    private const MODE_RESET = 'reset-and-seed';
    private const DEFAULT_SEED_PASSWORD = 'seed';
    private const ACTIVE_USER_TARGET = 80;
```

to:

```php
class DevSeedService
{
    private const MODE_APPEND = 'append';
    private const MODE_RESET = 'reset-and-seed';
    private const DEFAULT_SEED_PASSWORD = 'seed';
    private const ACTIVE_USER_TARGET = 80;

    public function __construct(private readonly BackupService $backupService)
    {
    }
```

Add the `backups` counter, changing:

```php
                'newsletter_archive' => 0,
                'mail_queue' => 0,
            ],
        ];
```

to:

```php
                'newsletter_archive' => 0,
                'mail_queue' => 0,
                'backups' => 0,
            ],
        ];
```

- [ ] **Step 4: Seed the Admin role permission**

In the `seedRoles()` Admin role definition, change:

```php
                'can_manage_mail_queue' => 1,
                'can_manage_tasks' => 1,
                'can_manage_sheet_archive' => 1,
            ],
```

to:

```php
                'can_manage_mail_queue' => 1,
                'can_manage_tasks' => 1,
                'can_manage_sheet_archive' => 1,
                'can_manage_backups' => 1,
            ],
```

- [ ] **Step 5: Clean up stray backups on reset**

At the end of `resetSeedData()`, change:

```php
        foreach ($tables as $table) {
            $connection->table($table)->truncate();
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }
```

to:

```php
        foreach ($tables as $table) {
            $connection->table($table)->truncate();
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS=1');

        foreach ($this->backupService->list() as $backup) {
            $this->backupService->delete($backup['id']);
        }
    }
```

- [ ] **Step 6: Add the `seedBackups()` method**

Add this private method (placed near the other `seed*` methods, e.g. after `seedAppSettings()`):

```php
    private function seedBackups(): void
    {
        $this->backupService->create(BackupService::TYPE_MANUAL, null);
        $this->report['counts']['backups']++;
    }
```

- [ ] **Step 7: Call it after the transaction**

Change:

```php
            $this->seedAuthData($users['all']);
            $this->seedAppSettings();
        });

        $this->report['duration_seconds'] = round(microtime(true) - $startedAt, 3);
```

to:

```php
            $this->seedAuthData($users['all']);
            $this->seedAppSettings();
        });

        $this->seedBackups();

        $this->report['duration_seconds'] = round(microtime(true) - $startedAt, 3);
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/DevSeedBackupCoverageFeatureTest.php`
Expected: `OK (1 test, ...)`.

- [ ] **Step 9: Run a real dev seed and inspect the report**

Run: `ddev exec php bin/dev_seed.php --mode=reset-and-seed`
Expected: exit code 0. Inspect the logged JSON report (stderr) and confirm `counts.backups` is `1` and `counts.roles`/other existing counters are still populated as before. Report the actual `counts` object observed.

- [ ] **Step 10: Run `phpcs`**

Run: `ddev composer phpcs`
Expected: no new violations in `src/Services/DevSeedService.php`.

- [ ] **Step 11: Run the full test suite**

Run: `ddev exec php vendor/bin/phpunit`
Expected: `OK` — every test added across Tasks 1-11 passes, and no pre-existing test regressed.

- [ ] **Step 12: Commit**

```bash
git add src/Services/DevSeedService.php tests/Feature/DevSeedBackupCoverageFeatureTest.php
git commit -m "feat: seed can_manage_backups permission and example backup in dev seed"
```

---

## Manual verification checklist (after all tasks)

These steps exercise the feature through the actual UI/browser, beyond what the automated tests cover:

1. Log in as Admin (seeded by Task 11), open the new "Backup-Verwaltung" nav entry, confirm the page loads and shows the one seeded manual backup.
2. Click "Backup erstellen", confirm a second backup appears with today's date and a plausible size.
3. Click "Download" on a backup, confirm a `.sql.gz` file downloads.
4. Click "Löschen" on a backup, confirm the modal appears, confirm, and confirm the row disappears.
5. In a second browser/incognito window, log in as a non-admin user without `can_manage_backups`; confirm `/backups` returns 403 and the nav entry is absent.
6. As Admin, click "Wiederherstellen" on a backup, confirm the modal warning text, confirm, and verify you are redirected to `/login` with the session ended. Log back in and confirm the app still works (data matches the restored backup's point in time).
7. Run `ddev exec php bin/create_backup.php` (no `--type`, defaults to `auto`) and confirm a new automatic backup shows up in the list with type "Automatisch".


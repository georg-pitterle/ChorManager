# Navigation-Encapsulation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das Navigationsmenü in einen testbaren PHP-`NavigationBuilder` kapseln, Parent-Sichtbarkeit strukturell aus den Kindern ableiten, und die Magic Number `role_level >= 40` durch ein erstklassiges Rechte-Flag `can_manage_own_voice_group` ersetzen.

**Architecture:** Ein `NavigationContext` (readonly Value Object) kapselt Permissions + Modul-Flags + Pfad. Ein `NavigationBuilder` erzeugt daraus einen fertigen Nav-Baum (nur sichtbare Knoten, Gruppen sichtbar ⇔ ≥1 sichtbares Kind, Active-State vorberechnet). Twig rendert den Baum logikfrei über ein einziges `menu.twig`. Ein neues Bool-Recht `can_manage_own_voice_group` ersetzt den Levelvergleich in Session, Nav und drei Nicht-Nav-Aufrufstellen.

**Tech Stack:** PHP 8.5, Slim 4 + PHP-DI, Eloquent (Capsule), Twig, Phinx, PHPUnit 13, Bootstrap 5.

**Spec:** `docs/superpowers/specs/2026-07-20-navigation-encapsulation-design.md`

**Branch:** `refactor/navigation-encapsulation` (bereits ausgecheckt; niemals `git push`).

## Global Constraints

- Alle Projekt-Kommandos via DDEV: `ddev exec vendor/bin/phpunit --filter <Name>`, `ddev exec ./vendor/bin/phinx migrate`, `ddev composer phpcs`, `ddev composer twigcs`.
- PHP: PSR-12, 4 Spaces, Zeilenlänge soft 120 / hard 130, `declare(strict_types=1);`. Nach PHP-Änderungen `ddev composer phpcs` (fix: `ddev composer phpcbf`).
- Twig: doppelte Anführungszeichen, keine Leerzeichen um `=` bei benannten Argument-Defaults, 1 Leerzeichen um `and`/`or`/`not`, keine mehrzeiligen Boolean-Ausdrücke (Sub-Bedingungen in `{% set %}`), Zeilenlänge soft 120 / hard 130. Nach Template-Änderungen `ddev composer twigcs`.
- Kein Inline-JS/CSS in Templates. Keine externen CDNs.
- UI-Texte deutsch mit echten Umlauten (ä/ö/ü/ß, niemals ae/oe/ue/ss).
- Logging via `Psr\Log\LoggerInterface`, kein `error_log()` in `src/`.
- Neue/geänderte Textdateien mit LF-Zeilenenden; nach Datei-Schreiboperationen auf Windows normalisieren:
  `$f = "<absolute-path>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
  (Repo-Hook prüft LF beim Commit — Commit schlägt sonst fehl.)
- Commits enden mit `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Niemals `git push`. Niemals `git stash` / `git stash pop` (Repo hat fremde Stashes).
- TDD: Test zuerst schreiben, fehlschlagen lassen, dann implementieren.
- Bekannte, nicht zu diesem Branch gehörende Flakes: 2 Fehler in `MailDeliveryLifecycleFeatureTest` (verwaiste Dev-DB-Zeilen) — nicht untersuchen, nur bestätigen dass keine NEUEN Fehler dazukommen.

---

### Task 1: Recht `can_manage_own_voice_group` — Migration, Modell, Session, Seed

**Files:**
- Create: `db/migrations/20260720120000_add_can_manage_own_voice_group_to_roles.php`
- Modify: `src/Models/Role.php` (fillable)
- Modify: `src/Services/SessionAuthService.php` (Flag in Session)
- Modify: `src/Services/DevSeedService.php` (`seedRoles()` — Flag je Rolle)
- Test: `tests/Feature/OwnVoiceGroupPermissionFeatureTest.php`

**Interfaces:**
- Produces: `$_SESSION['can_manage_own_voice_group']` (bool). Neue `roles`-Spalte `can_manage_own_voice_group` tinyint(1) default 0, backfilled für `hierarchy_level >= 40`.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/OwnVoiceGroupPermissionFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class OwnVoiceGroupPermissionFeatureTest extends TestCase
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

    public function testMigrationAddsColumnWithBackfill(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__) . '/../db/migrations/20260720120000_add_can_manage_own_voice_group_to_roles.php'
        );
        $this->assertIsString($migration);
        $this->assertStringContainsString('can_manage_own_voice_group', $migration);
        $this->assertStringContainsString('hierarchy_level >= 40', $migration);
    }

    public function testRoleColumnExistsAndIsFillable(): void
    {
        $role = Role::create([
            'name' => 'VG Rep Test ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 45,
            'can_manage_own_voice_group' => 1,
        ]);

        $fresh = Role::find($role->id);
        $this->assertSame(1, (int) $fresh->can_manage_own_voice_group);

        $role->delete();
    }

    public function testSessionReceivesFlagFromRole(): void
    {
        $role = Role::create([
            'name' => 'VG Rep Session ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_own_voice_group' => 1,
        ]);
        $user = User::create([
            'first_name' => 'Vera',
            'last_name' => 'Tretung',
            'email' => 'vera.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);

        (new SessionAuthService())->setAuthenticatedUser($user);

        $this->assertTrue($_SESSION['can_manage_own_voice_group']);

        $user->roles()->detach();
        $user->delete();
        $role->delete();
    }

    public function testSessionFlagFalseForPlainMember(): void
    {
        $role = Role::create([
            'name' => 'Plain Member ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 0,
            'can_manage_own_voice_group' => 0,
        ]);
        $user = User::create([
            'first_name' => 'Mit',
            'last_name' => 'Glied',
            'email' => 'mit.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);

        (new SessionAuthService())->setAuthenticatedUser($user);

        $this->assertFalse($_SESSION['can_manage_own_voice_group']);

        $user->roles()->detach();
        $user->delete();
        $role->delete();
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter OwnVoiceGroupPermissionFeatureTest`
Expected: FAIL (Migration + Spalte fehlen).

- [ ] **Step 3: Migration schreiben**

`db/migrations/20260720120000_add_can_manage_own_voice_group_to_roles.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCanManageOwnVoiceGroupToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles ADD COLUMN can_manage_own_voice_group TINYINT(1) NOT NULL DEFAULT 0"
            . " AFTER can_manage_backups;"
        );
        $this->execute(
            "UPDATE roles SET can_manage_own_voice_group = 1 WHERE hierarchy_level >= 40;"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_own_voice_group;");
    }
}
```

- [ ] **Step 4: Migration ausführen**

Run: `ddev exec ./vendor/bin/phinx migrate`
Expected: `AddCanManageOwnVoiceGroupToRoles: migrated`. Fehler melden, nicht verschlucken.

- [ ] **Step 5: Modell + Session + Seed anpassen**

`src/Models/Role.php` — in `$fillable` nach `'can_manage_backups',`:

```php
        'can_manage_backups',
        'can_manage_own_voice_group',
```

`src/Services/SessionAuthService.php` — neue lokale Variable neben den anderen (nach `$canManageBackups = false;`):

```php
        $canManageBackups = false;
        $canManageOwnVoiceGroup = false;
```

In der `foreach ($user->roles ...)`-Schleife nach dem `can_manage_backups`-Block:

```php
            if (($role->can_manage_backups ?? false)) {
                $canManageBackups = true;
            }
            if (($role->can_manage_own_voice_group ?? false)) {
                $canManageOwnVoiceGroup = true;
            }
```

Nach `$_SESSION['can_manage_backups'] = $canManageBackups;`:

```php
        $_SESSION['can_manage_backups'] = $canManageBackups;
        $_SESSION['can_manage_own_voice_group'] = $canManageOwnVoiceGroup;
```

`src/Services/DevSeedService.php` — in `seedRoles()` jeder Rollen-Definition das Flag hinzufügen, Wert = (`hierarchy_level >= 40` ? 1 : 0), um exakt den Migrations-Backfill zu spiegeln:
- Admin (100), Vorstand (80), Kassier (60), Chorleitung (80), Stimmvertretung (50), Ersatzvertretung (40): `'can_manage_own_voice_group' => 1,`
- Mitglied (0): `'can_manage_own_voice_group' => 0,`

Beispiel (Stimmvertretung, nach `'can_manage_sheet_archive' => 0,`):

```php
                'can_manage_sheet_archive' => 0,
                'can_manage_own_voice_group' => 1,
```

- [ ] **Step 6: Test ausführen — muss bestehen**

Run: `ddev exec vendor/bin/phpunit --filter "OwnVoiceGroupPermissionFeatureTest|AuthFeatureTest"`
Expected: PASS. (AuthFeatureTest prüft Session-Flag-Setzen und darf nicht brechen.)

- [ ] **Step 7: Seed-Lauf + phpcs + Commit**

```bash
ddev exec php bin/dev_seed.php
ddev composer phpcs
git add db/migrations/20260720120000_add_can_manage_own_voice_group_to_roles.php src/Models/Role.php src/Services/SessionAuthService.php src/Services/DevSeedService.php tests/Feature/OwnVoiceGroupPermissionFeatureTest.php
git commit -m "feat: Recht can_manage_own_voice_group (Migration, Session, Seed)"
```

Seed-Lauf bestätigt, dass die Rollen-Seed-Definitionen mit der neuen Spalte durchlaufen.

---

### Task 2: Rollen-Admin-UI für `can_manage_own_voice_group`

**Files:**
- Modify: `src/Controllers/RoleController.php` (`buildPermissionFlags`, `create`, `update`)
- Modify: `templates/roles/index.twig` (Create-Modal + Edit-Modal Checkbox + Matrix data-Attribut)
- Modify: `public/js/roles.js` (Edit-Modal-Befüllung)
- Test: `tests/Feature/RoleOwnVoiceGroupUiFeatureTest.php`

**Interfaces:**
- Consumes: Spalte aus Task 1.
- Produces: Formularfeld `can_manage_own_voice_group` (Checkbox) im Rollen-Create/Edit; `buildPermissionFlags()` liefert den Schlüssel.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/RoleOwnVoiceGroupUiFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use PHPUnit\Framework\TestCase;

class RoleOwnVoiceGroupUiFeatureTest extends TestCase
{
    public function testBuildPermissionFlagsIncludesOwnVoiceGroup(): void
    {
        $flags = RoleController::buildPermissionFlags(['can_manage_own_voice_group' => '1']);
        $this->assertSame(1, $flags['can_manage_own_voice_group']);

        $flagsOff = RoleController::buildPermissionFlags([]);
        $this->assertSame(0, $flagsOff['can_manage_own_voice_group']);
    }

    public function testRolesTemplateOffersOwnVoiceGroupCheckboxInBothModals(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('id="can_manage_own_voice_group"', $template);
        $this->assertStringContainsString('id="edit_can_manage_own_voice_group"', $template);
        $this->assertStringContainsString('name="can_manage_own_voice_group"', $template);
        $this->assertStringContainsString('data-own-voice-group="', $template);
    }

    public function testRolesJsPopulatesOwnVoiceGroupOnEdit(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/../public/js/roles.js');
        $this->assertIsString($js);
        $this->assertStringContainsString("data-own-voice-group", $js);
        $this->assertStringContainsString('edit_can_manage_own_voice_group', $js);
    }

    public function testControllerPersistsOwnVoiceGroupFlag(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/RoleController.php');
        $this->assertIsString($controller);
        $this->assertSame(
            3,
            substr_count($controller, 'can_manage_own_voice_group'),
            'Erwartet: buildPermissionFlags + create-Array + update-Array.'
        );
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter RoleOwnVoiceGroupUiFeatureTest`
Expected: FAIL.

- [ ] **Step 3: RoleController erweitern**

In `buildPermissionFlags()` (`src/Controllers/RoleController.php`) nach `'can_manage_backups' => ...,`:

```php
            'can_manage_backups' => isset($data['can_manage_backups']) ? 1 : 0,
            'can_manage_own_voice_group' => isset($data['can_manage_own_voice_group']) ? 1 : 0,
```

In `create()` und `update()` jeweils im `Role::create([...])`/`$role->update([...])`-Array nach `'can_manage_backups' => $permissions['can_manage_backups']`:

```php
                'can_manage_backups' => $permissions['can_manage_backups'],
                'can_manage_own_voice_group' => $permissions['can_manage_own_voice_group']
```

(Im `create()` steht danach `]);` — Komma-Konsistenz beachten: in `create()` ist `can_manage_backups` die letzte Zeile ohne Komma; verschiebe das Komma so, dass `can_manage_own_voice_group` die neue letzte Zeile ohne Komma ist. Gleiches in `update()`.)

- [ ] **Step 4: Template + JS erweitern**

`templates/roles/index.twig` — im Create-Modal nach dem Backup-Block (nach Zeile ~583, dem schließenden `</div>` des `can_manage_backups`-Blocks):

```twig
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input"
                               type="checkbox"
                               role="switch"
                               id="can_manage_own_voice_group"
                               name="can_manage_own_voice_group"
                               value="1">
                        <label class="form-check-label fw-bold text-primary"
                               for="can_manage_own_voice_group">Eigene Stimmgruppe verwalten</label>
                        <div class="form-text">
                            Wenn aktiv, darf diese Person Anwesenheit und Anmeldungen ihrer eigenen Stimmgruppe
                            verwalten (Stimmvertretung), unabhängig vom Hierarchie-Level.
                        </div>
                    </div>
```

Im Edit-Modal analog nach dem `edit_can_manage_backups`-Block (nach Zeile ~840):

```twig
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input"
                               type="checkbox"
                               role="switch"
                               id="edit_can_manage_own_voice_group"
                               name="can_manage_own_voice_group"
                               value="1">
                        <label class="form-check-label fw-bold text-primary"
                               for="edit_can_manage_own_voice_group">Eigene Stimmgruppe verwalten</label>
                        <div class="form-text">
                            Wenn aktiv, darf diese Person Anwesenheit und Anmeldungen ihrer eigenen Stimmgruppe
                            verwalten (Stimmvertretung), unabhängig vom Hierarchie-Level.
                        </div>
                    </div>
```

In der Matrix am Edit-Button (die Zeile mit `data-backups="{{ role.can_manage_backups ? '1' : '0' }}">`, ~Zeile 321) das `>` ans Ende verschieben und ein Attribut ergänzen:

```twig
                                                            data-backups="{{ role.can_manage_backups ? '1' : '0' }}"
                                                            data-own-voice-group="{{ role.can_manage_own_voice_group ? '1' : '0' }}">
```

`public/js/roles.js` — nach der `edit_can_manage_backups`-Zeile (Zeile 63):

```javascript
                setCheckedIfPresent('edit_can_manage_backups', this.getAttribute('data-backups') === '1');
                setCheckedIfPresent('edit_can_manage_own_voice_group', this.getAttribute('data-own-voice-group') === '1');
```

- [ ] **Step 5: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "RoleOwnVoiceGroupUiFeatureTest|RoleFeatureTest"`
Expected: PASS.

- [ ] **Step 6: phpcs, twigcs + Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Controllers/RoleController.php templates/roles/index.twig public/js/roles.js tests/Feature/RoleOwnVoiceGroupUiFeatureTest.php
git commit -m "feat: Rollen-Admin-Checkbox fuer can_manage_own_voice_group"
```

---

### Task 3: Nicht-Nav-Aufrufstellen von `>= 40` auf das Flag migrieren

**Files:**
- Modify: `src/Services/AttendanceScopeService.php` (`canManageOthers`)
- Modify: `src/Middleware/RoleMiddleware.php` (`allowVoiceGroupReps`-Block)
- Modify: `src/Controllers/UserController.php` (`canDeactivateTargetUser`, ~Zeile 618)
- Test: `tests/Feature/OwnVoiceGroupCallSitesFeatureTest.php`

**Interfaces:**
- Consumes: `$_SESSION['can_manage_own_voice_group']` (Task 1).
- Produces: Capability-Checks unabhängig von `role_level`.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/OwnVoiceGroupCallSitesFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AttendanceScopeService;
use PHPUnit\Framework\TestCase;

class OwnVoiceGroupCallSitesFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCanManageOthersUsesFlagNotLevel(): void
    {
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['can_manage_own_voice_group'] = true;

        $this->assertTrue((new AttendanceScopeService())->canManageOthers());
    }

    public function testCanManageOthersFalseWithoutFlagOrAdmin(): void
    {
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 30;
        $_SESSION['can_manage_own_voice_group'] = false;

        $this->assertFalse((new AttendanceScopeService())->canManageOthers());
    }

    public function testAdminStillManagesOthers(): void
    {
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_manage_own_voice_group'] = false;

        $this->assertTrue((new AttendanceScopeService())->canManageOthers());
    }

    public function testCallSitesReferenceFlagNotMagicNumber(): void
    {
        $scope = file_get_contents(dirname(__DIR__) . '/../src/Services/AttendanceScopeService.php');
        $middleware = file_get_contents(dirname(__DIR__) . '/../src/Middleware/RoleMiddleware.php');
        $userCtrl = file_get_contents(dirname(__DIR__) . '/../src/Controllers/UserController.php');

        $this->assertStringContainsString('can_manage_own_voice_group', $scope);
        $this->assertStringNotContainsString('>= 40', $scope);
        $this->assertStringContainsString('can_manage_own_voice_group', $middleware);
        $this->assertStringNotContainsString('< 40', $middleware);
        $this->assertStringContainsString('can_manage_own_voice_group', $userCtrl);
        $this->assertStringNotContainsString('$userLevel >= 40', $userCtrl);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter OwnVoiceGroupCallSitesFeatureTest`
Expected: FAIL.

- [ ] **Step 3: Aufrufstellen migrieren**

`src/Services/AttendanceScopeService.php` — `canManageOthers()`:

```php
    public function canManageOthers(): bool
    {
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $canManageOwnVoiceGroup = (bool) ($_SESSION['can_manage_own_voice_group'] ?? false);

        return $canManageUsers || $canManageOwnVoiceGroup;
    }
```

WICHTIG: `getManageableUserIds()` in derselben Datei nutzt weiterhin `role_level < 80` für den Admin-Bypass — das ist Hierarchie-Logik, NICHT die 40er-Capability, und bleibt unverändert. Nur `canManageOthers()` migriert.

`src/Middleware/RoleMiddleware.php` — im `process()`, wo die Session-Flags gelesen werden (nach `$userLevel = $_SESSION['role_level'] ?? 0;`), ergänzen:

```php
        $canManageOwnVoiceGroup = $_SESSION['can_manage_own_voice_group'] ?? false;
```

Im `allowVoiceGroupReps`-Block (die Bedingung `if (!$canManageUsers && $userLevel < 40)`):

```php
        if ($this->allowVoiceGroupReps) {
            // Must have global manage OR the own-voice-group capability
            if (!$canManageUsers && !$canManageOwnVoiceGroup) {
                $response = new SlimResponse();
                $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung für diese Aktion.");
                return $response->withStatus(403);
            }
        } else {
```

`src/Controllers/UserController.php` — `canDeactivateTargetUser()`, die letzte Zeile (`return $userLevel >= 40 && $isInMyGroup;`) und die davorstehende `$userLevel`-Zeile:

```php
        $canManageOwnVoiceGroup = (bool) ($_SESSION['can_manage_own_voice_group'] ?? false);
        $myVgs = $_SESSION['voice_group_ids'] ?? [];
        $targetVgIds = $targetUser->voiceGroups->pluck('id')->toArray();
        $isInMyGroup = !empty(array_intersect($myVgs, $targetVgIds));

        return $canManageOwnVoiceGroup && $isInMyGroup;
```

(Die Zeile `$userLevel = (int) ($_SESSION['role_level'] ?? 0);` in dieser Methode entfällt, falls `$userLevel` dort sonst nicht mehr gebraucht wird — per Blick prüfen; wird `$userLevel` weiter unten in der Methode nicht verwendet, entfernen.)

- [ ] **Step 4: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "OwnVoiceGroupCallSitesFeatureTest|AttendanceScopeServiceFeatureTest|RoleMiddlewareFeatureTest|UserManagementFeatureTest|UserHierarchyProtectionFeatureTest"`
Expected: PASS. Falls ein bestehender Test einen Voice-Rep über `role_level=50` ohne gesetztes `can_manage_own_voice_group`-Session-Flag konstruiert, im Test zusätzlich `$_SESSION['can_manage_own_voice_group'] = true;` setzen (das Flag ist jetzt die Capability-Quelle). Solche Anpassungen mit committen.

- [ ] **Step 5: phpcs + Commit**

```bash
ddev composer phpcs
git add src/Services/AttendanceScopeService.php src/Middleware/RoleMiddleware.php src/Controllers/UserController.php tests/Feature/OwnVoiceGroupCallSitesFeatureTest.php
git commit -m "refactor: Voice-Rep-Capability-Checks auf can_manage_own_voice_group umstellen"
```

---

### Task 4: NavigationContext + NavigationBuilder (reine PHP-Logik)

**Files:**
- Create: `src/Navigation/NavigationContext.php`
- Create: `src/Navigation/NavigationBuilder.php`
- Test: `tests/Feature/NavigationBuilderFeatureTest.php`

**Interfaces:**
- Produces:
  - `App\Navigation\NavigationContext` — readonly; Konstruktor `(array $permissions, array $modules, string $path, string $navKey = '')`; Factory `NavigationContext::fromSession(array $session, array $settings, string $path, string $navKey = ''): self`; Methoden `can(string $flag): bool`, `module(string $name): bool`.
  - `App\Navigation\NavigationBuilder::build(NavigationContext $ctx): array` — Liste von Top-Level-Knoten. Link-Knoten: `['type'=>'link','label'=>string,'url'=>string,'icon'=>string,'active'=>bool]`. Gruppen-Knoten: `['type'=>'group','label'=>string,'icon'=>string,'active'=>bool,'items'=>array]`, wobei jedes Item `['label','url','icon','active','divider_before'=>bool]` ist. Nur sichtbare Knoten/Items; Gruppen mit 0 sichtbaren Items fehlen.
- Consumers: Task 5 (Twig-Wiring + Rendering).

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/NavigationBuilderFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use PHPUnit\Framework\TestCase;

class NavigationBuilderFeatureTest extends TestCase
{
    /**
     * @param array<string,bool> $permissions
     * @param array<string,bool> $modules
     */
    private function build(array $permissions, array $modules = [], string $path = '/dashboard'): array
    {
        $ctx = new NavigationContext($permissions, $modules, $path);
        return (new NavigationBuilder())->build($ctx);
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     */
    private function group(array $tree, string $label): ?array
    {
        foreach ($tree as $node) {
            if ($node['type'] === 'group' && $node['label'] === $label) {
                return $node;
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     */
    private function urls(array $tree): array
    {
        $urls = [];
        foreach ($tree as $node) {
            if ($node['type'] === 'link') {
                $urls[] = $node['url'];
            } else {
                foreach ($node['items'] as $item) {
                    $urls[] = $item['url'];
                }
            }
        }
        return $urls;
    }

    public function testPlainMemberSeesOnlyPublicItems(): void
    {
        $tree = $this->build([], ['registration' => false]);
        $urls = $this->urls($tree);

        $this->assertContains('/dashboard', $urls);
        $this->assertContains('/events', $urls);
        $this->assertContains('/downloads', $urls);
        $this->assertContains('/evaluations/project-members', $urls);

        $this->assertNotContains('/attendance', $urls);
        $this->assertNotContains('/registrations', $urls);
        $this->assertNotContains('/users', $urls);
        $this->assertNotContains('/roles', $urls);
        $this->assertNotContains('/backups', $urls);

        // Keine leeren Admin-Gruppen im Baum.
        $this->assertNull($this->group($tree, 'Verwaltung'));
    }

    public function testRegistrationModuleTogglesRegistrationLinks(): void
    {
        $on = $this->urls($this->build([], ['registration' => true]));
        $this->assertContains('/registrations', $on);
        $this->assertContains('/evaluations/registrations', $on);

        $off = $this->urls($this->build([], ['registration' => false]));
        $this->assertNotContains('/registrations', $off);
        $this->assertNotContains('/evaluations/registrations', $off);
    }

    public function testVoiceRepSeesScopedItems(): void
    {
        $urls = $this->urls($this->build([
            'can_manage_own_voice_group' => true,
        ]));

        $this->assertContains('/attendance', $urls);
        $this->assertContains('/users', $urls);
        $this->assertContains('/evaluations', $urls);
    }

    public function testBackupOnlyRoleSeesVerwaltungWithBackupItem(): void
    {
        $tree = $this->build(['can_manage_backups' => true]);
        $verwaltung = $this->group($tree, 'Verwaltung');

        $this->assertNotNull($verwaltung, 'Verwaltung-Gruppe muss fuer Backup-Recht erscheinen.');
        $itemUrls = array_column($verwaltung['items'], 'url');
        $this->assertContains('/backups', $itemUrls);
        $this->assertNotContains('/roles', $itemUrls);
    }

    public function testAdminSeesFullStructure(): void
    {
        $tree = $this->build([
            'can_manage_users' => true,
            'can_manage_master_data' => true,
            'can_manage_mail_queue' => true,
            'can_manage_backups' => true,
        ], [
            'finance' => true,
            'newsletter' => true,
        ]);
        $urls = $this->urls($tree);

        foreach (['/users', '/roles', '/voice-groups', '/settings', '/admin/mail-queue', '/backups'] as $u) {
            $this->assertContains($u, $urls, "Admin muss {$u} sehen.");
        }
        $this->assertNotNull($this->group($tree, 'Verwaltung'));
    }

    public function testActiveStatePropagatesToGroup(): void
    {
        $tree = $this->build(['can_manage_users' => true], ['registration' => true], '/registrations');
        $termine = $this->group($tree, 'Termine');

        $this->assertNotNull($termine);
        $this->assertTrue($termine['active'], 'Gruppe Termine muss aktiv sein bei /registrations.');

        $anmeldung = null;
        foreach ($termine['items'] as $item) {
            if ($item['url'] === '/registrations') {
                $anmeldung = $item;
            }
        }
        $this->assertNotNull($anmeldung);
        $this->assertTrue($anmeldung['active']);
    }

    public function testDividerOnlyBetweenVisibleAdminSections(): void
    {
        // Nur Backup-Recht: Verwaltung hat genau ein sichtbares Item, kein fuehrender Divider.
        $tree = $this->build(['can_manage_backups' => true]);
        $verwaltung = $this->group($tree, 'Verwaltung');
        $this->assertFalse($verwaltung['items'][0]['divider_before']);
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter NavigationBuilderFeatureTest`
Expected: FAIL (Klassen fehlen).

- [ ] **Step 3: NavigationContext schreiben**

`src/Navigation/NavigationContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Navigation;

/**
 * Immutable snapshot of everything that decides nav visibility and active state.
 * Keeps $_SESSION and $settings out of the builder so it is testable in isolation.
 */
final class NavigationContext
{
    /**
     * @param array<string,bool> $permissions can_* capability flags
     * @param array<string,bool> $modules      settings.modules.* toggles
     */
    public function __construct(
        public readonly array $permissions,
        public readonly array $modules,
        public readonly string $path,
        public readonly string $navKey = ''
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $settings
     */
    public static function fromSession(
        array $session,
        array $settings,
        string $path,
        string $navKey = ''
    ): self {
        $flags = [
            'can_manage_users',
            'can_manage_attendance',
            'can_manage_project_members',
            'can_read_finances',
            'can_manage_finances',
            'can_manage_master_data',
            'can_manage_sponsoring',
            'can_manage_song_library',
            'can_manage_newsletters',
            'can_manage_mail_queue',
            'can_manage_budget',
            'can_manage_backups',
            'can_manage_own_voice_group',
        ];

        $permissions = [];
        foreach ($flags as $flag) {
            $permissions[$flag] = (bool) ($session[$flag] ?? false);
        }

        $modules = [];
        foreach ((array) ($settings['modules'] ?? []) as $name => $enabled) {
            $modules[(string) $name] = (bool) $enabled;
        }

        return new self($permissions, $modules, $path, $navKey);
    }

    public function can(string $flag): bool
    {
        return (bool) ($this->permissions[$flag] ?? false);
    }

    public function module(string $name): bool
    {
        return (bool) ($this->modules[$name] ?? false);
    }
}
```

- [ ] **Step 4: NavigationBuilder schreiben**

`src/Navigation/NavigationBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Navigation;

/**
 * Builds the top navigation as a plain tree of visible nodes.
 * Group visibility is derived automatically: a group appears iff at least one
 * of its child links is visible. Active state is precomputed from the context
 * path / nav key. Twig renders the resulting tree without any logic.
 */
final class NavigationBuilder
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function build(NavigationContext $ctx): array
    {
        $tree = [];

        foreach ($this->definition() as $node) {
            if ($node['kind'] === 'link') {
                if (!($node['visible'])($ctx)) {
                    continue;
                }
                $tree[] = [
                    'type' => 'link',
                    'label' => $node['label'],
                    'url' => $node['url'],
                    'icon' => $node['icon'],
                    'active' => $this->matchesActive($node, $ctx),
                ];
                continue;
            }

            $items = [];
            $previousSection = null;
            $groupActive = false;

            foreach ($node['children'] as $child) {
                if (!($child['visible'])($ctx)) {
                    continue;
                }

                $active = $this->matchesActive($child, $ctx);
                $groupActive = $groupActive || $active;

                $section = $child['section'] ?? null;
                $dividerBefore = $items !== [] && $section !== null && $section !== $previousSection;
                $previousSection = $section;

                $items[] = [
                    'label' => $child['label'],
                    'url' => $child['url'],
                    'icon' => $child['icon'],
                    'active' => $active,
                    'divider_before' => $dividerBefore,
                ];
            }

            if ($items === []) {
                continue;
            }

            $tree[] = [
                'type' => 'group',
                'label' => $node['label'],
                'icon' => $node['icon'],
                'active' => $groupActive,
                'items' => $items,
            ];
        }

        return $tree;
    }

    private function matchesActive(array $node, NavigationContext $ctx): bool
    {
        foreach (($node['excl'] ?? []) as $exclude) {
            if ($exclude !== '' && str_starts_with($ctx->path, $exclude)) {
                return false;
            }
        }

        $navKeys = $node['navKeys'] ?? [];
        if ($ctx->navKey !== '' && in_array($ctx->navKey, $navKeys, true)) {
            return true;
        }

        foreach (($node['prefixes'] ?? []) as $prefix) {
            if ($prefix === '/') {
                if ($ctx->path === '/') {
                    return true;
                }
                continue;
            }
            if ($prefix !== '' && str_starts_with($ctx->path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single declarative menu definition. Each item carries its label,
     * icon, url, a visibility predicate, and active-matching metadata.
     *
     * @return array<int,array<string,mixed>>
     */
    private function definition(): array
    {
        $always = static fn(NavigationContext $c): bool => true;

        return [
            [
                'kind' => 'link',
                'label' => 'Dashboard',
                'url' => '/dashboard',
                'icon' => 'bi-house',
                'prefixes' => ['/', '/dashboard'],
                'navKeys' => ['dashboard'],
                'visible' => $always,
            ],
            [
                'kind' => 'group',
                'label' => 'Termine',
                'icon' => 'bi-calendar-event',
                'children' => [
                    [
                        'label' => 'Termine',
                        'url' => '/events',
                        'icon' => 'bi-calendar-event',
                        'prefixes' => ['/events'],
                        'navKeys' => ['events'],
                        'visible' => $always,
                    ],
                    [
                        'label' => 'Anwesenheit',
                        'url' => '/attendance',
                        'icon' => 'bi-person-check',
                        'prefixes' => ['/attendance'],
                        'navKeys' => ['attendance'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_attendance') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Anmeldungen',
                        'url' => '/registrations',
                        'icon' => 'bi-calendar-check',
                        'prefixes' => ['/registrations'],
                        'navKeys' => ['registrations'],
                        'visible' => static fn(NavigationContext $c): bool => $c->module('registration'),
                    ],
                ],
            ],
            [
                'kind' => 'group',
                'label' => 'Bereiche',
                'icon' => 'bi-grid-3x3-gap-fill',
                'children' => [
                    [
                        'label' => 'Mitgliederverwaltung',
                        'url' => '/users',
                        'icon' => 'bi-people-fill',
                        'prefixes' => ['/users'],
                        'navKeys' => ['users'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_users') || $c->can('can_manage_own_voice_group'),
                    ],
                    [
                        'label' => 'Meine Projekte',
                        'url' => '/projects/members',
                        'icon' => 'bi-person-check',
                        'prefixes' => ['/projects/members'],
                        'navKeys' => ['project_members'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_project_members') && !$c->can('can_manage_master_data'),
                    ],
                    [
                        'label' => 'Kassa',
                        'url' => '/finances',
                        'icon' => 'bi-bank',
                        'prefixes' => ['/finances'],
                        'navKeys' => ['finances'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->module('finance')
                            && ($c->can('can_read_finances') || $c->can('can_manage_finances')
                                || $c->can('can_manage_users')),
                    ],
                    [
                        'label' => 'Budget',
                        'url' => '/budget',
                        'icon' => 'bi-calculator',
                        'prefixes' => ['/budget'],
                        'navKeys' => ['budget'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->module('budget')
                            && ($c->can('can_read_finances') || $c->can('can_manage_finances')
                                || $c->can('can_manage_users') || $c->can('can_manage_budget')),
                    ],
                    [
                        'label' => 'Sponsoring',
                        'url' => '/sponsoring',
                        'icon' => 'bi-briefcase',
                        'prefixes' => ['/sponsoring'],
                        'navKeys' => ['sponsoring'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->module('sponsoring') && $c->can('can_manage_sponsoring'),
                    ],
                    [
                        'label' => 'Repertoire',
                        'url' => '/song-library',
                        'icon' => 'bi-music-note-list',
                        'prefixes' => ['/song-library'],
                        'navKeys' => ['song_library'],
                        'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_song_library'),
                    ],
                    [
                        'label' => 'Downloads',
                        'url' => '/downloads',
                        'icon' => 'bi-download',
                        'prefixes' => ['/downloads'],
                        'navKeys' => ['downloads'],
                        'visible' => $always,
                    ],
                    [
                        'label' => 'Meine Newsletter',
                        'url' => '/newsletters/archive',
                        'icon' => 'bi-envelope',
                        'prefixes' => ['/newsletters/archive'],
                        'navKeys' => ['newsletters_archive'],
                        'visible' => static fn(NavigationContext $c): bool => $c->module('newsletter'),
                    ],
                    [
                        'label' => 'Newsletter',
                        'url' => '/newsletters',
                        'icon' => 'bi-envelope-open',
                        'prefixes' => ['/newsletters'],
                        'navKeys' => ['newsletters'],
                        'excl' => ['/newsletters/archive'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->module('newsletter') && $c->can('can_manage_newsletters'),
                    ],
                ],
            ],
            [
                'kind' => 'group',
                'label' => 'Auswertungen',
                'icon' => 'bi-bar-chart-fill',
                'children' => [
                    [
                        'label' => 'Anwesenheitsquoten',
                        'url' => '/evaluations',
                        'icon' => 'bi-bar-chart-line-fill',
                        'prefixes' => ['/evaluations'],
                        'navKeys' => ['evaluations'],
                        'excl' => ['/evaluations/project-members', '/evaluations/registrations'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_users') || $c->can('can_manage_own_voice_group'),
                    ],
                    [
                        'label' => 'Projektmitglieder',
                        'url' => '/evaluations/project-members',
                        'icon' => 'bi-people-fill',
                        'prefixes' => ['/evaluations/project-members'],
                        'navKeys' => ['evaluations_project_members'],
                        'visible' => $always,
                    ],
                    [
                        'label' => 'Anmeldungen',
                        'url' => '/evaluations/registrations',
                        'icon' => 'bi-calendar-check',
                        'prefixes' => ['/evaluations/registrations'],
                        'navKeys' => ['evaluations_registrations'],
                        'visible' => static fn(NavigationContext $c): bool => $c->module('registration'),
                    ],
                ],
            ],
            [
                'kind' => 'group',
                'label' => 'Verwaltung',
                'icon' => 'bi-gear-fill',
                'children' => [
                    [
                        'label' => 'Projekte',
                        'url' => '/projects',
                        'icon' => 'bi-folder-fill',
                        'prefixes' => ['/projects'],
                        'navKeys' => ['projects'],
                        'excl' => ['/projects/members'],
                        'section' => 'core',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_master_data') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Rollen',
                        'url' => '/roles',
                        'icon' => 'bi-shield-lock-fill',
                        'prefixes' => ['/roles'],
                        'navKeys' => ['roles'],
                        'section' => 'core',
                        'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Stimmgruppen',
                        'url' => '/voice-groups',
                        'icon' => 'bi-music-note-beamed',
                        'prefixes' => ['/voice-groups'],
                        'navKeys' => ['voice_groups'],
                        'section' => 'core',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_master_data') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Termin-Typen',
                        'url' => '/event-types',
                        'icon' => 'bi-tag',
                        'prefixes' => ['/event-types'],
                        'navKeys' => ['event_types'],
                        'section' => 'core',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_master_data') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'App-Einstellungen',
                        'url' => '/settings',
                        'icon' => 'bi-sliders',
                        'prefixes' => ['/settings'],
                        'navKeys' => ['settings'],
                        'section' => 'settings',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_master_data') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Mailversand',
                        'url' => '/admin/mail-queue',
                        'icon' => 'bi-envelope',
                        'prefixes' => ['/admin/mail-queue', '/mail-queue'],
                        'navKeys' => ['mail_queue'],
                        'section' => 'mailqueue',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_mail_queue') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Backup-Verwaltung',
                        'url' => '/backups',
                        'icon' => 'bi-database-down',
                        'prefixes' => ['/backups'],
                        'navKeys' => ['backups'],
                        'section' => 'backups',
                        'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_backups'),
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 5: Test ausführen — muss bestehen**

Run: `ddev exec vendor/bin/phpunit --filter NavigationBuilderFeatureTest`
Expected: PASS (alle Tests).

- [ ] **Step 6: phpcs + Commit**

```bash
ddev composer phpcs
git add src/Navigation/NavigationContext.php src/Navigation/NavigationBuilder.php tests/Feature/NavigationBuilderFeatureTest.php
git commit -m "feat: NavigationContext und NavigationBuilder als gekapselte Nav-Logik"
```

---

### Task 5: Twig-Wiring, `menu.twig`, layout-Umbau, alten Müll entfernen

**Files:**
- Create: `templates/partials/navigation/menu.twig`
- Modify: `src/Dependencies.php` (Twig-Funktion `navigation`, ggf. `nav_active` entfernen)
- Modify: `templates/layout.twig` (Gate-/Active-Sets + Includes → ein Include)
- Delete: `templates/partials/navigation/events.twig`, `areas.twig`, `admin.twig`, `evaluations.twig`, `dashboard.twig`
- Modify: `tests/Feature/AttendanceFeatureTest.php` (Nav-Grep umziehen), `tests/Feature/NavigationVisibilityFeatureTest.php` (auf Builder/gerendertes Menü umziehen)
- Test: `tests/Feature/NavigationMenuRenderFeatureTest.php`

**Interfaces:**
- Consumes: `NavigationBuilder`, `NavigationContext` (Task 4).
- Produces: Twig-Funktion `navigation(activeNav): array` (liefert den Baum); `menu.twig` rendert ihn.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/NavigationMenuRenderFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class NavigationMenuRenderFeatureTest extends TestCase
{
    private function render(array $permissions, array $modules, string $path): string
    {
        $tree = (new NavigationBuilder())->build(
            new NavigationContext($permissions, $modules, $path)
        );

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));

        return $twig->render('partials/navigation/menu.twig', ['navigation' => $tree]);
    }

    public function testPlainMemberMenuHtmlHasPublicLinksOnly(): void
    {
        $html = $this->render([], ['registration' => true], '/dashboard');

        $this->assertStringContainsString('href="/registrations"', $html);
        $this->assertStringContainsString('href="/downloads"', $html);
        $this->assertStringContainsString('href="/evaluations/project-members"', $html);
        $this->assertStringNotContainsString('href="/roles"', $html);
        $this->assertStringNotContainsString('href="/backups"', $html);
    }

    public function testActiveClassRenderedForCurrentPath(): void
    {
        $html = $this->render(['can_manage_users' => true], ['registration' => true], '/registrations');
        $this->assertMatchesRegularExpression('/class="dropdown-item active"[^>]*href="\/registrations"/', $html);
    }

    public function testWiringAndLayoutUseBuilder(): void
    {
        $deps = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');
        $this->assertIsString($deps);
        $this->assertStringContainsString("'navigation'", $deps);
        $this->assertStringContainsString('NavigationBuilder', $deps);

        $layout = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');
        $this->assertIsString($layout);
        $this->assertStringContainsString("include('partials/navigation/menu.twig'", $layout);
        $this->assertStringNotContainsString('can_show_events', $layout);
        $this->assertStringNotContainsString('can_show_admin', $layout);
    }

    public function testOldNavPartialsRemoved(): void
    {
        foreach (['events', 'areas', 'admin', 'evaluations', 'dashboard'] as $partial) {
            $this->assertFileDoesNotExist(
                dirname(__DIR__) . '/../templates/partials/navigation/' . $partial . '.twig'
            );
        }
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `ddev exec vendor/bin/phpunit --filter NavigationMenuRenderFeatureTest`
Expected: FAIL.

- [ ] **Step 3: `menu.twig` schreiben**

`templates/partials/navigation/menu.twig`:

```twig
{% for node in navigation %}
    {% if node.type == "link" %}
        <li class="nav-item">
            <a class="nav-link {% if node.active %}active{% endif %}"
               href="{{ node.url }}"><i class="bi {{ node.icon }} me-1"></i> {{ node.label }}</a>
        </li>
    {% else %}
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle {% if node.active %}active{% endif %}"
               href="#"
               data-bs-toggle="dropdown"
               aria-expanded="false"><i class="bi {{ node.icon }} me-1"></i> {{ node.label }}</a>
            <ul class="dropdown-menu">
                {% for item in node.items %}
                    {% if item.divider_before %}
                        <li><hr class="dropdown-divider"></li>
                    {% endif %}
                    <li>
                        <a class="dropdown-item {% if item.active %}active{% endif %}"
                           href="{{ item.url }}"><i class="bi {{ item.icon }} me-2"></i> {{ item.label }}</a>
                    </li>
                {% endfor %}
            </ul>
        </li>
    {% endif %}
{% endfor %}
```

- [ ] **Step 4: Twig-Funktion `navigation` in Dependencies wiren**

`src/Dependencies.php` — bei den `use`-Imports oben ergänzen:

```php
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
```

In der Twig-Factory, nach dem `nav_active`-`addFunction`-Block (vor `return $twig;`), neue Funktion registrieren:

```php
            $environment->addFunction(new TwigFunction(
                'navigation',
                static function (string $activeNav = "") use ($allSettings, $currentPath): array {
                    $context = NavigationContext::fromSession($_SESSION, $allSettings, $currentPath, $activeNav);

                    return (new NavigationBuilder())->build($context);
                }
            ));
```

- [ ] **Step 5: `layout.twig` umbauen**

`templates/layout.twig` — den gesamten Block Zeile 34–44 (die `{% set ... %}`-Zeilen für `path`, `nav`, alle `is_*_active` und alle `can_show_*`) ersetzen durch:

```twig
            {% set navigation = navigation(active_nav|default("")) %}
```

Und die fünf `include('partials/navigation/...')`-Aufrufe (Zeile 68–98, dashboard/events/areas/evaluations/admin) durch einen einzigen ersetzen:

```twig
                        <ul class="navbar-nav me-auto mb-2 mb-md-0">
                            {{ include('partials/navigation/menu.twig', { navigation: navigation }, false) }}
                        </ul>
```

Der `user_menu.twig`-Include (Zeile 100) bleibt unverändert.

- [ ] **Step 6: Alte Nav-Partials löschen**

```bash
git rm templates/partials/navigation/events.twig templates/partials/navigation/areas.twig templates/partials/navigation/admin.twig templates/partials/navigation/evaluations.twig templates/partials/navigation/dashboard.twig
```

- [ ] **Step 7: Gebrochene Nav-Grep-Tests umziehen**

`tests/Feature/AttendanceFeatureTest.php` — der Block, der `partials/navigation/events.twig` liest (die drei Assertions mit `$eventsNavTemplate`), zeigt auf eine gelöschte Datei. Ersetzen durch eine Assertion gegen die Builder-Definition:

```php
        $navBuilder = file_get_contents(dirname(__DIR__) . '/../src/Navigation/NavigationBuilder.php');
        $this->assertIsString($navBuilder);
        $this->assertStringContainsString("'url' => '/attendance'", $navBuilder);
        $this->assertStringContainsString("can_manage_attendance", $navBuilder);
```

(Die Assertions gegen `dashboard/index.twig` und `events/index.twig` in derselben Testmethode bleiben — das sind Seiteninhalte, keine Nav-Partials.)

`tests/Feature/NavigationVisibilityFeatureTest.php` — diese Datei testete das alte `layout.twig`-Gate-Verhalten über `DashboardController`-Render. Ersetzen durch Assertions gegen den gerenderten `menu.twig`-Baum (Muster wie `NavigationMenuRenderFeatureTest`): plain member sieht `/registrations` + `/downloads`, nicht `/roles`; Backup-only sieht `/backups`. Falls dadurch inhaltsgleich zu `NavigationMenuRenderFeatureTest`/`NavigationBuilderFeatureTest`, die Datei löschen (`git rm`) statt duplizieren — Entscheidung im Zuge der Umsetzung, im Report begründen.

- [ ] **Step 8: `nav_active` Twig-Funktion prüfen/entfernen**

Run: `grep -rn "nav_active" templates/`
Wenn kein Treffer mehr (alle Partials weg): die `nav_active`-`addFunction`-Registrierung in `src/Dependencies.php` (der Block ab `$environment->addFunction(new TwigFunction('nav_active', ...))`) entfernen. Wenn noch Treffer existieren, Funktion belassen und das im Report vermerken.

- [ ] **Step 9: Tests ausführen**

Run: `ddev exec vendor/bin/phpunit --filter "NavigationMenuRenderFeatureTest|NavigationBuilderFeatureTest|AttendanceFeatureTest|LayoutFeatureTest"`
Expected: PASS.

- [ ] **Step 10: Volle Suite + Lint + Commit**

```bash
ddev exec vendor/bin/phpunit
ddev composer phpcs
ddev composer twigcs
git add -A
git commit -m "refactor: Nav-Menue ueber NavigationBuilder rendern, alte Partials/Gates entfernt"
```

Volle Suite: nur die 2 bekannten `MailDeliveryLifecycleFeatureTest`-Flakes dürfen fehlschlagen, sonst grün.

---

### Task 6: Abschluss-Verifikation

**Files:** keine neuen — Gesamtprüfung.

- [ ] **Step 1: Volle Testsuite**

Run: `ddev composer test`
Expected: PASS bis auf die 2 bekannten Flakes. (Enthält LF-Check.)

- [ ] **Step 2: Linting**

Run: `ddev composer phpcs && ddev composer twigcs`
Expected: keine Fehler; sonst `ddev composer phpcbf` / `ddev composer twigcbf` und erneut.

- [ ] **Step 3: Migrations-Status**

Run: `ddev exec ./vendor/bin/phinx status`
Expected: alle Migrationen `up`, inkl. `AddCanManageOwnVoiceGroupToRoles`.

- [ ] **Step 4: Verwaiste Referenzen prüfen**

Run: `grep -rn "can_show_events\|can_show_admin\|can_show_areas\|can_show_evaluations\|role_level >= 40\|userLevel >= 40\|userLevel < 40\|nav_active" src/ templates/`
Expected: keine Treffer (Capability-Magic-Number vollständig ersetzt; Gate-Booleans + nav_active entfernt). Treffer in `docs/`/`tests/` sind ok. `hierarchy_level`-Vergleiche (Hierarchie-Schutz) dürfen bleiben.

- [ ] **Step 5: Ergebnis berichten**

Zusammenfassung an den Nutzer: geänderte Dateien, Kommandos, Testresultate, Migrationsstatus, entfernter Müll. Kein `git push`.

## Bewusste Nicht-Ziele (YAGNI)

- `hierarchy_level` bleibt Spalte + Session (Hierarchie-Schutz, ≥ 80 Admin-Implizitrechte).
- `user_menu.twig` unverändert.
- Übrige `can_manage_*`-Flags unverändert.
- Keine neue Sichtbarkeit für Routen, die nicht ohnehin erreichbar sind (siehe Spec 2a — nur Downloads/Projektmitglieder/Anmeldungen werden im Menü aufgedeckt, alle bereits per Route für alle Mitglieder erreichbar).

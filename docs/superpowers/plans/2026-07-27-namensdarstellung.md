# Konfigurierbare Namensdarstellung – Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In den Stammdaten wird global eingestellt, ob Personennamen als `Vorname Nachname` oder `Nachname, Vorname` erscheinen; die Einstellung steuert alle Anzeigen und Sortierungen, und in den Verwaltungslisten ist der Name für Berechtigte mit dem Bearbeiten-Modal verlinkt.

**Architecture:** Ein zentraler `NameFormatterService` liest den `app_settings`-Key `name_display_format` und liefert sowohl den formatierten Anzeigenamen als auch die Sortierspalten. Er wird per DI in Controller und Queries injiziert und als Twig-Filter `person_name` registriert. Die Bearbeitungsberechtigung pro Mitglied wandert aus `UserController::index()` in eine neue `UserEditPolicy`; ein Twig-Makro rendert den Namen wahlweise als Link auf `/users?edit={id}`.

**Tech Stack:** PHP 8, Slim 4, PHP-DI, Eloquent, Twig, Bootstrap 5, PHPUnit 10, Phinx (hier nicht benötigt).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-27-namensdarstellung-design.md`
- PHP: PSR-12, 4 Spaces, Zeilenlänge weich 120 / hart 130. Gate: `ddev composer phpcs`, Fix: `ddev composer phpcbf`.
- Twig: offizielle Twig-Coding-Standards. Immer doppelte Anführungszeichen, nie einfache. Named-Argument-Defaults ohne Leerzeichen: `member_id=null`. Binäre Operatoren (`and`, `or`, `not`) mit genau einem Leerzeichen auf beiden Seiten; mehrzeilige Boolean-Ausdrücke sind verboten – Teilbedingungen in `{% set %}`-Variablen auslagern. Gate: `ddev composer twigcs`, Fix: `ddev composer twigcbf`.
- Kein Inline-JavaScript und kein `style="..."` in Templates. Keine CDN-Assets.
- Alle Textdateien mit LF-Zeilenenden anlegen. Nach jedem Schreibvorgang unter Windows normalisieren:
  `$f = "<absolute-path>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
- Deutsche Texte mit echten Umlauten (`ä`, `ö`, `ü`, `ß`), keine Umschreibungen.
- Logging ausschließlich über `Psr\Log\LoggerInterface`, kein `error_log()`.
- Niemals `git push` ausführen. Nur lokal committen.
- Kommandos laufen über DDEV: `ddev exec ./vendor/bin/phpunit`, `ddev composer <script>`.
- Erlaubte Werte der Einstellung: `first_last` (Default) und `last_first`. Der `app_settings`-Key heißt `name_display_format`.
- Keine Phinx-Migration – `app_settings` ist eine Key-Value-Tabelle.

## Dateiübersicht

| Datei | Verantwortung |
| --- | --- |
| `src/Services/NameFormatterService.php` (neu) | Formatierung, Format-Normalisierung, Sortierspalten |
| `src/Policies/UserEditPolicy.php` (neu) | Darf Betrachter dieses Mitglied bearbeiten? |
| `templates/macros/person.twig` (neu) | Makro `member_link` für Name mit/ohne Bearbeiten-Link |
| `src/Dependencies.php` | DI-Registrierung, Twig-Filter `person_name`, Twig-Global `name_display_format` |
| `src/Controllers/AppSettingController.php` | Normalisierung und Speicherung der Einstellung |
| `templates/settings/index.twig` | Auswahlfeld in den Stammdaten |
| `src/Controllers/UserController.php` | Deep-Link `?edit=`, `can_edit`-Flag je Zeile |
| `src/Queries/UserQuery.php`, `src/Queries/ProjectQuery.php` | Sortierung nach Format |
| Diverse Controller und Templates | Anzeige über `person_name` statt Inline-Verkettung |
| `src/Services/DevSeedService.php` | Seed des neuen Einstellungswerts |
| `tests/Unit/...`, `tests/Feature/...` | Tests je Task |

---

### Task 1: NameFormatterService

**Files:**
- Create: `src/Services/NameFormatterService.php`
- Test: `tests/Unit/Services/NameFormatterServiceTest.php`

**Interfaces:**
- Consumes: nichts
- Produces:
  - `NameFormatterService::FORMAT_FIRST_LAST = 'first_last'`
  - `NameFormatterService::FORMAT_LAST_FIRST = 'last_first'`
  - `NameFormatterService::DEFAULT_FORMAT = 'first_last'`
  - `public static function normalizeFormat(?string $format): string`
  - `public function __construct(?string $format = null)`
  - `public function getFormat(): string`
  - `public function format(?string $firstName, ?string $lastName): string`
  - `public function formatPerson(mixed $person): string`
  - `public function orderColumns(): array` (Werte `['first_name','last_name']` oder `['last_name','first_name']`)

- [x] **Step 1: Failing Test schreiben**

Datei `tests/Unit/Services/NameFormatterServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NameFormatterService;
use PHPUnit\Framework\TestCase;

final class NameFormatterServiceTest extends TestCase
{
    public function testFormatsFirstNameFirstByDefault(): void
    {
        $service = new NameFormatterService();

        $this->assertSame('first_last', $service->getFormat());
        $this->assertSame('Anna Müller', $service->format('Anna', 'Müller'));
    }

    public function testFormatsLastNameFirstWhenConfigured(): void
    {
        $service = new NameFormatterService('last_first');

        $this->assertSame('Müller, Anna', $service->format('Anna', 'Müller'));
    }

    public function testFallsBackToDefaultForUnknownOrEmptyFormat(): void
    {
        $this->assertSame('first_last', NameFormatterService::normalizeFormat(null));
        $this->assertSame('first_last', NameFormatterService::normalizeFormat(''));
        $this->assertSame('first_last', NameFormatterService::normalizeFormat('kraut'));
        $this->assertSame('last_first', NameFormatterService::normalizeFormat(' LAST_FIRST '));
    }

    public function testHandlesMissingNameParts(): void
    {
        $service = new NameFormatterService('last_first');

        $this->assertSame('', $service->format(null, null));
        $this->assertSame('Anna', $service->format('Anna', ''));
        $this->assertSame('Müller', $service->format(null, 'Müller'));
    }

    public function testFormatsArraysAndObjects(): void
    {
        $service = new NameFormatterService('last_first');

        $this->assertSame(
            'Müller, Anna',
            $service->formatPerson(['first_name' => 'Anna', 'last_name' => 'Müller'])
        );
        $this->assertSame(
            'Müller, Anna',
            $service->formatPerson((object) ['first_name' => 'Anna', 'last_name' => 'Müller'])
        );
        $this->assertSame('', $service->formatPerson(null));
        $this->assertSame('', $service->formatPerson('Anna Müller'));
    }

    public function testOrderColumnsFollowFormat(): void
    {
        $this->assertSame(
            ['first_name', 'last_name'],
            (new NameFormatterService('first_last'))->orderColumns()
        );
        $this->assertSame(
            ['last_name', 'first_name'],
            (new NameFormatterService('last_first'))->orderColumns()
        );
    }
}
```

- [x] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter NameFormatterServiceTest`
Expected: FAIL mit `Class "App\Services\NameFormatterService" not found`

- [x] **Step 3: Service implementieren**

Datei `src/Services/NameFormatterService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Formats person names according to the globally configured display order.
 */
class NameFormatterService
{
    public const FORMAT_FIRST_LAST = 'first_last';
    public const FORMAT_LAST_FIRST = 'last_first';
    public const DEFAULT_FORMAT = self::FORMAT_FIRST_LAST;

    private string $format;

    public function __construct(?string $format = null)
    {
        $this->format = self::normalizeFormat($format);
    }

    public static function normalizeFormat(?string $format): string
    {
        $candidate = strtolower(trim((string) $format));
        $allowed = [self::FORMAT_FIRST_LAST, self::FORMAT_LAST_FIRST];

        return in_array($candidate, $allowed, true) ? $candidate : self::DEFAULT_FORMAT;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function format(?string $firstName, ?string $lastName): string
    {
        $first = trim((string) $firstName);
        $last = trim((string) $lastName);

        if ($first === '') {
            return $last;
        }

        if ($last === '') {
            return $first;
        }

        return $this->format === self::FORMAT_LAST_FIRST
            ? $last . ', ' . $first
            : $first . ' ' . $last;
    }

    /**
     * Accepts Eloquent models, plain objects and arrays with first_name/last_name keys.
     */
    public function formatPerson(mixed $person): string
    {
        if (is_array($person)) {
            return $this->format($person['first_name'] ?? null, $person['last_name'] ?? null);
        }

        if (is_object($person)) {
            return $this->format($person->first_name ?? null, $person->last_name ?? null);
        }

        return '';
    }

    /**
     * Column order for ORDER BY chains and collection sorts.
     *
     * @return array<int, string>
     */
    public function orderColumns(): array
    {
        return $this->format === self::FORMAT_LAST_FIRST
            ? ['last_name', 'first_name']
            : ['first_name', 'last_name'];
    }
}
```

- [x] **Step 4: LF normalisieren**

```powershell
$f = "d:\Proggen\ChorManager\src\Services\NameFormatterService.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\tests\Unit\Services\NameFormatterServiceTest.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

- [x] **Step 5: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter NameFormatterServiceTest`
Expected: PASS, 6 Tests

- [x] **Step 6: Commit**

```bash
git add src/Services/NameFormatterService.php tests/Unit/Services/NameFormatterServiceTest.php
git commit -m "feat: NameFormatterService für konfigurierbare Namensdarstellung"
```

---

### Task 2: DI-Registrierung und Twig-Filter

**Files:**
- Modify: `src/Dependencies.php`
- Test: `tests/Feature/NameFormatterWiringFeatureTest.php` (neu)

**Interfaces:**
- Consumes: `NameFormatterService` aus Task 1
- Produces:
  - Container-Eintrag `App\Services\NameFormatterService::class`, konstruiert aus `app_settings['name_display_format']`
  - Twig-Filter `person_name` (nimmt Model, Array oder `stdClass`, liefert String)
  - Twig-Global `name_display_format`

- [x] **Step 1: Failing Test schreiben**

Datei `tests/Feature/NameFormatterWiringFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NameFormatterService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

final class NameFormatterWiringFeatureTest extends TestCase
{
    public function testDependenciesRegisterServiceAndTwigFilter(): void
    {
        $dependencies = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');

        $this->assertIsString($dependencies);
        $this->assertStringContainsString('NameFormatterService::class', $dependencies);
        $this->assertStringContainsString("'name_display_format'", $dependencies);
        $this->assertStringContainsString("'person_name'", $dependencies);
    }

    public function testPersonNameFilterRendersConfiguredOrder(): void
    {
        $formatter = new NameFormatterService(NameFormatterService::FORMAT_LAST_FIRST);
        $twig = new Environment(new ArrayLoader(['t' => '{{ person|person_name }}']));
        $twig->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => $formatter->formatPerson($person)
        ));

        $rendered = $twig->render('t', [
            'person' => ['first_name' => 'Anna', 'last_name' => 'Müller'],
        ]);

        $this->assertSame('Müller, Anna', $rendered);
    }
}
```

- [x] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter NameFormatterWiringFeatureTest`
Expected: FAIL im ersten Test, weil `NameFormatterService::class` in `src/Dependencies.php` fehlt.

- [x] **Step 3: Container-Eintrag ergänzen**

In `src/Dependencies.php` bei den übrigen `use`-Statements ergänzen:

```php
use App\Services\NameFormatterService;
use Twig\TwigFilter;
```

Direkt vor dem `Twig::class`-Eintrag (nach `TaskPolicy::class => \DI\autowire(),`) einfügen:

```php
        NameFormatterService::class => function (ContainerInterface $c): NameFormatterService {
            // Falls die Tabelle noch nicht existiert (frische Installation),
            // greift der Default des Service.
            try {
                $stored = \App\Models\AppSetting::query()
                    ->find('name_display_format')?->setting_value;
            } catch (\Throwable $e) {
                $stored = null;
            }

            return new NameFormatterService($stored !== null ? (string) $stored : null);
        },
```

- [x] **Step 4: Twig-Filter und Global registrieren**

In `src/Dependencies.php` im `Twig::class`-Closure direkt vor `return $twig;` einfügen:

```php
            $nameFormatter = $c->get(NameFormatterService::class);
            $environment->addGlobal('name_display_format', $nameFormatter->getFormat());
            $environment->addFilter(new TwigFilter(
                'person_name',
                static fn (mixed $person): string => $nameFormatter->formatPerson($person)
            ));
```

- [x] **Step 5: LF normalisieren, Tests laufen lassen**

```powershell
$f = "d:\Proggen\ChorManager\src\Dependencies.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\tests\Feature\NameFormatterWiringFeatureTest.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

Run: `ddev exec ./vendor/bin/phpunit --filter NameFormatterWiringFeatureTest`
Expected: PASS, 2 Tests

- [x] **Step 6: Commit**

```bash
git add src/Dependencies.php tests/Feature/NameFormatterWiringFeatureTest.php
git commit -m "feat: NameFormatterService im Container und als Twig-Filter registrieren"
```

---

### Task 3: Einstellung in den Stammdaten

**Files:**
- Modify: `src/Controllers/AppSettingController.php`
- Modify: `templates/settings/index.twig`
- Test: `tests/Feature/AppSettingFeatureTest.php`

**Interfaces:**
- Consumes: `NameFormatterService::normalizeFormat()` aus Task 1
- Produces: `app_settings`-Key `name_display_format`; Formularfeld `name="name_display_format"` unter `/settings`

- [x] **Step 1: Failing Tests schreiben**

An `tests/Feature/AppSettingFeatureTest.php` anhängen (vor der schließenden Klammer der Klasse):

```php
    public function testSettingsTemplateOffersNameDisplayFormatField(): void
    {
        $template = file_get_contents(
            dirname(__DIR__) . '/../templates/settings/index.twig'
        );

        $this->assertIsString($template);
        $this->assertStringContainsString('name="name_display_format"', $template);
        $this->assertStringContainsString('first_last', $template);
        $this->assertStringContainsString('last_first', $template);
    }

    public function testSaveNormalizesNameDisplayFormat(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__) . '/../src/Controllers/AppSettingController.php'
        );

        $this->assertIsString($controller);
        $this->assertStringContainsString('name_display_format', $controller);
        $this->assertStringContainsString('NameFormatterService::normalizeFormat', $controller);
    }

    public function testNameDisplayFormatWhitelistRejectsUnknownValues(): void
    {
        $this->assertSame(
            'first_last',
            \App\Services\NameFormatterService::normalizeFormat('irgendwas')
        );
        $this->assertSame(
            'last_first',
            \App\Services\NameFormatterService::normalizeFormat('last_first')
        );
    }
```

- [x] **Step 2: Tests laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter AppSettingFeatureTest`
Expected: FAIL, `name="name_display_format"` nicht im Template gefunden.

- [x] **Step 3: Controller erweitern**

In `src/Controllers/AppSettingController.php` bei den `use`-Statements ergänzen:

```php
use App\Services\NameFormatterService;
```

In `save()` nach der Zeile mit `$registrationReminderDaysBefore = ...` ergänzen:

```php
        $nameDisplayFormat = NameFormatterService::normalizeFormat($data['name_display_format'] ?? null);
```

Im `try`-Block nach dem `registration_reminder_days_before`-Block ergänzen:

```php
            AppSetting::updateOrCreate(
                ['setting_key' => 'name_display_format'],
                [
                    'setting_value' => $nameDisplayFormat,
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );
```

- [x] **Step 4: Auswahlfeld im Template ergänzen**

In `templates/settings/index.twig` direkt vor dem `<div class="d-grid gap-2 d-md-flex justify-content-md-end">` am Ende des Formulars einfügen:

```twig
                        <hr class="my-4">

                        <h2 class="h5 mb-3">Darstellung</h2>

                        {% set _name_format = settings_values.name_display_format|default("first_last") %}
                        <div class="mb-4">
                            <label for="name_display_format" class="form-label fw-bold">Namensdarstellung</label>
                            <select class="form-select"
                                    id="name_display_format"
                                    name="name_display_format">
                                <option value="first_last"
                                        {% if _name_format == "first_last" %}selected{% endif %}>
                                    Vorname Nachname
                                </option>
                                <option value="last_first"
                                        {% if _name_format == "last_first" %}selected{% endif %}>
                                    Nachname, Vorname
                                </option>
                            </select>
                            <div class="form-text">
                                Gilt für alle Listen, Auswahlfelder und Sortierungen der App.
                            </div>
                        </div>
```

- [x] **Step 5: LF normalisieren und Tests laufen lassen**

```powershell
$f = "d:\Proggen\ChorManager\src\Controllers\AppSettingController.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\templates\settings\index.twig"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\tests\Feature\AppSettingFeatureTest.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

Run: `ddev exec ./vendor/bin/phpunit --filter AppSettingFeatureTest`
Expected: PASS

- [x] **Step 6: Commit**

```bash
git add src/Controllers/AppSettingController.php templates/settings/index.twig tests/Feature/AppSettingFeatureTest.php
git commit -m "feat: Namensdarstellung in den Stammdaten einstellbar"
```

---

### Task 4: UserEditPolicy

**Files:**
- Create: `src/Policies/UserEditPolicy.php`
- Modify: `src/Dependencies.php`
- Test: `tests/Unit/Policies/UserEditPolicyTest.php` (neu)

**Interfaces:**
- Consumes: nichts aus vorherigen Tasks
- Produces:
  - `public function canEdit(array $session, User $target): bool`
  - `public function editableUserIdMap(array $session): array` – Rückgabe `array<int, true>`, Schlüssel sind bearbeitbare Benutzer-IDs

Regel (identisch zur heutigen Logik in `UserController::index()`):
1. Inaktive (archivierte) Mitglieder sind nie bearbeitbar – für sie existiert kein Bearbeiten-Modal.
2. `can_edit_users` in der Session → bearbeitbar.
3. Sonst: Stimmgruppen-Vertreter, wenn `voice_group_ids` der Session und Stimmgruppen des Ziels sich schneiden.
4. Sonst: nicht bearbeitbar.

- [x] **Step 1: Failing Test schreiben**

Datei `tests/Unit/Policies/UserEditPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Models\VoiceGroup;
use App\Policies\UserEditPolicy;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class UserEditPolicyTest extends TestCase
{
    private function makeUser(int $id, array $voiceGroupIds, int $isActive = 1): User
    {
        $user = new User();
        $user->forceFill(['id' => $id, 'is_active' => $isActive]);

        $groups = array_map(static function (int $groupId): VoiceGroup {
            $group = new VoiceGroup();
            $group->forceFill(['id' => $groupId]);

            return $group;
        }, $voiceGroupIds);

        $user->setRelation('voiceGroups', new Collection($groups));

        return $user;
    }

    public function testGlobalEditPermissionAllowsEditing(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [])));
    }

    public function testManagerWithoutEditPermissionCannotEdit(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => false, 'can_manage_users' => true];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1])));
    }

    public function testVoiceGroupRepresentativeCanEditOwnVoiceGroupMember(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => false, 'voice_group_ids' => [2, 3]];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [3])));
    }

    public function testVoiceGroupRepresentativeCannotEditForeignMember(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => false, 'voice_group_ids' => [2]];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [5])));
    }

    public function testArchivedMemberIsNeverEditable(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1], 0)));
    }

    public function testEmptySessionCannotEdit(): void
    {
        $policy = new UserEditPolicy();

        $this->assertFalse($policy->canEdit([], $this->makeUser(7, [1])));
    }
}
```

- [x] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter UserEditPolicyTest`
Expected: FAIL mit `Class "App\Policies\UserEditPolicy" not found`

- [x] **Step 3: Policy implementieren**

Datei `src/Policies/UserEditPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Decides whether the current session may edit a given member.
 *
 * Mirrors the rule previously inlined in UserController::index():
 * global can_edit_users, otherwise voice group representatives are
 * limited to members of their own voice groups.
 */
class UserEditPolicy
{
    /**
     * @param array<string, mixed> $session
     */
    public function canEdit(array $session, User $target): bool
    {
        if ((int) ($target->is_active ?? 1) !== 1) {
            return false;
        }

        if (!empty($session['can_edit_users'])) {
            return true;
        }

        $ownGroupIds = $this->sessionVoiceGroupIds($session);
        if ($ownGroupIds === []) {
            return false;
        }

        $targetGroupIds = $target->voiceGroups
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_intersect($ownGroupIds, $targetGroupIds) !== [];
    }

    /**
     * Editable member IDs for list views that only carry plain arrays.
     *
     * @param array<string, mixed> $session
     * @return array<int, true>
     */
    public function editableUserIdMap(array $session): array
    {
        if (!empty($session['can_edit_users'])) {
            $ids = User::query()->where('is_active', 1)->pluck('id');

            return array_fill_keys(
                $ids->map(static fn ($id): int => (int) $id)->all(),
                true
            );
        }

        $ownGroupIds = $this->sessionVoiceGroupIds($session);
        if ($ownGroupIds === []) {
            return [];
        }

        $ids = User::query()
            ->where('is_active', 1)
            ->whereHas('voiceGroups', static function ($query) use ($ownGroupIds): void {
                $query->whereIn('voice_groups.id', $ownGroupIds);
            })
            ->pluck('id');

        return array_fill_keys(
            $ids->map(static fn ($id): int => (int) $id)->all(),
            true
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<int, int>
     */
    private function sessionVoiceGroupIds(array $session): array
    {
        $ids = (array) ($session['voice_group_ids'] ?? []);

        return array_values(array_map(static fn ($id): int => (int) $id, $ids));
    }
}
```

- [x] **Step 4: Im Container registrieren**

In `src/Dependencies.php` neben `ProjectMemberPolicy::class => \DI\autowire(),` ergänzen:

```php
        \App\Policies\UserEditPolicy::class => \DI\autowire(),
```

- [x] **Step 5: LF normalisieren, Test laufen lassen**

```powershell
$f = "d:\Proggen\ChorManager\src\Policies\UserEditPolicy.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\tests\Unit\Policies\UserEditPolicyTest.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\src\Dependencies.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

Run: `ddev exec ./vendor/bin/phpunit --filter UserEditPolicyTest`
Expected: PASS, 6 Tests

- [x] **Step 6: Commit**

```bash
git add src/Policies/UserEditPolicy.php src/Dependencies.php tests/Unit/Policies/UserEditPolicyTest.php
git commit -m "feat: UserEditPolicy für Bearbeitungsrecht je Mitglied"
```

---

### Task 5: Namens-Makro

**Files:**
- Create: `templates/macros/person.twig`
- Test: `tests/Feature/PersonMacroFeatureTest.php` (neu)

**Interfaces:**
- Consumes: Twig-Filter `person_name` aus Task 2
- Produces: Makro `member_link(person, member_id=null, can_edit_map={})`
  - rendert `<a href="/users?edit={id}" class="member-edit-link">Name</a>`, wenn `can_edit_map[member_id]` wahr ist
  - sonst reinen Text

- [x] **Step 1: Failing Test schreiben**

Datei `tests/Feature/PersonMacroFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NameFormatterService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class PersonMacroFeatureTest extends TestCase
{
    private function makeTwig(string $format): Environment
    {
        $formatter = new NameFormatterService($format);
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__) . '/../templates'),
            ['autoescape' => 'html']
        );
        $twig->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => $formatter->formatPerson($person)
        ));

        return $twig;
    }

    private function render(string $format, array $context): string
    {
        $twig = $this->makeTwig($format);
        $template = $twig->createTemplate(
            '{% import "macros/person.twig" as person %}'
            . '{{ person.member_link(member, member.id, can_edit_map) }}'
        );

        return trim($template->render($context));
    }

    public function testRendersLinkWhenMemberIsEditable(): void
    {
        $html = $this->render('first_last', [
            'member' => ['id' => 42, 'first_name' => 'Anna', 'last_name' => 'Müller'],
            'can_edit_map' => [42 => true],
        ]);

        $this->assertStringContainsString('href="/users?edit=42"', $html);
        $this->assertStringContainsString('Anna Müller', $html);
    }

    public function testRendersPlainTextWhenMemberIsNotEditable(): void
    {
        $html = $this->render('last_first', [
            'member' => ['id' => 42, 'first_name' => 'Anna', 'last_name' => 'Müller'],
            'can_edit_map' => [],
        ]);

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertSame('Müller, Anna', $html);
    }

    public function testRendersPlainTextWhenIdIsMissing(): void
    {
        $twig = $this->makeTwig('first_last');
        $template = $twig->createTemplate(
            '{% import "macros/person.twig" as person %}'
            . '{{ person.member_link(member) }}'
        );

        $html = trim($template->render([
            'member' => ['first_name' => 'Anna', 'last_name' => 'Müller'],
        ]));

        $this->assertSame('Anna Müller', $html);
    }
}
```

- [x] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter PersonMacroFeatureTest`
Expected: FAIL mit `Unable to find template "macros/person.twig"`

- [x] **Step 3: Makro implementieren**

Datei `templates/macros/person.twig`:

```twig
{% macro member_link(person, member_id=null, can_edit_map={}) %}
    {%- set _can_edit = member_id is not null and can_edit_map[member_id]|default(false) -%}
    {%- if _can_edit -%}
        <a href="/users?edit={{ member_id }}" class="member-edit-link">{{ person|person_name }}</a>
    {%- else -%}
        {{- person|person_name -}}
    {%- endif -%}
{% endmacro %}
```

- [x] **Step 4: LF normalisieren, Test laufen lassen**

```powershell
$f = "d:\Proggen\ChorManager\templates\macros\person.twig"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\tests\Feature\PersonMacroFeatureTest.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

Run: `ddev exec ./vendor/bin/phpunit --filter PersonMacroFeatureTest`
Expected: PASS, 3 Tests

- [x] **Step 5: Commit**

```bash
git add templates/macros/person.twig tests/Feature/PersonMacroFeatureTest.php
git commit -m "feat: Twig-Makro für Mitgliedsnamen mit optionalem Bearbeiten-Link"
```

---

### Task 6: Mitgliederliste – Namenslink und Deep-Link

**Files:**
- Modify: `src/Controllers/UserController.php` (Konstruktor, `index()`)
- Modify: `templates/users/manage.twig` (Namensspalte, Sortierattribut, Edit-Modal-Trigger)
- Test: `tests/Feature/UserEditDeepLinkFeatureTest.php` (neu)

**Interfaces:**
- Consumes: `UserEditPolicy::canEdit()` (Task 4), Makro `member_link` (Task 5), Filter `person_name` (Task 2)
- Produces: Template-Variablen `open_edit_user_id` (int|null) und `can_edit_member` (`array<int, true>`) in `users/manage.twig`

- [x] **Step 1: Failing Test schreiben**

Datei `tests/Feature/UserEditDeepLinkFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class UserEditDeepLinkFeatureTest extends TestCase
{
    private function controllerSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/../src/Controllers/UserController.php'
        );
        $this->assertIsString($source);

        return $source;
    }

    public function testControllerEvaluatesEditQueryParameter(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString("\$params['edit']", $source);
        $this->assertStringContainsString('open_edit_user_id', $source);
    }

    public function testControllerUsesPolicyForRowPermissions(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString('UserEditPolicy', $source);
        $this->assertStringContainsString('can_edit_member', $source);
    }

    public function testTemplateLinksNameAndOpensModalFromDeepLink(): void
    {
        $template = file_get_contents(
            dirname(__DIR__) . '/../templates/users/manage.twig'
        );

        $this->assertIsString($template);
        $this->assertStringContainsString('macros/person.twig', $template);
        $this->assertStringContainsString('person.member_link(user, user.id, can_edit_member)', $template);
        $this->assertStringContainsString('open_edit_user_id', $template);
        $this->assertStringNotContainsString(
            '<td data-label="Name">{{ user.first_name }} {{ user.last_name }}</td>',
            $template
        );
    }
}
```

- [x] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter UserEditDeepLinkFeatureTest`
Expected: FAIL, `$params['edit']` fehlt im Controller.

- [x] **Step 3: Controller anpassen**

In `src/Controllers/UserController.php` bei den `use`-Statements ergänzen:

```php
use App\Policies\UserEditPolicy;
```

Property und Konstruktorparameter ergänzen (Property zu den übrigen `private`-Feldern, Parameter als letzten Konstruktorparameter nach `LoggerInterface $logger`):

```php
    private UserEditPolicy $userEditPolicy;
```

```php
        LoggerInterface $logger,
        UserEditPolicy $userEditPolicy
    ) {
```

und im Konstruktorrumpf nach `$this->logger = $logger;`:

```php
        $this->userEditPolicy = $userEditPolicy;
```

In `index()` direkt vor dem `return $this->view->render(...)` einfügen:

```php
        $canEditMember = [];
        foreach ($users as $user) {
            if ($this->userEditPolicy->canEdit($_SESSION, $user)) {
                $canEditMember[(int) $user->id] = true;
            }
        }

        $openEditUserId = null;
        $requestedEditId = (int) ($params['edit'] ?? 0);
        if ($requestedEditId > 0 && !$showArchived && isset($canEditMember[$requestedEditId])) {
            $openEditUserId = $requestedEditId;
        }
```

Und im Render-Array ergänzen:

```php
            'can_edit_member' => $canEditMember,
            'open_edit_user_id' => $openEditUserId,
```

- [x] **Step 4: Template anpassen**

In `templates/users/manage.twig` unter dem bestehenden `{% import "macros/modal_form.twig" as modal %}` ergänzen:

```twig
{% import "macros/person.twig" as person %}
```

Sortierattribut der Zeile ersetzen:

```twig
                                        data-sort-name="{{ user|person_name|lower }}"
```

Namensspalte ersetzen:

```twig
                                        <td data-label="Name">{{ person.member_link(user, user.id, can_edit_member) }}</td>
```

Im Edit-Modal-Loop den Öffnen-Trigger ersetzen. Vor `<div class="modal fade"` innerhalb der `{% for user in users %}`-Schleife einfügen:

```twig
            {% set _open_by_error = modal_form_edits[user.id].open_modal|default(false) %}
            {% set _open_by_link = open_edit_user_id|default(null) == user.id %}
            {% set _open_edit_modal = _open_by_error or _open_by_link %}
```

und das Attribut ersetzen:

```twig
                 data-open-edit-modal="{{ _open_edit_modal ? '1' : '0' }}"
```

Die vorhandene Logik in `public/js/users.js` (`[id^="editUserModal"][data-open-edit-modal="1"]`) öffnet das Modal dadurch ohne Änderung.

- [x] **Step 5: LF normalisieren und Tests laufen lassen**

```powershell
$f = "d:\Proggen\ChorManager\src\Controllers\UserController.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\templates\users\manage.twig"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
$f = "d:\Proggen\ChorManager\tests\Feature\UserEditDeepLinkFeatureTest.php"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

Run: `ddev exec ./vendor/bin/phpunit --filter UserEditDeepLinkFeatureTest`
Expected: PASS, 3 Tests

Run: `ddev exec ./vendor/bin/phpunit`
Expected: PASS (keine Regression durch den geänderten Konstruktor)

- [x] **Step 6: Commit**

```bash
git add src/Controllers/UserController.php templates/users/manage.twig tests/Feature/UserEditDeepLinkFeatureTest.php
git commit -m "feat: Mitgliedsname verlinkt auf Bearbeiten-Modal inkl. Deep-Link"
```

---

### Task 7: Verlinkung in den übrigen Verwaltungslisten

**Files:**
- Modify: `src/Controllers/AttendanceController.php` (`show()`)
- Modify: `src/Controllers/EvaluationController.php` (`index()`, Projektmitglieder-Ansicht)
- Modify: `src/Controllers/ProjectController.php` (`showMembers()`)
- Modify: `templates/attendance/show.twig`, `templates/evaluations/index.twig`, `templates/evaluations/project_members.twig`, `templates/projects/members.twig`
- Test: `tests/Feature/MemberLinkCoverageFeatureTest.php` (neu)

**Interfaces:**
- Consumes: `UserEditPolicy::editableUserIdMap()` (Task 4), Makro `member_link` (Task 5)
- Produces: Template-Variable `can_edit_member` (`array<int, true>`) in den vier Templates

- [x] **Step 1: Failing Test schreiben**

Datei `tests/Feature/MemberLinkCoverageFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class MemberLinkCoverageFeatureTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function managementTemplates(): array
    {
        return [
            ['templates/attendance/show.twig'],
            ['templates/evaluations/index.twig'],
            ['templates/evaluations/project_members.twig'],
            ['templates/projects/members.twig'],
        ];
    }

    /**
     * @dataProvider managementTemplates
     */
    public function testManagementTemplatesUseMemberLinkMacro(string $relativePath): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($template);
        $this->assertStringContainsString('macros/person.twig', $template);
        $this->assertStringContainsString('person.member_link(', $template);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function controllers(): array
    {
        return [
            ['src/Controllers/AttendanceController.php'],
            ['src/Controllers/EvaluationController.php'],
            ['src/Controllers/ProjectController.php'],
        ];
    }

    /**
     * @dataProvider controllers
     */
    public function testControllersProvideEditableMemberMap(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($source);
        $this->assertStringContainsString('editableUserIdMap', $source);
        $this->assertStringContainsString('can_edit_member', $source);
    }
}
```

- [x] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter MemberLinkCoverageFeatureTest`
Expected: FAIL, `macros/person.twig` fehlt in `templates/attendance/show.twig`.

- [x] **Step 3: Controller mit `can_edit_member` versorgen**

In allen drei Controllern `use App\Policies\UserEditPolicy;` ergänzen, die Policy als letzten Konstruktorparameter injizieren (Property `private UserEditPolicy $userEditPolicy;`, Zuweisung im Rumpf) und im jeweiligen Render-Array ergänzen:

```php
            'can_edit_member' => $this->userEditPolicy->editableUserIdMap($_SESSION),
```

Betroffene Render-Aufrufe:
- `AttendanceController::show()` → `attendance/show.twig`
- `EvaluationController::index()` → `evaluations/index.twig`
- `EvaluationController` → `evaluations/project_members.twig`
- `ProjectController::showMembers()` → `projects/members.twig`

- [x] **Step 4: Templates auf das Makro umstellen**

Jeweils unterhalb der bestehenden `{% extends %}`- bzw. `{% import %}`-Zeilen ergänzen:

```twig
{% import "macros/person.twig" as person %}
```

`templates/attendance/show.twig` (Zeile 90, Array-Schlüssel ist `user_id`):

```twig
                                            {{ person.member_link(member, member.user_id, can_edit_member) }}
```

`src/Controllers/EvaluationController.php` – das `$stats[]`-Array führt heute keine ID mit. Als ersten Schlüssel ergänzen:

```php
                        $stats[] = [
                            'user_id' => (int) $user->id,
                            'first_name' => $user->first_name,
```

`templates/evaluations/index.twig` (Zeile 65 und 72):

```twig
                                <tr data-sort-name="{{ stat|person_name|lower }}"
```

```twig
                                        <strong>{{ person.member_link(stat, stat.user_id, can_edit_member) }}</strong>
```

`templates/evaluations/project_members.twig` (Zeile 85):

```twig
                                            <span><strong>{{ person.member_link(m, m.id, can_edit_member) }}</strong></span>
```

`templates/projects/members.twig` (Zeile 107 und 111):

```twig
                                <tr data-sort-member_name="{{ m|person_name|lower }}"
```

```twig
                                        <strong>{{ person.member_link(m, m.id, can_edit_member) }}</strong>
```

- [x] **Step 5: LF normalisieren und Tests laufen lassen**

```powershell
Get-ChildItem "d:\Proggen\ChorManager\templates\attendance\show.twig","d:\Proggen\ChorManager\templates\evaluations\index.twig","d:\Proggen\ChorManager\templates\evaluations\project_members.twig","d:\Proggen\ChorManager\templates\projects\members.twig","d:\Proggen\ChorManager\src\Controllers\AttendanceController.php","d:\Proggen\ChorManager\src\Controllers\EvaluationController.php","d:\Proggen\ChorManager\src\Controllers\ProjectController.php","d:\Proggen\ChorManager\tests\Feature\MemberLinkCoverageFeatureTest.php" | ForEach-Object { [System.IO.File]::WriteAllText($_.FullName, ((Get-Content $_.FullName -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev exec ./vendor/bin/phpunit`
Expected: PASS

- [x] **Step 6: Commit**

```bash
git add src/Controllers/AttendanceController.php src/Controllers/EvaluationController.php src/Controllers/ProjectController.php templates/attendance/show.twig templates/evaluations/index.twig templates/evaluations/project_members.twig templates/projects/members.twig tests/Feature/MemberLinkCoverageFeatureTest.php
git commit -m "feat: Namenslinks in Anwesenheits-, Auswertungs- und Projektlisten"
```

---

### Task 8: Sortierung folgt der Einstellung

**Files:**
- Modify: `src/Queries/UserQuery.php`, `src/Queries/ProjectQuery.php`
- Modify: `src/Controllers/AttendanceController.php`, `src/Controllers/EvaluationController.php`, `src/Controllers/EventController.php`, `src/Controllers/NewsletterController.php`, `src/Controllers/RegistrationController.php`, `src/Controllers/TaskController.php`
- Test: `tests/Feature/NameSortOrderFeatureTest.php` (neu)

**Interfaces:**
- Consumes: `NameFormatterService::orderColumns()` (Task 1)
- Produces: keine neuen öffentlichen Signaturen; alle Namenssortierungen laufen über `orderColumns()`

- [x] **Step 1: Failing Test schreiben**

Datei `tests/Feature/NameSortOrderFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class NameSortOrderFeatureTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function sortingSources(): array
    {
        return [
            ['src/Queries/UserQuery.php'],
            ['src/Queries/ProjectQuery.php'],
            ['src/Controllers/AttendanceController.php'],
            ['src/Controllers/EvaluationController.php'],
            ['src/Controllers/EventController.php'],
            ['src/Controllers/NewsletterController.php'],
            ['src/Controllers/RegistrationController.php'],
            ['src/Controllers/TaskController.php'],
        ];
    }

    /**
     * @dataProvider sortingSources
     */
    public function testNoHardcodedNameOrdering(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($source);
        $this->assertStringNotContainsString("orderBy('last_name')", $source);
        $this->assertStringNotContainsString("orderBy('first_name')", $source);
        $this->assertStringNotContainsString("sortBy(['last_name', 'first_name'])", $source);
        $this->assertStringContainsString('orderColumns()', $source);
    }
}
```

- [x] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter NameSortOrderFeatureTest`
Expected: FAIL für alle acht Dateien.

- [x] **Step 3: Queries umstellen**

`src/Queries/UserQuery.php` – Konstruktor ergänzen und beide Methoden anpassen:

```php
use App\Services\NameFormatterService;

class UserQuery
{
    private NameFormatterService $nameFormatter;

    public function __construct(NameFormatterService $nameFormatter)
    {
        $this->nameFormatter = $nameFormatter;
    }
```

```php
    public function getAllUsers(): Collection
    {
        $query = User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup', 'projects'])
            ->where('is_active', 1);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    public function getArchivedUsers(): Collection
    {
        $query = User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup', 'projects'])
            ->where('is_active', 0);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }
```

`src/Queries/ProjectQuery.php` – analoger Konstruktor; in `getProjectMembers()` und der gruppierten Mitgliederabfrage (`$query->orderBy('last_name')->orderBy('first_name')->get()`) sowie bei den `orderBy('first_name')`-Aufrufen dieselbe Schleife verwenden:

```php
        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }
```

- [x] **Step 4: Controller umstellen**

In allen sechs Controllern `use App\Services\NameFormatterService;` ergänzen, Service als letzten Konstruktorparameter injizieren (Property `private NameFormatterService $nameFormatter;`) und die Sortierungen ersetzen:

- `AttendanceController::show()`:
  ```php
                    ->sortBy($this->nameFormatter->orderColumns());
  ```
- `RegistrationController` (Zeile mit `sortBy(['last_name', 'first_name'])`): identisch ersetzen.
- `EvaluationController::index()` – Sortierspalten dynamisch:
  ```php
        $tableParams = TableQueryParams::from(
            $params,
            array_merge(
                $this->nameFormatter->orderColumns(),
                ['percentage', 'present_count', 'excused_count', 'unexcused_count']
            )
        );
  ```
  und den `orderBy('first_name')`-Aufruf durch die `foreach`-Schleife über `orderColumns()` ersetzen.
- `EventController` (zwei Stellen `->orderBy('last_name')->orderBy('first_name')->get()`), `NewsletterController` (zwei Stellen), `TaskController` (zwei Stellen `orderBy('first_name')`): jeweils Query in eine Variable ziehen und die `foreach`-Schleife über `orderColumns()` anwenden, dann `->get()`.

- [x] **Step 5: Twig-Sortierattribute angleichen**

In `templates/projects/tasks.twig` Zeile 98:

```twig
                                            data-sort-assignee_name="{{ task.assignee ? task.assignee|person_name|lower : "" }}"
```

In `templates/newsletters/index.twig` Zeile 167:

```twig
                                                data-sort-sender="{{ newsletter.createdBy|person_name|lower }}"
```

- [x] **Step 6: LF normalisieren und Tests laufen lassen**

```powershell
Get-ChildItem "d:\Proggen\ChorManager\src\Queries\UserQuery.php","d:\Proggen\ChorManager\src\Queries\ProjectQuery.php","d:\Proggen\ChorManager\src\Controllers\AttendanceController.php","d:\Proggen\ChorManager\src\Controllers\EvaluationController.php","d:\Proggen\ChorManager\src\Controllers\EventController.php","d:\Proggen\ChorManager\src\Controllers\NewsletterController.php","d:\Proggen\ChorManager\src\Controllers\RegistrationController.php","d:\Proggen\ChorManager\src\Controllers\TaskController.php","d:\Proggen\ChorManager\templates\projects\tasks.twig","d:\Proggen\ChorManager\templates\newsletters\index.twig","d:\Proggen\ChorManager\tests\Feature\NameSortOrderFeatureTest.php" | ForEach-Object { [System.IO.File]::WriteAllText($_.FullName, ((Get-Content $_.FullName -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev exec ./vendor/bin/phpunit`
Expected: PASS

- [x] **Step 7: Commit**

```bash
git add src/Queries src/Controllers templates/projects/tasks.twig templates/newsletters/index.twig tests/Feature/NameSortOrderFeatureTest.php
git commit -m "feat: Namenssortierung folgt der eingestellten Darstellung"
```

---

### Task 9: Restliche Anzeigestellen in Twig

**Files:**
- Modify: `templates/events/detail.twig`, `templates/events/_audience_sources.twig`, `templates/newsletters/create.twig`, `templates/newsletters/edit.twig`, `templates/newsletters/index.twig`, `templates/newsletters/archive.twig`, `templates/newsletters/preview.twig`, `templates/newsletters/locked.twig`, `templates/projects/members.twig`, `templates/projects/tasks.twig`, `templates/projects/task_detail.twig`, `templates/partials/comments.twig`, `templates/partials/history.twig`, `templates/registrations/detail.twig`, `templates/sponsoring/sponsors/detail.twig`
- Test: `tests/Feature/NameDisplayCoverageFeatureTest.php` (neu)

**Interfaces:**
- Consumes: Filter `person_name` (Task 2)
- Produces: keine neuen Signaturen

- [ ] **Step 1: Failing Test schreiben**

Datei `tests/Feature/NameDisplayCoverageFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class NameDisplayCoverageFeatureTest extends TestCase
{
    /**
     * Templates, die Namen bewusst nicht über den Filter ausgeben:
     * E-Mail-Anreden, Initialen-Avatare und Formularfelder.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'templates/emails/invitation.twig',
        'templates/emails/password_reset.twig',
        'templates/emails/registration_reminder.twig',
        'templates/auth/setup.twig',
        'templates/profile/index.twig',
        'templates/users/manage.twig',
    ];

    public function testNoTemplateConcatenatesNamesInline(): void
    {
        $root = realpath(dirname(__DIR__) . '/..');
        $this->assertIsString($root);

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates')
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            $patterns = [
                '/first_name\s*\}\}\s*\{\{\s*[\w.]+\.last_name/',
                '/last_name\s*\}\},\s*\{\{\s*[\w.]+\.first_name/',
                '/first_name[^}]*~\s*["\'][ ,]+["\']\s*~[^}]*last_name/',
                '/last_name[^}]*~\s*["\'][ ,]+["\']\s*~[^}]*first_name/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $content) === 1) {
                    $offenders[] = $relative;
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, 'Inline-Namensverkettung in: ' . implode(', ', $offenders));
    }
}
```

Hinweis: `templates/users/manage.twig` steht auf der Ausnahmeliste, weil dort die Formularfelder des Anlegen- und Bearbeiten-Modals `first_name`/`last_name` enthalten; die Namensspalte ist bereits in Task 6 umgestellt.

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter NameDisplayCoverageFeatureTest`
Expected: FAIL mit einer Liste der noch nicht umgestellten Templates.

- [ ] **Step 3: Templates umstellen**

Jede gemeldete Fundstelle nach diesem Muster ersetzen:

```twig
{# vorher #}
{{ note.user.first_name }} {{ note.user.last_name }}
{# nachher #}
{{ note.user|person_name }}
```

```twig
{# vorher #}
<option value="{{ member.id }}">{{ member.last_name }}, {{ member.first_name }}</option>
{# nachher #}
<option value="{{ member.id }}">{{ member|person_name }}</option>
```

```twig
{# vorher #}
{% set sender_name = (newsletter.createdBy.first_name|default("") ~ " " ~ newsletter.createdBy.last_name|default(""))|trim %}
{# nachher #}
{% set sender_name = newsletter.createdBy|person_name %}
```

Nicht anfassen: Initialen-Avatare wie `{{ comment.user.first_name|first }}{{ comment.user.last_name|first }}` und `{{ task.assignee.first_name|slice(0, 1) }}{{ task.assignee.last_name|slice(0, 1) }}`. Die `title`-Attribute daneben werden dagegen auf `{{ task.assignee|person_name }}` umgestellt.

- [ ] **Step 4: LF normalisieren, Twig-Gate und Tests laufen lassen**

```powershell
Get-ChildItem "d:\Proggen\ChorManager\templates" -Recurse -Filter *.twig | ForEach-Object { [System.IO.File]::WriteAllText($_.FullName, ((Get-Content $_.FullName -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer twigcs`
Expected: keine blockierenden Verstöße. Bei Bedarf `ddev composer twigcbf` und erneut prüfen.

Run: `ddev exec ./vendor/bin/phpunit --filter NameDisplayCoverageFeatureTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add templates tests/Feature/NameDisplayCoverageFeatureTest.php
git commit -m "feat: Namensausgabe in allen Templates über person_name"
```

---

### Task 10: PHP-Anzeigestellen

**Files:**
- Modify: `src/Services/SessionAuthService.php`, `src/Controllers/NewsletterController.php`, `src/Controllers/RegistrationController.php`, `src/Controllers/SponsoringDashboardController.php`, `src/Controllers/EventController.php`
- Test: `tests/Feature/NameDisplayPhpCoverageFeatureTest.php` (neu)

**Interfaces:**
- Consumes: `NameFormatterService::formatPerson()` (Task 1)
- Produces: `SessionAuthService::__construct(NameFormatterService $nameFormatter)`

- [ ] **Step 1: Failing Test schreiben**

Datei `tests/Feature/NameDisplayPhpCoverageFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\NameFormatterService;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;

final class NameDisplayPhpCoverageFeatureTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function sources(): array
    {
        return [
            ['src/Services/SessionAuthService.php'],
            ['src/Controllers/NewsletterController.php'],
            ['src/Controllers/RegistrationController.php'],
            ['src/Controllers/SponsoringDashboardController.php'],
            ['src/Controllers/EventController.php'],
        ];
    }

    /**
     * @dataProvider sources
     */
    public function testNoInlineNameConcatenation(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/first_name\s*\.\s*[\'"] [\'"]\s*\.\s*\$?\w+(->|\[)/',
            $source
        );
        $this->assertStringContainsString('formatPerson', $source);
    }

    public function testSessionDisplayNameFollowsConfiguredFormat(): void
    {
        $user = new User();
        $user->forceFill([
            'id' => 3,
            'first_name' => 'Anna',
            'last_name' => 'Müller',
        ]);
        $user->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([]));
        $user->setRelation('voiceGroups', new \Illuminate\Database\Eloquent\Collection([]));

        $_SESSION = [];
        $service = new SessionAuthService(
            new NameFormatterService(NameFormatterService::FORMAT_LAST_FIRST)
        );
        $service->setAuthenticatedUser($user);

        $this->assertSame('Müller, Anna', $_SESSION['user_name']);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter NameDisplayPhpCoverageFeatureTest`
Expected: FAIL, `formatPerson` fehlt in den Quellen und `SessionAuthService` hat keinen Konstruktor.

- [ ] **Step 3: SessionAuthService umstellen**

```php
use App\Services\NameFormatterService;

class SessionAuthService
{
    private NameFormatterService $nameFormatter;

    public function __construct(NameFormatterService $nameFormatter)
    {
        $this->nameFormatter = $nameFormatter;
    }

    public function setAuthenticatedUser(User $user): void
    {
        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['user_name'] = $this->nameFormatter->formatPerson($user);
```

Der Service wird bereits per Autowiring in `AuthController` und `AuthMiddleware` injiziert; durch die Container-Registrierung aus Task 2 löst PHP-DI die neue Abhängigkeit automatisch auf.

- [ ] **Step 4: Restliche PHP-Stellen umstellen**

In den vier Controllern `NameFormatterService` per Konstruktor injizieren (Property + Zuweisung) und ersetzen:

- `NewsletterController` (`'locked_by_user' => ...`):
  ```php
            'locked_by_user' => $lockedByUser
                ? $this->nameFormatter->formatPerson($lockedByUser)
                : 'Unknown',
  ```
- `RegistrationController`:
  ```php
        return $updatedBy ? $this->nameFormatter->formatPerson($updatedBy) : null;
  ```
- `SponsoringDashboardController`:
  ```php
        return $this->nameFormatter->formatPerson($contact->user);
  ```
- `EventController` (Zielgruppen-Label):
  ```php
                'user' => 'Person: ' . $this->nameFormatter->formatPerson(User::find($refId)),
  ```

`TaskController` bleibt unverändert – der Aktivitätstext nutzt bewusst nur den Vornamen.

- [ ] **Step 5: LF normalisieren und Tests laufen lassen**

```powershell
Get-ChildItem "d:\Proggen\ChorManager\src\Services\SessionAuthService.php","d:\Proggen\ChorManager\src\Controllers\NewsletterController.php","d:\Proggen\ChorManager\src\Controllers\RegistrationController.php","d:\Proggen\ChorManager\src\Controllers\SponsoringDashboardController.php","d:\Proggen\ChorManager\src\Controllers\EventController.php","d:\Proggen\ChorManager\tests\Feature\NameDisplayPhpCoverageFeatureTest.php" | ForEach-Object { [System.IO.File]::WriteAllText($_.FullName, ((Get-Content $_.FullName -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev exec ./vendor/bin/phpunit`
Expected: PASS. Schlägt ein bestehender Test wegen des neuen `SessionAuthService`-Konstruktors fehl, dort `new SessionAuthService(new NameFormatterService())` übergeben.

- [ ] **Step 6: Commit**

```bash
git add src/Services/SessionAuthService.php src/Controllers tests/Feature/NameDisplayPhpCoverageFeatureTest.php
git commit -m "feat: PHP-seitige Namensausgabe über NameFormatterService"
```

---

### Task 11: Seed-Daten, Abschlussprüfung und Qualitätstore

**Files:**
- Modify: `src/Services/DevSeedService.php`
- Test: bestehende Suite

**Interfaces:**
- Consumes: Einstellungs-Key aus Task 3
- Produces: Seed-Wert `name_display_format = first_last`

- [ ] **Step 1: Failing Test schreiben**

An `tests/Feature/NameFormatterWiringFeatureTest.php` anhängen:

```php
    public function testSeedProvidesNameDisplayFormat(): void
    {
        $seed = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seed);
        $this->assertStringContainsString('name_display_format', $seed);
    }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit --filter NameFormatterWiringFeatureTest`
Expected: FAIL, `name_display_format` fehlt in `DevSeedService`.

- [ ] **Step 3: Seed ergänzen**

In `src/Services/DevSeedService.php` im Array der App-Einstellungen (bei `AppSetting::updateOrCreate`, rund um Zeile 1457) einen weiteren Eintrag ergänzen:

```php
            [
                'setting_key' => 'name_display_format',
                'setting_value' => 'first_last',
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ],
```

Den zugehörigen Zähler im Seed-Report von `run()` mitführen, so wie es die übrigen App-Einstellungen tun. Keine neue Tabelle, daher kein Eintrag in `resetSeedData()`.

- [ ] **Step 4: Dev-Seed real ausführen und Report prüfen**

Run: `ddev exec php bin/dev_seed.php`
Expected: Lauf ohne Fehler; im Report ist die Zahl der App-Einstellungen um eins höher als vorher.

Prüfen:

Run: `ddev exec php -r "require 'vendor/autoload.php';" && ddev mysql -e "SELECT setting_key, setting_value FROM app_settings WHERE setting_key='name_display_format';"`
Expected: eine Zeile mit `first_last`.

- [ ] **Step 5: Abschluss-Grep gegen übersehene Fundstellen**

Run: `ddev exec grep -rn "first_name" templates src --include=*.twig --include=*.php`
Erwartet werden nur noch:
- Formularfelder und Validierung (`auth/setup.twig`, `profile/index.twig`, `users/manage.twig`, `AuthController`, `ProfileController`, `UserController`)
- E-Mail-Anreden in `templates/emails/*`
- Initialen-Avatare (`|first`, `|slice(0, 1)`)
- Datenaufbau in Queries, Controllern und `DevSeedService` (Array-Schlüssel)
- `TaskController` (bewusst nur Vorname)

Jede andere Fundstelle nachziehen und den passenden Test aus Task 9 oder 10 erweitern.

- [ ] **Step 6: Qualitätstore**

Run: `ddev composer phpcs`
Expected: 0 Errors. Bei Verstößen `ddev composer phpcbf` und erneut prüfen.

Run: `ddev composer twigcs`
Expected: keine blockierenden Verstöße. Bei Verstößen `ddev composer twigcbf` und erneut prüfen.

Run: `ddev exec ./vendor/bin/phpunit`
Expected: gesamte Suite grün.

- [ ] **Step 7: Manuelle Gegenprobe beider Formate**

In den Stammdaten auf `Nachname, Vorname` umstellen, speichern und `/users`, `/attendance`, `/evaluations` sowie eine Projektmitgliederliste aufrufen. Erwartet: Anzeige und Sortierreihenfolge drehen gemeinsam. Danach zurück auf `Vorname Nachname` stellen.

- [ ] **Step 8: Commit**

```bash
git add src/Services/DevSeedService.php tests/Feature/NameFormatterWiringFeatureTest.php
git commit -m "feat: Seed-Daten für Namensdarstellung und Abschlussprüfung"
```

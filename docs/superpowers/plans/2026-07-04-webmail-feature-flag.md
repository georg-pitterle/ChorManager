# Webmail-Feature-Flag Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** SnappyMail-Webmail per `.env`-Flag `FEATURE_WEBMAIL` abschaltbar machen; bei abgeschaltetem Flag optional externe Webmail-URL pro Benutzer, auf die das Mail-Badge verlinkt.

**Architecture:** Bestehendes `FEATURE_*`-Muster: Env-Variable → `modules`-Array in `src/Settings.php` → Route-Gate in `src/Routes.php` → Template-Gates über das Twig-Global `settings`. Neue nullable Spalte `external_webmail_url` auf `user_mail_accounts` (Phinx-Migration), gepflegt im Mailbox-Formular des Profils, ausgespielt als Twig-Global für das Badge.

**Tech Stack:** PHP 8.5 (Slim 4, Eloquent/Capsule, PHP-DI, Twig), Phinx, PHPUnit 10, DDEV.

**Spec:** `docs/superpowers/specs/2026-07-04-webmail-feature-flag-design.md`

## Global Constraints

- Alle Kommandos via DDEV: `ddev exec ./vendor/bin/phpunit ...`, `ddev exec ./vendor/bin/phinx migrate`, `ddev composer phpcs`, `ddev composer twigcs`.
- Kein `git push` — nur lokale Commits.
- Neue/geänderte Textdateien mit LF-Zeilenenden. Nach jedem Schreiben auf Windows normalisieren:
  `$f = "<absoluter-pfad>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
- Deutsche Texte mit echten Umlauten (ä/ö/ü/ß), niemals ae/oe/ue/ss.
- PHP: PSR-12, 4 Spaces, Zeilenlimit soft 120 / hard 130.
- Twig: doppelte Anführungszeichen, kein Inline-JS/CSS, Operatoren mit genau 1 Leerzeichen.
- Logging nur über `Psr\Log\LoggerInterface` mit `event`-Key im Context.
- Default des Flags: `false`. Exakter Settings-Eintrag: `'webmail' => EnvHelper::read('FEATURE_WEBMAIL', 'false') === 'true',`

---

### Task 1: Migration + Model — Spalte `external_webmail_url`

**Files:**
- Create: `db/migrations/20260704200000_add_external_webmail_url_to_user_mail_accounts.php`
- Modify: `src/Models/UserMailAccount.php:15-30` (`$fillable`)
- Test: `tests/Feature/WebmailFeatureFlagTest.php` (neu)

**Interfaces:**
- Consumes: bestehende Tabelle `user_mail_accounts` (Migrationen `20260624204635`, `20260630194003`).
- Produces: nullable Spalte `external_webmail_url` (varchar 255) + Model-Attribut `external_webmail_url` für Tasks 3-6.

- [ ] **Step 1: Failing Test schreiben**

Neue Datei `tests/Feature/WebmailFeatureFlagTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserMailAccount;
use PHPUnit\Framework\TestCase;

final class WebmailFeatureFlagTest extends TestCase
{
    public function testUserMailAccountHasExternalWebmailUrlFillable(): void
    {
        $this->assertContains('external_webmail_url', (new UserMailAccount())->getFillable());
    }

    public function testMigrationForExternalWebmailUrlExists(): void
    {
        $migration = dirname(__DIR__, 2)
            . '/db/migrations/20260704200000_add_external_webmail_url_to_user_mail_accounts.php';
        $this->assertFileExists($migration);

        $content = file_get_contents($migration);
        $this->assertIsString($content);
        $this->assertStringContainsString("'external_webmail_url'", $content);
        $this->assertStringContainsString("'null' => true", $content);
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: FAIL (2 Failures: fillable fehlt, Migrationsdatei fehlt)

- [ ] **Step 3: Migration schreiben**

Neue Datei `db/migrations/20260704200000_add_external_webmail_url_to_user_mail_accounts.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddExternalWebmailUrlToUserMailAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_mail_accounts')
            ->addColumn('external_webmail_url', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'mail_badge_enabled',
            ])
            ->save();
    }

    public function down(): void
    {
        $this->table('user_mail_accounts')
            ->removeColumn('external_webmail_url')
            ->save();
    }
}
```

- [ ] **Step 4: Model erweitern**

In `src/Models/UserMailAccount.php` im `$fillable`-Array nach `'mail_badge_enabled',` einfügen:

```php
        'external_webmail_url',
```

- [ ] **Step 5: Migration ausführen**

Run: `ddev exec ./vendor/bin/phinx migrate`
Expected: `20260704200000 AddExternalWebmailUrlToUserMailAccounts: migrated` — Ergebnis (Erfolg/Fehler) berichten.

- [ ] **Step 6: Test ausführen, Erfolg verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: PASS (2 Tests)

- [ ] **Step 7: Commit**

```bash
git add db/migrations/20260704200000_add_external_webmail_url_to_user_mail_accounts.php src/Models/UserMailAccount.php tests/Feature/WebmailFeatureFlagTest.php
git commit -m "feat(webmail): add external_webmail_url column to user_mail_accounts"
```

---

### Task 2: Feature-Flag in Settings + Route-Gate

**Files:**
- Modify: `src/Settings.php:40-43` (`modules`-Array)
- Modify: `src/Routes.php:109` (Webmail-Route)
- Test: `tests/Feature/WebmailFeatureFlagTest.php` (erweitern)

**Interfaces:**
- Consumes: `App\Util\EnvHelper::read(string $key, string $default): string` (bereits in Settings.php importiert und benutzt).
- Produces: `$settings['modules']['webmail']` (bool) — von Routes.php und Twig-Global `settings.modules.webmail` (Tasks 4-5) gelesen.

- [ ] **Step 1: Failing Tests ergänzen**

In `tests/Feature/WebmailFeatureFlagTest.php` ergänzen:

```php
    public function testSettingsExposeWebmailFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'webmail'\\s*=>\\s*EnvHelper::read\\('FEATURE_WEBMAIL', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testWebmailRouteIsRegisteredOnlyInsideFeatureGate(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        $gatePos = strpos($content, "if (\$settings['modules']['webmail'] ?? false) {");
        $routePos = strpos($content, "'/profile/webmail/start'");

        $this->assertNotFalse($gatePos, 'Webmail feature gate missing in Routes.php.');
        $this->assertNotFalse($routePos, 'Webmail route missing in Routes.php.');
        $this->assertGreaterThan($gatePos, $routePos, 'Webmail route must be inside the feature gate.');
    }
```

- [ ] **Step 2: Tests ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: FAIL (die zwei neuen Tests)

- [ ] **Step 3: Settings.php erweitern**

In `src/Settings.php` das `modules`-Array ändern von:

```php
            'modules' => [
                'sheet_archive' => EnvHelper::read('FEATURE_SHEET_ARCHIVE', 'false') === 'true',
                'budget'        => EnvHelper::read('FEATURE_BUDGET', 'false') === 'true',
            ],
```

zu:

```php
            'modules' => [
                'sheet_archive' => EnvHelper::read('FEATURE_SHEET_ARCHIVE', 'false') === 'true',
                'budget'        => EnvHelper::read('FEATURE_BUDGET', 'false') === 'true',
                'webmail'       => EnvHelper::read('FEATURE_WEBMAIL', 'false') === 'true',
            ],
```

- [ ] **Step 4: Routes.php gaten**

In `src/Routes.php` die Zeile

```php
            $group->post('/profile/webmail/start', [WebmailController::class, 'start']);
```

ersetzen durch:

```php
            if ($settings['modules']['webmail'] ?? false) {
                $group->post('/profile/webmail/start', [WebmailController::class, 'start']);
            }
```

Hinweis: Die umgebende Group-Closure hat bereits `use ($settings)` (siehe `src/Routes.php:100`), nichts weiter nötig.

- [ ] **Step 5: Tests ausführen, Erfolg verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: PASS

- [ ] **Step 6: Bestehende Webmail-Tests gegenprüfen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailControllerFeatureTest.php tests/Unit/Services/SnappymailSsoTokenServiceTest.php`
Expected: PASS (Controller/Service unverändert; Tests instanziieren direkt, kein Routing)

- [ ] **Step 7: Commit**

```bash
git add src/Settings.php src/Routes.php tests/Feature/WebmailFeatureFlagTest.php
git commit -m "feat(webmail): gate webmail route behind FEATURE_WEBMAIL flag"
```

---

### Task 3: ProfileController — externe Webmail-URL speichern und validieren

**Files:**
- Modify: `src/Controllers/ProfileController.php:89-102` (`mailboxViewFromOldInput`), `:190-269` (`updateMailbox`)
- Test: `tests/Feature/ProfileExternalWebmailUrlTest.php` (neu)

**Interfaces:**
- Consumes: `UserMailAccount` mit fillable `external_webmail_url` (Task 1); `MailCredentialCryptoService` (Konstruktor wirft ohne `MAIL_CREDENTIAL_KEY`-Env — Test muss Key setzen).
- Produces: `updateMailbox()` persistiert `external_webmail_url` (string|null); Attribut wird nur angefasst, wenn der Request-Body den Key `external_webmail_url` enthält (Formularfeld existiert bei aktivem SnappyMail nicht — vorhandene Werte dürfen dann nicht gelöscht werden).

Validierungsregeln (Spec): leer erlaubt (→ `null`), sonst muss die URL mit `https://` oder `http://` beginnen, `FILTER_VALIDATE_URL` bestehen und max. 255 Zeichen lang sein.

- [ ] **Step 1: Failing Test schreiben**

Neue Datei `tests/Feature/ProfileExternalWebmailUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProfileController;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\PasswordPolicyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class ProfileExternalWebmailUrlTest extends TestCase
{
    use TestHttpHelpers;

    private const CRYPTO_ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ProfileController $controller;
    private User $user;
    private ?string $originalCryptoEnvValue = null;
    private bool $hadCryptoEnvValue = false;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->hadCryptoEnvValue = array_key_exists(self::CRYPTO_ENV_KEY, $_ENV);
        $this->originalCryptoEnvValue = $_ENV[self::CRYPTO_ENV_KEY] ?? null;
        $cryptoKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::CRYPTO_ENV_KEY] = $cryptoKey;
        $_SERVER[self::CRYPTO_ENV_KEY] = $cryptoKey;
        putenv(self::CRYPTO_ENV_KEY . '=' . $cryptoKey);

        $this->controller = new ProfileController(
            $this->createMock(Twig::class),
            new UserQuery(),
            new PasswordPolicyService(),
            new NullLogger(),
            new MailCredentialCryptoService()
        );

        $this->user = User::create([
            'first_name' => 'Extern',
            'last_name' => 'Webmail',
            'email' => 'extern.webmail.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        // Bestehender Account, damit updateMailbox ohne Passwort auskommt.
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'extern.webmail@example.org',
            'imap_password_enc' => 'dummy-enc-value',
            'imap_enabled' => true,
        ]);

        $_SESSION = [];
        $_SESSION['user_id'] = $this->user->id;
    }

    protected function tearDown(): void
    {
        UserMailAccount::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();

        if ($this->hadCryptoEnvValue) {
            $_ENV[self::CRYPTO_ENV_KEY] = $this->originalCryptoEnvValue;
            $_SERVER[self::CRYPTO_ENV_KEY] = $this->originalCryptoEnvValue;
            putenv(self::CRYPTO_ENV_KEY . '=' . $this->originalCryptoEnvValue);
        } else {
            unset($_ENV[self::CRYPTO_ENV_KEY], $_SERVER[self::CRYPTO_ENV_KEY]);
            putenv(self::CRYPTO_ENV_KEY);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function mailboxPostBody(array $overrides = []): array
    {
        return array_merge([
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'extern.webmail@example.org',
            'imap_password' => '',
        ], $overrides);
    }

    public function testValidExternalWebmailUrlIsPersisted(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', $this->mailboxPostBody([
            'external_webmail_url' => 'https://webmail.example.org/inbox',
        ]));

        $this->controller->updateMailbox($request, $this->makeResponse());

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertSame('https://webmail.example.org/inbox', $account->external_webmail_url);
        $this->assertArrayHasKey('success', $_SESSION);
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testInvalidExternalWebmailUrlIsRejected(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', $this->mailboxPostBody([
            'external_webmail_url' => 'javascript:alert(1)',
        ]));

        $this->controller->updateMailbox($request, $this->makeResponse());

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNull($account->external_webmail_url);
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testEmptyExternalWebmailUrlClearsStoredValue(): void
    {
        UserMailAccount::where('user_id', $this->user->id)
            ->update(['external_webmail_url' => 'https://webmail.example.org/']);

        $request = $this->makeRequest('POST', '/profile/mailbox', $this->mailboxPostBody([
            'external_webmail_url' => '',
        ]));

        $this->controller->updateMailbox($request, $this->makeResponse());

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNull($account->external_webmail_url);
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testMissingExternalWebmailUrlKeyKeepsStoredValue(): void
    {
        UserMailAccount::where('user_id', $this->user->id)
            ->update(['external_webmail_url' => 'https://webmail.example.org/']);

        // Kein external_webmail_url-Key im Body (Feld wird bei aktivem SnappyMail nicht gerendert).
        $request = $this->makeRequest('POST', '/profile/mailbox', $this->mailboxPostBody());

        $this->controller->updateMailbox($request, $this->makeResponse());

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertSame('https://webmail.example.org/', $account->external_webmail_url);
        unset($_SESSION['success'], $_SESSION['error']);
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileExternalWebmailUrlTest.php`
Expected: FAIL — `testValidExternalWebmailUrlIsPersisted` (Wert bleibt null), `testInvalidExternalWebmailUrlIsRejected` (kein error in Session). `testMissingExternalWebmailUrlKeyKeepsStoredValue` und `testEmptyExternalWebmailUrlClearsStoredValue` können je nach Ist-Zustand grün sein — das ist ok.

- [ ] **Step 3: Validierung + Persistierung implementieren**

In `src/Controllers/ProfileController.php`:

(a) In `updateMailbox()` nach der Zeile `$smtpEncryption = trim((string)($data['smtp_encryption'] ?? ''));` einfügen:

```php
        $hasExternalWebmailUrl = array_key_exists('external_webmail_url', $data);
        $externalWebmailUrl = $hasExternalWebmailUrl ? trim((string)$data['external_webmail_url']) : '';
```

(b) Nach dem bestehenden Block

```php
        if ($error === null && $imapPassword !== '' && self::containsControlChars($imapPassword)) {
            $error = 'Das Passwort darf keine Steuerzeichen enthalten.';
        }
```

einfügen:

```php
        if ($error === null && $externalWebmailUrl !== '') {
            $error = self::validateExternalWebmailUrl($externalWebmailUrl);
        }
```

(c) Im `$attributes`-Array nach `'mail_badge_enabled' => $mailBadgeEnabled,` einfügen:

```php
        if ($hasExternalWebmailUrl) {
            $attributes['external_webmail_url'] = $externalWebmailUrl !== '' ? $externalWebmailUrl : null;
        }
```

(Direkt nach dem Array-Literal als eigene Anweisung, analog zum bestehenden `imap_password_enc`-Block.)

(d) Neue private Methode nach `validateMailboxConnectionFields()`:

```php
    /**
     * Externe Webmail-URL: nur http(s), gültige URL, max. 255 Zeichen.
     * Liefert null bei gültiger Eingabe, sonst die Fehlermeldung.
     */
    private static function validateExternalWebmailUrl(string $url): ?string
    {
        $isHttp = str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
        if (!$isHttp || strlen($url) > 255 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Bitte gib eine gültige Webmail-URL an (http:// oder https://, max. 255 Zeichen).';
        }

        return null;
    }
```

(e) In `mailboxViewFromOldInput()` im Rückgabe-Array ergänzen:

```php
            'external_webmail_url' => trim((string)($data['external_webmail_url'] ?? '')),
```

- [ ] **Step 4: Test ausführen, Erfolg verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileExternalWebmailUrlTest.php`
Expected: PASS (4 Tests)

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/ProfileController.php tests/Feature/ProfileExternalWebmailUrlTest.php
git commit -m "feat(webmail): store optional external webmail URL in mailbox settings"
```

---

### Task 4: Profil-Template — URL-Feld + "Webmail öffnen"-Button

**Files:**
- Modify: `templates/profile/index.twig:285-315` (Formularende + Webmail-Button)
- Test: `tests/Feature/WebmailFeatureFlagTest.php` (erweitern)

**Interfaces:**
- Consumes: Twig-Global `settings.modules.webmail` (Task 2); View-Variablen `mail_account` (Objekt oder Array aus `mailboxViewFromOldInput`, Task 3) und `webmail_available` (bestehend).
- Produces: Formularfeld `external_webmail_url` (nur bei Flag aus) — von `updateMailbox()` (Task 3) gelesen.

- [ ] **Step 1: Failing Tests ergänzen**

In `tests/Feature/WebmailFeatureFlagTest.php` ergänzen:

```php
    public function testProfileTemplateGatesExternalUrlFieldAndSsoButton(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/profile/index.twig');
        $this->assertIsString($template);

        // URL-Feld nur bei abgeschaltetem Flag.
        $this->assertStringContainsString('{% if not settings.modules.webmail %}', $template);
        $this->assertStringContainsString('name="external_webmail_url"', $template);

        // SSO-Button nur bei aktivem Flag.
        $this->assertStringContainsString(
            '{% if settings.modules.webmail and webmail_available %}',
            $template
        );

        // Externer Link-Button bei Flag aus + gesetzter URL.
        $this->assertStringContainsString('{% set _external_url =', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);
    }
```

- [ ] **Step 2: Test ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: FAIL (neuer Test)

- [ ] **Step 3: Template anpassen**

In `templates/profile/index.twig`:

(a) Nach dem `mail_badge_enabled`-Checkbox-Block (`</div>` von `form-check mb-4`, Zeile ~292) und vor dem Button-`div` einfügen:

```twig
                        {% if not settings.modules.webmail %}
                            <div class="mb-4">
                                <label for="external_webmail_url"
                                       class="form-label text-muted small fw-bold text-uppercase">
                                    Externe Webmail-URL (optional)
                                </label>
                                <input type="url"
                                       class="form-control"
                                       id="external_webmail_url"
                                       name="external_webmail_url"
                                       value="{{ mail_account.external_webmail_url|default("") }}"
                                       maxlength="255"
                                       placeholder="https://webmail.example.org">
                                <div class="form-text">
                                    Wird gesetzt, verlinkt das Mail-Badge in der Navigation auf diese Adresse.
                                </div>
                            </div>
                        {% endif %}
```

(b) Den bestehenden Block

```twig
                    {% if webmail_available %}
                        <hr>
                        <form action="/profile/webmail/start" method="post" target="_blank" rel="noopener noreferrer">
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-box-arrow-up-right"></i> Webmail öffnen
                                </button>
                            </div>
                        </form>
                    {% endif %}
```

ersetzen durch:

```twig
                    {% if settings.modules.webmail and webmail_available %}
                        <hr>
                        <form action="/profile/webmail/start" method="post" target="_blank" rel="noopener noreferrer">
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-box-arrow-up-right"></i> Webmail öffnen
                                </button>
                            </div>
                        </form>
                    {% endif %}
                    {% set _external_url = mail_account.external_webmail_url|default("") %}
                    {% if not settings.modules.webmail and _external_url != "" %}
                        <hr>
                        <div class="d-flex justify-content-end">
                            <a href="{{ _external_url }}"
                               class="btn btn-success"
                               target="_blank"
                               rel="noopener noreferrer">
                                <i class="bi bi-box-arrow-up-right"></i> Webmail öffnen
                            </a>
                        </div>
                    {% endif %}
```

Hinweis: Das bestehende SSO-Formular hat ein CSRF-Hidden-Feld, falls im Ist-Stand vorhanden — beim Ersetzen unverändert übernehmen (nur die `{% if %}`-Bedingung ändert sich).

- [ ] **Step 4: Tests + Twig-Lint ausführen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php tests/Feature/FinanceFeatureTest.php`
Expected: PASS (FinanceFeatureTest prüft andere Template-Flags mit, schadet nicht)

Run: `ddev composer twigcs`
Expected: keine Fehler. Bei Formatfehlern: `ddev composer twigcbf`, dann erneut prüfen. Ausgeführte Kommandos und Ergebnis berichten.

- [ ] **Step 5: Commit**

```bash
git add templates/profile/index.twig tests/Feature/WebmailFeatureFlagTest.php
git commit -m "feat(webmail): profile UI for external webmail URL and gated SSO button"
```

---

### Task 5: Mail-Badge — drei Fälle + Twig-Global für externe URL

**Files:**
- Modify: `src/Dependencies.php:173-190` (Badge-Global-Block in der Twig-Factory)
- Modify: `templates/partials/navigation/user_menu.twig:1-11`
- Test: `tests/Feature/WebmailFeatureFlagTest.php` (erweitern)

**Interfaces:**
- Consumes: `UserMailAccount->external_webmail_url` (Task 1); `settings.modules.webmail` (Task 2).
- Produces: Twig-Global `mail_external_webmail_url` (string|null) — nur vom Badge-Partial konsumiert.

- [ ] **Step 1: Failing Tests ergänzen**

In `tests/Feature/WebmailFeatureFlagTest.php` ergänzen:

```php
    public function testDependenciesExposeExternalWebmailUrlGlobal(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("addGlobal('mail_external_webmail_url'", $content);
    }

    public function testUserMenuBadgeCoversAllThreeWebmailCases(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/user_menu.twig');
        $this->assertIsString($template);

        // Fall 1: Flag an -> SSO-Formular.
        $this->assertStringContainsString('{% if settings.modules.webmail %}', $template);
        $this->assertStringContainsString('action="/profile/webmail/start"', $template);

        // Fall 2: Flag aus + externe URL -> Link.
        $this->assertStringContainsString('{% elseif mail_external_webmail_url %}', $template);
        $this->assertStringContainsString('href="{{ mail_external_webmail_url }}"', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);

        // Fall 3: Flag aus, keine URL -> reiner Indikator ohne Form/Link.
        $this->assertStringContainsString('title="Ungelesene Nachrichten"', $template);
    }
```

- [ ] **Step 2: Tests ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: FAIL (die zwei neuen Tests)

- [ ] **Step 3: Dependencies.php erweitern**

In `src/Dependencies.php` den bestehenden Badge-Block

```php
            // Add the current user's cached unread-mail badge count to Twig
            try {
                $mailBadgeUnseenCount = null;
                if (isset($_SESSION['user_id'])) {
                    $mailAccount = \App\Models\UserMailAccount::where('user_id', (int) $_SESSION['user_id'])
                        ->first();
                    if (
                        $mailAccount !== null
                        && $mailAccount->imap_enabled
                        && $mailAccount->mail_badge_enabled
                    ) {
                        $mailBadgeUnseenCount = (int) $mailAccount->mail_last_unseen_count;
                    }
                }
            } catch (\Exception $e) {
                $mailBadgeUnseenCount = null;
            }
            $environment->addGlobal('mail_badge_unseen_count', $mailBadgeUnseenCount);
```

ersetzen durch:

```php
            // Add the current user's cached unread-mail badge count to Twig
            try {
                $mailBadgeUnseenCount = null;
                $mailExternalWebmailUrl = null;
                if (isset($_SESSION['user_id'])) {
                    $mailAccount = \App\Models\UserMailAccount::where('user_id', (int) $_SESSION['user_id'])
                        ->first();
                    if (
                        $mailAccount !== null
                        && $mailAccount->imap_enabled
                        && $mailAccount->mail_badge_enabled
                    ) {
                        $mailBadgeUnseenCount = (int) $mailAccount->mail_last_unseen_count;
                        $mailExternalWebmailUrl = $mailAccount->external_webmail_url ?: null;
                    }
                }
            } catch (\Exception $e) {
                $mailBadgeUnseenCount = null;
                $mailExternalWebmailUrl = null;
            }
            $environment->addGlobal('mail_badge_unseen_count', $mailBadgeUnseenCount);
            $environment->addGlobal('mail_external_webmail_url', $mailExternalWebmailUrl);
```

- [ ] **Step 4: Badge-Template umbauen**

`templates/partials/navigation/user_menu.twig` — den Block Zeilen 1-11

```twig
{% if mail_badge_unseen_count is not null and mail_badge_unseen_count > 0 %}
    <form action="/profile/webmail/start" method="post" class="m-0 me-3">
        <input type="hidden" name="_csrf" value="{{ csrf_token }}">
        <button type="submit" class="btn btn-link text-white p-0 mail-badge-trigger" title="Postfach öffnen">
            <i class="bi bi-envelope-fill fs-5"></i>
            <span class="badge bg-danger rounded-pill mail-badge-count">
                {{ mail_badge_unseen_count > 99 ? "99+" : mail_badge_unseen_count }}
            </span>
        </button>
    </form>
{% endif %}
```

ersetzen durch:

```twig
{% if mail_badge_unseen_count is not null and mail_badge_unseen_count > 0 %}
    {% set _badge_label = mail_badge_unseen_count > 99 ? "99+" : mail_badge_unseen_count %}
    {% if settings.modules.webmail %}
        <form action="/profile/webmail/start" method="post" class="m-0 me-3">
            <input type="hidden" name="_csrf" value="{{ csrf_token }}">
            <button type="submit" class="btn btn-link text-white p-0 mail-badge-trigger" title="Postfach öffnen">
                <i class="bi bi-envelope-fill fs-5"></i>
                <span class="badge bg-danger rounded-pill mail-badge-count">{{ _badge_label }}</span>
            </button>
        </form>
    {% elseif mail_external_webmail_url %}
        <a href="{{ mail_external_webmail_url }}"
           class="btn btn-link text-white p-0 mail-badge-trigger me-3"
           target="_blank"
           rel="noopener noreferrer"
           title="Postfach öffnen">
            <i class="bi bi-envelope-fill fs-5"></i>
            <span class="badge bg-danger rounded-pill mail-badge-count">{{ _badge_label }}</span>
        </a>
    {% else %}
        <span class="text-white me-3 mail-badge-trigger" title="Ungelesene Nachrichten">
            <i class="bi bi-envelope-fill fs-5"></i>
            <span class="badge bg-danger rounded-pill mail-badge-count">{{ _badge_label }}</span>
        </span>
    {% endif %}
{% endif %}
```

- [ ] **Step 5: Tests + Twig-Lint ausführen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: PASS

Run: `ddev composer twigcs`
Expected: keine Fehler (sonst `ddev composer twigcbf` + erneut prüfen; Kommandos und Ergebnis berichten).

- [ ] **Step 6: Commit**

```bash
git add src/Dependencies.php templates/partials/navigation/user_menu.twig tests/Feature/WebmailFeatureFlagTest.php
git commit -m "feat(webmail): mail badge links to external webmail when flag is off"
```

---

### Task 6: Seed-Daten — externe Webmail-URL

**Files:**
- Modify: `src/Services/DevSeedService.php:1356-1391` (`seedUserMailAccounts`)
- Test: Seed-Lauf (real), kein neuer PHPUnit-Test (reine Seed-Daten, bestehender Zähler deckt Zeilen ab)

**Interfaces:**
- Consumes: `UserMailAccount` mit fillable `external_webmail_url` (Task 1).
- Produces: Dev-Daten, bei denen ein Teil der Mail-Accounts `external_webmail_url` gesetzt hat (Badge-Link-Fall im Dev sofort testbar).

- [ ] **Step 1: Seed-Methode erweitern**

In `src/Services/DevSeedService.php`, `seedUserMailAccounts()`: im `firstOrCreate`-Attribute-Array nach `'mail_badge_enabled' => true,` einfügen:

```php
                    'external_webmail_url' => $index % 2 === 0 ? 'https://webmail.example.org/' : null,
```

- [ ] **Step 2: Seed-Lauf ausführen und Report prüfen**

Run: `ddev exec php bin/dev_seed.php`

Expected: Report zeigt `user_mail_accounts`-Zähler > 0; anschließend stichprobenartig prüfen:

```bash
ddev exec 'mariadb -udb -pdb db -e "SELECT COUNT(*) AS with_url FROM user_mail_accounts WHERE external_webmail_url IS NOT NULL;"'
```

Expected: `with_url` > 0. Ergebnis berichten.

- [ ] **Step 3: Bestehende Tests gegenprüfen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php tests/Feature/ProfileExternalWebmailUrlTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/Services/DevSeedService.php
git commit -m "feat(webmail): seed external webmail URLs for dev accounts"
```

---

### Task 7: Env-Dokumentation + Prod-Compose-Durchreichung

**Files:**
- Modify: `.env.example` (SnappyMail-Abschnitte, Zeilen ~116-150)
- Modify: `dist/.env.example` (Zeilen ~30-43)
- Modify: `dist/docker-compose.prod.yml` (environment-Block der App, Zeilen ~20-21)
- Modify: `dist/README.md` (Hinweis SnappyMail optional)
- Test: `tests/Feature/WebmailFeatureFlagTest.php` (erweitern)

**Interfaces:**
- Consumes: `FEATURE_WEBMAIL`-Read aus Task 2.
- Produces: dokumentierte Env-Variable; Prod-Container erhält `FEATURE_WEBMAIL` aus dem Host-Env.

- [ ] **Step 1: Failing Test ergänzen**

In `tests/Feature/WebmailFeatureFlagTest.php` ergänzen:

```php
    public function testEnvExamplesDocumentFeatureWebmail(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_WEBMAIL=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_WEBMAIL=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_WEBMAIL: ${FEATURE_WEBMAIL:-false}', $compose);
    }
```

- [ ] **Step 2: Test ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: FAIL (neuer Test)

- [ ] **Step 3: .env.example erweitern**

In `.env.example` direkt vor dem Abschnitt `# SnappyMail / Mail-Account-Verschlüsselung` (Zeile ~116) einfügen:

```
# =========================================
# Webmail-Modul (SnappyMail)
# =========================================
# true  = eingebettetes SnappyMail-Webmail aktiv (Container + SNAPPYMAIL_SSO_SECRET nötig)
# false = kein SnappyMail nötig; optional kann jeder Benutzer im Profil die URL
#         eines externen Webmail-Clients hinterlegen (Mail-Badge verlinkt dorthin)
FEATURE_WEBMAIL=false

```

Zusätzlich im bestehenden Abschnitt `# SnappyMail Single-Sign-On (Auto-Login)` als erste Kommentarzeile ergänzen:

```
# Nur relevant bei FEATURE_WEBMAIL=true.
```

- [ ] **Step 4: dist/.env.example erweitern**

In `dist/.env.example` vor der `SNAPPYMAIL_SSO_SECRET`-Zeile einfügen:

```
# Enable the embedded SnappyMail webmail (requires the snappymail service below).
# With false the snappymail service is not needed at all; users may instead store
# an external webmail URL in their profile (the mail badge links there).
FEATURE_WEBMAIL=false
```

- [ ] **Step 5: dist/docker-compose.prod.yml erweitern**

Im gemeinsamen environment-Block (dort wo `FEATURE_SHEET_ARCHIVE` und `FEATURE_BUDGET` stehen, Zeilen ~20-21) ergänzen:

```yaml
  FEATURE_WEBMAIL: ${FEATURE_WEBMAIL:-false}
```

(Einrückung exakt an die Nachbarzeilen anpassen.)

- [ ] **Step 6: dist/README.md ergänzen**

Im SnappyMail-/Webmail-Abschnitt von `dist/README.md` (per `grep -n -i snappymail dist/README.md` lokalisieren) einen Hinweis ergänzen:

```markdown
> **Optional:** SnappyMail ist per `FEATURE_WEBMAIL` steuerbar (Default `false`).
> Bei `FEATURE_WEBMAIL=false` kann der `snappymail`-Service samt
> `SNAPPYMAIL_SSO_SECRET` komplett entfallen; Benutzer können stattdessen im
> Profil eine externe Webmail-URL hinterlegen, auf die das Mail-Badge verlinkt.
```

- [ ] **Step 7: Test ausführen, Erfolg verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add .env.example dist/.env.example dist/docker-compose.prod.yml dist/README.md tests/Feature/WebmailFeatureFlagTest.php
git commit -m "docs(webmail): document FEATURE_WEBMAIL and optional snappymail service"
```

---

### Task 8: Gesamtverifikation

**Files:**
- Keine neuen; nur Prüfläufe.

**Interfaces:**
- Consumes: alle vorherigen Tasks.
- Produces: verifizierter Endzustand.

- [ ] **Step 1: Volle Testsuite**

Run: `ddev exec ./vendor/bin/phpunit`
Expected: alle Tests grün — mit einer bekannten Ausnahme: `UploadLimitFeatureTest` schlägt bereits auf `main` fehl (docker-compose.prod.yml-Drift, `CLIENT_MAX_BODY_SIZE`), unabhängig von dieser Änderung. Jeden anderen Fehlschlag untersuchen und beheben. Ergebnis exakt berichten.

- [ ] **Step 2: PHP- und Twig-Qualitätsgates**

Run: `ddev composer phpcs`
Expected: keine Verstöße (sonst `ddev composer phpcbf` + erneut prüfen).

Run: `ddev composer twigcs`
Expected: keine Verstöße (sonst `ddev composer twigcbf` + erneut prüfen).

- [ ] **Step 3: Flag-Verhalten manuell per Route prüfen**

```bash
grep -n "FEATURE_WEBMAIL" .env || echo "FEATURE_WEBMAIL nicht in .env (Default false aktiv)"
```

Danach in `.env` `FEATURE_WEBMAIL=true` setzen und wieder auf `false` zurück — jeweils per `ddev exec curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost/profile/webmail/start` prüfen: bei `false` Erwartung `404` (bzw. Redirect auf Login `302` — beides belegt, dass die Route-/Auth-Kette greift und kein 500 entsteht), bei `true` `302`. Ergebnis berichten. Abschließend `.env` im Ursprungszustand hinterlassen.

- [ ] **Step 4: Abschlussbericht**

Zusammenfassen: geänderte Dateien, ausgeführte Kommandos, Migrationsausgang, Seed-Report-Zahlen, Testergebnisse.

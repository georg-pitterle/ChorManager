# Newsletter-Projektentkopplung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Newsletter lösen sich von der Projektmitgliedschaft – das Recht `can_manage_newsletters` genügt, der Projektbezug wird optional, und der Versand ist gesperrt, solange kein Empfänger aufgelöst wird.

**Architecture:** Das Zugangs-Gate sitzt heute allein in `NewsletterController::getAccessibleProjects()`; es wird durch eine Methode ersetzt, die alle Projekte liefert, wodurch die vier `canAccess*`-Helfer und ihre 403-Zweige entfallen. Eine Phinx-Migration macht `newsletters.project_id` nullable. Die Empfängerpflicht wandert vom Speichern in den Versand und wird an der aufgelösten Personenzahl gemessen. Die Vorlagenverwaltung zieht in einen eigenen `NewsletterTemplateController` bei unveränderten URLs.

**Tech Stack:** PHP 8 (Slim 4, PHP-DI Autowiring), Eloquent (Illuminate Database), Phinx, Twig, PHPUnit 10, Bootstrap 5 + TinyMCE + Tom Select im Frontend, DDEV als Laufzeitumgebung.

**Spec:** `docs/superpowers/specs/2026-08-11-newsletter-projektentkopplung-design.md`

## Global Constraints

- Alle Befehle laufen über DDEV: `ddev exec …`, `ddev php …`, `ddev composer …`. Kein direkter PHP-Aufruf auf dem Windows-Host.
- Repository-Textdateien werden mit LF gespeichert. Nach jedem Schreiben auf Windows normalisieren:
  `$f = "<absoluter-pfad>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
- PHP folgt PSR-12, 4 Leerzeichen, Zeilenlänge weich 120 / hart 130. Prüfung: `ddev composer phpcs`, Korrektur: `ddev composer phpcbf`.
- Twig: doppelte Anführungszeichen, benannte Argumente ohne Leerzeichen um `=`, binäre Operatoren mit genau einem Leerzeichen, keine mehrzeiligen Booleschen Ausdrücke (Teilausdrücke in `{% set %}` auslagern). Prüfung: `ddev composer twigcs`.
- Keine Inline-Skripte und keine `style="…"`-Attribute in Templates; JS und CSS liegen in eigenen Dateien unter `public/js/` bzw. `public/css/style.css`.
- Logging über `Psr\Log\LoggerInterface` mit `event`-Schlüssel im Kontext; kein `error_log()` in `src/`.
- Schemaänderungen ausschließlich über Phinx: `ddev exec ./vendor/bin/phinx migrate`.
- Kein `git push`. Commits bleiben lokal auf dem Branch `feature/newsletter-projektentkopplung`.
- Testlauf: `ddev exec ./vendor/bin/phpunit` (voll) oder gezielt `ddev exec ./vendor/bin/phpunit --filter <TestName> tests/Feature/<Datei>.php`.
- Deutsche Texte verwenden echte Umlaute (ä/ö/ü/ß), keine Umschreibungen.
- Hilfetexte nennen niemals Rollennamen, sondern das Recht-Label **"Newsletter verwalten"**.

## File Structure

**Neu:**
- `db/migrations/20260811190000_allow_newsletters_without_project.php` – macht `newsletters.project_id` nullable, FK auf `SET NULL`
- `src/Controllers/NewsletterTemplateController.php` – gesamte Vorlagenverwaltung
- `src/Exceptions/NewsletterWithoutRecipientsException.php` – benannte Versandregel
- `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` – Verhaltenstests der neuen Regeln

**Geändert:**
- `src/Controllers/NewsletterController.php` – Gate entfernen, Filter umbauen, Versandantwort, Template-Methoden entfernen
- `src/Services/NewsletterService.php` – Versandregel bei 0 Empfängern
- `src/Routes.php` – Vorlagenrouten auf den neuen Controller
- `src/Services/DevSeedService.php` – Seed-Daten für projektlose Newsletter und weitere Quelltypen
- `templates/newsletters/index.twig` – Filter, Projektspalte, Aktionslinks
- `templates/newsletters/create.twig`, `edit.twig` – Projekt optional, Vorlagen gruppiert
- `public/js/newsletters-create.js`, `public/js/newsletters-edit.js` – leeres Projekt, Versenden-Sperre
- `tests/Feature/NewsletterFeatureTest.php`, `NewsletterSecurityHardeningFeatureTest.php`, `NewsletterTemplateManagementFeatureTest.php` – an neue Regeln angepasst
- `help/newsletter/docs/*.md` – Hilfetexte

**Gelöscht:**
- `src/Middleware/NewsletterAuthMiddleware.php` – toter Code, nirgends registriert

---

### Task 1: Migration – Newsletter ohne Projekt

**Files:**
- Create: `db/migrations/20260811190000_allow_newsletters_without_project.php`
- Create: `tests/Feature/NewsletterProjectDecouplingFeatureTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: Spalte `newsletters.project_id` ist `NULL`-fähig; Fremdschlüssel `newsletters_ibfk_1` löscht nicht mehr kaskadierend, sondern setzt auf `NULL`. Die Testklasse `Tests\Feature\NewsletterProjectDecouplingFeatureTest` mit den privaten Helfern `createUser()`, `createProject()` und `createDraft()` wird in späteren Tasks erweitert.

- [ ] **Step 1: Write the failing test**

Datei `tests/Feature/NewsletterProjectDecouplingFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\Project;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Newsletter haengen nicht mehr an der Projektmitgliedschaft: das Recht genuegt,
 * der Projektbezug ist optional, und versendet wird nur mit Empfaengern.
 */
final class NewsletterProjectDecouplingFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function createUser(bool $active = true): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "decoupling_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function createProject(): Project
    {
        return Project::create([
            'name' => 'Entkopplung ' . bin2hex(random_bytes(4)),
        ]);
    }

    private function createDraft(?Project $project, User $creator): Newsletter
    {
        $newsletter = Newsletter::create([
            'project_id' => $project?->id,
            'title' => 'Entwurf ' . bin2hex(random_bytes(4)),
            'content_html' => '<p>Hallo Chor!</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        if ($project !== null) {
            NewsletterRecipientSource::create([
                'newsletter_id' => $newsletter->id,
                'source_type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
                'reference_id' => $project->id,
            ]);
        }

        return $newsletter;
    }

    public function testNewsletterCanBeStoredWithoutProject(): void
    {
        $creator = $this->createUser();

        $newsletter = $this->createDraft(null, $creator);

        $this->assertNull($newsletter->fresh()->project_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter testNewsletterCanBeStoredWithoutProject tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: FAIL mit `SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'project_id' cannot be null`

- [ ] **Step 3: Write the migration**

Datei `db/migrations/20260811190000_allow_newsletters_without_project.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AllowNewslettersWithoutProject extends AbstractMigration
{
    public function up(): void
    {
        // Der Fremdschluessel muss weichen, bevor die Spalte veraendert werden kann.
        $this->table('newsletters')
            ->dropForeignKey('project_id')
            ->update();

        $this->table('newsletters')
            ->changeColumn('project_id', 'integer', ['null' => true])
            ->update();

        // SET NULL statt CASCADE: Ein geloeschtes Projekt darf die Versandhistorie
        // nicht mitnehmen, der Newsletter wird stattdessen projektlos.
        $this->table('newsletters')
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->execute('DELETE FROM newsletters WHERE project_id IS NULL');

        $this->table('newsletters')
            ->dropForeignKey('project_id')
            ->update();

        $this->table('newsletters')
            ->changeColumn('project_id', 'integer', ['null' => false])
            ->update();

        $this->table('newsletters')
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->update();
    }
}
```

- [ ] **Step 4: Run the migration**

Run: `ddev exec ./vendor/bin/phinx migrate`
Expected: `== AllowNewslettersWithoutProject: migrated` ohne Fehlermeldung

- [ ] **Step 5: Verify the schema**

Run: `ddev mysql -uroot -proot -e "SHOW CREATE TABLE newsletters\G" db`
Expected: Ausgabe enthält `` `project_id` int(11) DEFAULT NULL `` und `ON DELETE SET NULL`

- [ ] **Step 6: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter testNewsletterCanBeStoredWithoutProject tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: PASS (1 test, 1 assertion)

- [ ] **Step 7: Commit**

```bash
git add db/migrations/20260811190000_allow_newsletters_without_project.php tests/Feature/NewsletterProjectDecouplingFeatureTest.php
git commit -m "feat(newsletter): Newsletter ohne Projektbezug erlauben"
```

---

### Task 2: Zugangs-Gate durch Rechteprüfung ersetzen

**Files:**
- Modify: `src/Controllers/NewsletterController.php` (Methoden `getAccessibleProjects`, `getAccessibleProjectIds`, `canAccessNewsletterById`, `canAccessTemplateContext`, `index`, `create`, `store`, `edit`, `update`, `send`, `preview`, `deleteDraft`, `checkLock`, `releaseLock`, `saveAsTemplate`, `getTemplate`, `listTemplates`, `createTemplate`, `editTemplate`, `updateTemplate`, `cloneTemplate`, `resolveRecipientsPreview`)
- Delete: `src/Middleware/NewsletterAuthMiddleware.php`
- Modify: `tests/Feature/NewsletterFeatureTest.php:55-58`
- Modify: `tests/Feature/NewsletterProjectDecouplingFeatureTest.php`

**Interfaces:**
- Consumes: `Tests\Feature\NewsletterProjectDecouplingFeatureTest::createUser()`, `createProject()`, `createDraft()` aus Task 1
- Produces:
  - `NewsletterController::selectableProjects(): \Illuminate\Support\Collection` – alle Projekte nach Namen sortiert (ersetzt `getAccessibleProjects()`)
  - `NewsletterController::canManageNewsletters(): bool` – liest `$_SESSION['can_manage_newsletters']`
  - Testhelfer `newsletterController(): NewsletterController` in der Testklasse

- [ ] **Step 1: Write the failing tests**

An `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` anfügen – zuerst die Imports oben in der Datei ergänzen:

```php
use App\Controllers\NewsletterController;
use App\Models\NewsletterArchive;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use App\Persistence\NewsletterTemplatePersistence;
use App\Queries\NewsletterTemplateQuery;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
```

Dann diese Methoden in die Klasse einfügen:

```php
    private function newsletterController(): NewsletterController
    {
        return new NewsletterController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new NewsletterService(
                new NewsletterRecipientService(),
                new Mailer(new NullLogger()),
                new HtmlSanitizer(),
                new MailQueueService(),
                new NullLogger()
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NewsletterTemplateQuery(),
            new NewsletterTemplatePersistence(),
            new NullLogger(),
            new NameFormatterService()
        );
    }

    public function testEditIsAllowedForManagerWithoutProjectMembership(): void
    {
        $outsider = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $outsider);

        $_SESSION['user_id'] = (int) $outsider->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->edit(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/edit'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSendIsAllowedForManagerWithoutProjectMembership(): void
    {
        $manager = $this->createUser();
        $member = $this->createUser();
        $project = $this->createProject();
        $project->users()->attach([$member->id]);
        $newsletter = $this->createDraft($project, $manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;
        $_ENV['DISABLE_MAIL_SEND'] = $_SERVER['DISABLE_MAIL_SEND'] = 'true';

        $response = $this->newsletterController()->send(
            $this->makeRequest('POST', '/newsletters/' . $newsletter->id . '/send')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(Newsletter::STATUS_SENT, $newsletter->fresh()->status);
    }

    public function testPreviewIsForbiddenWithoutRightAndWithoutArchiveEntry(): void
    {
        $stranger = $this->createUser();
        $creator = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $creator);

        $_SESSION['user_id'] = (int) $stranger->id;
        $_SESSION['can_manage_newsletters'] = false;

        $response = $this->newsletterController()->preview(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/preview')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPreviewIsAllowedForManagerWithoutArchiveEntry(): void
    {
        $manager = $this->createUser();
        $creator = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $creator);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->preview(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/preview')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPreviewIsAllowedForRecipientWithoutRight(): void
    {
        $recipient = $this->createUser();
        $creator = $this->createUser();
        $project = $this->createProject();
        $newsletter = $this->createDraft($project, $creator);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $response = $this->newsletterController()->preview(
            $this->makeRequest('GET', '/newsletters/' . $newsletter->id . '/preview')
                ->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }
```

Zusätzlich in `tests/Feature/NewsletterFeatureTest.php` die Methode `testAuthMiddlewareExists()` samt ihres Docblocks (Zeilen 51–58) ersatzlos löschen – die Middleware verschwindet in diesem Task.

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterProjectDecouplingFeatureTest tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: FAIL – `testEditIsAllowedForManagerWithoutProjectMembership` und `testSendIsAllowedForManagerWithoutProjectMembership` erhalten 403, `testPreviewIsAllowedForRecipientWithoutRight` läuft bereits grün

- [ ] **Step 3: Replace the gate in the controller**

In `src/Controllers/NewsletterController.php` die Methoden `getAccessibleProjects()`, `getAccessibleProjectIds()`, `canAccessNewsletterById()` und `canAccessTemplateContext()` löschen und stattdessen einfügen:

```php
    /**
     * Alle Projekte als Auswahl. Der Zugang zum Modul wird allein ueber das Recht
     * can_manage_newsletters geregelt, nicht ueber die Projektmitgliedschaft.
     *
     * @return \Illuminate\Support\Collection<int, Project>
     */
    private function selectableProjects()
    {
        return Project::query()->orderBy('name')->get();
    }

    private function canManageNewsletters(): bool
    {
        return (bool) ($_SESSION['can_manage_newsletters'] ?? false);
    }
```

Alle Aufrufe von `$this->getAccessibleProjects($userId)` durch `$this->selectableProjects()` ersetzen. Sämtliche Blöcke löschen, die aus `getAccessibleProjectIds()`, `canAccessNewsletterById()` oder `canAccessTemplateContext()` einen 403 ableiten – konkret in `index()`, `create()`, `store()`, `edit()`, `update()`, `send()`, `deleteDraft()`, `checkLock()`, `releaseLock()`, `saveAsTemplate()`, `getTemplate()`, `listTemplates()`, `createTemplate()`, `editTemplate()`, `updateTemplate()`, `cloneTemplate()` und `resolveRecipientsPreview()`.

In `validateNewsletterSourcesInput()` entfällt der Parameter `$accessibleProjectIds` samt der Prüfung:

```php
    private function validateNewsletterSourcesInput(array $data): array
```

und im Rumpf statt der bisherigen Projektprüfung nur noch:

```php
            if ($type === NewsletterRecipientSource::TYPE_PROJECT_MEMBERS) {
                if (!Project::query()->where('id', $referenceId)->exists()) {
                    continue;
                }
            }
```

Die Aufrufer rufen entsprechend `$this->validateNewsletterSourcesInput($data)` auf.

In `listTemplates()` liefert der Query künftig alle Vorlagen:

```php
        $templates = NewsletterTemplate::query()->orderBy('name')->get();
```

In `create()` entfällt die Projektfilterung der Vorlagen:

```php
        $templates = NewsletterTemplate::query()->orderBy('name')->get();
```

`preview()` prüft neu:

```php
        if (!$this->canManageNewsletters() && !$this->canAccessReceivedNewsletterById($id, $userId)) {
            return $response->withStatus(403);
        }
```

`canAccessReceivedNewsletterById()` bleibt unverändert erhalten.

- [ ] **Step 4: Delete the dead middleware**

```bash
git rm src/Middleware/NewsletterAuthMiddleware.php
```

- [ ] **Step 5: Verify nothing references the deleted class**

Run: `ddev exec grep -rn "NewsletterAuthMiddleware" src/ tests/ || echo "keine Treffer"`
Expected: `keine Treffer`

- [ ] **Step 6: Run tests to verify they pass**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterProjectDecouplingFeatureTest tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: PASS (6 tests)

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/NewsletterFeatureTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A src/Controllers/NewsletterController.php src/Middleware tests/Feature
git commit -m "feat(newsletter): Zugang allein ueber das Recht statt Projektmitgliedschaft"
```

---

### Task 3: Vorlagenverwaltung in eigenen Controller

**Files:**
- Create: `src/Controllers/NewsletterTemplateController.php`
- Modify: `src/Controllers/NewsletterController.php` (Template-Methoden und deren Abhängigkeiten entfernen)
- Modify: `src/Routes.php:531-565`
- Modify: `tests/Feature/NewsletterTemplateManagementFeatureTest.php`
- Modify: `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` (Helfer `newsletterController()`)

**Interfaces:**
- Consumes: `NewsletterTemplateQuery::findById()`, `NewsletterTemplatePersistence::createTemplate()`, `updateTemplate()`, `cloneTemplate()`, `HtmlSanitizer::sanitizeNewsletterHtml()`
- Produces: `App\Controllers\NewsletterTemplateController` mit den öffentlichen Methoden `index`, `store`, `edit`, `update`, `clone`, `show`, `storeFromNewsletter`. Konstruktor: `__construct(Twig $view, HtmlSanitizer $htmlSanitizer, NewsletterTemplateQuery $templateQuery, NewsletterTemplatePersistence $templatePersistence)`. `NewsletterController` verliert die Konstruktorparameter `NewsletterTemplateQuery` und `NewsletterTemplatePersistence` und hat danach die Signatur `__construct(Twig $view, NewsletterService $newsletterService, NewsletterLockingService $lockingService, NewsletterRecipientService $recipientService, HtmlSanitizer $htmlSanitizer, LoggerInterface $logger, NameFormatterService $nameFormatter)`.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/NewsletterTemplateManagementFeatureTest.php` die Methoden `testTemplateManagementRoutesAreRegistered()`, `testNewsletterControllerExposesTemplateManagementActions()`, `testUpdateTemplateRejectsEmptyPayloadWith422()`, `testUpdateTemplateReturns404ForMissingTemplate()`, `testCloneTemplateReturns201AndCloneId()` und `testTemplateActionsSupportRedirectFallbackForNonAjaxRequests()` ersetzen durch:

```php
    public function testTemplateManagementRoutesArePointedAtTemplateController(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString(
            "'/newsletters/templates', [NewsletterTemplateController::class, 'index']",
            $routes
        );
        $this->assertStringContainsString(
            "'/newsletters/templates', [NewsletterTemplateController::class, 'store']",
            $routes
        );
        $this->assertStringContainsString("[NewsletterTemplateController::class, 'clone']", $routes);
        $this->assertStringContainsString("[NewsletterTemplateController::class, 'storeFromNewsletter']", $routes);
    }

    public function testTemplateControllerExposesTemplateManagementActions(): void
    {
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'store'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'edit'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'clone'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'show'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterTemplateController::class, 'storeFromNewsletter'));
    }

    public function testNewsletterControllerNoLongerHandlesTemplates(): void
    {
        $this->assertFalse(method_exists(\App\Controllers\NewsletterController::class, 'listTemplates'));
        $this->assertFalse(method_exists(\App\Controllers\NewsletterController::class, 'createTemplate'));
        $this->assertFalse(method_exists(\App\Controllers\NewsletterController::class, 'cloneTemplate'));
    }

    public function testTemplateActionsSupportRedirectFallbackForNonAjaxRequests(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterTemplateController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString("'/newsletters/templates/' . \$template->id . '/edit'", $controller);
        $this->assertStringContainsString('Vorlage gespeichert', $controller);
        $this->assertStringContainsString('Vorlage geklont', $controller);
    }
```

Zusätzlich in `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` anfügen – Vorlagen fremder Projekte müssen nutzbar sein. Dazu oben die Importe `use App\Controllers\NewsletterTemplateController;` und `use App\Models\NewsletterTemplate;` ergänzen:

```php
    private function templateController(): NewsletterTemplateController
    {
        return new NewsletterTemplateController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new HtmlSanitizer(),
            new NewsletterTemplateQuery(),
            new NewsletterTemplatePersistence()
        );
    }

    private function createProjectTemplate(Project $project, User $creator): NewsletterTemplate
    {
        return NewsletterTemplate::create([
            'name' => 'Projektvorlage ' . bin2hex(random_bytes(4)),
            'description' => 'Testvorlage',
            'content_html' => '<p>Vorlageninhalt</p>',
            'project_id' => $project->id,
            'created_by' => $creator->id,
        ]);
    }

    public function testForeignProjectTemplateCanBeEditedAndCloned(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $project = $this->createProject();
        $template = $this->createProjectTemplate($project, $owner);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $editResponse = $this->templateController()->edit(
            $this->makeRequest('GET', '/newsletters/templates/' . $template->id . '/edit')
                ->withAttribute('id', (string) $template->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $editResponse->getStatusCode());

        $cloneResponse = $this->templateController()->clone(
            $this->makeRequest('POST', '/newsletters/templates/' . $template->id . '/clone', [], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->withAttribute('id', (string) $template->id),
            $this->makeResponse()
        );

        $this->assertSame(201, $cloneResponse->getStatusCode());
    }

    public function testForeignProjectTemplateCanBeLoadedIntoAnyNewsletter(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $project = $this->createProject();
        $template = $this->createProjectTemplate($project, $owner);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->templateController()->show(
            $this->makeRequest('GET', '/newsletters/template/' . $template->id)
                ->withAttribute('id', (string) $template->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame((int) $template->id, (int) $payload['id']);
    }
```

Die in Task 2 entfernten Importe `NewsletterTemplateQuery` und `NewsletterTemplatePersistence` werden für diesen Helfer wieder gebraucht und bleiben in der Testdatei erhalten – entfernt wird in Schritt 6 nur ihre Verwendung im `NewsletterController`-Aufruf.

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/NewsletterTemplateManagementFeatureTest.php`
Expected: FAIL mit `Class "App\Controllers\NewsletterTemplateController" does not exist`

- [ ] **Step 3: Create the template controller**

Datei `src/Controllers/NewsletterTemplateController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Newsletter;
use App\Models\NewsletterTemplate;
use App\Models\Project;
use App\Persistence\NewsletterTemplatePersistence;
use App\Queries\NewsletterTemplateQuery;
use App\Services\HtmlSanitizer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Verwaltung der Newsletter-Vorlagen. Der Zugang haengt allein am Recht
 * can_manage_newsletters, das die Routengruppe absichert.
 */
class NewsletterTemplateController
{
    private Twig $view;
    private HtmlSanitizer $htmlSanitizer;
    private NewsletterTemplateQuery $templateQuery;
    private NewsletterTemplatePersistence $templatePersistence;

    public function __construct(
        Twig $view,
        HtmlSanitizer $htmlSanitizer,
        NewsletterTemplateQuery $templateQuery,
        NewsletterTemplatePersistence $templatePersistence
    ) {
        $this->view = $view;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->templateQuery = $templateQuery;
        $this->templatePersistence = $templatePersistence;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    private function expectsJson(Request $request): bool
    {
        $xRequestedWith = strtolower(trim($request->getHeaderLine('X-Requested-With')));
        if ($xRequestedWith === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool, payload:array<string, string>}
     */
    private function validateTemplateInput(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $contentHtml = $this->htmlSanitizer->sanitizeNewsletterHtml($data['content_html'] ?? '');
        $description = trim((string) ($data['description'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255 || $contentHtml === '') {
            return ['ok' => false, 'payload' => []];
        }

        return [
            'ok' => true,
            'payload' => [
                'name' => $name,
                'content_html' => $contentHtml,
                'description' => $description,
            ],
        ];
    }

    public function index(Request $request, Response $response): Response
    {
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'newsletters/templates_index.twig', [
            'projects' => Project::query()->orderBy('name')->get(),
            'templates' => NewsletterTemplate::query()->orderBy('name')->get(),
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $validation = $this->validateTemplateInput($data);
        if (!$validation['ok']) {
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = 'Ungültige Vorlagendaten';
                return $response->withHeader('Location', '/newsletters/templates')->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => 'Ungültige Vorlagendaten'], 422);
        }

        $projectId = null;
        if (($data['project_id'] ?? '') !== '') {
            $projectId = (int) $data['project_id'];
        }

        $template = $this->templatePersistence->createTemplate($validation['payload'], $userId, $projectId);

        if (!$this->expectsJson($request)) {
            $_SESSION['success'] = 'Vorlage erstellt';
            return $response
                ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                ->withStatus(302);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'template_id' => $template->id,
            'redirect' => '/newsletters/templates/' . $template->id . '/edit',
        ], 201);
    }

    public function edit(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $isModal = ((string) ($request->getQueryParams()['modal'] ?? '0')) === '1';

        return $this->view->render($response, 'newsletters/templates_edit.twig', [
            'template' => $template,
            'is_modal' => $isModal,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $validation = $this->validateTemplateInput((array) $request->getParsedBody());
        if (!$validation['ok']) {
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = 'Ungültige Vorlagendaten';
                return $response
                    ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                    ->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => 'Ungültige Vorlagendaten'], 422);
        }

        $this->templatePersistence->updateTemplate($template, $validation['payload']);
        $_SESSION['success'] = 'Vorlage gespeichert';

        if (!$this->expectsJson($request)) {
            return $response
                ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                ->withStatus(302);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }

    public function clone(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withStatus(403);
        }

        $clone = $this->templatePersistence->cloneTemplate($template, $userId);

        if (!$this->expectsJson($request)) {
            $_SESSION['success'] = 'Vorlage geklont';
            return $response
                ->withHeader('Location', '/newsletters/templates/' . $clone->id . '/edit')
                ->withStatus(302);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'template_id' => $clone->id,
            'redirect' => '/newsletters/templates/' . $clone->id . '/edit',
        ], 201);
    }

    public function show(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        return $this->jsonResponse($response, [
            'id' => $template->id,
            'name' => $template->name,
            'content_html' => $template->content_html,
        ]);
    }

    public function storeFromNewsletter(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $newsletter = Newsletter::find($id);

        if (!$newsletter) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $templateName = trim((string) ($data['template_name'] ?? $newsletter->title));
        $templateDescription = trim((string) ($data['template_description'] ?? ''));
        $templateContentHtml = $this->htmlSanitizer->sanitizeNewsletterHtml($newsletter->content_html);

        if ($templateName === '' || mb_strlen($templateName) > 255 || trim(strip_tags($templateContentHtml)) === '') {
            if ($this->expectsJson($request)) {
                return $this->jsonResponse($response, ['error' => 'Ungültige Vorlagendaten.'], 422);
            }

            $_SESSION['error'] = 'Ungültige Vorlagendaten.';
            return $response->withHeader('Location', '/newsletters/' . $id . '/edit')->withStatus(302);
        }

        $template = $this->templatePersistence->createTemplate(
            [
                'name' => $templateName,
                'description' => $templateDescription,
                'content_html' => $templateContentHtml,
            ],
            $userId,
            $newsletter->project_id === null ? null : (int) $newsletter->project_id
        );

        if (!$this->expectsJson($request)) {
            $_SESSION['success'] = 'Vorlage gespeichert';
            return $response
                ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                ->withStatus(302);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'template_id' => $template->id,
        ], 201);
    }
}
```

- [ ] **Step 4: Strip the template methods from NewsletterController**

Aus `src/Controllers/NewsletterController.php` löschen: `saveAsTemplate()`, `getTemplate()`, `listTemplates()`, `createTemplate()`, `editTemplate()`, `updateTemplate()`, `cloneTemplate()` und `validateTemplateInput()`. Konstruktor und Eigenschaften auf die verbleibenden Abhängigkeiten kürzen:

```php
    public function __construct(
        Twig $view,
        NewsletterService $newsletterService,
        NewsletterLockingService $lockingService,
        NewsletterRecipientService $recipientService,
        HtmlSanitizer $htmlSanitizer,
        LoggerInterface $logger,
        NameFormatterService $nameFormatter
    ) {
        $this->view = $view;
        $this->newsletterService = $newsletterService;
        $this->lockingService = $lockingService;
        $this->recipientService = $recipientService;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->logger = $logger;
        $this->nameFormatter = $nameFormatter;
    }
```

Die Eigenschaften `$templateQuery` und `$templatePersistence` sowie die Importe `use App\Queries\NewsletterTemplateQuery;` und `use App\Persistence\NewsletterTemplatePersistence;` entfallen. `use App\Models\NewsletterTemplate;` bleibt, weil `create()` weiterhin Vorlagen für das Auswahlfeld lädt.

- [ ] **Step 5: Repoint the routes**

In `src/Routes.php` den Import ergänzen:

```php
use App\Controllers\NewsletterTemplateController;
```

und die betroffenen Routen ersetzen:

```php
                        $newsletterGroup->post(
                            '/newsletters/{id:[0-9]+}/save-as-template',
                            [NewsletterTemplateController::class, 'storeFromNewsletter']
                        );
                        $newsletterGroup->get(
                            '/newsletters/template/{id:[0-9]+}',
                            [NewsletterTemplateController::class, 'show']
                        );
```

sowie im Vorlagenblock:

```php
                        // Newsletter template management
                        $newsletterGroup->get(
                            '/newsletters/templates',
                            [NewsletterTemplateController::class, 'index']
                        );
                        $newsletterGroup->post(
                            '/newsletters/templates',
                            [NewsletterTemplateController::class, 'store']
                        );
                        $newsletterGroup->get(
                            '/newsletters/templates/{id:[0-9]+}/edit',
                            [NewsletterTemplateController::class, 'edit']
                        );
                        $newsletterGroup->post(
                            '/newsletters/templates/{id:[0-9]+}',
                            [NewsletterTemplateController::class, 'update']
                        );
                        $newsletterGroup->post(
                            '/newsletters/templates/{id:[0-9]+}/clone',
                            [NewsletterTemplateController::class, 'clone']
                        );
```

Ein Container-Eintrag ist nicht nötig: Controller werden über das Autowiring von PHP-DI aufgelöst, wie schon beim `NewsletterController`.

- [ ] **Step 6: Update the test helper**

In `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` die Methode `newsletterController()` auf die neue Signatur kürzen (die Importe `NewsletterTemplateQuery` und `NewsletterTemplatePersistence` bleiben, weil `templateController()` sie braucht):

```php
    private function newsletterController(): NewsletterController
    {
        return new NewsletterController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new NewsletterService(
                new NewsletterRecipientService(),
                new Mailer(new NullLogger()),
                new HtmlSanitizer(),
                new MailQueueService(),
                new NullLogger()
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService()
        );
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/NewsletterTemplateManagementFeatureTest.php tests/Feature/NewsletterProjectDecouplingFeatureTest.php tests/Feature/NewsletterFeatureFlagTest.php`
Expected: PASS in allen drei Dateien

- [ ] **Step 8: Verify the routes still resolve**

Run: `ddev exec curl -s -o /dev/null -w "%{http_code}\n" https://chormanager.ddev.site/newsletters/templates`
Expected: `302` (Weiterleitung zum Login, keine 500)

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/NewsletterTemplateController.php src/Controllers/NewsletterController.php src/Routes.php tests/Feature
git commit -m "refactor(newsletter): Vorlagenverwaltung in eigenen Controller"
```

---

### Task 4: Listenfilter für alle und projektlose Newsletter

**Files:**
- Modify: `src/Controllers/NewsletterController.php` (`index`)
- Modify: `templates/newsletters/index.twig`
- Modify: `tests/Feature/NewsletterProjectDecouplingFeatureTest.php`

**Interfaces:**
- Consumes: `NewsletterController::selectableProjects()` aus Task 2
- Produces: `index()` versteht den Query-Parameter `project_id` mit den Werten `''` (alle), `none` (ohne Projekt) und einer numerischen ID; das Template erhält zusätzlich die Variable `project_filter` (string) und rendert keine Projekt-Vorbelegung mehr.

- [ ] **Step 1: Write the failing test**

An `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` anfügen:

```php
    public function testIndexWithoutProjectFilterListsDraftsFromAllProjects(): void
    {
        $manager = $this->createUser();
        $projectA = $this->createProject();
        $projectB = $this->createProject();
        $this->createDraft($projectA, $manager);
        $this->createDraft($projectB, $manager);
        $this->createDraft(null, $manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->index(
            $this->makeRequest('GET', '/newsletters'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexFilterNoneKeepsOnlyProjectlessDrafts(): void
    {
        $manager = $this->createUser();
        $project = $this->createProject();
        $withProject = $this->createDraft($project, $manager);
        $withoutProject = $this->createDraft(null, $manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $matching = Newsletter::query()
            ->whereNull('project_id')
            ->where('status', Newsletter::STATUS_DRAFT)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $withoutProject->id, $matching);
        $this->assertNotContains((int) $withProject->id, $matching);

        $response = $this->newsletterController()->index(
            $this->makeRequest('GET', '/newsletters', [], ['project_id' => 'none']),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter testIndexWithoutProjectFilterListsDraftsFromAllProjects tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: FAIL – der Controller antwortet mit 302 (Weiterleitung auf das erste Projekt) statt 200

- [ ] **Step 3: Rewrite index()**

`index()` in `src/Controllers/NewsletterController.php` vollständig ersetzen:

```php
    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $userId = $_SESSION['user_id'] ?? null;
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        $projects = $this->selectableProjects();

        $status = (string) ($queryParams['status'] ?? Newsletter::STATUS_DRAFT);
        if (!in_array($status, Newsletter::SUPPORTED_STATUSES, true)) {
            $status = Newsletter::STATUS_DRAFT;
        }

        $recipientType = trim((string) ($queryParams['recipient_type'] ?? ''));
        $allowedRecipientTypes = [
            NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
            NewsletterRecipientSource::TYPE_EVENT_ATTENDEES,
            NewsletterRecipientSource::TYPE_ROLE,
            NewsletterRecipientSource::TYPE_USER,
        ];
        if (!in_array($recipientType, $allowedRecipientTypes, true)) {
            $recipientType = '';
        }

        // '' = alle Projekte, 'none' = ohne Projekt, sonst eine konkrete ID.
        // Unbekannte Werte fallen auf 'alle' zurueck.
        $projectFilter = trim((string) ($queryParams['project_id'] ?? ''));
        if ($projectFilter !== '' && $projectFilter !== 'none' && !ctype_digit($projectFilter)) {
            $projectFilter = '';
        }

        $query = Newsletter::query()
            ->where('status', $status)
            ->with(['createdBy', 'project']);

        if ($projectFilter === 'none') {
            $query->whereNull('project_id');
        } elseif ($projectFilter !== '') {
            $query->where('project_id', (int) $projectFilter);
        }

        if ($recipientType !== '') {
            $query->whereHas('recipientSources', function ($sourceQuery) use ($recipientType) {
                $sourceQuery->where('source_type', $recipientType);
            });
        }

        if ($status === Newsletter::STATUS_SENT) {
            $query->orderBy('sent_at', 'desc');
        }

        $newsletters = $query->orderBy('created_at', 'desc')->get();

        return $this->view->render($response, 'newsletters/index.twig', [
            'newsletters' => $newsletters,
            'projects' => $projects,
            'project_filter' => $projectFilter,
            'status' => $status,
            'recipient_type' => $recipientType,
            'user_id' => $userId,
            'success' => $success,
            'error' => $error,
        ]);
    }
```

- [ ] **Step 4: Update the template header and filter**

In `templates/newsletters/index.twig` den Kopfbereich (Zeilen 12–36) ersetzen:

```twig
            {% if status == "sent" %}
                <p class="text-muted mb-0">Alle versendeten Newsletter.</p>
            {% else %}
                <p class="text-muted mb-0">Entwürfe erstellen, bearbeiten und versenden.</p>
            {% endif %}
        </div>
        <div class="page-actions d-flex gap-2">
            {% if status != "sent" %}
                <button type="button"
                        class="btn btn-primary"
                        data-newsletter-modal-url="/newsletters/create?modal=1"
                        data-newsletter-modal-title="Neuer Newsletter">
                    <i class="bi bi-plus-circle"></i> Neuer Newsletter
                </button>
            {% endif %}
            <a href="/newsletters/templates" class="btn btn-outline-secondary">
                <i class="bi bi-bookmark"></i> Vorlagen verwalten
            </a>
        </div>
```

Den Block `{% if projects|length == 0 %} … {% else %}` samt zugehörigem `{% endif %}` am Ende des Inhaltsbereichs entfernen, sodass die Liste immer gerendert wird. Die Zeile `{% set draft_project_id = … %}` ersatzlos löschen. Die Beschreibung der Filterzeile ändern zu:

```twig
                        <p class="dashboard-section-lead mb-0">Status, Empfängertyp und Projekt filtern.</p>
```

Den Projektfilter-Block (bisher `{% if status != "sent" and projects|length > 0 %} … {% else %} <input type="hidden" …> {% endif %}`) ersetzen durch:

```twig
                            <div class="col-12 col-md-4">
                                <label for="newsletter-status-project" class="form-label">Projekt</label>
                                <select id="newsletter-status-project"
                                        name="project_id"
                                        class="form-select onchange-submit">
                                    <option value="" {% if project_filter == "" %}selected{% endif %}>Alle Projekte</option>
                                    <option value="none" {% if project_filter == "none" %}selected{% endif %}>Ohne Projekt</option>
                                    {% for selectable_project in projects %}
                                        <option value="{{ selectable_project.id }}"
                                                {% if project_filter == selectable_project.id|string %}selected{% endif %}>
                                            {{ selectable_project.name }}
                                        </option>
                                    {% endfor %}
                                </select>
                            </div>
```

- [ ] **Step 5: Add the project column to the table**

In `templates/newsletters/index.twig` im `<thead>` nach der Titelspalte einfügen:

```twig
                                            <th data-sort-key="project" data-sort-type="text">Projekt</th>
```

Im `<tbody>` das `<tr>`-Attribut ergänzen:

```twig
                                                data-sort-project="{{ newsletter.project.name|default("")|lower }}"
```

und nach der Titelzelle einfügen:

```twig
                                                <td data-label="Projekt">{{ newsletter.project.name|default("–") }}</td>
```

Die Bearbeiten-Schaltfläche verliert den nicht mehr vorhandenen Projektbezug:

```twig
                                                                    data-newsletter-modal-url="/newsletters/{{ newsletter.id }}/edit?modal=1"
```

In der Leerzeile der Tabelle (`templates/newsletters/index.twig:248`) `colspan="6"` auf `colspan="7"` erhöhen.

- [ ] **Step 6: Run tests and the Twig linter**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: PASS

Run: `ddev composer twigcs`
Expected: keine Verstöße gemeldet

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/NewsletterController.php templates/newsletters/index.twig tests/Feature/NewsletterProjectDecouplingFeatureTest.php
git commit -m "feat(newsletter): Uebersicht filtert alle und projektlose Newsletter"
```

---

### Task 5: Entwurf ohne Empfängerquelle speicherbar

**Files:**
- Modify: `src/Controllers/NewsletterController.php` (`validateNewsletterSourcesInput`, `store`, `update`, `resolveRecipientsPreview`, `create`, `edit`)
- Modify: `tests/Feature/NewsletterProjectDecouplingFeatureTest.php`

**Interfaces:**
- Consumes: `NewsletterRecipientService::setSources()`
- Produces: `validateNewsletterSourcesInput(array $data): array` liefert `['ok' => true, 'message' => null, 'payload' => ['sources' => []]]` auch für eine leere Auswahl; `store()` und `update()` akzeptieren ein leeres `project_id` und speichern `null`.

- [ ] **Step 1: Write the failing test**

An `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` anfügen:

```php
    public function testDraftWithoutRecipientSourcesCanBeStored(): void
    {
        $manager = $this->createUser();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->store(
            $this->makeRequest(
                'POST',
                '/newsletters',
                [
                    'project_id' => '',
                    'title' => 'Entwurf ohne Empfänger',
                    'content_html' => '<p>Noch offen.</p>',
                ],
                [],
                ['X-Requested-With' => 'XMLHttpRequest']
            ),
            $this->makeResponse()
        );

        $this->assertSame(201, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $stored = Newsletter::find((int) $payload['id']);

        $this->assertNotNull($stored);
        $this->assertNull($stored->project_id);
        $this->assertSame(0, (int) $stored->recipient_count);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter testDraftWithoutRecipientSourcesCanBeStored tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: FAIL – Statuscode 422 mit `Mindestens eine Empfängerquelle ist erforderlich.`

- [ ] **Step 3: Allow empty sources**

In `src/Controllers/NewsletterController.php` den Kopf von `validateNewsletterSourcesInput()` ersetzen:

```php
    /**
     * Empfaengerquellen sind beim Speichern freiwillig; erst der Versand verlangt
     * mindestens eine aufgeloeste Person.
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool, message:?string, payload:array<string, mixed>}
     */
    private function validateNewsletterSourcesInput(array $data): array
    {
        $sources = $data['sources'] ?? null;
        if (!is_array($sources)) {
            $sources = [];
        }
```

und den Abschluss der Methode ersetzen (der bisherige `if ($normalized === [])`-Block entfällt ersatzlos):

```php
        return [
            'ok' => true,
            'message' => null,
            'payload' => [
                'sources' => $normalized,
            ],
        ];
    }
```

- [ ] **Step 4: Accept an empty project in store() and update()**

In `store()` die Projektermittlung ersetzen:

```php
        $projectId = null;
        if (($data['project_id'] ?? '') !== '') {
            $projectId = (int) $data['project_id'];
        }

        if ($projectId !== null && !Project::query()->where('id', $projectId)->exists()) {
            $message = 'Das gewählte Projekt existiert nicht.';
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => $message], 422);
            }

            $_SESSION['error'] = $message;
            return $response->withHeader('Location', '/newsletters/create')->withStatus(302);
        }
```

und beim Anlegen `'project_id' => $projectId` verwenden. Die Weiterleitung im Erfolgsfall nutzt keine Projekt-ID mehr:

```php
        return $this->jsonResponse($response, [
            'id' => $newsletter->id,
            'redirect' => "/newsletters/{$newsletter->id}/edit" . ($isModal ? '?modal=1' : ''),
        ], 201);
```

In `update()` analog:

```php
        $projectId = null;
        if (($data['project_id'] ?? '') !== '') {
            $projectId = (int) $data['project_id'];
        }

        if ($projectId !== null && !Project::query()->where('id', $projectId)->exists()) {
            return $this->jsonResponse($response, ['error' => 'Das gewählte Projekt existiert nicht.'], 422);
        }
```

und `$newsletter->update(['project_id' => $projectId, …])`.

In `resolveRecipientsPreview()` entfällt die Projektpflicht vollständig; die Methode beginnt neu mit:

```php
    public function resolveRecipientsPreview(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $validation = $this->validateNewsletterSourcesInput($data);
        if (!$validation['ok']) {
            return $this->jsonResponse($response, [
                'errors' => [(string) ($validation['message'] ?? 'Ungültige Empfängerquellen.')],
            ], 422);
        }
```

In `create()` darf das Projekt fehlen:

```php
        $projects = $this->selectableProjects();
        $projectId = !empty($queryParams['project_id']) ? (int) $queryParams['project_id'] : null;
        $project = $projectId === null ? null : $projects->firstWhere('id', $projectId);
```

Die Vorbelegung der Empfängerquellen hängt daran:

```php
            'recipient_sources' => $project === null ? [] : [
                [
                    'type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
                    'reference_id' => (int) $project->id,
                ],
            ],
```

In `edit()` entfällt die Ersatzquelle für leere Auswahlen; `$sources = $this->recipientService->getSources($newsletter);` bleibt ohne den nachfolgenden `if ($sources === [])`-Block stehen.

- [ ] **Step 5: Run tests to verify they pass**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/NewsletterController.php tests/Feature/NewsletterProjectDecouplingFeatureTest.php
git commit -m "feat(newsletter): Entwuerfe ohne Empfaengerquelle speicherbar"
```

---

### Task 6: Versand verlangt mindestens einen Empfänger

**Files:**
- Create: `src/Exceptions/NewsletterWithoutRecipientsException.php`
- Modify: `src/Services/NewsletterService.php:45-70`
- Modify: `src/Controllers/NewsletterController.php` (`send`)
- Modify: `tests/Feature/NewsletterSecurityHardeningFeatureTest.php:23-32`
- Modify: `tests/Feature/NewsletterProjectDecouplingFeatureTest.php`

**Interfaces:**
- Consumes: `NewsletterRecipientService::resolveRecipients()`
- Produces: `App\Exceptions\NewsletterWithoutRecipientsException` (erweitert `RuntimeException`, Standardmeldung „Newsletter hat keine Empfänger."); `NewsletterService::send()` wirft sie, `NewsletterController::send()` beantwortet sie mit HTTP 422 bzw. einer Weiterleitung mit derselben Meldung.

- [ ] **Step 1: Write the failing test**

An `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` anfügen:

```php
    public function testSendWithoutRecipientsIsRejectedWith422(): void
    {
        $manager = $this->createUser();
        $newsletter = $this->createDraft(null, $manager);

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->send(
            $this->makeRequest('POST', '/newsletters/' . $newsletter->id . '/send', [], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Newsletter hat keine Empfänger.', (string) $response->getBody());
        $this->assertSame(Newsletter::STATUS_DRAFT, $newsletter->fresh()->status);
    }
```

Ebenfalls anfügen – der projektlose Versand muss vollständig funktionieren, nicht nur abgelehnt werden:

```php
    public function testProjectlessNewsletterIsSentAndArchivedForEveryRecipient(): void
    {
        $manager = $this->createUser();
        $recipientA = $this->createUser();
        $recipientB = $this->createUser();
        $newsletter = $this->createDraft(null, $manager);

        foreach ([$recipientA, $recipientB] as $recipient) {
            NewsletterRecipientSource::create([
                'newsletter_id' => $newsletter->id,
                'source_type' => NewsletterRecipientSource::TYPE_USER,
                'reference_id' => $recipient->id,
            ]);
        }

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;
        $_ENV['DISABLE_MAIL_SEND'] = $_SERVER['DISABLE_MAIL_SEND'] = 'true';

        $response = $this->newsletterController()->send(
            $this->makeRequest('POST', '/newsletters/' . $newsletter->id . '/send', [], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->withAttribute('id', (string) $newsletter->id),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $fresh = $newsletter->fresh();
        $this->assertSame(Newsletter::STATUS_SENT, $fresh->status);
        $this->assertNull($fresh->project_id);

        $archivedUserIds = NewsletterArchive::query()
            ->where('newsletter_id', $newsletter->id)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $recipientA->id, $archivedUserIds);
        $this->assertContains((int) $recipientB->id, $archivedUserIds);
    }
```

In `tests/Feature/NewsletterSecurityHardeningFeatureTest.php` die Methode `testNewsletterControllerReturnsJsonErrorPayloadForAjaxSendFailures()` ersetzen:

```php
    public function testNewsletterControllerReturnsDedicatedStatusForEmptyRecipientSend(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('NewsletterWithoutRecipientsException', $controllerContent);
        $this->assertStringContainsString(
            "return \$this->jsonResponse(\$response, ['error' => \$message], 422);",
            $controllerContent
        );
        $this->assertStringContainsString(
            "return \$this->jsonResponse(\$response, ['error' => \$message], 500);",
            $controllerContent
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter testSendWithoutRecipientsIsRejectedWith422 tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: FAIL – Statuscode 500 statt 422

- [ ] **Step 3: Add the named exception**

Datei `src/Exceptions/NewsletterWithoutRecipientsException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Ein Newsletter ohne aufgelöste Empfänger darf nicht versendet werden.
 */
class NewsletterWithoutRecipientsException extends RuntimeException
{
    public const MESSAGE = 'Newsletter hat keine Empfänger.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 4: Throw it from the service**

In `src/Services/NewsletterService.php` den Import ergänzen:

```php
use App\Exceptions\NewsletterWithoutRecipientsException;
```

und in `send()` die bisherige Prüfung ersetzen:

```php
        if ($recipients->count() === 0) {
            throw new NewsletterWithoutRecipientsException();
        }
```

- [ ] **Step 5: Answer with 422 in the controller**

In `src/Controllers/NewsletterController.php` den Import ergänzen:

```php
use App\Exceptions\NewsletterWithoutRecipientsException;
```

und in `send()` vor dem bestehenden `catch (\Exception $e)` einfügen:

```php
        } catch (NewsletterWithoutRecipientsException $e) {
            $message = $e->getMessage();
            $this->logger->info(
                'Newsletter send blocked without recipients.',
                [
                    'event' => 'newsletter.send.blocked_without_recipients',
                    'newsletter_id' => $id,
                    'user_id' => is_numeric($userId) ? (int) $userId : null,
                ]
            );

            if (!$expectsJson) {
                $_SESSION['error'] = $message;
                return $response
                    ->withHeader('Location', '/newsletters?status=' . Newsletter::STATUS_DRAFT)
                    ->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => $message], 422);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/NewsletterProjectDecouplingFeatureTest.php tests/Feature/NewsletterSecurityHardeningFeatureTest.php tests/Feature/NewsletterSendArchiveFeatureTest.php`
Expected: PASS in allen drei Dateien

- [ ] **Step 7: Commit**

```bash
git add src/Exceptions src/Services/NewsletterService.php src/Controllers/NewsletterController.php tests/Feature
git commit -m "feat(newsletter): Versand ohne Empfaenger klar mit 422 ablehnen"
```

---

### Task 7: Formulare für optionales Projekt und alle Vorlagen

**Files:**
- Modify: `src/Controllers/NewsletterController.php` (`create`, `edit`)
- Modify: `templates/newsletters/create.twig`
- Modify: `templates/newsletters/edit.twig`

**Interfaces:**
- Consumes: `NewsletterController::selectableProjects()`
- Produces: Beide Formulare liefern `project_id` als leeren String, wenn kein Projekt gewählt ist. `create()` und `edit()` übergeben zusätzlich `template_groups` als `array<int, array{label: string, templates: \Illuminate\Support\Collection}>` – die erste Gruppe trägt das Label `Global`.

- [ ] **Step 1: Group the templates in the controller**

In `src/Controllers/NewsletterController.php` eine Hilfsmethode ergänzen:

```php
    /**
     * Vorlagen fuer das Auswahlfeld: global zuerst, danach je Projekt.
     *
     * @return array<int, array{label: string, templates: \Illuminate\Support\Collection}>
     */
    private function groupedTemplates(): array
    {
        $templates = NewsletterTemplate::query()->with('project')->orderBy('name')->get();

        $global = $templates->filter(static fn ($template): bool => $template->project_id === null)->values();
        $groups = [];

        if ($global->isNotEmpty()) {
            $groups[] = ['label' => 'Global', 'templates' => $global];
        }

        $byProject = $templates
            ->filter(static fn ($template): bool => $template->project_id !== null)
            ->groupBy(static fn ($template): string => (string) ($template->project->name ?? 'Projekt'));

        foreach ($byProject->sortKeys() as $projectName => $projectTemplates) {
            $groups[] = ['label' => (string) $projectName, 'templates' => $projectTemplates->values()];
        }

        return $groups;
    }
```

In `create()` `'templates' => …` durch `'template_groups' => $this->groupedTemplates(),` ersetzen und in `edit()` denselben Schlüssel ergänzen.

- [ ] **Step 2: Make the project optional in create.twig**

In `templates/newsletters/create.twig` das Projektfeld ersetzen:

```twig
                            <div class="mb-3">
                                <label for="project_id" class="form-label">Projekt (optional)</label>
                                <select class="form-select" id="project_id" name="project_id">
                                    <option value="">— kein Projekt —</option>
                                    {% for selectable_project in projects %}
                                        <option value="{{ selectable_project.id }}"
                                                {% if project and selectable_project.id == project.id %}selected{% endif %}>
                                            {{ selectable_project.name }}
                                        </option>
                                    {% endfor %}
                                </select>
                            </div>
```

Die Zeile `{% set default_project_source_id = project.id %}` ersetzen durch:

```twig
                            {% set default_project_source_id = project ? project.id : null %}
```

Das Vorlagenfeld ersetzen:

```twig
                            <div class="mb-3">
                                <label for="template" class="form-label">Vorlage laden (optional)</label>
                                <select class="form-select" id="template" data-action="load-template">
                                    <option value="">-- Keine Vorlage --</option>
                                    {% for group in template_groups %}
                                        <optgroup label="{{ group.label }}">
                                            {% for template in group.templates %}
                                                <option value="{{ template.id }}">{{ template.name }}</option>
                                            {% endfor %}
                                        </optgroup>
                                    {% endfor %}
                                </select>
                            </div>
```

Im Kopfbereich die Projektzeile absichern:

```twig
            <p class="text-muted mb-0">
                Projekt: <strong>{{ project.name|default("kein Projekt") }}</strong>
            </p>
```

Die Links „Zurück zur Übersicht" und „Abbrechen" zeigen ohne Projektbezug auf `/newsletters?status=draft`.

- [ ] **Step 3: Make the project optional in edit.twig**

In `templates/newsletters/edit.twig` dieselbe Projektauswahl einsetzen:

```twig
                            <div class="mb-3">
                                <label for="project_id" class="form-label">Projekt (optional)</label>
                                <select class="form-select" id="project_id" name="project_id">
                                    <option value="" {% if not newsletter.project_id %}selected{% endif %}>— kein Projekt —</option>
                                    {% for selectable_project in projects %}
                                        <option value="{{ selectable_project.id }}"
                                                {% if newsletter.project_id == selectable_project.id %}selected{% endif %}>
                                            {{ selectable_project.name }}
                                        </option>
                                    {% endfor %}
                                </select>
                            </div>
```

Kopfbereich und Zurück-Link analog absichern:

```twig
            <p class="text-muted mb-0">
                Projekt: <strong>{{ project.name|default("kein Projekt") }}</strong>
            </p>
```

```twig
                    <a href="/newsletters?status=draft" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Zurück
                    </a>
```

- [ ] **Step 4: Run the Twig linter**

Run: `ddev composer twigcs`
Expected: keine Verstöße gemeldet

- [ ] **Step 5: Verify the forms render**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/NewsletterProjectDecouplingFeatureTest.php`
Expected: PASS (die Tests rendern `create.twig` und `edit.twig` über den echten Twig-Renderer)

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/NewsletterController.php templates/newsletters/create.twig templates/newsletters/edit.twig
git commit -m "feat(newsletter): Projektfeld optional und alle Vorlagen gruppiert anbieten"
```

---

### Task 8: Frontend – leeres Projekt und Versand-Sperre

**Files:**
- Modify: `public/js/newsletters-create.js:105-170`
- Modify: `public/js/newsletters-edit.js:145-210`

**Interfaces:**
- Consumes: `POST /newsletters/resolve-recipients-preview` (liefert `{"count": <int>}`), Element `#send-newsletter-btn`, Badge `#recipient-count-badge`
- Produces: keine neuen Schnittstellen; `newsletters-edit.js` erhält die interne Funktion `applyRecipientCount(count)`, die Badge und Versenden-Schaltfläche gemeinsam setzt.

- [ ] **Step 1: Allow an empty project in the preview request**

In `public/js/newsletters-create.js` und `public/js/newsletters-edit.js` bleibt der Block

```js
        if (projectSelect && projectSelect.value) {
            requestData.append("project_id", projectSelect.value);
        }
```

unverändert – er sendet bereits nichts, wenn kein Projekt gewählt ist. Prüfen und belassen.

- [ ] **Step 2: Add the send button gate in newsletters-edit.js**

In `public/js/newsletters-edit.js` nach der Deklaration von `sendButton` einfügen:

```js
    // Ein Newsletter ohne aufgeloeste Empfaenger darf nicht versendet werden.
    // Der Server prueft es ebenfalls; hier geht es nur um die klare Anzeige.
    function applyRecipientCount(count) {
        if (recipientCountBadge) {
            recipientCountBadge.textContent = String(count);
        }

        if (!sendButton) {
            return;
        }

        const hasRecipients = Number(count) > 0;
        sendButton.disabled = !hasRecipients;
        sendButton.title = hasRecipients ? "" : "Kein Empfänger ausgewählt";
    }
```

In `refreshRecipientPreview()` die drei Stellen ersetzen, die das Badge direkt setzen:

```js
        const payload = syncSourcesHiddenInputs();
        if (payload.length === 0) {
            applyRecipientCount(0);
            if (recipientCountStatus) {
                recipientCountStatus.textContent = "";
            }
            return;
        }
```

```js
            const data = await response.json();
            applyRecipientCount(Number(data.count ?? 0));
            if (recipientCountStatus) {
                recipientCountStatus.textContent = "";
            }
```

Die beiden Fehlerpfade behalten `recipientCountBadge.textContent = "-";`, ergänzen aber die Sperre:

```js
                if (sendButton) {
                    sendButton.disabled = true;
                    sendButton.title = "Empfängerzahl unbekannt";
                }
```

- [ ] **Step 3: Set the initial state on load**

Im `window.addEventListener("load", …)`-Block von `public/js/newsletters-edit.js` vor `refreshRecipientPreviewDebounced()` einfügen:

```js
        applyRecipientCount(Number(recipientCountBadge ? recipientCountBadge.textContent : 0));
```

- [ ] **Step 4: Verify in the browser**

Run: `ddev exec node help/newsletter/scripts/screenshot.js`
Expected: Das Skript läuft ohne Fehler durch und schreibt elf Dateien; `05-edit-modal-actions.png` zeigt die Schaltfläche „Versenden" weiterhin aktiv, weil der Seed-Entwurf Empfänger hat.

- [ ] **Step 5: Commit**

```bash
git add public/js/newsletters-edit.js public/js/newsletters-create.js
git commit -m "feat(newsletter): Versenden-Schaltflaeche ohne Empfaenger sperren"
```

---

### Task 9: Seed-Daten für die neuen Fälle

**Files:**
- Modify: `src/Services/DevSeedService.php:3169-3210` (Ende von `seedNewsletters()`)

**Interfaces:**
- Consumes: `Newsletter`, `NewsletterRecipientSource`, `NewsletterRecipient`, `NewsletterArchive`, `Role`, `User` (alle bereits in `DevSeedService` importiert – `Role` prüfen und bei Bedarf ergänzen)
- Produces: zusätzliche Datensätze in denselben Zählern (`newsletters`, `newsletter_recipient_sources`, `newsletter_recipients`, `newsletter_archive`)

- [ ] **Step 1: Check the imports**

Run: `ddev exec grep -n "^use App\\\\Models\\\\Role;" src/Services/DevSeedService.php`
Expected: eine Trefferzeile. Fehlt sie, `use App\Models\Role;` bei den übrigen Model-Importen ergänzen.

- [ ] **Step 2: Seed a projectless draft and a projectless sent newsletter**

Am Ende von `seedNewsletters()` in `src/Services/DevSeedService.php`, nach der Projektschleife, einfügen:

```php
        // Projektlose Newsletter: seit der Entkopplung braucht ein Rundschreiben
        // kein Projekt mehr, sondern nur Empfaenger.
        $announcementAuthor = $activeUsers[0] ?? null;
        if ($announcementAuthor === null) {
            return;
        }

        $boardRole = Role::query()->orderBy('id')->first();
        $singleRecipients = array_slice($activeUsers, 0, 3);

        $generalDraft = Newsletter::create([
            'project_id' => null,
            'title' => 'Entwurf: Vereinsweite Ankündigung',
            'content_html' => '<h2>Vereinsweite Ankündigung</h2>'
                . '<p>Dieser Entwurf richtet sich an einzelne Mitglieder, nicht an ein Projekt.</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $announcementAuthor->id,
        ]);
        $this->report['counts']['newsletters']++;

        foreach ($singleRecipients as $recipient) {
            NewsletterRecipientSource::create([
                'newsletter_id' => $generalDraft->id,
                'source_type' => NewsletterRecipientSource::TYPE_USER,
                'reference_id' => $recipient->id,
            ]);
            $this->report['counts']['newsletter_recipient_sources']++;

            NewsletterRecipient::create([
                'newsletter_id' => $generalDraft->id,
                'user_id' => $recipient->id,
                'status' => 'pending',
            ]);
            $this->report['counts']['newsletter_recipients']++;
        }

        $generalDraft->recipient_count = count($singleRecipients);
        $generalDraft->save();

        if ($boardRole === null) {
            return;
        }

        $sentAt = (new DateTimeImmutable())->modify('-3 days');
        $roleNewsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Rundschreiben an eine Rolle',
            'content_html' => '<h2>Information für Funktionsträger</h2>'
                . '<p>Dieses Rundschreiben ging an alle Mitglieder einer Rolle.</p>',
            'status' => Newsletter::STATUS_SENT,
            'created_by' => $announcementAuthor->id,
            'sent_at' => $sentAt->format('Y-m-d H:i:s'),
        ]);
        $this->report['counts']['newsletters']++;

        NewsletterRecipientSource::create([
            'newsletter_id' => $roleNewsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_ROLE,
            'reference_id' => $boardRole->id,
        ]);
        $this->report['counts']['newsletter_recipient_sources']++;

        $roleUserIds = User::query()
            ->whereHas('roles', static function ($query) use ($boardRole): void {
                $query->where('role_id', $boardRole->id);
            })
            ->where('is_active', 1)
            ->pluck('id')
            ->all();

        foreach ($roleUserIds as $userId) {
            NewsletterRecipient::create([
                'newsletter_id' => $roleNewsletter->id,
                'user_id' => $userId,
                'status' => 'sent',
            ]);
            $this->report['counts']['newsletter_recipients']++;

            $recipientUser = User::find($userId);
            if ($recipientUser) {
                NewsletterArchive::create([
                    'newsletter_id' => $roleNewsletter->id,
                    'user_id' => $userId,
                    'email' => $recipientUser->email,
                    'sent_at' => $sentAt->format('Y-m-d H:i:s'),
                ]);
                $this->report['counts']['newsletter_archive']++;
            }
        }

        $roleNewsletter->recipient_count = count($roleUserIds);
        $roleNewsletter->save();
```

- [ ] **Step 3: Run the seed**

Run: `ddev php bin/dev_seed.php --mode=reset-and-seed --years=3`
Expected: JSON-Zeile mit `"status":"ok"`; `newsletters` steigt von 22 auf 24, `newsletter_recipient_sources` auf mindestens 26

- [ ] **Step 4: Verify the projectless rows exist**

Run: `ddev mysql -uroot -proot -e "SELECT id, title, status, project_id FROM newsletters WHERE project_id IS NULL;" db`
Expected: zwei Zeilen – „Entwurf: Vereinsweite Ankündigung" (draft) und „Rundschreiben an eine Rolle" (sent)

- [ ] **Step 5: Commit**

```bash
git add src/Services/DevSeedService.php
git commit -m "feat(seed): projektlose Newsletter und Rollen-Empfaenger seeden"
```

---

### Task 10: Hilfetexte und Screenshots nachziehen

**Files:**
- Modify: `help/newsletter/docs/newsletter.md`
- Modify: `help/newsletter/docs/newsletter-compose.md`
- Modify: `help/newsletter/docs/newsletter-templates.md`
- Modify: `help/newsletter/scripts/screenshot.js:118-125`

**Interfaces:**
- Consumes: die Seed-Daten aus Task 9
- Produces: aktualisierte Hilfeseiten unter `/help/newsletter`, `/help/newsletter-compose`, `/help/newsletter-templates`

- [ ] **Step 1: Point the screenshot script at the unfiltered overview**

In `help/newsletter/scripts/screenshot.js` den ersten Seitenaufruf ersetzen:

```js
        // 1. Übersicht: Entwürfe über alle Projekte
        await page.goto(`${BASE_URL}/newsletters?status=draft`, { waitUntil: 'networkidle' });
```

- [ ] **Step 2: Retake the screenshots**

Run: `ddev exec node help/newsletter/scripts/screenshot.js`
Expected: elf Zeilen `gespeichert: …`, Abschlusszeile `Fertig: alle Newsletter-Screenshots erstellt.`

- [ ] **Step 3: Update newsletter.md**

In `help/newsletter/docs/newsletter.md`:

- Den Satz „Du siehst ausschließlich Newsletter aus Projekten, in denen du selbst Mitglied bist." ersetzen durch: „Mit dem Recht **„Newsletter verwalten"** siehst du alle Newsletter – unabhängig davon, in welchen Projekten du selbst mitsingst."
- Den Abschnitt „Entwürfe und versendete Newsletter" ersetzen durch:

```markdown
### Projekt als optionale Zuordnung

Ein Newsletter *kann* zu einem Projekt gehören, muss es aber nicht. Der Projektfilter gilt für beide Status und kennt drei Einstellungen: **Alle Projekte** (Vorgabe), **Ohne Projekt** für projektlose Rundschreiben und ein einzelnes Projekt. Die Spalte **Projekt** in der Liste zeigt die Zuordnung, bei projektlosen Newslettern steht dort „–".
```

- Die Stolperfalle „**Keine Projekte, keine Newsletter**: …" ersetzen durch:

```markdown
- **Newsletter ohne Empfänger lässt sich nicht versenden**: Die Schaltfläche „Versenden" bleibt gesperrt, solange die Empfängerzahl 0 ist – etwa bei einer Rolle ohne aktive Mitglieder.
```

- Die Stolperfalle „**„Neuer Newsletter" fehlt**" bleibt inhaltlich richtig und bleibt unverändert.

- [ ] **Step 4: Update newsletter-compose.md**

In `help/newsletter/docs/newsletter-compose.md`:

- Im Abschnitt „1. Entwurf anlegen" den Einstieg ersetzen: „Öffne **Bereiche → Newsletter**, stelle den Status auf **Entwürfe** und klicke oben rechts auf **Neuer Newsletter**."
- Die Pflichtfeldliste ersetzen:

```markdown
Pflichtfelder sind:

- **Titel** – wird zugleich als Betreff der E-Mail verwendet (höchstens 255 Zeichen).
- **Inhalt** – der Text des Newsletters.

Freiwillig sind:

- **Projekt** – ordnet den Newsletter einem Projekt zu. Ohne Auswahl bleibt er projektlos, was für Rundschreiben an eine Rolle oder an einzelne Personen der Normalfall ist.
- **Empfängerquellen** – ein Entwurf lässt sich auch ohne Empfänger speichern und später ergänzen. Versendet werden kann er dann allerdings noch nicht.
```

- Den Satz „Zur Auswahl stehen nur Projekte, in denen du Mitglied bist." streichen.
- Im Abschnitt „2. Empfänger zusammenstellen" den Satz zur Vorbelegung ersetzen: „Wählst du beim Anlegen ein Projekt aus, ist es zugleich als Quelle **Projektmitglieder** vorbelegt."
- In der Liste unter „Wichtig dabei" ergänzen:

```markdown
- **Ohne Empfänger kein Versand**: Die Schaltfläche „Versenden" ist gesperrt, solange die Empfängerzahl 0 ist. Das gilt auch, wenn zwar eine Quelle gewählt ist, sich daraus aber keine aktive Person ergibt.
```

- In den Stolperfallen den Punkt „**„Mindestens eine gültige Empfängerquelle ist erforderlich"**" ersetzen durch:

```markdown
- **Versand abgelehnt („Newsletter hat keine Empfänger.")**: Es ist keine Quelle gewählt, oder die gewählten Quellen ergeben keine aktive Person – etwa eine Veranstaltung ohne erfasste Anwesenheit oder eine Rolle ohne aktive Mitglieder.
```

- [ ] **Step 5: Update newsletter-templates.md**

In `help/newsletter/docs/newsletter-templates.md`:

- Die Beschreibung des Kontexts ersetzen:

```markdown
Die Spalte **Kontext** ordnet jede Vorlage ein:

- **Global** – nicht an ein Projekt gebunden.
- **Projekt** – inhaltlich zu einem bestimmten Projekt gehörig.

Der Kontext ist eine Einordnung, keine Sperre: Mit dem Recht **„Newsletter verwalten"** kannst du jede Vorlage öffnen, bearbeiten, klonen und in jedem Newsletter laden.
```

- Im Abschnitt „5. Vorlage verwenden" den Satz zur Auswahl ersetzen: „Angeboten werden alle Vorlagen, im Auswahlfeld nach Kontext gruppiert – zuerst die globalen, danach die Vorlagen je Projekt."
- Die Stolperfalle „**Vorlage taucht nicht in der Auswahl auf**" ersatzlos streichen.
- Im Abschnitt „4. Vorlage aus einem Newsletter erzeugen" ergänzen: „Gehört der Newsletter zu keinem Projekt, entsteht eine globale Vorlage."

- [ ] **Step 6: Normalize line endings**

Run in PowerShell:

```powershell
Get-ChildItem "d:\Proggen\ChorManager\help\newsletter\docs\*.md" | ForEach-Object { $f=$_.FullName; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Expected: keine Ausgabe, Befehl endet ohne Fehler

- [ ] **Step 7: Commit**

```bash
git add help/newsletter
git commit -m "docs(newsletter): Hilfetexte und Screenshots an die Entkopplung angepasst"
```

---

### Task 11: Gesamtverifikation

**Files:**
- Keine Änderungen; ausschließlich Prüfläufe. Etwaige Korrekturen erfolgen in der jeweils betroffenen Datei.

**Interfaces:**
- Consumes: alle vorherigen Tasks
- Produces: nachgewiesener grüner Zustand von Migration, Tests, Lintern, Seed und Hilfeseiten

- [ ] **Step 1: Confirm the migration status**

Run: `ddev exec ./vendor/bin/phinx status`
Expected: Zeile zu `AllowNewslettersWithoutProject` mit Status `up`

- [ ] **Step 2: Run the full test suite**

Run: `ddev exec ./vendor/bin/phpunit`
Expected: `OK` ohne Fehler und ohne Risky-Tests

- [ ] **Step 3: Run the PHP linter**

Run: `ddev composer phpcs`
Expected: keine Verstöße. Bei Meldungen `ddev composer phpcbf` ausführen und Schritt 3 wiederholen.

- [ ] **Step 4: Run the Twig linter**

Run: `ddev composer twigcs`
Expected: keine Verstöße

- [ ] **Step 5: Check the line endings across the repository**

Run: `ddev exec php bin/check_lf_repo.php`
Expected: Logzeile mit `lf_repo_check.completed` und keine Fehlermeldung

- [ ] **Step 6: Run a fresh seed**

Run: `ddev php bin/dev_seed.php --mode=reset-and-seed --years=3`
Expected: `"status":"ok"` mit `newsletters` = 24

- [ ] **Step 7: Verify the help pages**

Run: `ddev exec node help/newsletter/scripts/screenshot.js`
Expected: elf gespeicherte Dateien, Abschlusszeile `Fertig: alle Newsletter-Screenshots erstellt.`

- [ ] **Step 8: Commit any fixes**

```bash
git add -A
git commit -m "chore(newsletter): Linter- und Testkorrekturen nach der Entkopplung"
```

Falls keine Korrekturen nötig waren, entfällt dieser Commit.

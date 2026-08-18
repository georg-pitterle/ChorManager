# Newsletter-Platzhalter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Newsletter werden personalisiert versendet — Redakteure schreiben `{{vorname}}` und Co. in Inhalt und Betreff, die Anwendung ersetzt sie pro Empfänger.

**Architecture:** Ein `NewsletterPlaceholderService` hält eine Registry aus `PlaceholderDefinition`-Objekten und rendert Text gegen einen `RenderContext` plus optionalen Empfänger. `NewsletterService::send()` baut den Kontext einmal und rendert Body und Betreff je Empfänger direkt vor dem Enqueue. Dieselbe Registry speist den TinyMCE-Einfügeknopf, die Validierung unbekannter Token, die Vorschau und die Testmail.

**Tech Stack:** PHP 8.5, Slim 4, Eloquent, Twig, PHPUnit 10, TinyMCE 8, Playwright, DDEV.

Spec: `docs/superpowers/specs/2026-08-18-newsletter-platzhalter-design.md`

## Global Constraints

- PHP nach PSR-12, 4 Leerzeichen, Zeilenlänge weich 120, hart 130. Prüfen mit `ddev composer phpcs`, fixen mit `ddev composer phpcbf`.
- Twig nach Projektstandard: doppelte Anführungszeichen, benannte Argumente ohne Leerzeichen um `=`, keine mehrzeiligen Boolean-Ausdrücke. Prüfen mit `ddev composer twigcs`.
- Bezeichner englisch (Klassen, Methoden, Variablen, Routen, CSS-Klassen, `data-*`, JS-Exporte). Ausnahme: die Platzhalter-Token selbst sind Inhalt und heißen deutsch.
- Sichtbarer Text deutsch mit echten Umlauten `ä ö ü ß`, nie `ae/oe/ue/ss`.
- Textdateien mit LF speichern. Nach jedem Schreibvorgang auf Windows normalisieren:
  `$f = "<pfad>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
- Kein Inline-JavaScript und kein `style="..."` in Templates. Keine externen CDNs.
- Logging über `Psr\Log\LoggerInterface` mit stabilem `event`-Key im Kontext, kein `error_log()`.
- Kein `git push`. Nach dem letzten lokalen Commit stoppen und den Entwickler informieren.
- Testkommandos laufen über DDEV: `ddev exec ./vendor/bin/phpunit --filter <Name>`.
- Es gibt keine Schemaänderung in diesem Plan. Wer eine für nötig hält, hat einen Denkfehler — `mail_queue` speichert Betreff und Body bereits pro Empfänger.

---

### Task 1: Wertobjekte und Registry

**Files:**
- Create: `src/Newsletter/PlaceholderDefinition.php`
- Create: `src/Newsletter/RenderContext.php`
- Create: `src/Services/NewsletterPlaceholderService.php`
- Test: `tests/Feature/NewsletterPlaceholderFeatureTest.php`

**Interfaces:**
- Consumes: `App\Services\NameFormatterService`, `App\Util\MailBranding`, `App\Models\Newsletter`, `App\Models\User`
- Produces:
  - `PlaceholderDefinition::__construct(string $key, string $label, string $description, string $scope, string $example, callable $resolver, bool $isRawHtml = false)`, Konstanten `SCOPE_RECIPIENT`, `SCOPE_NEWSLETTER`, `SCOPE_GLOBAL`, Methode `resolve(RenderContext $context, ?User $recipient): string`
  - `RenderContext::__construct(string $appName, string $baseUrl, ?int $newsletterId, string $title, string $projectName, string $senderName, string $date)` und `RenderContext::fromNewsletter(Newsletter $newsletter, string $baseUrl, NameFormatterService $nameFormatter): self`
  - `NewsletterPlaceholderService::__construct(NameFormatterService $nameFormatter)`, `definitions(): array<string, PlaceholderDefinition>`, `findUnknownTokens(string $text): array<int, string>`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterPlaceholderFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use App\Newsletter\PlaceholderDefinition;
use App\Newsletter\RenderContext;
use App\Services\NameFormatterService;
use App\Services\NewsletterPlaceholderService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Platzhalter in Newslettern: Registry, Auflösung, Escaping und Fallbacks.
 */
final class NewsletterPlaceholderFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function service(): NewsletterPlaceholderService
    {
        return new NewsletterPlaceholderService(new NameFormatterService());
    }

    private function createUser(string $firstName = 'Georg', string $lastName = 'Pitterle'): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "placeholder_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => 1,
        ]);
    }

    private function createNewsletter(?Project $project, User $creator, string $title = 'Probenplan'): Newsletter
    {
        return Newsletter::create([
            'project_id' => $project?->id,
            'title' => $title,
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
    }

    public function testRegistryContainsAllDocumentedTokens(): void
    {
        $keys = array_keys($this->service()->definitions());

        sort($keys);

        $this->assertSame([
            'absender',
            'anrede',
            'app_name',
            'archiv_link',
            'datum',
            'email',
            'login_url',
            'name',
            'nachname',
            'projekt',
            'stimmgruppe',
            'titel',
            'vorname',
        ], $keys);
    }

    public function testEveryDefinitionCarriesGermanMetadataAndValidScope(): void
    {
        $validScopes = [
            PlaceholderDefinition::SCOPE_RECIPIENT,
            PlaceholderDefinition::SCOPE_NEWSLETTER,
            PlaceholderDefinition::SCOPE_GLOBAL,
        ];

        foreach ($this->service()->definitions() as $key => $definition) {
            $this->assertSame($key, $definition->key);
            $this->assertNotSame('', trim($definition->label), "Label fehlt: {$key}");
            $this->assertNotSame('', trim($definition->description), "Beschreibung fehlt: {$key}");
            $this->assertContains($definition->scope, $validScopes, "Ungültiger Scope: {$key}");
        }
    }

    public function testUnknownTokensAreReportedAndKnownOnesAreNot(): void
    {
        $service = $this->service();

        $this->assertSame(
            ['tippfehler'],
            $service->findUnknownTokens('<p>Hallo {{ vorname }}, {{tippfehler}} und {{nachname}}</p>')
        );
        $this->assertSame([], $service->findUnknownTokens('<p>Hallo {{vorname}}</p>'));
    }

    public function testUnknownTokensAreReportedOnlyOncePerKey(): void
    {
        $this->assertSame(
            ['tippfehler'],
            $this->service()->findUnknownTokens('{{tippfehler}} und nochmal {{tippfehler}}')
        );
    }

    public function testContextResolvesNewsletterAndGlobalScopes(): void
    {
        $creator = $this->createUser('Anna', 'Berger');
        $project = Project::create(['name' => 'Frühjahrskonzert']);
        $newsletter = $this->createNewsletter($project, $creator, 'Probenplan Mai');

        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());
        $definitions = $this->service()->definitions();

        $this->assertSame('Probenplan Mai', $definitions['titel']->resolve($context, null));
        $this->assertSame('Frühjahrskonzert', $definitions['projekt']->resolve($context, null));
        $this->assertSame('Anna Berger', $definitions['absender']->resolve($context, null));
        $this->assertSame('https://chor.example', $definitions['login_url']->resolve($context, null));
        $this->assertSame(
            '<a href="https://chor.example/newsletters/' . $newsletter->id . '/preview">Im Browser ansehen</a>',
            $definitions['archiv_link']->resolve($context, null)
        );
    }

    public function testProjectlessNewsletterResolvesProjectToEmptyString(): void
    {
        $creator = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);

        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $this->assertSame('', $this->service()->definitions()['projekt']->resolve($context, null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderFeatureTest`
Expected: FAIL mit `Class "App\Newsletter\PlaceholderDefinition" not found`

- [ ] **Step 3: Write PlaceholderDefinition**

Create `src/Newsletter/PlaceholderDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Models\User;

/**
 * Ein einzelner Newsletter-Platzhalter samt Metadaten für Auswahlliste und Hilfe.
 *
 * Der Scope legt fest, was zum Auflösen nötig ist: "recipient" braucht eine Person,
 * "newsletter" nur den Datensatz, "global" gar nichts.
 */
final class PlaceholderDefinition
{
    public const SCOPE_RECIPIENT = 'recipient';
    public const SCOPE_NEWSLETTER = 'newsletter';
    public const SCOPE_GLOBAL = 'global';

    /** @var callable(RenderContext, ?User): string */
    private $resolver;

    /**
     * @param callable(RenderContext, ?User): string $resolver
     * @param bool $isRawHtml true nur für Platzhalter, die selbst sicheres Markup erzeugen.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $scope,
        public readonly string $example,
        callable $resolver,
        public readonly bool $isRawHtml = false
    ) {
        $this->resolver = $resolver;
    }

    public function resolve(RenderContext $context, ?User $recipient): string
    {
        return (string) ($this->resolver)($context, $recipient);
    }
}
```

- [ ] **Step 4: Write RenderContext**

Create `src/Newsletter/RenderContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Models\Newsletter;
use App\Services\NameFormatterService;
use App\Util\MailBranding;
use Carbon\Carbon;

/**
 * Alles, was beim Rendern eines Newsletters unabhängig vom einzelnen Empfänger ist.
 * Wird einmal je Versand aufgebaut, nicht je Empfänger.
 */
final class RenderContext
{
    public function __construct(
        public readonly string $appName,
        public readonly string $baseUrl,
        public readonly ?int $newsletterId,
        public readonly string $title,
        public readonly string $projectName,
        public readonly string $senderName,
        public readonly string $date
    ) {
    }

    public static function fromNewsletter(
        Newsletter $newsletter,
        string $baseUrl,
        NameFormatterService $nameFormatter
    ): self {
        $sentAt = $newsletter->sent_at instanceof Carbon ? $newsletter->sent_at : Carbon::now();

        return new self(
            appName: (string) MailBranding::resolve()['app_name'],
            baseUrl: rtrim($baseUrl, '/'),
            newsletterId: $newsletter->id === null ? null : (int) $newsletter->id,
            title: (string) $newsletter->title,
            projectName: (string) ($newsletter->project->name ?? ''),
            senderName: $nameFormatter->formatPerson($newsletter->createdBy),
            date: $sentAt->format('d.m.Y')
        );
    }
}
```

- [ ] **Step 5: Write NewsletterPlaceholderService with registry**

Create `src/Services/NewsletterPlaceholderService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Newsletter\PlaceholderDefinition;
use App\Newsletter\RenderContext;

/**
 * Registry und Auflösung der Newsletter-Platzhalter. Einzige Quelle für Rendering,
 * Auswahlliste im Editor, Validierung unbekannter Token und Hilfetext.
 */
class NewsletterPlaceholderService
{
    private const PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/u';

    /** @var array<string, PlaceholderDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly NameFormatterService $nameFormatter)
    {
    }

    /**
     * @return array<string, PlaceholderDefinition>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $nameFormatter = $this->nameFormatter;

        $definitions = [
            new PlaceholderDefinition(
                key: 'anrede',
                label: 'Anrede',
                description: 'Begrüßung mit Vornamen, ohne Vornamen nur "Hallo".',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Hallo Georg',
                resolver: static function (RenderContext $context, ?User $recipient): string {
                    $firstName = trim((string) ($recipient->first_name ?? ''));

                    return $firstName === '' ? 'Hallo' : 'Hallo ' . $firstName;
                }
            ),
            new PlaceholderDefinition(
                key: 'vorname',
                label: 'Vorname',
                description: 'Vorname der empfangenden Person.',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Georg',
                resolver: static fn (RenderContext $context, ?User $recipient): string
                    => trim((string) ($recipient->first_name ?? ''))
            ),
            new PlaceholderDefinition(
                key: 'nachname',
                label: 'Nachname',
                description: 'Nachname der empfangenden Person.',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Pitterle',
                resolver: static fn (RenderContext $context, ?User $recipient): string
                    => trim((string) ($recipient->last_name ?? ''))
            ),
            new PlaceholderDefinition(
                key: 'name',
                label: 'Vollständiger Name',
                description: 'Name in der global eingestellten Reihenfolge, ersatzweise die E-Mail-Adresse.',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Georg Pitterle',
                resolver: static function (RenderContext $context, ?User $recipient) use ($nameFormatter): string {
                    if ($recipient === null) {
                        return '';
                    }

                    $name = trim($nameFormatter->formatPerson($recipient));

                    return $name === '' ? trim((string) $recipient->email) : $name;
                }
            ),
            new PlaceholderDefinition(
                key: 'email',
                label: 'E-Mail-Adresse',
                description: 'E-Mail-Adresse der empfangenden Person.',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'georg@example.at',
                resolver: static fn (RenderContext $context, ?User $recipient): string
                    => trim((string) ($recipient->email ?? ''))
            ),
            new PlaceholderDefinition(
                key: 'stimmgruppe',
                label: 'Stimmgruppe',
                description: 'Stimmgruppen samt Untergruppe, ohne Zuordnung "ohne Stimmgruppe".',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Sopran (Sopran 1)',
                resolver: static function (RenderContext $context, ?User $recipient): string {
                    if ($recipient === null) {
                        return 'ohne Stimmgruppe';
                    }

                    $subVoiceNames = $recipient->subVoices->keyBy('id');
                    $parts = [];

                    foreach ($recipient->voiceGroups as $group) {
                        $subVoiceId = (int) ($group->pivot->sub_voice_id ?? 0);
                        $subVoiceName = $subVoiceId > 0
                            ? trim((string) ($subVoiceNames[$subVoiceId]->name ?? ''))
                            : '';
                        $groupName = trim((string) $group->name);

                        $parts[] = $subVoiceName === ''
                            ? $groupName
                            : $groupName . ' (' . $subVoiceName . ')';
                    }

                    return $parts === [] ? 'ohne Stimmgruppe' : implode(', ', $parts);
                }
            ),
            new PlaceholderDefinition(
                key: 'titel',
                label: 'Newsletter-Titel',
                description: 'Betreff dieses Newsletters.',
                scope: PlaceholderDefinition::SCOPE_NEWSLETTER,
                example: 'Probenplan Mai',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->title
            ),
            new PlaceholderDefinition(
                key: 'projekt',
                label: 'Projekt',
                description: 'Name des verknüpften Projekts, leer bei projektlosen Newslettern.',
                scope: PlaceholderDefinition::SCOPE_NEWSLETTER,
                example: 'Frühjahrskonzert',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->projectName
            ),
            new PlaceholderDefinition(
                key: 'datum',
                label: 'Datum',
                description: 'Versanddatum, in der Vorschau das aktuelle Datum.',
                scope: PlaceholderDefinition::SCOPE_NEWSLETTER,
                example: '18.08.2026',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->date
            ),
            new PlaceholderDefinition(
                key: 'absender',
                label: 'Absender',
                description: 'Person, die den Newsletter angelegt hat.',
                scope: PlaceholderDefinition::SCOPE_NEWSLETTER,
                example: 'Anna Berger',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->senderName
            ),
            new PlaceholderDefinition(
                key: 'app_name',
                label: 'Anwendungsname',
                description: 'Name der Anwendung aus den Einstellungen.',
                scope: PlaceholderDefinition::SCOPE_GLOBAL,
                example: 'Chor-Manager',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->appName
            ),
            new PlaceholderDefinition(
                key: 'login_url',
                label: 'Adresse der Anwendung',
                description: 'Basisadresse für den Login.',
                scope: PlaceholderDefinition::SCOPE_GLOBAL,
                example: 'https://chor.example',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->baseUrl
            ),
            new PlaceholderDefinition(
                key: 'archiv_link',
                label: 'Link zur Browser-Ansicht',
                description: 'Verweis auf diesen Newsletter im persönlichen Archiv.',
                scope: PlaceholderDefinition::SCOPE_GLOBAL,
                example: 'Im Browser ansehen',
                resolver: static function (RenderContext $context, ?User $recipient): string {
                    if ($context->newsletterId === null) {
                        return '';
                    }

                    return '<a href="' . $context->baseUrl . '/newsletters/' . $context->newsletterId
                        . '/preview">Im Browser ansehen</a>';
                },
                isRawHtml: true
            ),
        ];

        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->key] = $definition;
        }

        $this->definitions = $indexed;

        return $indexed;
    }

    /**
     * Token, die im Text stehen, aber nicht in der Registry. Jeder Key nur einmal.
     *
     * @return array<int, string>
     */
    public function findUnknownTokens(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $matches) === 0) {
            return [];
        }

        $known = $this->definitions();
        $unknown = [];

        foreach ($matches[1] as $key) {
            if (!isset($known[$key]) && !in_array($key, $unknown, true)) {
                $unknown[] = $key;
            }
        }

        return $unknown;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderFeatureTest`
Expected: PASS, 6 Tests

- [ ] **Step 7: Commit**

```bash
git add src/Newsletter/PlaceholderDefinition.php src/Newsletter/RenderContext.php src/Services/NewsletterPlaceholderService.php tests/Feature/NewsletterPlaceholderFeatureTest.php
git commit -m "feat(newsletter): Platzhalter-Registry und Renderkontext"
```

---

### Task 2: HTML-Rendering mit Escaping und Fallbacks

**Files:**
- Modify: `src/Services/NewsletterPlaceholderService.php`
- Test: `tests/Feature/NewsletterPlaceholderFeatureTest.php`

**Interfaces:**
- Consumes: `PlaceholderDefinition`, `RenderContext` aus Task 1
- Produces: `NewsletterPlaceholderService::renderHtml(string $html, RenderContext $context, ?User $recipient): string`

- [ ] **Step 1: Write the failing test**

Append inside `NewsletterPlaceholderFeatureTest`, vor der schließenden Klammer:

```php
    private function createVoiceGroupAssignment(User $user, string $groupName, ?string $subVoiceName): void
    {
        $group = VoiceGroup::create(['name' => $groupName]);
        $subVoiceId = null;

        if ($subVoiceName !== null) {
            $subVoiceId = SubVoice::create([
                'name' => $subVoiceName,
                'voice_group_id' => $group->id,
            ])->id;
        }

        $user->voiceGroups()->attach($group->id, ['sub_voice_id' => $subVoiceId]);
        $user->unsetRelation('voiceGroups');
        $user->unsetRelation('subVoices');
    }

    public function testRenderHtmlReplacesRecipientTokens(): void
    {
        $creator = $this->createUser('Anna', 'Berger');
        $recipient = $this->createUser('Georg', 'Pitterle');
        $this->createVoiceGroupAssignment($recipient, 'Bass', 'Bass 2');
        $newsletter = $this->createNewsletter(null, $creator, 'Probenplan Mai');
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml(
            '<p>{{anrede}}, deine Stimmgruppe: {{stimmgruppe}}. Von {{absender}}.</p>',
            $context,
            $recipient
        );

        $this->assertSame(
            '<p>Hallo Georg, deine Stimmgruppe: Bass (Bass 2). Von Anna Berger.</p>',
            $rendered
        );
    }

    public function testRenderHtmlUsesFallbackForMissingFirstNameAndVoiceGroup(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('', 'Huber');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml(
            '<p>{{anrede}}! Gruppe: {{stimmgruppe}}</p>',
            $context,
            $recipient
        );

        $this->assertSame('<p>Hallo! Gruppe: ohne Stimmgruppe</p>', $rendered);
    }

    public function testRenderHtmlFallsBackToEmailWhenNameIsEmpty(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('', '');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{name}}</p>', $context, $recipient);

        $this->assertSame('<p>' . $recipient->email . '</p>', $rendered);
    }

    public function testRenderHtmlEscapesRecipientValues(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('Georg', '<script>alert(1)</script>');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{nachname}}</p>', $context, $recipient);

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function testRenderHtmlKeepsArchiveLinkAsMarkup(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{archiv_link}}</p>', $context, $recipient);

        $this->assertSame(
            '<p><a href="https://chor.example/newsletters/' . $newsletter->id . '/preview">Im Browser ansehen</a></p>',
            $rendered
        );
    }

    public function testRenderHtmlLeavesUnknownTokensUntouched(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{tippfehler}}</p>', $context, $recipient);

        $this->assertSame('<p>{{tippfehler}}</p>', $rendered);
    }

    public function testRenderHtmlWithoutRecipientUsesFallbacks(): void
    {
        $creator = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{anrede}} {{stimmgruppe}} {{vorname}}</p>', $context, null);

        $this->assertSame('<p>Hallo ohne Stimmgruppe </p>', $rendered);
    }
```

Ergänze oben in der Testdatei die Imports:

```php
use App\Models\SubVoice;
use App\Models\VoiceGroup;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderFeatureTest`
Expected: FAIL mit `Call to undefined method App\Services\NewsletterPlaceholderService::renderHtml()`

- [ ] **Step 3: Implement renderHtml**

Ergänze in `src/Services/NewsletterPlaceholderService.php` nach `definitions()`:

```php
    /**
     * Ersetzt Platzhalter im HTML-Body. Aufgelöste Werte werden escaped, weil die
     * Ersetzung nach dem Sanitizing passiert und ein Name sonst Markup einschleusen könnte.
     */
    public function renderHtml(string $html, RenderContext $context, ?User $recipient): string
    {
        return $this->replace($html, $context, $recipient, static function (string $value, bool $isRawHtml): string {
            return $isRawHtml ? $value : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        });
    }

    /**
     * @param callable(string, bool): string $escape
     */
    private function replace(string $text, RenderContext $context, ?User $recipient, callable $escape): string
    {
        $definitions = $this->definitions();

        $replaced = preg_replace_callback(
            self::PATTERN,
            static function (array $matches) use ($definitions, $context, $recipient, $escape): string {
                $definition = $definitions[$matches[1]] ?? null;
                if ($definition === null) {
                    return $matches[0];
                }

                return $escape($definition->resolve($context, $recipient), $definition->isRawHtml);
            },
            $text
        );

        return $replaced ?? $text;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderFeatureTest`
Expected: PASS, 13 Tests

- [ ] **Step 5: Commit**

```bash
git add src/Services/NewsletterPlaceholderService.php tests/Feature/NewsletterPlaceholderFeatureTest.php
git commit -m "feat(newsletter): Platzhalter im HTML-Body mit Escaping ersetzen"
```

---

### Task 3: Betreff-Rendering ohne Entities, mit Header-Schutz

**Files:**
- Modify: `src/Services/NewsletterPlaceholderService.php`
- Test: `tests/Feature/NewsletterPlaceholderFeatureTest.php`

**Interfaces:**
- Produces: `NewsletterPlaceholderService::renderSubject(string $subject, RenderContext $context, ?User $recipient): string`

- [ ] **Step 1: Write the failing test**

Append inside `NewsletterPlaceholderFeatureTest`:

```php
    public function testRenderSubjectKeepsAmpersandUnencoded(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('Maria', 'Müller & Sohn');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $subject = $this->service()->renderSubject('Info für {{nachname}}', $context, $recipient);

        $this->assertSame('Info für Müller & Sohn', $subject);
    }

    public function testRenderSubjectStripsLineBreaksToPreventHeaderInjection(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser("Georg\r\nBcc: angriff@example.test", 'Pitterle');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $subject = $this->service()->renderSubject('Hallo {{vorname}}', $context, $recipient);

        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertSame('Hallo Georg Bcc: angriff@example.test', $subject);
    }

    public function testRenderSubjectReducesMarkupTokensToPlainText(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $subject = $this->service()->renderSubject('Newsletter {{archiv_link}}', $context, $recipient);

        $this->assertSame('Newsletter Im Browser ansehen', $subject);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderFeatureTest`
Expected: FAIL mit `Call to undefined method App\Services\NewsletterPlaceholderService::renderSubject()`

- [ ] **Step 3: Implement renderSubject**

Ergänze in `src/Services/NewsletterPlaceholderService.php` nach `renderHtml()`:

```php
    /**
     * Ersetzt Platzhalter in der Betreffzeile. Kein HTML-Escaping, weil "&" im Betreff
     * als "&" stehen bleiben soll. Zeilenumbrüche werden entfernt, sonst erlaubt ein
     * manipulierter Name das Einschleusen zusätzlicher Mail-Header.
     */
    public function renderSubject(string $subject, RenderContext $context, ?User $recipient): string
    {
        $rendered = $this->replace($subject, $context, $recipient, static function (
            string $value,
            bool $isRawHtml
        ): string {
            $plain = $isRawHtml ? strip_tags($value) : $value;

            return trim((string) preg_replace('/[\r\n]+/u', ' ', $plain));
        });

        return trim((string) preg_replace('/[\r\n]+/u', ' ', $rendered));
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderFeatureTest`
Expected: PASS, 16 Tests

- [ ] **Step 5: Run the style gate**

Run: `ddev composer phpcs`
Expected: keine Fehler in `src/Newsletter/` und `src/Services/NewsletterPlaceholderService.php`. Bei Formatierungsfehlern `ddev composer phpcbf` laufen lassen und erneut prüfen.

- [ ] **Step 6: Commit**

```bash
git add src/Services/NewsletterPlaceholderService.php tests/Feature/NewsletterPlaceholderFeatureTest.php
git commit -m "feat(newsletter): Platzhalter im Betreff ohne HTML-Entities ersetzen"
```

---

### Task 4: Versand rendert pro Empfänger

**Files:**
- Modify: `src/Services/NewsletterService.php`
- Modify: `src/Services/NewsletterRecipientService.php:143-149`
- Modify: `src/Controllers/NewsletterController.php:668`
- Modify: `tests/Feature/NewsletterSendArchiveFeatureTest.php:142,187,219`
- Modify: `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` (Konstruktoraufruf von `NewsletterService`)
- Test: `tests/Feature/NewsletterPersonalizedSendFeatureTest.php`

**Interfaces:**
- Consumes: `NewsletterPlaceholderService::renderHtml()`, `renderSubject()`, `RenderContext::fromNewsletter()`
- Produces: `NewsletterService::send(Newsletter $newsletter, int $userId, string $baseUrl): int` — dritter Parameter ist neu und verpflichtend.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterPersonalizedSendFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Versand personalisiert Body und Betreff je Empfänger.
 */
final class NewsletterPersonalizedSendFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function service(): NewsletterService
    {
        return new NewsletterService(
            new NewsletterRecipientService(),
            new Mailer(new NullLogger()),
            new HtmlSanitizer(),
            new MailQueueService(),
            new NullLogger(),
            new NewsletterPlaceholderService(new NameFormatterService())
        );
    }

    private function createUser(string $firstName): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "personalized_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => 'Test',
            'is_active' => 1,
        ]);
    }

    public function testEachRecipientGetsOwnBodyAndSubject(): void
    {
        $creator = $this->createUser('Anna');
        $first = $this->createUser('Georg');
        $second = $this->createUser('Maria');

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Probenplan für {{vorname}}',
            'content_html' => '<p>{{anrede}}, willkommen bei {{app_name}}.</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        foreach ([$first, $second] as $recipient) {
            NewsletterRecipientSource::create([
                'newsletter_id' => $newsletter->id,
                'source_type' => NewsletterRecipientSource::TYPE_USER,
                'reference_id' => $recipient->id,
            ]);
        }

        $sentCount = $this->service()->send($newsletter, (int) $creator->id, 'https://chor.example');

        $this->assertSame(2, $sentCount);

        $queued = MailQueue::query()
            ->where('mail_type', 'newsletter')
            ->get()
            ->keyBy('recipient_email');

        $this->assertStringContainsString('Hallo Georg', (string) $queued[$first->email]->body_html);
        $this->assertStringContainsString('Hallo Maria', (string) $queued[$second->email]->body_html);
        $this->assertSame('Probenplan für Georg', (string) $queued[$first->email]->subject);
        $this->assertSame('Probenplan für Maria', (string) $queued[$second->email]->subject);
    }

    public function testStoredNewsletterKeepsRawTokens(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        $this->service()->send($newsletter, (int) $creator->id, 'https://chor.example');

        $this->assertStringContainsString('{{anrede}}', (string) $newsletter->fresh()->content_html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPersonalizedSendFeatureTest`
Expected: FAIL — `NewsletterService::__construct()` nimmt keinen sechsten Parameter

- [ ] **Step 3: Extend NewsletterService**

In `src/Services/NewsletterService.php` Feld und Konstruktor erweitern. Ein zusätzlicher Import ist nicht nötig — `NewsletterPlaceholderService` liegt im selben Namensraum `App\Services`:

```php
    private NewsletterPlaceholderService $placeholderService;

    public function __construct(
        NewsletterRecipientService $recipientService,
        Mailer $mailer,
        HtmlSanitizer $htmlSanitizer,
        MailQueueService $mailQueueService,
        LoggerInterface $logger,
        NewsletterPlaceholderService $placeholderService
    ) {
        $this->recipientService = $recipientService;
        $this->mailer = $mailer;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->mailQueueService = $mailQueueService;
        $this->logger = $logger;
        $this->placeholderService = $placeholderService;
    }
```

Signatur und Rumpf von `send()`. Ersetze die Zeilen ab `public function send(` bis einschließlich `$emailContent = ...`:

```php
    /**
     * Send a newsletter to all recipients
     *
     * @param Newsletter $newsletter
     * @param int $userId User ID who triggered the send
     * @param string $baseUrl Basisadresse für Links in Platzhaltern
     * @return int Number of recipients actually sent to (or that would have been sent to when disabled)
     * @throws Exception
     */
    public function send(Newsletter $newsletter, int $userId, string $baseUrl): int
```

Danach, direkt nach `$emailContent = $this->htmlSanitizer->sanitizeNewsletterHtml(...)`, den Kontext bauen. Der NameFormatter steckt bereits im Platzhalter-Service, deshalb läuft der Aufbau über dessen Fabrikmethode:

```php
        // Empfänger-unabhängige Werte einmal auflösen, nicht je Empfänger.
        $renderContext = $this->placeholderService->contextFor($newsletter, $baseUrl);
```

Ergänze dafür in `src/Services/NewsletterPlaceholderService.php` die Fabrikmethode:

```php
    public function contextFor(Newsletter $newsletter, string $baseUrl): RenderContext
    {
        return RenderContext::fromNewsletter($newsletter, $baseUrl, $this->nameFormatter);
    }
```

mit dem zusätzlichen Import `use App\Models\Newsletter;` in derselben Datei.

In der Empfängerschleife von `send()` ersetze den `enqueueNewsletterMail`-Aufruf:

```php
                $this->mailQueueService->enqueueNewsletterMail(
                    recipientEmail: $toEmail,
                    subject: $this->placeholderService->renderSubject(
                        (string) $newsletter->title,
                        $renderContext,
                        $recipient->user
                    ),
                    bodyHtml: $this->placeholderService->renderHtml(
                        $emailContent,
                        $renderContext,
                        $recipient->user
                    ),
                    newsletterId: (int) $newsletter->id,
                    recipientId: (int) $recipient->id
                );
```

- [ ] **Step 4: Add eager loading**

In `src/Services/NewsletterRecipientService.php`, Methode `getRecipients()`:

```php
        return NewsletterRecipient::query()
            ->with(['user.voiceGroups', 'user.subVoices'])
            ->where('newsletter_id', $newsletterId)
            ->get();
```

- [ ] **Step 5: Update the call sites**

In `src/Controllers/NewsletterController.php`, Import ergänzen (falls nicht vorhanden) und Aufruf anpassen:

```php
            $recipientCount = $this->newsletterService->send(
                $newsletter,
                $userId,
                AppUrlResolver::resolveBaseUrl($request)
            );
```

`use App\Util\AppUrlResolver;` steht noch nicht in dieser Datei — ergänzen.

In `tests/Feature/NewsletterSendArchiveFeatureTest.php` die drei `send()`-Aufrufe um `'https://chor.example'` als dritten Parameter erweitern und den dortigen `NewsletterService`-Konstruktor um
`new NewsletterPlaceholderService(new NameFormatterService())` ergänzen, samt Imports.

In `tests/Feature/NewsletterProjectDecouplingFeatureTest.php` denselben Konstruktor in `newsletterController()` ergänzen.

- [ ] **Step 6: Run the newsletter test suites**

Run: `ddev exec ./vendor/bin/phpunit --filter "Newsletter"`
Expected: PASS, keine Fehler

- [ ] **Step 7: Commit**

```bash
git add src/Services/NewsletterService.php src/Services/NewsletterPlaceholderService.php src/Services/NewsletterRecipientService.php src/Controllers/NewsletterController.php tests/Feature/
git commit -m "feat(newsletter): Versand rendert Platzhalter je Empfaenger"
```

---

### Task 5: Registry als JSON-Endpunkt

**Files:**
- Modify: `src/Controllers/NewsletterController.php`
- Modify: `src/Routes.php:541-548`
- Test: `tests/Feature/NewsletterPlaceholderEndpointFeatureTest.php`

**Interfaces:**
- Consumes: `NewsletterPlaceholderService::definitions()`
- Produces: `GET /newsletters/placeholders` → `{"placeholders":[{"key":"anrede","token":"{{anrede}}","label":"Anrede","description":"…","scope":"recipient","example":"Hallo Georg"}]}`; Controller-Methode `NewsletterController::placeholders(Request $request, Response $response): Response`
- Produces: endgültige Konstruktorsignatur von `NewsletterController` — die beiden neuen Parameter `NewsletterPlaceholderService $placeholderService` und `MailQueueService $mailQueueService` werden hier in einem Zug angehängt, damit spätere Tasks die Signatur nicht erneut ändern. `$mailQueueService` wird erst in Task 9 benutzt.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterPlaceholderEndpointFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterController;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Platzhalter-Liste für den Editor kommt aus der Registry, nicht aus dem JavaScript.
 */
final class NewsletterPlaceholderEndpointFeatureTest extends TestCase
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

    private function controller(): NewsletterController
    {
        return new NewsletterController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new NewsletterService(
                new NewsletterRecipientService(),
                new Mailer(new NullLogger()),
                new HtmlSanitizer(),
                new MailQueueService(),
                new NullLogger(),
                new NewsletterPlaceholderService(new NameFormatterService())
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            new MailQueueService()
        );
    }

    public function testPlaceholderListIsReturnedAsJson(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->controller()->placeholders(
            $this->makeRequest('GET', '/newsletters/placeholders'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $tokens = array_column($payload['placeholders'], 'token');

        $this->assertContains('{{vorname}}', $tokens);
        $this->assertContains('{{stimmgruppe}}', $tokens);
        $this->assertCount(13, $payload['placeholders']);
        $this->assertArrayHasKey('label', $payload['placeholders'][0]);
        $this->assertArrayHasKey('description', $payload['placeholders'][0]);
    }

    public function testPlaceholderListRequiresManagementRight(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_newsletters'] = false;

        $response = $this->controller()->placeholders(
            $this->makeRequest('GET', '/newsletters/placeholders'),
            $this->makeResponse()
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderEndpointFeatureTest`
Expected: FAIL — `NewsletterController::__construct()` nimmt keinen achten Parameter

- [ ] **Step 3: Extend the controller**

In `src/Controllers/NewsletterController.php`:

```php
use App\Services\MailQueueService;
use App\Services\NewsletterPlaceholderService;
use App\Util\AppUrlResolver;
```

Zwei Felder, zwei Konstruktorparameter am Ende der Liste (`NewsletterPlaceholderService $placeholderService`, danach `MailQueueService $mailQueueService`) und die beiden Zuweisungen ergänzen. `AppUrlResolver` und `MailQueueService` werden erst in Task 7 und 9 gebraucht; die Signatur wird trotzdem jetzt final gemacht, damit die Testklassen nicht zweimal angepasst werden müssen.

PHP-DI hat Autowiring aktiv, der neue Dienst würde also auch ohne Eintrag aufgelöst. Der Konvention der Datei folgend wird er trotzdem explizit registriert — in `src/Dependencies.php` neben `NewsletterService::class`:

```php
        NewsletterPlaceholderService::class => \DI\autowire(),
```

samt Import `use App\Services\NewsletterPlaceholderService;` im Kopf der Datei.

Neue Methode:

```php
    /**
     * Platzhalter-Registry für die Auswahlliste im Editor.
     */
    public function placeholders(Request $request, Response $response): Response
    {
        if (!$this->canManageNewsletters()) {
            return $this->jsonResponse($response, ['error' => 'Zugriff verweigert.'], 403);
        }

        $placeholders = [];
        foreach ($this->placeholderService->definitions() as $definition) {
            $placeholders[] = [
                'key' => $definition->key,
                'token' => '{{' . $definition->key . '}}',
                'label' => $definition->label,
                'description' => $definition->description,
                'scope' => $definition->scope,
                'example' => $definition->example,
            ];
        }

        return $this->jsonResponse($response, ['placeholders' => $placeholders]);
    }
```

- [ ] **Step 4: Register the route**

In `src/Routes.php`, innerhalb der Newsletter-Verwaltungsgruppe direkt nach `$newsletterGroup->get('/newsletters/create', ...)`:

```php
                        $newsletterGroup->get(
                            '/newsletters/placeholders',
                            [NewsletterController::class, 'placeholders']
                        );
```

Die Route steht bewusst vor den `{id:[0-9]+}`-Routen; deren numerische Bedingung greift für `placeholders` ohnehin nicht.

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderEndpointFeatureTest`
Expected: PASS, 2 Tests

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/NewsletterController.php src/Routes.php tests/Feature/NewsletterPlaceholderEndpointFeatureTest.php
git commit -m "feat(newsletter): Platzhalter-Registry als JSON-Endpunkt"
```

---

### Task 6: Einfügeknopf im Editor

**Files:**
- Modify: `public/js/tinymce-init.js`
- Modify: `templates/newsletters/create.twig:182`
- Modify: `templates/newsletters/edit.twig` (Textarea `content_html`)
- Test: `tests/Feature/NewsletterPlaceholderUiFeatureTest.php`

**Interfaces:**
- Consumes: `GET /newsletters/placeholders` aus Task 5
- Produces: Textarea-Attribut `data-placeholder-source="/newsletters/placeholders"`; TinyMCE-Toolbar-Eintrag `placeholders`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterPlaceholderUiFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Editor bindet die Platzhalter-Auswahl ohne Inline-JavaScript ein.
 */
final class NewsletterPlaceholderUiFeatureTest extends TestCase
{
    private function templatePath(string $name): string
    {
        return dirname(__DIR__, 2) . '/templates/newsletters/' . $name;
    }

    public function testEditorTemplatesDeclarePlaceholderSource(): void
    {
        foreach (['create.twig', 'edit.twig'] as $template) {
            $markup = (string) file_get_contents($this->templatePath($template));

            $this->assertStringContainsString(
                'data-placeholder-source="/newsletters/placeholders"',
                $markup,
                "Platzhalter-Quelle fehlt in {$template}"
            );
        }
    }

    public function testTinymceInitRegistersPlaceholderMenuButton(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/tinymce-init.js');

        $this->assertStringContainsString('addMenuButton', $script);
        $this->assertStringContainsString('placeholderSource', $script);
        $this->assertStringContainsString('insertContent', $script);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderUiFeatureTest`
Expected: FAIL mit `Platzhalter-Quelle fehlt in create.twig`

- [ ] **Step 3: Add the data attribute in both templates**

In `templates/newsletters/create.twig` die Textarea ersetzen:

```twig
                            <textarea id="content_html"
                                      name="content_html"
                                      class="tinymce-editor"
                                      data-placeholder-source="/newsletters/placeholders"></textarea>
```

In `templates/newsletters/edit.twig` dieselbe Ergänzung an der Textarea mit `id="content_html"` vornehmen; den bestehenden Inhalt der Textarea unverändert lassen.

- [ ] **Step 4: Extend tinymce-init.js**

Ersetze in `public/js/tinymce-init.js` den `tinymce.init`-Aufruf durch:

```javascript
        const placeholderSource = textarea.dataset.placeholderSource || '';
        const baseToolbar = 'undo redo | blocks | bold italic underline | forecolor | alignleft aligncenter alignright | bullist numlist outdent indent | link image media table | code fullscreen';

        tinymce.init({
            license_key: 'gpl',
            selector: '#' + textarea.id,
            language: 'de',
            language_url: '/vendor/tinymce/langs/de.js',
            plugins: 'image link media table lists code fullscreen',
            toolbar: placeholderSource ? baseToolbar + ' | placeholders' : baseToolbar,
            height: 400,
            menubar: 'file edit view insert format tools table help',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; }',
            promotion: false,
            setup: function (editor) {
                editor.on('change', function () {
                    tinymce.triggerSave();
                });

                if (!placeholderSource) {
                    return;
                }

                let placeholders = [];

                editor.on('init', function () {
                    fetch(placeholderSource, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (response) {
                            return response.ok ? response.json() : { placeholders: [] };
                        })
                        .then(function (data) {
                            placeholders = Array.isArray(data.placeholders) ? data.placeholders : [];
                        })
                        .catch(function () {
                            placeholders = [];
                        });
                });

                editor.ui.registry.addMenuButton('placeholders', {
                    text: 'Platzhalter',
                    tooltip: 'Platzhalter einfügen',
                    fetch: function (callback) {
                        callback(placeholders.map(function (placeholder) {
                            return {
                                type: 'menuitem',
                                text: placeholder.label + ' — ' + placeholder.token,
                                onAction: function () {
                                    // Als reiner Text einfügen: Formatierung innerhalb der
                                    // Klammern würde die Ersetzung beim Versand verhindern.
                                    editor.insertContent(placeholder.token);
                                }
                            };
                        }));
                    }
                });
            }
        });
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPlaceholderUiFeatureTest`
Expected: PASS, 2 Tests

- [ ] **Step 6: Run the Twig gate**

Run: `ddev composer twigcs`
Expected: keine blockierenden Meldungen. Bei Formatierungsfehlern `ddev composer twigcbf` und erneut prüfen.

- [ ] **Step 7: Commit**

```bash
git add public/js/tinymce-init.js templates/newsletters/create.twig templates/newsletters/edit.twig tests/Feature/NewsletterPlaceholderUiFeatureTest.php
git commit -m "feat(newsletter): Platzhalter ueber Editor-Menue einfuegen"
```

---

### Task 7: Vorschau mit Empfängerdaten

**Files:**
- Create: `tests/Feature/NewsletterControllerTestScaffold.php`
- Modify: `src/Controllers/NewsletterController.php:606-628`
- Modify: `templates/newsletters/preview.twig`
- Test: `tests/Feature/NewsletterPreviewPersonalizationFeatureTest.php`

**Interfaces:**
- Consumes: `NewsletterPlaceholderService::renderHtml()`, `contextFor()`
- Produces: `GET /newsletters/{id}/preview?recipient_id=<id>` — rendert für den gewählten Empfänger, 403 wenn dieser nicht zu den aufgelösten Empfängern gehört. Template erhält zusätzlich `preview_recipient_name` (string) und `preview_is_own_data` (bool).
- Produces: Trait `Tests\Feature\NewsletterControllerTestScaffold` mit `setUp()`, `tearDown()`, `createUser(string $firstName): User`, `createNewsletter(User $creator, User $recipient): Newsletter` und `controller(): NewsletterController`. Die Tasks 8, 9 und 10 bauen darauf auf, statt dieselbe Verdrahtung viermal zu wiederholen.

- [ ] **Step 1: Create the shared controller scaffold**

Die Tasks 7 bis 10 brauchen denselben verdrahteten Controller. Statt ihn viermal aufzubauen, kommt er einmal in ein Trait.

Create `tests/Feature/NewsletterControllerTestScaffold.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterController;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Die Vorschau löst Platzhalter live auf — für Empfänger mit deren eigenen Daten,
 * für Verwaltende wahlweise mit den Daten eines echten Empfängers.
 */
/**
 * Gemeinsames Gerüst für die Controller-Tests rund um Platzhalter: Datenbank je Test in einer
 * Transaktion, ein vollständig verdrahteter Controller und die immer gleichen Fixtures.
 */
trait NewsletterControllerTestScaffold
{
    use TestHttpHelpers;
    use TwigViewStubs;

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

    private function createUser(string $firstName): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "preview_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => 'Test',
            'is_active' => 1,
        ]);
    }

    private function controller(): NewsletterController
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $environment = $twig->getEnvironment();
        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('current_path', '/newsletters');
        $this->registerMailBadgeStub($environment);
        $environment->addFunction(new TwigFunction('asset_path', static fn (string $path): string => $path));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $context = NavigationContext::fromSession($_SESSION, [], '/newsletters', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return new NewsletterController(
            $twig,
            new NewsletterService(
                new NewsletterRecipientService(),
                new Mailer(new NullLogger()),
                new HtmlSanitizer(),
                new MailQueueService(),
                new NullLogger(),
                new NewsletterPlaceholderService(new NameFormatterService())
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            new MailQueueService()
        );
    }

    private function createNewsletter(User $creator, User $recipient): Newsletter
    {
        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Probenplan',
            'content_html' => '<p>{{anrede}}</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        return $newsletter;
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/NewsletterPreviewPersonalizationFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NewsletterArchive;
use PHPUnit\Framework\TestCase;

/**
 * Die Vorschau löst Platzhalter live auf — für Empfänger mit deren eigenen Daten,
 * für Verwaltende wahlweise mit den Daten eines echten Empfängers.
 */
final class NewsletterPreviewPersonalizationFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testRecipientSeesOwnDataInArchive(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => '2026-08-18 10:00:00',
        ]);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Hallo Georg', (string) $response->getBody());
    }

    public function testManagerCanPreviewAsResolvedRecipient(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview",
            [],
            ['recipient_id' => (string) $recipient->id]
        )->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Hallo Georg', (string) $response->getBody());
    }

    public function testManagerCannotPreviewAsUnrelatedUser(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $outsider = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview",
            [],
            ['recipient_id' => (string) $outsider->id]
        )->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testManagerWithoutParameterSeesOwnData(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Hallo Anna', $body);
        $this->assertStringContainsString('eigenen Daten', $body);
    }
}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPreviewPersonalizationFeatureTest`
Expected: FAIL — Vorschau zeigt `{{anrede}}` statt `Hallo Georg`

- [ ] **Step 4: Rewrite preview()**

Ersetze `preview()` in `src/Controllers/NewsletterController.php`:

```php
    public function preview(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $queryParams = $request->getQueryParams();
        $isModal = ((string) ($queryParams['modal'] ?? '0')) === '1';
        $userId = $_SESSION['user_id'] ?? null;
        $canManage = $this->canManageNewsletters();

        if (!$canManage && !$this->canAccessReceivedNewsletterById($id, $userId)) {
            return $response->withStatus(403);
        }

        $newsletter = Newsletter::find($id);

        if (!$newsletter) {
            return $response->withStatus(404);
        }

        $requestedRecipientId = (int) ($queryParams['recipient_id'] ?? 0);
        $viewer = $userId === null ? null : User::find((int) $userId);
        $previewRecipient = $viewer;
        $isOwnData = true;

        // Fremde Empfängerdaten darf nur sehen, wer den Newsletter verwaltet, und auch
        // dann nur für Personen, die tatsächlich zu den Empfängern zählen. Ohne diese
        // Prüfung würde die Route beliebige Nutzerdaten preisgeben.
        if ($canManage && $requestedRecipientId > 0) {
            $isResolvedRecipient = $this->recipientService
                ->resolveRecipients($newsletter)
                ->contains(static fn ($user): bool => (int) $user->id === $requestedRecipientId);

            if (!$isResolvedRecipient) {
                return $response->withStatus(403);
            }

            $previewRecipient = User::find($requestedRecipientId);
            $isOwnData = false;
        }

        $sanitized = $this->htmlSanitizer->sanitizeNewsletterHtml((string) $newsletter->content_html);
        $context = $this->placeholderService->contextFor(
            $newsletter,
            AppUrlResolver::resolveBaseUrl($request)
        );

        return $this->view->render($response, 'newsletters/preview.twig', [
            'newsletter' => $newsletter,
            'preview_content_html' => $this->placeholderService->renderHtml($sanitized, $context, $previewRecipient),
            'preview_title' => $this->placeholderService->renderSubject(
                (string) $newsletter->title,
                $context,
                $previewRecipient
            ),
            'preview_recipient_name' => $previewRecipient === null
                ? ''
                : $this->nameFormatter->formatPerson($previewRecipient),
            'preview_is_own_data' => $isOwnData,
            'is_modal' => $isModal,
        ]);
    }
```

- [ ] **Step 5: Show the personalization hint in the template**

In `templates/newsletters/preview.twig` den Kartenkopf ersetzen:

```twig
                    <div class="card-header bg-light">
                        <h5 class="mb-1">{{ preview_title|default(newsletter.title) }}</h5>
                        <small class="text-muted">
                            Von: {{ newsletter.createdBy|person_name }}
                            | Erstellt: {{ newsletter.created_at|date("d.m.Y H:i") }}
                        </small>
                        {% if preview_recipient_name is defined and preview_recipient_name != "" %}
                            <p class="text-muted small mb-0 mt-2">
                                {% if preview_is_own_data|default(false) %}
                                    Platzhalter sind mit deinen eigenen Daten gefüllt.
                                {% else %}
                                    Platzhalter sind mit den Daten von {{ preview_recipient_name }} gefüllt.
                                {% endif %}
                            </p>
                        {% endif %}
                    </div>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPreviewPersonalizationFeatureTest`
Expected: PASS, 4 Tests

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/NewsletterController.php templates/newsletters/preview.twig tests/Feature/NewsletterControllerTestScaffold.php tests/Feature/NewsletterPreviewPersonalizationFeatureTest.php
git commit -m "feat(newsletter): Vorschau loest Platzhalter mit Empfaengerdaten auf"
```

---

### Task 8: Vorschau des ungespeicherten Entwurfs

Die Modal-Vorschau im Editor zeigt heute den Editor-Inhalt direkt im Browser an, also unersetzte Token. Sie braucht eine Server-Runde, weil nur der Server die Registry kennt. Das ist eine Ergänzung zur Spec, die deren Vorschau-Anforderung erst erfüllbar macht.

**Files:**
- Modify: `src/Controllers/NewsletterController.php`
- Modify: `src/Routes.php`
- Modify: `templates/newsletters/edit.twig`
- Modify: `public/js/newsletters-edit.js:370-393`
- Test: `tests/Feature/NewsletterPreviewRenderEndpointFeatureTest.php`

**Interfaces:**
- Produces: `POST /newsletters/{id}/preview-render` mit Body `{title, content_html, recipient_id}` → `{"title": "…", "content_html": "…"}`; Controller-Methode `NewsletterController::previewRender(Request $request, Response $response): Response`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterPreviewRenderEndpointFeatureTest.php`. Das Gerüst kommt aus dem Trait von Task 7; die Datei enthält nur Kopf und Testfälle:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Editor-Stand wird serverseitig gerendert, bevor er in der Vorschau erscheint.
 */
final class NewsletterPreviewRenderEndpointFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;
```

Die folgenden Testmethoden gehören in diese Klasse, danach schließt eine `}` die Klasse:


```php
    public function testUnsavedContentIsRenderedForSelectedRecipient(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/preview-render", [
            'title' => 'Info für {{vorname}}',
            'content_html' => '<p>{{anrede}}</p>',
            'recipient_id' => (string) $recipient->id,
        ])->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->previewRender($request, $this->makeResponse());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Info für Georg', $payload['title']);
        $this->assertSame('<p>Hallo Georg</p>', $payload['content_html']);
    }

    public function testRenderEndpointRejectsUnrelatedRecipient(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $outsider = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/preview-render", [
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
            'recipient_id' => (string) $outsider->id,
        ])->withAttribute('id', (string) $newsletter->id);

        $this->assertSame(403, $this->controller()->previewRender($request, $this->makeResponse())->getStatusCode());
    }

    public function testRenderEndpointRequiresManagementRight(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/preview-render", [
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->assertSame(403, $this->controller()->previewRender($request, $this->makeResponse())->getStatusCode());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPreviewRenderEndpointFeatureTest`
Expected: FAIL mit `Call to undefined method App\Controllers\NewsletterController::previewRender()`

- [ ] **Step 3: Implement previewRender()**

In `src/Controllers/NewsletterController.php`:

```php
    /**
     * Rendert den noch nicht gespeicherten Editor-Inhalt mit den Daten eines Empfängers.
     */
    public function previewRender(Request $request, Response $response): Response
    {
        if (!$this->canManageNewsletters()) {
            return $this->jsonResponse($response, ['error' => 'Zugriff verweigert.'], 403);
        }

        $id = (int) $request->getAttribute('id');
        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $this->jsonResponse($response, ['error' => 'Newsletter wurde nicht gefunden.'], 404);
        }

        $data = (array) $request->getParsedBody();
        $requestedRecipientId = (int) ($data['recipient_id'] ?? 0);
        $userId = $_SESSION['user_id'] ?? null;
        $recipient = $userId === null ? null : User::find((int) $userId);

        if ($requestedRecipientId > 0) {
            $isResolvedRecipient = $this->recipientService
                ->resolveRecipients($newsletter)
                ->contains(static fn ($user): bool => (int) $user->id === $requestedRecipientId);

            if (!$isResolvedRecipient) {
                return $this->jsonResponse($response, ['error' => 'Unbekannter Empfänger.'], 403);
            }

            $recipient = User::find($requestedRecipientId);
        }

        $context = $this->placeholderService->contextFor(
            $newsletter,
            AppUrlResolver::resolveBaseUrl($request)
        );
        $sanitized = $this->htmlSanitizer->sanitizeNewsletterHtml((string) ($data['content_html'] ?? ''));

        return $this->jsonResponse($response, [
            'title' => $this->placeholderService->renderSubject(
                trim((string) ($data['title'] ?? '')),
                $context,
                $recipient
            ),
            'content_html' => $this->placeholderService->renderHtml($sanitized, $context, $recipient),
        ]);
    }
```

- [ ] **Step 4: Register the route**

In `src/Routes.php`, in der Newsletter-Verwaltungsgruppe nach der `send`-Route:

```php
                        $newsletterGroup->post(
                            '/newsletters/{id:[0-9]+}/preview-render',
                            [NewsletterController::class, 'previewRender']
                        );
```

- [ ] **Step 5: Add the recipient selector to the editor**

In `templates/newsletters/edit.twig` direkt vor dem Vorschau-Knopf:

```twig
                    <select id="preview-recipient" class="form-select form-select-sm w-auto" aria-label="Vorschau für">
                        <option value="">Vorschau mit eigenen Daten</option>
                        {% for user in users %}
                            <option value="{{ user.id }}">{{ user|person_name }}</option>
                        {% endfor %}
                    </select>
```

- [ ] **Step 6: Fetch the rendered preview in JS**

Ersetze in `public/js/newsletters-edit.js` den Rumpf des Klick-Handlers ab `const previewTitle = ...` durch:

```javascript
            const previewTitle = document.getElementById("preview-modal-title");
            const previewProject = document.getElementById("preview-modal-project");
            const previewContent = document.getElementById("preview-modal-content");
            const recipientSelect = document.getElementById("preview-recipient");
            const editor = typeof tinymce !== "undefined" ? tinymce.get("content_html") : null;

            if (previewProject && projectSelect) {
                const selectedProject = projectSelect.options[projectSelect.selectedIndex];
                previewProject.textContent = selectedProject ? selectedProject.textContent.trim() : "";
            }

            const body = new FormData();
            body.set("title", titleInput && titleInput.value ? titleInput.value : "Ohne Titel");
            body.set("content_html", editor ? editor.getContent() : "");
            body.set("recipient_id", recipientSelect ? recipientSelect.value : "");

            fetch(`/newsletters/${newsletterId}/preview-render`, {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: body
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (!data) {
                        return;
                    }

                    if (previewTitle) {
                        previewTitle.textContent = data.title || "Ohne Titel";
                    }

                    if (previewContent) {
                        previewContent.innerHTML = data.content_html || "";
                    }
                })
                .catch(function () {
                    if (previewContent) {
                        previewContent.textContent = "Vorschau konnte nicht geladen werden.";
                    }
                });
```

Die Zeile mit `window.newsletterModalNavigate` am Anfang des Handlers bleibt unverändert.

- [ ] **Step 7: Run tests**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterPreviewRenderEndpointFeatureTest`
Expected: PASS, 3 Tests

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/NewsletterController.php src/Routes.php templates/newsletters/edit.twig public/js/newsletters-edit.js tests/Feature/NewsletterPreviewRenderEndpointFeatureTest.php
git commit -m "feat(newsletter): Vorschau rendert ungespeicherten Entwurf serverseitig"
```

---

### Task 9: Testmail an die eigene Adresse

**Files:**
- Modify: `src/Services/MailQueueService.php:22-39`
- Modify: `src/Controllers/NewsletterController.php`
- Modify: `src/Routes.php`
- Modify: `templates/newsletters/edit.twig`
- Modify: `public/js/newsletters-edit.js`
- Test: `tests/Feature/NewsletterTestMailFeatureTest.php`

**Interfaces:**
- Consumes: `NewsletterLockingService::isLockedBy()`, `NewsletterPlaceholderService`
- Produces: `MailQueueService::enqueueNewsletterTestMail(string $recipientEmail, string $subject, string $bodyHtml, int $newsletterId): MailQueue`
- Produces: `POST /newsletters/{id}/test-mail` → `{"success":true,"message":"Testmail wurde eingereiht."}`; Controller-Methode `NewsletterController::testMail(Request $request, Response $response): Response`

`MailQueueService::enqueueGenericMail()` ist private und darf vom Controller nicht aufgerufen werden — deshalb die neue öffentliche Methode.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterTestMailFeatureTest.php`. Das Gerüst kommt aus dem Trait von Task 7; die Datei enthält nur Kopf und Testfälle:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use PHPUnit\Framework\TestCase;

/**
 * Die Testmail geht ausschliesslich an die eigene Adresse und beruehrt den Versand nicht.
 */
final class NewsletterTestMailFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;
```

Die folgenden Testmethoden gehören in diese Klasse, danach schließt eine `}` die Klasse:


```php
    public function testTestMailGoesToOwnAddressAndCreatesNoRecipientRows(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/test-mail", [
            'title' => 'Probe für {{vorname}}',
            'content_html' => '<p>{{anrede}}</p>',
        ])->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->testMail($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());

        $queued = MailQueue::query()->where('recipient_email', $creator->email)->first();

        $this->assertNotNull($queued);
        $this->assertSame('Probe für Anna', (string) $queued->subject);
        $this->assertStringContainsString('Hallo Anna', (string) $queued->body_html);
        $this->assertSame(0, NewsletterRecipient::query()->where('newsletter_id', $newsletter->id)->count());
        $this->assertSame(0, NewsletterArchive::query()->where('newsletter_id', $newsletter->id)->count());
        $this->assertSame(Newsletter::STATUS_DRAFT, $newsletter->fresh()->status);
    }

    public function testTestMailIgnoresRecipientAddressFromRequest(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/test-mail", [
            'title' => 'Probe',
            'content_html' => '<p>Inhalt</p>',
            'recipient_email' => 'angriff@example.test',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->controller()->testMail($request, $this->makeResponse());

        $this->assertSame(0, MailQueue::query()->where('recipient_email', 'angriff@example.test')->count());
        $this->assertSame(1, MailQueue::query()->where('recipient_email', $creator->email)->count());
    }

    public function testTestMailRequiresManagementRight(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/test-mail", [
            'title' => 'Probe',
            'content_html' => '<p>Inhalt</p>',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->assertSame(403, $this->controller()->testMail($request, $this->makeResponse())->getStatusCode());
    }
```


- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterTestMailFeatureTest`
Expected: FAIL mit `Call to undefined method App\Controllers\NewsletterController::testMail()`

- [ ] **Step 3: Add the public queue method**

In `src/Services/MailQueueService.php` neben `enqueueNewsletterMail()`:

```php
    /**
     * Testmail eines Newsletters an die auslösende Person. Ohne Empfängerzeile, weil
     * die Testmail nicht Teil des Versands ist und keine Zustellstatistik erzeugen darf.
     *
     * @throws Exception
     */
    public function enqueueNewsletterTestMail(
        string $recipientEmail,
        string $subject,
        string $bodyHtml,
        int $newsletterId
    ): MailQueue {
        return $this->enqueueGenericMail(
            mailType: 'newsletter',
            recipientEmail: $recipientEmail,
            subject: $subject,
            bodyHtml: $bodyHtml,
            payload: [
                'newsletter_id' => $newsletterId,
                'test_mail' => true,
            ]
        );
    }
```

- [ ] **Step 4: Implement testMail()**

Der Konstruktor von `NewsletterController` hat `MailQueueService` bereits aus Task 5.

```php
    /**
     * Schickt den aktuellen Editor-Stand als Testmail an die eigene Adresse.
     * Die Zieladresse stammt aus der Sitzung, nie aus dem Request.
     */
    public function testMail(Request $request, Response $response): Response
    {
        if (!$this->canManageNewsletters()) {
            return $this->jsonResponse($response, ['error' => 'Zugriff verweigert.'], 403);
        }

        $id = (int) $request->getAttribute('id');
        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $this->jsonResponse($response, ['error' => 'Newsletter wurde nicht gefunden.'], 404);
        }

        $userId = $_SESSION['user_id'] ?? null;
        $sender = $userId === null ? null : User::find((int) $userId);
        $senderEmail = trim((string) ($sender->email ?? ''));

        if ($sender === null || filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            return $this->jsonResponse($response, ['error' => 'Keine gültige eigene E-Mail-Adresse.'], 422);
        }

        if ($newsletter->isLocked() && !$this->lockingService->isLockedBy($newsletter, $userId)) {
            return $this->jsonResponse(
                $response,
                ['error' => 'Newsletter wird gerade von einer anderen Person bearbeitet.'],
                409
            );
        }

        $data = (array) $request->getParsedBody();
        $context = $this->placeholderService->contextFor(
            $newsletter,
            AppUrlResolver::resolveBaseUrl($request)
        );
        $sanitized = $this->htmlSanitizer->sanitizeNewsletterHtml((string) ($data['content_html'] ?? ''));

        $this->mailQueueService->enqueueNewsletterTestMail(
            recipientEmail: $senderEmail,
            subject: $this->placeholderService->renderSubject(
                trim((string) ($data['title'] ?? '')),
                $context,
                $sender
            ),
            bodyHtml: $this->placeholderService->renderHtml($sanitized, $context, $sender),
            newsletterId: (int) $newsletter->id
        );

        $this->logger->info('Newsletter test mail enqueued.', [
            'event' => 'newsletter.test_mail.enqueued',
            'newsletter_id' => (int) $newsletter->id,
            'user_id' => (int) $sender->id,
        ]);

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Testmail wurde eingereiht.',
        ]);
    }
```

- [ ] **Step 5: Register the route**

In `src/Routes.php`, nach der `preview-render`-Route:

```php
                        $newsletterGroup->post(
                            '/newsletters/{id:[0-9]+}/test-mail',
                            [NewsletterController::class, 'testMail']
                        );
```

- [ ] **Step 6: Add the button and its handler**

In `templates/newsletters/edit.twig` neben dem Vorschau-Knopf:

```twig
                    <button type="button" class="btn btn-outline-secondary" id="test-mail-btn">
                        <i class="bi bi-envelope-check"></i> Testmail
                    </button>
```

In `public/js/newsletters-edit.js` am Ende der Initialisierung:

```javascript
    const testMailButton = document.getElementById("test-mail-btn");
    if (testMailButton) {
        testMailButton.addEventListener("click", function () {
            const editor = typeof tinymce !== "undefined" ? tinymce.get("content_html") : null;
            const body = new FormData();
            body.set("title", titleInput && titleInput.value ? titleInput.value : "Ohne Titel");
            body.set("content_html", editor ? editor.getContent() : "");

            testMailButton.disabled = true;

            fetch(`/newsletters/${newsletterId}/test-mail`, {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: body
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    window.alert(data.message || data.error || "Testmail konnte nicht gesendet werden.");
                })
                .catch(function () {
                    window.alert("Testmail konnte nicht gesendet werden.");
                })
                .finally(function () {
                    testMailButton.disabled = false;
                });
        });
    }
```

- [ ] **Step 7: Run tests**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterTestMailFeatureTest`
Expected: PASS, 3 Tests

- [ ] **Step 8: Commit**

```bash
git add src/Services/MailQueueService.php src/Controllers/NewsletterController.php src/Routes.php templates/newsletters/edit.twig public/js/newsletters-edit.js tests/Feature/NewsletterTestMailFeatureTest.php
git commit -m "feat(newsletter): Testmail an eigene Adresse"
```

---

### Task 10: Warnung bei unbekannten Platzhaltern

**Files:**
- Modify: `src/Controllers/NewsletterController.php` (`store()`, `update()`, `send()`)
- Test: `tests/Feature/NewsletterUnknownPlaceholderFeatureTest.php`

**Interfaces:**
- Consumes: `NewsletterPlaceholderService::findUnknownTokens()`
- Produces: `store()` und `update()` liefern zusätzlich `warnings: string[]` im JSON; `send()` blockiert nicht, sondern hängt die Warnung an die Erfolgsmeldung.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterUnknownPlaceholderFeatureTest.php`. Das Gerüst kommt aus dem Trait von Task 7; die Datei enthält nur Kopf und Testfälle:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NewsletterLockingService;
use PHPUnit\Framework\TestCase;

/**
 * Unbekannte Platzhalter werden gemeldet, aber nicht aus dem Text entfernt.
 */
final class NewsletterUnknownPlaceholderFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;
```

Die folgenden Testmethoden gehören in diese Klasse, danach schließt eine `}` die Klasse:


```php
    public function testUpdateReturnsWarningForUnknownTokens(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info {{tippfehler}}',
            'content_html' => '<p>{{anrede}} {{quatsch}}</p>',
            'suppress_flash' => '1',
        ])->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->update($request, $this->makeResponse());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($payload['warnings']);
        $this->assertStringContainsString('tippfehler', implode(' ', $payload['warnings']));
        $this->assertStringContainsString('quatsch', implode(' ', $payload['warnings']));
    }

    public function testUpdateWithoutUnknownTokensHasNoWarnings(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
            'suppress_flash' => '1',
        ])->withAttribute('id', (string) $newsletter->id);

        $payload = json_decode(
            (string) $this->controller()->update($request, $this->makeResponse())->getBody(),
            true
        );

        $this->assertSame([], $payload['warnings']);
    }

    public function testUnknownTokenSurvivesInStoredContent(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info',
            'content_html' => '<p>{{quatsch}}</p>',
            'suppress_flash' => '1',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->controller()->update($request, $this->makeResponse());

        $this->assertStringContainsString('{{quatsch}}', (string) $newsletter->fresh()->content_html);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterUnknownPlaceholderFeatureTest`
Expected: FAIL mit `Undefined array key "warnings"`

- [ ] **Step 3: Build the warning helper**

In `src/Controllers/NewsletterController.php`:

```php
    /**
     * Unbekannte Platzhalter melden, aber nicht entfernen: ein Tippfehler soll auffallen,
     * ohne dass Text stillschweigend verschwindet.
     *
     * @return array<int, string>
     */
    private function placeholderWarnings(string $title, string $contentHtml): array
    {
        $unknown = array_values(array_unique(array_merge(
            $this->placeholderService->findUnknownTokens($title),
            $this->placeholderService->findUnknownTokens($contentHtml)
        )));

        if ($unknown === []) {
            return [];
        }

        $tokens = implode(', ', array_map(static fn (string $key): string => '{{' . $key . '}}', $unknown));

        return ['Unbekannte Platzhalter bleiben unverändert stehen: ' . $tokens];
    }
```

- [ ] **Step 4: Use it in store(), update() and send()**

In `store()` die Erfolgsantwort ersetzen:

```php
        return $this->jsonResponse($response, [
            'id' => $newsletter->id,
            'redirect' => "/newsletters/{$newsletter->id}/edit" . ($isModal ? '?modal=1' : ''),
            'warnings' => $this->placeholderWarnings(
                (string) $validation['payload']['title'],
                (string) $validation['payload']['content_html']
            ),
        ], 201);
```

In `update()`:

```php
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Newsletter gespeichert',
            'warnings' => $this->placeholderWarnings(
                (string) $validation['payload']['title'],
                (string) $validation['payload']['content_html']
            ),
        ]);
```

In `send()` direkt vor `$recipientCount = $this->newsletterService->send(...)`:

```php
        $warnings = $this->placeholderWarnings(
            (string) $newsletter->title,
            (string) $newsletter->content_html
        );
```

und nach dem Setzen von `$_SESSION['success']`:

```php
        if ($warnings !== []) {
            $_SESSION['warning'] = implode(' ', $warnings);
        }
```

- [ ] **Step 5: Show warnings in the editor JS**

In `public/js/newsletters-edit.js` an der Stelle, an der die Antwort von `update` verarbeitet wird, nach dem Erfolgspfad ergänzen:

```javascript
            if (Array.isArray(data.warnings) && data.warnings.length > 0) {
                window.alert(data.warnings.join("\n"));
            }
```

- [ ] **Step 6: Run tests**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterUnknownPlaceholderFeatureTest`
Expected: PASS, 3 Tests

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/NewsletterController.php public/js/newsletters-edit.js tests/Feature/NewsletterUnknownPlaceholderFeatureTest.php
git commit -m "feat(newsletter): Warnung bei unbekannten Platzhaltern"
```

---

### Task 11: Seed-Daten mit Platzhaltern

**Files:**
- Modify: `src/Services/DevSeedService.php:3233-3410` (Methode `seedNewsletters`)
- Test: `tests/Feature/NewsletterSeedPlaceholderFeatureTest.php`

**Interfaces:**
- Consumes: bestehende Seed-Struktur; keine neuen Tabellen, daher keine Änderung an `resetSeedData()` und keine neuen Zähler.
- Produces: mindestens ein Entwurf mit Platzhalter im Betreff, Platzhalter in Vorlagen und Newsletter-Inhalten, ein aktives Mitglied ohne Vornamen als Empfänger.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterSeedPlaceholderFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die Dev-Seed liefert Newsletter, an denen sich Platzhalter sofort ausprobieren lassen.
 */
final class NewsletterSeedPlaceholderFeatureTest extends TestCase
{
    private function seedSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');
    }

    public function testSeedContentUsesPlaceholders(): void
    {
        $source = $this->seedSource();

        $this->assertStringContainsString('{{anrede}}', $source);
        $this->assertStringContainsString('{{stimmgruppe}}', $source);
        $this->assertStringContainsString('{{archiv_link}}', $source);
    }

    public function testSeedContainsDraftWithPlaceholderInSubject(): void
    {
        $this->assertStringContainsString(
            "'title' => 'Entwurf für {{vorname}}: Probenplan'",
            $this->seedSource()
        );
    }

    public function testSeedContainsMemberWithoutFirstName(): void
    {
        $this->assertStringContainsString('placeholder_fallback', $this->seedSource());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterSeedPlaceholderFeatureTest`
Expected: FAIL, alle drei Fälle

- [ ] **Step 3: Put placeholders into the seeded content**

In `src/Services/DevSeedService.php`, Methode `seedNewsletters()`:

Die Vorlage „Newsletter Standard" bekommt Platzhalter:

```php
            [
                'name' => 'Newsletter Standard',
                'category' => 'general',
                'content_html' => '<h2>Newsletter</h2>' .
                    '<p>{{anrede}},</p>' .
                    '<p>hier sind die wichtigsten Neuigkeiten zu {{projekt}}:</p>' .
                    '<ul><li>Informationen</li><li>Ankündigungen</li><li>Sonstiges</li></ul>' .
                    '<p>Deine Stimmgruppe: {{stimmgruppe}}</p>' .
                    '<p>{{archiv_link}}</p>',
            ],
```

Der gesendete Newsletter je Projekt:

```php
                    'content_html' => '<h2>Newsletter {{projekt}}</h2>' .
                        '<p>{{anrede}},</p>' .
                        '<p>aktuelle Informationen zum Projekt, versendet am {{datum}}.</p>' .
                        '<p>Deine Stimmgruppe: {{stimmgruppe}}</p>' .
                        '<p>{{archiv_link}}</p>',
```

Der Entwurf je Projekt bekommt einen Platzhalter im Betreff:

```php
                'title' => 'Entwurf für {{vorname}}: Probenplan',
                'content_html' => '<h2>Editierbar</h2>' .
                    '<p>{{anrede}}, dieser Entwurf zeigt Platzhalter im Betreff und im Text.</p>' .
                    '<p>Angelegt von {{absender}} in {{app_name}}.</p>',
```

- [ ] **Step 4: Seed a member without a first name**

Am Ende von `seedNewsletters()`, vor dem Rücksprung, ein aktives Mitglied ohne Vornamen anlegen und dem projektlosen Entwurf als Einzelempfänger zuordnen:

```php
        // Fallback-Pfad der Platzhalter live prüfbar machen: ohne Vornamen muss
        // {{anrede}} zu "Hallo" werden statt zu "Hallo ,".
        $fallbackMember = User::updateOrCreate(
            ['email' => 'placeholder_fallback@example.test'],
            [
                'password' => password_hash('test1234', PASSWORD_BCRYPT),
                'first_name' => '',
                'last_name' => 'Ohnevorname',
                'is_active' => 1,
            ]
        );

        NewsletterRecipientSource::create([
            'newsletter_id' => $generalDraft->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $fallbackMember->id,
        ]);
        $this->report['counts']['newsletter_recipient_sources']++;

        NewsletterRecipient::create([
            'newsletter_id' => $generalDraft->id,
            'user_id' => $fallbackMember->id,
            'status' => 'pending',
        ]);
        $this->report['counts']['newsletter_recipients']++;
```

Prüfe dabei, dass `$generalDraft` an dieser Stelle im Sichtbereich liegt; andernfalls den Block direkt nach dem Anlegen von `$generalDraft` einsetzen. Der Zähler `users` im Report wird nicht erhöht, weil `updateOrCreate` beim zweiten Lauf nichts Neues anlegt — wenn die Methode ohnehin einen Nutzerzähler pflegt, nur bei `wasRecentlyCreated` hochzählen.

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterSeedPlaceholderFeatureTest`
Expected: PASS, 3 Tests

- [ ] **Step 6: Run a real dev seed**

Run: `ddev composer seed:dev`
Expected: Lauf endet ohne Fehler. Im Bericht müssen `newsletters`, `newsletter_recipients`, `newsletter_recipient_sources` und `newsletter_templates` mit Zahlen größer null stehen. Bericht in die Abschlussmeldung übernehmen.

- [ ] **Step 7: Verify the seeded data in the app**

Run: `ddev exec ./vendor/bin/phpunit --filter "Newsletter"`
Expected: PASS. Zusätzlich in der laufenden Dev-Instanz einen Entwurf öffnen und die Vorschau prüfen: statt `{{anrede}}` muss die Begrüßung stehen.

- [ ] **Step 8: Commit**

```bash
git add src/Services/DevSeedService.php tests/Feature/NewsletterSeedPlaceholderFeatureTest.php
git commit -m "feat(newsletter): Seed-Daten mit Platzhaltern"
```

---

### Task 12: Hilfetext

**Files:**
- Modify: `help/newsletter/docs/newsletter-compose.md`
- Test: `tests/Feature/NewsletterHelpDocFeatureTest.php`

**Interfaces:**
- Consumes: Registry-Keys aus Task 1
- Produces: Abschnitt „Platzhalter" mit Tabelle aller dreizehn Token

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NewsletterHelpDocFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NameFormatterService;
use App\Services\NewsletterPlaceholderService;
use PHPUnit\Framework\TestCase;

/**
 * Der Hilfetext dokumentiert jeden Platzhalter, den die Registry kennt.
 */
final class NewsletterHelpDocFeatureTest extends TestCase
{
    public function testHelpDocumentsEveryPlaceholder(): void
    {
        $doc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/help/newsletter/docs/newsletter-compose.md'
        );

        foreach (array_keys((new NewsletterPlaceholderService(new NameFormatterService()))->definitions()) as $key) {
            $this->assertStringContainsString('{{' . $key . '}}', $doc, "Platzhalter fehlt in der Hilfe: {$key}");
        }
    }

    public function testHelpAvoidsRoleNames(): void
    {
        $doc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/help/newsletter/docs/newsletter-compose.md'
        );

        foreach (['Vorstand', 'Kassier', 'Admin-Rolle'] as $roleName) {
            $this->assertStringNotContainsString($roleName, $doc, "Rollenname in der Hilfe: {$roleName}");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterHelpDocFeatureTest`
Expected: FAIL mit `Platzhalter fehlt in der Hilfe: anrede`

- [ ] **Step 3: Write the help section**

An `help/newsletter/docs/newsletter-compose.md` anhängen:

```markdown
## Platzhalter

Platzhalter werden beim Versand für jede empfangende Person einzeln ersetzt. Du schreibst sie in
den Text oder in den Betreff — entweder über den Knopf **Platzhalter** in der Editor-Leiste oder
von Hand in doppelten geschweiften Klammern.

| Platzhalter | Ergebnis | Wenn der Wert fehlt |
|---|---|---|
| `{{anrede}}` | Hallo Georg | Hallo |
| `{{vorname}}` | Georg | bleibt leer |
| `{{nachname}}` | Pitterle | bleibt leer |
| `{{name}}` | Georg Pitterle | die E-Mail-Adresse |
| `{{email}}` | georg@example.at | — |
| `{{stimmgruppe}}` | Sopran (Sopran 1) | ohne Stimmgruppe |
| `{{titel}}` | Betreff des Newsletters | bleibt leer |
| `{{projekt}}` | Name des Projekts | bleibt leer |
| `{{datum}}` | Versanddatum | — |
| `{{absender}}` | Person, die den Newsletter angelegt hat | bleibt leer |
| `{{app_name}}` | Name dieser Anwendung | — |
| `{{login_url}}` | Adresse der Anwendung | — |
| `{{archiv_link}}` | Link „Im Browser ansehen" | — |

**Vorher prüfen.** Über der Vorschau wählst du eine empfangende Person aus; die Vorschau zeigt
dann deren Werte. Ohne Auswahl siehst du deine eigenen Daten. Der Knopf **Testmail** schickt den
aktuellen Stand an deine eigene Adresse, ohne die Empfängerliste zu berühren.

**Tippfehler.** Ein unbekannter Platzhalter wie `{{vorrname}}` wird nicht ersetzt, sondern bleibt
im Text stehen. Beim Speichern erscheint dazu ein Hinweis.

**Formatierung.** Setze innerhalb der Klammern keine Formatierung wie Fettdruck — der Platzhalter
wird dann nicht mehr erkannt. Am sichersten ist der Knopf **Platzhalter** in der Editor-Leiste.

**E-Mail-Adressen.** `{{email}}` schreibt die Adresse der empfangenden Person in den Text. Wird
die Mail weitergeleitet, ist die Adresse mit unterwegs.

Fehlt dir der Knopf **Platzhalter**, hast du kein Recht zum Verwalten von Newslettern. Wende dich
an den Administrator.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec ./vendor/bin/phpunit --filter NewsletterHelpDocFeatureTest`
Expected: PASS, 2 Tests

- [ ] **Step 5: Commit**

```bash
git add help/newsletter/docs/newsletter-compose.md tests/Feature/NewsletterHelpDocFeatureTest.php
git commit -m "docs(newsletter): Hilfetext zu Platzhaltern"
```

---

### Task 13: E2E-Szenario

**Files:**
- Modify: `tests/e2e/steps/newsletters.mjs`
- Modify: `tests/e2e/data/newsletters.mjs`
- Modify: `tests/e2e/scenarios/newsletter-dispatch.e2e.test.mjs`

**Interfaces:**
- Consumes: `createNewsletterDraft`, `fillEditor`, `pickRecipientSource`, `sendOpenNewsletter` aus `steps/newsletters.mjs`; `deliverQueuedMails(subject)` aus `steps/mail.mjs`
- Produces: Step `insertPlaceholder(page, label)` in `steps/newsletters.mjs`; Step `mailpitBodyForSubjectAndRecipient(request, subject, email)` in `steps/mail.mjs`; Szenario „Platzhalter werden je Empfänger ersetzt"

Der Betreff dieses Szenarios bleibt bewusst ohne Platzhalter: `deliverQueuedMails(subject)` und `countQueuedMails(subject)` suchen nach exaktem Betreff, ein personalisierter Betreff hätte je Empfänger einen anderen. Personalisiert wird hier nur der Text. Die Betreffs-Ersetzung ist durch `NewsletterPersonalizedSendFeatureTest` aus Task 4 abgedeckt.

- [ ] **Step 1: Add the step helper**

An `tests/e2e/steps/newsletters.mjs` anhängen:

```javascript
// Fügt einen Platzhalter über die Editor-Leiste ein, nicht per Tastatur: nur so ist
// sichergestellt, dass der Knopf existiert und reinen Text einfügt.
export async function insertPlaceholder(page, label) {
    await page.getByRole('button', { name: 'Platzhalter' }).click();
    await page.getByRole('menuitem', { name: new RegExp(label) }).click();
}
```

- [ ] **Step 2: Add the mail helper**

An `tests/e2e/steps/mail.mjs` anhängen. `mailpitBodyForSubject` liefert nur die erste Mail; der Nachweis der Personalisierung braucht die Mail einer bestimmten Adresse:

```javascript
/**
 * Textinhalt der Mail, die unter diesem Betreff an genau diese Adresse ging.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} subject
 * @param {string} email
 * @returns {Promise<string>}
 */
export async function mailpitBodyForSubjectAndRecipient(request, subject, email) {
    const query = encodeURIComponent(`subject:"${subject}" to:"${email}"`);
    const listResponse = await request.get(`${MAILPIT_BASE}/api/v1/search?query=${query}&limit=1`);
    const list = await listResponse.json();
    const first = (list.messages ?? [])[0];
    if (!first) {
        return '';
    }

    const messageResponse = await request.get(`${MAILPIT_BASE}/api/v1/message/${first.ID}`);
    const message = await messageResponse.json();
    return `${message.HTML ?? ''}\n${message.Text ?? ''}`;
}
```

- [ ] **Step 3: Add scenario data**

An `tests/e2e/data/newsletters.mjs` anhängen. `NEWSLETTER_PROJECT` ist dort bereits definiert:

```javascript
export const NEWSLETTER_WITH_PLACEHOLDERS = {
    title: 'Platzhalter-Rundschreiben',
    project: NEWSLETTER_PROJECT.name,
    content: 'Persönlicher Gruß folgt: ',
};
```

Vergleiche die Feldnamen mit `NEWSLETTER_WITHOUT_TEMPLATE` in derselben Datei und übernimm dessen Struktur, damit `createNewsletterDraft` das Objekt verarbeiten kann.

- [ ] **Step 4: Write the scenario**

An `tests/e2e/scenarios/newsletter-dispatch.e2e.test.mjs` anhängen; oben `insertPlaceholder`, `pickRecipientSource`, `mailpitBodyForSubjectAndRecipient` und `NEWSLETTER_WITH_PLACEHOLDERS` importieren. Die Einträge in `PROJECT_MEMBERS` haben die Felder `firstName`, `lastName`, `email`, `group`, `sub`. `deliverQueuedMails` ist synchron und erwartet den Betreff:

```javascript
test('Newsletter: Platzhalter werden je Empfänger ersetzt', async ({ browser, page, request }) => {
    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist deaktiviert');

    await bootstrapNewsletterFixtures(page);

    await asUser(browser, EDITOR_PRIMARY.email, async (editorPage) => {
        await createNewsletterDraft(editorPage, NEWSLETTER_WITH_PLACEHOLDERS);
        await fillEditor(editorPage, NEWSLETTER_WITH_PLACEHOLDERS.content);
        await insertPlaceholder(editorPage, 'Anrede');
        await pickRecipientSource(editorPage, 'project_members', [NEWSLETTER_PROJECT.name]);
        await sendOpenNewsletter(editorPage);
    });

    deliverQueuedMails(NEWSLETTER_WITH_PLACEHOLDERS.title);

    const [first, second] = PROJECT_MEMBERS;
    const firstBody = await mailpitBodyForSubjectAndRecipient(
        request,
        NEWSLETTER_WITH_PLACEHOLDERS.title,
        first.email
    );
    const secondBody = await mailpitBodyForSubjectAndRecipient(
        request,
        NEWSLETTER_WITH_PLACEHOLDERS.title,
        second.email
    );

    expect(firstBody).not.toContain('{{anrede}}');
    expect(firstBody).toContain(`Hallo ${first.firstName}`);
    expect(secondBody).toContain(`Hallo ${second.firstName}`);
});
```

Setzt `createNewsletterDraft` die Empfängerquelle bereits selbst, entfällt der `pickRecipientSource`-Aufruf — das zeigt ein Blick auf die bestehenden Szenarien in derselben Datei.

- [ ] **Step 5: Run the scenario**

Run: `ddev exec npx playwright test tests/e2e/scenarios/newsletter-dispatch.e2e.test.mjs -g "Platzhalter"`
Expected: PASS. Bei Fehlschlag zuerst prüfen, ob der Toolbar-Knopf aus Task 6 im Editor sichtbar ist.

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/steps/newsletters.mjs tests/e2e/steps/mail.mjs tests/e2e/data/newsletters.mjs tests/e2e/scenarios/newsletter-dispatch.e2e.test.mjs
git commit -m "test(newsletter): E2E-Szenario fuer Platzhalter"
```

---

### Task 14: Abschluss — Qualitätstore und Gesamtlauf

**Files:**
- Modify: alle in diesem Plan berührten Dateien, sofern die Werkzeuge Formatierungsfehler melden

- [ ] **Step 1: Run the PHP style gate**

Run: `ddev composer phpcs`
Expected: keine Fehler. Bei Meldungen `ddev composer phpcbf` laufen lassen und erneut prüfen.

- [ ] **Step 2: Run the Twig gate**

Run: `ddev composer twigcs`
Expected: keine blockierenden Meldungen. Bei Meldungen `ddev composer twigcbf` laufen lassen und erneut prüfen.

- [ ] **Step 3: Check line endings**

Run: `ddev composer eol:check`
Expected: „LF endings verified for tracked text files."

- [ ] **Step 4: Run the full test suite**

Run: `ddev composer test`
Expected: PASS, keine Fehler und keine Warnungen

- [ ] **Step 5: Commit any fixes**

```bash
git add -A
git commit -m "style(newsletter): Formatierung nach Qualitaetstoren"
```

Falls die Werkzeuge nichts geändert haben, entfällt dieser Commit.

- [ ] **Step 6: Report and stop**

Nicht pushen. Dem Entwickler melden: geänderte Dateien, ausgeführte Kommandos, Ergebnis der Testläufe, Ergebnis des Dev-Seed-Laufs aus Task 11.

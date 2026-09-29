# Newsletter-Anhänge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Newsletter kann Dateien mitführen — je Datei wahlweise als echter Mail-Anhang oder als Download-Link in der Mail.

**Architecture:** Die bestehende `attachments`-Tabelle wird mit `entity_type = 'newsletter'` mitgenutzt und um eine Spalte `delivery_mode` ergänzt. Ein neuer `NewsletterAttachmentService` ist die einzige Stelle, die Schwellen, Summen und Modi kennt; Controller, Renderer, Versand und Mail-Worker fragen ihn. Der Zugriff auf eine Datei folgt dem Zugriff auf den Newsletter, umgesetzt in einer neuen `NewsletterPolicy`, die Controller und `AttachmentAccessRegistry` gemeinsam nutzen.

**Tech Stack:** PHP 8.2, Slim 4, Eloquent, Phinx, PHPMailer, Twig, PHPUnit/paratest, DDEV.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-09-28-newsletter-attachments-design.md`
- Bezeichner englisch, Texte/Kommentare/Commit-Nachrichten deutsch mit echten Umlauten `ä ö ü ß` (nie `ae/oe/ue/ss`).
- PSR-12, 4 Leerzeichen, Zeilenlänge weich 120 / hart 130. `ddev composer phpcs`, bei Bedarf `ddev composer phpcbf`.
- Twig: doppelte Anführungszeichen, keine mehrzeiligen Bool-Ausdrücke, kein Inline-JS, kein Inline-CSS (Ausnahme `templates/emails/` — dort sind Inline-Styles Pflicht). `ddev composer twigcs`.
- Alle Textdateien mit LF. Nach jedem Schreiben auf Windows normalisieren.
- Schema nur über Phinx: `ddev exec ./vendor/bin/phinx migrate`. Phinx-Ketten immer mit `update()`/`create()`/`save()` abschließen.
- Logging über `Psr\Log\LoggerInterface` mit stabilem `event`-Schlüssel, nie `error_log()`.
- Gefiltert testen: `ddev php vendor/bin/phpunit --filter "<Muster>"`. Volle Suite genau einmal am Schluss: `ddev composer test:parallel`.
- Schwellwerte: Vorbelegung `attach` unter 2 MB, hartes Gesamtlimit 10 MB je Mail.

## Abweichung von der Spec (bewusst, beim Planen festgestellt)

Die Spec sagt, `store()` und `update()` des `NewsletterController` nähmen die Dateien
mit entgegen. Beide sind aber reine JSON-Endpunkte: `store()` wird aus einem
Modal-Dialog per fetch aufgerufen und leitet sofort auf `/newsletters/{id}/edit` weiter,
`update()` ist der Autosave des Editors und läuft mehrfach je Sitzung. Dateien dort
mitzuschicken hieße, sie bei jedem Autosave erneut hochzuladen.

Stattdessen: drei eigene, klassische Formular-Routen auf der Edit-Seite
(Upload, Moduswechsel, Löschen), jede mit Redirect zurück. Der Anlege-Dialog bleibt
unverändert — die Redaktion landet ohnehin direkt im Editor.

## File Structure

**Neu**

| Datei | Verantwortung |
|---|---|
| `db/migrations/20260928120000_add_delivery_mode_to_attachments.php` | Spalte `delivery_mode` |
| `src/Services/NewsletterAttachmentService.php` | Schwellen, Modi, Summen, Dateilisten je Newsletter |
| `src/Policies/NewsletterPolicy.php` | „Wer darf diesen Newsletter sehen" — eine Quelle für Controller und Anhang-Zugriff |
| `src/Exceptions/NewsletterAttachmentsTooLargeException.php` | Abbruch des Versands über dem Gesamtlimit |
| `tests/Unit/Services/NewsletterAttachmentServiceTest.php` | Schwelle und Summenregel |
| `tests/Feature/NewsletterAttachmentManagementFeatureTest.php` | Upload, Moduswechsel, Löschen, Sperre nach Versand |
| `tests/Feature/NewsletterAttachmentAccessFeatureTest.php` | Download-Zugriff |
| `tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php` | Link-Block, MIME-Anhang, Gesamtlimit |

**Geändert**

| Datei | Änderung |
|---|---|
| `src/Models/Attachment.php` | `delivery_mode` in `$fillable` |
| `src/Services/EntityAttachmentService.php` | optionaler Rückruf für zusätzliche Spalten je Datei |
| `src/Services/AttachmentAccessRegistry.php` | Zweig `'newsletter'` |
| `src/Controllers/NewsletterController.php` | drei Anhang-Aktionen, Policy statt privatem Helfer |
| `src/Services/NewsletterMailRenderer.php` | Link-Liste an das Template |
| `src/Services/NewsletterService.php` | Limitprüfung vor dem Claim, Link-Liste beim Rendern |
| `src/Services/MailQueueService.php` | `newsletter_id` auch im Testmail-Payload |
| `src/Services/MailDeliveryService.php` | Anhänge zur Sendezeit nachladen |
| `src/Services/Mailer.php` | optionaler Parameter `$attachments` |
| `src/Dependencies.php` | neue Dienste verdrahten |
| `src/routes.php` | drei Routen |
| `templates/newsletters/edit.twig` | Anhang-Bereich |
| `templates/emails/newsletter.twig` | Abschnitt „Dateien" |
| `tests/Feature/NewsletterControllerTestScaffold.php` | neue Controller-Abhängigkeiten |
| `src/Services/DevSeedService.php` | Seed-Anhänge samt Zähler und Reset |

---

### Task 1: Spalte `delivery_mode`

**Files:**
- Create: `db/migrations/20260928120000_add_delivery_mode_to_attachments.php`
- Modify: `src/Models/Attachment.php` (`$fillable`)
- Test: `tests/Unit/Migrations/DeliveryModeColumnTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: Spalte `attachments.delivery_mode` mit Werten `'attach'` und `'link'`, Default `'link'`; `Attachment::create([... 'delivery_mode' => 'attach'])` funktioniert.

- [ ] **Step 1: Test schreiben, der die Spalte verlangt**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use App\Models\Attachment;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Modus entscheidet, ob eine Datei an der Mail hängt oder nur verlinkt wird.
 * Fehlt die Spalte, laufen Versand und Oberfläche auf eine stille Standardannahme
 * hinaus - deshalb ist sie hier festgeschrieben.
 */
final class DeliveryModeColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::boot();
    }

    public function testColumnExistsWithLinkAsDefault(): void
    {
        $attachment = Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => 987654,
            'filename' => 'abc_probe.pdf',
            'original_name' => 'probe.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3,
            'file_content' => 'pdf',
        ]);

        $stored = Capsule::table('attachments')->where('id', $attachment->id)->first();

        $this->assertSame('link', $stored->delivery_mode);

        Capsule::table('attachments')->where('id', $attachment->id)->delete();
    }

    public function testModeIsFillable(): void
    {
        $attachment = Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => 987655,
            'filename' => 'abc_plan.pdf',
            'original_name' => 'plan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3,
            'file_content' => 'pdf',
            'delivery_mode' => 'attach',
        ]);

        $this->assertSame('attach', Attachment::query()->find($attachment->id)->delivery_mode);

        Capsule::table('attachments')->where('id', $attachment->id)->delete();
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit --filter DeliveryModeColumnTest`
Erwartet: FAIL — unbekannte Spalte `delivery_mode`.

- [ ] **Step 3: Migration schreiben**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Je Newsletter-Datei steht hier, wie sie beim Empfänger ankommt: als echter
 * Mail-Anhang (`attach`) oder als Download-Link im Newsletter (`link`).
 *
 * Der Standard ist `link`, weil das die harmlose Richtung ist: Eine Zeile ohne
 * bewusst gesetzten Modus bläht keine Mail auf und läuft in kein Größenlimit
 * eines Empfänger-Postfachs. Anhänge anderer Bereiche (Finanzen, Aufgaben,
 * Sponsoring, Repertoire) tragen die Spalte mit und werten sie nie aus.
 */
final class AddDeliveryModeToAttachments extends AbstractMigration
{
    public function up(): void
    {
        $this->table('attachments')
            ->addColumn('delivery_mode', 'enum', [
                'values' => ['attach', 'link'],
                'null' => false,
                'default' => 'link',
                'after' => 'file_size',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('attachments')
            ->removeColumn('delivery_mode')
            ->update();
    }
}
```

- [ ] **Step 4: `delivery_mode` in `Attachment::$fillable` ergänzen**

In `src/Models/Attachment.php` die Liste erweitern:

```php
    protected $fillable = [
        'entity_type',
        'entity_id',
        'filename',
        'original_name',
        'mime_type',
        'file_size',
        'delivery_mode',
        'file_content',
        'created_at'
    ];
```

- [ ] **Step 5: Migration laufen lassen**

Run: `ddev exec ./vendor/bin/phinx migrate`
Erwartet: `AddDeliveryModeToAttachments` als `== ... migrated`.

- [ ] **Step 6: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "DeliveryModeColumnTest|MigrationChainCompletionTest"`
Erwartet: PASS (die Testdatenbank migriert sich über `bin/prepare_test_database.php` selbst).

- [ ] **Step 7: LF normalisieren und committen**

```bash
ddev php bin/normalize_lf_staged.php
git add db/migrations/20260928120000_add_delivery_mode_to_attachments.php src/Models/Attachment.php tests/Unit/Migrations/DeliveryModeColumnTest.php
git commit -m "feat(newsletter): Anhänge kennen einen Zustellmodus"
```

---

### Task 2: Zusätzliche Spalten beim Upload setzen

**Files:**
- Modify: `src/Services/EntityAttachmentService.php:86-155` (`storeUploads()`)
- Test: `tests/Unit/Services/EntityAttachmentExtraColumnsTest.php`

**Interfaces:**
- Consumes: Task 1 (`delivery_mode`)
- Produces: `storeUploads(mixed $files, string $entityType, int $entityId, ?callable $extraColumns = null): array{stored:int, error:?string, ids:list<int>}`. Der Rückruf erhält `(int $size, string $mimeType)` und liefert zusätzliche Spalten; `ids` trägt die Kennungen der gespeicherten Zeilen in Upload-Reihenfolge.

- [ ] **Step 1: Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attachment;
use App\Services\EntityAttachmentService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Bootstrap;

/**
 * Der Dienst bleibt fachlich neutral: Welche zusätzliche Spalte eine Datei trägt,
 * entscheidet der Aufrufer je Datei - der Newsletter braucht den Zustellmodus,
 * niemand sonst.
 */
final class EntityAttachmentExtraColumnsTest extends TestCase
{
    private const ENTITY_ID = 987660;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::boot();
    }

    protected function tearDown(): void
    {
        Capsule::table('attachments')
            ->where('entity_type', 'newsletter')
            ->where('entity_id', self::ENTITY_ID)
            ->delete();

        parent::tearDown();
    }

    public function testExtraColumnsCallbackSeesFileSizeAndIsStored(): void
    {
        $service = new EntityAttachmentService(new NullLogger());

        $result = $service->storeUploads(
            [$this->uploadedFile('klein.pdf', 'abc')],
            'newsletter',
            self::ENTITY_ID,
            static fn (int $size): array => ['delivery_mode' => $size < 10 ? 'attach' : 'link']
        );

        $this->assertSame(1, $result['stored']);
        $this->assertCount(1, $result['ids']);

        $attachment = Attachment::query()->find($result['ids'][0]);
        $this->assertSame('attach', $attachment->delivery_mode);
    }

    public function testWithoutCallbackTheColumnKeepsItsDefault(): void
    {
        $service = new EntityAttachmentService(new NullLogger());

        $result = $service->storeUploads(
            [$this->uploadedFile('gross.pdf', 'abcdefghijkl')],
            'newsletter',
            self::ENTITY_ID
        );

        $this->assertSame(1, $result['stored']);
        $this->assertSame('link', Attachment::query()->find($result['ids'][0])->delivery_mode);
    }

    private function uploadedFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/pdf', strlen($contents), UPLOAD_ERR_OK);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit --filter EntityAttachmentExtraColumnsTest`
Erwartet: FAIL — `storeUploads()` nimmt nur drei Parameter, `ids` fehlt im Rückgabewert.

- [ ] **Step 3: `storeUploads()` erweitern**

Signatur und PHPDoc in `src/Services/EntityAttachmentService.php`:

```php
    /**
     * @param callable(int, string): array<string, mixed>|null $extraColumns Zusätzliche
     *        Spalten je Datei, aus Größe und MIME-Typ abgeleitet. Der Dienst kennt die
     *        Bedeutung nicht - der Newsletter setzt darüber seinen Zustellmodus.
     * @return array{stored: int, error: string|null, ids: list<int>}
     */
    public function storeUploads(
        mixed $files,
        string $entityType,
        int $entityId,
        ?callable $extraColumns = null
    ): array {
```

Frühen Ausstieg und Zähler anpassen:

```php
        if ($files === null) {
            return ['stored' => 0, 'error' => null, 'ids' => []];
        }
```

```php
        $stored = 0;
        $error = null;
        $ids = [];
```

Das `Attachment::create([...])` ersetzen durch:

```php
            $columns = [
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'filename'      => self::storedName($clientFilename),
                'original_name' => self::originalName($clientFilename),
                'mime_type'     => UploadValidator::normalizeMimeType($mimeType),
                'file_size'     => $size,
                'file_content'  => $contents,
            ];

            if ($extraColumns !== null) {
                $columns = array_merge($columns, $extraColumns($size, $mimeType));
            }

            $attachment = Attachment::create($columns);

            $ids[] = (int) $attachment->id;
            $stored++;
```

Rückgabe:

```php
        return ['stored' => $stored, 'error' => $error, 'ids' => $ids];
```

- [ ] **Step 4: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "EntityAttachmentExtraColumnsTest|Attachment"`
Erwartet: PASS. Die bestehenden Aufrufer lesen nur `stored` und `error` und bleiben unangetastet.

- [ ] **Step 5: Commit**

```bash
ddev composer phpcs
git add src/Services/EntityAttachmentService.php tests/Unit/Services/EntityAttachmentExtraColumnsTest.php
git commit -m "refactor(attachments): Upload kann zusätzliche Spalten je Datei setzen"
```

---

### Task 3: `NewsletterAttachmentService`

**Files:**
- Create: `src/Services/NewsletterAttachmentService.php`
- Modify: `src/Dependencies.php`
- Test: `tests/Unit/Services/NewsletterAttachmentServiceTest.php`

**Interfaces:**
- Consumes: Task 1, Task 2
- Produces:
  - `NewsletterAttachmentService::ENTITY_TYPE` (`'newsletter'`)
  - `NewsletterAttachmentService::MODE_ATTACH` (`'attach'`), `MODE_LINK` (`'link'`)
  - `NewsletterAttachmentService::ATTACH_SUGGESTION_LIMIT` (`2097152`)
  - `NewsletterAttachmentService::MAX_ATTACHED_TOTAL` (`10485760`)
  - `suggestMode(int $sizeBytes): string`
  - `metadataFor(int $newsletterId, ?string $mode = null): \Illuminate\Support\Collection`
  - `attachedTotalBytes(int $newsletterId): int`
  - `attachedFiles(int $newsletterId): array<int, array{content: string, name: string, mime: string}>`
  - `linkedFiles(int $newsletterId, string $baseUrl): array<int, array{name: string, size: int, url: string}>`
  - `setMode(int $newsletterId, int $attachmentId, string $mode): bool`

- [ ] **Step 1: Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attachment;
use App\Services\NewsletterAttachmentService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die eine Stelle, die Schwelle, Summe und Modus kennt. Läge das verteilt in
 * Controller, Versand und Mail-Worker, entschiede jede Stelle für sich.
 */
final class NewsletterAttachmentServiceTest extends TestCase
{
    private const NEWSLETTER_ID = 987670;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::boot();
    }

    protected function tearDown(): void
    {
        Capsule::table('attachments')
            ->where('entity_type', 'newsletter')
            ->where('entity_id', self::NEWSLETTER_ID)
            ->delete();

        parent::tearDown();
    }

    public function testSmallFileIsSuggestedAsAttachment(): void
    {
        $service = new NewsletterAttachmentService();

        $this->assertSame('attach', $service->suggestMode(NewsletterAttachmentService::ATTACH_SUGGESTION_LIMIT - 1));
    }

    public function testFileAtTheThresholdIsSuggestedAsLink(): void
    {
        $service = new NewsletterAttachmentService();

        $this->assertSame('link', $service->suggestMode(NewsletterAttachmentService::ATTACH_SUGGESTION_LIMIT));
    }

    public function testTotalCountsOnlyAttachedFiles(): void
    {
        $this->createAttachment('anhang.pdf', 1000, 'attach');
        $this->createAttachment('link.pdf', 5000, 'link');

        $service = new NewsletterAttachmentService();

        $this->assertSame(1000, $service->attachedTotalBytes(self::NEWSLETTER_ID));
    }

    public function testLinkedFilesCarryNameSizeAndDownloadUrl(): void
    {
        $id = $this->createAttachment('programm.pdf', 5000, 'link');

        $service = new NewsletterAttachmentService();
        $linked = $service->linkedFiles(self::NEWSLETTER_ID, 'https://chor.example/');

        $this->assertCount(1, $linked);
        $this->assertSame('programm.pdf', $linked[0]['name']);
        $this->assertSame(5000, $linked[0]['size']);
        $this->assertSame('https://chor.example/attachments/' . $id . '/download', $linked[0]['url']);
    }

    public function testSetModeRejectsAnAttachmentOfAnotherNewsletter(): void
    {
        $id = $this->createAttachment('fremd.pdf', 100, 'link');

        $service = new NewsletterAttachmentService();

        $this->assertFalse($service->setMode(self::NEWSLETTER_ID + 1, $id, 'attach'));
        $this->assertSame('link', Attachment::query()->find($id)->delivery_mode);
    }

    public function testSetModeRejectsAnUnknownMode(): void
    {
        $id = $this->createAttachment('datei.pdf', 100, 'link');

        $service = new NewsletterAttachmentService();

        $this->assertFalse($service->setMode(self::NEWSLETTER_ID, $id, 'inline'));
        $this->assertSame('link', Attachment::query()->find($id)->delivery_mode);
    }

    private function createAttachment(string $name, int $size, string $mode): int
    {
        return (int) Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => self::NEWSLETTER_ID,
            'filename' => bin2hex(random_bytes(4)) . '_' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => $size,
            'file_content' => str_repeat('x', min($size, 32)),
            'delivery_mode' => $mode,
        ])->id;
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit --filter NewsletterAttachmentServiceTest`
Erwartet: FAIL — Klasse `App\Services\NewsletterAttachmentService` existiert nicht.

- [ ] **Step 3: Dienst schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Support\Collection;

/**
 * Alles, was der Newsletter über seine Dateien wissen muss.
 *
 * Schwelle, Gesamtlimit und die Bedeutung von `attach`/`link` stehen hier und nur
 * hier. Controller, Versand, Mail-Renderer und Queue-Worker fragen denselben Dienst -
 * sonst entschiede jede der vier Stellen für sich, und eine spätere Änderung der
 * Schwelle bliebe irgendwo liegen.
 *
 * Gelesen wird durchweg ohne `file_content`, außer in `attachedFiles()`. Der Inhalt
 * ist ein BLOB; wer ihn für eine Namensliste mitlädt, zieht bei drei PDFs zweistellige
 * Megabytes durch den Speicher.
 */
class NewsletterAttachmentService
{
    public const ENTITY_TYPE = 'newsletter';

    public const MODE_ATTACH = 'attach';
    public const MODE_LINK = 'link';

    /**
     * Bis hierher schlägt die Oberfläche "Anhang" vor. Darüber wird "Link"
     * vorbelegt - umschalten darf die Redaktion trotzdem, solange das
     * Gesamtlimit hält.
     */
    public const ATTACH_SUGGESTION_LIMIT = 2 * 1024 * 1024;

    /**
     * Harte Grenze für die Summe aller echten Anhänge einer Mail. Darüber lehnen
     * verbreitete Postfächer die Zustellung ab, und der Versand liefe für jeden
     * Empfänger einzeln in denselben Fehler.
     */
    public const MAX_ATTACHED_TOTAL = 10 * 1024 * 1024;

    public function suggestMode(int $sizeBytes): string
    {
        return $sizeBytes < self::ATTACH_SUGGESTION_LIMIT ? self::MODE_ATTACH : self::MODE_LINK;
    }

    /**
     * Metadaten der Dateien eines Newsletters, wahlweise auf einen Modus begrenzt.
     *
     * @return Collection<int, Attachment>
     */
    public function metadataFor(int $newsletterId, ?string $mode = null): Collection
    {
        $query = Attachment::query()
            ->select(array_merge(EntityAttachmentService::METADATA_COLUMNS, ['delivery_mode']))
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $newsletterId)
            ->orderBy('id');

        if ($mode !== null) {
            $query->where('delivery_mode', $mode);
        }

        return $query->get();
    }

    public function attachedTotalBytes(int $newsletterId): int
    {
        return (int) Attachment::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $newsletterId)
            ->where('delivery_mode', self::MODE_ATTACH)
            ->sum('file_size');
    }

    /**
     * Die Dateien, die an der Mail hängen - hier mit Inhalt, weil PHPMailer ihn braucht.
     *
     * @return array<int, array{content: string, name: string, mime: string}>
     */
    public function attachedFiles(int $newsletterId): array
    {
        return Attachment::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $newsletterId)
            ->where('delivery_mode', self::MODE_ATTACH)
            ->orderBy('id')
            ->get()
            ->map(static fn (Attachment $attachment): array => [
                'content' => (string) $attachment->file_content,
                'name' => (string) $attachment->original_name,
                'mime' => (string) $attachment->mime_type,
            ])
            ->all();
    }

    /**
     * Die Dateien, die als Link in der Mail stehen.
     *
     * @return array<int, array{name: string, size: int, url: string}>
     */
    public function linkedFiles(int $newsletterId, string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');

        return $this->metadataFor($newsletterId, self::MODE_LINK)
            ->map(static fn (Attachment $attachment): array => [
                'name' => (string) $attachment->original_name,
                'size' => (int) $attachment->file_size,
                'url' => $base . '/attachments/' . (int) $attachment->id . '/download',
            ])
            ->values()
            ->all();
    }

    /**
     * Der Newsletter steht bewusst in der Bedingung und nicht in einem Vergleich
     * danach: Eine fremde Anhang-Kennung aus dem Formular darf nicht die Datei
     * eines anderen Newsletters umschalten.
     */
    public function setMode(int $newsletterId, int $attachmentId, string $mode): bool
    {
        if (!in_array($mode, [self::MODE_ATTACH, self::MODE_LINK], true)) {
            return false;
        }

        return Attachment::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $newsletterId)
            ->where('id', $attachmentId)
            ->update(['delivery_mode' => $mode]) > 0;
    }
}
```

- [ ] **Step 4: Im Container verdrahten**

In `src/Dependencies.php` neben den übrigen Diensten eintragen:

```php
    NewsletterAttachmentService::class => static fn (): NewsletterAttachmentService
        => new NewsletterAttachmentService(),
```

(Import `use App\Services\NewsletterAttachmentService;` ergänzen. Nutzt die Datei für
andere parameterlose Dienste Autowiring ohne eigenen Eintrag, entfällt dieser Schritt —
dann nur prüfen, dass die Container-Tests grün bleiben.)

- [ ] **Step 5: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter NewsletterAttachmentServiceTest`
Erwartet: PASS, 6 Tests.

- [ ] **Step 6: Commit**

```bash
ddev composer phpcs
git add src/Services/NewsletterAttachmentService.php src/Dependencies.php tests/Unit/Services/NewsletterAttachmentServiceTest.php
git commit -m "feat(newsletter): Dienst für Zustellmodus und Größen der Anhänge"
```

---

### Task 4: `NewsletterPolicy` und Anhang-Zugriff

**Files:**
- Create: `src/Policies/NewsletterPolicy.php`
- Modify: `src/Services/AttachmentAccessRegistry.php:42-72`
- Modify: `src/Controllers/NewsletterController.php:188-198` (`canAccessReceivedNewsletterById()` nutzt die Policy)
- Modify: `src/Dependencies.php`
- Modify: `tests/Feature/NewsletterControllerTestScaffold.php`
- Test: `tests/Feature/NewsletterAttachmentAccessFeatureTest.php`

**Interfaces:**
- Consumes: Task 3 (`NewsletterAttachmentService::ENTITY_TYPE`)
- Produces: `App\Policies\NewsletterPolicy::canView(int $newsletterId, ?int $userId): bool` — wahr für Newsletter-Verwaltung (`$_SESSION['can_manage_newsletters']`) und für jeden mit einer Zeile in `newsletter_archive`.

- [ ] **Step 1: Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\NewsletterArchive;
use App\Policies\NewsletterPolicy;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Wer den Newsletter ansehen darf, darf auch die verlinkte Datei laden - und sonst
 * niemand. Ohne diese Kopplung wäre die zentrale Anhang-Route ein Weg an der
 * Newsletter-Sichtbarkeit vorbei.
 */
final class NewsletterAttachmentAccessFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testRecipientOfTheNewsletterMayLoadTheFile(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);
        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
        ]);
        $attachment = $this->createNewsletterAttachment((int) $newsletter->id);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $this->assertTrue($this->registry()->mayAccess($attachment, (int) $recipient->id));
    }

    public function testSomeoneOutsideTheDistributionListIsRejected(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $stranger = $this->createUser('Lena');
        $newsletter = $this->createNewsletter($creator, $recipient);
        $attachment = $this->createNewsletterAttachment((int) $newsletter->id);

        $_SESSION['user_id'] = (int) $stranger->id;
        $_SESSION['can_manage_newsletters'] = false;

        $this->assertFalse($this->registry()->mayAccess($attachment, (int) $stranger->id));
    }

    public function testNewsletterManagementMayLoadTheFileBeforeSending(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);
        $attachment = $this->createNewsletterAttachment((int) $newsletter->id);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $this->assertTrue($this->registry()->mayAccess($attachment, (int) $creator->id));
    }

    public function testDisabledModuleLocksTheFile(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);
        $attachment = $this->createNewsletterAttachment((int) $newsletter->id);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $this->assertFalse($this->registry(false)->mayAccess($attachment, (int) $creator->id));
    }

    private function registry(bool $moduleEnabled = true): AttachmentAccessRegistry
    {
        return new AttachmentAccessRegistry(
            new SponsoringPolicy(),
            new TaskPolicy(),
            ['newsletter' => $moduleEnabled],
            new NewsletterPolicy()
        );
    }

    private function createNewsletterAttachment(int $newsletterId): Attachment
    {
        return Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => $newsletterId,
            'filename' => bin2hex(random_bytes(4)) . '_programm.pdf',
            'original_name' => 'programm.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'file_content' => 'pdf-inhalt-x',
            'delivery_mode' => 'link',
        ]);
    }
}
```

Vor dem Schreiben die tatsächlichen Konstruktor-Signaturen prüfen
(`grep -n "__construct" src/Policies/SponsoringPolicy.php src/Policies/TaskPolicy.php`)
und die Aufrufe im Test daran anpassen.

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit --filter NewsletterAttachmentAccessFeatureTest`
Erwartet: FAIL — `App\Policies\NewsletterPolicy` existiert nicht.

- [ ] **Step 3: Policy schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NewsletterArchive;

/**
 * Wer einen Newsletter ansehen darf.
 *
 * Die Regel stand bisher als privater Helfer im NewsletterController. Mit den
 * Anhängen braucht sie eine zweite Leserin - die AttachmentAccessRegistry -, und
 * zwei Kopien derselben Zugriffsregel laufen auseinander, sobald eine von beiden
 * angepasst wird.
 *
 * Zwei Wege führen hierher: die Newsletter-Verwaltung, die auch einen Entwurf vor
 * dem Versand prüfen können muss, und jede Person mit einer Archiv-Zeile, also
 * jede, an die der Newsletter tatsächlich ging.
 */
class NewsletterPolicy
{
    public function canView(int $newsletterId, ?int $userId): bool
    {
        if ((bool) ($_SESSION['can_manage_newsletters'] ?? false)) {
            return true;
        }

        if ($newsletterId <= 0 || !$userId) {
            return false;
        }

        return NewsletterArchive::query()
            ->where('newsletter_id', $newsletterId)
            ->where('user_id', (int) $userId)
            ->exists();
    }
}
```

- [ ] **Step 4: Registry erweitern**

In `src/Services/AttachmentAccessRegistry.php` die Abhängigkeit aufnehmen
(`use App\Policies\NewsletterPolicy;`):

```php
    private NewsletterPolicy $newsletterPolicy;
```

Konstruktor um den vierten Parameter ergänzen und im `match` den Zweig eintragen:

```php
            'newsletter'  => $this->moduleEnabled('newsletter')
                && $this->newsletterPolicy->canView($entityId, $userId),
```

Der Zweig `default => false` bleibt.

- [ ] **Step 5: Controller auf die Policy umstellen**

In `src/Controllers/NewsletterController.php`:

```php
    private function canAccessReceivedNewsletterById(int $newsletterId, ?int $userId): bool
    {
        return $this->newsletterPolicy->canView($newsletterId, $userId);
    }
```

Die Policy als Konstruktor-Abhängigkeit aufnehmen. `canManageNewsletters()` bleibt
bestehen — die Policy liest dieselbe Sitzungsangabe selbst.

Achtung: Der bisherige Helfer prüfte **nicht** auf Verwaltungsrecht. Aufrufstellen
durchgehen (`grep -n "canAccessReceivedNewsletterById" src/Controllers/NewsletterController.php`)
und sicherstellen, dass keine davon auf „nur Empfänger, ausdrücklich ohne Verwaltung"
angewiesen ist; wo das doch der Fall ist, dort die Archiv-Abfrage direkt stehen lassen.

- [ ] **Step 6: Container und Test-Gerüst nachziehen**

`src/Dependencies.php`: `NewsletterPolicy` registrieren und an `AttachmentAccessRegistry`
sowie `NewsletterController` übergeben.
`tests/Feature/NewsletterControllerTestScaffold.php`: `controller()` um die neue
Abhängigkeit ergänzen (`new NewsletterPolicy()`).

- [ ] **Step 7: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "NewsletterAttachmentAccessFeatureTest|AttachmentAccess|Newsletter"`
Erwartet: PASS.

- [ ] **Step 8: Commit**

```bash
ddev composer phpcs
git add src/Policies/NewsletterPolicy.php src/Services/AttachmentAccessRegistry.php src/Controllers/NewsletterController.php src/Dependencies.php tests/Feature/NewsletterAttachmentAccessFeatureTest.php tests/Feature/NewsletterControllerTestScaffold.php
git commit -m "feat(newsletter): Anhang-Zugriff folgt dem Zugriff auf den Newsletter"
```

---

### Task 5: Anhänge verwalten (Routen, Controller, Oberfläche)

**Files:**
- Modify: `src/Controllers/NewsletterController.php` (drei neue Aktionen, `edit()`-Kontext)
- Modify: `src/routes.php` (drei Routen in der Newsletter-Manage-Gruppe)
- Modify: `templates/newsletters/edit.twig` (Bereich nach dem `</form>` in Zeile 289)
- Test: `tests/Feature/NewsletterAttachmentManagementFeatureTest.php`

**Interfaces:**
- Consumes: Task 3 (`NewsletterAttachmentService`), Task 2 (`storeUploads()` mit Rückruf)
- Produces:
  - `NewsletterController::uploadAttachments(Request $request, Response $response, array $args): Response`
  - `NewsletterController::updateAttachmentMode(Request $request, Response $response, array $args): Response`
  - `NewsletterController::deleteAttachment(Request $request, Response $response, array $args): Response`
  - Routen `POST /newsletters/{id}/attachments`,
    `POST /newsletters/{id}/attachments/{attachment_id}/mode`,
    `POST /newsletters/{id}/attachments/{attachment_id}/delete`

- [ ] **Step 1: Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Newsletter;
use App\Services\NewsletterAttachmentService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

/**
 * Anhänge gehören zum Entwurf. Nach dem Versand sind sie eingefroren - ein
 * nachträglich gelöschter Link führte in bereits zugestellten Mails ins Leere.
 */
final class NewsletterAttachmentManagementFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testUploadSuggestsAttachmentForASmallFile(): void
    {
        $newsletter = $this->draft();

        $this->upload($newsletter, 'probenplan.pdf', 'kurz');

        $attachment = Attachment::query()
            ->where('entity_type', 'newsletter')
            ->where('entity_id', $newsletter->id)
            ->first();

        $this->assertNotNull($attachment);
        $this->assertSame('probenplan.pdf', $attachment->original_name);
        $this->assertSame('attach', $attachment->delivery_mode);
    }

    public function testModeCanBeSwitchedToLink(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'probenplan.pdf', 'kurz');
        $attachment = $this->firstAttachment($newsletter);

        $request = $this->makeRequest(
            'POST',
            "/newsletters/{$newsletter->id}/attachments/{$attachment->id}/mode"
        )->withParsedBody(['delivery_mode' => 'link']);

        $this->controller()->updateAttachmentMode(
            $request,
            $this->makeResponse(),
            ['id' => (string) $newsletter->id, 'attachment_id' => (string) $attachment->id]
        );

        $this->assertSame('link', Attachment::query()->find($attachment->id)->delivery_mode);
    }

    public function testAttachmentCanBeDeletedFromADraft(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'probenplan.pdf', 'kurz');
        $attachment = $this->firstAttachment($newsletter);

        $this->controller()->deleteAttachment(
            $this->makeRequest('POST', "/newsletters/{$newsletter->id}/attachments/{$attachment->id}/delete"),
            $this->makeResponse(),
            ['id' => (string) $newsletter->id, 'attachment_id' => (string) $attachment->id]
        );

        $this->assertNull(Attachment::query()->find($attachment->id));
    }

    public function testASentNewsletterAcceptsNoFurtherUpload(): void
    {
        $newsletter = $this->draft();
        $newsletter->update(['status' => Newsletter::STATUS_SENT]);

        $this->upload($newsletter, 'nachtrag.pdf', 'kurz');

        $this->assertSame(
            0,
            Attachment::query()
                ->where('entity_type', 'newsletter')
                ->where('entity_id', $newsletter->id)
                ->count()
        );
    }

    public function testASentNewsletterKeepsItsAttachments(): void
    {
        $newsletter = $this->draft();
        $this->upload($newsletter, 'programm.pdf', 'kurz');
        $attachment = $this->firstAttachment($newsletter);
        $newsletter->update(['status' => Newsletter::STATUS_SENT]);

        $this->controller()->deleteAttachment(
            $this->makeRequest('POST', "/newsletters/{$newsletter->id}/attachments/{$attachment->id}/delete"),
            $this->makeResponse(),
            ['id' => (string) $newsletter->id, 'attachment_id' => (string) $attachment->id]
        );

        $this->assertNotNull(Attachment::query()->find($attachment->id));
    }

    public function testWithoutTheManagementRightNothingIsStored(): void
    {
        $newsletter = $this->draft();
        $_SESSION['can_manage_newsletters'] = false;

        $this->upload($newsletter, 'fremd.pdf', 'kurz');

        $this->assertSame(
            0,
            Attachment::query()
                ->where('entity_type', 'newsletter')
                ->where('entity_id', $newsletter->id)
                ->count()
        );
    }

    private function draft(): Newsletter
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        return $this->createNewsletter($creator, $recipient);
    }

    private function upload(Newsletter $newsletter, string $name, string $contents): void
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/attachments")
            ->withUploadedFiles([
                'attachments' => [
                    new UploadedFile($path, $name, 'application/pdf', strlen($contents), UPLOAD_ERR_OK),
                ],
            ]);

        $this->controller()->uploadAttachments(
            $request,
            $this->makeResponse(),
            ['id' => (string) $newsletter->id]
        );
    }

    private function firstAttachment(Newsletter $newsletter): Attachment
    {
        return Attachment::query()
            ->where('entity_type', NewsletterAttachmentService::ENTITY_TYPE)
            ->where('entity_id', $newsletter->id)
            ->firstOrFail();
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit --filter NewsletterAttachmentManagementFeatureTest`
Erwartet: FAIL — `uploadAttachments()` existiert nicht.

- [ ] **Step 3: Controller-Aktionen schreiben**

In `src/Controllers/NewsletterController.php`, mit
`use App\Services\EntityAttachmentService;` und
`use App\Services\NewsletterAttachmentService;` sowie beiden Diensten im Konstruktor:

```php
    /**
     * Gemeinsamer Vorlauf der drei Anhang-Aktionen: Newsletter da, Recht da,
     * und noch ein Entwurf.
     *
     * Der Status steht bewusst in dieser Prüfung und nicht nur in der Oberfläche:
     * Ein versendeter Newsletter darf seine Dateien nicht mehr verlieren, sonst
     * zeigen die Links in bereits zugestellten Mails ins Leere.
     */
    private function attachmentGuard(int $newsletterId): ?Newsletter
    {
        if (!$this->canManageNewsletters()) {
            $_SESSION['error'] = 'Zugriff verweigert.';

            return null;
        }

        $newsletter = Newsletter::find($newsletterId);
        if (!$newsletter instanceof Newsletter) {
            $_SESSION['error'] = 'Newsletter nicht gefunden.';

            return null;
        }

        if (!$newsletter->isDraft()) {
            $_SESSION['error'] = 'Ein versendeter Newsletter lässt sich nicht mehr ändern.';

            return null;
        }

        return $newsletter;
    }

    private function backToEdit(Response $response, int $newsletterId): Response
    {
        return $response
            ->withHeader('Location', "/newsletters/{$newsletterId}/edit")
            ->withStatus(302);
    }

    public function uploadAttachments(Request $request, Response $response, array $args): Response
    {
        $newsletterId = (int) ($args['id'] ?? 0);
        $newsletter = $this->attachmentGuard($newsletterId);
        if ($newsletter === null) {
            return $this->backToEdit($response, $newsletterId);
        }

        // Der Modus wird je Datei aus ihrer Größe vorbelegt; umschalten kann die
        // Redaktion anschließend in der Liste.
        $result = $this->entityAttachments->storeUploads(
            $request->getUploadedFiles()['attachments'] ?? null,
            NewsletterAttachmentService::ENTITY_TYPE,
            (int) $newsletter->id,
            fn (int $size): array => ['delivery_mode' => $this->newsletterAttachments->suggestMode($size)]
        );

        if ($result['error'] !== null) {
            $_SESSION['error'] = $result['error'];
        }

        if ($result['stored'] > 0) {
            $_SESSION['success'] = $result['stored'] === 1
                ? 'Datei hinzugefügt.'
                : $result['stored'] . ' Dateien hinzugefügt.';
        }

        return $this->backToEdit($response, (int) $newsletter->id);
    }

    public function updateAttachmentMode(Request $request, Response $response, array $args): Response
    {
        $newsletterId = (int) ($args['id'] ?? 0);
        $newsletter = $this->attachmentGuard($newsletterId);
        if ($newsletter === null) {
            return $this->backToEdit($response, $newsletterId);
        }

        $data = (array) $request->getParsedBody();
        $mode = InputValidator::asString($data['delivery_mode'] ?? '');
        $attachmentId = (int) ($args['attachment_id'] ?? 0);

        if (!$this->newsletterAttachments->setMode((int) $newsletter->id, $attachmentId, $mode)) {
            $_SESSION['error'] = 'Der Zustellweg konnte nicht geändert werden.';

            return $this->backToEdit($response, (int) $newsletter->id);
        }

        $_SESSION['success'] = $mode === NewsletterAttachmentService::MODE_ATTACH
            ? 'Datei wird an die Mail gehängt.'
            : 'Datei wird in der Mail verlinkt.';

        return $this->backToEdit($response, (int) $newsletter->id);
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $newsletterId = (int) ($args['id'] ?? 0);
        $newsletter = $this->attachmentGuard($newsletterId);
        if ($newsletter === null) {
            return $this->backToEdit($response, $newsletterId);
        }

        $deleted = $this->entityAttachments->deleteForEntity(
            NewsletterAttachmentService::ENTITY_TYPE,
            (int) $newsletter->id,
            (int) ($args['attachment_id'] ?? 0)
        );

        $_SESSION[$deleted ? 'success' : 'error'] = $deleted
            ? 'Datei entfernt.'
            : 'Datei nicht gefunden.';

        return $this->backToEdit($response, (int) $newsletter->id);
    }
```

- [ ] **Step 4: Routen eintragen**

In `src/routes.php` innerhalb der Newsletter-Manage-Gruppe (dort, wo bereits
`/newsletters/{id:[0-9]+}/send` steht):

```php
                        $newsletterGroup->post(
                            '/newsletters/{id:[0-9]+}/attachments',
                            [NewsletterController::class, 'uploadAttachments']
                        );
                        $newsletterGroup->post(
                            '/newsletters/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}/mode',
                            [NewsletterController::class, 'updateAttachmentMode']
                        );
                        $newsletterGroup->post(
                            '/newsletters/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}/delete',
                            [NewsletterController::class, 'deleteAttachment']
                        );
```

- [ ] **Step 5: `edit()` gibt die Anhänge an die Vorlage**

In `NewsletterController::edit()` dem Render-Kontext hinzufügen:

```php
            'attachments' => $this->newsletterAttachments->metadataFor((int) $newsletter->id),
            'max_attached_total' => NewsletterAttachmentService::MAX_ATTACHED_TOTAL,
            'attached_total' => $this->newsletterAttachments->attachedTotalBytes((int) $newsletter->id),
```

- [ ] **Step 6: Oberfläche ergänzen**

In `templates/newsletters/edit.twig` **nach** dem schließenden `</form>` des
Editor-Formulars (derzeit Zeile 289) und vor `<div class="modal fade" id="previewModal"`
einfügen — außerhalb, weil HTML keine verschachtelten Formulare erlaubt:

```twig
        <section class="dashboard-section" aria-labelledby="newsletter-attachments-title">
            <div class="dashboard-section-head">
                <h2 class="dashboard-section-title" id="newsletter-attachments-title">Dateien</h2>
                <p class="dashboard-section-lead mb-0">
                    Kleine Dateien hängen an der Mail, große werden darin verlinkt.
                </p>
            </div>

            <div class="surface-card form-surface card border-0">
                <div class="card-body">
                    {% if attachments|length > 0 %}
                        <ul class="list-group list-group-flush mb-3">
                            {% for attachment in attachments %}
                                <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                                    <span class="flex-grow-1">
                                        <i class="bi bi-paperclip"></i>
                                        {{ attachment.original_name }}
                                    </span>
                                    <form method="post"
                                          action="/newsletters/{{ newsletter.id }}/attachments/{{ attachment.id }}/mode"
                                          class="d-flex align-items-center gap-2">
                                        <label class="visually-hidden"
                                               for="mode-{{ attachment.id }}">Zustellweg</label>
                                        <select class="form-select form-select-sm w-auto"
                                                id="mode-{{ attachment.id }}"
                                                name="delivery_mode">
                                            <option value="attach"
                                                    {% if attachment.delivery_mode == "attach" %}selected{% endif %}>
                                                An die Mail hängen
                                            </option>
                                            <option value="link"
                                                    {% if attachment.delivery_mode == "link" %}selected{% endif %}>
                                                In der Mail verlinken
                                            </option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            Übernehmen
                                        </button>
                                    </form>
                                    <form method="post"
                                          action="/newsletters/{{ newsletter.id }}/attachments/{{ attachment.id }}/delete"
                                          data-confirm="Diese Datei wirklich entfernen?">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                            <span class="visually-hidden">Entfernen</span>
                                        </button>
                                    </form>
                                </li>
                            {% endfor %}
                        </ul>

                        {% if attached_total > max_attached_total %}
                            <div class="alert alert-warning">
                                Die angehängten Dateien sind zusammen zu groß. Der Versand wird
                                abgelehnt, solange das so bleibt - stelle große Dateien auf
                                "In der Mail verlinken" um.
                            </div>
                        {% endif %}
                    {% else %}
                        <p class="text-muted mb-3">Noch keine Dateien.</p>
                    {% endif %}

                    <form method="post"
                          action="/newsletters/{{ newsletter.id }}/attachments"
                          enctype="multipart/form-data"
                          class="d-flex flex-wrap align-items-center gap-2">
                        <label class="visually-hidden" for="newsletter-attachments">Dateien wählen</label>
                        <input type="file"
                               class="form-control w-auto"
                               id="newsletter-attachments"
                               name="attachments[]"
                               multiple>
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-upload"></i> Hochladen
                        </button>
                    </form>
                </div>
            </div>
        </section>
```

Die Dateigröße bewusst nicht ausgegeben, solange kein Twig-Filter dafür existiert.
Gibt es einen (`grep -rn "filesize" src/ templates/ | head`), ihn neben dem Namen
einsetzen; sonst keinen neuen Filter nur für diese Stelle anlegen.

- [ ] **Step 7: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter NewsletterAttachmentManagementFeatureTest`
Erwartet: PASS, 6 Tests.

- [ ] **Step 8: Linter laufen lassen und committen**

```bash
ddev composer phpcs
ddev composer twigcs
ddev php bin/normalize_lf_staged.php
git add src/Controllers/NewsletterController.php src/routes.php templates/newsletters/edit.twig tests/Feature/NewsletterAttachmentManagementFeatureTest.php
git commit -m "feat(newsletter): Dateien am Entwurf hochladen, umschalten und entfernen"
```

---

### Task 6: Link-Block in der Mail

**Files:**
- Modify: `src/Services/NewsletterMailRenderer.php:58-80` (`renderHtml()`)
- Modify: `templates/emails/newsletter.twig`
- Modify: `src/Services/NewsletterService.php` (Link-Liste beim Rendern übergeben)
- Modify: `src/Controllers/NewsletterController.php` (`previewFrame()`, `previewRender()`, `testMail()`)
- Test: `tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php` (erster Teil)

**Interfaces:**
- Consumes: Task 3 (`linkedFiles()`)
- Produces: `NewsletterMailRenderer::renderHtml(Newsletter $newsletter, string $subject, string $contentHtml, string $baseUrl, bool $includeBrowseLink = true, array $linkedFiles = []): string` — `$linkedFiles` in der Form `array<int, array{name: string, size: int, url: string}>`.

- [ ] **Step 1: Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Newsletter;
use PHPUnit\Framework\TestCase;

/**
 * Eine verlinkte Datei muss in der Mail auch auftauchen - sonst liegt sie im
 * ChorManager und niemand erfährt davon. Eine angehängte muss an der Mail hängen,
 * und die nächste Mail darf sie nicht erben.
 */
final class NewsletterAttachmentDeliveryFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testLinkedFileAppearsInTheRenderedMail(): void
    {
        $newsletter = $this->draft();
        $attachment = $this->attachment((int) $newsletter->id, 'programm.pdf', 'link');

        $html = $this->renderer()->renderHtml(
            $newsletter,
            'Probenplan',
            '<p>Inhalt</p>',
            'https://chor.example',
            true,
            [[
                'name' => 'programm.pdf',
                'size' => (int) $attachment->file_size,
                'url' => 'https://chor.example/attachments/' . $attachment->id . '/download',
            ]]
        );

        $this->assertStringContainsString('programm.pdf', $html);
        $this->assertStringContainsString('/attachments/' . $attachment->id . '/download', $html);
    }

    public function testWithoutLinkedFilesNoSectionIsRendered(): void
    {
        $newsletter = $this->draft();

        $html = $this->renderer()->renderHtml($newsletter, 'Probenplan', '<p>Inhalt</p>', 'https://chor.example');

        $this->assertStringNotContainsString('newsletter-files', $html);
    }

    private function draft(): Newsletter
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        return $this->createNewsletter($creator, $recipient);
    }

    private function attachment(int $newsletterId, string $name, string $mode, int $size = 12): Attachment
    {
        return Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => $newsletterId,
            'filename' => bin2hex(random_bytes(4)) . '_' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => $size,
            'file_content' => str_repeat('x', min($size, 32)),
            'delivery_mode' => $mode,
        ]);
    }
}
```

`renderer()` liefert den `NewsletterMailRenderer` aus dem Test-Gerüst; steht er dort
noch nicht als eigene Methode bereit, in `NewsletterControllerTestScaffold` eine
ergänzen, die dieselbe Twig-Instanz nutzt wie `controller()`.

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit --filter NewsletterAttachmentDeliveryFeatureTest`
Erwartet: FAIL — `renderHtml()` kennt den sechsten Parameter nicht.

- [ ] **Step 3: Renderer erweitern**

In `src/Services/NewsletterMailRenderer.php`:

```php
    /**
     * @param array<int, array{name: string, size: int, url: string}> $linkedFiles Dateien,
     *        die nicht an der Mail hängen, sondern darin verlinkt werden. Die Liste kommt
     *        fertig aufbereitet aus dem NewsletterAttachmentService - der Renderer
     *        entscheidet nicht, was verlinkt wird, er stellt es nur dar.
     */
    public function renderHtml(
        Newsletter $newsletter,
        string $subject,
        string $contentHtml,
        string $baseUrl,
        bool $includeBrowseLink = true,
        array $linkedFiles = []
    ): string {
```

und im Aufruf von `fetch()` ergänzen:

```php
            'linked_files' => $linkedFiles,
```

- [ ] **Step 4: Mail-Template ergänzen**

In `templates/emails/newsletter.twig` nach dem Inhaltsbereich und vor der Fußzeile
einfügen. Inline-Styles sind hier Pflicht — Mail-Programme werten externe Stylesheets
nicht aus:

```twig
{% if linked_files|length > 0 %}
    <tr>
        <td class="newsletter-files" style="padding: 0 24px 24px 24px;">
            <h3 style="margin: 0 0 8px 0; font-size: 16px; color: {{ primary_strong }};">Dateien</h3>
            <ul style="margin: 0; padding-left: 20px;">
                {% for file in linked_files %}
                    <li style="margin-bottom: 4px;">
                        <a href="{{ file.url }}" style="color: {{ primary_strong }};">{{ file.name }}</a>
                    </li>
                {% endfor %}
            </ul>
        </td>
    </tr>
{% endif %}
```

Die Tabellenstruktur der vorhandenen Datei prüfen und den Block passend einhängen —
steht der Inhalt dort nicht in einer Tabellenzeile, den `<tr>`/`<td>`-Rahmen weglassen.
Den Variablennamen für die Markenfarbe aus `resolveBranding()` übernehmen
(`grep -n "primary_strong" src/Services/NewsletterMailRenderer.php templates/emails/newsletter.twig`).

- [ ] **Step 5: Aufrufer versorgen**

`src/Services/NewsletterService.php`: die Link-Liste einmal je Versandlauf auflösen
(nicht je Empfänger) und an `renderHtml()` durchreichen:

```php
        $linkedFiles = $this->attachments->linkedFiles((int) $newsletter->id, $baseUrl);
```

`src/Controllers/NewsletterController.php`: in `previewFrame()`, `previewRender()`
und `testMail()` dieselbe Liste übergeben, damit Vorschau und Mail übereinstimmen.

- [ ] **Step 6: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "NewsletterAttachmentDeliveryFeatureTest|NewsletterPreview|NewsletterSend"`
Erwartet: PASS.

- [ ] **Step 7: Linter und Commit**

```bash
ddev composer phpcs
ddev composer twigcs
git add src/Services/NewsletterMailRenderer.php src/Services/NewsletterService.php src/Controllers/NewsletterController.php templates/emails/newsletter.twig tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php
git commit -m "feat(newsletter): verlinkte Dateien stehen als Block in der Mail"
```

---

### Task 7: Echte Anhänge bis in die Mail

**Files:**
- Modify: `src/Services/Mailer.php:165-215` (`composeMessage()`, `sendHtmlMailDetailed()`, `sendHtmlMail()`, `buildMimeMessage()`)
- Modify: `src/Services/MailDeliveryService.php:155-165` (`sendEntry()`)
- Modify: `src/Services/MailQueueService.php:139-166` (`enqueueNewsletterTestMail()`)
- Modify: `src/Dependencies.php`
- Test: `tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php` (zweiter Teil)

**Interfaces:**
- Consumes: Task 3 (`attachedFiles()`)
- Produces:
  - `Mailer::sendHtmlMailDetailed(string $to, string $subject, string $htmlBody, array $attachments = []): array`
  - `Mailer::buildMimeMessage(string $to, string $subject, string $htmlBody, array $attachments = []): string`
  - `$attachments` in der Form `array<int, array{content: string, name: string, mime: string}>`

- [ ] **Step 1: Test ergänzen**

An `tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php` anfügen:

```php
    public function testAttachedFileEndsUpInTheMimeMessage(): void
    {
        $mailer = $this->mailer();

        $mime = $mailer->buildMimeMessage(
            'georg@example.test',
            'Probenplan',
            '<p>Inhalt</p>',
            [['content' => 'pdf-inhalt', 'name' => 'programm.pdf', 'mime' => 'application/pdf']]
        );

        $this->assertStringContainsString('programm.pdf', $mime);
        $this->assertStringContainsString(base64_encode('pdf-inhalt'), $mime);
    }

    public function testTheNextMailDoesNotCarryThePreviousAttachment(): void
    {
        $mailer = $this->mailer();

        $mailer->buildMimeMessage(
            'georg@example.test',
            'Erste',
            '<p>Inhalt</p>',
            [['content' => 'pdf-inhalt', 'name' => 'programm.pdf', 'mime' => 'application/pdf']]
        );

        $second = $mailer->buildMimeMessage('anna@example.test', 'Zweite', '<p>Inhalt</p>');

        $this->assertStringNotContainsString('programm.pdf', $second);
    }
```

`mailer()` liefert die `Mailer`-Instanz; die im Repository bereits genutzte Aufbauweise
übernehmen (`grep -rn "new Mailer(" tests/ | head`).

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit --filter "testAttachedFileEndsUpInTheMimeMessage|testTheNextMailDoesNotCarryThePreviousAttachment"`
Erwartet: FAIL — `buildMimeMessage()` kennt den vierten Parameter nicht.

- [ ] **Step 3: Mailer erweitern**

```php
    /**
     * @param array<int, array{content: string, name: string, mime: string}> $attachments
     *        Dateien, die an der Mail hängen. Der Inhalt kommt als Zeichenkette aus der
     *        Datenbank, nicht als Pfad - im Dateisystem des Containers liegt er nicht.
     */
    private function composeMessage(string $to, string $subject, string $htmlBody, array $attachments = []): void
```

Im Rumpf nach den eingebetteten Bildern:

```php
        foreach ($attachments as $attachment) {
            $this->mail->addStringAttachment(
                $attachment['content'],
                $attachment['name'],
                PHPMailer::ENCODING_BASE64,
                $attachment['mime']
            );
        }
```

`sendHtmlMailDetailed()`, `sendHtmlMail()` und `buildMimeMessage()` bekommen denselben
optionalen Parameter und reichen ihn an `composeMessage()` weiter. Das bereits
vorhandene `clearAttachments()` am Anfang von `composeMessage()` deckt das Zurücksetzen
zwischen zwei Mails ab — der zweite Test wacht darüber.

- [ ] **Step 4: Worker lädt die Dateien nach**

In `src/Services/MailDeliveryService.php` den Dienst in den Konstruktor aufnehmen
(`NewsletterAttachmentService $attachments`) und in `sendEntry()` vor dem Senden:

```php
            // Die Dateien liegen nicht in der Queue, sondern am Newsletter: Eine Kopie
            // je Empfängerzeile wären bei 80 Empfängern und einem 3-MB-PDF eine
            // Viertelgigabyte in mail_queue.
            $attachments = [];
            $payload = $entry->payload_json ?? [];
            if ($entry->mail_type === 'newsletter' && isset($payload['newsletter_id'])) {
                $attachments = $this->attachments->attachedFiles((int) $payload['newsletter_id']);
            }

            $result = $this->mailer->sendHtmlMailDetailed(
                $entry->recipient_email,
                $entry->subject,
                $entry->body_html,
                $attachments
            );
```

Den tatsächlichen Namen der Spalte für die Mail-Art prüfen
(`grep -n "mail_type" src/Models/MailQueue.php src/Services/MailQueueService.php`)
und die Bedingung daran anpassen. Trägt die Testmail eine eigene Art, beide Arten
zulassen.

- [ ] **Step 5: Testmail trägt die Newsletter-Kennung**

In `src/Services/MailQueueService::enqueueNewsletterTestMail()` die `newsletter_id`
in den Payload aufnehmen, damit der Worker denselben Weg geht. Die Aufrufstelle in
`NewsletterController::testMail()` entsprechend erweitern.

- [ ] **Step 6: Container nachziehen**

`src/Dependencies.php`: `MailDeliveryService` bekommt den `NewsletterAttachmentService`.
Alle Stellen prüfen, die ihn von Hand bauen (`grep -rn "new MailDeliveryService" src/ bin/ tests/`).

- [ ] **Step 7: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "NewsletterAttachmentDeliveryFeatureTest|MailDelivery|MailQueue|Mailer"`
Erwartet: PASS.

- [ ] **Step 8: Commit**

```bash
ddev composer phpcs
git add src/Services/Mailer.php src/Services/MailDeliveryService.php src/Services/MailQueueService.php src/Controllers/NewsletterController.php src/Dependencies.php tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php
git commit -m "feat(newsletter): angehängte Dateien reisen mit der Mail"
```

---

### Task 8: Gesamtlimit bricht den Versand ab

**Files:**
- Create: `src/Exceptions/NewsletterAttachmentsTooLargeException.php`
- Modify: `src/Services/NewsletterService.php:54-95` (`send()`, vor dem Status-Claim)
- Modify: `src/Controllers/NewsletterController.php` (`send()` fängt die Ausnahme)
- Test: `tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php` (dritter Teil)

**Interfaces:**
- Consumes: Task 3 (`attachedTotalBytes()`, `MAX_ATTACHED_TOTAL`)
- Produces: `App\Exceptions\NewsletterAttachmentsTooLargeException extends \RuntimeException`, Konstruktor `(int $totalBytes, int $limitBytes)`

- [ ] **Step 1: Test ergänzen**

```php
    public function testSendIsRefusedWhenAttachmentsExceedTheLimit(): void
    {
        $newsletter = $this->draft();

        // Nur die Angabe in file_size zählt - ein echtes 11-MB-BLOB im Test wäre
        // nichts als Laufzeit.
        $this->attachment((int) $newsletter->id, 'riesig.pdf', 'attach', 11 * 1024 * 1024);

        $this->expectException(NewsletterAttachmentsTooLargeException::class);

        try {
            $this->newsletterService()->send($newsletter, (int) $_SESSION['user_id'], 'https://chor.example');
        } finally {
            $this->assertSame(
                Newsletter::STATUS_DRAFT,
                Newsletter::query()->find($newsletter->id)->status
            );
        }
    }

    public function testLinkedFilesDoNotCountTowardsTheLimit(): void
    {
        $newsletter = $this->draft();

        $this->attachment((int) $newsletter->id, 'riesig.pdf', 'link', 11 * 1024 * 1024);

        $this->newsletterService()->send($newsletter, (int) $_SESSION['user_id'], 'https://chor.example');

        $this->assertSame(
            Newsletter::STATUS_SENT,
            Newsletter::query()->find($newsletter->id)->status
        );
    }
```

`newsletterService()` liefert den `NewsletterService` wie im Test-Gerüst aufgebaut;
fehlt er dort, eine Methode im `NewsletterControllerTestScaffold` ergänzen, die
dieselben Abhängigkeiten nutzt wie `controller()`.

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev php vendor/bin/phpunit --filter "testSendIsRefusedWhenAttachmentsExceedTheLimit|testLinkedFilesDoNotCountTowardsTheLimit"`
Erwartet: FAIL — Klasse `NewsletterAttachmentsTooLargeException` existiert nicht.

- [ ] **Step 3: Ausnahme schreiben**

Zuerst `use App\Exceptions\NewsletterAttachmentsTooLargeException;` am Kopf von
`tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php` ergänzen — in Task 6 wäre
der Import noch ungenutzt und phpcs hätte ihn beanstandet.

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Die Summe der angehängten Dateien übersteigt, was verbreitete Postfächer
 * annehmen. Ohne diese Grenze liefe der Versand für jeden Empfänger einzeln
 * in denselben Zustellfehler, und die Redaktion sähe erst am Fehlerbericht,
 * dass nichts angekommen ist.
 */
class NewsletterAttachmentsTooLargeException extends RuntimeException
{
    public function __construct(int $totalBytes, int $limitBytes)
    {
        parent::__construct(sprintf(
            'Die angehängten Dateien sind zusammen %d MB groß, erlaubt sind %d MB. '
            . 'Stelle große Dateien auf "In der Mail verlinken" um.',
            (int) ceil($totalBytes / 1048576),
            (int) floor($limitBytes / 1048576)
        ));
    }
}
```

- [ ] **Step 4: Prüfung in `send()` einsetzen**

In `src/Services/NewsletterService::send()` **vor** dem bedingten Update, das den
Entwurf beansprucht (direkt nach der Empfängerprüfung):

```php
        // Vor dem Claim: Ein abgelehnter Versand darf den Entwurf nicht als
        // "versendet" zurücklassen.
        $attachedTotal = $this->attachments->attachedTotalBytes((int) $newsletter->id);
        if ($attachedTotal > NewsletterAttachmentService::MAX_ATTACHED_TOTAL) {
            throw new NewsletterAttachmentsTooLargeException(
                $attachedTotal,
                NewsletterAttachmentService::MAX_ATTACHED_TOTAL
            );
        }
```

- [ ] **Step 5: Controller meldet es verständlich**

In `NewsletterController::send()` die neue Ausnahme fangen — dieselbe Behandlung wie
`NewsletterWithoutRecipientsException`: Meldung aus `getMessage()` in `$_SESSION['error']`,
zurück auf die Edit-Seite.

- [ ] **Step 6: Tests laufen lassen**

Run: `ddev php vendor/bin/phpunit --filter "NewsletterAttachment|NewsletterSend"`
Erwartet: PASS.

- [ ] **Step 7: Commit**

```bash
ddev composer phpcs
git add src/Exceptions/NewsletterAttachmentsTooLargeException.php src/Services/NewsletterService.php src/Controllers/NewsletterController.php tests/Feature/NewsletterAttachmentDeliveryFeatureTest.php
git commit -m "feat(newsletter): Versand lehnt zu große Anhänge ab, bevor er beginnt"
```

---

### Task 9: Seed-Daten und Abschluss

**Files:**
- Modify: `src/Services/DevSeedService.php`
- Nachweis: Seed-Lauf und volle Testsuite

**Interfaces:**
- Consumes: Task 1, Task 3
- Produces: Seed-Newsletter mit zwei Dateien; Zähler `newsletter_attachments` im Seed-Bericht.

- [ ] **Step 1: `resetSeedData()` erweitern**

In `src/Services/DevSeedService.php` die Newsletter-Anhänge mit abräumen. Vorhandenes
Muster prüfen (`grep -n "attachments" src/Services/DevSeedService.php`); gibt es bereits
einen Aufruf von `deleteAllForEntities()`, dort die Newsletter-Kennungen ergänzen, sonst:

```php
        Attachment::query()
            ->where('entity_type', NewsletterAttachmentService::ENTITY_TYPE)
            ->delete();
```

- [ ] **Step 2: Seed-Methode schreiben**

```php
    /**
     * Zwei Dateien an einem Newsletter, eine je Zustellweg - nur so lässt sich in
     * Dev sehen, dass der Link-Block in der Mail auftaucht und der Anhang nicht.
     *
     * Die Angabe in `file_size` ist bewusst größer als der abgelegte Inhalt: Der
     * Link-Fall soll sichtbar sein, ohne vier Megabyte Platzhalter in die
     * Datenbank zu schreiben.
     */
    private function seedNewsletterAttachments(Newsletter $newsletter): int
    {
        $files = [
            ['Probenplan.pdf', NewsletterAttachmentService::MODE_ATTACH, 40 * 1024],
            ['Konzertprogramm.pdf', NewsletterAttachmentService::MODE_LINK, 4 * 1024 * 1024],
        ];

        $created = 0;
        foreach ($files as [$name, $mode, $size]) {
            Attachment::create([
                'entity_type' => NewsletterAttachmentService::ENTITY_TYPE,
                'entity_id' => (int) $newsletter->id,
                'filename' => EntityAttachmentService::storedName($name),
                'original_name' => $name,
                'mime_type' => 'application/pdf',
                'file_size' => $size,
                'file_content' => str_repeat('%PDF-1.4 Beispiel ', 64),
                'delivery_mode' => $mode,
            ]);
            $created++;
        }

        return $created;
    }
```

- [ ] **Step 3: In `run()` einhängen**

Nach dem Anlegen der Newsletter aufrufen und den Zähler in den Bericht aufnehmen,
im selben Stil wie die übrigen Einträge:

```php
        $counts['newsletter_attachments'] = $this->seedNewsletterAttachments($newsletter);
```

Imports ergänzen, soweit sie fehlen: `use App\Models\Attachment;`,
`use App\Services\EntityAttachmentService;`, `use App\Services\NewsletterAttachmentService;`.

- [ ] **Step 4: Seed wirklich laufen lassen**

Run: `ddev php bin/dev_seed.php`
Erwartet: Lauf ohne Fehler, im Bericht steht `newsletter_attachments: 2`.

- [ ] **Step 5: Volle Suite parallel**

Run: `ddev composer test:parallel`
Erwartet: PASS, keine Leck-Meldung.

- [ ] **Step 6: Linter über alles**

```bash
ddev composer phpcs
ddev composer twigcs
```

- [ ] **Step 7: Commit**

```bash
ddev php bin/normalize_lf_staged.php
git add src/Services/DevSeedService.php
git commit -m "feat(newsletter): Seed legt je einen angehängten und einen verlinkten Anhang an"
```

---

## Abschluss

Nach Task 9 steht das Feature. Der Zusammenführung auf `main` geht der
`git-commit`-Ablauf voraus (Squash, Rebase, Fast-Forward); gepusht wird erst nach
ausdrücklicher Zustimmung.

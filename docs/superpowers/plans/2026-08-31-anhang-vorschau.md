# Anhang-Vorschau und Download vereinheitlichen — Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Anhänge bekommen an allen sechs Anzeigestellen dasselbe Vorschau-Modal und denselben Download-Button, ausgeliefert über zwei zentrale Routen mit einer Rechte-Tabelle je `entity_type`.

**Architecture:** Eine Utility-Klasse entscheidet über darstellbare MIME-Typen, eine Registry über Zugriffsrechte je `entity_type`, eine Response-Factory baut Download- und Inline-Antworten samt `Range`. Darauf sitzt ein schlanker `AttachmentController` mit `/attachments/{id}/preview` und `/attachments/{id}/download`. Im Frontend rendert ein Twig-Baustein das Buttonpaar, ein einziges global eingebundenes Modal zeigt die Vorschau.

**Tech Stack:** PHP 8, Slim 4, PHP-DI (Autowiring), Eloquent, Twig, Bootstrap 5, PHPUnit, Phinx (hier nicht nötig — keine Schemaänderung).

**Spec:** `docs/superpowers/specs/2026-08-31-anhang-vorschau-design.md`

## Global Constraints

- Bezeichner englisch, Inhalt deutsch mit echten Umlauten `ä ö ü ß` (`instructions/naming.md`). Der Hook `.claude/hooks/check-german-umlauts.sh` weist `ae/oe/ue/ss` ab.
- Commit-Nachrichten sind deutscher Fließtext.
- PHP: PSR-12, 4 Leerzeichen, Zeilenlänge weich 120 / hart 130. Nach PHP-Änderungen `ddev composer phpcs`, bei Bedarf `ddev composer phpcbf`.
- Twig: doppelte Anführungszeichen, keine mehrzeiligen booleschen Ausdrücke, benannte Argumente ohne Leerzeichen um `=`. Nach Twig-Änderungen `ddev composer twigcs`.
- Templates: kein Inline-JS, kein Inline-CSS, keine CDN-Ressourcen.
- Alle Textdateien mit LF. Nach jedem Schreiben auf Windows normalisieren:
  ```powershell
  $f = "<absoluter-pfad>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
  ```
- Logging über `Psr\Log\LoggerInterface`, strukturierte JSON-Einträge mit stabilem `event`-Schlüssel. Kein `error_log()`.
- Tests laufen mit `ddev exec php vendor/bin/phpunit`.
- Es gibt **keine** Schemaänderung in diesem Plan. Keine Phinx-Migration.
- Kein `git push`.

---

## File Structure

**Neu:**

| Datei | Verantwortung |
|---|---|
| `src/Util/AttachmentPreview.php` | MIME-Normalisierung, `isInlineServable()`, `isModalPreviewable()` |
| `src/Services/AttachmentResponseFactory.php` | PSR-7-Antworten für Download und Inline, `Range`-Behandlung |
| `src/Services/AttachmentAccessRegistry.php` | `entity_type` → Modulprüfung + Rechteprüfung |
| `src/Controllers/AttachmentController.php` | zwei Routen, dünn: laden, prüfen, ausliefern |
| `templates/partials/attachment_actions.twig` | Buttonpaar für einen Anhang |
| `templates/partials/attachment_preview_modal.twig` | die Modal-Hülle, einmal global |
| `public/js/attachment-preview.js` | Modal befüllen je MIME-Gruppe |
| `src/Services/DevSeedAttachmentFixtures.php` | echte Dateiinhalte für Seed-Anhänge |
| `tests/Unit/Util/AttachmentPreviewTest.php` | Unit-Test der MIME-Entscheidung |
| `tests/Unit/Services/AttachmentResponseFactoryTest.php` | Unit-Test Range-Parser und Header |
| `tests/Feature/AttachmentAccessFeatureTest.php` | Rechte je `entity_type`, Module, Statuscodes |
| `tests/Feature/AttachmentPreviewCspFeatureTest.php` | CSP-Ausnahme nur für die Vorschau-Route |
| `tests/Feature/AttachmentActionsPartialFeatureTest.php` | Baustein an allen sechs Stellen benutzt |

**Geändert:**

| Datei | Änderung |
|---|---|
| `src/Routes.php` | zwei neue Routen, fünf alte Routenblöcke entfernt |
| `src/Dependencies.php` | Twig-Funktion `attachment_previewable`, Registry im Container |
| `src/Middleware/SecurityHeadersMiddleware.php` | zweites Framing-Muster |
| `src/Controllers/DownloadController.php` | `downloadAttachment()`, `streamAttachment()`, `parseRangeHeader()`, `$streamableMimeTypes` entfallen |
| `src/Controllers/FinanceController.php` | `viewAttachment()`, `isInlineViewableMimeType()` entfallen |
| `src/Controllers/TaskController.php` | `downloadAttachment()` entfällt |
| `src/Controllers/SponsorController.php` | `downloadAttachment()` entfällt |
| `src/Controllers/SponsorshipController.php` | `downloadAttachment()` entfällt |
| `src/Controllers/SponsoringAttachmentController.php` | `download_url` aus `baseRow()`-Ergänzungen entfernt |
| `src/Services/EntityAttachmentService.php` | `buildDownloadResponse()` entfällt |
| `templates/layout.twig` | Modal + JS global einbinden |
| `templates/partials/attachments.twig` | Buttonpaar |
| `templates/finances/index.twig` | Buttonpaar im Dropdown |
| `templates/sponsoring/attachments/index.twig` | Buttonpaar in Aktionsspalte |
| `templates/sponsoring/sponsors/detail.twig` | Buttonpaar in beiden Listen |
| `templates/songs/detail.twig` | Buttonpaar |
| `templates/songs/downloads.twig` | Player-Quelle umgestellt, Buttonpaar |
| `public/css/style.css` | Modal-Klassen |
| `src/Services/DevSeedService.php` | Fixture-Klasse benutzen |
| `tests/Feature/DownloadFeatureTest.php` | Erwartungen an entfernte Methoden korrigieren |

---

## Task 1: MIME-Entscheidung als eigene Klasse

**Files:**
- Create: `src/Util/AttachmentPreview.php`
- Test: `tests/Unit/Util/AttachmentPreviewTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces:
  - `AttachmentPreview::normalize(string $mimeType): string`
  - `AttachmentPreview::isInlineServable(string $mimeType): bool`
  - `AttachmentPreview::isModalPreviewable(string $mimeType): bool`

- [ ] **Step 1: Write the failing test**

Datei `tests/Unit/Util/AttachmentPreviewTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\AttachmentPreview;
use PHPUnit\Framework\TestCase;

/**
 * Die einzige Stelle, die entscheidet, was inline ausgeliefert und was im
 * Modal gezeigt wird. Zwei Fragen, weil sie nicht dieselbe sind: MIDI wird
 * ausgeliefert (der eingebettete Player auf der Downloads-Seite braucht die
 * Quelle), taugt aber nicht fuers globale Modal - die Wiedergabe haengt an
 * drei Bibliotheken, die nur auf jener Seite geladen werden.
 */
final class AttachmentPreviewTest extends TestCase
{
    public function testNormalizeCutsParametersAndLowercases(): void
    {
        $this->assertSame('text/plain', AttachmentPreview::normalize('text/plain; charset=utf-8'));
        $this->assertSame('text/plain', AttachmentPreview::normalize('  TEXT/PLAIN  '));
        $this->assertSame('application/pdf', AttachmentPreview::normalize('Application/PDF'));
        $this->assertSame('', AttachmentPreview::normalize(''));
    }

    public function testInlineServableTypes(): void
    {
        foreach (['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'] as $mime) {
            $this->assertTrue(AttachmentPreview::isInlineServable($mime), $mime);
        }

        foreach (['text/plain', 'audio/mpeg', 'audio/midi', 'audio/x-midi', 'application/x-midi'] as $mime) {
            $this->assertTrue(AttachmentPreview::isInlineServable($mime), $mime);
        }

        $this->assertTrue(AttachmentPreview::isInlineServable('text/plain; charset=utf-8'));
    }

    public function testInlineServableRejectsOfficeDocuments(): void
    {
        $office = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        foreach ($office as $mime) {
            $this->assertFalse(AttachmentPreview::isInlineServable($mime), $mime);
        }

        $this->assertFalse(AttachmentPreview::isInlineServable('text/html'));
        $this->assertFalse(AttachmentPreview::isInlineServable(''));
    }

    public function testModalPreviewableExcludesMidi(): void
    {
        $this->assertTrue(AttachmentPreview::isModalPreviewable('application/pdf'));
        $this->assertTrue(AttachmentPreview::isModalPreviewable('image/png'));
        $this->assertTrue(AttachmentPreview::isModalPreviewable('text/plain; charset=utf-8'));
        $this->assertTrue(AttachmentPreview::isModalPreviewable('audio/mpeg'));

        foreach (['audio/midi', 'audio/x-midi', 'application/x-midi'] as $mime) {
            $this->assertFalse(AttachmentPreview::isModalPreviewable($mime), $mime);
        }
    }

    public function testModalPreviewableIsSubsetOfInlineServable(): void
    {
        $all = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'text/plain',
            'audio/mpeg',
            'audio/midi',
            'audio/x-midi',
            'application/x-midi',
            'application/msword',
            'text/html',
        ];

        foreach ($all as $mime) {
            if (AttachmentPreview::isModalPreviewable($mime)) {
                $this->assertTrue(AttachmentPreview::isInlineServable($mime), $mime);
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Util/AttachmentPreviewTest.php`
Expected: FAIL — `Class "App\Util\AttachmentPreview" not found`

- [ ] **Step 3: Write minimal implementation**

Datei `src/Util/AttachmentPreview.php`:

```php
<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Entscheidet, wie ein Anhang ausgeliefert und angezeigt wird.
 *
 * Vorher stand diese Liste dreimal im Code: als `$streamableMimeTypes` im
 * DownloadController, als `isInlineViewableMimeType()` im FinanceController und
 * gar nicht in den Templates - die verlinkten einfach jeden Anhang gleich. Die
 * Kopien liefen bereits auseinander, und die Oberflaeche wusste nichts davon.
 *
 * Zwei Fragen, bewusst getrennt:
 *
 *  - `isInlineServable()` beantwortet, was die Vorschau-Route ueberhaupt mit
 *    `Content-Disposition: inline` herausgibt.
 *  - `isModalPreviewable()` beantwortet, was das gemeinsame Modal auch wirklich
 *    darstellen kann.
 *
 * MIDI faellt auseinander: die Downloads-Seite spielt es mit drei nur dort
 * geladenen Bibliotheken ab und braucht dafuer die Inline-Quelle, das globale
 * Modal koennte es nirgends anzeigen.
 */
final class AttachmentPreview
{
    /** @var list<string> */
    private const INLINE_SERVABLE = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'text/plain',
        'audio/mpeg',
        'audio/midi',
        'audio/x-midi',
        'application/x-midi',
    ];

    /** @var list<string> */
    private const MODAL_ONLY_EXCLUDED = [
        'audio/midi',
        'audio/x-midi',
        'application/x-midi',
    ];

    /**
     * `UploadValidator::normalizeMimeType()` reicht hier nicht: es trimmt und
     * schreibt klein, schneidet aber keine Parameter ab. Ein gespeichertes
     * `text/plain; charset=utf-8` fiele damit durch jeden Vergleich.
     */
    public static function normalize(string $mimeType): string
    {
        $withoutParameters = explode(';', $mimeType, 2)[0];

        return strtolower(trim($withoutParameters));
    }

    public static function isInlineServable(string $mimeType): bool
    {
        return in_array(self::normalize($mimeType), self::INLINE_SERVABLE, true);
    }

    public static function isModalPreviewable(string $mimeType): bool
    {
        $normalized = self::normalize($mimeType);

        if (in_array($normalized, self::MODAL_ONLY_EXCLUDED, true)) {
            return false;
        }

        return in_array($normalized, self::INLINE_SERVABLE, true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Util/AttachmentPreviewTest.php`
Expected: PASS, 5 Tests

- [ ] **Step 5: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\src\Util\AttachmentPreview.php",
  "d:\Proggen\ChorManager\tests\Unit\Util\AttachmentPreviewTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer phpcs`
Expected: keine Fehler in `src/Util/AttachmentPreview.php`

- [ ] **Step 6: Commit**

```bash
git add src/Util/AttachmentPreview.php tests/Unit/Util/AttachmentPreviewTest.php
git commit -m "feat(attachments): eine Quelle für darstellbare Dateitypen"
```

---

## Task 2: Antwort-Factory mit Range-Behandlung

**Files:**
- Create: `src/Services/AttachmentResponseFactory.php`
- Test: `tests/Unit/Services/AttachmentResponseFactoryTest.php`
- Read for reference: `src/Controllers/DownloadController.php:85-195`

**Interfaces:**
- Consumes: `AttachmentPreview::isInlineServable()` aus Task 1.
- Produces:
  - `AttachmentResponseFactory::parseRangeHeader(string $rangeHeader, int $fileSize): ?array` (statisch, gibt `array{0:int,1:int}` oder `null`)
  - `AttachmentResponseFactory::download(ResponseInterface $response, Attachment $attachment): ResponseInterface`
  - `AttachmentResponseFactory::inline(ResponseInterface $response, Attachment $attachment, string $rangeHeader): ResponseInterface`

- [ ] **Step 1: Write the failing test**

Datei `tests/Unit/Services/AttachmentResponseFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attachment;
use App\Services\AttachmentResponseFactory;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Baut die Antworten fuer Download und Vorschau. Der Range-Parser lag vorher
 * als statische Methode im DownloadController und war damit nur ueber die
 * Song-Downloads erreichbar.
 */
final class AttachmentResponseFactoryTest extends TestCase
{
    private function attachment(string $mime, string $content, string $name): Attachment
    {
        $attachment = new Attachment();
        $attachment->mime_type = $mime;
        $attachment->original_name = $name;
        $attachment->file_size = strlen($content);
        $attachment->file_content = $content;

        return $attachment;
    }

    public function testParseRangeHeaderAcceptsValidRanges(): void
    {
        $this->assertSame([10, 20], AttachmentResponseFactory::parseRangeHeader('bytes=10-20', 100));
        $this->assertSame([25, 99], AttachmentResponseFactory::parseRangeHeader('bytes=25-', 100));
        $this->assertSame([90, 99], AttachmentResponseFactory::parseRangeHeader('bytes=-10', 100));
        $this->assertSame([0, 99], AttachmentResponseFactory::parseRangeHeader('bytes=-200', 100));
    }

    public function testParseRangeHeaderRejectsInvalidRanges(): void
    {
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('bytes=20-10', 100));
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('bytes=100-101', 100));
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('bytes=-0', 100));
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('invalid', 100));
        $this->assertNull(AttachmentResponseFactory::parseRangeHeader('bytes=0-0', 0));
    }

    public function testDownloadSetsAttachmentDisposition(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('application/pdf', 'PDF-Inhalt', 'vertrag rechnung.pdf');

        $response = $factory->download(new Response(), $attachment);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('attachment; ', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('filename="vertrag rechnung.pdf"', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString("filename*=UTF-8''", $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('10', $response->getHeaderLine('Content-Length'));

        $response->getBody()->rewind();
        $this->assertSame('PDF-Inhalt', $response->getBody()->getContents());
    }

    public function testDownloadFallsBackToOctetStreamWhenMimeMissing(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('', 'irgendwas', 'ohne-typ.bin');

        $response = $factory->download(new Response(), $attachment);

        $this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
    }

    public function testInlineWithoutRangeReturnsWholeFile(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('text/plain', 'Zeile eins', 'beleg.txt');

        $response = $factory->inline(new Response(), $attachment, '');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('inline; ', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertSame('10', $response->getHeaderLine('Content-Length'));
    }

    public function testInlineWithRangeReturnsPartialContent(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('audio/mpeg', '0123456789', 'probe.mp3');

        $response = $factory->inline(new Response(), $attachment, 'bytes=2-5');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 2-5/10', $response->getHeaderLine('Content-Range'));
        $this->assertSame('4', $response->getHeaderLine('Content-Length'));

        $response->getBody()->rewind();
        $this->assertSame('2345', $response->getBody()->getContents());
    }

    public function testInlineWithUnsatisfiableRangeReturns416(): void
    {
        $factory = new AttachmentResponseFactory();
        $attachment = $this->attachment('audio/mpeg', '0123456789', 'probe.mp3');

        $response = $factory->inline(new Response(), $attachment, 'bytes=50-60');

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */10', $response->getHeaderLine('Content-Range'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Services/AttachmentResponseFactoryTest.php`
Expected: FAIL — `Class "App\Services\AttachmentResponseFactory" not found`

- [ ] **Step 3: Write minimal implementation**

Datei `src/Services/AttachmentResponseFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Util\DownloadFileName;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Baut die HTTP-Antwort fuer einen Anhang - einmal als Download, einmal zur
 * Anzeige im Browser.
 *
 * Vorher gab es drei handgebaute Fassungen des `Content-Disposition`-Headers
 * (EntityAttachmentService, FinanceController, DownloadController), von denen
 * zwei kein `Content-Length` setzten und eine `Accept-Ranges` nur fuer Audio
 * kannte.
 */
class AttachmentResponseFactory
{
    public function download(Response $response, Attachment $attachment): Response
    {
        $content = (string) $attachment->file_content;

        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', $this->contentType($attachment))
            ->withHeader('Content-Length', (string) strlen($content))
            ->withHeader('Content-Disposition', $this->disposition('attachment', $attachment));
    }

    /**
     * Ohne `Range`-Kopfzeile die ganze Datei, sonst der angeforderte Ausschnitt.
     * `Accept-Ranges` steht auch in der vollstaendigen Antwort - erst dadurch
     * weiss ein Player, dass er ueberhaupt springen darf.
     */
    public function inline(Response $response, Attachment $attachment, string $rangeHeader): Response
    {
        $content = (string) $attachment->file_content;
        $fileSize = strlen($content);
        $rangeHeader = trim($rangeHeader);

        if ($rangeHeader === '') {
            $response->getBody()->write($content);

            return $response
                ->withHeader('Content-Type', $this->contentType($attachment))
                ->withHeader('Content-Length', (string) $fileSize)
                ->withHeader('Accept-Ranges', 'bytes')
                ->withHeader('Content-Disposition', $this->disposition('inline', $attachment));
        }

        $range = self::parseRangeHeader($rangeHeader, $fileSize);
        if ($range === null) {
            return $response
                ->withStatus(416)
                ->withHeader('Content-Range', 'bytes */' . $fileSize);
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        $response->getBody()->write(substr($content, $start, $length));

        return $response
            ->withStatus(206)
            ->withHeader('Content-Type', $this->contentType($attachment))
            ->withHeader('Content-Length', (string) $length)
            ->withHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $fileSize)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition', $this->disposition('inline', $attachment));
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public static function parseRangeHeader(string $rangeHeader, int $fileSize): ?array
    {
        if ($fileSize <= 0) {
            return null;
        }

        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches)) {
            return null;
        }

        $rawStart = $matches[1];
        $rawEnd = $matches[2];

        // RFC 7233 suffix-byte-range-spec: "bytes=-500" meint die letzten 500 Bytes.
        if ($rawStart === '' && $rawEnd !== '') {
            $suffixLength = (int) $rawEnd;
            if ($suffixLength <= 0) {
                return null;
            }

            return [max(0, $fileSize - $suffixLength), $fileSize - 1];
        }

        $start = $rawStart === '' ? 0 : (int) $rawStart;
        $end = $rawEnd === '' ? $fileSize - 1 : (int) $rawEnd;

        if ($start < 0 || $end < $start || $start >= $fileSize || $end >= $fileSize) {
            return null;
        }

        return [$start, $end];
    }

    private function contentType(Attachment $attachment): string
    {
        $mimeType = trim((string) $attachment->mime_type);

        return $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }

    private function disposition(string $type, Attachment $attachment): string
    {
        $name = DownloadFileName::sanitize((string) $attachment->original_name);

        return $type . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Services/AttachmentResponseFactoryTest.php`
Expected: PASS, 7 Tests

- [ ] **Step 5: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\src\Services\AttachmentResponseFactory.php",
  "d:\Proggen\ChorManager\tests\Unit\Services\AttachmentResponseFactoryTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer phpcs`
Expected: keine Fehler in `src/Services/AttachmentResponseFactory.php`

- [ ] **Step 6: Commit**

```bash
git add src/Services/AttachmentResponseFactory.php tests/Unit/Services/AttachmentResponseFactoryTest.php
git commit -m "feat(attachments): gemeinsame Antwort-Factory mit Range-Unterstützung"
```

---

## Task 3: Rechte-Registry je entity_type

**Files:**
- Create: `src/Services/AttachmentAccessRegistry.php`
- Test: `tests/Feature/AttachmentAccessRegistryFeatureTest.php`
- Read for reference: `src/Controllers/DownloadController.php:143-160` (Song-Abfrage), `src/Policies/SponsoringPolicy.php:126-140`, `src/Policies/TaskPolicy.php:36`

**Interfaces:**
- Consumes: nichts aus Task 1/2.
- Produces:
  - `new AttachmentAccessRegistry(SponsoringPolicy $sponsoringPolicy, TaskPolicy $taskPolicy, array $modules)`
  - `AttachmentAccessRegistry::mayAccess(Attachment $attachment, int $userId): bool`

Der Parameter `$modules` ist das Array aus `src/Settings.php:44`, also `['finance' => bool, 'sponsoring' => bool, 'tasks' => bool, ...]`.

- [ ] **Step 1: Write the failing test**

Datei `tests/Feature/AttachmentAccessRegistryFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Die Tabelle, die entscheidet, wer einen Anhang sehen darf.
 *
 * Sie ersetzt fuenf verstreute Pruefungen. Zwei Eigenschaften sind ihr Zweck:
 * ein unbekannter `entity_type` wird abgelehnt statt durchgelassen, und ein
 * abgeschaltetes Modul sperrt seine Anhaenge - vorher erledigte das die
 * Routen-Datei, in der die alten Routen innerhalb der Modul-Bloecke lagen.
 */
final class AttachmentAccessRegistryFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @param array<string, bool> $modules
     */
    private function registry(array $modules = []): AttachmentAccessRegistry
    {
        $defaults = [
            'finance' => true,
            'sponsoring' => true,
            'tasks' => true,
        ];

        return new AttachmentAccessRegistry(
            new SponsoringPolicy(),
            new TaskPolicy(),
            array_merge($defaults, $modules)
        );
    }

    private function attachment(string $entityType, int $entityId = 1): Attachment
    {
        $attachment = new Attachment();
        $attachment->entity_type = $entityType;
        $attachment->entity_id = $entityId;

        return $attachment;
    }

    public function testUnknownEntityTypeIsRejected(): void
    {
        $_SESSION['can_manage_finances'] = true;
        $_SESSION['can_manage_tasks'] = true;

        $this->assertFalse($this->registry()->mayAccess($this->attachment('newsletter'), 1));
        $this->assertFalse($this->registry()->mayAccess($this->attachment(''), 1));
    }

    public function testFinanceNeedsReadOrManagePermission(): void
    {
        $_SESSION['can_read_finances'] = true;
        $this->assertTrue($this->registry()->mayAccess($this->attachment('finance'), 1));

        $_SESSION = ['can_manage_finances' => true];
        $this->assertTrue($this->registry()->mayAccess($this->attachment('finance'), 1));

        $_SESSION = [];
        $this->assertFalse($this->registry()->mayAccess($this->attachment('finance'), 1));
    }

    public function testFinanceIsBlockedWhenModuleIsOff(): void
    {
        $_SESSION['can_manage_finances'] = true;

        $registry = $this->registry(['finance' => false]);

        $this->assertFalse($registry->mayAccess($this->attachment('finance'), 1));
    }

    public function testTaskNeedsTaskManagementAndModule(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $this->assertTrue($this->registry()->mayAccess($this->attachment('task'), 1));

        $this->assertFalse($this->registry(['tasks' => false])->mayAccess($this->attachment('task'), 1));

        $_SESSION = [];
        $this->assertFalse($this->registry()->mayAccess($this->attachment('task'), 1));
    }

    public function testSponsorAndSponsorshipAreBlockedWhenModuleIsOff(): void
    {
        $_SESSION['can_manage_sponsoring'] = true;

        $registry = $this->registry(['sponsoring' => false]);

        $this->assertFalse($registry->mayAccess($this->attachment('sponsor'), 1));
        $this->assertFalse($registry->mayAccess($this->attachment('sponsorship'), 1));
    }

    public function testSongLibraryManagerMayAccessWithoutProjectMembership(): void
    {
        $_SESSION['can_manage_song_library'] = true;

        // entity_id zeigt auf ein Lied, das es nicht geben muss: das Recht
        // entscheidet, nicht die Zuordnung zu einem Projekt.
        $this->assertTrue($this->registry()->mayAccess($this->attachment('song', 999999), 1));
    }

    public function testSongWithoutPermissionAndWithoutMembershipIsRejected(): void
    {
        $this->assertFalse($this->registry()->mayAccess($this->attachment('song', 999999), 1));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentAccessRegistryFeatureTest.php`
Expected: FAIL — `Class "App\Services\AttachmentAccessRegistry" not found`

- [ ] **Step 3: Write minimal implementation**

Datei `src/Services/AttachmentAccessRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Wer darf welchen Anhang sehen.
 *
 * Vorher stand die Antwort fuenfmal in je einem Controller, und jede Fassung
 * kannte nur ihren eigenen Bereich. Mit einer gemeinsamen Auslieferungsroute
 * braucht es eine gemeinsame Tabelle, sonst waere die Route ein Loch, das an
 * allen fuenf Pruefungen vorbeifuehrt.
 *
 * Zwei Eigenschaften sind hier bewusst festgelegt:
 *
 *  - Ein `entity_type` ohne Eintrag wird abgelehnt. Ein neuer Anhang-Typ muss
 *    seine Regel hier hinterlegen, sonst ist er nicht abrufbar - das ist die
 *    sichere Richtung.
 *  - Ein abgeschaltetes Modul sperrt seine Anhaenge. Die alten Routen lagen
 *    innerhalb der `if ($settings['modules'][...])`-Bloecke in Routes.php und
 *    verschwanden mit dem Modul; die zentrale Route liegt ausserhalb.
 */
class AttachmentAccessRegistry
{
    private SponsoringPolicy $sponsoringPolicy;
    private TaskPolicy $taskPolicy;

    /** @var array<string, bool> */
    private array $modules;

    /**
     * @param array<string, bool> $modules
     */
    public function __construct(SponsoringPolicy $sponsoringPolicy, TaskPolicy $taskPolicy, array $modules)
    {
        $this->sponsoringPolicy = $sponsoringPolicy;
        $this->taskPolicy = $taskPolicy;
        $this->modules = $modules;
    }

    public function mayAccess(Attachment $attachment, int $userId): bool
    {
        $entityType = (string) $attachment->entity_type;
        $entityId = (int) $attachment->entity_id;

        return match ($entityType) {
            'finance'     => $this->moduleEnabled('finance') && $this->mayReadFinances(),
            'task'        => $this->moduleEnabled('tasks') && $this->taskPolicy->canManageTasks(),
            'sponsor'     => $this->moduleEnabled('sponsoring') && $this->maySeeSponsor($entityId),
            'sponsorship' => $this->moduleEnabled('sponsoring') && $this->maySeeSponsorship($entityId),
            'song'        => $this->maySeeSong($entityId, $userId),
            default       => false,
        };
    }

    private function moduleEnabled(string $module): bool
    {
        return (bool) ($this->modules[$module] ?? false);
    }

    /**
     * Dieselben zwei Rechte wie das Gate `requiresFinanceRead` in RoleMiddleware.
     */
    private function mayReadFinances(): bool
    {
        return (bool) ($_SESSION['can_read_finances'] ?? false)
            || (bool) ($_SESSION['can_manage_finances'] ?? false);
    }

    private function maySeeSponsor(int $sponsorId): bool
    {
        $sponsor = Sponsor::find($sponsorId);

        return $sponsor !== null && $this->sponsoringPolicy->canSeeSponsorDetails($sponsor);
    }

    private function maySeeSponsorship(int $sponsorshipId): bool
    {
        $sponsorship = Sponsorship::find($sponsorshipId);

        return $sponsorship !== null && $this->sponsoringPolicy->canSeeSponsorshipDetails($sponsorship);
    }

    /**
     * Zwei Wege zum selben Notenblatt: wer das Lied im Projekt singt, und wer
     * das Repertoire verwaltet. Der zweite Weg fehlte bisher - der Link auf der
     * Lied-Detailseite zeigte auf die mitgliedschaftsgebundene Route und lief
     * fuer eine Repertoire-Verwaltung ohne Projekt ins Leere.
     */
    private function maySeeSong(int $songId, int $userId): bool
    {
        if ((bool) ($_SESSION['can_manage_song_library'] ?? false)) {
            return true;
        }

        if ($userId <= 0 || $songId <= 0) {
            return false;
        }

        return Capsule::table('project_song_assignments')
            ->join(
                'project_users',
                'project_users.project_id',
                '=',
                'project_song_assignments.project_id'
            )
            ->where('project_song_assignments.song_id', $songId)
            ->where('project_users.user_id', $userId)
            ->exists();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentAccessRegistryFeatureTest.php`
Expected: PASS, 7 Tests

Falls der Song-Test an fehlender Datenbankverbindung scheitert, in `setUp()` `Bootstrap::setupTestDatabase();` ergänzen (Import `use Tests\Unit\Bootstrap;`) — dasselbe Muster wie in `tests/Feature/MailBadgeEndpointFeatureTest.php:33`.

- [ ] **Step 5: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\src\Services\AttachmentAccessRegistry.php",
  "d:\Proggen\ChorManager\tests\Feature\AttachmentAccessRegistryFeatureTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer phpcs`
Expected: keine Fehler

- [ ] **Step 6: Commit**

```bash
git add src/Services/AttachmentAccessRegistry.php tests/Feature/AttachmentAccessRegistryFeatureTest.php
git commit -m "feat(attachments): Rechte-Tabelle je entity_type"
```

---

## Task 4: AttachmentController mit den zwei Routen

**Files:**
- Create: `src/Controllers/AttachmentController.php`
- Modify: `src/Routes.php` (neue Routen im geschützten Hauptblock, direkt vor `// Download section for project members`, aktuell Zeile 187)
- Modify: `src/Dependencies.php` (Registry-Eintrag im Container)
- Test: `tests/Feature/AttachmentAccessFeatureTest.php`

**Interfaces:**
- Consumes: `AttachmentPreview` (Task 1), `AttachmentResponseFactory` (Task 2), `AttachmentAccessRegistry` (Task 3), `EntityAttachmentService::METADATA_COLUMNS`.
- Produces:
  - `AttachmentController::preview(Request $request, Response $response, array $args): Response`
  - `AttachmentController::download(Request $request, Response $response, array $args): Response`
  - Routen `/attachments/{id:[0-9]+}/preview` und `/attachments/{id:[0-9]+}/download`

- [ ] **Step 1: Write the failing test**

Datei `tests/Feature/AttachmentAccessFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AttachmentController;
use App\Models\Attachment;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use App\Services\AttachmentResponseFactory;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die zentrale Auslieferung. Geprueft wird, was an der alten, verstreuten
 * Fassung nicht pruefbar war: dass fehlendes Recht und fehlender Datensatz
 * dieselbe Antwort geben, und dass ein nicht darstellbarer Typ auf der
 * Vorschau-Route abgewiesen wird statt zum Download zu werden.
 */
final class AttachmentAccessFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** @var list<int> */
    private array $createdAttachmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->createdAttachmentIds !== []) {
            Attachment::whereIn('id', $this->createdAttachmentIds)->delete();
            $this->createdAttachmentIds = [];
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function createAttachment(string $entityType, string $mime, string $name): Attachment
    {
        $content = 'Inhalt für ' . $name;

        $attachment = Attachment::create([
            'entity_type'   => $entityType,
            'entity_id'     => 424242,
            'filename'      => 'seed_' . $name,
            'original_name' => $name,
            'mime_type'     => $mime,
            'file_size'     => strlen($content),
            'file_content'  => $content,
        ]);

        $this->createdAttachmentIds[] = (int) $attachment->id;

        return $attachment;
    }

    private function makeController(): AttachmentController
    {
        $registry = new AttachmentAccessRegistry(
            new SponsoringPolicy(),
            new TaskPolicy(),
            ['finance' => true, 'sponsoring' => true, 'tasks' => true]
        );

        return new AttachmentController($registry, new AttachmentResponseFactory());
    }

    public function testTaskAttachmentDownloadForPermittedUser(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment('task', 'application/pdf', 'protokoll.pdf');

        $response = $this->makeController()->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('attachment; ', $response->getHeaderLine('Content-Disposition'));
    }

    public function testTaskAttachmentPreviewForPermittedUser(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment('task', 'application/pdf', 'protokoll.pdf');

        $response = $this->makeController()->preview(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/preview'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('inline; ', $response->getHeaderLine('Content-Disposition'));
    }

    public function testWithoutPermissionBothRoutesAnswerNotFound(): void
    {
        $attachment = $this->createAttachment('task', 'application/pdf', 'protokoll.pdf');
        $controller = $this->makeController();

        $download = $controller->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );
        $preview = $controller->preview(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/preview'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(404, $download->getStatusCode());
        $this->assertSame(404, $preview->getStatusCode());
    }

    public function testMissingAttachmentAnswersNotFound(): void
    {
        $_SESSION['can_manage_tasks'] = true;

        $response = $this->makeController()->download(
            $this->makeRequest('GET', '/attachments/99999999/download'),
            $this->makeResponse(),
            ['id' => '99999999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPreviewRejectsNonServableMimeType(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment(
            'task',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'konzept.docx'
        );

        $response = $this->makeController()->preview(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/preview'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testDownloadAcceptsNonServableMimeType(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $attachment = $this->createAttachment(
            'task',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'konzept.docx'
        );

        $response = $this->makeController()->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testFinanceAttachmentNeedsFinancePermission(): void
    {
        $attachment = $this->createAttachment('finance', 'text/plain', 'beleg.txt');
        $controller = $this->makeController();

        $denied = $controller->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );
        $this->assertSame(404, $denied->getStatusCode());

        $_SESSION['can_read_finances'] = true;
        $allowed = $controller->download(
            $this->makeRequest('GET', '/attachments/' . $attachment->id . '/download'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );
        $this->assertSame(200, $allowed->getStatusCode());
    }

    public function testPreviewPassesRangeHeaderThrough(): void
    {
        $_SESSION['can_manage_song_library'] = true;
        $attachment = $this->createAttachment('song', 'audio/mpeg', 'probe.mp3');

        $response = $this->makeController()->preview(
            $this->makeRequest(
                'GET',
                '/attachments/' . $attachment->id . '/preview',
                [],
                [],
                ['Range' => 'bytes=0-3']
            ),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('4', $response->getHeaderLine('Content-Length'));
    }

    public function testRoutesAreRegistered(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString("'/attachments/{id:[0-9]+}/preview'", $routes);
        $this->assertStringContainsString("'/attachments/{id:[0-9]+}/download'", $routes);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentAccessFeatureTest.php`
Expected: FAIL — `Class "App\Controllers\AttachmentController" not found`

- [ ] **Step 3: Write the controller**

Datei `src/Controllers/AttachmentController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Attachment;
use App\Services\AttachmentAccessRegistry;
use App\Services\AttachmentResponseFactory;
use App\Services\EntityAttachmentService;
use App\Util\AttachmentPreview;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Die eine Stelle, die Anhaenge ausliefert.
 *
 * Bewusst ohne RoleMiddleware in Routes.php: welches Recht noetig ist, haengt
 * am `entity_type` der Zeile, nicht am Pfad. Die Entscheidung kann deshalb erst
 * fallen, wenn der Anhang geladen ist - dafuer ist die Registry da.
 */
class AttachmentController
{
    private AttachmentAccessRegistry $access;
    private AttachmentResponseFactory $responses;

    public function __construct(AttachmentAccessRegistry $access, AttachmentResponseFactory $responses)
    {
        $this->access = $access;
        $this->responses = $responses;
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $attachment = $this->authorize($args);
        if ($attachment === null) {
            return $this->notFound($response);
        }

        return $this->responses->download($response, $attachment);
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        $attachment = $this->authorize($args);
        if ($attachment === null) {
            return $this->notFound($response);
        }

        if (!AttachmentPreview::isInlineServable((string) $attachment->mime_type)) {
            $response->getBody()->write('Dieser Dateityp wird nicht im Browser angezeigt.');

            return $response->withStatus(415)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $this->responses->inline($response, $attachment, $request->getHeaderLine('Range'));
    }

    /**
     * Erst die Metadaten, dann das Recht, erst danach der Inhalt. `file_content`
     * ist ein BLOB - wer ihn vor der Pruefung liest, zieht bei einem
     * Vertrags-PDF zweistellige Megabyte durch den Speicher, um sie gleich
     * darauf zu verwerfen.
     *
     * @param array<string, mixed> $args
     */
    private function authorize(array $args): ?Attachment
    {
        $attachmentId = (int) ($args['id'] ?? 0);
        if ($attachmentId <= 0) {
            return null;
        }

        $metadata = Attachment::query()
            ->select(EntityAttachmentService::METADATA_COLUMNS)
            ->find($attachmentId);

        if ($metadata === null) {
            return null;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$this->access->mayAccess($metadata, $userId)) {
            return null;
        }

        return Attachment::find($attachmentId);
    }

    /**
     * Fehlendes Recht und fehlender Datensatz geben dieselbe Antwort. Ein 403
     * verriete, dass es den Anhang gibt.
     */
    private function notFound(Response $response): Response
    {
        $response->getBody()->write('Datei nicht gefunden oder kein Zugriff.');

        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
```

- [ ] **Step 4: Register the routes**

In `src/Routes.php`, im Kopf zu den übrigen Controller-Imports:

```php
use App\Controllers\AttachmentController;
```

Und im geschützten Hauptblock direkt oberhalb des Kommentars `// Download section for project members` (aktuell Zeile 187):

```php
            // Anhaenge zentral: Vorschau und Download fuer jeden entity_type.
            // Ohne RoleMiddleware, weil das noetige Recht am Anhang haengt und
            // nicht am Pfad - AttachmentAccessRegistry entscheidet.
            $group->get('/attachments/{id:[0-9]+}/preview', [AttachmentController::class, 'preview']);
            $group->get('/attachments/{id:[0-9]+}/download', [AttachmentController::class, 'download']);
```

- [ ] **Step 5: Register the registry in the container**

In `src/Dependencies.php` bei den übrigen `use`-Zeilen ergänzen:

```php
use App\Services\AttachmentAccessRegistry;
use App\Services\AttachmentResponseFactory;
```

Und bei den Autowire-Einträgen (neben `SponsoringPolicy::class => \DI\autowire(),`, aktuell Zeile 446):

```php
        AttachmentResponseFactory::class => \DI\autowire(),
        // Die Registry braucht das Modul-Array aus den Settings; PHP-DI kann
        // einen einfachen Array-Parameter nicht selbst aufloesen.
        AttachmentAccessRegistry::class => function (ContainerInterface $c): AttachmentAccessRegistry {
            $modules = $c->get('settings')['modules'] ?? [];

            return new AttachmentAccessRegistry(
                $c->get(SponsoringPolicy::class),
                $c->get(TaskPolicy::class),
                is_array($modules) ? $modules : []
            );
        },
```

- [ ] **Step 6: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentAccessFeatureTest.php`
Expected: PASS, 9 Tests

- [ ] **Step 7: Verify the routes resolve in the running app**

Run: `ddev exec php -r "require 'vendor/autoload.php'; echo 'ok';"` als Rauchprobe, dann im Browser oder per curl:

Run: `ddev exec curl -s -o /dev/null -w "%{http_code}" http://localhost/attachments/1/download`
Expected: `302` (Weiterleitung zur Anmeldung durch AuthMiddleware) — **nicht** `404` aus dem Router und **nicht** `500`.

- [ ] **Step 8: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\src\Controllers\AttachmentController.php",
  "d:\Proggen\ChorManager\src\Routes.php",
  "d:\Proggen\ChorManager\src\Dependencies.php",
  "d:\Proggen\ChorManager\tests\Feature\AttachmentAccessFeatureTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer phpcs`
Expected: keine Fehler

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/AttachmentController.php src/Routes.php src/Dependencies.php tests/Feature/AttachmentAccessFeatureTest.php
git commit -m "feat(attachments): zentrale Routen für Vorschau und Download"
```

---

## Task 5: CSP-Ausnahme für die Vorschau-Route

**Files:**
- Modify: `src/Middleware/SecurityHeadersMiddleware.php:56-66`
- Test: `tests/Feature/AttachmentPreviewCspFeatureTest.php`

**Interfaces:**
- Consumes: die Route aus Task 4.
- Produces: nichts für spätere Tasks.

- [ ] **Step 1: Write the failing test**

Datei `tests/Feature/AttachmentPreviewCspFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Das PDF im Vorschau-Modal steckt in einem iframe auf die eigene
 * Vorschau-Route. Ohne Ausnahme vom Framing-Verbot bliebe der Rahmen leer.
 *
 * Die Ausnahme ist eng gefasst: nur diese eine Route, und ausdruecklich nicht
 * die Download-Route daneben. Ein zu weites Muster waere ein Clickjacking-Loch
 * fuer alles, was unter /attachments liegt.
 */
final class AttachmentPreviewCspFeatureTest extends TestCase
{
    private function headersFor(string $path): ResponseInterface
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);

        return $middleware->process($request, new class () implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return new Response();
            }
        });
    }

    public function testPreviewRouteAllowsSameOriginFraming(): void
    {
        $response = $this->headersFor('/attachments/42/preview');

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    public function testDownloadRouteStaysUnframeable(): void
    {
        $response = $this->headersFor('/attachments/42/download');

        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    public function testNeighbouringPathsStayUnframeable(): void
    {
        foreach (['/attachments/42/preview/extra', '/attachments/preview', '/dashboard'] as $path) {
            $response = $this->headersFor($path);

            $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), $path);
            $this->assertStringContainsString(
                "frame-ancestors 'none'",
                $response->getHeaderLine('Content-Security-Policy'),
                $path
            );
        }
    }

    public function testNewsletterPreviewFrameKeepsItsException(): void
    {
        $response = $this->headersFor('/newsletters/7/preview-frame');

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentPreviewCspFeatureTest.php`
Expected: FAIL bei `testPreviewRouteAllowsSameOriginFraming` — `Failed asserting that 'DENY' is identical to 'SAMEORIGIN'`

- [ ] **Step 3: Widen the exception**

In `src/Middleware/SecurityHeadersMiddleware.php` den Kommentarblock und die Methode `allowsSelfFraming()` ersetzen:

```php
    /**
     * Die beiden einzigen Ausnahmen vom vollständigen Framing-Verbot:
     *
     *  - die Route, die das fertige Mail-HTML eines gespeicherten Newsletters ausliefert. Sie
     *    dient als Quelle des eingebetteten Rahmens auf templates/newsletters/preview.twig.
     *  - die Anhang-Vorschau. Ein PDF lässt sich nur in einem Rahmen anzeigen; `object-src
     *    'none'` schließt `<embed>` und `<object>` aus, und ein neuer Tab wäre die Rückkehr
     *    zu genau der uneinheitlichen Bedienung, die das gemeinsame Modal ablöst.
     *
     * Vertretbar ist die zweite Ausnahme, weil die Route ausschließlich Dateiinhalte
     * ausliefert, kein HTML-Typ auf der Inline-Liste in App\Util\AttachmentPreview steht und
     * `X-Content-Type-Options: nosniff` gesetzt bleibt. Die Download-Route daneben behält
     * `'none'` - sie muss nie eingebettet werden.
     *
     * Jede andere Route bleibt uneingebettet - das ist der wirksamste Schutz gegen
     * Clickjacking.
     */
    private function allowsSelfFraming(Request $request): bool
    {
        $path = $request->getUri()->getPath();

        if (preg_match('#^/newsletters/\d+/preview-frame$#', $path)) {
            return true;
        }

        return (bool) preg_match('#^/attachments/\d+/preview$#', $path);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentPreviewCspFeatureTest.php tests/Feature/SecurityHeadersMiddlewareFeatureTest.php`
Expected: PASS, alle Tests beider Dateien

- [ ] **Step 5: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\src\Middleware\SecurityHeadersMiddleware.php",
  "d:\Proggen\ChorManager\tests\Feature\AttachmentPreviewCspFeatureTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer phpcs`
Expected: keine Fehler

- [ ] **Step 6: Commit**

```bash
git add src/Middleware/SecurityHeadersMiddleware.php tests/Feature/AttachmentPreviewCspFeatureTest.php
git commit -m "feat(security): Anhang-Vorschau darf in den eigenen Rahmen"
```

---

## Task 6: Twig-Funktion, Baustein und Modal

**Files:**
- Create: `templates/partials/attachment_actions.twig`
- Create: `templates/partials/attachment_preview_modal.twig`
- Create: `public/js/attachment-preview.js`
- Modify: `src/Dependencies.php` (Twig-Funktion `attachment_previewable`)
- Modify: `templates/layout.twig:77-91` (Modal und Skript einbinden)
- Modify: `public/css/style.css` (Modal-Klassen anhängen)
- Test: `tests/Feature/AttachmentPreviewPartialFeatureTest.php`

**Interfaces:**
- Consumes: `AttachmentPreview::isModalPreviewable()` (Task 1), Route `/attachments/{id}/preview` und `/download` (Task 4).
- Produces:
  - Twig-Funktion `attachment_previewable(mime)` → bool
  - Baustein `partials/attachment_actions.twig` mit Parametern `attachment_id`, `attachment_name`, `attachment_mime`, `attachment_size`, optional `show_label`
  - Modal-Element mit `id="attachmentPreviewModal"`

- [ ] **Step 1: Write the failing test**

Datei `tests/Feature/AttachmentPreviewPartialFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Baustein und das Modal existieren genau einmal. Der Test haelt fest, was
 * sonst still auseinanderlaeuft: dass das Modal global eingebunden ist und
 * nicht in sechs Templates einzeln, und dass der Baustein die Datenattribute
 * traegt, aus denen das Skript liest.
 */
final class AttachmentPreviewPartialFeatureTest extends TestCase
{
    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);
        $this->assertIsString($content, $relativePath . ' fehlt');

        return $content;
    }

    public function testActionsPartialCarriesDataAttributes(): void
    {
        $partial = $this->read('templates/partials/attachment_actions.twig');

        $this->assertStringContainsString('data-attachment-id="{{ attachment_id }}"', $partial);
        $this->assertStringContainsString('data-attachment-name="{{ attachment_name }}"', $partial);
        $this->assertStringContainsString('data-attachment-mime="{{ attachment_mime }}"', $partial);
        $this->assertStringContainsString('data-attachment-size="{{ attachment_size }}"', $partial);
        $this->assertStringContainsString('/attachments/{{ attachment_id }}/download', $partial);
        $this->assertStringContainsString('attachment_previewable(attachment_mime)', $partial);
    }

    public function testModalIsIncludedOnceGloballyInLayout(): void
    {
        $layout = $this->read('templates/layout.twig');

        $this->assertStringContainsString('partials/attachment_preview_modal.twig', $layout);
        $this->assertStringContainsString('/js/attachment-preview.js', $layout);
        $this->assertSame(1, substr_count($layout, 'partials/attachment_preview_modal.twig'));
    }

    public function testModalMarkupHasStableHooks(): void
    {
        $modal = $this->read('templates/partials/attachment_preview_modal.twig');

        $this->assertStringContainsString('id="attachmentPreviewModal"', $modal);
        $this->assertStringContainsString('id="attachmentPreviewBody"', $modal);
        $this->assertStringContainsString('id="attachmentPreviewTitle"', $modal);
        $this->assertStringContainsString('id="attachmentPreviewMeta"', $modal);
        $this->assertStringContainsString('id="attachmentPreviewDownload"', $modal);
    }

    public function testScriptExistsAndAvoidsInlineHandlers(): void
    {
        $script = $this->read('public/js/attachment-preview.js');

        $this->assertStringContainsString('attachmentPreviewModal', $script);
        $this->assertStringContainsString('data-attachment-id', $script);

        $modal = $this->read('templates/partials/attachment_preview_modal.twig');
        $this->assertStringNotContainsString('onclick', $modal);
        $this->assertStringNotContainsString('<script', $modal);
        $this->assertStringNotContainsString('style="', $modal);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentPreviewPartialFeatureTest.php`
Expected: FAIL — `templates/partials/attachment_actions.twig fehlt`

- [ ] **Step 3: Register the Twig function**

In `src/Dependencies.php` bei den `use`-Zeilen:

```php
use App\Util\AttachmentPreview;
```

Und im Twig-Factory-Block, direkt hinter der `navigation`-Funktion (aktuell Zeile 553):

```php
            // Ob ein Anhang einen Vorschau-Button bekommt, entscheidet dieselbe
            // Klasse wie im Controller. Zwei Listen - eine in PHP, eine in Twig -
            // waeren genau die Doppelung, die dieser Umbau beseitigt.
            $environment->addFunction(new TwigFunction(
                'attachment_previewable',
                static fn (?string $mimeType): bool => AttachmentPreview::isModalPreviewable((string) $mimeType)
            ));
```

- [ ] **Step 4: Write the actions partial**

Datei `templates/partials/attachment_actions.twig`:

```twig
{# Buttonpaar fuer einen Anhang: Vorschau (sofern darstellbar) und Download.

   Die Parameter sind einfache Werte statt eines Objekts, weil die Aufrufstellen
   unterschiedliche Formen liefern: fuenf reichen ein Eloquent-Modell mit
   original_name/file_size herein, die Sponsoring-Uebersicht ein Array mit
   name/size_bytes aus SponsoringAttachmentController::baseRow().

   Parameter:
     attachment_id    Zahl
     attachment_name  Anzeigename
     attachment_mime  MIME-Typ
     attachment_size  Groesse in Bytes
     show_label       Beschriftung neben dem Symbol zeigen (Vorgabe: false) #}
{% set _show_label = show_label|default(false) %}
{% set _previewable = attachment_previewable(attachment_mime) %}
<div class="btn-group btn-group-sm attachment-actions"
     role="group"
     aria-label="Aktionen für Anhang {{ attachment_name }}">
    {% if _previewable %}
        <button type="button"
                class="btn btn-outline-secondary"
                data-attachment-id="{{ attachment_id }}"
                data-attachment-name="{{ attachment_name }}"
                data-attachment-mime="{{ attachment_mime }}"
                data-attachment-size="{{ attachment_size }}"
                title="Vorschau">
            <i class="bi bi-eye" aria-hidden="true"></i>
            {% if _show_label %}
                <span class="d-none d-sm-inline">Vorschau</span>
            {% else %}
                <span class="visually-hidden">Vorschau</span>
            {% endif %}
        </button>
    {% endif %}
    <a class="btn btn-outline-primary"
       href="/attachments/{{ attachment_id }}/download"
       title="Herunterladen">
        <i class="bi bi-download" aria-hidden="true"></i>
        {% if _show_label %}
            <span class="d-none d-sm-inline">Download</span>
        {% else %}
            <span class="visually-hidden">Herunterladen</span>
        {% endif %}
    </a>
</div>
```

- [ ] **Step 5: Write the modal partial**

Datei `templates/partials/attachment_preview_modal.twig`:

```twig
{# Eine Modal-Huelle fuer alle Bereiche, global aus layout.twig eingebunden.
   Der Koerper wird von public/js/attachment-preview.js gefuellt und beim
   Schliessen geleert - sonst liefe ein Audio-Anhang weiter und das naechste
   Oeffnen zeigte kurz die vorige Datei. #}
<div class="modal fade"
     id="attachmentPreviewModal"
     tabindex="-1"
     aria-labelledby="attachmentPreviewTitle"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="me-auto text-truncate">
                    <h5 class="modal-title text-truncate" id="attachmentPreviewTitle">Anhang</h5>
                    <div class="text-muted small" id="attachmentPreviewMeta"></div>
                </div>
                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Schließen"></button>
            </div>
            <div class="modal-body attachment-preview-body" id="attachmentPreviewBody"></div>
            <div class="modal-footer">
                <a class="btn btn-primary" id="attachmentPreviewDownload" href="#">
                    <i class="bi bi-download" aria-hidden="true"></i> Herunterladen
                </a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 6: Write the JavaScript**

Datei `public/js/attachment-preview.js`:

```javascript
/**
 * Fuellt das gemeinsame Anhang-Vorschau-Modal.
 *
 * Delegiert auf dem Dokument statt auf einzelnen Buttons, damit auch Anhaenge
 * in einem Dropdown oder in einer nachgeladenen Tabellenzeile funktionieren.
 */
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('attachmentPreviewModal');
    if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) {
        return;
    }

    const body = document.getElementById('attachmentPreviewBody');
    const title = document.getElementById('attachmentPreviewTitle');
    const meta = document.getElementById('attachmentPreviewMeta');
    const downloadLink = document.getElementById('attachmentPreviewDownload');

    // Ab dieser Groesse wird eine Textvorschau abgeschnitten: der Modal-Koerper
    // ist kein Editor, und 10 MB Text wuerden den Browser blockieren.
    const TEXT_PREVIEW_LIMIT = 200 * 1024;

    function formatSize(bytes) {
        const value = Number(bytes);
        if (!Number.isFinite(value) || value <= 0) {
            return '';
        }
        if (value >= 1048576) {
            return (value / 1048576).toFixed(1).replace('.', ',') + ' MB';
        }
        if (value >= 1024) {
            return Math.round(value / 1024) + ' KB';
        }
        return value + ' B';
    }

    function clearBody() {
        while (body.firstChild) {
            body.removeChild(body.firstChild);
        }
    }

    function showMessage(text) {
        clearBody();
        const paragraph = document.createElement('p');
        paragraph.className = 'text-muted mb-0';
        paragraph.textContent = text;
        body.appendChild(paragraph);
    }

    function renderImage(url, name) {
        const image = document.createElement('img');
        image.src = url;
        image.alt = name;
        image.className = 'attachment-preview-image';
        image.addEventListener('error', function () {
            showMessage('Die Vorschau konnte nicht geladen werden. Bitte die Datei herunterladen.');
        });
        body.appendChild(image);
    }

    function renderPdf(url, name) {
        const frame = document.createElement('iframe');
        frame.src = url;
        frame.title = name;
        frame.className = 'attachment-preview-frame';
        body.appendChild(frame);
    }

    function renderAudio(url) {
        const audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'none';
        audio.className = 'w-100';
        audio.src = url;
        body.appendChild(audio);
    }

    function renderText(url) {
        showMessage('Vorschau wird geladen …');

        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Antwort ' + response.status);
                }
                return response.text();
            })
            .then(function (text) {
                clearBody();
                const block = document.createElement('pre');
                block.className = 'attachment-preview-text mb-0';
                block.textContent = text.length > TEXT_PREVIEW_LIMIT
                    ? text.slice(0, TEXT_PREVIEW_LIMIT)
                    : text;
                body.appendChild(block);

                if (text.length > TEXT_PREVIEW_LIMIT) {
                    const note = document.createElement('p');
                    note.className = 'text-muted small mt-2 mb-0';
                    note.textContent = 'Vorschau gekürzt. Die vollständige Datei steht im Download.';
                    body.appendChild(note);
                }
            })
            .catch(function () {
                showMessage('Die Vorschau konnte nicht geladen werden. Bitte die Datei herunterladen.');
            });
    }

    function render(mime, id, name) {
        const previewUrl = '/attachments/' + encodeURIComponent(id) + '/preview';
        clearBody();

        if (mime.indexOf('image/') === 0) {
            renderImage(previewUrl, name);
            return;
        }
        if (mime === 'application/pdf') {
            renderPdf(previewUrl, name);
            return;
        }
        if (mime === 'audio/mpeg') {
            renderAudio(previewUrl);
            return;
        }
        if (mime === 'text/plain') {
            renderText(previewUrl);
            return;
        }

        showMessage('Für diesen Dateityp gibt es keine Vorschau. Bitte die Datei herunterladen.');
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-attachment-id]');
        if (!trigger) {
            return;
        }

        event.preventDefault();

        const id = trigger.getAttribute('data-attachment-id');
        const name = trigger.getAttribute('data-attachment-name') || 'Anhang';
        const rawMime = trigger.getAttribute('data-attachment-mime') || '';
        const mime = rawMime.split(';')[0].trim().toLowerCase();
        const size = trigger.getAttribute('data-attachment-size');

        title.textContent = name;
        const sizeText = formatSize(size);
        meta.textContent = sizeText ? rawMime + ' · ' + sizeText : rawMime;
        downloadLink.setAttribute('href', '/attachments/' + encodeURIComponent(id) + '/download');

        render(mime, id, name);

        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        clearBody();
    });
});
```

- [ ] **Step 7: Wire modal and script into the layout**

In `templates/layout.twig` direkt hinter `</main>` (aktuell Zeile 76) einfügen:

```twig
        {{ include("partials/attachment_preview_modal.twig", [], false) }}
```

Und in der Skriptliste hinter `upload-helper.js` (aktuell Zeile 84):

```twig
        <script src="{{ asset_path('/js/attachment-preview.js') }}"></script>
```

- [ ] **Step 8: Add the styles**

An `public/css/style.css` anhängen:

```css
/* Anhang-Vorschau: das gemeinsame Modal fuer alle Bereiche. Feste Hoehe fuer
   PDF-Rahmen, weil ein iframe ohne Hoehenangabe auf 150px zusammenfaellt. */
.attachment-preview-body {
    min-height: 12rem;
}

.attachment-preview-image {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0 auto;
}

.attachment-preview-frame {
    width: 100%;
    height: 70vh;
    min-height: 20rem;
    border: 0;
}

.attachment-preview-text {
    max-height: 60vh;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

@media (max-width: 575.98px) {
    .attachment-preview-frame {
        height: 60vh;
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentPreviewPartialFeatureTest.php`
Expected: PASS, 4 Tests

- [ ] **Step 10: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\templates\partials\attachment_actions.twig",
  "d:\Proggen\ChorManager\templates\partials\attachment_preview_modal.twig",
  "d:\Proggen\ChorManager\templates\layout.twig",
  "d:\Proggen\ChorManager\public\js\attachment-preview.js",
  "d:\Proggen\ChorManager\public\css\style.css",
  "d:\Proggen\ChorManager\src\Dependencies.php",
  "d:\Proggen\ChorManager\tests\Feature\AttachmentPreviewPartialFeatureTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer twigcs`
Expected: keine Beanstandung. Bei Formatfehlern `ddev composer twigcbf`, danach erneut `ddev composer twigcs`.

Run: `ddev composer phpcs`
Expected: keine Fehler

- [ ] **Step 11: Commit**

```bash
git add templates/partials/attachment_actions.twig templates/partials/attachment_preview_modal.twig templates/layout.twig public/js/attachment-preview.js public/css/style.css src/Dependencies.php tests/Feature/AttachmentPreviewPartialFeatureTest.php
git commit -m "feat(attachments): gemeinsames Vorschau-Modal und Buttonpaar"
```

---

## Task 7: Die sechs Anzeigestellen umstellen

**Files:**
- Modify: `templates/partials/attachments.twig`
- Modify: `templates/finances/index.twig:215-240`
- Modify: `templates/sponsoring/attachments/index.twig:92-115`
- Modify: `templates/sponsoring/sponsors/detail.twig:237-260` und `:388-410`
- Modify: `templates/songs/detail.twig:202-224`
- Modify: `templates/songs/downloads.twig:99-140`
- Modify: `src/Controllers/SponsoringAttachmentController.php:119-150` (`download_url` entfernen)
- Test: `tests/Feature/AttachmentActionsUsageFeatureTest.php`

**Interfaces:**
- Consumes: `partials/attachment_actions.twig` aus Task 6.
- Produces: nichts für spätere Tasks.

- [ ] **Step 1: Write the failing test**

Datei `tests/Feature/AttachmentActionsUsageFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Regressionsschutz gegen den Zustand vor dem Umbau: sechs Templates, sechs
 * verschiedene Arten, einen Anhang anzubieten. Der Test haelt fest, dass alle
 * sechs denselben Baustein benutzen und keine der alten URLs mehr vorkommt.
 */
final class AttachmentActionsUsageFeatureTest extends TestCase
{
    /** @var list<string> */
    private const TEMPLATES = [
        'templates/partials/attachments.twig',
        'templates/finances/index.twig',
        'templates/sponsoring/attachments/index.twig',
        'templates/sponsoring/sponsors/detail.twig',
        'templates/songs/detail.twig',
        'templates/songs/downloads.twig',
    ];

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);
        $this->assertIsString($content, $relativePath . ' fehlt');

        return $content;
    }

    public function testEveryAttachmentTemplateUsesTheSharedPartial(): void
    {
        foreach (self::TEMPLATES as $template) {
            $this->assertStringContainsString(
                'partials/attachment_actions.twig',
                $this->read($template),
                $template
            );
        }
    }

    public function testNoTemplateStillUsesTheRemovedUrls(): void
    {
        $removed = [
            '/downloads/attachments/',
            '/finances/attachments/{{',
            '/tasks/" ~',
            'attachment.download_url',
        ];

        foreach (self::TEMPLATES as $template) {
            $content = $this->read($template);

            foreach ($removed as $needle) {
                $this->assertStringNotContainsString($needle, $content, $template . ' → ' . $needle);
            }
        }
    }

    public function testDownloadsPageKeepsItsEmbeddedPlayers(): void
    {
        $downloads = $this->read('templates/songs/downloads.twig');

        $this->assertStringContainsString('<audio controls', $downloads);
        $this->assertStringContainsString('<midi-player', $downloads);
        $this->assertStringContainsString('/attachments/{{ attachment.id }}/preview', $downloads);
    }

    public function testSponsoringOverviewNoLongerBuildsItsOwnDownloadUrl(): void
    {
        $controller = $this->read('src/Controllers/SponsoringAttachmentController.php');

        $this->assertStringNotContainsString("'download_url'", $controller);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentActionsUsageFeatureTest.php`
Expected: FAIL bei `testEveryAttachmentTemplateUsesTheSharedPartial`

- [ ] **Step 3: Aufgaben — `templates/partials/attachments.twig`**

Den `<li>`-Block ersetzen. Alt:

```twig
                    <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-text text-muted fs-4 me-3"></i>
                            <div>
                                <a href="{{ download_url }}/{{ attachment.id }}/download"
                                   class="text-decoration-none fw-medium text-dark">{{ attachment.original_name }}</a>
                                <div class="text-muted small">
                                    {{ attachment.file_size ? (attachment.file_size / 1024)|round(1) ~ ' KB' : '?' }} • {{ attachment.created_at|date('d.m.Y') }}
                                </div>
                            </div>
                        </div>
                        <div>
```

Neu:

```twig
                    <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-text text-muted fs-4 me-3"></i>
                            <div>
                                <span class="fw-medium text-dark">{{ attachment.original_name }}</span>
                                <div class="text-muted small">
                                    {{ attachment.file_size ? (attachment.file_size / 1024)|round(1) ~ " KB" : "?" }} • {{ attachment.created_at|date("d.m.Y") }}
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            {{ include("partials/attachment_actions.twig", {
                                attachment_id: attachment.id,
                                attachment_name: attachment.original_name,
                                attachment_mime: attachment.mime_type,
                                attachment_size: attachment.file_size,
                            }, false) }}
```

Der Parameter `download_url` wird im Baustein nicht mehr gebraucht. Er bleibt vorerst in der `include`-Liste von `templates/projects/task_detail.twig:141-146` unbenutzt stehen — in Schritt 4 wird er dort entfernt.

- [ ] **Step 4: Aufgaben — `templates/projects/task_detail.twig`**

Die `include`-Parameter kürzen. Alt (Zeile 141-146):

```twig
                    {{ include("partials/attachments.twig", {
                    attachments: task.attachments,
                    upload_url: "/tasks/" ~ task.id ~ "/attachments",
                    download_url: "/tasks/" ~ task.id ~ "/attachments",
                    delete_url: "/tasks/" ~ task.id ~ "/attachments",
```

Neu — die Zeile mit `download_url` ersatzlos streichen, `upload_url` und `delete_url` bleiben (sie zeigen weiterhin auf die unveränderten Upload- und Löschrouten):

```twig
                    {{ include("partials/attachments.twig", {
                    attachments: task.attachments,
                    upload_url: "/tasks/" ~ task.id ~ "/attachments",
                    delete_url: "/tasks/" ~ task.id ~ "/attachments",
```

- [ ] **Step 5: Finanzen — `templates/finances/index.twig`**

Den Dropdown-Inhalt ersetzen. Alt (Zeile 225-235):

```twig
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        {% for att in item.attachments %}
                                                            <li>
                                                                <a class="dropdown-item d-flex justify-content-between align-items-center"
                                                                   href="/finances/attachments/{{ att.id }}"
                                                                   target="_blank">
                                                                    <span>{{ att.filename }}</span>
                                                                    <i class="bi bi-box-arrow-up-right ms-2 small"></i>
                                                                </a>
                                                            </li>
                                                        {% endfor %}
                                                    </ul>
```

Neu:

```twig
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        {% for att in item.attachments %}
                                                            <li class="dropdown-item-text d-flex justify-content-between align-items-center gap-3">
                                                                <span class="text-truncate" title="{{ att.original_name }}">{{ att.original_name }}</span>
                                                                {{ include("partials/attachment_actions.twig", {
                                                                    attachment_id: att.id,
                                                                    attachment_name: att.original_name,
                                                                    attachment_mime: att.mime_type,
                                                                    attachment_size: att.file_size,
                                                                }, false) }}
                                                            </li>
                                                        {% endfor %}
                                                    </ul>
```

Damit `att.mime_type` und `att.file_size` gefüllt sind, muss die Abfrage in `FinanceController::index()` diese Spalten laden. Prüfen mit:

Run: `ddev exec grep -n "attachments" src/Controllers/FinanceController.php`

Lädt die Beziehung dort mit einer Spaltenliste (`with(['attachments' => …select(…)])`), müssen `mime_type` und `file_size` darin stehen; am einfachsten `EntityAttachmentService::METADATA_COLUMNS` verwenden. Lädt sie ohne Spaltenliste, ist nichts zu tun.

- [ ] **Step 6: Sponsoring-Übersicht — Template und Controller**

In `templates/sponsoring/attachments/index.twig` die Dateispalte entlinken (Zeile 92-97). Alt:

```twig
                                    <td data-label="Datei">
                                        <a href="{{ attachment.download_url }}" class="fw-semibold text-decoration-none">
                                            <i class="bi bi-file-earmark-arrow-down me-1"></i>{{ attachment.name }}
                                        </a>
                                        <div class="text-muted small">{{ attachment.mime_type }}</div>
                                    </td>
```

Neu:

```twig
                                    <td data-label="Datei">
                                        <span class="fw-semibold">
                                            <i class="bi bi-file-earmark-arrow-down me-1"></i>{{ attachment.name }}
                                        </span>
                                        <div class="text-muted small">{{ attachment.mime_type }}</div>
                                    </td>
```

Und die Aktionsspalte (Zeile 108-115). Alt:

```twig
                                    <td data-label="Aktionen" class="text-end text-nowrap">
                                        <a href="{{ attachment.download_url }}"
                                           class="btn btn-sm btn-outline-primary"
                                           title="Herunterladen">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    </td>
```

Neu:

```twig
                                    <td data-label="Aktionen" class="text-end text-nowrap">
                                        {{ include("partials/attachment_actions.twig", {
                                            attachment_id: attachment.id,
                                            attachment_name: attachment.name,
                                            attachment_mime: attachment.mime_type,
                                            attachment_size: attachment.size_bytes,
                                        }, false) }}
                                    </td>
```

In `src/Controllers/SponsoringAttachmentController.php` beide `'download_url' => …`-Einträge streichen — je einer in `mapSponsorshipAttachment()` und in `mapSponsorAttachment()`. Die verbleibenden Schlüssel (`context_label`, `sponsor_name`, `sponsor_url`, `reference`, `project_name`) bleiben unverändert.

- [ ] **Step 7: Sponsor-Detailseite — beide Listen**

In `templates/sponsoring/sponsors/detail.twig` die Sponsor-Anhangsliste (um Zeile 241) und die Vereinbarungs-Anhangsliste (um Zeile 394) umstellen. Beide Male den `<a href="…">`-Namenslink durch Text plus Baustein ersetzen. Für die Sponsor-Liste:

```twig
                                            <span class="text-truncate" title="{{ att.original_name }}">{{ att.original_name }}</span>
                                            {{ include("partials/attachment_actions.twig", {
                                                attachment_id: att.id,
                                                attachment_name: att.original_name,
                                                attachment_mime: att.mime_type,
                                                attachment_size: att.file_size,
                                            }, false) }}
```

Für die Vereinbarungs-Liste identisch — dieselben vier Parameter, dieselbe Schleifenvariable `att`. Die Lösch-Formulare darunter (`…/attachments/{{ att.id }}/delete`) bleiben unverändert, ihre Routen wurden nicht angefasst.

- [ ] **Step 8: Lied-Detailseite**

In `templates/songs/detail.twig` den Download-Link (Zeile 210-214) ersetzen. Alt:

```twig
                                        <a href="/downloads/attachments/{{ attachment.id }}/download"
                                           class="btn btn-outline-secondary btn-sm"
                                           title="Herunterladen">
                                            <i class="bi bi-download"></i>
                                        </a>
```

Neu:

```twig
                                        {{ include("partials/attachment_actions.twig", {
                                            attachment_id: attachment.id,
                                            attachment_name: attachment.original_name,
                                            attachment_mime: attachment.mime_type,
                                            attachment_size: attachment.file_size,
                                        }, false) }}
```

- [ ] **Step 9: Downloads-Seite**

In `templates/songs/downloads.twig` zwei Stellen. Erstens die beiden Player-Quellen (Zeile 113 und 119) von `/downloads/attachments/{{ attachment.id }}/stream` auf `/attachments/{{ attachment.id }}/preview` umstellen:

```twig
                                                                            <audio controls preload="none" class="w-100">
                                                                                <source src="/attachments/{{ attachment.id }}/preview"
                                                                                        type="audio/mpeg">
                                                                                Dein Browser unterstützt kein Audio-Playback.
                                                                            </audio>
```

```twig
                                                                                <midi-player src="/attachments/{{ attachment.id }}/preview" visualizer="#none" class="w-100"></midi-player>
```

Zweitens die Aktionsspalte (Zeile 125-136). Alt:

```twig
                                                                    <td data-label="Aktionen" class="text-end">
                                                                        <div class="btn-group"
                                                                             role="group"
                                                                             aria-label="Aktionen für Anhang {{ attachment.filename }}">
                                                                            <a class="btn btn-sm btn-outline-primary"
                                                                               href="/downloads/attachments/{{ attachment.id }}/download">
                                                                                <i class="bi bi-download"></i>
                                                                                <span class="d-none d-sm-inline">Download</span>
                                                                            </a>
                                                                        </div>
                                                                    </td>
```

Neu:

```twig
                                                                    <td data-label="Aktionen" class="text-end">
                                                                        {{ include("partials/attachment_actions.twig", {
                                                                            attachment_id: attachment.id,
                                                                            attachment_name: attachment.original_name,
                                                                            attachment_mime: attachment.mime_type,
                                                                            attachment_size: attachment.file_size,
                                                                            show_label: true,
                                                                        }, false) }}
                                                                    </td>
```

Die Spaltenüberschriften „Groesse" (Zeile 93) und der Text „Keine Anhaenge zu diesem Lied vorhanden." (Zeile 139) sowie „Dein Browser unterstuetzt kein Audio-Playback." tragen Transliterationen. Da die Zeilen ohnehin angefasst werden, im selben Schritt auf echte Umlaute korrigieren: „Größe", „Keine Anhänge zu diesem Lied vorhanden.", „Dein Browser unterstützt kein Audio-Playback.", „MIDI-Playback im Browser nicht verfügbar. Bitte Datei herunterladen." Ebenso `data-label="Groesse"` → `data-label="Größe"`.

- [ ] **Step 10: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/AttachmentActionsUsageFeatureTest.php`
Expected: PASS, 4 Tests

- [ ] **Step 11: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\templates\partials\attachments.twig",
  "d:\Proggen\ChorManager\templates\projects\task_detail.twig",
  "d:\Proggen\ChorManager\templates\finances\index.twig",
  "d:\Proggen\ChorManager\templates\sponsoring\attachments\index.twig",
  "d:\Proggen\ChorManager\templates\sponsoring\sponsors\detail.twig",
  "d:\Proggen\ChorManager\templates\songs\detail.twig",
  "d:\Proggen\ChorManager\templates\songs\downloads.twig",
  "d:\Proggen\ChorManager\src\Controllers\SponsoringAttachmentController.php",
  "d:\Proggen\ChorManager\tests\Feature\AttachmentActionsUsageFeatureTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer twigcs`
Expected: keine Beanstandung. Bei Formatfehlern `ddev composer twigcbf`, danach erneut prüfen.

Run: `ddev composer phpcs`
Expected: keine Fehler

- [ ] **Step 12: Commit**

```bash
git add templates/ src/Controllers/SponsoringAttachmentController.php tests/Feature/AttachmentActionsUsageFeatureTest.php
git commit -m "feat(attachments): alle sechs Anzeigestellen auf den gemeinsamen Baustein"
```

---

## Task 8: Alte Routen und tote Methoden entfernen

**Files:**
- Modify: `src/Routes.php` (fünf Routenblöcke)
- Modify: `src/Controllers/DownloadController.php`
- Modify: `src/Controllers/FinanceController.php`
- Modify: `src/Controllers/TaskController.php`
- Modify: `src/Controllers/SponsorController.php`
- Modify: `src/Controllers/SponsorshipController.php`
- Modify: `src/Services/EntityAttachmentService.php`
- Modify: `tests/Feature/DownloadFeatureTest.php`

**Interfaces:**
- Consumes: die zentralen Routen aus Task 4, die umgestellten Templates aus Task 7.
- Produces: nichts.

Dieser Task kommt bewusst **nach** Task 7. Erst wenn keine Oberfläche mehr auf die alten URLs zeigt, dürfen sie weg — andersherum wäre der Zwischenstand kaputt.

- [ ] **Step 1: Adjust the existing test first**

`tests/Feature/DownloadFeatureTest.php` prüft heute die Existenz der entfernten Methoden und Routen. Die Erwartungen umdrehen — alt (Zeile 16-27):

```php
        $this->assertTrue(class_exists(DownloadController::class));
        $this->assertTrue(class_exists(Attachment::class));
        $this->assertTrue(method_exists(DownloadController::class, 'index'));
        $this->assertTrue(method_exists(DownloadController::class, 'downloadAttachment'));
        $this->assertTrue(method_exists(DownloadController::class, 'streamAttachment'));
        $this->assertTrue(method_exists(Attachment::class, 'song'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/downloads'", $routesContent);
        $this->assertStringContainsString("'/downloads/attachments/{attachment_id:[0-9]+}/download'", $routesContent);
        $this->assertStringContainsString("'/downloads/attachments/{attachment_id:[0-9]+}/stream'", $routesContent);
```

Neu:

```php
        $this->assertTrue(class_exists(DownloadController::class));
        $this->assertTrue(class_exists(Attachment::class));
        $this->assertTrue(method_exists(DownloadController::class, 'index'));
        $this->assertTrue(method_exists(Attachment::class, 'song'));

        // Ausliefern kann nur noch AttachmentController. Bleiben diese Methoden
        // stehen, gibt es wieder zwei Wege an dieselbe Datei - mit zwei
        // Rechtepruefungen, die auseinanderlaufen koennen.
        $this->assertFalse(method_exists(DownloadController::class, 'downloadAttachment'));
        $this->assertFalse(method_exists(DownloadController::class, 'streamAttachment'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/downloads'", $routesContent);
        $this->assertStringNotContainsString('/downloads/attachments/', $routesContent);
```

Ebenso die beiden Range-Parser-Tests `testParseRangeHeaderAcceptsValidRange()` und `testParseRangeHeaderRejectsInvalidRanges()` aus dieser Datei **löschen** — sie leben jetzt in `tests/Unit/Services/AttachmentResponseFactoryTest.php` (Task 2). Der Import `use App\Util\DownloadFileName;` und `testNormalizeFileNameStripsUnsafeCharacters()` bleiben.

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/DownloadFeatureTest.php`
Expected: FAIL — `Failed asserting that true is false` (die Methoden gibt es noch)

- [ ] **Step 3: Remove the old routes**

In `src/Routes.php` streichen:

1. Zeile 189-193 (Downloads):
```php
            $group->get(
                '/downloads/attachments/{attachment_id:[0-9]+}/download',
                [DownloadController::class, 'downloadAttachment']
            );
            $group->get('/downloads/attachments/{attachment_id:[0-9]+}/stream', [DownloadController::class, 'streamAttachment']);
```
`$group->get('/downloads', …)` bleibt.

2. Zeile 296-299 (Finanzen, im Block `requiresFinanceRead`):
```php
                        $financeReadGroup->get(
                            '/finances/attachments/{id:[0-9]+}',
                            [FinanceController::class, 'viewAttachment']
                        );
```
Die Löschroute `'/finances/attachments/{id:[0-9]+}/delete'` bleibt.

3. Zeile 381-384 (Aufgaben):
```php
                        $taskGroup->get(
                            '/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}/download',
                            [TaskController::class, 'downloadAttachment']
                        );
```

4. Zeile 416-419 (Sponsor):
```php
                        $sponsoringGroup->get(
                            '/sponsors/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}',
                            [SponsorController::class, 'downloadAttachment']
                        );
```

5. Zeile 435-438 (Vereinbarung):
```php
                        $sponsoringGroup->get(
                            '/sponsorships/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}',
                            [SponsorshipController::class, 'downloadAttachment']
                        );
```

Die exakte Einrückung und der Name der Gruppenvariablen stehen in der Datei — beim Streichen unverändert lassen, was drumherum steht.

- [ ] **Step 4: Remove the dead controller methods**

- `src/Controllers/DownloadController.php`: `downloadAttachment()`, `streamAttachment()`, `parseRangeHeader()`, die Eigenschaft `$streamableMimeTypes` und `findMemberAttachment()` löschen. Danach die Importe `use App\Util\DownloadFileName;` und `use App\Models\Attachment;` prüfen — werden sie nicht mehr gebraucht, ebenfalls streichen. `index()` bleibt vollständig erhalten.
- `src/Controllers/FinanceController.php`: `viewAttachment()` und `isInlineViewableMimeType()` löschen. `deleteAttachment()` bleibt.
- `src/Controllers/TaskController.php`: `downloadAttachment()` löschen. `uploadAttachment()` und `deleteAttachment()` bleiben.
- `src/Controllers/SponsorController.php`: `downloadAttachment()` löschen. `uploadAttachment()` und `deleteAttachment()` bleiben.
- `src/Controllers/SponsorshipController.php`: `downloadAttachment()` löschen. `deleteAttachment()` bleibt.
- `src/Services/EntityAttachmentService.php`: `buildDownloadResponse()` löschen, dazu die dann unbenutzten Importe `use App\Util\DownloadFileName;` und `use Psr\Http\Message\ResponseInterface as Response;`. `METADATA_COLUMNS`, `storeUploads()`, `findWithContent()`, `deleteForEntity()` und `deleteAllForEntities()` bleiben.

Nach jeder Löschung prüfen, ob eine Nutzung übrig ist:

Run: `ddev exec grep -rn "buildDownloadResponse\|viewAttachment\|streamAttachment\|isInlineViewableMimeType" src/ templates/ tests/`
Expected: keine Treffer außer in `tests/Feature/DownloadFeatureTest.php` (dort als `assertFalse`)

- [ ] **Step 5: Run the whole suite**

Run: `ddev exec php vendor/bin/phpunit`
Expected: PASS. Schlagen andere Tests fehl, weil sie eine entfernte Methode oder Route erwarten, deren Erwartung entsprechend anpassen — nicht die Methode zurückholen.

Insbesondere prüfen:

Run: `ddev exec grep -rln "downloads/attachments\|finances/attachments/{id}\|attachments/{attachment_id}" tests/ templates/`
Expected: keine Treffer

- [ ] **Step 6: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\src\Routes.php",
  "d:\Proggen\ChorManager\src\Controllers\DownloadController.php",
  "d:\Proggen\ChorManager\src\Controllers\FinanceController.php",
  "d:\Proggen\ChorManager\src\Controllers\TaskController.php",
  "d:\Proggen\ChorManager\src\Controllers\SponsorController.php",
  "d:\Proggen\ChorManager\src\Controllers\SponsorshipController.php",
  "d:\Proggen\ChorManager\src\Services\EntityAttachmentService.php",
  "d:\Proggen\ChorManager\tests\Feature\DownloadFeatureTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer phpcs`
Expected: keine Fehler

- [ ] **Step 7: Commit**

```bash
git add src/ tests/Feature/DownloadFeatureTest.php
git commit -m "refactor(attachments): fünf alte Auslieferungswege entfernt"
```

---

## Task 9: Seed-Daten mit echten Dateiinhalten

**Files:**
- Create: `src/Services/DevSeedAttachmentFixtures.php`
- Modify: `src/Services/DevSeedService.php` (`seedSongAttachments()` ~Zeile 2894, `seedTaskAttachments()` ~Zeile 3233, `seedFinances()` ~Zeile 1556 und 1573, `seedSponsorAttachments()` ~Zeile 2658, `seedSponsorLogoAttachments()` ~Zeile 2725)
- Test: `tests/Unit/Services/DevSeedAttachmentFixturesTest.php`

**Interfaces:**
- Consumes: `AttachmentPreview::isModalPreviewable()` aus Task 1 (nur im Test).
- Produces: vier statische Methoden, die je ein `array{mime_type: string, content: string}` zurückgeben:
  - `DevSeedAttachmentFixtures::pdf(string $caption): array{mime_type: string, content: string}`
  - `DevSeedAttachmentFixtures::png(): array{mime_type: string, content: string}`
  - `DevSeedAttachmentFixtures::text(string $body): array{mime_type: string, content: string}`
  - `DevSeedAttachmentFixtures::wordDocument(string $caption): array{mime_type: string, content: string}`

- [ ] **Step 1: Write the failing test**

Datei `tests/Unit/Services/DevSeedAttachmentFixturesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DevSeedAttachmentFixtures;
use App\Util\AttachmentPreview;
use PHPUnit\Framework\TestCase;

/**
 * Die Seed-Anhaenge trugen bisher PDF-MIME und enthielten reinen Text. Im
 * Vorschau-Modal blieb der Rahmen damit leer - genau der Fall, den man beim
 * Entwickeln sehen will. Diese Fixtures liefern Inhalte, die zu ihrem Typ
 * passen.
 */
final class DevSeedAttachmentFixturesTest extends TestCase
{
    public function testPdfStartsWithPdfMagicNumberAndEndsWithEof(): void
    {
        $fixture = DevSeedAttachmentFixtures::pdf('Testbeleg');

        $this->assertSame('application/pdf', $fixture['mime_type']);
        $this->assertStringStartsWith('%PDF-1.', $fixture['content']);
        $this->assertStringContainsString('%%EOF', $fixture['content']);
        $this->assertStringContainsString('Testbeleg', $fixture['content']);
    }

    public function testPngStartsWithPngSignature(): void
    {
        $fixture = DevSeedAttachmentFixtures::png();

        $this->assertSame('image/png', $fixture['mime_type']);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($fixture['content'], 0, 8));
        $this->assertGreaterThan(20, strlen($fixture['content']));
    }

    public function testTextCarriesItsBody(): void
    {
        $fixture = DevSeedAttachmentFixtures::text('Zeile mit Umlauten: ärgerlich, größer, über.');

        $this->assertSame('text/plain', $fixture['mime_type']);
        $this->assertStringContainsString('größer', $fixture['content']);
    }

    public function testWordDocumentIsNotPreviewable(): void
    {
        $fixture = DevSeedAttachmentFixtures::wordDocument('Konzept');

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $fixture['mime_type']
        );
        $this->assertFalse(AttachmentPreview::isModalPreviewable($fixture['mime_type']));
        $this->assertNotSame('', $fixture['content']);
    }

    public function testPreviewableFixturesAreActuallyPreviewable(): void
    {
        $previewable = [
            DevSeedAttachmentFixtures::pdf('x'),
            DevSeedAttachmentFixtures::png(),
            DevSeedAttachmentFixtures::text('x'),
        ];

        foreach ($previewable as $fixture) {
            $this->assertTrue(AttachmentPreview::isModalPreviewable($fixture['mime_type']), $fixture['mime_type']);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Services/DevSeedAttachmentFixturesTest.php`
Expected: FAIL — `Class "App\Services\DevSeedAttachmentFixtures" not found`

- [ ] **Step 3: Write the fixtures class**

Datei `src/Services/DevSeedAttachmentFixtures.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Dateiinhalte fuer die Seed-Anhaenge.
 *
 * Vorher trugen alle Seed-Anhaenge entweder `text/plain` oder ein PDF-MIME mit
 * reinem Text dahinter. Im Vorschau-Modal blieb der PDF-Rahmen damit leer, und
 * ein Bild kam in den Testdaten ueberhaupt nicht vor - man konnte die Vorschau
 * in Dev also nicht sehen, obwohl sie funktionierte.
 *
 * Die Inhalte sind bewusst winzig: sie sollen zeigen, dass die Anzeige greift,
 * nicht die Datenbank fuellen.
 */
final class DevSeedAttachmentFixtures
{
    /**
     * Ein von Hand gebautes, minimal gueltiges PDF. Kein Generator noetig - die
     * Datei besteht aus vier Objekten und einer Querverweistabelle. Die
     * Byte-Abstaende der Tabelle stimmen nicht exakt; alle gaengigen Betrachter
     * bauen den Verweisbaum in diesem Fall selbst neu auf, und mehr braucht eine
     * Testdatei nicht.
     *
     * @return array{mime_type: string, content: string}
     */
    public static function pdf(string $caption): array
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $caption);
        $stream = "BT /F1 18 Tf 60 700 Td (" . $escaped . ") Tj ET";

        $content = "%PDF-1.4\n"
            . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            . "/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj\n"
            . "4 0 obj << /Length " . strlen($stream) . " >> stream\n"
            . $stream . "\nendstream endobj\n"
            . "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            . "trailer << /Root 1 0 R /Size 6 >>\n"
            . "%%EOF\n";

        return ['mime_type' => 'application/pdf', 'content' => $content];
    }

    /**
     * Ein 1x1 Pixel grosses PNG, base64-kodiert abgelegt. Kleiner geht ein
     * gueltiges PNG nicht.
     *
     * @return array{mime_type: string, content: string}
     */
    public static function png(): array
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
            . 'z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        return ['mime_type' => 'image/png', 'content' => (string) base64_decode($base64, true)];
    }

    /**
     * @return array{mime_type: string, content: string}
     */
    public static function text(string $body): array
    {
        return ['mime_type' => 'text/plain', 'content' => $body];
    }

    /**
     * Kein echtes Office-Dokument, sondern ein Platzhalter mit dem passenden
     * MIME-Typ. Er dient genau einem Zweck: zu zeigen, dass ein nicht
     * darstellbarer Anhang in der Oberflaeche keinen Vorschau-Button bekommt.
     *
     * @return array{mime_type: string, content: string}
     */
    public static function wordDocument(string $caption): array
    {
        return [
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'content'   => 'Platzhalter für ein Word-Dokument: ' . $caption
                . '. Dieser Anhang hat bewusst keine Vorschau.',
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Unit/Services/DevSeedAttachmentFixturesTest.php`
Expected: PASS, 5 Tests

- [ ] **Step 5: Use the fixtures in the seed**

In `src/Services/DevSeedService.php` bei den Importen ergänzen:

```php
use App\Services\DevSeedAttachmentFixtures;
```

(Liegt die Klasse im selben Namensraum `App\Services`, entfällt der Import — dann direkt `DevSeedAttachmentFixtures::pdf(...)` benutzen.)

Dann jede der fünf Seed-Stellen so umbauen, dass sie abwechselnd verschiedene Typen erzeugt. Muster für `seedSongAttachments()`:

```php
            $song = $songs[$attempt % count($songs)];
            $slot = (int) floor($attempt / count($songs)) + 1;

            // Vier Typen im Wechsel: PDF und Bild und Text sind im Modal
            // darstellbar, das Word-Dokument bewusst nicht - so ist in Dev
            // sofort beides zu sehen.
            $fixture = match ($slot % 4) {
                1 => DevSeedAttachmentFixtures::pdf('Notenblatt Song ' . $song->id),
                2 => DevSeedAttachmentFixtures::png(),
                3 => DevSeedAttachmentFixtures::text('Textblatt für Song ' . $song->id . '.'),
                default => DevSeedAttachmentFixtures::wordDocument('Probenplan Song ' . $song->id),
            };

            $extension = match ($fixture['mime_type']) {
                'application/pdf' => 'pdf',
                'image/png' => 'png',
                'text/plain' => 'txt',
                default => 'docx',
            };

            $originalName = sprintf('notenblatt-%d-%02d.%s', $song->id, $slot, $extension);

            $attachment = Attachment::firstOrCreate(
                [
                    'entity_type' => 'song',
                    'entity_id' => $song->id,
                    'original_name' => $originalName,
                ],
                [
                    'filename' => sprintf('seed-song-%d-%02d.%s', $song->id, $slot, $extension),
                    'mime_type' => $fixture['mime_type'],
                    'file_size' => strlen($fixture['content']),
                    'file_content' => $fixture['content'],
                ]
            );
```

Dasselbe Muster auf `seedTaskAttachments()` anwenden (Basisname `task-anhang-%d-%02d.%s`, Text „Anhang für Aufgabe X.").

Für `seedFinances()` beide Anhang-Blöcke: der erste Block bekommt abwechselnd `DevSeedAttachmentFixtures::pdf('Beleg ' . $finance->running_number)` und `DevSeedAttachmentFixtures::text('Testbeleg für Laufnummer ' . $finance->running_number)`, der zweite Block (`beleg-zusatz-…`) durchgehend `DevSeedAttachmentFixtures::wordDocument('Zusatzbeleg')`. Dateiendung jeweils passend setzen.

Für `seedSponsorAttachments()` und `seedSponsorLogoAttachments()` die `'file_content'`- und `'mime_type'`-Werte in den `$definitions`-Arrays durch Fixture-Aufrufe ersetzen. Konkret:

- `vertrag-musikhaus-weber.pdf` → `DevSeedAttachmentFixtures::pdf('Sponsoringvertrag Musikhaus Weber')`
- `foerderantrag-kulturstiftung.pdf` → `DevSeedAttachmentFixtures::pdf('Förderantrag Kulturstiftung am Fluss')`
- `dankesschreiben-barbara-singer.txt` → `DevSeedAttachmentFixtures::text('Vielen Dank für Ihre Unterstützung des Chorprojekts.')`
- `branding-briefing-taktvoll.pdf` → `DevSeedAttachmentFixtures::pdf('Branding-Briefing Eventagentur Taktvoll')`
- `angebot-sommergala.pdf` → `DevSeedAttachmentFixtures::wordDocument('Angebot Sommergala')`, Dateiname auf `angebot-sommergala.docx` ändern
- `logo-musikhaus-weber.txt` → `DevSeedAttachmentFixtures::png()`, Dateiname auf `logo-musikhaus-weber.png`
- `mediadaten-kulturstiftung.txt` → `DevSeedAttachmentFixtures::pdf('Mediadaten Kulturstiftung am Fluss')`, Dateiname auf `mediadaten-kulturstiftung.pdf`
- `logo-taktvoll.txt` → `DevSeedAttachmentFixtures::png()`, Dateiname auf `logo-taktvoll.png`

Der Dateiname `foerderantrag-…` trägt eine Transliteration. Da die Zeile ohnehin angefasst wird, auf `foerderantrag` belassen ist nicht zulässig — er ist ein Datenwert, kein Bezeichner, also `förderantrag-kulturstiftung.pdf`. Bleibt der Wert technisch problematisch, `naming:ascii` in dieselbe Zeile setzen.

Die Zähler in `run()` und `resetSeedData()` bleiben unverändert: es entstehen keine neuen Tabellen und keine zusätzlichen Anhänge, nur andere Inhalte.

- [ ] **Step 6: Run a real seed**

Run: `ddev composer seed:dev`
Expected: Lauf endet ohne Fehler; im Bericht sind `song_attachments`, `task_attachments`, `finance_attachments`, `sponsor_attachments` und `sponsor_logo_attachments` mit Zahlen > 0 belegt. Die Zahlen mit dem vorherigen Lauf vergleichen — sie sollen gleich bleiben.

- [ ] **Step 7: Verify the mix in the database**

Run: `ddev exec mysql -uroot -proot db -e "SELECT entity_type, mime_type, COUNT(*) FROM attachments GROUP BY entity_type, mime_type ORDER BY entity_type, mime_type;"`
Expected: Für jeden der fünf `entity_type` mindestens ein vorschaubarer Typ (`application/pdf`, `image/png` oder `text/plain`) **und** mindestens ein `…wordprocessingml.document`.

- [ ] **Step 8: Normalize line endings and lint**

```powershell
$files = @(
  "d:\Proggen\ChorManager\src\Services\DevSeedAttachmentFixtures.php",
  "d:\Proggen\ChorManager\src\Services\DevSeedService.php",
  "d:\Proggen\ChorManager\tests\Unit\Services\DevSeedAttachmentFixturesTest.php"
)
foreach ($f in $files) { [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

Run: `ddev composer phpcs`
Expected: keine Fehler

- [ ] **Step 9: Commit**

```bash
git add src/Services/DevSeedAttachmentFixtures.php src/Services/DevSeedService.php tests/Unit/Services/DevSeedAttachmentFixturesTest.php
git commit -m "feat(seed): echte Dateiinhalte für Anhänge in den Testdaten"
```

---

## Task 10: Gesamtlauf, Hilfetext und Abschluss

**Files:**
- Modify: `docs/help/*.md` (nur falls ein Hilfetext Anhänge beschreibt — in Schritt 2 ermitteln)
- Test: alle

**Interfaces:**
- Consumes: alles aus Task 1-9.
- Produces: nichts.

- [ ] **Step 1: Run the whole suite**

Run: `ddev exec php vendor/bin/phpunit`
Expected: PASS, keine Fehler und keine Warnungen

Run: `ddev composer eol:check`
Expected: `LF endings verified for tracked text files.`

- [ ] **Step 2: Check whether a help topic mentions attachments**

Run: `ddev exec grep -rln "Anhang\|Anhänge\|herunterladen" docs/`
Expected: Liste der betroffenen Hilfetexte

Beschreibt einer davon, wie ein Anhang geöffnet wird („Klick auf den Dateinamen lädt die Datei herunter" o. ä.), diesen Satz auf das neue Verhalten anpassen: Vorschau-Button öffnet ein Fenster mit der Datei, Download-Button lädt sie herunter, bei Office-Dateien gibt es nur den Download. Dabei nach `instructions/help-docs.md` **keine** Rollennamen nennen, sondern das Recht mit seinem Label aus `templates/roles/index.twig`. Findet sich kein solcher Satz, diesen Schritt ohne Änderung abhaken.

- [ ] **Step 3: Run both linters one final time**

Run: `ddev composer phpcs`
Expected: keine Fehler

Run: `ddev composer twigcs`
Expected: keine Beanstandung

- [ ] **Step 4: Manual smoke test in the browser**

Angemeldet mit einem Konto, das Finanz-, Aufgaben- und Sponsoring-Rechte hat, alle sechs Stellen aufrufen:

1. `/tasks` → eine Aufgabe mit Anhang öffnen
2. `/finances` → Paperclip-Knopf einer Buchung mit Anhang
3. `/sponsoring/attachments`
4. `/sponsoring/sponsors/{id}`
5. `/song-library/{id}`
6. `/downloads`

Je Stelle prüfen: PDF-Anhang öffnet das Modal und zeigt das Dokument im Rahmen; Bild-Anhang zeigt das Bild; Text-Anhang zeigt den Text; Word-Anhang hat **keinen** Vorschau-Button; der Download-Button lädt die Datei mit korrektem Namen; auf `/downloads` spielen Audio- und MIDI-Player weiter.

In der Browser-Konsole darf keine CSP-Meldung stehen.

- [ ] **Step 5: Commit any help text changes**

Nur falls Schritt 2 etwas geändert hat:

```bash
git add docs/
git commit -m "docs(help): Anhang-Vorschau im Hilfetext beschrieben"
```

- [ ] **Step 6: Report**

Bericht nach `instructions/change-reporting.md` erstellen: was geändert wurde, welche Befehle ausgeführt wurden (`phpunit`, `phpcs`, `twigcs`, `seed:dev`) und mit welchem Ergebnis. Kein `git push`.

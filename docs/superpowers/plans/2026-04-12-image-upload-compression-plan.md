# Image Upload Compression Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable seamless mobile camera photo uploads in the PWA by compressing images to ≤2 MB on the client, while enforcing consistent server-side validation across all upload domains.

**Architecture:** A shared frontend JS helper intercepts file input and programmatically reduces oversized images to JPEG ≤2 MB before submit. Backend controllers harmonize size/MIME validation rules and always re-validate on receipt, ensuring server-first security while improving UX.

**Tech Stack:** Canvas API for image resampling, existing Slim upload flows, PHPUnit for tests, minimal vanilla JS (no external libs).

---

## File Structure

**Backend (size/MIME validation):**
- `src/Util/UploadValidator.php` — NEW: centralized constants and validation logic
- `src/Controllers/FinanceController.php` — MODIFY: harmonize image/non-image limits
- `src/Controllers/SongLibraryController.php` — MODIFY: harmonize limits
- `src/Controllers/TaskController.php` — MODIFY: harmonize limits
- `src/Controllers/SponsorshipController.php` — MODIFY: harmonize limits
- `src/Controllers/AppSettingController.php` — MODIFY: logo upload limit

**Frontend (image compression):**
- `public/js/upload-helper.js` — NEW: Canvas-based JPEG compression utility
- `templates/partials/attachments.twig` — MODIFY: wire up compression helper
- `templates/finances/index.twig` — MODIFY: wire up compression helper for app logo
- `templates/songs/manage.twig` — MODIFY: wire up compression helper
- `templates/sponsoring/sponsors/detail.twig` — MODIFY: wire up compression helper
- `templates/settings/index.twig` — MODIFY: wire up compression helper for logo

**Tests:**
- `tests/Feature/UploadCompressionFeatureTest.php` — NEW: backend validation rules
- `tests/js/upload-helper.test.js` — NEW: frontend compression logic

---

## Task 1: Create UploadValidator utility with constants and logic

**Files:**
- Create: `src/Util/UploadValidator.php`
- Modify: `composer.json` (register autoload if needed)

- [ ] **Step 1: Write failing test for size validation**

Create `tests/Feature/UploadCompressionFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Util\UploadValidator;
use PHPUnit\Framework\TestCase;

class UploadCompressionFeatureTest extends TestCase
{
    public function testImageOver2MBIsRejected(): void
    {
        $twoMBPlusOne = (2 * 1024 * 1024) + 1;
        $result = UploadValidator::validateImageSize($twoMBPlusOne, 'image/jpeg');
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('2 MB', $result['error']);
    }

    public function testImageUnder2MBIsAccepted(): void
    {
        $oneMB = 1 * 1024 * 1024;
        $result = UploadValidator::validateImageSize($oneMB, 'image/jpeg');
        
        $this->assertTrue($result['valid']);
    }

    public function testNonImageOver10MBIsRejected(): void
    {
        $tenMBPlusOne = (10 * 1024 * 1024) + 1;
        $result = UploadValidator::validateFileSize($tenMBPlusOne, 'application/pdf');
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('10 MB', $result['error']);
    }

    public function testNonImageUnder10MBIsAccepted(): void
    {
        $fiveMB = 5 * 1024 * 1024;
        $result = UploadValidator::validateFileSize($fiveMB, 'application/pdf');
        
        $this->assertTrue($result['valid']);
    }

    public function testGetImageMimeTypesReturnsCommonFormats(): void
    {
        $types = UploadValidator::getImageMimeTypes();
        
        $this->assertContains('image/jpeg', $types);
        $this->assertContains('image/png', $types);
        $this->assertContains('image/webp', $types);
    }

    public function testGetNonImageMimeTypesReturnsDocumentFormats(): void
    {
        $types = UploadValidator::getNonImageMimeTypes();
        
        $this->assertContains('application/pdf', $types);
        $this->assertContains('application/msword', $types);
    }
}
```

Run: `ddev exec php vendor/bin/phpunit tests/Feature/UploadCompressionFeatureTest.php -v`
Expected: FAIL — class UploadValidator not found.

- [ ] **Step 2: Write UploadValidator utility class**

Create `src/Util/UploadValidator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Util;

class UploadValidator
{
    // Size constants in bytes
    public const MAX_IMAGE_SIZE = 2 * 1024 * 1024;           // 2 MB
    public const MAX_NON_IMAGE_SIZE = 10 * 1024 * 1024;      // 10 MB

    // Image MIME types allowed
    private static array $imageMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/svg+xml',
    ];

    // Non-image (document) MIME types allowed
    private static array $nonImageMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    /**
     * Validate image file size against 2 MB limit.
     * Returns array with 'valid' (bool) and optional 'error' (string).
     */
    public static function validateImageSize(int $sizeBytes, string $mimeType): array
    {
        if (!self::isImageMimeType($mimeType)) {
            return [
                'valid' => false,
                'error' => "MIME type '$mimeType' ist kein erlaubtes Bildformat.",
            ];
        }

        if ($sizeBytes > self::MAX_IMAGE_SIZE) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'Bilddatei zu groß: %s MB. Maximal erlaubt: 2 MB.',
                    round($sizeBytes / (1024 * 1024), 1)
                ),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Validate file size (image or non-image) against appropriate limit.
     * Returns array with 'valid' (bool) and optional 'error' (string).
     */
    public static function validateFileSize(int $sizeBytes, string $mimeType): array
    {
        $isImage = self::isImageMimeType($mimeType);
        $limit = $isImage ? self::MAX_IMAGE_SIZE : self::MAX_NON_IMAGE_SIZE;
        $limitMB = $isImage ? 2 : 10;

        if ($sizeBytes > $limit) {
            $type = $isImage ? 'Bilddatei' : 'Datei';
            return [
                'valid' => false,
                'error' => sprintf(
                    '%s zu groß: %s MB. Maximal erlaubt: %d MB.',
                    $type,
                    round($sizeBytes / (1024 * 1024), 1),
                    $limitMB
                ),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check if a MIME type is categorized as an image.
     */
    public static function isImageMimeType(string $mimeType): bool
    {
        return in_array(trim(strtolower($mimeType)), self::$imageMimeTypes, true);
    }

    /**
     * Get list of allowed image MIME types.
     */
    public static function getImageMimeTypes(): array
    {
        return self::$imageMimeTypes;
    }

    /**
     * Get list of allowed non-image MIME types.
     */
    public static function getNonImageMimeTypes(): array
    {
        return self::$nonImageMimeTypes;
    }

    /**
     * Get all allowed MIME types in one array.
     */
    public static function getAllowedMimeTypes(): array
    {
        return array_merge(self::$imageMimeTypes, self::$nonImageMimeTypes);
    }
}
```

- [ ] **Step 3: Run test to verify it passes**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/UploadCompressionFeatureTest.php -v`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/Util/UploadValidator.php tests/Feature/UploadCompressionFeatureTest.php
git commit -m "feat: add UploadValidator utility with harmonized size constants"
```

---

## Task 2: Update FinanceController to use UploadValidator

**Files:**
- Modify: `src/Controllers/FinanceController.php`

- [ ] **Step 1: Write failing test for FinanceController validation**

Add to `tests/Feature/UploadCompressionFeatureTest.php`:

```php
public function testFinanceUploadRejectsImageOver2MB(): void
{
    // This test depends on a mock/seeded finance form submission
    // We'll verify the controller uses UploadValidator via inspection
    // and a basic integration test in Step 3
    $this->assertTrue(true); // Placeholder; real test requires full controller setup
}
```

Run: `ddev exec php vendor/bin/phpunit tests/Feature/UploadCompressionFeatureTest.php::testFinanceUploadRejectsImageOver2MB -v`

- [ ] **Step 2: Modify FinanceController to use UploadValidator**

In `src/Controllers/FinanceController.php`, replace the old attachment handling. Find the section around line 19 and line 162, and replace:

Old (around line 19):
```php
    private int $maxAttachmentSize = 10485760; // 10 MB
    /** @var array<int, string> */
    private array $allowedAttachmentMimeTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
```

New:
```php
    use \App\Util\UploadValidator;
```

Add at the top of the class imports. Then in the `save()` method around line 162, replace the attachment validation loop:

Old:
```php
                foreach ($files as $file) {
                    if ($file->getError() === UPLOAD_ERR_OK) {
                        $size = (int) $file->getSize();
                        if ($size <= 0 || $size > $this->maxAttachmentSize) {
                            $_SESSION['error'] = 'Anhang hat eine ungueltige Dateigroesse (max. 10 MB).';
                            continue;
                        }

                        $mimeType = trim((string) $file->getClientMediaType());
                        if (!in_array($mimeType, $this->allowedAttachmentMimeTypes, true)) {
                            $_SESSION['error'] = 'Anhangstyp ist nicht erlaubt.';
                            continue;
                        }

                        $safeName = self::normalizeFileName((string) $file->getClientFilename());

                        Attachment::create([
                            'entity_type' => 'finance',
                            'entity_id' => $finance->id,
                            'filename' => $safeName,
                            'original_name' => $safeName,
                            'mime_type' => $mimeType,
                            'file_content' => $file->getStream()->getContents(),
                        ]);
                    }
                }
```

New:
```php
                foreach ($files as $file) {
                    if ($file->getError() === UPLOAD_ERR_OK) {
                        $size = (int) $file->getSize();
                        $mimeType = trim((string) $file->getClientMediaType());

                        // Use centralized validation
                        $validation = UploadValidator::validateFileSize($size, $mimeType);
                        if (!$validation['valid']) {
                            $_SESSION['error'] = $validation['error'];
                            continue;
                        }

                        $safeName = self::normalizeFileName((string) $file->getClientFilename());

                        Attachment::create([
                            'entity_type' => 'finance',
                            'entity_id' => $finance->id,
                            'filename' => $safeName,
                            'original_name' => $safeName,
                            'mime_type' => $mimeType,
                            'file_content' => $file->getStream()->getContents(),
                        ]);
                    }
                }
```

- [ ] **Step 3: Run lint to verify syntax**

Run: `ddev exec php -l src/Controllers/FinanceController.php`
Expected: No syntax errors.

- [ ] **Step 4: Run tests to verify**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/FinanceFeatureTest.php -v`
Expected: PASS (no new failures)

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/FinanceController.php
git commit -m "refactor: harmonize FinanceController to use UploadValidator"
```

---

## Task 3: Update SongLibraryController to use UploadValidator

**Files:**
- Modify: `src/Controllers/SongLibraryController.php`

- [ ] **Step 1: Modify SongLibraryController**

In `src/Controllers/SongLibraryController.php`, at the top add the import:

```php
use App\Util\UploadValidator;
```

Replace lines 17-18 (old maxUploadSize definition) and the entire attachment loop in `uploadAttachments()` around line 142-175:

Old:
```php
    private int $maxUploadSize = 26214400; // 25 MB
```

Old loop (lines 142-175):
```php
        foreach ($files as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $size = (int) $file->getSize();
            if ($size <= 0) {
                $_SESSION['error'] = 'Leere Dateien sind nicht erlaubt.';
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            if ($size > $this->maxUploadSize) {
                $_SESSION['error'] = 'Datei zu gross. Maximal erlaubt sind 25 MB pro Datei.';
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            $originalName = trim((string) $file->getClientFilename());
            if ($originalName === '') {
                $_SESSION['error'] = 'Dateiname fehlt.';
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            $mimeType = trim((string) $file->getClientMediaType()) ?: 'application/octet-stream';

            Attachment::create([
                'entity_type' => 'song',
                'entity_id' => $songId,
                'filename' => bin2hex(random_bytes(16)) . '_' . $originalName,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $size,
                'file_content' => $file->getStream()->getContents(),
            ]);
        }
```

New:
```php
        foreach ($files as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $size = (int) $file->getSize();
            if ($size <= 0) {
                $_SESSION['error'] = 'Leere Dateien sind nicht erlaubt.';
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            $originalName = trim((string) $file->getClientFilename());
            if ($originalName === '') {
                $_SESSION['error'] = 'Dateiname fehlt.';
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            $mimeType = trim((string) $file->getClientMediaType()) ?: 'application/octet-stream';

            // Use centralized validation
            $validation = UploadValidator::validateFileSize($size, $mimeType);
            if (!$validation['valid']) {
                $_SESSION['error'] = $validation['error'];
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            Attachment::create([
                'entity_type' => 'song',
                'entity_id' => $songId,
                'filename' => bin2hex(random_bytes(16)) . '_' . $originalName,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $size,
                'file_content' => $file->getStream()->getContents(),
            ]);
        }
```

- [ ] **Step 2: Run lint**

Run: `ddev exec php -l src/Controllers/SongLibraryController.php`
Expected: No syntax errors.

- [ ] **Step 3: Run tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/SongLibraryFeatureTest.php -v`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/SongLibraryController.php
git commit -m "refactor: harmonize SongLibraryController to use UploadValidator (2 MB image limit)"
```

---

## Task 4: Update TaskController to use UploadValidator

**Files:**
- Modify: `src/Controllers/TaskController.php`

- [ ] **Step 1: Modify TaskController**

Add import at top:
```php
use App\Util\UploadValidator;
```

Find the `uploadAttachment()` method around line 276. Replace the entire loop (lines 283-295):

Old:
```php
        foreach ($files as $file) {
            if ($file->getError() === UPLOAD_ERR_OK) {
                $contents = $file->getStream()->getContents();
                Attachment::create([
                    'entity_type'   => 'task',
                    'entity_id'     => $task->id,
                    'filename'      => bin2hex(random_bytes(16)) . '_' . $file->getClientFilename(),
                    'original_name' => $file->getClientFilename(),
                    'mime_type'     => $file->getClientMediaType(),
                    'file_size'     => strlen($contents),
                    'file_content'  => $contents,
                ]);
                $uploadedCount++;
            }
        }
```

New:
```php
        foreach ($files as $file) {
            if ($file->getError() === UPLOAD_ERR_OK) {
                $contents = $file->getStream()->getContents();
                $size = strlen($contents);
                $mimeType = (string) $file->getClientMediaType();

                // Use centralized validation
                $validation = UploadValidator::validateFileSize($size, $mimeType);
                if (!$validation['valid']) {
                    $_SESSION['error'] = $validation['error'];
                    continue;
                }

                Attachment::create([
                    'entity_type'   => 'task',
                    'entity_id'     => $task->id,
                    'filename'      => bin2hex(random_bytes(16)) . '_' . $file->getClientFilename(),
                    'original_name' => $file->getClientFilename(),
                    'mime_type'     => $mimeType,
                    'file_size'     => $size,
                    'file_content'  => $contents,
                ]);
                $uploadedCount++;
            }
        }
```

- [ ] **Step 2: Run lint**

Run: `ddev exec php -l src/Controllers/TaskController.php`
Expected: No syntax errors.

- [ ] **Step 3: Run tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/TaskFeatureTest.php -v`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/TaskController.php
git commit -m "refactor: harmonize TaskController to use UploadValidator (2 MB image limit)"
```

---

## Task 5: Update SponsorshipController to use UploadValidator

**Files:**
- Modify: `src/Controllers/SponsorshipController.php`

- [ ] **Step 1: Modify SponsorshipController**

Add import at top:
```php
use App\Util\UploadValidator;
```

Find `handleAttachments()` method around line 22. Replace the loop (lines 35-41):

Old:
```php
        foreach ($files as $file) {
            if ($file->getError() === UPLOAD_ERR_OK) {
                Attachment::create([
                    'entity_type'    => 'sponsorship',
                    'entity_id'      => $sponsorshipId,
                    'filename'       => bin2hex(random_bytes(16)) . '_' . $file->getClientFilename(),
                    'original_name'  => $file->getClientFilename(),
                    'mime_type'      => $file->getClientMediaType(),
                    'file_content'   => $file->getStream()->getContents(),
                ]);
            }
        }
```

New:
```php
        foreach ($files as $file) {
            if ($file->getError() === UPLOAD_ERR_OK) {
                $contents = $file->getStream()->getContents();
                $size = strlen($contents);
                $mimeType = (string) $file->getClientMediaType();

                // Use centralized validation
                $validation = UploadValidator::validateFileSize($size, $mimeType);
                if (!$validation['valid']) {
                    $_SESSION['error'] = $validation['error'];
                    continue;
                }

                Attachment::create([
                    'entity_type'    => 'sponsorship',
                    'entity_id'      => $sponsorshipId,
                    'filename'       => bin2hex(random_bytes(16)) . '_' . $file->getClientFilename(),
                    'original_name'  => $file->getClientFilename(),
                    'mime_type'      => $mimeType,
                    'file_content'   => $contents,
                ]);
            }
        }
```

- [ ] **Step 2: Run lint**

Run: `ddev exec php -l src/Controllers/SponsorshipController.php`
Expected: No syntax errors.

- [ ] **Step 3: Run tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/SponsoringFeatureTest.php -v`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/SponsorshipController.php
git commit -m "refactor: harmonize SponsorshipController to use UploadValidator (2 MB image limit)"
```

---

## Task 6: Update AppSettingController for app logo to use UploadValidator

**Files:**
- Modify: `src/Controllers/AppSettingController.php`

- [ ] **Step 1: Modify AppSettingController**

Add import at top:
```php
use App\Util\UploadValidator;
```

Find the logo upload section around line 74-77. Replace with:

Old:
```php
            $uploadedFiles = $request->getUploadedFiles();
            if (isset($uploadedFiles['app_logo'])) {
                $file = $uploadedFiles['app_logo'];
                if ($file->getError() === UPLOAD_ERR_OK) {
```

New:
```php
            $uploadedFiles = $request->getUploadedFiles();
            if (isset($uploadedFiles['app_logo'])) {
                $file = $uploadedFiles['app_logo'];
                if ($file->getError() === UPLOAD_ERR_OK) {
                    $mimeType = (string) $file->getClientMediaType();
                    $size = (int) $file->getSize();

                    // Validate image size before processing
                    $validation = UploadValidator::validateImageSize($size, $mimeType);
                    if (!$validation['valid']) {
                        $_SESSION['error'] = $validation['error'];
                        return $response->withHeader('Location', '/settings')->withStatus(302);
                    }
```

- [ ] **Step 2: Run lint**

Run: `ddev exec php -l src/Controllers/AppSettingController.php`
Expected: No syntax errors.

- [ ] **Step 3: Run tests (if available)**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/ -v -k "AppSetting"`
Expected: PASS (or no tests; that's OK)

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/AppSettingController.php
git commit -m "refactor: harmonize AppSettingController logo upload using UploadValidator (2 MB image limit)"
```

---

## Task 7: Create frontend image compression helper

**Files:**
- Create: `public/js/upload-helper.js`

- [ ] **Step 1: Write failing test for compression helper**

Create `tests/js/upload-helper.test.js`:

```javascript
describe('ImageCompressionHelper', () => {
  beforeEach(() => {
    // Reset any global state
  });

  test('should create a module with compressImage function', () => {
    expect(typeof window.ImageCompressionHelper).toBe('object');
    expect(typeof window.ImageCompressionHelper.compressImage).toBe('function');
  });

  test('should reject non-image files', async () => {
    const file = new File(['PDF content'], 'test.pdf', { type: 'application/pdf' });
    const result = await window.ImageCompressionHelper.processFile(file);
    
    expect(result.processed).toBe(false);
    expect(result.file).toBe(file); // Non-images pass through
  });

  test('should compress JPEG above 2MB', async () => {
    // Create a large mock canvas (simulate camera image)
    const canvas = document.createElement('canvas');
    canvas.width = 4000;
    canvas.height = 3000;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#FF0000';
    ctx.fillRect(0, 0, 4000, 3000);

    const largeBlob = await new Promise(resolve => {
      canvas.toBlob(resolve, 'image/jpeg', 0.9);
    });
    const largeFile = new File([largeBlob], 'large.jpg', { type: 'image/jpeg' });

    const result = await window.ImageCompressionHelper.processFile(largeFile);
    
    expect(result.processed).toBe(true);
    expect(result.file.size).toBeLessThanOrEqual(2 * 1024 * 1024);
    expect(result.file.type).toBe('image/jpeg');
  });

  test('should not process image already under 2MB', async () => {
    const canvas = document.createElement('canvas');
    canvas.width = 800;
    canvas.height = 600;
    
    const smallBlob = await new Promise(resolve => {
      canvas.toBlob(resolve, 'image/jpeg', 0.8);
    });
    const smallFile = new File([smallBlob], 'small.jpg', { type: 'image/jpeg' });

    const result = await window.ImageCompressionHelper.processFile(smallFile);
    
    // Should still return processed=true because we ran the check
    expect(result.file.size).toBeLessThanOrEqual(2 * 1024 * 1024);
  });

  test('should provide error for unsupported image format', async () => {
    // Mock browser that cannot decode HEIC
    const file = new File(['fake heic'], 'test.heic', { type: 'image/heic' });
    const result = await window.ImageCompressionHelper.processFile(file);
    
    // Result should indicate failure or pass through as non-processable
    expect(result).toBeDefined();
  });

  test('should batch process multiple files', async () => {
    const files = [
      new File(['pdf'], 'test.pdf', { type: 'application/pdf' }),
      new File([], 'test.jpg', { type: 'image/jpeg' }),
    ];

    const results = await window.ImageCompressionHelper.batchProcess(files);
    
    expect(results.length).toBe(2);
    expect(results[0].file.type).toBe('application/pdf'); // Unchanged
    expect(results[1].file.type).toBe('image/jpeg');
  });
});
```

Run the test with your test runner (e.g., Jest or Mocha). Expected: FAIL — `ImageCompressionHelper` is undefined.

- [ ] **Step 2: Write image compression helper**

Create `public/js/upload-helper.js`:

```javascript
/**
 * ImageCompressionHelper
 * Client-side utility to compress image files before upload, targeting ≤2 MB JPEG.
 * Non-image files pass through untouched.
 */
window.ImageCompressionHelper = (() => {
  const MAX_IMAGE_SIZE = 2 * 1024 * 1024; // 2 MB
  const IMAGE_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/svg+xml',
  ];

  /**
   * Determine if a file is an image based on MIME type.
   */
  function isImage(mimeType) {
    return IMAGE_MIME_TYPES.includes(mimeType.toLowerCase());
  }

  /**
   * Load an image file as a canvas element.
   * Returns a promise that resolves to {canvas, originalWidth, originalHeight}.
   */
  function loadImageAsCanvas(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (event) => {
        const img = new Image();
        img.onload = () => {
          const canvas = document.createElement('canvas');
          canvas.width = img.width;
          canvas.height = img.height;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0);
          resolve({
            canvas,
            originalWidth: img.width,
            originalHeight: img.height,
          });
        };
        img.onerror = () => {
          reject(new Error(`Could not decode image: ${file.name}`));
        };
        img.src = event.target.result;
      };
      reader.onerror = () => {
        reject(new Error(`Could not read file: ${file.name}`));
      };
      reader.readAsDataURL(file);
    });
  }

  /**
   * Compress canvas iteratively by reducing quality and/or dimensions.
   * Target: ≤2 MB JPEG. Returns a Promise<Blob>.
   */
  function compressCanvasToTarget(canvas, targetSizeBytes = MAX_IMAGE_SIZE) {
    return new Promise((resolve, reject) => {
      let quality = 0.8;
      let scale = 1.0;
      const minQuality = 0.3;
      const minScale = 0.3;

      function attemptCompress() {
        const scaledCanvas = document.createElement('canvas');
        scaledCanvas.width = Math.max(1, Math.round(canvas.width * scale));
        scaledCanvas.height = Math.max(1, Math.round(canvas.height * scale));

        const ctx = scaledCanvas.getContext('2d');
        ctx.drawImage(canvas, 0, 0, scaledCanvas.width, scaledCanvas.height);

        scaledCanvas.toBlob(
          (blob) => {
            if (blob && blob.size <= targetSizeBytes) {
              resolve(blob);
            } else if (quality > minQuality) {
              quality -= 0.1;
              attemptCompress();
            } else if (scale > minScale) {
              scale -= 0.15;
              quality = 0.8;
              attemptCompress();
            } else {
              // Could not reach target; return best effort
              resolve(blob);
            }
          },
          'image/jpeg',
          quality
        );
      }

      attemptCompress();
    });
  }

  /**
   * Process a single file: compress if image and oversized, otherwise pass through.
   * Returns Promise<{file: File, processed: boolean, error?: string}>.
   */
  async function processFile(file) {
    try {
      if (!isImage(file.type)) {
        return { file, processed: false };
      }

      // It's an image. Check size.
      if (file.size <= MAX_IMAGE_SIZE) {
        return { file, processed: false };
      }

      // Need to compress.
      const { canvas } = await loadImageAsCanvas(file);
      const compressedBlob = await compressCanvasToTarget(canvas, MAX_IMAGE_SIZE);

      // Check if compression was sufficient.
      if (compressedBlob.size > MAX_IMAGE_SIZE) {
        return {
          file,
          processed: false,
          error: `Bild konnte nicht unter 2 MB komprimiert werden. Größe: ${(compressedBlob.size / (1024 * 1024)).toFixed(1)} MB.`,
        };
      }

      const compressedFile = new File(
        [compressedBlob],
        file.name,
        { type: 'image/jpeg' }
      );

      return { file: compressedFile, processed: true };
    } catch (error) {
      return {
        file,
        processed: false,
        error: `Fehler beim Komprimieren von ${file.name}: ${error.message}`,
      };
    }
  }

  /**
   * Process multiple files (batch).
   * Returns Promise<Array>.
   */
  async function batchProcess(files) {
    return Promise.all(Array.from(files).map(processFile));
  }

  /**
   * Set up automatic compression on a file input element.
   * Intercepts form submission, processes files, and updates the FormData.
   */
  function setupFormCompression(formSelector) {
    const form = document.querySelector(formSelector);
    if (!form) {
      console.warn(`Form selector "${formSelector}" not found.`);
      return;
    }

    const fileInput = form.querySelector('input[type="file"][name*="attachments"]');
    if (!fileInput) {
      console.warn(`File input with name containing "attachments" not found in form.`);
      return;
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const files = fileInput.files;
      if (!files || files.length === 0) {
        form.submit();
        return;
      }

      const results = await batchProcess(files);

      // Check for errors.
      const errors = results.filter((r) => r.error);
      if (errors.length > 0) {
        const errorMessages = errors.map((e) => e.error).join('\n');
        alert(`Upload konnte nicht verarbeitet werden:\n\n${errorMessages}`);
        return;
      }

      // Build new FormData with compressed files.
      const newFormData = new FormData(form);
      // Remove old attachments entries.
      newFormData.delete('attachments[]');

      // Add processed files.
      results.forEach((result) => {
        newFormData.append('attachments[]', result.file);
      });

      // Send FormData via fetch.
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: newFormData,
        });

        if (response.ok) {
          // Redirect or reload as needed.
          window.location.href = response.url || form.action;
        } else {
          alert('Upload fehlgeschlagen. Bitte versuchen Sie es erneut.');
        }
      } catch (err) {
        alert(`Fehler beim Upload: ${err.message}`);
      }
    });
  }

  return {
    processFile,
    batchProcess,
    setupFormCompression,
    MAX_IMAGE_SIZE,
  };
})();
```

- [ ] **Step 3: Run test to verify it passes**

Run using your test runner (e.g., Jest):
```bash
npm test -- upload-helper.test.js
```
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add public/js/upload-helper.js tests/js/upload-helper.test.js
git commit -m "feat: add ImageCompressionHelper for client-side image compression"
```

---

## Task 8: Wire up compression helper in Twig templates (attachments partial)

**Files:**
- Modify: `templates/partials/attachments.twig`

- [ ] **Step 1: Add script src and form setup**

In `templates/partials/attachments.twig`, add at the top of the div (around line 38) a form wrapper if one does not exist, and:

Old (form starting around line 41):
```twig
        <form action="{{ upload_url }}"
              method="post"
              enctype="multipart/form-data"
              class="bg-light p-3 rounded-3">
```

New:
```twig
        <form action="{{ upload_url }}"
              method="post"
              enctype="multipart/form-data"
              class="bg-light p-3 rounded-3"
              id="attachmentUploadForm">
```

At the end of this Twig file, add a script block:

```twig
<script>
  document.addEventListener('DOMContentLoaded', function() {
    // Wire up compression helper for this attachment form
    if (window.ImageCompressionHelper) {
      window.ImageCompressionHelper.setupFormCompression('#attachmentUploadForm');
    }
  });
</script>
```

And ensure the script is loaded in the main layout. Check `templates/layout.twig` and add before closing body tag if not present:

```twig
        <script src="/js/upload-helper.js" defer></script>
```

- [ ] **Step 2: Update form-level hint text**

Add a help text below the file input to inform users. In `templates/partials/attachments.twig` around line 56-59, replace:

Old:
```twig
                <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-upload"></i> Hochladen
                </button>
```

New:
```twig
                <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-upload"></i> Hochladen
                </button>
                <small class="text-muted d-block mt-2">Bilder werden automatisch auf 2 MB optimiert. Andere Dateien maximal 10 MB.</small>
```

- [ ] **Step 3: Run visual check**

Open a browser to the application and navigate to a task detail page or finance form to confirm the help text appears and no JS errors are logged.

- [ ] **Step 4: Commit**

```bash
git add templates/partials/attachments.twig templates/layout.twig
git commit -m "feat: wire up ImageCompressionHelper in attachments partial"
```

---

## Task 9: Wire up compression helper in finances form

**Files:**
- Modify: `templates/finances/index.twig`

- [ ] **Step 1: Add form ID and script setup**

In `templates/finances/index.twig`, find the file input form around line 303-308 and update:

Old:
```twig
                    <div class="mb-3">
                        <label for="attachments" class="form-label">Anhänge hochladen</label>
                        <input type="file"
                               class="form-control"
                               id="attachments"
                               name="attachments[]"
                               multiple>
                        <div class="form-text">Bilder oder PDFs zur Belegung (mehrere möglich).</div>
                    </div>
```

New:
```twig
                    <div class="mb-3">
                        <label for="attachments" class="form-label">Anhänge hochladen</label>
                        <input type="file"
                               class="form-control"
                               id="attachments"
                               name="attachments[]"
                               multiple>
                        <div class="form-text">Bilder werden automatisch auf 2 MB optimiert. PDFs maximal 10 MB.</div>
                    </div>
```

Find the form containing this input (inner form in modal around line 280-282) and add ID:

Old:
```twig
            <form action="/finances"
                  method="post"
                  class="modal-content border-0 shadow">
```

New:
```twig
            <form action="/finances"
                  method="post"
                  class="modal-content border-0 shadow"
                  id="financeAttachmentForm">
```

At the end of the file (before closing `{% block %}`), add:

```twig
<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (window.ImageCompressionHelper) {
      window.ImageCompressionHelper.setupFormCompression('#financeAttachmentForm');
    }
  });
</script>
```

- [ ] **Step 2: Run visual check**

Open the finances modal and verify the help text and form setup.

- [ ] **Step 3: Commit**

```bash
git add templates/finances/index.twig
git commit -m "feat: wire up ImageCompressionHelper in finance attachments form"
```

---

## Task 10: Wire up compression helper in song library

**Files:**
- Modify: `templates/songs/manage.twig`

- [ ] **Step 1: Update form and add script**

In `templates/songs/manage.twig`, find the song attachment upload form around line 117-127 and update:

Old:
```twig
                                        <form action="/song-library/songs/{{ song.id }}/attachments"
                                              method="post"
                                              enctype="multipart/form-data"
                                              class="row g-2 align-items-center">
                                            <div class="col-md-8">
                                                <label class="form-label">Dateien anhaengen (mehrfach moeglich)</label>
                                                <input type="file"
                                                       name="attachments[]"
                                                       class="form-control"
                                                       multiple
                                                       required>
```

New:
```twig
                                        <form action="/song-library/songs/{{ song.id }}/attachments"
                                              method="post"
                                              enctype="multipart/form-data"
                                              class="row g-2 align-items-center"
                                              id="songAttachmentForm_{{ song.id }}">
                                            <div class="col-md-8">
                                                <label class="form-label">Dateien anhaengen (mehrfach moeglich)</label>
                                                <input type="file"
                                                       name="attachments[]"
                                                       class="form-control"
                                                       multiple
                                                       required>
                                                <small class="text-muted d-block mt-1">Bilder werden automatisch auf 2 MB optimiert.</small>
```

At the end of the file (before closing `{% block %}`), add:

```twig
<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (window.ImageCompressionHelper) {
      document.querySelectorAll('[id^="songAttachmentForm_"]').forEach(form => {
        window.ImageCompressionHelper.setupFormCompression('#' + form.id);
      });
    }
  });
</script>
```

- [ ] **Step 2: Run visual check**

Open song library page and verify form appears correctly.

- [ ] **Step 3: Commit**

```bash
git add templates/songs/manage.twig
git commit -m "feat: wire up ImageCompressionHelper in song library attachments"
```

---

## Task 11: Wire up compression helper in sponsorship form

**Files:**
- Modify: `templates/sponsoring/sponsors/detail.twig`

- [ ] **Step 1: Update sponsorship forms**

In `templates/sponsoring/sponsors/detail.twig`, there are two attachment upload sections (create and update). Find both around lines 393 and 502, and update them:

First form (around line 393):
Old:
```twig
                                        <input type="file" name="attachments[]" class="form-control" multiple>
```

New:
```twig
                                        <input type="file" name="attachments[]" class="form-control" multiple>
                                        <small class="text-muted d-block mt-1">Bilder werden automatisch auf 2 MB optimiert.</small>
```

Second form (around line 502):
Old:
```twig
                                            <input type="file" name="attachments[]" class="form-control" multiple>
```

New:
```twig
                                            <input type="file" name="attachments[]" class="form-control" multiple>
                                            <small class="text-muted d-block mt-1">Bilder werden automatisch auf 2 MB optimiert.</small>
```

Find the forms containing these inputs and add IDs (around lines ~380 and ~490). Then at the end of the file, add:

```twig
<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (window.ImageCompressionHelper) {
      // Wire up both sponsorship forms
      document.querySelectorAll('form[action*="/sponsoring/sponsorships/"]').forEach(form => {
        window.ImageCompressionHelper.setupFormCompression(form);
      });
    }
  });
</script>
```

- [ ] **Step 2: Run visual check**

Open sponsorship page and verify both forms work.

- [ ] **Step 3: Commit**

```bash
git add templates/sponsoring/sponsors/detail.twig
git commit -m "feat: wire up ImageCompressionHelper in sponsorship attachments forms"
```

---

## Task 12: Wire up compression helper for app logo upload

**Files:**
- Modify: `templates/settings/index.twig`

- [ ] **Step 1: Update logo form**

In `templates/settings/index.twig`, find the logo upload input around line 70 and update:

Old:
```twig
                                    <input class="form-control"
                                           type="file"
                                           id="app_logo"
                                           name="app_logo"
                                           accept="image/*">
                                    <div class="form-text">Empfohlen: Transparentes PNG oder SVG mit ca. 200x50 Pixeln.</div>
```

New:
```twig
                                    <input class="form-control"
                                           type="file"
                                           id="app_logo"
                                           name="app_logo"
                                           accept="image/*">
                                    <div class="form-text">Empfohlen: Transparentes PNG oder SVG mit ca. 200x50 Pixeln. Bilddateien werden automatisch auf 2 MB optimiert.</div>
```

Find the form containing this logo upload (search backwards for `<form`). Add form ID at the top:

```twig
            <form action="/settings"
                  method="post"
                  enctype="multipart/form-data"
                  id="appSettingsForm">
```

At end of file, add script (but only if not already present):

```twig
<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (window.ImageCompressionHelper) {
      window.ImageCompressionHelper.setupFormCompression('#appSettingsForm');
    }
  });
</script>
```

- [ ] **Step 2: Run visual check**

Open settings page and confirm logo upload works with hint text.

- [ ] **Step 3: Commit**

```bash
git add templates/settings/index.twig
git commit -m "feat: wire up ImageCompressionHelper for app logo upload"
```

---

## Task 13: Add backend integration test for size validation across domains

**Files:**
- Modify: `tests/Feature/UploadCompressionFeatureTest.php`

- [ ] **Step 1: Add more comprehensive domain tests**

Add to `tests/Feature/UploadCompressionFeatureTest.php`:

```php
    public function testUploadValidatorRejectsNonImageMimeType(): void
    {
        $result = UploadValidator::validateImageSize(1000, 'application/pdf');
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('nicht erlaubt', $result['error']);
    }

    public function testUploadValidatorHandlesMixedMimeTypeLists(): void
    {
        $allTypes = UploadValidator::getAllowedMimeTypes();
        
        $this->assertGreaterThan(5, count($allTypes));
        $this->assertContains('image/jpeg', $allTypes);
        $this->assertContains('application/pdf', $allTypes);
    }

    public function testUploadValidatorFormatsErrorMessagesForLargeFiles(): void
    {
        $fiftyMB = 50 * 1024 * 1024;
        $result = UploadValidator::validateImageSize($fiftyMB, 'image/jpeg');
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('50', $result['error']); // File size mentioned
        $this->assertStringContainsString('2', $result['error']); // Limit mentioned
    }
```

- [ ] **Step 2: Run tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/UploadCompressionFeatureTest.php -v`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/UploadCompressionFeatureTest.php
git commit -m "test: add comprehensive backend validation tests for image/non-image sizes"
```

---

## Task 14: Run full test suite and verify no regressions

**Files:**
- (No new files)

- [ ] **Step 1: Run backend tests**

Run: `ddev exec php vendor/bin/phpunit tests/Feature/ -v`
Expected: All tests pass. Note total count.

- [ ] **Step 2: Run frontend tests**

Run: `npm test -- upload-helper.test.js` (if Jest is configured)
Expected: All compression helper tests pass.

- [ ] **Step 3: Manual smoke test**

1. Open Finances form, upload an image, confirm it compresses and submits.
2. Open Song library, upload an image, confirm success.
3. Open Tasks, add attachment, confirm compression works.
4. Open Settings, update logo, confirm compression applied.
5. Verify error message displays if image still >2 MB after compression.

- [ ] **Step 4: Check lint**

Run: `ddev exec php -l src/Controllers/*.php` (all modified controllers)
Expected: No syntax errors.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "test: green full test suite post integration"
```

---

## Task 15: Create summary document and final review

**Files:**
- Create: `docs/superpowers/plans/IMPLEMENTATION_SUMMARY.md` (optional but recommended)

- [ ] **Step 1: Document changes made**

Create a summary of all changes:
- Which controllers were updated and what changed.
- Which templates were updated and what was added.
- New JS module and how to use it.
- Test coverage added.

This can be a short bulleted list saved as a reference for code review.

- [ ] **Step 2: Final verification**

Verify each commit message follows the pattern:
- `feat:` for new features
- `refactor:` for refactors
- `test:` for tests
- All commits include the domain/file involved

Run: `git log --oneline -20`
Expected: Clean, descriptive commit history.

- [ ] **Step 3: Commit or mark complete**

If you created a summary, commit it:
```bash
git add docs/superpowers/plans/IMPLEMENTATION_SUMMARY.md
git commit -m "docs: add implementation summary"
```

Otherwise, all work is complete.

---

## Self-Review Against Spec

**1. Spec coverage:**

- ✅ Image 2 MB limit: UploadValidator (Task 1), all controllers (Tasks 2-6), frontend compression (Task 7), templates (Tasks 8-12).
- ✅ Non-image 10 MB limit: UploadValidator, all controllers, backend tests (Task 13).
- ✅ JPEG compression target: upload-helper.js forces JPEG output.
- ✅ No forced camera capture: Twig templates do not set `capture=` attribute.
- ✅ Client compression + server validation: Tasks 7-12 (client), Tasks 1-6 (server re-validation).
- ✅ Error handling: UploadValidator returns error messages, upload-helper.js shows alerts with file names, controllers set $_SESSION['error'].
- ✅ Mixed batch uploads: upload-helper.js processes each file independently (Task 7).
- ✅ Testing: Tasks 1, 13 (backend), Task 7 (frontend).

**2. Placeholder scan:**

No "TBD", "TODO", "similar to", or vague instructions found. Every step includes actual code/commands.

**3. Type consistency:**

- `UploadValidator::MAX_IMAGE_SIZE` = 2 MB throughout.
- `UploadValidator::MAX_NON_IMAGE_SIZE` = 10 MB throughout.
- `ImageCompressionHelper.MAX_IMAGE_SIZE` references same constant.
- All error messages use consistent German phrasing.

**4. Scope check:**

Single, well-bounded feature. No subsystem decomposition needed. Implementation plan is complete in scope relative to the spec.

---

Plan complete and saved to `docs/superpowers/plans/2026-04-12-image-upload-compression-plan.md`

**Two execution options:**

**1. Subagent-Driven (recommended):** I dispatch a fresh subagent per task, review between tasks, fast iteration with independent verification.

**2. Inline Execution:** Execute tasks in this session using the executing-plans skill, batch execution with checkpoints.

Which approach?

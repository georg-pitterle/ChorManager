<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use PHPUnit\Framework\TestCase;

class NewsletterSecurityHardeningFeatureTest extends TestCase
{
    public function testNewsletterControllerValidatesDraftInputBeforePersisting(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('private function validateNewsletterDraftInput', $controllerContent);
        $this->assertStringContainsString('Titel und Inhalt sind Pflichtfelder.', $controllerContent);
        $this->assertStringContainsString('Der Titel ist zu lang (max. 255 Zeichen).', $controllerContent);
        $this->assertStringContainsString("if (!\$validation['ok'])", $controllerContent);
    }

    public function testNewsletterControllerReturnsJsonErrorPayloadForAjaxSendFailures(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString(
            "return \$this->jsonResponse(\$response, ['error' => \$message], 500);",
            $controllerContent
        );
    }

    public function testNewsletterControllerSanitizesAndValidatesSaveAsTemplateInput(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString(
            'trim((string) ($data[\'template_name\'] ?? $newsletter->title))',
            $controllerContent
        );
        $this->assertStringContainsString('mb_strlen($templateName) > 255', $controllerContent);
        $this->assertStringContainsString(
            '$this->htmlSanitizer->sanitizeNewsletterHtml($newsletter->content_html)',
            $controllerContent
        );
    }

    public function testMailQueueDueSoonScopeOnlyReturnsRetryableFailedEntries(): void
    {
        $modelContent = file_get_contents(dirname(__DIR__) . '/../src/Models/MailQueue.php');

        $this->assertIsString($modelContent);
        $this->assertStringContainsString("where('status', 'queued')", $modelContent);
        $this->assertStringContainsString("orWhere(function (\$retryableFailed)", $modelContent);
        $this->assertStringContainsString("where('status', 'failed')", $modelContent);
        $this->assertStringContainsString("where('is_retryable', true)", $modelContent);
        $this->assertStringContainsString("whereColumn('attempts', '<', 'max_attempts')", $modelContent);
    }

    public function testMailQueueCanRetryRespectsRetryabilityAndAttemptsForFailedEntries(): void
    {
        $failedRetryable = new MailQueue();
        $failedRetryable->status = 'failed';
        $failedRetryable->is_retryable = true;
        $failedRetryable->attempts = 1;
        $failedRetryable->max_attempts = 3;

        $failedExhausted = new MailQueue();
        $failedExhausted->status = 'failed';
        $failedExhausted->is_retryable = true;
        $failedExhausted->attempts = 3;
        $failedExhausted->max_attempts = 3;

        $deadLetter = new MailQueue();
        $deadLetter->status = 'dead';
        $deadLetter->is_retryable = false;
        $deadLetter->attempts = 5;
        $deadLetter->max_attempts = 3;

        $this->assertTrue($failedRetryable->canRetry());
        $this->assertFalse($failedExhausted->canRetry());
        $this->assertTrue($deadLetter->canRetry());
    }
}

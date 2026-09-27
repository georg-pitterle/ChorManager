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

    /**
     * Überprüft, dass der NewsletterTemplateController Template-Eingaben
     * beim Erstellen aus einem Newsletter bereinigt und validiert.
     */
    public function testNewsletterTemplateControllerSanitizesAndValidatesTemplateCreationFromNewsletter(): void
    {
        $controllerContent = file_get_contents(
            dirname(__DIR__) . '/../src/Controllers/NewsletterTemplateController.php'
        );

        $this->assertIsString($controllerContent);
        // Der Name lief vorher über `trim((string) ($data['template_name'] ?? ...))`
        // und wurde hier als Zeichenkette gesucht. Seit dem Umstieg auf
        // InputValidator::asString() steht dort ein anderer Wortlaut, und die Suche
        // hätte ohnehin nie gemerkt, ob der Rückfall und die Längengrenze wirken.
        // Beides prüft jetzt Tests\Feature\NewsletterTemplateSettingsFeatureTest
        // am gespeicherten Datensatz.
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

    /**
     * Von Hand erneut versenden darf man nur einen endgültig liegengebliebenen
     * Eintrag. Ein `failed`-Eintrag mit freien Versuchen gehört dem automatischen
     * Weg (`scopeDueSoon()`) - vorher bejahte `canRetry()` ihn und widersprach
     * damit `MailQueueAdminService::retrySingle()`, das ihn abweist.
     */
    public function testMailQueueCanRetryOnlyAllowsDeadEntries(): void
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

        $queued = new MailQueue();
        $queued->status = 'queued';

        $sent = new MailQueue();
        $sent->status = 'sent';

        $deadLetter = new MailQueue();
        $deadLetter->status = 'dead';
        $deadLetter->is_retryable = false;
        $deadLetter->attempts = 5;
        $deadLetter->max_attempts = 3;

        $this->assertTrue($deadLetter->canRetry());
        $this->assertFalse(
            $failedRetryable->canRetry(),
            'Den holt sich dueSoon() von selbst - der Handknopf ist dafür nicht da.'
        );
        $this->assertFalse($failedExhausted->canRetry());
        $this->assertFalse($queued->canRetry());
        $this->assertFalse($sent->canRetry());
    }

    /**
     * Die Verwaltung muss dieselbe Bedingung benutzen und nicht ihre eigene
     * mitbringen. Genau daran liefen die beiden Antworten vorher auseinander.
     */
    public function testRetrySingleAsksTheModelWhetherAnEntryMayBeRetried(): void
    {
        $serviceContent = file_get_contents(dirname(__DIR__) . '/../src/Services/MailQueueAdminService.php');

        $this->assertIsString($serviceContent);
        $this->assertStringContainsString('if (!$entry->canRetry())', $serviceContent);
        $this->assertStringNotContainsString("if (\$entry->status !== 'dead')", $serviceContent);
    }
}

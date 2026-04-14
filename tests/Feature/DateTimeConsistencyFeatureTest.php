<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class DateTimeConsistencyFeatureTest extends TestCase
{
    public function testTimezoneIsConfiguredGloballyForAppAndDatabase(): void
    {
        $settings = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');
        $bootstrap = file_get_contents(dirname(__DIR__) . '/../public/index.php');
        $timezoneUtil = file_get_contents(dirname(__DIR__) . '/../src/Util/Timezone.php');

        $this->assertIsString($settings);
        $this->assertIsString($bootstrap);
        $this->assertIsString($timezoneUtil);

        $this->assertStringContainsString('resolveAppTimezone', $timezoneUtil);
        $this->assertStringContainsString('resolveDatabaseTimezoneOffset', $timezoneUtil);
        $this->assertStringContainsString("'timezone' => \$appTimezone", $settings);
        $this->assertStringContainsString("'timezone' => Timezone::resolveDatabaseTimezoneOffset()", $settings);
        $this->assertStringContainsString('date_default_timezone_set(Timezone::resolveAppTimezone());', $bootstrap);
    }

    public function testDateAndDateTimeFieldsAreExplicitlyCastInModels(): void
    {
        $models = [
            'Activity' => ["'created_at' => 'datetime'"],
            'Attachment' => ["'created_at' => 'datetime'"],
            'Comment' => ["'created_at' => 'datetime'", "'updated_at' => 'datetime'"],
            'Event' => ["'event_date' => 'datetime'"],
            'EventSeries' => ["'end_date' => 'date'"],
            'Finance' => ["'invoice_date' => 'date'", "'payment_date' => 'date'"],
            'Newsletter' => ["'created_at' => 'datetime'", "'updated_at' => 'datetime'", "'locked_at' => 'datetime'", "'sent_at' => 'datetime'"],
            'NewsletterArchive' => ["'sent_at' => 'datetime'"],
            'NewsletterTemplate' => ["'created_at' => 'datetime'", "'updated_at' => 'datetime'"],
            'PasswordReset' => ["'created_at' => 'datetime'"],
            'Project' => ["'start_date' => 'date'", "'end_date' => 'date'"],
            'RememberLogin' => ["'expires_at' => 'datetime'", "'created_at' => 'datetime'", "'last_used_at' => 'datetime'"],
            'SponsoringContact' => ["'contact_date'   => 'date'", "'follow_up_date' => 'date'"],
            'Sponsorship' => ["'start_date' => 'date'", "'end_date'   => 'date'"],
            'Task' => ["'start_date' => 'date'", "'end_date' => 'date'", "'created_at' => 'datetime'", "'updated_at' => 'datetime'"],
        ];

        foreach ($models as $model => $needles) {
            $content = file_get_contents(dirname(__DIR__) . '/../src/Models/' . $model . '.php');

            $this->assertIsString($content);
            $this->assertStringContainsString('protected $casts', $content, 'Missing casts array in model ' . $model);

            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $content, sprintf('Missing cast %s in model %s', $needle, $model));
            }
        }
    }

    public function testNewsletterDateFieldsAreRenderedWithTwigDateFilter(): void
    {
        $indexTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/index.twig');
        $archiveTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/archive.twig');
        $previewTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/preview.twig');
        $lockedTemplate = file_get_contents(dirname(__DIR__) . '/../templates/newsletters/locked.twig');

        $this->assertIsString($indexTemplate);
        $this->assertIsString($archiveTemplate);
        $this->assertIsString($previewTemplate);
        $this->assertIsString($lockedTemplate);

        $this->assertStringContainsString('newsletter.created_at|date("d.m.Y H:i")', $indexTemplate);
        $this->assertStringContainsString('entry.sent_at|date("d.m.Y H:i")', $archiveTemplate);
        $this->assertStringContainsString('newsletter.created_at|date("d.m.Y H:i")', $previewTemplate);
        $this->assertStringContainsString('newsletter.locked_at|date("d.m.Y H:i:s")', $lockedTemplate);
    }
}

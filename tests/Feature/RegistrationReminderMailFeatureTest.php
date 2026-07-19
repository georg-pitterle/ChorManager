<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Services\MailQueueService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class RegistrationReminderMailFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    public function testEnqueueRegistrationReminderMail(): void
    {
        $service = new MailQueueService();
        $entry = $service->enqueueRegistrationReminderMail(
            'mitglied@example.org',
            'Erinnerung: Anmeldung zur Probe',
            '<p>Bitte eintragen</p>',
            42,
            7
        );

        $this->assertSame('registration_reminder', $entry->mail_type);
        $this->assertSame('queued', $entry->status);
        $this->assertSame(['user_id' => 42, 'event_id' => 7], $entry->payload_json);

        MailQueue::where('id', $entry->id)->delete();
    }

    public function testReminderTemplateExistsWithDirectLink(): void
    {
        $path = dirname(__DIR__) . '/../templates/emails/registration_reminder.twig';
        $this->assertFileExists($path);

        $template = file_get_contents($path);
        $this->assertIsString($template);
        $this->assertStringContainsString('{{ link }}', $template);
        $this->assertStringContainsString('Anmeldeschluss', $template);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Services\MailDeliveryService;
use App\Services\Mailer;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Der Queue-Worker arbeitet immer nur einen Ausschnitt der Warteschlange ab.
 * Ohne feste Reihenfolge entscheidet die Datenbank, welche Mails das sind.
 */
final class MailDeliveryServiceFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $_ENV['DISABLE_MAIL_SEND'] = $_SERVER['DISABLE_MAIL_SEND'] = 'true';

        MailQueue::query()->delete();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function enqueue(string $subject, Carbon $createdAt): MailQueue
    {
        $entry = MailQueue::create([
            'mail_type' => 'invitation',
            'recipient_email' => 'worker_' . bin2hex(random_bytes(4)) . '@example.test',
            'subject' => $subject,
            'body_html' => '<p>Inhalt</p>',
            'payload_json' => [],
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => 3,
            'is_retryable' => false,
            'next_attempt_at' => Carbon::now()->subMinute(),
        ]);

        // created_at wird von Eloquent gesetzt; für die Reihenfolge braucht der
        // Test einen eindeutigen Abstand.
        MailQueue::query()->whereKey($entry->id)->update(['created_at' => $createdAt]);

        return $entry->refresh();
    }

    public function testTheWorkerProcessesTheOldestEntriesFirst(): void
    {
        $now = Carbon::now();

        // Bewusst in umgekehrter Reihenfolge eingefügt: Ohne ausdrückliche
        // Sortierung liefert die Datenbank die Zeilen in Speicherreihenfolge und
        // der jüngste Eintrag käme zuerst dran.
        $newest = $this->enqueue('Zuletzt', $now->copy()->subMinutes(10));
        $oldest = $this->enqueue('Zuerst', $now->copy()->subMinutes(30));
        $middle = $this->enqueue('Danach', $now->copy()->subMinutes(20));

        $service = new MailDeliveryService(new Mailer(new NullLogger()));
        $service->processDueEntries(2);

        $this->assertSame('skipped', MailQueue::findOrFail($oldest->id)->status);
        $this->assertSame('skipped', MailQueue::findOrFail($middle->id)->status);
        $this->assertSame(
            'queued',
            MailQueue::findOrFail($newest->id)->status,
            'Der jüngste Eintrag muss auf den nächsten Durchlauf warten.'
        );
    }

    /**
     * Stellt einen Mailer, dessen Versand mit der übergebenen Fehlermeldung
     * scheitert - so wie PHPMailer die Antwort des Servers durchreicht.
     */
    private function failingMailer(string $errorMessage): Mailer
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendHtmlMailDetailed')->willReturn(['success' => false]);
        $mailer->method('getLastError')->willReturn($errorMessage);
        $mailer->method('isUsingSmtp')->willReturn(true);

        return $mailer;
    }

    /**
     * Ein dauerhafter 5xx-Fehler bedeutet: Diese Adresse gibt es nicht. Weitere
     * Versuche wären reines Zustellrauschen an einen toten Empfänger und
     * schaden der Reputation des Absenders.
     */
    public function testAPermanentSmtpFailureIsNotRetried(): void
    {
        $entry = $this->enqueue('Dauerhaft gescheitert', Carbon::now());
        $service = new MailDeliveryService(
            $this->failingMailer('SMTP Error: 550 5.1.1 <weg@example.test>: Recipient address rejected: User unknown')
        );

        $service->sendEntry($entry);

        $stored = MailQueue::findOrFail($entry->id);
        $this->assertSame('dead', $stored->status);
        $this->assertFalse((bool) $stored->is_retryable);
        $this->assertSame(1, (int) $stored->attempts, 'Der erste Versuch muss der letzte gewesen sein.');
    }

    /**
     * Auch ohne Klartext-Code muss der erweiterte Statuscode der Klasse 5
     * als dauerhaft erkannt werden.
     */
    public function testAnEnhancedFiveClassStatusCodeIsNotRetried(): void
    {
        $entry = $this->enqueue('Mailbox gibt es nicht', Carbon::now());
        $service = new MailDeliveryService(
            $this->failingMailer('Mailbox unavailable (5.1.1)')
        );

        $service->sendEntry($entry);

        $this->assertSame('dead', MailQueue::findOrFail($entry->id)->status);
    }

    /**
     * Ein 4xx-Fehler ist vorübergehend - da muss der Worker es erneut versuchen.
     */
    public function testATemporarySmtpFailureStaysRetryable(): void
    {
        $entry = $this->enqueue('Vorübergehend gescheitert', Carbon::now());
        $service = new MailDeliveryService(
            $this->failingMailer('SMTP Error: 451 4.3.0 Temporary lookup failure, try again later')
        );

        $service->sendEntry($entry);

        $stored = MailQueue::findOrFail($entry->id);
        $this->assertSame('failed', $stored->status);
        $this->assertTrue((bool) $stored->is_retryable);
        $this->assertNotNull($stored->next_attempt_at);
    }

    /**
     * Zahlen im Fließtext dürfen keinen dauerhaften Fehler vortäuschen, sonst
     * bleiben zustellbare Mails liegen.
     */
    public function testAPlainNumberInTheMessageDoesNotLookPermanent(): void
    {
        $entry = $this->enqueue('Netzwerkfehler', Carbon::now());
        $service = new MailDeliveryService(
            $this->failingMailer('Connection timed out after 500 ms')
        );

        $service->sendEntry($entry);

        $this->assertSame('failed', MailQueue::findOrFail($entry->id)->status);
    }
}

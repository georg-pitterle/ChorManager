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
}

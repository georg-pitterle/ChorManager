<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Services\MailQueueAdminService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Filter der Queue-Übersicht kommen direkt aus der Adresszeile und sind
 * damit beliebiger Text.
 */
final class MailQueueAdminServiceFeatureTest extends TestCase
{
    private MailQueueAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->service = new MailQueueAdminService();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function createEntry(): MailQueue
    {
        return MailQueue::create([
            'mail_type' => 'newsletter',
            'recipient_email' => 'filter_' . bin2hex(random_bytes(6)) . '@example.test',
            'subject' => 'Filtertest',
            'body_html' => '<p>Filtertest</p>',
            'payload_json' => [],
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => 3,
            'is_retryable' => false,
            'next_attempt_at' => Carbon::now(),
        ]);
    }

    public function testInvalidDateFiltersAreIgnoredInsteadOfCrashing(): void
    {
        $entry = $this->createEntry();

        $entries = $this->service->listEntries([
            'from_date' => 'kein Datum',
            'to_date' => '31.02.',
        ]);

        $this->assertTrue(
            $entries->contains(static fn(MailQueue $item): bool => (int) $item->id === (int) $entry->id),
            'Ein unlesbares Datum darf den Filter nur überspringen, nicht die Seite abbrechen.'
        );
    }

    public function testValidDateFiltersStillNarrowTheResult(): void
    {
        $entry = $this->createEntry();

        $matching = $this->service->listEntries([
            'from_date' => Carbon::now()->subDay()->format('Y-m-d'),
            'to_date' => Carbon::now()->format('Y-m-d'),
        ]);
        $this->assertTrue(
            $matching->contains(static fn(MailQueue $item): bool => (int) $item->id === (int) $entry->id)
        );

        $outside = $this->service->listEntries([
            'from_date' => Carbon::now()->addDays(5)->format('Y-m-d'),
        ]);
        $this->assertFalse(
            $outside->contains(static fn(MailQueue $item): bool => (int) $item->id === (int) $entry->id)
        );
    }
}

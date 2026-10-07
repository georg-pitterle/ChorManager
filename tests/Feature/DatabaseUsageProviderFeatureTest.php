<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Services\Storage\DatabaseUsageProvider;
use App\Services\Storage\StorageUsageNode;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Tabellengrößen aus information_schema sind bei MySQL zwischengespeichert und im
 * Test nicht verlässlich. Geprüft werden deshalb die exakt gerechneten Teile - die
 * Anhänge nach Bereich - und die Struktur, nicht einzelne Tabellengrößen.
 */
final class DatabaseUsageProviderFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }

    private function attach(string $entityType, int $bytes, ?int $recordedSize = -1): void
    {
        Attachment::create([
            'entity_type' => $entityType,
            'entity_id' => 1,
            'filename' => bin2hex(random_bytes(6)) . '.bin',
            'original_name' => 'datei.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => $recordedSize === -1 ? $bytes : $recordedSize,
            'file_content' => str_repeat('x', $bytes),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Die gespeicherte Größe genügt - LENGTH() auf den BLOB müsste jeden Anhang von
     * der Platte lesen, bei jeder Messung. Nur Altdaten ohne Größe werden gemessen.
     */
    public function testRecordedSizeIsUsedAndOnlyMissingSizesAreMeasured(): void
    {
        $this->attach('task', 10, 1000);
        $this->attach('task', 30, null);

        $task = $this->usage()->child('database.attachments')?->child('database.attachments.task');

        $this->assertSame(1030, $task?->bytes);
        $this->assertSame(2, $task?->count);
    }

    private function usage(): StorageUsageNode
    {
        return (new DatabaseUsageProvider())->usage();
    }

    public function testAttachmentsAreGroupedByAreaWithExactPayload(): void
    {
        $this->attach('finance', 1000);
        $this->attach('finance', 500);
        $this->attach('song', 300);
        $this->attach('mystery', 20);

        $attachments = $this->usage()->child('database.attachments');

        $this->assertNotNull($attachments);
        $this->assertSame(1500, $attachments->child('database.attachments.finance')?->bytes);
        $this->assertSame(2, $attachments->child('database.attachments.finance')?->count);
        $this->assertSame('Finanzen', $attachments->child('database.attachments.finance')?->label);
        $this->assertSame('Lieder', $attachments->child('database.attachments.song')?->label);
        $this->assertSame('mystery', $attachments->child('database.attachments.mystery')?->label);
        $this->assertSame(4, $attachments->count);
    }

    /**
     * Ist die gemeldete Tabellengröße kleiner als die Nutzdaten (veraltete Statistik),
     * darf "Verwaltung und Index" nicht negativ werden.
     */
    public function testOverheadIsNeverNegativeAndRootIsSumOfChildren(): void
    {
        $this->attach('event', 200000);

        $root = $this->usage();
        $attachments = $root->child('database.attachments');

        $this->assertGreaterThanOrEqual(0, $attachments?->child('database.attachments.overhead')?->bytes ?? 0);
        $this->assertGreaterThanOrEqual(200000, $attachments?->bytes);

        $sum = 0;
        foreach ($root->children as $child) {
            $sum += $child->bytes;
        }
        $this->assertSame($sum, $root->bytes);
    }

    public function testStructureContainsMailQueueNotificationsAndAtMostTenTopTables(): void
    {
        $root = $this->usage();

        $this->assertSame('database', $root->key);
        $this->assertNotNull($root->child('database.mail_queue'));
        $this->assertIsInt($root->child('database.mail_queue')?->count, 'Zeilenzahl über COUNT(*).');
        $notifications = $root->child('database.notifications');
        $this->assertNotNull($notifications?->child('database.notifications.user_notifications'));
        $this->assertNotNull($notifications?->child('database.notifications.notification_dispatch_log'));

        $top = array_filter(
            $root->children,
            static fn (StorageUsageNode $n): bool => str_starts_with($n->key, 'database.tables.')
        );
        $this->assertLessThanOrEqual(10, count($top));
        foreach ($top as $node) {
            $this->assertNotSame('database.tables.attachments', $node->key, 'Anhänge stehen nicht doppelt.');
            $this->assertNotSame('database.tables.mail_queue', $node->key);
        }
    }
}

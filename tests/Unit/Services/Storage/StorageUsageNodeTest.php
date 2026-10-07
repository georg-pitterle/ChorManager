<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\StorageUsageNode;
use PHPUnit\Framework\TestCase;

final class StorageUsageNodeTest extends TestCase
{
    /**
     * Eine Aufschlüsselung (Teamordner) zeigt dieselben Bytes noch einmal anders
     * gruppiert - sie darf die Summe nicht verdoppeln.
     */
    public function testSumIgnoresBreakdownChildren(): void
    {
        $node = StorageUsageNode::sum('files', 'Dateiablage', [
            new StorageUsageNode('files.current', 'Aktuell', 300),
            new StorageUsageNode('files.trash', 'Papierkorb', 50),
            new StorageUsageNode('files.team_folders', 'Teamordner', 350, breakdown: true),
        ], 7);

        $this->assertSame(350, $node->bytes);
        $this->assertSame(7, $node->count);
        $this->assertSame(50, $node->child('files.trash')?->bytes);
        $this->assertNull($node->child('files.unknown'));
    }

    public function testFailedNodeHasNoBytesAndAnError(): void
    {
        $node = StorageUsageNode::failed('backups', 'Backups');

        $this->assertSame(0, $node->bytes);
        $this->assertSame('nicht ermittelbar', $node->error);
        $this->assertSame([], $node->children);
    }
}

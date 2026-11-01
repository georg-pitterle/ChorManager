<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Speicherübersicht zählt die Anhänge allein über `file_size`. Eine Zeile ohne
 * Größe zählte dort stumm mit null Byte - deshalb weist die Datenbank sie ab, statt
 * dass die Messung wieder auf den BLOB ausweicht.
 */
final class AttachmentFileSizeColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    public function testColumnRefusesAMissingSize(): void
    {
        $column = Capsule::connection()->selectOne(
            "SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'file_size'"
        );

        $this->assertSame('NO', $column->nullable);
    }
}

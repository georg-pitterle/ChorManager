<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseRowCountSnapshot;

/**
 * Der Wächter meldet Abweichungen in beide Richtungen. Nur auf Zuwachs zu schauen
 * reicht nicht: ein Test, der fremde Zeilen löscht, beschädigt den Bestand genauso -
 * BackupServiceTest entfernte auf diesem Weg bei jedem Lauf echte remember_logins.
 */
final class DatabaseRowCountSnapshotTest extends TestCase
{
    public function testAnUnchangedDatabaseYieldsNoDifference(): void
    {
        $counts = ['songs' => 12, 'users' => 3];

        $this->assertSame([], DatabaseRowCountSnapshot::compare($counts, $counts));
    }

    public function testLeftoverRowsAreReportedAsAPositiveDifference(): void
    {
        $this->assertSame(
            ['songs' => 4],
            DatabaseRowCountSnapshot::compare(['songs' => 12], ['songs' => 16])
        );
    }

    public function testDeletedRowsAreReportedAsANegativeDifference(): void
    {
        $this->assertSame(
            ['remember_logins' => -7],
            DatabaseRowCountSnapshot::compare(['remember_logins' => 7], ['remember_logins' => 0])
        );
    }

    /**
     * Eine im Test angelegte Tabelle gehört danach wieder weg, also zählt sie voll mit.
     */
    public function testATableCreatedDuringTheRunCountsInFull(): void
    {
        $this->assertSame(
            ['probe' => 2],
            DatabaseRowCountSnapshot::compare([], ['probe' => 2])
        );
    }

    /**
     * Eine verschwundene Tabelle taucht im Endstand nicht mehr auf - ohne den zweiten
     * Durchlauf über den Ausgangsstand bliebe ihr Verlust unbemerkt.
     */
    public function testATableDroppedDuringTheRunIsReported(): void
    {
        $this->assertSame(
            ['songs' => -12],
            DatabaseRowCountSnapshot::compare(['songs' => 12, 'users' => 3], ['users' => 3])
        );
    }

    /**
     * Eine leere Tabelle, die verschwindet, hat keine Zeilen verloren - sie soll den
     * Wächter nicht ohne Not rot färben.
     */
    public function testADroppedEmptyTableIsNotReported(): void
    {
        $this->assertSame([], DatabaseRowCountSnapshot::compare(['probe' => 0], []));
    }

    public function testTheDescriptionNamesBothDirections(): void
    {
        $text = DatabaseRowCountSnapshot::describeDifferences(['songs' => 4, 'remember_logins' => -7]);

        $this->assertStringContainsString('songs: +4 Zeile(n) zurückgelassen', $text);
        $this->assertStringContainsString('remember_logins: -7 Zeile(n) gelöscht', $text);
    }
}

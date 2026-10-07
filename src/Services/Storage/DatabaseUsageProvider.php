<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Datenbank. Tabellengrößen kommen aus information_schema (Daten + Index); MySQL
 * hält sie bis zu einen Tag zwischengespeichert, sie sind also eine Näherung.
 * Genau gerechnet werden die Anhänge (gespeicherte Größe, nur ohne sie LENGTH des
 * BLOBs) und alle Zeilenzahlen
 * (COUNT(*) statt TABLE_ROWS, das bei InnoDB nur geschätzt ist).
 *
 * Die Wurzel ist immer die Summe ihrer Kinder, damit die Übersicht in sich aufgeht,
 * auch wenn die Statistik hinter den Nutzdaten herhinkt.
 */
final class DatabaseUsageProvider implements StorageUsageProvider
{
    private const TOP_TABLES = 10;

    private const ATTACHMENT_LABELS = [
        'event' => 'Termine',
        'finance' => 'Finanzen',
        'song' => 'Lieder',
        'sponsor' => 'Sponsoren',
        'sponsorship' => 'Sponsoring',
        'task' => 'Aufgaben',
        'newsletter' => 'Newsletter',
    ];

    private const NOTIFICATION_TABLES = [
        'user_notifications' => 'In der App',
        'notification_dispatch_log' => 'Versandprotokoll',
    ];

    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Datenbank';
    }

    public function usage(): StorageUsageNode
    {
        $sizes = $this->tableSizes();
        $children = [];

        if (isset($sizes['attachments'])) {
            $children[] = $this->attachments($sizes['attachments']);
            unset($sizes['attachments']);
        }

        if (isset($sizes['mail_queue'])) {
            $children[] = $this->table('database.mail_queue', 'Mail-Warteschlange', 'mail_queue', $sizes['mail_queue']);
            unset($sizes['mail_queue']);
        }

        $notifications = [];
        foreach (self::NOTIFICATION_TABLES as $table => $label) {
            if (isset($sizes[$table])) {
                $notifications[] = $this->table('database.notifications.' . $table, $label, $table, $sizes[$table]);
                unset($sizes[$table]);
            }
        }
        if ($notifications !== []) {
            $children[] = StorageUsageNode::sum('database.notifications', 'Benachrichtigungen', $notifications);
        }

        arsort($sizes);
        foreach (array_slice($sizes, 0, self::TOP_TABLES, true) as $table => $bytes) {
            $children[] = $this->table('database.tables.' . $table, (string) $table, (string) $table, $bytes);
        }

        $rest = array_slice($sizes, self::TOP_TABLES, null, true);
        if ($rest !== []) {
            $children[] = new StorageUsageNode('database.other', 'Übrige Tabellen', array_sum($rest), count($rest));
        }

        return StorageUsageNode::sum($this->key(), $this->label(), $children);
    }

    /**
     * @return array<string, int>
     */
    private function tableSizes(): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME AS name, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );

        $sizes = [];
        foreach ($rows as $row) {
            $sizes[(string) $row->name] = (int) $row->bytes;
        }

        return $sizes;
    }

    private function table(string $key, string $label, string $table, int $bytes): StorageUsageNode
    {
        return new StorageUsageNode($key, $label, $bytes, DB::table($table)->count());
    }

    private function attachments(int $tableBytes): StorageUsageNode
    {
        // file_size setzt jeder Schreiber; LENGTH() auf den BLOB müsste jeden Anhang
        // von der Platte lesen. COALESCE wertet es nur für Altdaten ohne Größe aus.
        $rows = DB::table('attachments')
            ->selectRaw(
                'entity_type, COUNT(*) AS file_count, '
                . 'COALESCE(SUM(COALESCE(file_size, LENGTH(file_content))), 0) AS payload'
            )
            ->groupBy('entity_type')
            ->get();

        $children = [];
        $payload = 0;
        $count = 0;
        foreach ($rows as $row) {
            $type = (string) $row->entity_type;
            $bytes = (int) $row->payload;
            $children[] = new StorageUsageNode(
                'database.attachments.' . $type,
                self::ATTACHMENT_LABELS[$type] ?? $type,
                $bytes,
                (int) $row->file_count
            );
            $payload += $bytes;
            $count += (int) $row->file_count;
        }

        usort($children, static fn (StorageUsageNode $a, StorageUsageNode $b): int => $b->bytes <=> $a->bytes);
        $children[] = new StorageUsageNode(
            'database.attachments.overhead',
            'Verwaltung und Index',
            max(0, $tableBytes - $payload)
        );

        return StorageUsageNode::sum('database.attachments', 'Anhänge', $children, $count);
    }
}

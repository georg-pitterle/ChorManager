<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `attachments.file_size` wird Pflicht.
 *
 * Die Speicherübersicht wich für Zeilen ohne Größe auf LENGTH(file_content) aus.
 * Das sollte nur Altdaten treffen, ließ InnoDB aber jeden BLOB von der Platte
 * lesen, weil die Spalte im Statement stand - gemessen auf MySQL 8.4 bei 1,2 GB
 * Anhängen 8 s statt 0,2 s, je Aufruf von /storage. Alle Schreibwege setzen die
 * Größe längst; mit dieser Migration kann die Messung ohne den Ausweg auskommen.
 *
 * Die Größe wird Zeile für Zeile nachgetragen. Ein UPDATE ... WHERE file_size IS
 * NULL nennt den BLOB ebenfalls und läse ihn für jede Zeile, auch wenn keine
 * einzige betroffen ist; die Liste der IDs kommt ohne ihn aus.
 *
 * Das ALTER baut die Tabelle samt BLOBs neu auf. Das kostet beim Einspielen
 * einmalig Zeit und vorübergehend Platz in Höhe der Tabelle.
 */
final class RequireFileSizeOnAttachments extends AbstractMigration
{
    /**
     * Stehen als Konstanten bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisungen prüfen kann, die hier auch ausgeführt werden.
     */
    public const MISSING_SIZE_IDS_SQL = 'SELECT id FROM attachments WHERE file_size IS NULL';

    public const FILL_SIZE_SQL = 'UPDATE attachments SET file_size = LENGTH(file_content) WHERE id = %d';

    public const MISSING_SIZES_SQL = <<<'SQL'
        SELECT COUNT(*) AS missing_sizes
        FROM attachments
        WHERE file_size IS NULL
        SQL;

    public function up(): void
    {
        foreach ($this->fetchAll(self::MISSING_SIZE_IDS_SQL) as $row) {
            $this->execute(sprintf(self::FILL_SIZE_SQL, (int) $row['id']));
        }

        $missing = (int) ($this->fetchRow(self::MISSING_SIZES_SQL)['missing_sizes'] ?? 0);

        if ($missing > 0) {
            throw new RuntimeException(sprintf(
                'Umstellung abgebrochen: %d Anhang/Anhänge haben weiterhin keine Größe. '
                    . 'Diese Zeilen zuerst prüfen, sonst verweigert die Spalte den Lauf.',
                $missing
            ));
        }

        $this->table('attachments')
            ->changeColumn('file_size', 'integer', [
                'null' => false,
                'signed' => true,
            ])
            ->update();
    }

    public function down(): void
    {
        // Keine Prüfung nötig: Der Rückbau weitet die Spalte und nimmt jeden Wert mit.
        $this->table('attachments')
            ->changeColumn('file_size', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ])
            ->update();
    }
}

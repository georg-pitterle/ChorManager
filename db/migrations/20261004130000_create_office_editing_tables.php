<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Office-Bearbeitung über Collabora (WOPI).
 *
 * `office_access_tokens`: Zugangstokens, mit denen Collabora Dateien holt und
 * speichert. Gespeichert wird nur der SHA-256-Hash.
 *
 * `file_versions.office_session_open` / `office_saved_at`: Eine Bearbeitungssitzung
 * ergibt eine Version. Solange die Sitzung offen ist, ersetzt jedes Speichern den
 * Inhalt dieser Version (unter neuem Pfad).
 *
 * `up()` und `down()` statt `change()`: Phinx spielt ein `change()` beim
 * Rückbau verkehrt herum nach und erzeugt aus dem `create()` selbst ein
 * `drop()`, vor dem keine Zählabfrage stehen kann. Ein noch gültiges Token ist
 * eine offene Bearbeitungssitzung - fällt es weg, kann Collabora nicht mehr
 * speichern und die Änderungen des Mitglieds sind fort. `/phinx-migration`
 * verlangt die Prüfung ausdrücklich auch im eigenen `down()`.
 */
final class CreateOfficeEditingTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('office_access_tokens')
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('file_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_office_access_tokens_hash'])
            ->addIndex(['expires_at'])
            ->addForeignKey('file_id', 'files', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_office_access_tokens_file',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_office_access_tokens_user',
            ])
            ->create();

        $this->table('file_versions')
            ->addColumn('office_session_open', 'boolean', ['null' => false, 'default' => false, 'after' => 'uploaded_by'])
            ->addColumn('office_saved_at', 'datetime', ['null' => true, 'after' => 'office_session_open'])
            ->update();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist mit dieser Migration
        // entstanden, ihr Inhalt aber im Betrieb. Gezählt wird alles, nicht nur
        // die gültigen Tokens - eine frische Installation hat gar keine, und
        // abgelaufene wegzuräumen kostet eine Zeile. Die Meldung sagt, was zu
        // tun ist.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM office_access_tokens'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: office_access_tokens enthält %d Token. Ein noch gültiges ist eine '
                    . 'offene Bearbeitungssitzung, deren Änderungen damit verloren gingen. Erst alle '
                    . 'Sitzungen schließen, dann die Tabelle bewusst selbst leeren.',
                $rowsLeft
            ));
        }

        $this->table('office_access_tokens')->drop()->update();

        $this->table('file_versions')
            ->removeColumn('office_saved_at')
            ->removeColumn('office_session_open')
            ->update();
    }
}

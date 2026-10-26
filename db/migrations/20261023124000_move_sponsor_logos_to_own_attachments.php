<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Mehrere Logo-Dateien je Sponsor.
 *
 * 20261023122000 sah genau ein Logo je Sponsor vor (sponsors.logo_attachment_id).
 * In der Praxis kommen Logos aber in vielen Formaten und Varianten, und wer sie
 * hochlädt, weiß oft nicht, welche der Grafiker braucht. Logo-Dateien sind
 * deshalb jetzt eigene Anhänge mit entity_type 'sponsor_logo', beliebig viele
 * je Sponsor und getrennt von Mediadaten und Verträgen.
 *
 * Das bisher verknüpfte Logo wird umgehängt, die Spalte entfällt.
 */
final class MoveSponsorLogosToOwnAttachments extends AbstractMigration
{
    /**
     * Sponsoren mit mehr als einer Logo-Datei: Der Rückbau kennt nur ein Logo
     * je Sponsor und könnte die übrigen nicht mehr als Logo kennzeichnen.
     */
    public const SPONSORS_WITH_SEVERAL_LOGOS_SQL = <<<'SQL'
        SELECT COUNT(*) AS rows_left FROM (
            SELECT entity_id FROM attachments
            WHERE entity_type = 'sponsor_logo'
            GROUP BY entity_id
            HAVING COUNT(*) > 1
        ) AS several
        SQL;

    public function up(): void
    {
        // `a.entity_id = s.id` steht mit in der Bedingung, obwohl die Spalte
        // schon über `a.id` verbunden ist: Zeigte eine Verknüpfung auf den
        // Anhang eines *anderen* Sponsors, würde dessen Datei sonst zum Logo
        // des verweisenden Sponsors umgeschrieben. Die Prüfung darunter findet
        // so eine Verknüpfung und bricht ab - dann aber erst, nachdem der
        // fremde Anhang schon angefasst wäre. Dass die Transaktion von Phinx
        // das zurücknimmt, ist richtig und soll die Absicht nicht ersetzen.
        $this->execute(
            "UPDATE attachments a
             JOIN sponsors s ON s.logo_attachment_id = a.id
             SET a.entity_type = 'sponsor_logo'
             WHERE a.entity_type = 'sponsor'
               AND a.entity_id = s.id"
        );

        // Prüfung vor dem Entfernen der Spalte: Jede Verknüpfung muss jetzt
        // auf eine umgehängte Logo-Datei zeigen. Zeigt eine auf etwas anderes,
        // ginge die Kennzeichnung mit der Spalte verloren.
        $unmoved = (int) ($this->fetchRow(
            "SELECT COUNT(*) AS rows_left FROM sponsors s
             LEFT JOIN attachments a ON a.id = s.logo_attachment_id
             WHERE s.logo_attachment_id IS NOT NULL
               AND (a.id IS NULL OR a.entity_type <> 'sponsor_logo' OR a.entity_id <> s.id)"
        )['rows_left'] ?? 0);
        if ($unmoved > 0) {
            throw new RuntimeException(sprintf(
                'Umbau abgebrochen: %d Sponsor(en) verweisen auf ein Logo, das nicht umgehängt werden '
                    . 'konnte. Diese Verknüpfungen zuerst prüfen.',
                $unmoved
            ));
        }

        $this->table('sponsors')
            ->dropForeignKey('logo_attachment_id')
            ->removeColumn('logo_attachment_id')
            ->update();
    }

    public function down(): void
    {
        // Prüfung vor dem Umbau: Mit mehreren Logo-Dateien je Sponsor gäbe es
        // für den Rückbau keine richtige Wahl, welche "das" Logo ist.
        $several = (int) ($this->fetchRow(self::SPONSORS_WITH_SEVERAL_LOGOS_SQL)['rows_left'] ?? 0);
        if ($several > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %d Sponsor(en) haben mehrere Logo-Dateien, die alte Spalte '
                    . 'kennt nur eine. Überzählige Logo-Dateien zuerst bewusst selbst entfernen.',
                $several
            ));
        }

        $this->table('sponsors')
            ->addColumn('logo_attachment_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
                'after' => 'website',
            ])
            ->addForeignKey('logo_attachment_id', 'attachments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();

        $this->execute(
            "UPDATE sponsors s
             JOIN attachments a ON a.entity_type = 'sponsor_logo' AND a.entity_id = s.id
             SET s.logo_attachment_id = a.id"
        );
        $this->execute("UPDATE attachments SET entity_type = 'sponsor' WHERE entity_type = 'sponsor_logo'");
    }
}

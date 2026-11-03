<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Umfragen bekommen ihre Zielgruppe über audience_filters, wie Termine. Der
 * CHECK auf genau einen Besitzer wird dafür mit der sechsten Spalte neu
 * angelegt.
 */
final class AddPollOwnerToAudienceFilters extends AbstractMigration
{
    private const OWNER_COUNT_WITHOUT_POLL = '(event_id IS NOT NULL) + (newsletter_id IS NOT NULL)'
        . ' + (newsletter_template_id IS NOT NULL) + (file_folder_share_id IS NOT NULL)'
        . ' + (file_share_id IS NOT NULL)';

    private const OWNER_COUNT_WITH_POLL = self::OWNER_COUNT_WITHOUT_POLL . ' + (poll_id IS NOT NULL)';

    public function up(): void
    {
        // polls.id kommt von Phinx und ist unsigned.
        $this->table('audience_filters')
            ->addColumn('poll_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['poll_id'], ['name' => 'idx_audience_filters_poll_id'])
            ->addForeignKey('poll_id', 'polls', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_audience_filters_poll_id',
            ])
            ->update();

        $this->execute('ALTER TABLE audience_filters DROP CONSTRAINT chk_audience_filters_single_owner');
        $this->execute(
            'ALTER TABLE audience_filters ADD CONSTRAINT chk_audience_filters_single_owner CHECK ('
            . self::OWNER_COUNT_WITH_POLL . ' = 1)'
        );
    }

    public function down(): void
    {
        // Filter einer Umfrage hätten ohne die Spalte keinen Besitzer mehr und
        // verletzten den alten CHECK; ihre Zielgruppe stünde nirgends sonst.
        $pollFilters = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM audience_filters WHERE poll_id IS NOT NULL'
        )['rows_left'] ?? 0);
        if ($pollFilters > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %d Zielgruppen-Filter gehören zu Umfragen. Diese zuerst bewusst selbst entfernen.',
                $pollFilters
            ));
        }

        $this->execute('ALTER TABLE audience_filters DROP CONSTRAINT chk_audience_filters_single_owner');
        $this->execute(
            'ALTER TABLE audience_filters ADD CONSTRAINT chk_audience_filters_single_owner CHECK ('
            . self::OWNER_COUNT_WITHOUT_POLL . ' = 1)'
        );

        $this->table('audience_filters')->dropForeignKey('poll_id')->update();
        $this->table('audience_filters')
            ->removeIndexByName('idx_audience_filters_poll_id')
            ->removeColumn('poll_id')
            ->update();
    }
}

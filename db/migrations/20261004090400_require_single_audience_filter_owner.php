<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Jeder Zielgruppen-Filter gehört genau einem Besitzer. Ohne Besitzer räumt
 * ihn kein Fremdschlüssel mehr ab; mit zweien wäre unklar, wessen Zielgruppe
 * er ist. Erst jetzt möglich, nachdem alle Module umgestellt sind.
 */
final class RequireSingleAudienceFilterOwner extends AbstractMigration
{
    private const OWNER_COUNT = '(event_id IS NOT NULL) + (newsletter_id IS NOT NULL)'
        . ' + (newsletter_template_id IS NOT NULL) + (file_folder_share_id IS NOT NULL)'
        . ' + (file_share_id IS NOT NULL)';

    public function up(): void
    {
        $broken = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM audience_filters WHERE ' . self::OWNER_COUNT . ' <> 1'
        )['n'] ?? 0);
        if ($broken > 0) {
            throw new RuntimeException(sprintf(
                '%d Zielgruppen-Filter ohne oder mit mehreren Besitzern - CHECK nicht angelegt.',
                $broken
            ));
        }

        $this->execute(
            'ALTER TABLE audience_filters ADD CONSTRAINT chk_audience_filters_single_owner CHECK ('
            . self::OWNER_COUNT . ' = 1)'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE audience_filters DROP CONSTRAINT chk_audience_filters_single_owner');
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RemoveSmtpSettingsFromAppSettings extends AbstractMigration
{
    public function up(): void
    {
        $keys = [
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_from_email',
            'smtp_from_name',
        ];

        $quotedKeys = array_map(
            fn(string $key): string => $this->getAdapter()->getConnection()->quote($key),
            $keys
        );

        $this->execute(
            sprintf(
                'DELETE FROM app_settings WHERE setting_key IN (%s)',
                implode(', ', $quotedKeys)
            )
        );
    }

    public function down(): void
    {
        // No-op: sensitive SMTP values are intentionally not recreated.
    }
}

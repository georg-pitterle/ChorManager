<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Brücke zwischen ChorManager und einer angeschlossenen Anwendung.
 *
 * `users.external_uid` ist die Kennung, unter der ein Mitglied dort geführt
 * wird. Sie ist nötig, weil einige Konten in der Nextcloud-Instanz schon vor
 * der Anbindung bestanden - ohne die Zuordnung von Hand entstünde daneben ein
 * zweites, leeres Konto. Wer keine hat, bekommt im Anmeldezeugnis die stabile
 * Form `cm-<id>`.
 *
 * `roles.external_group` ist die Gruppe, die einer Rolle drüben entspricht. Nur
 * Rollen mit gesetztem Wert wandern in den `groups`-Anspruch; es gibt bewusst
 * keinen Rückfall auf den Rollennamen, sonst zerlegte ein Umbenennen in
 * ChorManager die Freigaben in der anderen Anwendung.
 *
 * Beide Spalten heißen `external_*` und nicht `nextcloud_*`: Der Provider kann
 * später weitere Clients bedienen, die Bedeutung bleibt dieselbe.
 */
final class AddExternalIdentityColumns extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('external_uid', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addIndex(['external_uid'], ['unique' => true])
            ->update();

        $this->table('roles')
            ->addColumn('external_group', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeIndex(['external_uid'])
            ->removeColumn('external_uid')
            ->update();

        $this->table('roles')
            ->removeColumn('external_group')
            ->update();
    }
}

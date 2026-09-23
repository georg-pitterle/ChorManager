<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gibt dem Merkzettel der Erinnerungen einen Index auf created_at.
 *
 * notification_dispatch_log wächst mit jeder verschickten Erinnerung und wurde
 * nie aufgeräumt. Der eindeutige Index aus 20260830141000 beginnt mit
 * notification_type und beantwortet deshalb keine Frage nach dem Alter - ein
 * Aufräumlauf hätte die ganze Tabelle lesen müssen, also genau das, was mit
 * ihrer Größe immer teurer wird.
 *
 * Aufgeräumt wird ab jetzt von NotificationReminderService::pruneExpired(), das
 * im selben Lauf wie das Verschicken läuft. Der Index ist die Voraussetzung
 * dafür, dass das ein Zugriff über einen Bereich bleibt und kein voller
 * Tabellenlauf.
 *
 * Die Sperre gegen Doppelmails hängt nicht am Alter, sondern am eindeutigen
 * Index. Ein Eintrag, der älter ist als jedes Fälligkeitsfenster, kann keine
 * zweite Mail mehr verhindern - er sagt nur noch, dass vor über einem Jahr
 * einmal eine raus ist.
 */
final class AddCreatedAtIndexToNotificationDispatchLog extends AbstractMigration
{
    private const INDEX = 'idx_notification_dispatch_log_created_at';

    public function up(): void
    {
        $this->table('notification_dispatch_log')
            ->addIndex(['created_at'], ['name' => self::INDEX])
            ->update();
    }

    public function down(): void
    {
        $this->table('notification_dispatch_log')
            ->removeIndexByName(self::INDEX)
            ->update();
    }
}

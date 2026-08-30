<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Merkzettel der termingesteuerten Erinnerungen.
 *
 * Die Anmelde-Erinnerung löst dasselbe Problem mit einer Spalte am Termin
 * (`events.registration_reminder_sent_at`) und einem bedingten Update als
 * Sperre. Für Aufgaben und Wiedervorlagen bräuchte es dafür zwei weitere
 * Spalten an zwei weiteren Tabellen - und die Sperre säße jeweils am Objekt,
 * nicht am Empfänger.
 *
 * Hier steht sie am Paar aus Objekt und Empfänger, und der eindeutige Index
 * macht "genau einmal" zu einer Eigenschaft der Datenbank statt zu einem
 * Wettlauf zwischen zwei Workern: Der zweite Versuch scheitert am Index,
 * bevor eine Mail entsteht.
 *
 * `dispatch_key` trägt den Anlass des Laufs, in der Regel das Fälligkeitsdatum.
 * Verschiebt jemand die Aufgabe, ist es ein anderer Schlüssel und der nächste
 * Lauf erinnert erneut - ohne dass irgendwo etwas aufgeräumt werden müsste.
 */
final class CreateNotificationDispatchLog extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_dispatch_log')
            ->addColumn('notification_type', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('entity_type', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('entity_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('dispatch_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(
                ['notification_type', 'entity_type', 'entity_id', 'user_id', 'dispatch_key'],
                ['unique' => true, 'name' => 'uq_notification_dispatch']
            )
            // Verlässt jemand den Chor, gehen seine Merkzettel mit - sie sagen
            // nichts mehr aus und hielten sonst den Fremdschlüssel fest.
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('notification_dispatch_log')->drop()->save();
    }
}

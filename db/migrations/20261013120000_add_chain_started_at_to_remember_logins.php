<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Angemeldet bleiben" bekommt eine absolute Höchstdauer.
 *
 * Bisher war die Frist gleitend: Jeder Besuch tauschte das Token und setzte
 * `expires_at` neu auf REMEMBER_ME_DAYS. Ein regelmäßig benutztes Gerät blieb
 * damit unbegrenzt angemeldet - die 30 Tage waren in Wahrheit "30 Tage
 * Untätigkeit", keine Obergrenze. Ein Tablet, das jahrelang im Probenraum
 * liegt, war so ein dauerhaft offener Zugang.
 *
 * Die Obergrenze braucht einen Bezugspunkt, der den Tokentausch überlebt. Die
 * bisherigen Spalten taugen dafür nicht: `created_at` gehört zum einzelnen
 * Token und beginnt bei jeder Drehung neu, und die alte Zeile wird beim Tausch
 * gelöscht. `chain_started_at` hält deshalb den Beginn der ganzen Kette -
 * gesetzt bei der Anmeldung, von jeder Drehung unverändert übernommen.
 *
 * Bestehende Zeilen erben `created_at`. Das ist der ehrlichste verfügbare Wert:
 * Wann die Kette wirklich begann, steht nirgends mehr, und `created_at` ist
 * nicht später als dieser Zeitpunkt. Wer gerade angemeldet ist, bekommt damit
 * ab heute die vollen REMEMBER_ME_MAX_DAYS statt rückwirkend gesperrt zu
 * werden.
 */
final class AddChainStartedAtToRememberLogins extends AbstractMigration
{
    public function up(): void
    {
        // Erst nullbar anlegen, damit die bestehenden Zeilen einen Wert bekommen
        // können, bevor die Spalte eng gemacht wird. Ohne diesen Zwischenschritt
        // setzte MySQL für sie den Nullwert des Typs ("0000-00-00 00:00:00") und
        // jede bestehende Anmeldung wäre auf einen Schlag über der Obergrenze.
        $this->table('remember_logins')
            ->addColumn('chain_started_at', 'datetime', [
                'null' => true,
                'after' => 'created_at',
            ])
            ->update();

        $this->execute(
            'UPDATE remember_logins SET chain_started_at = created_at WHERE chain_started_at IS NULL'
        );

        $this->table('remember_logins')
            ->changeColumn('chain_started_at', 'datetime', ['null' => false])
            ->update();
    }

    public function down(): void
    {
        // Keine Zählabfrage davor: Die Spalte entsteht in `up()` dieser
        // Migration, und ihre Werte sind Abschriften von `created_at`, das
        // stehen bleibt. Der Rückbau nimmt eine Erzeugung zurück und vernichtet
        // nichts, was es vorher gab.
        $this->table('remember_logins')
            ->removeColumn('chain_started_at')
            ->update();
    }
}

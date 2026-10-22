<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `attendance.status` wird ein ENUM.
 *
 * Die Spalte war ein `varchar(50)`, während jede vergleichbare Statusspalte im
 * Projekt ein ENUM ist. `Attendance::STATUS_*` nannte vier Werte, die Datenbank
 * nahm aber jede Zeichenkette an: Ein Tippfehler an einer Schreibstelle wäre als
 * eigener Status in der Tabelle gelandet, hätte durch jede Auswertung
 * hindurchgefallen und niemandem einen Fehler gezeigt. Aufgefallen ist das im
 * rotierenden Review-Lauf 51.
 *
 * `unknown` gehört bewusst dazu: Es ist kein Platzhalter für "noch nichts
 * erfasst" - normalerweise existiert dann gar keine Zeile -, sondern der
 * Zustand einer Zeile, die nur wegen einer Notiz bestehen bleibt.
 */
final class RequireKnownAttendanceStatus extends AbstractMigration
{
    /** Die vier zulässigen Werte, in der Reihenfolge von `Attendance::SUPPORTED_STATUSES`. */
    private const STATUSES = "'present','excused','unexcused','unknown'";

    /**
     * Zählt die Erfassungen, die keinen der vier Werte tragen.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * AddChannelToUserNotificationSettings::BELL_SETTINGS_SQL.
     */
    public const UNKNOWN_STATUSES_SQL = <<<'SQL'
        SELECT COUNT(*) AS unknown_statuses
        FROM attendance
        WHERE status NOT IN ('present', 'excused', 'unexcused', 'unknown')
        SQL;

    public function up(): void
    {
        // Prüfung vor dem verengenden Schritt: Ein Wert, den das ENUM nicht
        // kennt, wird vom ALTER nicht abgewiesen, sondern je nach Modus zur
        // leeren Zeichenkette - die Erfassung wäre dann stillschweigend weg,
        // und zurückholen ließe sie sich aus nichts. Der Lauf bricht deshalb ab
        // und nennt die Zahl; wer solche Zeilen hat, richtet sie zuerst bewusst
        // selbst.
        $unknown = (int) ($this->fetchRow(self::UNKNOWN_STATUSES_SQL)['unknown_statuses'] ?? 0);

        if ($unknown > 0) {
            throw new RuntimeException(sprintf(
                'Umstellung abgebrochen: %d Erfassung(en) tragen einen Status außerhalb von '
                    . 'present/excused/unexcused/unknown. Das ENUM würde sie zur leeren Zeichenkette '
                    . 'machen. Diese Zeilen zuerst bewusst selbst richten.',
                $unknown
            ));
        }

        $this->execute(
            'ALTER TABLE attendance MODIFY status ENUM(' . self::STATUSES . ") NOT NULL DEFAULT 'unknown'"
        );
    }

    public function down(): void
    {
        // Keine Prüfung nötig: Der Rückbau weitet die Spalte und nimmt jeden
        // Wert mit, der im ENUM stand.
        $this->execute('ALTER TABLE attendance MODIFY status VARCHAR(50) NOT NULL');
    }
}

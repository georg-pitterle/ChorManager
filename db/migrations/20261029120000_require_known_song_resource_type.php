<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `song_resources.resource_type` wird ein ENUM.
 *
 * Die Spalte war ein `varchar(32)` mit genau einem gültigen Wert, während jede
 * vergleichbare Artspalte im Projekt ein ENUM ist. `SongResource::TYPE_LINK`
 * nannte den Wert, die Datenbank nahm aber jede Zeichenkette an. Gleicher
 * Befund und gleiche Begründung wie bei `attendance.status` in
 * 20261022120000: Ein Tippfehler an einer Schreibstelle landete als eigene Art
 * in der Tabelle, und beide Richtungen schwiegen - geschrieben wurde eine
 * Quelle, die keine Leseseite je findet, gelesen wurde eine leere Liste.
 * Aufgefallen im rotierenden Review-Lauf 58, entschieden in der Nacharbeit
 * dazu.
 *
 * Nur `link`: Mehr Arten gibt es nicht, und eine zweite vorsorglich ins ENUM zu
 * schreiben würde nur eine Grenze aufweichen, die gerade erst entsteht. Kommt
 * eine dazu, nennt sie eine eigene Migration zusammen mit
 * `SongResource::RESOURCE_TYPES`.
 */
final class RequireKnownSongResourceType extends AbstractMigration
{
    /** Der einzige zulässige Wert, wie `SongResource::RESOURCE_TYPES` ihn nennt. */
    private const TYPES = "'link'";

    /**
     * Zählt die Quellen, die eine andere Art tragen.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * RequireKnownAttendanceStatus::UNKNOWN_STATUSES_SQL.
     */
    public const UNKNOWN_TYPES_SQL = <<<'SQL'
        SELECT COUNT(*) AS unknown_types
        FROM song_resources
        WHERE resource_type NOT IN ('link')
        SQL;

    public function up(): void
    {
        // Prüfung vor dem verengenden Schritt: Eine Art, die das ENUM nicht
        // kennt, wird vom ALTER nicht abgewiesen, sondern je nach Modus zur
        // leeren Zeichenkette - die Quelle hinge dann an keiner Art mehr und
        // wäre über die Oberfläche nicht wiederzufinden. Der Lauf bricht
        // deshalb ab und nennt die Zahl.
        $unknown = (int) ($this->fetchRow(self::UNKNOWN_TYPES_SQL)['unknown_types'] ?? 0);

        if ($unknown > 0) {
            throw new RuntimeException(sprintf(
                'Umstellung abgebrochen: %d Liedquelle(n) tragen eine Art außerhalb von link. '
                    . 'Das ENUM würde sie zur leeren Zeichenkette machen. Diese Zeilen zuerst '
                    . 'bewusst selbst richten.',
                $unknown
            ));
        }

        $this->execute(
            'ALTER TABLE song_resources MODIFY resource_type ENUM(' . self::TYPES . ') NOT NULL'
        );
    }

    public function down(): void
    {
        // Keine Prüfung nötig: Der Rückbau weitet die Spalte und nimmt jeden
        // Wert mit, der im ENUM stand.
        $this->execute('ALTER TABLE song_resources MODIFY resource_type VARCHAR(32) NOT NULL');
    }
}

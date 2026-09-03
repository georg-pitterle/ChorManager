<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entfernt den letzten Klartext aus calendar_subscription_tokens.
 *
 * 20260825120300 hat das Hashen eingeführt, den Klartext bereits verteilter
 * Abos aber stehen lassen, damit sie weiterlaufen. Damit gibt ein
 * Datenbank-Auszug für genau diese Zeilen weiterhin sofort benutzbare
 * Abo-Adressen her - das Token ist ein Bearer-Token, wer es hat, sieht ohne
 * Anmeldung die Termine des Mitglieds.
 *
 * Der Klartext muss dafür nicht verworfen werden: Der Hash ist ein einfaches
 * SHA-256 ohne Salt, und `SHA2(token, 256)` liefert in MySQL exakt dasselbe wie
 * `hash('sha256', ...)` in PHP. Die Altbestände werden deshalb in ihren Hash
 * umgerechnet, bevor die Spalte fällt - die Abos laufen unverändert weiter,
 * niemand muss sein Abo neu anlegen.
 */
final class DropPlaintextCalendarSubscriptionTokens extends AbstractMigration
{
    /**
     * Überführt Altbestände in den Hash. Zeilen, die beides tragen, sind bereits
     * umgestellt und bleiben unangetastet.
     *
     * Steht als Konstante bereit, damit CalendarTokenHashBackfillTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * RepairFinanceAccountOpeningData::REPAIR_SQL.
     */
    public const BACKFILL_SQL = <<<'SQL'
        UPDATE calendar_subscription_tokens
        SET token_hash = SHA2(token, 256)
        WHERE token_hash IS NULL
          AND token IS NOT NULL
        SQL;

    public function up(): void
    {
        $this->execute(self::BACKFILL_SQL);

        // Zeilen ohne beides sind über keinen Weg mehr auffindbar - weder über
        // den Hash noch über den Klartext. Sie waren schon vor dieser Migration
        // wertlos und würden die Prüfung darunter sonst dauerhaft blockieren.
        $this->execute(
            'DELETE FROM calendar_subscription_tokens
             WHERE token_hash IS NULL
               AND token IS NULL'
        );

        // Prüfung vor dem destruktiven Schritt: Bleibt eine Zeile ohne Hash,
        // verlöre sie mit der Spalte ihren einzigen Zugang. Der Abbruch lässt
        // den Klartext stehen, statt das Abo still unbrauchbar zu machen.
        $withoutHash = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS without_hash
             FROM calendar_subscription_tokens
             WHERE token_hash IS NULL'
        )['without_hash'] ?? 0);

        if ($withoutHash > 0) {
            throw new RuntimeException(sprintf(
                'Es gibt %d Kalender-Abo(s) ohne Hash. Die Klartext-Spalte bleibt deshalb erhalten.',
                $withoutHash
            ));
        }

        $this->table('calendar_subscription_tokens')
            ->removeIndexByName('token')
            ->removeColumn('token')
            ->update();
    }

    public function down(): void
    {
        // Die Spalte kommt leer zurück: Aus dem Hash lässt sich der Klartext
        // nicht zurückrechnen. Das ist folgenlos - seit 20260825120300 findet
        // der Feed jedes Abo über den Hash, und genau den tragen nach dieser
        // Migration alle Zeilen.
        $this->table('calendar_subscription_tokens')
            ->addColumn('token', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'after' => 'user_id',
            ])
            ->addIndex(['token'], ['unique' => true, 'name' => 'token'])
            ->update();
    }
}

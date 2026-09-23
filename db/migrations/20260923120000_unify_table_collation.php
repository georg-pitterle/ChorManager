<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zieht die Tabellen der Phinx-API auf die Kollation des übrigen Schemas nach.
 *
 * Das Schema war bisher zweigeteilt, ohne dass das je jemand entschieden hätte:
 *
 * - Was über rohes `CREATE TABLE` entstand, trägt `utf8mb4_general_ci` - die
 *   Ursprungsmigration schreibt es dreißigmal ausdrücklich hin.
 * - Was über `$this->table(...)` entstand, trägt `utf8mb4_unicode_ci`. Diese
 *   Vorgabe kommt aus Phinx selbst (`MysqlAdapter::$options['collation']`), und
 *   `phinx.php` gab bisher nur `charset` vor, also blieb sie stehen.
 *
 * Vierzig Tabellen standen so auf der einen, vierzehn auf der anderen Seite.
 *
 * Folgenreich ist das, weil MySQL den Vergleich zweier Textspalten
 * unterschiedlicher Kollation nicht ausführt, sondern abbricht:
 *
 *     SELECT u.email FROM users u
 *     JOIN mail_queue m ON m.recipient_email = u.email
 *     -- ERROR 1267: Illegal mix of collations
 *
 * Kein heutiger Codepfad tut das - geprüft wurde jeder JOIN über die Grenze.
 * Der erste, der es täte, fiele aber erst zur Laufzeit auf, und zwar dort, wo
 * die Abfrage steht, nicht dort, wo die Ursache liegt.
 *
 * Dazu kommt die stille Bedeutungsverschiebung: `unicode_ci` hält 'ß' und 'ss'
 * für denselben Text, `general_ci` nicht. Ein eindeutiger Index auf einen Namen
 * bedeutete damit in `finance_accounts` etwas anderes als in `voice_groups`.
 *
 * ## Warum general_ci und nicht unicode_ci
 *
 * Nicht, weil es die bessere Kollation wäre - für deutschen Text ist sie es
 * nicht. Sondern weil sie die einzige ist, die hier je bewusst gewählt wurde,
 * und weil nur diese Richtung gefahrlos ist: `general_ci` unterscheidet mehr
 * Zeichenfolgen als `unicode_ci`. Zeilen, die unter `unicode_ci` in einen
 * eindeutigen Index passten, passen danach erst recht hinein.
 *
 * Umgekehrt wäre es ein Eingriff in Daten: Ein Chor mit beiden Schreibweisen
 * von "Straße" in derselben Spalte verlöre den Index-Aufbau mitten im Lauf. Ob
 * das Schema stattdessen ganz auf `unicode_ci` wandern soll, ist deshalb eine
 * Entscheidung mit Datenprüfung davor - nicht eine, die eine Aufräum-Migration
 * nebenbei trifft.
 */
final class UnifyTableCollation extends AbstractMigration
{
    private const TARGET_COLLATION = 'utf8mb4_general_ci';
    private const PREVIOUS_COLLATION = 'utf8mb4_unicode_ci';

    /**
     * Die Tabellen, die über die Phinx-API entstanden sind. Ausgeschrieben statt
     * zur Laufzeit ermittelt, damit im Diff steht, was die Migration anfasst.
     * Die Prüfung darunter fängt ab, falls eine Installation davon abweicht.
     *
     * `phinxlog` fehlt bewusst: Die Tabelle gehört Phinx, trägt keine
     * Anwendungsdaten und wird von keinem JOIN berührt.
     *
     * @var list<string>
     */
    private const PHINX_CREATED_TABLES = [
        'calendar_subscription_tokens',
        'event_audience_sources',
        'event_registrations',
        'finance_accounts',
        'finance_revisions',
        'invitation_tokens',
        'mail_delivery_events',
        'mail_queue',
        'newsletter_recipient_sources',
        'newsletter_template_recipient_sources',
        'notification_dispatch_log',
        'task_assignees',
        'user_notification_settings',
        'webdav_access_tokens',
    ];

    public function up(): void
    {
        $this->convertAll(self::TARGET_COLLATION);

        // Prüfung nach dem Umbau, nicht davor: Hier geht nichts verloren, was
        // eine Prüfung noch retten könnte - `CONVERT TO` bleibt innerhalb von
        // utf8mb4 und schreibt nur die Sortierregel um. Was offenbleibt, ist die
        // Vollständigkeit, und die lässt sich erst danach feststellen.
        $this->assertNoTableDeviates(self::TARGET_COLLATION);
    }

    /**
     * Die Rückrichtung stellt nur die vierzehn Tabellen zurück, die up()
     * angefasst hat. Die übrigen vierzig standen nie auf `unicode_ci`.
     */
    public function down(): void
    {
        $this->convertAll(self::PREVIOUS_COLLATION);
    }

    private function convertAll(string $collation): void
    {
        foreach (self::PHINX_CREATED_TABLES as $table) {
            if (!$this->hasTable($table)) {
                continue;
            }

            $this->execute(sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE %s',
                $table,
                $collation
            ));
        }
    }

    /**
     * Fängt Installationen ab, in denen eine Tabelle außerhalb der Liste
     * ausschert - etwa weil sie von Hand angelegt wurde. Ohne diese Prüfung
     * bliebe die Zweiteilung bestehen, und die Migration meldete trotzdem
     * Erfolg.
     */
    private function assertNoTableDeviates(string $collation): void
    {
        $rows = $this->fetchAll(sprintf(
            "SELECT TABLE_NAME, TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_NAME <> 'phinxlog'
               AND TABLE_COLLATION <> '%s'
             ORDER BY TABLE_NAME",
            $collation
        ));

        if ($rows === []) {
            return;
        }

        $names = array_map(
            static fn (array $row): string => sprintf('%s (%s)', $row['TABLE_NAME'], $row['TABLE_COLLATION']),
            $rows
        );

        throw new RuntimeException(sprintf(
            'Diese Tabellen stehen weiterhin nicht auf %s: %s. Sie gehören in PHINX_CREATED_TABLES '
                . 'oder wurden außerhalb der Migrationen angelegt.',
            $collation,
            implode(', ', $names)
        ));
    }
}

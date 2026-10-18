<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Letzte Anmeldung und Versand der Einladung am Mitglied.
 *
 * Ob jemand eine Einladung angenommen hat, ließ sich bisher nur erraten: Der
 * Einladungstoken verschwindet beim Einlösen, aber ebenso beim Aufruf eines
 * abgelaufenen Links, und ob die Mail je beim Mailserver ankam, stand nur in
 * der Warteschlange, verknüpft über eine E-Mail-Adresse, die sich ändern kann.
 *
 * `invitation_sent_at` wird aus der Warteschlange nachgetragen, soweit sie
 * angenommene Einladungen noch kennt; die Mitglieder-ID steht in deren
 * `payload_json`. Für `last_login_at` gibt es keine verlässliche Quelle - die
 * Spalte beginnt leer und füllt sich mit der nächsten Anmeldung.
 */
final class AddActivityTimestampsToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('last_login_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('invitation_sent_at', 'datetime', ['null' => true, 'default' => null])
            ->update();

        $this->execute(
            "UPDATE users u
             JOIN (
                 SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.user_id')) AS UNSIGNED) AS user_id,
                        MAX(sent_at) AS sent_at
                 FROM mail_queue
                 WHERE mail_type = 'invitation'
                   AND status = 'sent'
                   AND sent_at IS NOT NULL
                   AND JSON_VALID(payload_json)
                 GROUP BY user_id
             ) sent ON sent.user_id = u.id
             SET u.invitation_sent_at = sent.sent_at"
        );
    }

    public function down(): void
    {
        // Keine Zählabfrage davor: Beide Spalten entstehen in `up()` dieser
        // Migration. Der Rückbau nimmt eine Erzeugung zurück und vernichtet
        // nichts, was es vorher gab.
        $this->table('users')
            ->removeColumn('invitation_sent_at')
            ->removeColumn('last_login_at')
            ->update();
    }
}

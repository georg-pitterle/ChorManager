<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Das Kalender-Token ist ein Bearer-Token: Wer es hat, sieht ohne Anmeldung die
 * Terminliste des Mitglieds. Es lag als Klartext in der Tabelle, ein
 * Datenbank-Auszug gab damit sofort funktionierende Abo-Adressen her. Alle
 * anderen Token-Tabellen im Projekt speichern längst nur den Hash
 * (invitation_tokens, remember_logins, password_resets).
 *
 * Neue Token werden ab hier nur noch als SHA-256 abgelegt; token bleibt für
 * bereits verteilte Abos bestehen und wird deshalb nullable statt gelöscht.
 * Der Nachschlag im Feed prüft erst den Hash und fällt für Altbestände auf den
 * Klartext zurück - laufende Abos brechen nicht.
 *
 * SHA-256 ohne Salt und ohne Streckung ist hier richtig: Der Token ist ein
 * Zufallswert mit 256 Bit Entropie, kein Passwort. Ein deterministischer Hash
 * ist nötig, damit der Feed die Zeile über einen Index finden kann.
 */
final class HashCalendarSubscriptionTokens extends AbstractMigration
{
    private const INDEX = 'uq_calendar_subscription_tokens_token_hash';

    public function up(): void
    {
        $this->table('calendar_subscription_tokens')
            ->addColumn('token_hash', 'char', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'after' => 'token',
            ])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => self::INDEX])
            ->update();

        // token darf ab jetzt leer bleiben - neue Zeilen tragen nur noch den Hash.
        // MySQL erlaubt beliebig viele NULL-Werte in einem UNIQUE-Index, der
        // bestehende Index auf token bleibt deshalb unverändert nutzbar.
        $this->table('calendar_subscription_tokens')
            ->changeColumn('token', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->update();
    }

    public function down(): void
    {
        // Prüfung vor dem destruktiven Schritt: Zeilen ohne Klartext sind nach
        // dieser Migration entstanden. Ihr Token liegt nur als Hash vor und ist
        // nicht rekonstruierbar - token wieder auf NOT NULL zu setzen würde sie
        // still auf einen leeren String ziehen und die Abos unbrauchbar machen.
        $hashOnlyRows = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS hash_only FROM calendar_subscription_tokens WHERE token IS NULL'
        )['hash_only'] ?? 0);

        if ($hashOnlyRows > 0) {
            throw new \RuntimeException(sprintf(
                'Rollback blocked: %d Kalender-Token liegen nur als Hash vor. '
                    . 'Diese Zeilen zuerst löschen, die Mitglieder erzeugen ihr Abo danach neu.',
                $hashOnlyRows
            ));
        }

        $this->table('calendar_subscription_tokens')
            ->changeColumn('token', 'string', ['limit' => 64, 'null' => false])
            ->update();

        $this->table('calendar_subscription_tokens')
            ->removeIndexByName(self::INDEX)
            ->removeColumn('token_hash')
            ->update();
    }
}

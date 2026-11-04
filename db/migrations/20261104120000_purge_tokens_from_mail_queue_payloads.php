<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entfernt den Einmal-Link aus den Nutzlasten der Mail-Warteschlange.
 *
 * MailQueueService schrieb den Token einer Passwort-Zurücksetzung und einer
 * Einladung zusätzlich nach `mail_queue.payload_json`. Für neue Mails ist das
 * behoben - die Methoden nehmen ihn nicht mehr an. In den Zeilen, die vorher
 * entstanden sind, steht er weiter, und zwar unbegrenzt: Anders als `body_html`,
 * das MailDeliveryService nach dem Zustellen leert, hat diese Spalte nie etwas
 * verloren.
 *
 * Warum das zählt: Ein Einladungs-Token läuft lange, teils gar nicht ab. Wer das
 * Recht "Mailversand verwalten" hat oder einen Datenbank-Auszug liest, bekommt
 * damit einen benutzbaren Zugang zu einem fremden Konto - auch zu einem mit mehr
 * Rechten. Für Passwort-Tokens ist die Frist kurz, der Wert also meist schon
 * verfallen; nachgesehen wird trotzdem in derselben Anweisung, weil beide
 * Schlüssel aus derselben Quelle stammen.
 *
 * Gelöscht wird ohne Zählprüfung davor, und das ist hier die Ausnahme, nicht die
 * Regel: Geprüft würde sonst, ob Werte verloren gehen - genau das ist der Zweck.
 * Der Wert ist nachweislich ohne Nutzen, niemand liest ihn: Der gültige Token
 * steht in `password_resets` beziehungsweise `invitation_tokens`, und aus der
 * Nutzlast greift sich nur `user_id`, `newsletter_id` und `recipient_id` jemand
 * ab (MailDeliveryService::recordInvitationSent, attachmentsFor,
 * syncNewsletterRecipient). Dieselbe Begründung wie in
 * 20260901122000_drop_plaintext_calendar_subscription_tokens.
 *
 * Alles andere in der Nutzlast bleibt stehen - `user_id` wird gebraucht, und
 * Empfänger, Betreff und Status sind für die Fehlersuche da.
 */
final class PurgeTokensFromMailQueuePayloads extends AbstractMigration
{
    /**
     * Nimmt die zwei Schlüssel heraus und lässt den Rest der Nutzlast, wie er ist.
     *
     * Die drei Bedingungen sind jede für sich nötig: `mail_type` grenzt auf die
     * zwei Mailarten ein, die den Token je getragen haben. `JSON_VALID` hält
     * Zeilen heraus, deren Spalte kein lesbares JSON enthält - `JSON_REMOVE`
     * liefert darauf NULL und hätte die Nutzlast ganz gelöscht.
     * `JSON_CONTAINS_PATH` fasst nur Zeilen an, in denen wirklich einer der
     * Schlüssel steht, damit der Lauf die übrigen nicht umformatiert.
     *
     * Steht als Konstante bereit, damit MailQueuePayloadTokenPurgeTest genau die
     * Anweisung gegen eine Wegwerf-Tabelle prüfen kann, die hier auch läuft -
     * gleiches Muster wie DropPlaintextCalendarSubscriptionTokens::BACKFILL_SQL.
     */
    public const PURGE_SQL = <<<'SQL'
        UPDATE mail_queue
        SET payload_json = JSON_REMOVE(payload_json, '$.reset_token', '$.invitation_token')
        WHERE mail_type IN ('password_reset', 'invitation')
          AND payload_json IS NOT NULL
          AND JSON_VALID(payload_json)
          AND JSON_CONTAINS_PATH(payload_json, 'one', '$.reset_token', '$.invitation_token')
        SQL;

    public function up(): void
    {
        $this->execute(self::PURGE_SQL);
    }

    public function down(): void
    {
        // Der Rückbau tut nichts, und das ist die einzige richtige Antwort: Ein
        // Einmal-Link lässt sich nicht zurückholen, und niemand soll ihn
        // zurückhaben. Die Spalte selbst ist unverändert, es gibt also keine
        // Struktur, die ein Rückbau wiederherstellen müsste. Ein Abbruch mit
        // Fehler wäre falsch - er würde den Rückbau jeder späteren Migration
        // mit aufhalten, obwohl hier nichts fehlt.
    }
}

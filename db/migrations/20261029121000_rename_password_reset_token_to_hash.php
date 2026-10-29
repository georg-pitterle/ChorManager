<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `password_resets.token` heißt `token_hash`.
 *
 * In der Spalte stand nie der Token des Zurücksetzen-Links, sondern sein
 * bcrypt-Hash: `PasswordResetController` legt ihn mit `PasswordHasher::hash()`
 * an und löst ihn mit `password_verify()` ein, der Klartext verlässt die Mail
 * nie. Jede andere Token-Tabelle nennt diese Spalte deshalb `token_hash` -
 * `invitation_tokens`, `remember_logins`, `calendar_subscription_tokens`,
 * `webdav_access_tokens`, `office_access_tokens`, `file_public_links`.
 *
 * Der alte Name las sich wie ein Klartextspeicher, und genau daran hing auch der
 * falsche Kommentar an `PasswordReset::$hidden`, den Lauf 58 gefunden hat: Wer
 * den Namen glaubt, hält die Spalte für ein Versäumnis und "repariert" sie.
 * Entschieden in der Nacharbeit zu Lauf 58.
 *
 * Betrieb: Ein Zurücksetzen-Link, der im Augenblick des Ausrollens unterwegs
 * ist, lässt sich danach nicht mehr einlösen - zwischen dem ALTER und dem neuen
 * Code liest die alte Fassung eine Spalte, die es nicht mehr gibt. Die Links
 * laufen nach zwei Stunden ohnehin ab; betroffen ist also nur, wer in diesem
 * Fenster gerade einen angefordert hat, und er fordert ihn neu an.
 *
 * Kein Datenverlust: `renameColumn` behält Inhalt, Typ und Schlüssel, und das
 * `down()` benennt zurück.
 */
final class RenamePasswordResetTokenToHash extends AbstractMigration
{
    public function up(): void
    {
        $this->table('password_resets')
            ->renameColumn('token', 'token_hash')
            ->update();
    }

    public function down(): void
    {
        $this->table('password_resets')
            ->renameColumn('token_hash', 'token')
            ->update();
    }
}

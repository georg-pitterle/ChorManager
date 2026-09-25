<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Schreibt die gespeicherten E-Mail-Adressen klein.
 *
 * Das Zurücksetzen des Passworts schrieb die Adresse seit immer klein, das
 * Anlegen eines Mitglieds bis Lauf 28 so, wie sie eingegeben wurde. Aufgefallen
 * ist das nie, weil die Spalten auf `utf8mb4_unicode_ci` liegen und ein
 * `WHERE email = ?` damit ohne Rücksicht auf Groß- und Kleinschreibung trifft.
 * Sobald das Schema auf eine `_bin`-Kollation wechselt - in Lauf 27 war die
 * Kollation schon einmal Thema -, fände das Zurücksetzen ein Konto mit
 * gemischter Schreibweise nicht mehr, und die betroffene Person käme ohne Weg
 * zurück an ihr Konto.
 *
 * Betroffen sind nur die zwei Spalten, über die nachgeschlagen wird:
 * `users.email` als Kennung des Kontos und `password_resets.email` als
 * Schlüssel des Reset-Vorgangs. `sponsors.email`, `mail_queue.recipient_email`
 * und `newsletter_archive.email` bleiben unangetastet: Das eine ist angezeigte
 * Kontaktangabe, die anderen halten fest, wohin tatsächlich versendet wurde.
 * Beides ist Inhalt und kein Suchschlüssel.
 */
final class LowercaseStoredEmailAddresses extends AbstractMigration
{
    /** Die Spalten, über die nachgeschlagen wird - siehe Klassenkommentar. */
    private const TABLES = ['users', 'password_resets'];

    /**
     * Adressen, die in mehr als einer Schreibweise vorliegen.
     *
     * Als Zeichenkette herausgegeben und nicht abgesetzt, damit
     * `Tests\Feature\LowercaseEmailBackfillTest` sie gegen eine Wegwerf-Tabelle
     * laufen lassen kann. Eine zweite, nachgebaute Fassung im Test prüfte sonst
     * nur sich selbst.
     */
    public static function collisionQuery(string $table): string
    {
        return sprintf(
            'SELECT LOWER(email) AS normalized, COUNT(*) AS count
            FROM %s
            GROUP BY LOWER(email)
            HAVING COUNT(*) > 1',
            $table
        );
    }

    /**
     * `BINARY` erzwingt den Vergleich Zeichen für Zeichen. Ohne das Schlüsselwort
     * wäre die Bedingung unter `utf8mb4_unicode_ci` sinnlos: Sie verglich die
     * Adresse mit ihrer eigenen Kleinschreibung und fände nie einen Unterschied,
     * die Migration schriebe also nichts um und meldete trotzdem Erfolg. Genau das
     * hält der Test fest.
     */
    public static function lowercaseStatement(string $table): string
    {
        return sprintf('UPDATE %s SET email = LOWER(email) WHERE email <> BINARY LOWER(email)', $table);
    }

    public function up(): void
    {
        // Die Prüfung steht vor dem Schreiben, nicht danach: Danach wären die
        // kollidierenden Adressen schon zusammengefallen, und der Lauf endete
        // mitten drin - nachholen ließe sich das nicht, weil Phinx den Eintrag
        // in `phinxlog` bereits gesetzt hat.
        $collisions = $this->fetchAll(self::collisionQuery('users'));

        if ($collisions !== []) {
            // Unter der heutigen Kollation kann das nicht vorkommen - der
            // Unique-Index auf users.email weist die zweite Schreibweise schon beim
            // Anlegen ab. Die Prüfung gilt der Installation, die bereits auf einer
            // `_bin`-Kollation läuft: Dort dürfen zwei Konten so existieren, und sie
            // kleinzuschreiben hieße, eines davon unerreichbar zu machen.
            $addresses = implode(', ', array_map(
                static fn (array $row): string => (string) $row['normalized'],
                $collisions
            ));

            throw new \RuntimeException(
                'Cannot lowercase users.email: these addresses exist in more than one spelling '
                . 'and would collide on the unique index: ' . $addresses
                . '. Merge or archive the duplicate accounts first.'
            );
        }

        foreach (self::TABLES as $table) {
            $this->execute(self::lowercaseStatement($table));
        }
    }

    /**
     * Bewusst ohne Wirkung.
     *
     * Die ursprüngliche Schreibweise ist nach dem Kleinschreiben nicht mehr
     * vorhanden, und es gibt keine zweite Stelle, aus der sie zurückzuholen wäre.
     * Sie irgendwie zu erraten wäre schlimmer als sie zu verlieren: Ein
     * `UCFIRST` oder Ähnliches erfände eine Schreibweise, die nie jemand
     * eingegeben hat.
     *
     * Verloren geht dabei nichts, was eine Bedeutung trägt. Der Domänenteil einer
     * Adresse ist laut RFC 1035 ohnehin ohne Rücksicht auf die Schreibweise zu
     * behandeln, und beim lokalen Teil hält sich in der Praxis kein Anbieter die
     * Unterscheidung offen. Zugestellt wird an dieselbe Person.
     */
    public function down(): void
    {
    }
}

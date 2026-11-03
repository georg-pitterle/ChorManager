<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Offene Fragen: Statt aus festen Antworten zu wählen, schreibt jede Person
 * eine eigene ("Vorschläge für den nächsten Konzerttitel").
 *
 * - polls.answer_kind: "choice" (feste Antworten, wie bisher) oder "text".
 *   Bestehende Umfragen bleiben "choice".
 * - poll_text_answers: eine Antwort je Person und Frage, die Teilnahme steht
 *   wie bei den Stimmen in poll_participants. Wie poll_votes ohne Zeitstempel
 *   und mit zufälliger UUID statt laufender Id, damit eine anonyme Antwort
 *   keine Reihenfolge trägt. Bei anonymen Fragen bleibt user_id leer.
 */
final class AddOpenQuestionsToPolls extends AbstractMigration
{
    public function up(): void
    {
        $this->table('polls')
            ->addColumn('answer_kind', 'enum', [
                'values' => ['choice', 'text'],
                'null' => false,
                'default' => 'choice',
                'after' => 'description',
            ])
            ->update();

        $this->table('poll_text_answers', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('poll_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('body', 'text', ['null' => false])
            // Der eindeutige Index deckt auch die Suche nach poll_id allein ab.
            ->addIndex(['poll_id', 'user_id'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addForeignKey('poll_id', 'polls', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Antworten stehen nirgends sonst, und eine
        // offene Frage ohne answer_kind sähe danach wie eine Auswahl ohne
        // Antworten aus.
        $answers = (int) ($this->fetchRow('SELECT COUNT(*) AS rows_left FROM poll_text_answers')['rows_left'] ?? 0);
        $questions = (int) ($this->fetchRow(
            "SELECT COUNT(*) AS rows_left FROM polls WHERE answer_kind = 'text'"
        )['rows_left'] ?? 0);

        if ($answers > 0 || $questions > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen, diese Daten wären unwiederbringlich weg: poll_text_answers: %d Zeile(n),'
                    . ' offene Fragen in polls: %d. Diese zuerst bewusst selbst entfernen.',
                $answers,
                $questions
            ));
        }

        $this->table('poll_text_answers')->drop()->update();
        $this->table('polls')->removeColumn('answer_kind')->update();
    }
}

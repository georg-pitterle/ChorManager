<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zieht den fehlenden Fremdschlüssel auf finance_revisions.finance_id nach.
 *
 * Das Prüfjournal hatte als einzige Tabelle mit Buchungsbezug keinen Constraint
 * darauf. Folgen hatte das bisher keine: Buchungen werden nie gelöscht, sondern
 * storniert - das Original bleibt stehen, die Gegenbuchung verweist über
 * reversal_of_id darauf (§ 131 BAO verlangt genau das). Die Zusicherung lag
 * damit aber allein in der Anwendung, und ein Journal, dessen Einträge ins Leere
 * zeigen können, ist als Nachweis wertlos.
 *
 * RESTRICT statt CASCADE oder SET NULL: Das Journal soll das Löschen einer
 * Buchung nicht überstehen, sondern verhindern. CASCADE nähme die Einträge mit,
 * SET NULL kappte die Zuordnung - beides macht genau die Lücke auf, gegen die
 * das Journal geführt wird. Am Verhalten ändert sich nichts, weil heute ohnehin
 * niemand löscht; ab jetzt hält die Datenbank die Regel selbst.
 *
 * Die Spalte bleibt nullable, und das muss sie: Seit 20260826120000 trägt das
 * Journal auch Sperr-Einträge (`action = 'lock'`), die an keiner einzelnen
 * Buchung hängen. MySQL prüft einen Fremdschlüssel bei NULL nicht, diese
 * Einträge bleiben also unberührt.
 *
 * Der Index idx_finance_revisions_finance_id aus 20260815160000 deckt den
 * Constraint bereits ab - MySQL verlangt dafür einen Index, in dem die Spalte an
 * erster Stelle steht, und genau so ist er gebaut. Es kommt deshalb kein
 * weiterer dazu.
 *
 * Der fehlende Fremdschlüssel auf user_id bleibt bewusst fehlen: 20260901121000
 * begründet ihn ausführlich - das Journal soll ein gelöschtes Mitglied
 * überleben, deshalb steht dessen Name dort zusätzlich als Momentaufnahme.
 */
final class AddFinanceForeignKeyToFinanceRevisions extends AbstractMigration
{
    private const CONSTRAINT = 'fk_finance_revisions_finance';

    public function up(): void
    {
        // Prüfung vor dem ALTER: Zeigt ein Eintrag auf eine Buchung, die es
        // nicht mehr gibt, scheitert der Constraint - mit einer Meldung, die
        // nicht sagt, welche Zeile gemeint ist. Gelöscht wird hier nichts:
        // Ein Journaleintrag ist ein Nachweis, kein Zwischenstand. Welcher von
        // beiden Fehlern vorliegt - verwaiste Zeile oder versehentlich gelöschte
        // Buchung -, entscheidet der Betreiber.
        $orphaned = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS orphaned
             FROM finance_revisions r
             LEFT JOIN finances f ON f.id = r.finance_id
             WHERE r.finance_id IS NOT NULL
               AND f.id IS NULL'
        )['orphaned'] ?? 0);

        if ($orphaned > 0) {
            throw new RuntimeException(sprintf(
                'Es gibt %d Journaleintrag/-einträge, deren Buchung fehlt. Der Fremdschlüssel '
                    . 'wird deshalb nicht gesetzt - diese Einträge zuerst prüfen.',
                $orphaned
            ));
        }

        $this->table('finance_revisions')
            ->addForeignKey('finance_id', 'finances', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => self::CONSTRAINT,
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('finance_revisions')
            ->dropForeignKey('finance_id', self::CONSTRAINT)
            ->update();
    }
}

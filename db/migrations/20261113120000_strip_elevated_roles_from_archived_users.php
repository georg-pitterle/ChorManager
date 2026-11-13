<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zieht archivierten Konten die Rollen über dem niedrigsten vergebenen Level ab.
 *
 * Seit Lauf 60 verlangt `UserEditPolicy::canDeactivate()`, dass ein Konto vor
 * dem Archivieren keine erhöhte Rolle mehr trägt. Der Grund steht an der
 * Wiederherstellung: Ein archiviertes Konto kommt nicht nur über die
 * Mitgliederverwaltung zurück, sondern auch dadurch, dass jemand es einem
 * Projekt zuordnet - `ProjectPersistence::addProjectMember()` aktiviert es
 * dabei. Dieser Weg kennt die Rollenhierarchie bewusst nicht, weil die
 * Projektzuordnung Besetzungsliste ist und nicht Kontoverwaltung (siehe
 * `ProjectMemberPolicy`). Trägt das Konto beim Archivieren nichts Erhöhtes
 * mehr, kann der Weg auch nichts Erhöhtes zurückholen.
 *
 * Für die vor Lauf 60 archivierten Konten gilt das nicht - ihr Rollensatz blieb
 * absichtlich unangetastet, sonst steckten sie mit ihren alten Rollen für immer
 * im Archiv fest. Der Review-Lauf 73 hat die Folge benannt: Ein solches
 * Altkonto mit Vorstands- oder Admin-Rolle kann über die Besetzung wieder aktiv
 * werden, und zwar auch durch eine Stimmvertretung, die allein
 * `can_assign_own_voice_group_to_project` hält - archivierte Konten behalten
 * ihre Stimmgruppen und stehen in der Kandidatenliste
 * (`ProjectQuery::candidatesQuery()` filtert `is_active` nicht).
 *
 * Dieser Nachtrag bringt die Altkonten auf denselben Stand, den der reguläre
 * Weg seit Lauf 60 erzeugt. Danach gilt die Zusage lückenlos, und zwar
 * dauerhaft: Die Rollen eines archivierten Kontos lassen sich nicht mehr
 * ändern, weil `UserEditPolicy::passesBaseGuards()` jedes nicht aktive Ziel
 * abweist, und `capRoleIdsToActorLevel()` deckelt jede Vergabe ohnehin.
 *
 * Verworfen wurde der naheliegende Gegenweg, die Hierarchie in der
 * Reaktivierung selbst zu prüfen. Das widerspricht der ausdrücklichen
 * Entscheidung in `ProjectMemberPolicy` - mit Hierarchie könnte die Chorleitung
 * den Vorstand nicht mehr in ein Projekt aufnehmen -, und es verschiebt die
 * Lücke nur: Ein Altkonto bliebe weiter mit erhöhter Rolle im Archiv stehen.
 */
final class StripElevatedRolesFromArchivedUsers extends AbstractMigration
{
    /**
     * Die betroffenen Konten, je mit der Zahl der erhöhten und der verbleibenden
     * Rollen. `%1$d` ist das niedrigste vergebene Level.
     *
     * Die Anweisungen stehen als Konstanten da, damit
     * `Tests\Feature\ArchivedElevatedRolesCleanupFeatureTest` genau die prüfen
     * kann, die auch läuft - dasselbe Vorgehen wie bei
     * `StoreImpliedRolePermissions` und den Zählabfragen der destruktiven
     * Migrationen.
     */
    public const ACCOUNTS_SQL =
        'SELECT u.id AS user_id,'
        . ' SUM(CASE WHEN r.hierarchy_level > %1$d THEN 1 ELSE 0 END) AS elevated,'
        . ' SUM(CASE WHEN r.hierarchy_level <= %1$d THEN 1 ELSE 0 END) AS remaining'
        . ' FROM users u'
        . ' JOIN user_roles ur ON ur.user_id = u.id'
        . ' JOIN roles r ON r.id = ur.role_id'
        . ' WHERE u.is_active = 0'
        . ' GROUP BY u.id'
        . ' HAVING elevated > 0';

    /**
     * Die Rollen auf dem niedrigsten vergebenen Level, aufsteigend nach Kennung.
     */
    public const BASELINE_ROLES_SQL =
        'SELECT id FROM roles WHERE hierarchy_level = %1$d ORDER BY id';

    /**
     * Das Abziehen selbst. Das Level steht als Zahl im Text und nicht als
     * Unterabfrage auf `roles`: MySQL verbietet in einem DELETE die Unterabfrage
     * auf eine Tabelle des Löschziels, und eine Zahl ist hier ohnehin die
     * ehrlichere Angabe - sie wurde eine Zeile zuvor gelesen und steht damit im
     * Prüflauf genauso wie im Betrieb.
     */
    public const STRIP_SQL =
        'DELETE ur FROM user_roles ur'
        . ' JOIN users u ON u.id = ur.user_id'
        . ' JOIN roles r ON r.id = ur.role_id'
        . ' WHERE u.is_active = 0 AND r.hierarchy_level > %1$d';

    /**
     * Setzt einem Konto die unterste Rolle nach. `%1$d` ist das Konto,
     * `%2$d` die Rolle.
     */
    public const BASELINE_SQL =
        'INSERT INTO user_roles (user_id, role_id) VALUES (%1$d, %2$d)';

    /**
     * Die Konten, die nach dem Abziehen ohne jede Rolle dastünden.
     *
     * Ein Konto ganz ohne Rolle ist ein Zustand, den der reguläre Weg nie
     * erzeugt: `UserController::create()` und `::update()` verlangen beide
     * mindestens eine Rolle. Diese Konten bekommen deshalb die unterste Rolle
     * nachgesetzt und stehen danach genau so da wie ein seit Lauf 60 regulär
     * archiviertes.
     *
     * @param iterable<array<string, mixed>> $accounts Ergebnis von ACCOUNTS_SQL
     * @return list<int>
     */
    public static function strandedAccounts(iterable $accounts): array
    {
        $stranded = [];

        foreach ($accounts as $account) {
            if ((int) ($account['remaining'] ?? 0) > 0) {
                continue;
            }

            $stranded[] = (int) ($account['user_id'] ?? 0);
        }

        return $stranded;
    }

    /**
     * Die Rolle, die nachgesetzt wird - oder null, wenn das nicht eindeutig ist.
     *
     * Mehrere Rollen auf dem untersten Level sind erlaubt, und dann sagt nichts
     * im Datenbestand, welche davon die Vorgabe ist. Geraten wird deshalb nicht:
     * `UserController::defaultRoleIds()` hält sich an derselben Stelle genauso
     * zurück und wählt dann gar nichts vor. Hier bricht der Lauf stattdessen ab,
     * damit niemandem still die falsche Rolle zugeteilt wird.
     *
     * @param list<int> $baselineRoleIds
     */
    public static function baselineRoleIdFor(array $baselineRoleIds): ?int
    {
        return count($baselineRoleIds) === 1 ? (int) $baselineRoleIds[0] : null;
    }

    public function up(): void
    {
        $minimalLevel = (int) ($this->fetchRow(
            'SELECT MIN(hierarchy_level) AS minimal FROM roles'
        )['minimal'] ?? 0);

        $accounts = $this->fetchAll(sprintf(self::ACCOUNTS_SQL, $minimalLevel));
        $stranded = self::strandedAccounts($accounts);

        $baselineRoleIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->fetchAll(sprintf(self::BASELINE_ROLES_SQL, $minimalLevel))
        );
        $baselineRoleId = self::baselineRoleIdFor($baselineRoleIds);

        // Vollständigkeitsprüfung vor dem Löschen: Was abgezogen wird, ist
        // gewollt - was nicht passieren darf, ist ein archiviertes Konto, das
        // danach ohne jede Rolle dasteht. Genau das träte ein, wenn ein Konto
        // nur erhöhte Rollen trägt und die unterste Rolle nicht eindeutig ist.
        // Der Lauf bricht dann ab, statt eine davon zu raten; wer die Lage
        // auflösen will, vergibt den betroffenen Konten vorher von Hand eine
        // Rolle auf dem untersten Level.
        if ($stranded !== [] && $baselineRoleId === null) {
            throw new \RuntimeException(sprintf(
                'Diese archivierten Konten tragen nur erhöhte Rollen und stünden nach dem '
                . 'Abziehen ohne jede Rolle da: %s. Auf Level %d liegen %d Rollen - welche '
                . 'davon die Vorgabe ist, sagt der Bestand nicht. Erst eine Rolle dieses '
                . 'Levels von Hand vergeben, dann erneut migrieren.',
                implode(', ', $stranded),
                $minimalLevel,
                count($baselineRoleIds)
            ));
        }

        $this->execute(sprintf(self::STRIP_SQL, $minimalLevel));

        foreach ($stranded as $userId) {
            $this->execute(sprintf(self::BASELINE_SQL, $userId, (int) $baselineRoleId));
        }

        $this->output->writeln(sprintf(
            ' -- %d archivierte Konten heruntergestuft, davon %d mit nachgesetzter Rolle',
            count($accounts),
            count($stranded)
        ));
    }

    /**
     * Bewusst ohne Rückbau.
     *
     * Welche Rolle ein Konto vor dem Abziehen trug, steht nach dem Abziehen
     * nirgends mehr - die Zuordnung war die einzige Quelle. Ein Rückbau könnte
     * sie deshalb nicht wiederherstellen, sondern nur raten, und das hieße:
     * archivierten Konten Rollen geben, die sie nie hatten. Dass die Zuordnung
     * verloren geht, ist die bewusste Entscheidung dieses Nachtrags; beim
     * Wiederherstellen eines Kontos wird die Rolle ohnehin von Hand neu
     * vergeben, genau wie bei jedem seit Lauf 60 archivierten Konto.
     */
    public function down(): void
    {
    }
}

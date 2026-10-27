<?php

declare(strict_types=1);

use App\Models\Role;
use Phinx\Migration\AbstractMigration;

/**
 * Trägt die eingeschlossenen Rechte in die Spalte nach, in der sie fehlen.
 *
 * `Role::IMPLIED_PERMISSIONS` nennt zwei Paare: Finanzen verwalten zieht Finanzen
 * lesen mit, Sponsoring verwalten zieht eigene Patenschaften anlegen mit.
 * `SessionAuthService` setzt beide zur Anmeldung, gespeichert wurde bisher nur das
 * erste - `RoleController::buildPermissionFlags()` hatte die Finanz-Regel
 * handgeschrieben, die Sponsoring-Regel nirgends.
 *
 * Seit Lauf 56 wendet der Controller beide Regeln an. Das greift aber erst, wenn
 * jemand die Rolle das nächste Mal speichert; bis dahin zeigt die Rechte-Matrix
 * weiter zu wenig, denn sie liest die Spalte. Dieser Nachtrag holt die bestehenden
 * Rollen auf denselben Stand.
 *
 * Kein neues Recht entsteht dabei: Jede betroffene Rolle hält das kleinere Recht
 * im Betrieb schon, weil die Sitzung es aus dem Vollrecht ableitet. Verändert wird
 * allein die Spalte, aus der die Anzeige liest.
 */
final class StoreImpliedRolePermissions extends AbstractMigration
{
    /**
     * Die Anweisung steht als Konstante da, damit
     * `Tests\Feature\RoleImpliedPermissionsFeatureTest` genau die prüfen kann, die
     * auch läuft - dasselbe Vorgehen wie bei den Zählabfragen der destruktiven
     * Migrationen.
     */
    public const BACKFILL_SQL = 'UPDATE roles SET %2$s = 1 WHERE %1$s = 1 AND %2$s = 0';

    /**
     * Die Anweisungen dieses Nachtrags, ein Paar aus `Role::IMPLIED_PERMISSIONS`
     * je Zeile.
     *
     * @return list<string>
     */
    public static function statements(): array
    {
        $statements = [];

        foreach (Role::IMPLIED_PERMISSIONS as $full => $impliedPermissions) {
            foreach ($impliedPermissions as $implied) {
                $statements[] = sprintf(self::BACKFILL_SQL, $full, $implied);
            }
        }

        return $statements;
    }

    public function up(): void
    {
        foreach (self::statements() as $statement) {
            $this->execute($statement . ';');
        }
    }

    /**
     * Bewusst ohne Rückbau.
     *
     * Die Spalte sagt nach dem Nachtrag nicht mehr, ob das kleinere Recht
     * ausdrücklich angehakt oder aus dem Vollrecht abgeleitet wurde. Ein `SET ... = 0`
     * träfe deshalb auch jede Rolle, die es von Hand bekommen hat - es würde Rechte
     * wegnehmen, die jemand mit Absicht vergeben hat. Und zu gewinnen wäre nichts:
     * Im Betrieb hält die Rolle das Recht ohnehin weiter, die Sitzung leitet es
     * aus dem Vollrecht ab.
     */
    public function down(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Zählstand aller Tabellen, einmal vor dem ersten Test festgehalten.
 *
 * Der Vergleich am Ende des Laufs deckt jeden Test auf, der Zeilen hinterlässt - ohne
 * dass die Prüfung wissen muss, wonach sie sucht. Eine Prüfung auf bekannte Merkmale
 * ("Test Song", "@example.test") würde nur die Lecks finden, die schon einmal jemand
 * bemerkt hat; ein neu geschriebener Test mit anderen Daten liefe wieder unbemerkt aus.
 *
 * Verglichen wird gegen den Ausgangsstand, nicht gegen Null: eine bereits verschmutzte
 * Entwicklungsdatenbank soll den Wächter nicht dauerhaft rot färben.
 *
 * Gemeldet werden Abweichungen in beide Richtungen. Ein Test, der Zeilen löscht, ist
 * genauso ein Fehler wie einer, der welche liegen lässt: BackupServiceTest entfernte
 * bei jedem Lauf echte remember_logins aus dem Seed-Bestand, weil der Dienst hinter
 * dem Test alle Token verwirft und die Löschung ohne Transaktion stehen blieb.
 */
final class DatabaseRowCountSnapshot
{
    /** @var array<string, int>|null */
    private static ?array $baseline = null;

    private static ?string $baselineFailure = null;

    public static function captureBaseline(): void
    {
        self::$baseline = self::countRows();
        self::$baselineFailure = null;
    }

    /**
     * Warum kein Ausgangsstand zustande kam. Der Wächter nennt den Grund beim
     * Überspringen - ein stilles Skip verbirgt sonst auch echte Fehler, etwa fehlende
     * Rechte auf information_schema.
     */
    public static function recordBaselineFailure(Throwable $error): void
    {
        self::$baseline = null;
        self::$baselineFailure = $error::class . ': ' . $error->getMessage();
    }

    public static function baselineFailure(): ?string
    {
        return self::$baselineFailure;
    }

    /**
     * @return array<string, int>|null Null, solange kein Ausgangsstand aufgenommen wurde.
     */
    public static function baseline(): ?array
    {
        return self::$baseline;
    }

    public static function forget(): void
    {
        self::$baseline = null;
        self::$baselineFailure = null;
    }

    /**
     * @return array<string, int> Tabelle => Abweichung gegenüber dem Ausgangsstand,
     *                            positiv für hinterlassene, negativ für gelöschte Zeilen.
     */
    public static function differencesSinceBaseline(): array
    {
        return self::compare(self::$baseline ?? [], self::countRows());
    }

    /**
     * Der Vergleich als reine Funktion, damit beide Richtungen ohne Datenbank prüfbar sind.
     *
     * @param array<string, int> $baseline
     * @param array<string, int> $current
     *
     * @return array<string, int>
     */
    public static function compare(array $baseline, array $current): array
    {
        $differences = [];

        foreach ($current as $table => $count) {
            // Tabellen ohne Ausgangswert entstehen erst während des Laufs. Sie zählen
            // trotzdem mit: auch eine im Test angelegte Tabelle gehört danach wieder weg.
            $before = $baseline[$table] ?? 0;

            if ($count !== $before) {
                $differences[$table] = $count - $before;
            }
        }

        // Eine Tabelle, die es am Anfang gab und am Ende nicht mehr, taucht in der
        // Schleife oben nicht auf - sie fehlt ja im aktuellen Zählstand.
        foreach ($baseline as $table => $before) {
            if (!array_key_exists($table, $current) && $before !== 0) {
                $differences[$table] = -$before;
            }
        }

        return $differences;
    }

    /**
     * @param array<string, int> $differences
     */
    public static function describeDifferences(array $differences): string
    {
        $lines = [];

        foreach ($differences as $table => $delta) {
            $lines[] = sprintf(
                '  %s: %+d Zeile(n) %s',
                $table,
                $delta,
                $delta > 0 ? 'zurückgelassen' : 'gelöscht'
            );
        }

        return "Der Testlauf hat den Datenbestand verändert:\n"
            . implode("\n", $lines)
            . "\n\nJeder Test räumt auf, was er anlegt, und lässt fremde Zeilen stehen -"
            . "\nentweder über eine Transaktion mit beginTransaction()/rollBack() oder"
            . "\ndurch gezieltes Löschen in tearDown()."
            . "\nAchtung: Zeilen, die vor beginTransaction() entstehen, überleben den rollBack().";
    }

    /**
     * @return array<string, int>
     */
    private static function countRows(): array
    {
        $connection = Capsule::connection();
        $counts = [];

        foreach (self::tableNames($connection) as $table) {
            $counts[$table] = (int) $connection->table($table)->count();
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private static function tableNames(Connection $connection): array
    {
        $rows = $connection->select(
            "SELECT TABLE_NAME AS table_name FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            . " ORDER BY TABLE_NAME"
        );

        $names = [];

        foreach ($rows as $row) {
            $names[] = (string) ((array) $row)['table_name'];
        }

        return $names;
    }
}

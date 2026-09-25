<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use LowercaseStoredEmailAddresses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

require_once dirname(__DIR__, 2) . '/db/migrations/20260925120000_lowercase_stored_email_addresses.php';

/**
 * 20260925120000 schreibt die gespeicherten Adressen klein.
 *
 * Das trägt eine Annahme, die unter `utf8mb4_unicode_ci` alles entscheidet:
 * `email <> BINARY LOWER(email)` muss eine Adresse mit gemischter Schreibweise
 * finden. Ohne `BINARY` vergleicht MySQL die Adresse mit ihrer eigenen
 * Kleinschreibung und findet nie einen Unterschied - die Migration schriebe
 * nichts um, meldete aber Erfolg, und der Fehler fiele erst beim Wechsel der
 * Kollation auf, wenn niemand mehr an diese Migration denkt.
 *
 * Geprüft wird gegen die echte Datenbank auf einer Wegwerf-Tabelle mit derselben
 * Kollation, nicht gegen eine Nachbildung in PHP. Die Anweisungen kommen aus der
 * Migration selbst; eine zweite Fassung hier prüfte nur sich selbst.
 */
final class LowercaseEmailBackfillTest extends TestCase
{
    private const TABLE = 'test_lowercase_emails';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $this->dropScratchTable();

        // Ohne Unique-Index: Zwei Schreibweisen derselben Adresse sollen sich
        // anlegen lassen, damit der Kollisionsfall überhaupt herstellbar ist. In
        // der echten Tabelle verhindert der Index sie - genau deshalb kann der
        // Abbruch dort heute nicht auslösen, und genau dafür ist er gedacht:
        // für eine Installation, die schon auf einer `_bin`-Kollation läuft.
        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                email varchar(255) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            self::TABLE
        ));
    }

    protected function tearDown(): void
    {
        $this->dropScratchTable();
        parent::tearDown();
    }

    public function testMixedCaseAddressesAreRewrittenAndLowercaseOnesLeftAlone(): void
    {
        $this->insert('Max.Mustermann@Example.TEST');
        $this->insert('rita.testperson@example.test');
        $this->insert('ANNA@EXAMPLE.TEST');

        $affected = Capsule::connection()->update(
            LowercaseStoredEmailAddresses::lowercaseStatement(self::TABLE)
        );

        $this->assertSame(2, $affected, 'Genau die zwei gemischten Schreibweisen werden angefasst.');
        $this->assertSame(
            ['anna@example.test', 'max.mustermann@example.test', 'rita.testperson@example.test'],
            $this->storedAddresses()
        );
    }

    /**
     * Die Gegenprobe zur Annahme oben: Ohne `BINARY` findet die Bedingung nichts.
     * Läuft dieser Fall rot, taugt das `BINARY` in der Migration nichts mehr -
     * etwa weil eine künftige MySQL-Fassung es anders auslegt.
     */
    public function testWithoutBinaryTheConditionWouldMatchNothing(): void
    {
        $this->insert('Max.Mustermann@Example.TEST');

        $affected = Capsule::connection()->update(sprintf(
            'UPDATE %s SET email = LOWER(email) WHERE email <> LOWER(email)',
            self::TABLE
        ));

        $this->assertSame(0, $affected, 'Ohne BINARY vergleicht die Kollation gleich - nichts trifft zu.');
        $this->assertSame(['Max.Mustermann@Example.TEST'], $this->storedAddresses());
    }

    public function testTheCollisionCheckFindsTwoSpellingsOfTheSameAddress(): void
    {
        $this->insert('Doppelt@Example.TEST');
        $this->insert('doppelt@example.test');
        $this->insert('einzeln@example.test');

        $rows = Capsule::connection()->select(
            LowercaseStoredEmailAddresses::collisionQuery(self::TABLE)
        );

        $this->assertCount(1, $rows, 'Nur die doppelte Adresse wird gemeldet.');
        $this->assertSame('doppelt@example.test', $rows[0]->normalized);
        $this->assertSame(2, (int) $rows[0]->count);
    }

    /**
     * Die Annahme hinter der Normalisierung in
     * `PasswordResetController::processReset()`: Unter einer `_bin`-Kollation
     * findet ein `WHERE email = ?` die kleingeschriebene Zeile nicht mehr, wenn
     * die gesuchte Adresse gemischt geschrieben ist - und mit vorheriger
     * Kleinschreibung schon.
     *
     * Geprüft auf einer zweiten Wegwerf-Tabelle statt an `users` selbst: Deren
     * Kollation umzustellen wäre DDL, also nicht zurückrollbar, und ein Abbruch
     * mitten im Test hinterließe die Spalte verändert für alles, was danach in
     * diesem Prozess läuft.
     */
    public function testUnderABinaryCollationTheSpellingDecidesTheLookup(): void
    {
        $table = self::TABLE . '_bin';
        Capsule::connection()->statement('DROP TABLE IF EXISTS ' . $table);
        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                email varchar(255) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            $table
        ));

        try {
            Capsule::connection()->insert(
                sprintf('INSERT INTO %s (email) VALUES (?)', $table),
                ['max.mustermann@example.test']
            );

            $mixedCase = 'Max.Mustermann@Example.TEST';

            $this->assertSame(
                [],
                Capsule::connection()->select(
                    sprintf('SELECT id FROM %s WHERE email = ?', $table),
                    [$mixedCase]
                ),
                'Ungenormt findet die Abfrage das Konto nicht - genau der Ausfall, um den es geht.'
            );

            $this->assertCount(
                1,
                Capsule::connection()->select(
                    sprintf('SELECT id FROM %s WHERE email = ?', $table),
                    [strtolower($mixedCase)]
                ),
                'Kleingeschrieben trifft sie.'
            );
        } finally {
            Capsule::connection()->statement('DROP TABLE IF EXISTS ' . $table);
        }
    }

    public function testTheCollisionCheckStaysQuietWithoutDuplicates(): void
    {
        $this->insert('Max@Example.TEST');
        $this->insert('rita@example.test');

        $this->assertSame(
            [],
            Capsule::connection()->select(LowercaseStoredEmailAddresses::collisionQuery(self::TABLE))
        );
    }

    private function insert(string $email): void
    {
        Capsule::connection()->insert(
            sprintf('INSERT INTO %s (email) VALUES (?)', self::TABLE),
            [$email]
        );
    }

    /**
     * @return list<string>
     */
    private function storedAddresses(): array
    {
        $rows = Capsule::connection()->select(
            sprintf('SELECT email FROM %s ORDER BY email', self::TABLE)
        );

        return array_map(static fn (object $row): string => (string) $row->email, $rows);
    }

    private function dropScratchTable(): void
    {
        Capsule::connection()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }
}

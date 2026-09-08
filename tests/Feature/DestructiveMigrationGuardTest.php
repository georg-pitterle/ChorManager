<?php

declare(strict_types=1);

namespace Tests\Feature;

use AllowLockEntriesInFinanceJournal;
use AllowNewslettersWithoutProject;
use CreateFinanceGroupsAndLink;
use DropEventsProjectId;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

require_once dirname(__DIR__, 2) . '/db/migrations/20260621120000_create_finance_groups_and_link.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20260722130000_drop_events_project_id.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20260811190000_allow_newsletters_without_project.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20260826120000_allow_lock_entries_in_finance_journal.php';

/**
 * Vier Migrationen führen einen Schritt aus, der Daten unwiederbringlich
 * beseitigt: zwei werfen im `up()` eine Spalte weg, deren Inhalt vorher
 * woandershin umgeschrieben wurde, zwei müssten im `down()` Zeilen loswerden,
 * die in die engere Spalte nicht mehr passen.
 *
 * In beiden Fällen ist der Wert danach fort, und nachholen lässt sich das nicht,
 * weil Phinx den Lauf bereits in `phinxlog` verbucht hat. Die Zählung davor ist
 * deshalb der eigentliche Schutz, und sie ist nur so viel wert, wie sie wirklich
 * findet. Der Test lässt sie gegen Wegwerf-Tabellen laufen, in denen jeweils
 * genau eine Zeile betroffen ist und eine unbedenkliche danebensteht.
 */
final class DestructiveMigrationGuardTest extends TestCase
{
    private const EVENTS_TABLE = 'test_guard_events';
    private const AUDIENCE_TABLE = 'test_guard_event_audience_sources';
    private const BUDGET_TABLE = 'test_guard_budget_categories';
    private const NEWSLETTERS_TABLE = 'test_guard_newsletters';
    private const REVISIONS_TABLE = 'test_guard_finance_revisions';

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->dropScratchTables();

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                project_id int(11) DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::EVENTS_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                event_id int(11) NOT NULL,
                source_type varchar(32) NOT NULL,
                reference_id int(11) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::AUDIENCE_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                group_name varchar(255) NOT NULL DEFAULT \'\',
                finance_group_id int(11) DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::BUDGET_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                project_id int(11) DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::NEWSLETTERS_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            "CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                finance_id int(11) DEFAULT NULL,
                action enum('create','update','reverse','lock') NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            self::REVISIONS_TABLE
        ));
    }

    protected function tearDown(): void
    {
        $this->dropScratchTables();
        parent::tearDown();
    }

    public function testEventGuardFindsTheTerminWithoutAudienceSource(): void
    {
        Capsule::connection()->insert(sprintf(
            'INSERT INTO %s (id, project_id) VALUES (1, 7), (2, 8), (3, NULL)',
            self::EVENTS_TABLE
        ));
        // Nur Termin 1 ist umgeschrieben; Termin 2 hat zwar eine Zeile, aber für
        // ein anderes Projekt, und Termin 3 hatte nie ein Projekt.
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (event_id, source_type, reference_id)
             VALUES (1, 'project_members', 7), (2, 'project_members', 99), (2, 'role', 8)",
            self::AUDIENCE_TABLE
        ));

        $this->assertSame(1, $this->countFrom(DropEventsProjectId::ORPHANED_EVENTS_SQL, [
            'event_audience_sources' => self::AUDIENCE_TABLE,
            'events' => self::EVENTS_TABLE,
        ]));
    }

    public function testEventGuardStaysQuietWhenEveryTerminIsRewritten(): void
    {
        Capsule::connection()->insert(sprintf(
            'INSERT INTO %s (id, project_id) VALUES (1, 7), (2, NULL)',
            self::EVENTS_TABLE
        ));
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (event_id, source_type, reference_id) VALUES (1, 'project_members', 7)",
            self::AUDIENCE_TABLE
        ));

        $this->assertSame(0, $this->countFrom(DropEventsProjectId::ORPHANED_EVENTS_SQL, [
            'event_audience_sources' => self::AUDIENCE_TABLE,
            'events' => self::EVENTS_TABLE,
        ]));
    }

    public function testBudgetGuardFindsTheCategoryWithoutFinanceGroup(): void
    {
        // Zeile 1 ist umgeschrieben, Zeile 2 blieb liegen, Zeile 3 trug nie einen
        // Gruppennamen und verliert deshalb auch nichts.
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (group_name, finance_group_id) VALUES ('Miete', 4), ('Noten', NULL), ('', NULL)",
            self::BUDGET_TABLE
        ));

        $this->assertSame(1, $this->countFrom(
            CreateFinanceGroupsAndLink::ORPHANED_BUDGET_CATEGORIES_SQL,
            ['budget_categories' => self::BUDGET_TABLE]
        ));
    }

    public function testBudgetGuardStaysQuietWhenEveryCategoryIsLinked(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (group_name, finance_group_id) VALUES ('Miete', 4), ('', NULL)",
            self::BUDGET_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            CreateFinanceGroupsAndLink::ORPHANED_BUDGET_CATEGORIES_SQL,
            ['budget_categories' => self::BUDGET_TABLE]
        ));
    }

    public function testNewsletterGuardFindsTheProjectlessNewsletter(): void
    {
        Capsule::connection()->insert(sprintf(
            'INSERT INTO %s (project_id) VALUES (3), (NULL)',
            self::NEWSLETTERS_TABLE
        ));

        $this->assertSame(1, $this->countFrom(
            AllowNewslettersWithoutProject::PROJECTLESS_NEWSLETTERS_SQL,
            ['newsletters' => self::NEWSLETTERS_TABLE]
        ));
    }

    public function testNewsletterGuardStaysQuietWhenEveryNewsletterHasAProject(): void
    {
        Capsule::connection()->insert(sprintf(
            'INSERT INTO %s (project_id) VALUES (3), (4)',
            self::NEWSLETTERS_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            AllowNewslettersWithoutProject::PROJECTLESS_NEWSLETTERS_SQL,
            ['newsletters' => self::NEWSLETTERS_TABLE]
        ));
    }

    public function testJournalGuardFindsBothTheLockEntryAndTheEntryWithoutBooking(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (finance_id, action) VALUES
             (5, 'create'), (NULL, 'lock'), (NULL, 'update')",
            self::REVISIONS_TABLE
        ));

        $this->assertSame(2, $this->countFrom(
            AllowLockEntriesInFinanceJournal::UNFITTING_REVISIONS_SQL,
            ['finance_revisions' => self::REVISIONS_TABLE]
        ));
    }

    public function testJournalGuardStaysQuietWhenEveryEntryFitsTheNarrowerColumn(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (finance_id, action) VALUES (5, 'create'), (6, 'reverse')",
            self::REVISIONS_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            AllowLockEntriesInFinanceJournal::UNFITTING_REVISIONS_SQL,
            ['finance_revisions' => self::REVISIONS_TABLE]
        ));
    }

    /**
     * Dieselbe Anweisung wie in der Migration, nur auf den Wegwerf-Tabellen.
     *
     * @param array<string, string> $tableMap
     */
    private function countFrom(string $sql, array $tableMap): int
    {
        $row = Capsule::connection()->selectOne(strtr($sql, $tableMap));

        return (int) $row->orphaned;
    }

    private function dropScratchTables(): void
    {
        $tables = [
            self::AUDIENCE_TABLE,
            self::EVENTS_TABLE,
            self::BUDGET_TABLE,
            self::NEWSLETTERS_TABLE,
            self::REVISIONS_TABLE,
        ];

        foreach ($tables as $table) {
            Capsule::connection()->statement('DROP TABLE IF EXISTS ' . $table);
        }
    }
}

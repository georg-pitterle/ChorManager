<?php

declare(strict_types=1);

namespace Tests\Feature;

use AddChannelToUserNotificationSettings;
use AllowLockEntriesInFinanceJournal;
use AllowMultipleTaskAssignees;
use AllowNewslettersWithoutProject;
use CreateFinanceAccounts;
use CreateFinanceGroupsAndLink;
use DropEventsProjectId;
use RequireKnownAttendanceStatus;
use RequireKnownSongResourceType;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;
use UseEnglishTaskStatusValues;

require_once dirname(__DIR__, 2) . '/db/migrations/20260621120000_create_finance_groups_and_link.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20260815140000_create_finance_accounts.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20260830120000_allow_multiple_task_assignees.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20260722130000_drop_events_project_id.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20260811190000_allow_newsletters_without_project.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20260826120000_allow_lock_entries_in_finance_journal.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20261005090100_add_channel_to_user_notification_settings.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20261022120000_require_known_attendance_status.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20261022130000_use_english_task_status_values.php';
require_once dirname(__DIR__, 2) . '/db/migrations/20261029120000_require_known_song_resource_type.php';

/**
 * Fünf Migrationen führen einen Schritt aus, der Daten unwiederbringlich
 * beseitigt: zwei werfen im `up()` eine Spalte weg, deren Inhalt vorher
 * woandershin umgeschrieben wurde, drei müssten im `down()` Zeilen loswerden,
 * die in den engeren Schlüssel oder die engere Spalte nicht mehr passen.
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
    private const SETTINGS_TABLE = 'test_guard_user_notification_settings';
    private const ASSIGNEES_TABLE = 'test_guard_task_assignees';
    private const GROUPS_TABLE = 'test_guard_finance_groups';
    private const FINANCES_TABLE = 'test_guard_finances';
    private const ACCOUNTS_TABLE = 'test_guard_finance_accounts';
    private const ATTENDANCE_TABLE = 'test_guard_attendance';
    private const TASKS_TABLE = 'test_guard_tasks';
    private const SONG_RESOURCES_TABLE = 'test_guard_song_resources';

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

        Capsule::connection()->statement(sprintf(
            "CREATE TABLE %s (
                user_id int(11) NOT NULL,
                notification_type varchar(64) NOT NULL,
                channel varchar(16) NOT NULL DEFAULT 'mail',
                PRIMARY KEY (user_id, notification_type, channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            self::SETTINGS_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                task_id int(11) NOT NULL,
                user_id int(11) NOT NULL,
                PRIMARY KEY (task_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::ASSIGNEES_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::GROUPS_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                group_name varchar(255) DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::FINANCES_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                iban varchar(34) DEFAULT NULL,
                opening_balance decimal(10,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::ACCOUNTS_TABLE
        ));

        // Bewusst varchar und nicht ENUM: Die Prüfung soll gerade den Wert
        // finden, den das ENUM nicht mehr zulassen würde.
        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                status varchar(50) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::ATTENDANCE_TABLE
        ));

        // Gleiche Begründung wie oben: varchar, damit die Prüfung die Art
        // findet, die das ENUM nicht mehr zulassen würde.
        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                resource_type varchar(32) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::SONG_RESOURCES_TABLE
        ));

        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                status varchar(32) NOT NULL,
                priority varchar(32) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::TASKS_TABLE
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

    /**
     * Der Rückbau von 20261005090100 nimmt die Spalte `channel` zurück. Was an
     * der Glocke hängt, hat im alten Schlüssel keinen Platz - bisher wurde es
     * dafür still gelöscht, obwohl es Entscheidungen von Mitgliedern sind.
     */
    public function testBellSettingGuardFindsTheSettingThatIsNotAboutMail(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (user_id, notification_type, channel) VALUES
             (1, 'task_comment', 'mail'), (1, 'task_comment', 'in_app')",
            self::SETTINGS_TABLE
        ));

        $this->assertSame(1, $this->countFrom(
            AddChannelToUserNotificationSettings::BELL_SETTINGS_SQL,
            ['user_notification_settings' => self::SETTINGS_TABLE],
            'bell_settings'
        ));
    }

    public function testBellSettingGuardStaysQuietWhenEverySettingIsAboutMail(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (user_id, notification_type, channel) VALUES
             (1, 'task_comment', 'mail'), (2, 'event_cancelled', 'mail')",
            self::SETTINGS_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            AddChannelToUserNotificationSettings::BELL_SETTINGS_SQL,
            ['user_notification_settings' => self::SETTINGS_TABLE],
            'bell_settings'
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
     * Der Rückbau von 20260830120000 schreibt je Aufgabe **eine** Zuweisung
     * zurück. Die Prüfung darf deshalb nicht alle Zeilen zählen, sondern genau
     * die, für die in `tasks.assigned_to` kein Platz ist.
     */
    public function testAssigneeGuardFindsOnlyTheSurplusAssignments(): void
    {
        // Aufgabe 1 hat drei Zugewiesene: zwei davon fallen weg. Aufgabe 2 hat
        // genau einen und verliert nichts.
        Capsule::connection()->insert(sprintf(
            'INSERT INTO %s (task_id, user_id) VALUES (1, 4), (1, 7), (1, 9), (2, 5)',
            self::ASSIGNEES_TABLE
        ));

        $this->assertSame(2, $this->countFrom(
            AllowMultipleTaskAssignees::SURPLUS_ASSIGNEES_SQL,
            ['task_assignees' => self::ASSIGNEES_TABLE],
            'dropped'
        ));
    }

    public function testAssigneeGuardStaysQuietWhenEveryTaskHasOneAssignee(): void
    {
        Capsule::connection()->insert(sprintf(
            'INSERT INTO %s (task_id, user_id) VALUES (1, 4), (2, 5), (3, 6)',
            self::ASSIGNEES_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            AllowMultipleTaskAssignees::SURPLUS_ASSIGNEES_SQL,
            ['task_assignees' => self::ASSIGNEES_TABLE],
            'dropped'
        ));
    }

    /**
     * Der Rückbau von 20260621120000 schreibt die Gruppennamen nach
     * budget_categories.group_name zurück, und finances.group_name stand
     * ohnehin daneben. Nur eine Gruppe, die in keiner der beiden Spalten
     * vorkommt, ist nach dem DROP TABLE nirgends mehr benannt.
     */
    public function testFinanceGroupGuardFindsOnlyTheGroupThatIsNamedNowhere(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (name) VALUES ('Miete'), ('Noten'), ('Reise')",
            self::GROUPS_TABLE
        ));
        // "Miete" überlebt in der Budget-Kategorie, "Noten" im Spiegelfeld der
        // Buchung - nur "Reise" wäre weg.
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (group_name, finance_group_id) VALUES ('Miete', 1)",
            self::BUDGET_TABLE
        ));
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (group_name) VALUES ('Noten')",
            self::FINANCES_TABLE
        ));

        $this->assertSame(1, $this->countFrom(
            CreateFinanceGroupsAndLink::VANISHING_FINANCE_GROUPS_SQL,
            [
                'finance_groups' => self::GROUPS_TABLE,
                'budget_categories' => self::BUDGET_TABLE,
                'finances' => self::FINANCES_TABLE,
            ],
            'vanishing'
        ));
    }

    public function testFinanceGroupGuardStaysQuietWhenEveryGroupIsNamedSomewhere(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (name) VALUES ('Miete'), ('Noten')",
            self::GROUPS_TABLE
        ));
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (group_name, finance_group_id) VALUES ('Miete', 1)",
            self::BUDGET_TABLE
        ));
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (group_name) VALUES ('Noten')",
            self::FINANCES_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            CreateFinanceGroupsAndLink::VANISHING_FINANCE_GROUPS_SQL,
            [
                'finance_groups' => self::GROUPS_TABLE,
                'budget_categories' => self::BUDGET_TABLE,
                'finances' => self::FINANCES_TABLE,
            ],
            'vanishing'
        ));
    }

    /**
     * Der Rückbau von 20260815140000 darf die beiden unberührten Vorgabekonten
     * mitnehmen - sie stammen aus dem eigenen `up()`. Alles andere ist Eingabe.
     */
    public function testAccountGuardFindsTheAccountsThatAreNotUntouchedDefaults(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (name, iban, opening_balance) VALUES
             ('Barkassa', NULL, '0.00'),
             ('Bankkonto', 'AT611904300234573201', '0.00'),
             ('Projektkonto', NULL, '0.00'),
             ('Bankkonto', NULL, '150.00')",
            self::ACCOUNTS_TABLE
        ));

        // Unberührt ist nur die Barkassa; die anderen drei tragen eine IBAN,
        // einen eigenen Namen oder einen geänderten Anfangsstand.
        $this->assertSame(3, $this->countFrom(
            CreateFinanceAccounts::USER_OWNED_ACCOUNTS_SQL,
            ['finance_accounts' => self::ACCOUNTS_TABLE],
            'rows_left'
        ));
    }

    public function testAccountGuardStaysQuietForTheUntouchedDefaults(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (name, iban, opening_balance) VALUES
             ('Barkassa', NULL, '0.00'), ('Bankkonto', NULL, '0.00')",
            self::ACCOUNTS_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            CreateFinanceAccounts::USER_OWNED_ACCOUNTS_SQL,
            ['finance_accounts' => self::ACCOUNTS_TABLE],
            'rows_left'
        ));
    }

    /**
     * Dieselbe Anweisung wie in der Migration, nur auf den Wegwerf-Tabellen.
     *
     * @param array<string, string> $tableMap
     */
    /**
     * 20261022120000 verengt `attendance.status` von varchar auf ein ENUM. Ein
     * Wert, den das ENUM nicht kennt, wird vom ALTER zur leeren Zeichenkette -
     * die Erfassung wäre dann still weg.
     */
    public function testAttendanceGuardFindsTheStatusOutsideTheEnum(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (status) VALUES ('present'), ('unknown'), ('anwesend')",
            self::ATTENDANCE_TABLE
        ));

        $this->assertSame(1, $this->countFrom(
            RequireKnownAttendanceStatus::UNKNOWN_STATUSES_SQL,
            ['attendance' => self::ATTENDANCE_TABLE],
            'unknown_statuses'
        ));
    }

    public function testAttendanceGuardStaysQuietWhenEveryStatusIsKnown(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (status) VALUES ('present'), ('excused'), ('unexcused'), ('unknown')",
            self::ATTENDANCE_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            RequireKnownAttendanceStatus::UNKNOWN_STATUSES_SQL,
            ['attendance' => self::ATTENDANCE_TABLE],
            'unknown_statuses'
        ));
    }

    /**
     * 20261029120000 verengt `song_resources.resource_type` auf ein ENUM mit
     * dem einzigen Wert `link`. Jede andere Art würde das ALTER zur leeren
     * Zeichenkette machen, und die Quelle hinge danach an keiner Art mehr.
     */
    public function testSongResourceGuardFindsTheTypeOutsideTheEnum(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (resource_type) VALUES ('link'), ('link'), ('lnik')",
            self::SONG_RESOURCES_TABLE
        ));

        $this->assertSame(1, $this->countFrom(
            RequireKnownSongResourceType::UNKNOWN_TYPES_SQL,
            ['song_resources' => self::SONG_RESOURCES_TABLE],
            'unknown_types'
        ));
    }

    public function testSongResourceGuardStaysQuietWhenEveryTypeIsKnown(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (resource_type) VALUES ('link'), ('link')",
            self::SONG_RESOURCES_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            RequireKnownSongResourceType::UNKNOWN_TYPES_SQL,
            ['song_resources' => self::SONG_RESOURCES_TABLE],
            'unknown_types'
        ));
    }

    /**
     * 20261022130000 schreibt Zustand und Dringlichkeit der Aufgaben auf
     * englische Bezeichner um. Was die Zuordnung nicht kennt, überlebt das
     * UPDATE unverändert und wird vom folgenden ENUM zur leeren Zeichenkette.
     *
     * Die Prüfung deckt beide Richtungen ab - deutsche und englische Werte
     * gelten als abgebildet -, weil dieselbe Abfrage auch im `down()` läuft.
     */
    public function testTaskGuardFindsTheUnmappedStatusAndPriority(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (status, priority) VALUES
             ('Offen', 'Mittel'), ('done', 'high'), ('Wartet', 'Mittel'), ('Offen', 'Dringend')",
            self::TASKS_TABLE
        ));

        $this->assertSame(2, $this->countFrom(
            UseEnglishTaskStatusValues::UNMAPPED_TASKS_SQL,
            ['tasks' => self::TASKS_TABLE],
            'unmapped_rows'
        ));
    }

    public function testTaskGuardStaysQuietForBothSpellings(): void
    {
        Capsule::connection()->insert(sprintf(
            "INSERT INTO %s (status, priority) VALUES
             ('Offen', 'Niedrig'), ('In Bearbeitung', 'Mittel'), ('Abgeschlossen', 'Hoch'),
             ('open', 'low'), ('in_progress', 'medium'), ('blocked', 'high'), ('done', 'low')",
            self::TASKS_TABLE
        ));

        $this->assertSame(0, $this->countFrom(
            UseEnglishTaskStatusValues::UNMAPPED_TASKS_SQL,
            ['tasks' => self::TASKS_TABLE],
            'unmapped_rows'
        ));
    }

    private function countFrom(string $sql, array $tableMap, string $column = 'orphaned'): int
    {
        $row = Capsule::connection()->selectOne(strtr($sql, $tableMap));

        return (int) $row->{$column};
    }

    private function dropScratchTables(): void
    {
        $tables = [
            self::AUDIENCE_TABLE,
            self::EVENTS_TABLE,
            self::BUDGET_TABLE,
            self::NEWSLETTERS_TABLE,
            self::REVISIONS_TABLE,
            self::SETTINGS_TABLE,
            self::ASSIGNEES_TABLE,
            self::GROUPS_TABLE,
            self::FINANCES_TABLE,
            self::ACCOUNTS_TABLE,
            self::ATTENDANCE_TABLE,
            self::TASKS_TABLE,
            self::SONG_RESOURCES_TABLE,
        ];

        foreach ($tables as $table) {
            Capsule::connection()->statement('DROP TABLE IF EXISTS ' . $table);
        }
    }
}

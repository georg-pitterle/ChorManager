<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CalendarSubscriptionService;
use DropPlaintextCalendarSubscriptionTokens;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

require_once dirname(__DIR__, 2) . '/db/migrations/20260901122000_drop_plaintext_calendar_subscription_tokens.php';

/**
 * 20260901122000 wirft den Klartext nicht weg, sondern rechnet ihn in den Hash
 * um - nur deshalb müssen die vor der Umstellung verteilten Abos nicht neu
 * angelegt werden.
 *
 * Das trägt genau eine Annahme: `SHA2(token, 256)` in MySQL liefert dasselbe wie
 * `hash('sha256', ...)` in PHP. Stimmt das nicht, wandert jedes Altbestands-Abo
 * auf einen Hash, den der Feed nie berechnet - die Abos wären still tot, und die
 * Migration hätte den Klartext bereits entfernt. Der Test prüft die Annahme
 * gegen die echte Datenbank statt gegen eine Nachbildung.
 */
final class CalendarTokenHashBackfillTest extends TestCase
{
    private const TABLE = 'test_calendar_subscription_tokens';

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->dropScratchTable();
        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                user_id int(11) NOT NULL,
                token varchar(64) DEFAULT NULL,
                token_hash char(64) DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::TABLE
        ));
    }

    protected function tearDown(): void
    {
        $this->dropScratchTable();
        parent::tearDown();
    }

    public function testMysqlHashMatchesTheHashTheFeedComputes(): void
    {
        $token = bin2hex(random_bytes(32));

        $row = Capsule::connection()->selectOne(
            'SELECT SHA2(?, 256) AS hashed',
            [$token]
        );

        $this->assertSame(
            CalendarSubscriptionService::hashToken($token),
            $row->hashed,
            'Backfill und Feed müssen denselben Hash berechnen, sonst brechen die Altbestände.'
        );
    }

    public function testBackfillConvertsPlaintextAndLeavesHashedRowsAlone(): void
    {
        $legacyToken = bin2hex(random_bytes(32));
        $alreadyHashed = CalendarSubscriptionService::hashToken(bin2hex(random_bytes(32)));

        Capsule::connection()->insert(
            sprintf('INSERT INTO %s (user_id, token, token_hash) VALUES (1, ?, NULL), (2, NULL, ?)', self::TABLE),
            [$legacyToken, $alreadyHashed]
        );

        Capsule::connection()->statement($this->backfillForScratchTable());

        $legacyRow = Capsule::connection()->selectOne(
            sprintf('SELECT token_hash FROM %s WHERE user_id = 1', self::TABLE)
        );
        $hashedRow = Capsule::connection()->selectOne(
            sprintf('SELECT token_hash FROM %s WHERE user_id = 2', self::TABLE)
        );

        $this->assertSame(
            CalendarSubscriptionService::hashToken($legacyToken),
            $legacyRow->token_hash,
            'Der Altbestand muss auf genau den Hash wandern, den der Feed nachschlägt.'
        );
        $this->assertSame(
            $alreadyHashed,
            $hashedRow->token_hash,
            'Bereits umgestellte Zeilen bleiben unangetastet.'
        );
    }

    /**
     * Dieselbe Anweisung wie in der Migration, nur auf der Wegwerf-Tabelle.
     */
    private function backfillForScratchTable(): string
    {
        return str_replace(
            'calendar_subscription_tokens',
            self::TABLE,
            DropPlaintextCalendarSubscriptionTokens::BACKFILL_SQL
        );
    }

    private function dropScratchTable(): void
    {
        Capsule::connection()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }
}

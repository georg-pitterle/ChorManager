<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use PurgeTokensFromMailQueuePayloads;
use Tests\Unit\Bootstrap;

require_once dirname(__DIR__, 2) . '/db/migrations/20261104120000_purge_tokens_from_mail_queue_payloads.php';

/**
 * 20261104120000 greift in bestehende Nutzlasten ein - und zwar mit einer
 * JSON-Anweisung, die mehr kaputt machen kann als sie soll: `JSON_REMOVE` auf
 * eine Spalte, die kein lesbares JSON trägt, liefert NULL und hätte die ganze
 * Nutzlast gelöscht, nicht nur den Token.
 *
 * Geprüft wird deshalb die echte Anweisung gegen eine Wegwerf-Tabelle in der
 * echten Datenbank, nicht eine Nachbildung: Was `JSON_REMOVE`,
 * `JSON_CONTAINS_PATH` und `JSON_VALID` in dieser MariaDB-Fassung tun, ist
 * genau die Annahme, auf der die Migration steht.
 */
final class MailQueuePayloadTokenPurgeTest extends TestCase
{
    private const TABLE = 'test_mail_queue_payloads';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $this->dropScratchTable();
        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                id int(11) NOT NULL AUTO_INCREMENT,
                mail_type varchar(40) NOT NULL,
                payload_json text DEFAULT NULL,
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

    public function testTheTokensGoAndEverythingElseStays(): void
    {
        $this->insert(1, 'password_reset', '{"user_id":42,"reset_token":"geheim"}'); // naming:ascii
        $this->insert(2, 'invitation', '{"user_id":43,"invitation_token":"geheim"}'); // naming:ascii
        $this->insert(3, 'invitation', '{"user_id":44}');
        $this->insert(4, 'newsletter', '{"newsletter_id":7,"recipient_id":9}');

        Capsule::connection()->statement($this->purgeForScratchTable());

        $this->assertSame(['user_id' => 42], $this->payloadOf(1));
        $this->assertSame(['user_id' => 43], $this->payloadOf(2));
        $this->assertSame(['user_id' => 44], $this->payloadOf(3), 'Ohne Token bleibt die Zeile unangetastet.');
        $this->assertSame(
            ['newsletter_id' => 7, 'recipient_id' => 9],
            $this->payloadOf(4),
            'Eine Newsletter-Nutzlast trug nie einen Token und wird nicht angefasst.'
        );
    }

    /**
     * Der Fall, der die Nutzlast gekostet hätte: Ohne `JSON_VALID` macht
     * `JSON_REMOVE` aus unlesbarem Inhalt NULL - aus einer kaputten Zeile würde
     * eine leere, und mit ihr verschwänden `user_id` und jede Spur.
     */
    public function testAnUnreadablePayloadSurvivesUntouched(): void
    {
        $this->insert(1, 'password_reset', 'kein lesbares JSON');
        $this->insert(2, 'password_reset', null);

        Capsule::connection()->statement($this->purgeForScratchTable());

        $this->assertSame('kein lesbares JSON', $this->rawPayloadOf(1));
        $this->assertNull($this->rawPayloadOf(2));
    }

    private function insert(int $id, string $mailType, ?string $payload): void
    {
        Capsule::connection()->insert(
            sprintf('INSERT INTO %s (id, mail_type, payload_json) VALUES (?, ?, ?)', self::TABLE),
            [$id, $mailType, $payload]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(int $id): array
    {
        return (array) json_decode((string) $this->rawPayloadOf($id), true);
    }

    private function rawPayloadOf(int $id): ?string
    {
        $row = Capsule::connection()->selectOne(
            sprintf('SELECT payload_json FROM %s WHERE id = ?', self::TABLE),
            [$id]
        );

        return $row->payload_json === null ? null : (string) $row->payload_json;
    }

    /**
     * Dieselbe Anweisung wie in der Migration, nur auf der Wegwerf-Tabelle.
     */
    private function purgeForScratchTable(): string
    {
        return str_replace('mail_queue', self::TABLE, PurgeTokensFromMailQueuePayloads::PURGE_SQL);
    }

    private function dropScratchTable(): void
    {
        Capsule::connection()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }
}

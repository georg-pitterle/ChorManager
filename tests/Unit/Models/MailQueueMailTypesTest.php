<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\MailQueue;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Mailarten der Warteschlange stehen als Konstanten im Modell, und jede
 * trägt ein deutsches Label.
 *
 * `status` und `delivery_status` waren längst so modelliert, `mail_type` nicht:
 * Die fünf ENUM-Werte standen als Zeichenketten im MailQueueService, im
 * MailDeliveryService, im Seed und zweimal als Twig-Zuordnung in den Vorlagen.
 * Die Zuordnung in den Vorlagen kannte nur drei davon - `registration_reminder`
 * und `notification` fielen durch den `default`-Filter und standen in der
 * deutschen Oberfläche als englischer Schlüssel da.
 *
 * Geprüft wird gegen das ENUM der Spalte, nicht gegen eine Liste im Test: Eine
 * Migration, die eine sechste Mailart aufnimmt, soll hier auffliegen und nicht
 * erst in der Oberfläche.
 */
final class MailQueueMailTypesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    /**
     * @return list<string>
     */
    private function enumValues(): array
    {
        $row = Capsule::connection()->selectOne(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['mail_queue', 'mail_type']
        );
        $type = is_object($row) ? (string) ($row->column_type ?? '') : '';

        self::assertNotSame('', $type, 'Die Spalte mail_queue.mail_type wurde nicht gefunden.');
        self::assertStringStartsWith('enum(', $type, 'mail_queue.mail_type ist kein ENUM.');

        preg_match_all("/'((?:[^']|'')*)'/", $type, $matches);

        $values = array_map(
            static fn (string $value): string => str_replace("''", "'", $value),
            $matches[1]
        );
        sort($values);

        return $values;
    }

    public function testJedeMailartDesEnumsStehtAlsKonstante(): void
    {
        $declared = array_map('strval', MailQueue::SUPPORTED_MAIL_TYPES);
        sort($declared);

        self::assertSame($this->enumValues(), $declared);
    }

    public function testJedeMailartHatEinDeutschesLabel(): void
    {
        $labelled = array_map('strval', array_keys(MailQueue::MAIL_TYPE_LABELS));
        sort($labelled);

        self::assertSame($this->enumValues(), $labelled);

        foreach (MailQueue::MAIL_TYPE_LABELS as $mailType => $label) {
            self::assertNotSame('', trim($label), sprintf('Mailart "%s" hat kein Label.', $mailType));
            self::assertNotSame(
                $mailType,
                $label,
                sprintf('Mailart "%s" trägt den Schlüssel als Label - das ist kein deutscher Text.', $mailType)
            );
        }
    }

    /**
     * Die Mailarten mit Einmal-Link im Text sind eine Teilmenge der Mailarten,
     * nicht eine zweite Aufzählung daneben.
     */
    public function testDieGeheimnisTragendenMailartenSindEchteMailarten(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(MailQueue::SECRET_BODY_MAIL_TYPES, MailQueue::SUPPORTED_MAIL_TYPES))
        );
    }
}

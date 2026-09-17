<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Concerns\MailDeliveryIngest;
use PHPUnit\Framework\TestCase;

/**
 * Die Umformung der Provider-Rückmeldung stand wortgleich in beiden
 * Ingest-Controllern und war nur über eine echte HTTP-Anfrage erreichbar.
 * Im gemeinsamen Trait lässt sie sich für sich prüfen - und dieser Test hält
 * fest, was die Doppelung verdeckt hatte.
 *
 * Geprüft wird über eine namenlose Klasse, die das Trait einbindet und seine
 * Umformung nach außen reicht. Das ist der Preis eines Traits gegenüber einem
 * eigenen Dienst: Es gibt nichts zu instanziieren, also muss der Test sich ein
 * Gefäß bauen - und die Kanal-Konstanten sind nur über eine einbindende Klasse
 * erreichbar, `MailDeliveryIngest::CHANNEL_WEBHOOK` wirft "Cannot access trait
 * constant ... directly".
 */
class MailDeliveryIngestTraitTest extends TestCase
{
    private object $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new class {
            use MailDeliveryIngest;

            /**
             * @param array<array-key, mixed> $payload
             * @return array<string, mixed>
             */
            public function normalize(
                string $provider,
                string $sourceChannel,
                array $payload,
                string $rawBody
            ): array {
                return $this->normalizePayload($provider, $sourceChannel, $payload, $rawBody);
            }
        };
    }

    public function testTheChannelDecidesTheIdempotencyKey(): void
    {
        $rawBody = '{"x":1}';

        $webhook = $this->normalizer->normalize(
            'smtp2go',
            $this->normalizer::CHANNEL_WEBHOOK,
            [],
            $rawBody
        );
        $dsn = $this->normalizer->normalize(
            'smtp2go',
            $this->normalizer::CHANNEL_DSN,
            [],
            $rawBody
        );

        // Dieselbe Nutzlast über zwei Wege ist nicht dasselbe Ereignis.
        $this->assertNotSame($webhook['idempotency_key'], $dsn['idempotency_key']);

        // Genau die Zeichenkette, die die Webhook-Fassung vorher wörtlich baute -
        // der Wert bestehender Einträge ändert sich durch den Umbau nicht.
        $this->assertSame(
            hash('sha256', 'smtp2go|webhook|' . $rawBody),
            $webhook['idempotency_key']
        );
        $this->assertSame(
            hash('sha256', 'smtp2go|dsn|' . $rawBody),
            $dsn['idempotency_key']
        );
    }

    public function testAnOwnIdempotencyKeyOfTheProviderWins(): void
    {
        $result = $this->normalizer->normalize(
            'smtp2go',
            $this->normalizer::CHANNEL_WEBHOOK,
            ['idempotency_key' => '  abc-123  '],
            '{}'
        );

        $this->assertSame('abc-123', $result['idempotency_key']);
    }

    public function testEventTypeFallsBackAlongTheDocumentedChain(): void
    {
        $onlyPlain = $this->normalizer->normalize(
            'smtp2go',
            $this->normalizer::CHANNEL_DSN,
            ['event_type' => 'Bounced'],
            '{}'
        );
        $this->assertSame('bounced', $onlyPlain['event_type_normalized']);
        $this->assertSame('Bounced', $onlyPlain['event_type_raw']);

        $nothing = $this->normalizer->normalize(
            'smtp2go',
            $this->normalizer::CHANNEL_DSN,
            [],
            '{}'
        );
        $this->assertSame('unknown', $nothing['event_type_normalized']);
        $this->assertSame('unknown', $nothing['event_type_raw']);
    }

    public function testAnEmptyProviderMessageIdBecomesNullInsteadOfAnEmptyString(): void
    {
        $result = $this->normalizer->normalize(
            'smtp2go',
            $this->normalizer::CHANNEL_WEBHOOK,
            ['provider_message_id' => '   '],
            '{}'
        );

        $this->assertNull($result['provider_message_id']);
    }

    /**
     * Ein Provider, der ein Feld als Objekt oder Liste schickt, darf den Eingang
     * nicht mit einer PHP-Warnung und der Zeichenkette "Array" füllen.
     */
    public function testNestedFieldsAreDroppedInsteadOfBecomingTheWordArray(): void
    {
        $result = $this->normalizer->normalize(
            'smtp2go',
            $this->normalizer::CHANNEL_WEBHOOK,
            [
                'event_type' => ['bounced'],
                'provider_message_id' => ['x'],
                'occurred_at' => ['2026-01-01'],
            ],
            '{}'
        );

        $this->assertSame('', $result['event_type_normalized']);
        $this->assertNull($result['provider_message_id']);
        // Kein lesbarer Zeitpunkt: Der Eingangszeitpunkt tritt an seine Stelle.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['occurred_at']);
    }

    public function testTheChannelAndRawBodyAreCarriedThroughUnchanged(): void
    {
        $result = $this->normalizer->normalize(
            'mailgun',
            $this->normalizer::CHANNEL_DSN,
            ['mail_queue_id' => '42'],
            '{"raw":true}'
        );

        $this->assertSame('dsn', $result['source_channel']);
        $this->assertSame('mailgun', $result['provider_name']);
        $this->assertSame('{"raw":true}', $result['raw_payload']);
        $this->assertSame(42, $result['mail_queue_id']);
    }
}

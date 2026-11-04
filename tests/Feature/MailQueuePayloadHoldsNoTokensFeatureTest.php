<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Services\MailQueueService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Einmal-Link einer Zurücksetzung oder Einladung gehört nicht in die
 * Warteschlange.
 *
 * MailDeliveryService leert `body_html` einer solchen Mail nach dem Zustellen
 * genau aus diesem Grund: Zugestellt ist der Link beim Empfänger, in der
 * Warteschlange wäre er nur noch ein zweiter Weg zum Konto. Dieselbe Zeichenkette
 * stand aber zusätzlich in `payload_json` - und die wird nie geleert. Gelesen hat
 * sie niemand: Der Token selbst lebt in `password_resets` beziehungsweise
 * `invitation_tokens`, aus dem Payload holt sich nur `user_id`, `newsletter_id`
 * und `recipient_id` jemand ab.
 *
 * Deshalb steht er dort nicht mehr. Dieser Test hält das fest, damit ein später
 * ergänzter Anlass ihn nicht wieder mitschreibt.
 */
final class MailQueuePayloadHoldsNoTokensFeatureTest extends TestCase
{
    private const RESET_TOKEN = 'zuruecksetzen-geheim-0123456789'; // naming:ascii
    private const INVITATION_TOKEN = 'einladung-geheim-0123456789'; // naming:ascii

    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->createdIds !== []) {
            MailQueue::whereIn('id', $this->createdIds)->delete();
        }

        parent::tearDown();
    }

    public function testAPasswordResetKeepsItsTokenOutOfThePayload(): void
    {
        $entry = (new MailQueueService())->enqueuePasswordResetMail(
            'mitglied@example.org',
            'Passwort zurücksetzen',
            '<p>Link: https://example.test/reset/' . self::RESET_TOKEN . '</p>',
            42
        );
        $this->createdIds[] = (int) $entry->id;

        $this->assertSame(['user_id' => 42], $entry->payload_json);
        $this->assertStringNotContainsString(
            self::RESET_TOKEN,
            (string) Capsule::table('mail_queue')->where('id', (int) $entry->id)->value('payload_json')
        );
    }

    public function testAnInvitationKeepsItsTokenOutOfThePayload(): void
    {
        $entry = (new MailQueueService())->enqueueInvitationMail(
            'neu@example.org',
            'Einladung',
            '<p>Link: https://example.test/invite/' . self::INVITATION_TOKEN . '</p>',
            43
        );
        $this->createdIds[] = (int) $entry->id;

        // `user_id` bleibt: MailDeliveryService::recordInvitationSent() hält damit
        // am Mitglied fest, dass der Mailserver die Einladung angenommen hat.
        $this->assertSame(['user_id' => 43], $entry->payload_json);
        $this->assertStringNotContainsString(
            self::INVITATION_TOKEN,
            (string) Capsule::table('mail_queue')->where('id', (int) $entry->id)->value('payload_json')
        );
    }
}

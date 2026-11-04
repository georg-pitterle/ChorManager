<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Services\MailDeliveryService;
use App\Services\MailQueueAdminService;
use App\Services\Mailer;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Einmal-Link einer Zurücksetzung oder Einladung verschwand bisher nur nach
 * erfolgreichem Versand aus `body_html`. Schaffte die Mail es nie hinaus und
 * landete endgültig auf `dead`, blieb der volle Text stehen - und die
 * Einzelansicht der Warteschlange zeigt ihn in einem Rahmen an. Das Recht
 * "Mailversand verwalten" reichte damit, um den Link zu einem fremden Konto zu
 * lesen, auch zu einem mit mehr Rechten.
 *
 * Eine endgültig liegengebliebene Mail braucht ihren Text nicht mehr: Sie geht
 * nicht mehr hinaus. Deshalb fällt er dort genauso wie beim Erfolg - und mit ihm
 * die Möglichkeit, sie von Hand erneut einzureihen, denn ohne Text ginge sie
 * leer hinaus.
 */
final class MailQueueDeadSecretBodyFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADeadPasswordResetLosesItsBody(): void
    {
        $entry = $this->createEntry(MailQueue::MAIL_TYPE_PASSWORD_RESET);

        $this->failUntilDead($entry);

        $reloaded = MailQueue::find($entry->id);
        $this->assertInstanceOf(MailQueue::class, $reloaded);
        $this->assertSame(MailQueue::STATUS_DEAD, $reloaded->status);
        $this->assertNull($reloaded->body_html);

        // Empfänger, Betreff und Fehlertext bleiben - ohne sie wäre die Zeile
        // für die Fehlersuche wertlos, und keiner der drei trägt ein Geheimnis.
        $this->assertSame('empfaenger@example.test', $reloaded->recipient_email); // naming:ascii
        $this->assertNotNull($reloaded->subject);
        $this->assertNotNull($reloaded->error_message);
    }

    public function testADeadInvitationLosesItsBody(): void
    {
        $entry = $this->createEntry(MailQueue::MAIL_TYPE_INVITATION);

        $this->failUntilDead($entry);

        $reloaded = MailQueue::find($entry->id);
        $this->assertInstanceOf(MailQueue::class, $reloaded);
        $this->assertNull($reloaded->body_html);
    }

    /**
     * Die Gegenprobe: Ein Newsletter trägt kein Geheimnis im Text, und für ihn
     * ist der Knopf "erneut einreihen" der einzige Weg zurück.
     */
    public function testADeadNewsletterKeepsItsBodyAndStaysRetryable(): void
    {
        $entry = $this->createEntry(MailQueue::MAIL_TYPE_NEWSLETTER);

        $this->failUntilDead($entry);

        $reloaded = MailQueue::find($entry->id);
        $this->assertInstanceOf(MailQueue::class, $reloaded);
        $this->assertSame(MailQueue::STATUS_DEAD, $reloaded->status);
        $this->assertSame('<p>Link: https://example.test/x/geheim</p>', $reloaded->body_html);
        $this->assertTrue($reloaded->canRetry());
    }

    public function testASecretMailCannotBeRetriedByHand(): void
    {
        $entry = $this->createEntry(MailQueue::MAIL_TYPE_PASSWORD_RESET);
        $this->failUntilDead($entry);

        $reloaded = MailQueue::find($entry->id);
        $this->assertInstanceOf(MailQueue::class, $reloaded);
        $this->assertFalse($reloaded->canRetry());

        $this->expectException(\Exception::class);
        (new MailQueueAdminService())->retrySingle((int) $entry->id);
    }

    /**
     * "Alle liegengebliebenen erneut einreihen" darf die geleerten nicht
     * mitnehmen: Sie gingen ohne Text hinaus und wären damit eine leere Mail an
     * ein Mitglied.
     */
    public function testRetryAllDeadSkipsTheSecretOnes(): void
    {
        $secret = $this->createEntry(MailQueue::MAIL_TYPE_PASSWORD_RESET);
        $newsletter = $this->createEntry(MailQueue::MAIL_TYPE_NEWSLETTER);
        $this->failUntilDead($secret);
        $this->failUntilDead($newsletter);

        $this->assertSame(1, (new MailQueueAdminService())->retryAllDead());

        $this->assertSame(MailQueue::STATUS_DEAD, MailQueue::find($secret->id)?->status);
        $this->assertSame(MailQueue::STATUS_QUEUED, MailQueue::find($newsletter->id)?->status);
    }

    /**
     * Die Übersicht lädt nur `MailQueue::LIST_COLUMNS`. Fehlte dort eine Spalte,
     * die `canRetry()` liest, wäre sie am geladenen Modell null - und der Knopf
     * stünde für eine Mail da, die ihn nicht haben darf, ohne dass irgendwo ein
     * Fehler auftaucht.
     */
    public function testTheListColumnsCarryWhatTheRetryDecisionReads(): void
    {
        foreach (['status', 'mail_type'] as $column) {
            $this->assertContains(
                $column,
                MailQueue::LIST_COLUMNS,
                sprintf('canRetry() liest %s; ohne die Spalte entscheidet die Übersicht blind.', $column)
            );
        }
    }

    /**
     * Beide Vorlagen fragen das Modell, nicht den Status. Die Frage "darf das
     * erneut hinaus" hatte schon einmal zwei Antworten an zwei Stellen; die
     * Vorlagen waren die dritte und vierte.
     */
    public function testNoTemplateDecidesTheRetryButtonOnItsOwn(): void
    {
        foreach (['show', 'index'] as $template) {
            $source = file_get_contents(
                dirname(__DIR__, 2) . '/templates/admin/mail_queue/' . $template . '.twig'
            );

            $this->assertIsString($source);
            $this->assertStringContainsString('entry.canRetry', $source);
            $this->assertStringNotContainsString("entry.status == 'dead'", $source);
        }
    }

    private function createEntry(string $mailType): MailQueue
    {
        return MailQueue::create([
            'mail_type' => $mailType,
            'recipient_email' => 'empfaenger@example.test', // naming:ascii
            'subject' => 'Testmail',
            'body_html' => '<p>Link: https://example.test/x/geheim</p>', // naming:ascii
            'payload_json' => ['user_id' => 1],
            'status' => MailQueue::STATUS_QUEUED,
            'attempts' => 0,
            'max_attempts' => 1,
            'is_retryable' => false,
            'next_attempt_at' => Carbon::now(),
        ]);
    }

    /**
     * Lässt den Versand dauerhaft scheitern, bis der Eintrag auf `dead` steht.
     * Die 5xx-Antwort ist entscheidend: Ein vorübergehender Fehler käme als
     * `failed` mit einem weiteren Versuch zurück, nicht als endgültig.
     */
    private function failUntilDead(MailQueue $entry): void
    {
        $service = new MailDeliveryService(new class extends Mailer {
            public function __construct()
            {
            }

            public function sendHtmlMailDetailed(
                string $to,
                string $subject,
                string $htmlBody,
                array $attachments = []
            ): array {
                return ['success' => false, 'skipped' => false];
            }

            public function getLastError(): ?string
            {
                return '550 5.1.1 Adresse unbekannt';
            }
        });

        $service->sendEntry($entry);
    }
}

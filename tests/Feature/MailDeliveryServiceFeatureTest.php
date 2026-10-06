<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Services\MailDeliveryService;
use App\Services\Mailer;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Der Queue-Worker arbeitet immer nur einen Ausschnitt der Warteschlange ab.
 * Ohne feste Reihenfolge entscheidet die Datenbank, welche Mails das sind.
 */
final class MailDeliveryServiceFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $_ENV['DISABLE_MAIL_SEND'] = $_SERVER['DISABLE_MAIL_SEND'] = 'true';

        MailQueue::query()->delete();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function enqueue(string $subject, Carbon $createdAt): MailQueue
    {
        $entry = MailQueue::create([
            'mail_type' => 'invitation',
            'recipient_email' => 'worker_' . bin2hex(random_bytes(4)) . '@example.test',
            'subject' => $subject,
            'body_html' => '<p>Inhalt</p>',
            'payload_json' => [],
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => 3,
            'is_retryable' => false,
            'next_attempt_at' => Carbon::now()->subMinute(),
        ]);

        // created_at wird von Eloquent gesetzt; für die Reihenfolge braucht der
        // Test einen eindeutigen Abstand.
        MailQueue::query()->whereKey($entry->id)->update(['created_at' => $createdAt]);

        return $entry->refresh();
    }

    public function testTheWorkerProcessesTheOldestEntriesFirst(): void
    {
        $now = Carbon::now();

        // Bewusst in umgekehrter Reihenfolge eingefügt: Ohne ausdrückliche
        // Sortierung liefert die Datenbank die Zeilen in Speicherreihenfolge und
        // der jüngste Eintrag käme zuerst dran.
        $newest = $this->enqueue('Zuletzt', $now->copy()->subMinutes(10));
        $oldest = $this->enqueue('Zuerst', $now->copy()->subMinutes(30));
        $middle = $this->enqueue('Danach', $now->copy()->subMinutes(20));

        $service = new MailDeliveryService(new Mailer(new NullLogger()));
        $service->processDueEntries(2);

        $this->assertSame('skipped', MailQueue::findOrFail($oldest->id)->status);
        $this->assertSame('skipped', MailQueue::findOrFail($middle->id)->status);
        $this->assertSame(
            'queued',
            MailQueue::findOrFail($newest->id)->status,
            'Der jüngste Eintrag muss auf den nächsten Durchlauf warten.'
        );
    }

    /**
     * Ein Fehler ausserhalb der Exception-Hierarchie darf den Durchlauf nicht
     * abbrechen.
     *
     * Ein TypeError aus dem Mailer oder einer seiner Abhängigkeiten ist ein
     * Error, keine Exception. Er lief an beiden Auffangstellen vorbei: Der
     * betroffene Eintrag blieb auf "sending" stehen, bis der Wächter ihn nach
     * einer Viertelstunde einsammelte, und alle Mails dahinter blieben in
     * derselben Runde unbearbeitet liegen.
     */
    public function testAnErrorInOneEntryDoesNotStopTheRun(): void
    {
        $now = Carbon::now();
        $failing = $this->enqueue('Bricht mit Error ab', $now->copy()->subMinutes(30));
        $following = $this->enqueue('Muss trotzdem drankommen', $now->copy()->subMinutes(20));

        $mailer = $this->createStub(Mailer::class);
        $mailer->method('isUsingSmtp')->willReturn(true);
        $mailer->method('sendHtmlMailDetailed')->willReturnCallback(
            static function (string $to, string $subject) use ($failing): array {
                if ($subject === $failing->subject) {
                    throw new \Error('Fehler ausserhalb der Exception-Hierarchie.');
                }

                return [
                    'success' => true,
                    'skipped' => true,
                    'provider_name' => 'disabled',
                    'provider_message_id' => null,
                ];
            }
        );

        $stats = (new MailDeliveryService($mailer))->processDueEntries(10);

        $this->assertSame(
            'skipped',
            MailQueue::findOrFail($following->id)->status,
            'Die Mails hinter dem Fehler müssen in derselben Runde drankommen.'
        );

        $stored = MailQueue::findOrFail($failing->id);
        $this->assertNotSame(
            'sending',
            $stored->status,
            'Ein gescheiterter Eintrag darf nicht auf den Wächter warten müssen.'
        );
        $this->assertSame(1, (int) $stored->attempts);
        $this->assertSame(1, $stats['failed']);
    }

    /**
     * Stellt einen Mailer, dessen Versand mit der übergebenen Fehlermeldung
     * scheitert - so wie PHPMailer die Antwort des Servers durchreicht.
     */
    private function failingMailer(string $errorMessage): Mailer
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendHtmlMailDetailed')->willReturn(['success' => false]);
        $mailer->method('getLastError')->willReturn($errorMessage);
        $mailer->method('isUsingSmtp')->willReturn(true);

        return $mailer;
    }

    /**
     * Ein dauerhafter 5xx-Fehler bedeutet: Diese Adresse gibt es nicht. Weitere
     * Versuche wären reines Zustellrauschen an einen toten Empfänger und
     * schaden der Reputation des Absenders.
     */
    public function testAPermanentSmtpFailureIsNotRetried(): void
    {
        $entry = $this->enqueue('Dauerhaft gescheitert', Carbon::now());
        $service = new MailDeliveryService(
            $this->failingMailer('SMTP Error: 550 5.1.1 <weg@example.test>: Recipient address rejected: User unknown')
        );

        $service->sendEntry($entry);

        $stored = MailQueue::findOrFail($entry->id);
        $this->assertSame('dead', $stored->status);
        $this->assertFalse((bool) $stored->is_retryable);
        $this->assertSame(1, (int) $stored->attempts, 'Der erste Versuch muss der letzte gewesen sein.');
    }

    /**
     * Auch ohne Klartext-Code muss der erweiterte Statuscode der Klasse 5
     * als dauerhaft erkannt werden.
     */
    public function testAnEnhancedFiveClassStatusCodeIsNotRetried(): void
    {
        $entry = $this->enqueue('Mailbox gibt es nicht', Carbon::now());
        $service = new MailDeliveryService(
            $this->failingMailer('Mailbox unavailable (5.1.1)')
        );

        $service->sendEntry($entry);

        $this->assertSame('dead', MailQueue::findOrFail($entry->id)->status);
    }

    /**
     * Ein 4xx-Fehler ist vorübergehend - da muss der Worker es erneut versuchen.
     */
    public function testATemporarySmtpFailureStaysRetryable(): void
    {
        $entry = $this->enqueue('Vorübergehend gescheitert', Carbon::now());
        $service = new MailDeliveryService(
            $this->failingMailer('SMTP Error: 451 4.3.0 Temporary lookup failure, try again later')
        );

        $service->sendEntry($entry);

        $stored = MailQueue::findOrFail($entry->id);
        $this->assertSame('failed', $stored->status);
        $this->assertTrue((bool) $stored->is_retryable);
        $this->assertNotNull($stored->next_attempt_at);
    }

    /**
     * Zahlen im Fließtext dürfen keinen dauerhaften Fehler vortäuschen, sonst
     * bleiben zustellbare Mails liegen.
     */
    public function testAPlainNumberInTheMessageDoesNotLookPermanent(): void
    {
        $entry = $this->enqueue('Netzwerkfehler', Carbon::now());
        $service = new MailDeliveryService(
            $this->failingMailer('Connection timed out after 500 ms')
        );

        $service->sendEntry($entry);

        $this->assertSame('failed', MailQueue::findOrFail($entry->id)->status);
    }

    /**
     * Zwei Durchläufe gleichzeitig: Genau einer bekommt die Mail.
     *
     * Beide holen sich denselben Ausschnitt der Warteschlange; den Zuschlag gibt dann ein
     * bedingtes Update. Wer verliert, bekam eine Ausnahme und wurde als `failed` gezählt.
     * Fehlgeschlagen ist dabei aber nichts - die Mail ist unterwegs, nur eben im anderen
     * Durchlauf. Ein gesunder Versand mit zwei Arbeitern sah damit in der Statistik nach
     * Problemen aus, und wer der Zahl folgte, suchte einen Fehler, den es nicht gab.
     *
     * Nachgestellt wird der Wettlauf über den zweiten Eintrag: Während der eigene
     * Durchlauf noch am ersten arbeitet, nimmt der andere Durchlauf den zweiten an sich.
     * Genau so liegt der Fall in Wirklichkeit - die Liste stand schon, als der Zuschlag
     * woanders fiel.
     */
    public function testAnEntryTakenByAnotherRunIsNotCountedAsFailed(): void
    {
        $now = Carbon::now();
        $mine = $this->enqueue('Gehört diesem Durchlauf', $now->copy()->subMinutes(30));
        $taken = $this->enqueue('Holt sich der andere Durchlauf', $now->copy()->subMinutes(20));

        $mailer = $this->createStub(Mailer::class);
        $mailer->method('isUsingSmtp')->willReturn(true);
        $mailer->method('sendHtmlMailDetailed')->willReturnCallback(
            static function (string $to, string $subject) use ($mine, $taken): array {
                if ($subject === $mine->subject) {
                    // Der andere Durchlauf greift zu, während dieser noch beschäftigt ist.
                    MailQueue::query()->whereKey($taken->id)->update(['status' => 'sending']);
                }

                return [
                    'success' => true,
                    'skipped' => true,
                    'provider_name' => 'disabled',
                    'provider_message_id' => null,
                ];
            }
        );

        $stats = (new MailDeliveryService($mailer))->processDueEntries(10);

        $this->assertSame(0, $stats['failed'], 'Ein fremder Anspruch ist kein Fehlschlag.');
        $this->assertSame(1, $stats['skipped'], 'Der eigene Eintrag zählt wie immer.');
        $this->assertSame(0, $stats['sent']);
        $this->assertSame(0, $stats['dead']);

        $stored = MailQueue::findOrFail($taken->id);
        $this->assertSame(
            'sending',
            $stored->status,
            'Der fremde Anspruch bleibt unangetastet - er gehört dem anderen Durchlauf.'
        );
        $this->assertSame(0, (int) $stored->attempts, 'Ein fremder Anspruch kostet keinen Versuch.');
    }

    /**
     * Verschwindet die Zeile im selben Augenblick, ist auch das kein Fehlschlag: Es gibt
     * nichts mehr zu verschicken.
     */
    public function testAnEntryThatVanishedIsNotCountedAsFailed(): void
    {
        $now = Carbon::now();
        $mine = $this->enqueue('Gehört diesem Durchlauf', $now->copy()->subMinutes(30));
        $gone = $this->enqueue('Ist beim Zugriff schon weg', $now->copy()->subMinutes(20));

        $mailer = $this->createStub(Mailer::class);
        $mailer->method('isUsingSmtp')->willReturn(true);
        $mailer->method('sendHtmlMailDetailed')->willReturnCallback(
            static function (string $to, string $subject) use ($mine, $gone): array {
                if ($subject === $mine->subject) {
                    MailQueue::query()->whereKey($gone->id)->delete();
                }

                return [
                    'success' => true,
                    'skipped' => true,
                    'provider_name' => 'disabled',
                    'provider_message_id' => null,
                ];
            }
        );

        $stats = (new MailDeliveryService($mailer))->processDueEntries(10);

        $this->assertSame(0, $stats['failed']);
        $this->assertSame(1, $stats['skipped']);
    }

    /**
     * Ein echter Fehlschlag zählt weiter: Die Abgrenzung oben darf nicht alles
     * verschlucken, was eine Ausnahme wirft.
     */
    public function testARealSendFailureIsStillCounted(): void
    {
        $this->enqueue('Scheitert wirklich', Carbon::now()->subMinutes(10));

        $mailer = $this->createStub(Mailer::class);
        $mailer->method('isUsingSmtp')->willReturn(true);
        $mailer->method('sendHtmlMailDetailed')->willReturnCallback(
            static fn (): array => throw new \Error('Fehler aus dem Mailer.')
        );

        $stats = (new MailDeliveryService($mailer))->processDueEntries(10);

        $this->assertSame(1, $stats['failed'], 'Ein tatsächlicher Fehlschlag gehört gezählt.');
    }
}

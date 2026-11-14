<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MailQueueController;
use App\Models\MailQueue;
use App\Services\MailQueueAdminService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Übersicht und Einzelansicht der Mail-Warteschlange, gerendert mit der echten
 * Vorlage und einem echten Eintrag.
 *
 * Die Vorlagen fragten `entry.canRetry` ohne Klammern. Twig prüft dabei zuerst,
 * ob das Modell eine Eigenschaft dieses Namens hat; Eloquent beantwortet das über
 * `getAttribute()`, findet die gleichnamige Methode und behandelt sie als
 * Beziehung - "must return a relationship instance". Die Seite antwortete mit
 * 500, sobald ein einziger Eintrag in der Liste stand. Die bisherigen Tests lasen
 * die Vorlage nur als Text und merkten das nicht.
 */
final class MailQueuePagesRenderFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = ['user_id' => 1, 'can_manage_mail_queue' => true];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testIndexRendersWithARetryableDeadEntry(): void
    {
        $this->createEntry(MailQueue::STATUS_DEAD);

        $response = $this->controller('/admin/mail-queue')
            ->index($this->makeRequest('GET', '/admin/mail-queue'), $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Erneut einreihen', (string) $response->getBody());
    }

    public function testIndexRendersWithoutRetryButtonForASentEntry(): void
    {
        $this->createEntry(MailQueue::STATUS_SENT);

        $response = $this->controller('/admin/mail-queue')
            ->index($this->makeRequest('GET', '/admin/mail-queue'), $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Erneut einreihen', (string) $response->getBody());
    }

    public function testShowRendersWithARetryableDeadEntry(): void
    {
        $entry = $this->createEntry(MailQueue::STATUS_DEAD);
        $path = '/admin/mail-queue/' . $entry->id;

        $response = $this->controller($path)
            ->show($this->makeRequest('GET', $path), $this->makeResponse(), ['id' => (string) $entry->id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('/admin/mail-queue/' . $entry->id . '/retry', (string) $response->getBody());
    }

    /**
     * Eine Zurücksetzungs- oder Einladungsmail trägt einen lebenden Einmal-Link.
     * Solange sie noch wartet, ist dieser Link gültig - und wer die Warteschlange
     * verwalten darf, konnte ihn hier ablesen: Zurücksetzen für ein fremdes Konto
     * anfordern, Eintrag öffnen, Link nehmen. Der Text wird deshalb für diese
     * Mailarten nie ausgeliefert, gleich in welchem Zustand der Eintrag ist.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function secretMailsInEveryState(): iterable
    {
        foreach (MailQueue::SECRET_BODY_MAIL_TYPES as $type) {
            foreach ([MailQueue::STATUS_QUEUED, MailQueue::STATUS_SENDING, MailQueue::STATUS_FAILED] as $status) {
                yield $type . '/' . $status => [$type, $status];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secretMailsInEveryState')]
    public function testShowNeverRendersTheBodyOfASecretMail(string $type, string $status): void
    {
        $entry = $this->createEntry($status, $type, '<p>https://chor.example/reset-password?token=geheim123</p>');
        $path = '/admin/mail-queue/' . $entry->id;

        $response = $this->controller($path)
            ->show($this->makeRequest('GET', $path), $this->makeResponse(), ['id' => (string) $entry->id]);

        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('geheim123', $body);
        $this->assertStringContainsString('Einmal-Link', $body);
    }

    public function testShowStillRendersTheBodyOfAnOrdinaryMail(): void
    {
        $entry = $this->createEntry(MailQueue::STATUS_QUEUED, MailQueue::MAIL_TYPE_NEWSLETTER, '<p>Probenplan</p>');
        $path = '/admin/mail-queue/' . $entry->id;

        $response = $this->controller($path)
            ->show($this->makeRequest('GET', $path), $this->makeResponse(), ['id' => (string) $entry->id]);

        $this->assertStringContainsString('Probenplan', (string) $response->getBody());
    }

    private function controller(string $currentPath): MailQueueController
    {
        return new MailQueueController($this->createAppTwig($currentPath), new MailQueueAdminService());
    }

    private function createEntry(
        string $status,
        string $type = MailQueue::MAIL_TYPE_NEWSLETTER,
        string $body = '<p>Hallo</p>'
    ): MailQueue {
        return MailQueue::create([
            'mail_type' => $type,
            'recipient_email' => 'empfaenger@example.test', // naming:ascii
            'subject' => 'Testmail',
            'body_html' => $body,
            'payload_json' => ['user_id' => 1],
            'status' => $status,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);
    }
}

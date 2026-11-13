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

    private function controller(string $currentPath): MailQueueController
    {
        return new MailQueueController($this->createAppTwig($currentPath), new MailQueueAdminService());
    }

    private function createEntry(string $status): MailQueue
    {
        return MailQueue::create([
            'mail_type' => MailQueue::MAIL_TYPE_NEWSLETTER,
            'recipient_email' => 'empfaenger@example.test', // naming:ascii
            'subject' => 'Testmail',
            'body_html' => '<p>Hallo</p>',
            'payload_json' => ['user_id' => 1],
            'status' => $status,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);
    }
}

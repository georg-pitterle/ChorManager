<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MailDeliveryDsnController;
use App\Controllers\MailDeliveryWebhookController;
use App\Models\MailDeliveryEvent;
use App\Models\MailQueue;
use App\Services\MailDeliveryService;
use App\Services\MailEventMapperService;
use App\Services\Mailer;
use App\Services\ProviderWebhookVerifier;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

final class MailDeliveryLifecycleFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testDeliveredEventUpdatesQueueToDelivered(): void
    {
        $queue = $this->createQueue();
        $occurredAt = '2026-04-20 12:00:00';

        (new MailEventMapperService())->mapEvent($this->makeEventPayload(
            idempotencyKey: 'task3-delivered-1',
            mailQueueId: (int) $queue->id,
            eventTypeNormalized: 'delivered',
            occurredAt: $occurredAt
        ));

        $queue->refresh();

        $this->assertSame('delivered', $queue->delivery_status);
        $this->assertSame('delivered', $queue->last_event_type);
        $this->assertSame($occurredAt, $queue->last_event_at?->format('Y-m-d H:i:s'));
        $this->assertSame($occurredAt, $queue->delivered_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, MailDeliveryEvent::query()->count());
    }

    public function testDuplicateEventKeepsSingleRowAndRepairsQueueState(): void
    {
        $queue = $this->createQueue();
        $occurredAt = '2026-04-20 13:00:00';
        $service = new MailEventMapperService();

        $service->mapEvent($this->makeEventPayload(
            idempotencyKey: 'task3-duplicate-1',
            mailQueueId: (int) $queue->id,
            eventTypeNormalized: 'delivered',
            occurredAt: $occurredAt
        ));

        $queue->update([
            'delivery_status' => 'pending',
            'delivered_at' => null,
            'last_event_at' => null,
            'last_event_type' => null,
        ]);

        $service->mapEvent($this->makeEventPayload(
            idempotencyKey: 'task3-duplicate-1',
            mailQueueId: (int) $queue->id,
            eventTypeNormalized: 'bounced',
            occurredAt: '2026-04-20 13:30:00'
        ));

        $queue->refresh();

        $this->assertSame(1, MailDeliveryEvent::query()->where('idempotency_key', 'task3-duplicate-1')->count());
        $this->assertSame('delivered', $queue->delivery_status);
        $this->assertSame('delivered', $queue->last_event_type);
        $this->assertSame($occurredAt, $queue->last_event_at?->format('Y-m-d H:i:s'));
        $this->assertSame($occurredAt, $queue->delivered_at?->format('Y-m-d H:i:s'));
    }

    public function testMissingOrEmptyIdempotencyKeyIsRejectedDeterministically(): void
    {
        $queue = $this->createQueue();
        $service = new MailEventMapperService();

        $basePayload = $this->makeEventPayload(
            idempotencyKey: 'placeholder',
            mailQueueId: (int) $queue->id,
            eventTypeNormalized: 'delivered',
            occurredAt: '2026-04-20 14:00:00'
        );

        try {
            $service->mapEvent(array_diff_key($basePayload, ['idempotency_key' => true]));
            $this->fail('Expected InvalidArgumentException for missing idempotency_key.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Missing idempotency_key.', $exception->getMessage());
        }

        try {
            $service->mapEvent(array_merge($basePayload, ['idempotency_key' => '   ']));
            $this->fail('Expected InvalidArgumentException for empty idempotency_key.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Missing idempotency_key.', $exception->getMessage());
        }

        $this->assertSame(0, MailDeliveryEvent::query()->count());
    }

    public function testSkippedSendSetsDeliveryStatusSkippedWithoutAcceptedAt(): void
    {
        $queue = $this->createQueue('queued', 'invitation');
        $service = new MailDeliveryService($this->createStubMailer([
            'success' => true,
            'skipped' => true,
            'provider_name' => 'disabled',
            'provider_message_id' => null,
        ]));

        $service->sendEntry($queue);
        $queue->refresh();

        $this->assertSame('skipped', $queue->status);
        $this->assertSame('skipped', $queue->delivery_status);
        $this->assertSame('disabled', $queue->provider_name);
        $this->assertNull($queue->provider_message_id);
        $this->assertNull($queue->accepted_at);
        $this->assertSame(1, $queue->attempts);
    }

    public function testSuccessfulSendSetsAcceptedAndProviderMetadata(): void
    {
        $queue = $this->createQueue('queued', 'invitation');
        $service = new MailDeliveryService($this->createStubMailer([
            'success' => true,
            'skipped' => false,
            'provider_name' => 'smtp',
            'provider_message_id' => 'message-id-123',
        ]));

        $service->sendEntry($queue);
        $queue->refresh();

        $this->assertSame('sent', $queue->status);
        $this->assertSame('accepted', $queue->delivery_status);
        $this->assertSame('smtp', $queue->provider_name);
        $this->assertSame('message-id-123', $queue->provider_message_id);
        $this->assertNotNull($queue->accepted_at);
        $this->assertNotNull($queue->sent_at);
        $this->assertSame(1, $queue->attempts);
    }

    public function testWatchdogRepairsOnlyStaleSendingEntries(): void
    {
        $now = Carbon::now();

        MailQueue::query()->insert([
            'mail_type' => 'invitation',
            'recipient_email' => 'stale.sending@example.test',
            'subject' => 'Stale entry',
            'body_html' => '<p>stale</p>',
            'payload_json' => json_encode(['case' => 'stale']),
            'status' => 'sending',
            'delivery_status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
            'is_retryable' => false,
            'created_at' => $now->copy()->subHour(),
            'updated_at' => $now->copy()->subMinutes(20),
        ]);

        MailQueue::query()->insert([
            'mail_type' => 'invitation',
            'recipient_email' => 'fresh.sending@example.test',
            'subject' => 'Fresh entry',
            'body_html' => '<p>fresh</p>',
            'payload_json' => json_encode(['case' => 'fresh']),
            'status' => 'sending',
            'delivery_status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
            'is_retryable' => false,
            'created_at' => $now->copy()->subHour(),
            'updated_at' => $now->copy()->subMinutes(5),
        ]);

        $service = new MailDeliveryService($this->createStubMailer([
            'success' => true,
            'skipped' => true,
            'provider_name' => 'disabled',
            'provider_message_id' => null,
        ]));

        $repaired = $service->repairStaleSendingEntries();

        $stale = MailQueue::query()->where('recipient_email', 'stale.sending@example.test')->first();
        $fresh = MailQueue::query()->where('recipient_email', 'fresh.sending@example.test')->first();

        $this->assertSame(1, $repaired);
        $this->assertInstanceOf(MailQueue::class, $stale);
        $this->assertInstanceOf(MailQueue::class, $fresh);
        $this->assertSame('failed', $stale->status);
        $this->assertSame(1, $stale->attempts);
        $this->assertTrue((bool) $stale->is_retryable);
        $this->assertSame('stale_sending_timeout', $stale->error_code);
        $this->assertNotNull($stale->last_attempt_at);
        $this->assertSame('sending', $fresh->status);
    }

    public function testWatchdogMarksStaleSendingEntriesDeadWhenMaxAttemptsReached(): void
    {
        $now = Carbon::now();

        MailQueue::query()->insert([
            'mail_type' => 'invitation',
            'recipient_email' => 'dead.sending@example.test',
            'subject' => 'Dead stale entry',
            'body_html' => '<p>dead</p>',
            'payload_json' => json_encode(['case' => 'dead']),
            'status' => 'sending',
            'delivery_status' => 'pending',
            'attempts' => 2,
            'max_attempts' => 3,
            'is_retryable' => true,
            'next_attempt_at' => $now,
            'created_at' => $now->copy()->subHour(),
            'updated_at' => $now->copy()->subMinutes(20),
        ]);

        $service = new MailDeliveryService($this->createStubMailer([
            'success' => true,
            'skipped' => true,
            'provider_name' => 'disabled',
            'provider_message_id' => null,
        ]));

        $repaired = $service->repairStaleSendingEntries();

        $entry = MailQueue::query()->where('recipient_email', 'dead.sending@example.test')->first();

        $this->assertSame(1, $repaired);
        $this->assertInstanceOf(MailQueue::class, $entry);
        $this->assertSame('dead', $entry->status);
        $this->assertSame(3, $entry->attempts);
        $this->assertFalse((bool) $entry->is_retryable);
        $this->assertNull($entry->next_attempt_at);
        $this->assertSame('stale_sending_timeout', $entry->error_code);
    }

    public function testRoutesExposeWebhookAndDsnEndpoints(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString('/mail/delivery/webhook', $routes);
        $this->assertStringContainsString('/mail/delivery/dsn', $routes);
    }

    public function testWebhookControllerUsesSignatureVerification(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/MailDeliveryWebhookController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('ProviderWebhookVerifier', $controller);
        $this->assertStringContainsString('verify', $controller);
    }

    public function testWebhookIngestRejectsInvalidSignature(): void
    {
        $queue = $this->createQueue();
        $idempotencyKey = 'task5-webhook-unauthorized';
        $payload = $this->makeEventPayload(
            idempotencyKey: $idempotencyKey,
            mailQueueId: (int) $queue->id,
            eventTypeNormalized: 'delivered',
            occurredAt: '2026-04-20 15:00:00'
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/mail/delivery/webhook')
            ->withQueryParams(['provider' => 'smtp2go'])
            ->withBody((new StreamFactory())->createStream((string) json_encode($payload, JSON_UNESCAPED_UNICODE)));

        $response = new Response();
        $controller = new MailDeliveryWebhookController(new ProviderWebhookVerifier(), new MailEventMapperService());
        $result = $controller->ingest($request, $response);

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame(
            0,
            MailDeliveryEvent::query()->where('idempotency_key', $idempotencyKey)->count()
        );
    }

    public function testWebhookIngestAcceptsValidSignatureAndMapsEvent(): void
    {
        $queue = $this->createQueue();
        $idempotencyKey = 'task5-webhook-authorized';
        $payload = $this->makeEventPayload(
            idempotencyKey: $idempotencyKey,
            mailQueueId: (int) $queue->id,
            eventTypeNormalized: 'delivered',
            occurredAt: '2026-04-20 16:00:00'
        );

        $secret = 'task5-test-secret';
        $rawBody = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $rawBody, $secret);

        $originalSecret = $_ENV['SMTP2GO_WEBHOOK_SECRET'] ?? null;
        $_ENV['SMTP2GO_WEBHOOK_SECRET'] = $secret;

        try {
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', '/mail/delivery/webhook')
                ->withQueryParams(['provider' => 'smtp2go'])
                ->withHeader('X-Smtp2go-Signature', $signature)
                ->withBody((new StreamFactory())->createStream($rawBody));

            $response = new Response();
            $controller = new MailDeliveryWebhookController(new ProviderWebhookVerifier(), new MailEventMapperService());
            $result = $controller->ingest($request, $response);

            $queue->refresh();

            $this->assertSame(200, $result->getStatusCode());
            $this->assertSame('delivered', $queue->delivery_status);
            $this->assertSame(1, MailDeliveryEvent::query()->where('idempotency_key', $idempotencyKey)->count());
        } finally {
            if ($originalSecret === null) {
                unset($_ENV['SMTP2GO_WEBHOOK_SECRET']);
            } else {
                $_ENV['SMTP2GO_WEBHOOK_SECRET'] = $originalSecret;
            }
        }
    }

    public function testDsnIngestMapsTrustedInternalEvent(): void
    {
        $queue = $this->createQueue();
        $idempotencyKey = 'task5-dsn-1';
        $payload = $this->makeEventPayload(
            idempotencyKey: $idempotencyKey,
            mailQueueId: (int) $queue->id,
            eventTypeNormalized: 'bounced',
            occurredAt: '2026-04-20 17:00:00'
        );

        $originalToken = $_ENV['MAIL_DSN_INGEST_TOKEN'] ?? null;
        $_ENV['MAIL_DSN_INGEST_TOKEN'] = 'task5-dsn-token';

        $request = $this->makeRequest('POST', '/mail/delivery/dsn', $payload, [], [
            'X-Mail-Dsn-Token' => 'task5-dsn-token',
        ]);
        $response = new Response();
        $controller = new MailDeliveryDsnController(new ProviderWebhookVerifier(), new MailEventMapperService());

        try {
            $result = $controller->ingest($request, $response);
        } finally {
            if ($originalToken === null) {
                unset($_ENV['MAIL_DSN_INGEST_TOKEN']);
            } else {
                $_ENV['MAIL_DSN_INGEST_TOKEN'] = $originalToken;
            }
        }

        $queue->refresh();

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('bounced', $queue->delivery_status);
        $this->assertSame(1, MailDeliveryEvent::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function testDsnIngestRejectsRequestWithoutTrustedToken(): void
    {
        $queue = $this->createQueue();
        $idempotencyKey = 'task5-dsn-unauthorized';
        $payload = $this->makeEventPayload(
            idempotencyKey: $idempotencyKey,
            mailQueueId: (int) $queue->id,
            eventTypeNormalized: 'delivered',
            occurredAt: '2026-04-20 17:30:00'
        );

        $request = $this->makeRequest('POST', '/mail/delivery/dsn', $payload);
        $response = new Response();
        $controller = new MailDeliveryDsnController(new ProviderWebhookVerifier(), new MailEventMapperService());
        $result = $controller->ingest($request, $response);

        $queue->refresh();

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('pending', $queue->delivery_status);
        $this->assertSame(0, MailDeliveryEvent::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function testProcessDueEntriesCountsFailedAndDeadWithoutOvercountingSent(): void
    {
        $failedEntry = $this->createQueue('queued', 'invitation');
        $deadEntry = $this->createQueue('queued', 'invitation');

        $deadEntry->update([
            'attempts' => 2,
            'max_attempts' => 3,
        ]);

        $service = new MailDeliveryService($this->createStubMailer([
            'success' => false,
            'skipped' => false,
            'provider_name' => 'smtp',
            'provider_message_id' => null,
        ]));

        $stats = $service->processDueEntries(10);

        $failedEntry->refresh();
        $deadEntry->refresh();

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['dead']);
        $this->assertSame('failed', $failedEntry->status);
        $this->assertSame('dead', $deadEntry->status);
    }

    public function testProcessDueEntriesCountsSkippedSeparatelyFromSent(): void
    {
        $skippedEntry = $this->createQueue('queued', 'invitation');
        $service = new MailDeliveryService($this->createStubMailer([
            'success' => true,
            'skipped' => true,
            'provider_name' => 'disabled',
            'provider_message_id' => null,
        ]));

        $stats = $service->processDueEntries(10);

        $skippedEntry->refresh();

        $this->assertSame('skipped', $skippedEntry->status);
        $this->assertSame('skipped', $skippedEntry->delivery_status);
        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(0, $stats['dead']);
    }

    public function testSendEntryRefreshesUpdatedAtWhenClaimingSendingState(): void
    {
        $queue = $this->createQueue('queued', 'invitation');
        $historicalUpdatedAt = Carbon::now()->subMinutes(45);

        $queue->update([
            'updated_at' => $historicalUpdatedAt,
        ]);

        $inspectingMailer = new class extends Mailer {
            private ?string $observedStatus = null;
            private ?Carbon $observedUpdatedAt = null;

            public function sendHtmlMailDetailed(string $to, string $subject, string $htmlBody): array
            {
                $current = MailQueue::query()->where('recipient_email', $to)->latest('id')->first();
                if ($current instanceof MailQueue) {
                    $this->observedStatus = (string) $current->status;
                    $this->observedUpdatedAt = $current->updated_at;
                }

                return [
                    'success' => true,
                    'skipped' => true,
                    'provider_name' => 'disabled',
                    'provider_message_id' => null,
                ];
            }

            public function observedStatus(): ?string
            {
                return $this->observedStatus;
            }

            public function observedUpdatedAt(): ?Carbon
            {
                return $this->observedUpdatedAt;
            }
        };

        $service = new MailDeliveryService($inspectingMailer);
        $service->sendEntry($queue);

        $this->assertSame('sending', $inspectingMailer->observedStatus());
        $this->assertNotNull($inspectingMailer->observedUpdatedAt());
        $this->assertTrue($inspectingMailer->observedUpdatedAt()->greaterThan($historicalUpdatedAt));
    }

    private function createQueue(string $status = 'sent', string $mailType = 'newsletter'): MailQueue
    {
        return MailQueue::query()->create([
            'mail_type' => $mailType,
            'recipient_email' => 'mail.lifecycle@example.test',
            'subject' => 'Lifecycle test mail',
            'body_html' => '<p>Lifecycle test</p>',
            'payload_json' => ['feature' => 'mail-delivery-lifecycle'],
            'status' => $status,
        ]);
    }

    /**
     * @param array{success: bool, skipped: bool, provider_name: string, provider_message_id: ?string} $result
     */
    private function createStubMailer(array $result): Mailer
    {
        return new class($result) extends Mailer {
            /** @var array{success: bool, skipped: bool, provider_name: string, provider_message_id: ?string} */
            private array $result;

            /**
             * @param array{success: bool, skipped: bool, provider_name: string, provider_message_id: ?string} $result
             */
            public function __construct(array $result)
            {
                $this->result = $result;
            }

            public function sendHtmlMailDetailed(string $to, string $subject, string $htmlBody): array
            {
                return $this->result;
            }

            public function sendHtmlMail(string $to, string $subject, string $htmlBody): bool
            {
                return (bool) $this->result['success'];
            }

            public function getLastError(): ?string
            {
                return null;
            }
        };
    }

    private function makeEventPayload(
        string $idempotencyKey,
        int $mailQueueId,
        string $eventTypeNormalized,
        string $occurredAt
    ): array {
        return [
            'mail_queue_id' => $mailQueueId,
            'provider_name' => 'symfony-mailer',
            'provider_message_id' => 'provider-message-' . $idempotencyKey,
            'source_channel' => 'webhook',
            'event_type_normalized' => $eventTypeNormalized,
            'event_type_raw' => strtoupper($eventTypeNormalized),
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'raw_payload' => json_encode(['event' => $eventTypeNormalized, 'key' => $idempotencyKey]),
        ];
    }
}

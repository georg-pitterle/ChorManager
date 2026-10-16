<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ScalewayWebhookController;
use App\Middleware\CsrfMiddleware;
use App\Models\MailDeliveryEvent;
use App\Models\MailQueue;
use App\Services\MailEventMapperService;
use App\Services\Scaleway\SnsMessageVerifier;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Tests\Support\SnsTestSigner;

/**
 * Bounce- und Zustell-Rückmeldungen von Scaleway Transactional Email.
 *
 * TEM schickt seine Ereignisse nicht selbst, sondern über ein Topic von
 * Scaleway Topics and Events (SNS-kompatibel). Was hier ankommt, ist eine
 * signierte SNS-Hülle; das TEM-Ereignis steckt als JSON-Text in `Message`.
 */
final class ScalewayWebhookFeatureTest extends TestCase
{
    private const MESSAGE_ID = '<seed-scaleway-1@chor.example>';

    private static ?Capsule $capsule = null;
    private static ?SnsTestSigner $signer = null;
    private string $workDir;
    private TestHandler $logs;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$signer ??= new SnsTestSigner();

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

        $this->workDir = sys_get_temp_dir() . '/scaleway-webhook-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
        file_put_contents($this->workDir . '/ca.pem', $this->signer()->caPem());
        $this->logs = new TestHandler();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach (glob($this->workDir . '/{,cache/}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->workDir . '/cache')) {
            rmdir($this->workDir . '/cache');
        }
        rmdir($this->workDir);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function finalEventTypes(): array
    {
        return [
            'zugestellt' => ['email_delivered', 'delivered'],
            'harter Bounce' => ['email_dropped', 'bounced'],
            'Postfach unbekannt' => ['email_mailbox_not_found', 'bounced'],
            'durch Sperrliste blockiert' => ['email_blocklisted', 'bounced'],
            'als Spam eingestuft' => ['email_spam', 'complained'],
        ];
    }

    #[DataProvider('finalEventTypes')]
    public function testEventUpdatesTheMatchingQueueEntry(string $scalewayType, string $expectedStatus): void
    {
        $queue = $this->createSentQueue();

        $result = $this->post($this->signer()->notification($this->temEvent($scalewayType)));

        $this->assertSame(200, $result->getStatusCode());
        $queue->refresh();
        $this->assertSame($expectedStatus, $queue->delivery_status);
        $this->assertSame($expectedStatus, $queue->last_event_type);
        $this->assertSame('2026-10-16 10:15:00', $queue->last_event_at?->format('Y-m-d H:i:s'));

        $event = MailDeliveryEvent::query()->where('mail_queue_id', $queue->id)->sole();
        $this->assertSame('scaleway', $event->provider_name);
        $this->assertSame('webhook', $event->source_channel);
        $this->assertSame($scalewayType, $event->event_type_raw);
        $this->assertSame($expectedStatus, $event->event_type_normalized);
        $this->assertSame('scaleway:tem-event-' . $scalewayType, $event->idempotency_key);
        $this->assertSame(self::MESSAGE_ID, $event->provider_message_id);
    }

    public function testBounceStoresTheTimeInTheAppTimezone(): void
    {
        $queue = $this->createSentQueue();

        $this->post($this->signer()->notification($this->temEvent('email_dropped')));

        $queue->refresh();
        // 08:15 UTC, die App läuft in Europe/Vienna (Sommerzeit, +02:00).
        $this->assertSame('2026-10-16 10:15:00', $queue->bounced_at?->format('Y-m-d H:i:s'));
    }

    /**
     * Die Doku nennt die Felder eines Headers `key`/`value`, die API-Referenz
     * `name`/`value`. Beides muss passen, bis ein echter Rumpf es entscheidet.
     */
    public function testHeaderNamedViaNameIsMatchedToo(): void
    {
        $queue = $this->createSentQueue();
        $event = $this->temEvent('email_dropped');
        $event['email_headers'] = [
            ['name' => 'Subject', 'value' => 'Probe'],
            ['name' => 'message-id', 'value' => self::MESSAGE_ID],
        ];

        $this->post($this->signer()->notification($event));

        $queue->refresh();
        $this->assertSame('bounced', $queue->delivery_status);
    }

    public function testMessageIdWithoutAngleBracketsMatchesToo(): void
    {
        $queue = $this->createSentQueue();
        $event = $this->temEvent('email_dropped');
        $event['email_headers'] = [['key' => 'Message-Id', 'value' => trim(self::MESSAGE_ID, '<>')]];

        $this->post($this->signer()->notification($event));

        $queue->refresh();
        $this->assertSame('bounced', $queue->delivery_status);
    }

    public function testRepeatedDeliveryStoresTheEventOnce(): void
    {
        $queue = $this->createSentQueue();
        $message = $this->signer()->notification($this->temEvent('email_dropped'));

        $this->assertSame(200, $this->post($message)->getStatusCode());
        $this->assertSame(200, $this->post($message)->getStatusCode());

        $this->assertSame(1, MailDeliveryEvent::query()->where('mail_queue_id', $queue->id)->count());
    }

    public function testDeferredEventIsRecordedWithoutChangingTheStatus(): void
    {
        $queue = $this->createSentQueue();

        $result = $this->post($this->signer()->notification($this->temEvent('email_deferred')));

        $this->assertSame(200, $result->getStatusCode());
        $queue->refresh();
        $this->assertSame('accepted', $queue->delivery_status);
        $this->assertSame(
            'deferred',
            MailDeliveryEvent::query()->where('mail_queue_id', $queue->id)->sole()->event_type_normalized
        );
    }

    /**
     * Mit einem Fehlercode würde Scaleway die Nachricht immer wieder schicken -
     * die Mail gibt es aber nicht, das ändert sich durch Wiederholen nicht.
     */
    public function testEventForAnUnknownMailIsAcknowledgedAndLogged(): void
    {
        $event = $this->temEvent('email_dropped');
        $event['email_headers'] = [['key' => 'Message-Id', 'value' => '<unknown@chor.example>']];

        $result = $this->post($this->signer()->notification($event));

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame(0, MailDeliveryEvent::query()->where('provider_name', 'scaleway')->count());
        $this->assertTrue($this->logged('mail.webhook.scaleway.unmatched'));
    }

    public function testBlocklistCreatedWithoutMailIsAcknowledgedAndLogged(): void
    {
        $result = $this->post($this->signer()->notification([
            'id' => 'tem-blocklist-1',
            'type' => 'blocklist_created',
            'created_at' => '2026-10-16T08:15:00Z',
            'email_to' => 'nobody@example.test',
            'blocklist_type' => 'mailbox_not_found',
        ]));

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame(0, MailDeliveryEvent::query()->where('provider_name', 'scaleway')->count());
        $this->assertTrue($this->logged('mail.webhook.scaleway.unmatched'));
    }

    public function testInvalidSignatureIsRejectedWithoutTouchingTheQueue(): void
    {
        $queue = $this->createSentQueue();
        $message = $this->signer()->notification($this->temEvent('email_delivered'));
        $message['Message'] = (string) json_encode($this->temEvent('email_dropped'));

        $result = $this->post($message);

        $this->assertSame(401, $result->getStatusCode());
        $queue->refresh();
        $this->assertSame('accepted', $queue->delivery_status);
        $this->assertSame(0, MailDeliveryEvent::query()->where('mail_queue_id', $queue->id)->count());
        $this->assertTrue($this->logged('mail.webhook.scaleway.rejected'));
    }

    /**
     * Scaleway verlangt, dass die SubscribeURL aus der ersten Nachricht in der
     * Konsole eingetragen wird. Sie landet im Log, damit sie dort abzuholen ist.
     */
    public function testSubscriptionConfirmationLogsTheSubscribeUrl(): void
    {
        $result = $this->post($this->signer()->subscriptionConfirmation());

        $this->assertSame(200, $result->getStatusCode());
        $records = array_filter(
            $this->logs->getRecords(),
            static fn ($record): bool => ($record->context['event'] ?? null)
                === 'mail.webhook.scaleway.subscription_confirmation'
        );
        $this->assertCount(1, $records);
        $this->assertSame(
            'https://sns.mnq.fr-par.scaleway.com/?Action=ConfirmSubscription&Token=confirm-token',
            array_values($records)[0]->context['subscribe_url'] ?? null
        );
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->assertSame(400, $this->postRaw('{not json')->getStatusCode());
    }

    public function testSignedNotificationWithInvalidEventJsonIsRejected(): void
    {
        $message = $this->signer()->sign([
            'Type' => 'Notification',
            'MessageId' => 'broken-1',
            'TopicArn' => SnsTestSigner::TOPIC_ARN,
            'Message' => 'kein json',
            'Timestamp' => '2026-10-16T08:00:00.000Z',
            'SignatureVersion' => '2',
            'SigningCertURL' => SnsTestSigner::CERT_URL,
        ]);

        $this->assertSame(400, $this->post($message)->getStatusCode());
    }

    public function testEndpointIsExemptFromCsrf(): void
    {
        $_SESSION = [];
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $result = (new CsrfMiddleware())->process(
            (new ServerRequestFactory())->createServerRequest('POST', '/mail/delivery/scaleway'),
            $handler
        );

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testRouteIsRegistered(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertStringContainsString(
            "\$app->post('/mail/delivery/scaleway', [ScalewayWebhookController::class, 'ingest']);",
            $routes
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function post(array $message): ResponseInterface
    {
        return $this->postRaw((string) json_encode($message, JSON_UNESCAPED_SLASHES));
    }

    private function postRaw(string $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/mail/delivery/scaleway')
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withBody((new StreamFactory())->createStream($body));

        $verifier = new SnsMessageVerifier(
            SnsTestSigner::TOPIC_ARN,
            $this->workDir . '/ca.pem',
            fn (string $url): ?string => $url === SnsTestSigner::CERT_URL ? $this->signer()->leafPem() : null,
            $this->workDir . '/cache'
        );
        $controller = new ScalewayWebhookController(
            $verifier,
            new MailEventMapperService(),
            new Logger('test', [$this->logs])
        );

        return $controller->ingest($request, new Response());
    }

    /**
     * @return array<string, mixed>
     */
    private function temEvent(string $type): array
    {
        return [
            'id' => 'tem-event-' . $type,
            'type' => $type,
            'organization_id' => 'org-1',
            'project_id' => 'project-test',
            'domain_id' => 'domain-1',
            'domain_name' => 'chor.example',
            'created_at' => '2026-10-16T08:15:00Z',
            'email_id' => 'tem-email-1',
            'email_from' => 'noreply@chor.example',
            'email_to' => 'mitglied@example.test',
            'email_headers' => [
                ['key' => 'Subject', 'value' => 'Probe am Donnerstag'],
                ['key' => 'Message-Id', 'value' => self::MESSAGE_ID],
            ],
            'email_response_code' => 550,
            'email_response_message' => '5.1.1 User unknown',
        ];
    }

    private function createSentQueue(): MailQueue
    {
        $queue = MailQueue::query()->create([
            'mail_type' => 'newsletter',
            'recipient_email' => 'mitglied@example.test',
            'subject' => 'Probe am Donnerstag',
            'body_html' => '<p>Probe</p>',
            'payload_json' => ['feature' => 'scaleway-webhook'],
            'status' => 'sent',
        ]);
        $queue->update([
            'delivery_status' => MailQueue::DELIVERY_STATUS_ACCEPTED,
            'provider_name' => 'smtp',
            'provider_message_id' => self::MESSAGE_ID,
        ]);

        return $queue;
    }

    private function logged(string $event): bool
    {
        foreach ($this->logs->getRecords() as $record) {
            if (($record->context['event'] ?? null) === $event) {
                return true;
            }
        }

        return false;
    }

    private function signer(): SnsTestSigner
    {
        return self::$signer ?? throw new \LogicException('Signer fehlt.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\MailQueueProcessingMiddleware;
use App\Models\AppSetting;
use App\Services\MailDeliveryService;
use App\Util\AfterResponse;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Der Versand im Anfrageweg läuft erst, wenn die Antwort draußen ist.
 *
 * Vorher lief er davor, mitten in der Anfrage und mit gesperrter Sitzung. Ein
 * Mailserver, der nicht antwortete, hielt so die Seite und die Sitzung fest;
 * jeder weitere Klick wartete auf die Sperre, belegte einen PHP-FPM-Prozess,
 * und nach fünf davon stand die Anwendung für alle.
 */
final class MailQueueProcessingMiddlewareFeatureTest extends TestCase
{
    private const MARKER_KEY = 'mailqueue_last_opportunistic_run_at';

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

        AppSetting::query()->whereIn('setting_key', [self::MARKER_KEY, 'mailqueue_trigger_mode'])->delete();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testResponseIsReturnedBeforeAnyMailIsSent(): void
    {
        $delivery = $this->recordingDeliveryService();
        $afterResponse = new AfterResponse(static function (): void {
        }, false);

        $response = $this->middleware($delivery, $afterResponse)->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->handlerRecording($delivery)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['handler'], $delivery->calls);

        $afterResponse->runPending();

        $this->assertSame(['handler', 'process:50'], $delivery->calls);
    }

    public function testConfiguredBatchSizeIsUsed(): void
    {
        $this->setting('mailqueue_batch_size', '7');
        $delivery = $this->recordingDeliveryService();
        $afterResponse = new AfterResponse(static function (): void {
        }, false);

        $this->middleware($delivery, $afterResponse)->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->handlerRecording($delivery)
        );
        $afterResponse->runPending();

        $this->assertSame(['handler', 'process:7'], $delivery->calls);
    }

    public function testCronModeSendsNothingFromTheRequest(): void
    {
        $this->setting('mailqueue_trigger_mode', 'cron');
        $delivery = $this->recordingDeliveryService();
        $afterResponse = new AfterResponse(static function (): void {
        }, false);

        $this->middleware($delivery, $afterResponse)->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->handlerRecording($delivery)
        );
        $afterResponse->runPending();

        $this->assertSame(['handler'], $delivery->calls);
    }

    private function middleware(
        MailDeliveryService $delivery,
        AfterResponse $afterResponse
    ): MailQueueProcessingMiddleware {
        return new MailQueueProcessingMiddleware($delivery, new NullLogger(), $afterResponse);
    }

    /**
     * @return MailDeliveryService&object{calls: list<string>}
     */
    private function recordingDeliveryService(): MailDeliveryService
    {
        return new class extends MailDeliveryService {
            /** @var list<string> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function processDueEntries(int $batchSize = 50): array
            {
                $this->calls[] = 'process:' . $batchSize;

                return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'dead' => 0];
            }
        };
    }

    private function handlerRecording(MailDeliveryService $delivery): RequestHandlerInterface
    {
        return new class ($delivery) implements RequestHandlerInterface {
            public function __construct(private readonly MailDeliveryService $delivery)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->delivery->calls[] = 'handler';

                return new Response(200);
            }
        };
    }

    private function setting(string $key, string $value): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value, 'binary_content' => '', 'mime_type' => 'text/plain']
        );
    }
}

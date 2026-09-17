<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MailQueueController;
use App\Services\MailQueueAdminService;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\ArrayLoader;

/**
 * Ein gescheiterter Wiederholungsversuch in der Mail-Warteschlange endete in
 * einer nackten Textseite ("Error: ..." mit Status 400) ohne Kopfzeile, ohne
 * Navigation und ohne Protokolleintrag - der Rest des Controllers leitet
 * zurück und meldet über die Flash-Nachricht. Der Grund stand nur auf dieser
 * Sackgasse, nirgends sonst.
 */
class MailQueueRetryFeedbackFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testControllerDeclaresStrictTypes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/MailQueueController.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('declare(strict_types=1);', $source);
    }

    public function testFailedRetryRedirectsWithAFlashMessageAndIsLogged(): void
    {
        [$logger, $handler] = $this->logger();

        $adminService = new class extends MailQueueAdminService {
            public function __construct()
            {
            }

            public function retrySingle(int $entryId): bool
            {
                throw new \RuntimeException('SQLSTATE[HY000]: interner Zustand');
            }
        };

        $controller = new MailQueueController(new Twig(new ArrayLoader([])), $adminService, $logger);

        $result = $controller->retrySingle(
            $this->makeRequest('POST', '/admin/mail-queue/7/retry'),
            $this->makeResponse(),
            ['id' => '7']
        );

        $this->assertRedirect($result, '/admin/mail-queue');

        $message = (string) ($_SESSION['error'] ?? '');
        $this->assertNotSame('', $message);
        // Der rohe Treibertext gehört ins Protokoll, nicht auf den Bildschirm.
        $this->assertStringNotContainsString('SQLSTATE', $message);

        $record = $this->recordFor($handler, 'mail_queue.retry.failed');
        $this->assertNotNull($record);
        $this->assertSame(7, $record->context['mail_queue_id'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterController;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterMailRenderer;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Platzhalter-Liste für den Editor kommt aus der Registry, nicht aus dem JavaScript.
 */
final class NewsletterPlaceholderEndpointFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function controller(): NewsletterController
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');

        return new NewsletterController(
            $twig,
            new NewsletterService(
                new NewsletterRecipientService(),
                new Mailer(new NullLogger()),
                new HtmlSanitizer(),
                new MailQueueService(),
                new NullLogger(),
                new NewsletterPlaceholderService(new NameFormatterService()),
                new NewsletterMailRenderer($twig)
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            new MailQueueService(),
            new NewsletterMailRenderer($twig)
        );
    }

    public function testPlaceholderListIsReturnedAsJson(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->controller()->placeholders(
            $this->makeRequest('GET', '/newsletters/placeholders'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $tokens = array_column($payload['placeholders'], 'token');

        $this->assertContains('{{vorname}}', $tokens);
        $this->assertContains('{{stimmgruppe}}', $tokens);
        $this->assertCount(13, $payload['placeholders']);
        $this->assertArrayHasKey('label', $payload['placeholders'][0]);
        $this->assertArrayHasKey('description', $payload['placeholders'][0]);
    }

    public function testPlaceholderListRequiresManagementRight(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_newsletters'] = false;

        $response = $this->controller()->placeholders(
            $this->makeRequest('GET', '/newsletters/placeholders'),
            $this->makeResponse()
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PasswordResetController;
use App\Models\User;
use App\Services\MailQueueService;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Das Anfordern eines Reset-Links war nur je Adresse gebremst. Wer von einer
 * Quelle aus der Reihe nach Adressen durchprobierte, lief nie auf - und jede
 * Anfrage zu einem bestehenden Konto verschickt eine Mail. Jetzt gilt
 * zusätzlich eine Grenze je Quell-IP.
 *
 * Die Antwort bleibt dabei dieselbe unverfängliche Meldung wie sonst: Eine
 * eigene Meldung für "gebremst" verriete nichts Neues, aber eine abweichende
 * Meldung je nach Konto täte es.
 */
final class PasswordResetRequestRateLimitFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private const GENERIC_MESSAGE = 'Existiert die E-Mail-Adresse, wurde ein Link zum Zurücksetzen des Passworts gesendet.';

    private string $storeDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
        $this->storeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_reset_request_' . bin2hex(random_bytes(6));
        @mkdir($this->storeDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ((array) glob($this->storeDir . DIRECTORY_SEPARATOR . '*') as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);

        $_SESSION = [];
        parent::tearDown();
    }

    public function testRequestsFromOneAddressAreCappedAcrossDifferentEmails(): void
    {
        $user = User::create([
            'first_name' => 'Reset',
            'last_name' => 'Ziel',
            'email' => 'reset.ziel.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);

        $mailQueue = $this->createMock(MailQueueService::class);
        $mailQueue->expects($this->never())->method('enqueuePasswordResetMail');
        $controller = $this->controller($mailQueue);

        for ($i = 0; $i < PasswordResetController::RESET_REQUESTS_PER_IP; $i++) {
            $this->request($controller, 'unbekannt.' . $i . '.' . bin2hex(random_bytes(3)) . '@example.test');
        }

        // Die nächste Anfrage von derselben Quelle trifft ein echtes Konto - und
        // verschickt trotzdem nichts.
        $this->request($controller, (string) $user->email);

        $this->assertSame(self::GENERIC_MESSAGE, $_SESSION['success'] ?? null);
    }

    public function testOtherAddressesAreNotAffected(): void
    {
        $mailQueue = $this->createStub(MailQueueService::class);
        $controller = $this->controller($mailQueue);

        for ($i = 0; $i <= PasswordResetController::RESET_REQUESTS_PER_IP; $i++) {
            $this->request($controller, 'flut.' . $i . '@example.test', '203.0.113.50');
        }

        $user = User::create([
            'first_name' => 'Reset',
            'last_name' => 'Andere',
            'email' => 'reset.andere.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
        $mailQueue = $this->createMock(MailQueueService::class);
        $mailQueue->expects($this->once())->method('enqueuePasswordResetMail');

        $this->request($this->controller($mailQueue), (string) $user->email, '198.51.100.77');
    }

    private function controller(MailQueueService $mailQueue): PasswordResetController
    {
        $twig = $this->createStub(Twig::class);
        $twig->method('fetch')->willReturn('<p>Link</p>');

        return $this->passwordResetController([
            'view' => $twig,
            'mailer' => null,
            'rateLimiter' => new RateLimiterService($this->storeDir),
            'passwordPolicyService' => new PasswordPolicyService(),
            'mailQueueService' => $mailQueue,
        ]);
    }

    private function request(
        PasswordResetController $controller,
        string $email,
        string $remoteAddress = '198.51.100.31'
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/forgot-password', ['REMOTE_ADDR' => $remoteAddress])
            ->withParsedBody(['email' => $email]);

        return $controller->sendResetLink($request, $this->makeResponse());
    }
}

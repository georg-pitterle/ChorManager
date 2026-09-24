<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PasswordResetController;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use App\Util\PasswordHasher;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Das Anfordern eines Reset-Links war begrenzt, das Einlösen nicht.
 *
 * Der Token ist mit 256 Bit nicht zu erraten - es geht um die Last: Jeder Aufruf
 * von /reset-password prüft ihn mit password_verify(), und ein bcrypt-Durchlauf
 * kostet im Container rund 160 ms. Ohne Grenze ließ sich der Webprozess mit
 * beliebig vielen Aufrufen beschäftigen, ohne einen einzigen gültigen Link zu
 * besitzen.
 */
final class PasswordResetConsumptionRateLimitFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const MAX_ATTEMPTS = 10;

    private string $storeDir = '';
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
        $this->storeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_reset_limit_' . bin2hex(random_bytes(6));
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

    private function controller(): PasswordResetController
    {
        [$logger, $handler] = $this->logger();
        $this->logHandler = $handler;

        return new PasswordResetController(
            $this->createStub(Twig::class),
            null,
            new RateLimiterService($this->storeDir),
            new PasswordPolicyService(),
            null,
            $logger
        );
    }

    /**
     * Jeder Versuch kommt von derselben Adresse - genau die Grenze, um die es
     * geht. Der Token ist absichtlich falsch: Geprüft wird die Begrenzung, nicht
     * das Einlösen.
     */
    private function consumeAttempt(PasswordResetController $controller, string $email): ResponseInterface
    {
        return $controller->processReset(
            $this->makeRequest(
                'POST',
                '/reset-password',
                [
                    'token' => bin2hex(random_bytes(32)),
                    'email' => $email,
                    'password' => 'Ein-gutes-Kennwort-1',
                    'password_confirm' => 'Ein-gutes-Kennwort-1',
                ],
                [],
                ['X-Forwarded-For' => '203.0.113.7']
            ),
            $this->makeResponse()
        );
    }

    public function testTheEleventhAttemptIsBlocked(): void
    {
        $controller = $this->controller();
        $email = 'limit-' . bin2hex(random_bytes(4)) . '@example.test';

        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            $this->consumeAttempt($controller, $email);
            $this->assertSame(
                'Dieser Link ist ungültig oder abgelaufen.',
                $_SESSION['error'] ?? null,
                "Versuch $i muss noch durchgelassen werden."
            );
        }

        $this->consumeAttempt($controller, $email);

        $this->assertSame('Zu viele Versuche. Bitte versuche es in wenigen Minuten erneut.', $_SESSION['error'] ?? null);
        $this->assertTrue(
            $this->hasEvent($this->logHandler, 'auth.password_reset.rate_limited'),
            'Das überschrittene Limit gehört ins Protokoll.'
        );
    }

    /**
     * Ein gültiger Link darf nicht an der Grenze scheitern, die für die
     * Fehlversuche gedacht ist - solange sie nicht erreicht ist.
     */
    public function testAValidLinkStillWorksBelowTheLimit(): void
    {
        $email = 'gueltig-' . bin2hex(random_bytes(4)) . '@example.test';
        $token = bin2hex(random_bytes(32));

        User::create([
            'first_name' => 'Rita',
            'last_name' => 'Testperson',
            'email' => $email,
            'password' => PasswordHasher::hash('altes-Kennwort-1'),
            'is_active' => 1,
        ]);
        PasswordReset::create([
            'email' => $email,
            'token' => PasswordHasher::hash($token),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $response = $this->controller()->processReset(
            $this->makeRequest('POST', '/reset-password', [
                'token' => $token,
                'email' => $email,
                'password' => 'Ein-gutes-Kennwort-1',
                'password_confirm' => 'Ein-gutes-Kennwort-1',
            ]),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/login');
        $this->assertSame(
            'Dein Passwort wurde erfolgreich gesetzt. Du kannst dich nun anmelden.',
            $_SESSION['success'] ?? null
        );
    }

    /** Ohne Begrenzer im Container bleibt der Weg offen - wie bei sendResetLink(). */
    public function testWithoutALimiterTheAttemptIsNotBlocked(): void
    {
        $controller = new PasswordResetController(
            $this->createStub(Twig::class),
            null,
            null,
            new PasswordPolicyService()
        );

        for ($i = 1; $i <= self::MAX_ATTEMPTS + 2; $i++) {
            $controller->processReset(
                $this->makeRequest('POST', '/reset-password', [
                    'token' => bin2hex(random_bytes(32)),
                    'email' => 'ohne-limit@example.test',
                    'password' => 'Ein-gutes-Kennwort-1',
                    'password_confirm' => 'Ein-gutes-Kennwort-1',
                ]),
                $this->makeResponse()
            );
        }

        $this->assertSame('Dieser Link ist ungültig oder abgelaufen.', $_SESSION['error'] ?? null);
    }
}

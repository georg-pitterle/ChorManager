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
use Slim\Psr7\Factory\ServerRequestFactory;
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
     * Ein Einlöseversuch. Der Token ist absichtlich falsch: Geprüft wird die
     * Begrenzung, nicht das Einlösen.
     *
     * Die Quell-IP steht als `REMOTE_ADDR` in den Server-Parametern und nicht als
     * `X-Forwarded-For`-Kopf: Den wertet ClientIpResolver nur hinter einem
     * vertrauten Proxy aus, und ohne `TRUSTED_PROXIES` fiele jeder Versuch auf
     * dieselbe Kennung "unknown" zurück. Genau das hatte die erste Fassung dieses
     * Tests getroffen - die IP-Grenze schlug zu, und die Konto-Grenze daneben war
     * nie geprüft.
     */
    private function consumeAttempt(
        PasswordResetController $controller,
        string $email,
        string $remoteAddress = '198.51.100.9'
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/reset-password', ['REMOTE_ADDR' => $remoteAddress])
            ->withParsedBody([
                'token' => bin2hex(random_bytes(32)),
                'email' => $email,
                'password' => 'Ein-gutes-Kennwort-1',
                'password_confirm' => 'Ein-gutes-Kennwort-1',
            ]);

        return $controller->processReset($request, $this->makeResponse());
    }

    /** Eine Adresse, die in dieser Prüfung sonst nirgends gezählt wird. */
    private function freshEmail(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(5)) . '@example.test';
    }

    /**
     * Die Grenze je Quell-IP: immer dieselbe IP, jedes Mal ein anderes Konto. Ein
     * verteilter Versuch, der Konten durchprobiert, läuft damit auf.
     */
    public function testTheEleventhAttemptFromOneAddressIsBlocked(): void
    {
        $controller = $this->controller();

        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            $this->consumeAttempt($controller, $this->freshEmail('ip'), '198.51.100.21');
            $this->assertSame(
                'Dieser Link ist ungültig oder abgelaufen.',
                $_SESSION['error'] ?? null,
                "Versuch $i muss noch durchgelassen werden."
            );
        }

        $this->consumeAttempt($controller, $this->freshEmail('ip'), '198.51.100.21');

        $this->assertSame('Zu viele Versuche. Bitte versuche es in wenigen Minuten erneut.', $_SESSION['error'] ?? null);
        $this->assertTrue(
            $this->hasEvent($this->logHandler, 'auth.password_reset.rate_limited'),
            'Das überschrittene Limit gehört ins Protokoll.'
        );
    }

    /**
     * Die Grenze je Zielkonto: jedes Mal eine andere Quell-IP, immer dasselbe
     * Konto. Ohne sie liefe ein Versuch, der die IP wechselt, unbegrenzt gegen ein
     * einzelnes Konto - deshalb zählt der Controller beides.
     */
    public function testTheEleventhAttemptAgainstOneAccountIsBlocked(): void
    {
        $controller = $this->controller();
        $email = $this->freshEmail('konto');

        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            $this->consumeAttempt($controller, $email, '203.0.113.' . $i);
            $this->assertSame(
                'Dieser Link ist ungültig oder abgelaufen.',
                $_SESSION['error'] ?? null,
                "Versuch $i muss noch durchgelassen werden."
            );
        }

        $this->consumeAttempt($controller, $email, '203.0.113.200');

        $this->assertSame('Zu viele Versuche. Bitte versuche es in wenigen Minuten erneut.', $_SESSION['error'] ?? null);
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

    /**
     * Zwei Schreibweisen derselben Adresse ergeben einen Zählstand, nicht zwei -
     * sonst wäre die Grenze je Konto durch Wechseln der Schreibweise zu umgehen.
     *
     * Dafür sorgt `RateLimiterService::normalizeKey()`, das jeden Schlüssel selbst
     * kleinschreibt, nicht der Controller: Der Fall bleibt grün, auch wenn
     * processReset() die Adresse ungenormt durchreicht. Das ist Absicht - die
     * Grenze soll halten, egal was der Aufrufer liefert.
     */
    public function testTheLimitCountsBothSpellingsOfTheSameAddress(): void
    {
        $controller = $this->controller();
        $local = 'grenze-' . bin2hex(random_bytes(5));

        // Wechselnde Quell-IPs, damit allein die Konto-Grenze zählt - sonst
        // schlüge die IP-Grenze zu und der Fall bewiese nichts über die
        // Schreibweise.
        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            $email = $i % 2 === 0 ? $local . '@example.test' : strtoupper($local) . '@Example.TEST';
            $this->consumeAttempt($controller, $email, '192.0.2.' . $i);
        }

        $this->consumeAttempt($controller, $local . '@example.test', '192.0.2.200');

        $this->assertSame(
            'Zu viele Versuche. Bitte versuche es in wenigen Minuten erneut.',
            $_SESSION['error'] ?? null
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\SessionExpiredSignal;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Die beiden Reihen, die eine nicht angemeldete Anfrage abweisen, antworten gleich.
 *
 * Erste Reihe ist die AuthMiddleware an der geschützten Gruppe, zweite Reihe die
 * RoleMiddleware an der einzelnen Route. Deren Abweisung ist im Regelbetrieb nicht
 * erreichbar - genau deshalb muss sie dasselbe tun wie die erste: Eine zweite Reihe,
 * die anders antwortet als die erste, fällt erst auf, wenn sie einmal greift.
 *
 * Gleich waren bisher nur die JSON-Antworten. Für den Browser schickte die erste
 * Reihe das gewünschte Ziel mit, die zweite ließ es weg - wer dort landete, stand
 * nach der Anmeldung auf dem Dashboard statt auf der Seite, die er aufgerufen hatte.
 */
final class UnauthenticatedRejectionConsistencyFeatureTest extends TestCase
{
    private AuthMiddleware $firstRow;
    private RoleMiddleware $secondRow;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Bootstrap::setupTestDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->firstRow = new AuthMiddleware(
            new UserQuery(new NameFormatterService()),
            new RememberLoginService(),
            new SessionAuthService(new NameFormatterService(), new RequestContext())
        );
        $this->secondRow = new RoleMiddleware(requiresUserManagement: true);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        unset($_COOKIE[RememberLoginService::COOKIE_NAME]);
    }

    protected function tearDown(): void
    {
        RoleMiddleware::setDefaultLogger(null);

        parent::tearDown();
    }

    public function testBothRowsSendTheBrowserBackToTheRequestedPage(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/users?voice_group=2');

        foreach ($this->rows() as $label => $row) {
            $response = $row->process($request, $this->handlerThatMustNotRun());

            $this->assertSame(302, $response->getStatusCode(), $label);
            $this->assertSame(
                '/login?redirect=' . rawurlencode('/users?voice_group=2'),
                $response->getHeaderLine('Location'),
                $label
            );
        }
    }

    public function testBothRowsAnswerJsonCallersWithTheSessionExpiredSignal(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/users/42/roles')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');

        foreach ($this->rows() as $label => $row) {
            $response = $row->process($request, $this->handlerThatMustNotRun());

            $this->assertSame(401, $response->getStatusCode(), $label);
            $this->assertSame('1', $response->getHeaderLine(SessionExpiredSignal::HEADER), $label);
            $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'), $label);
        }
    }

    /**
     * Zu einem POST-Ziel wie `/users/42/roles` gehört keine Seite, die man nach der
     * Anmeldung aufrufen könnte. Deshalb bleibt es dort bei der nackten Anmeldeseite.
     */
    public function testBothRowsDropTheTargetForRequestsThatAreNotGet(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/users/42/roles');

        foreach ($this->rows() as $label => $row) {
            $response = $row->process($request, $this->handlerThatMustNotRun());

            $this->assertSame(302, $response->getStatusCode(), $label);
            $this->assertSame('/login', $response->getHeaderLine('Location'), $label);
        }
    }

    /**
     * Weder die Anmeldeseite selbst noch das Dashboard taugen als Ziel: Das eine
     * schickte die Person nach erfolgreicher Anmeldung zurück auf das Formular, das
     * andere ist ohnehin das Ziel.
     */
    public function testBothRowsNeverPointTheTargetAtTheirOwnDestinations(): void
    {
        foreach (['/login', '/dashboard'] as $path) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

            foreach ($this->rows() as $label => $row) {
                $response = $row->process($request, $this->handlerThatMustNotRun());

                $this->assertSame(302, $response->getStatusCode(), $label . ' ' . $path);
                $this->assertSame('/login', $response->getHeaderLine('Location'), $label . ' ' . $path);
            }
        }
    }

    /**
     * @return array<string, MiddlewareInterface>
     */
    private function rows(): array
    {
        return [
            'AuthMiddleware' => $this->firstRow,
            'RoleMiddleware' => $this->secondRow,
        ];
    }

    private function handlerThatMustNotRun(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Die abgewiesene Anfrage darf den Handler nicht erreichen.');
            }
        };
    }
}

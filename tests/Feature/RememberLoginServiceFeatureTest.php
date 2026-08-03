<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RememberLogin;
use App\Models\User;
use App\Services\RememberLoginService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Unit\Bootstrap;

class RememberLoginServiceFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['REMEMBER_ME_DAYS'] = '30';
        $_SERVER['REMEMBER_ME_DAYS'] = '30';
        $_ENV['APP_ENV'] = 'test';
        $_SERVER['APP_ENV'] = 'test';
        $_SERVER['HTTPS'] = 'off';
    }

    public function testSplitCookieValueAcceptsValidFormat(): void
    {
        $service = new RememberLoginService();
        $method = new ReflectionMethod($service, 'splitCookieValue');

        $selector = str_repeat('a', 18);
        $validator = str_repeat('b', 64);
        $result = $method->invoke($service, $selector . ':' . $validator);

        $this->assertSame([$selector, $validator], $result);
    }

    public function testSplitCookieValueRejectsInvalidFormat(): void
    {
        $service = new RememberLoginService();
        $method = new ReflectionMethod($service, 'splitCookieValue');

        $this->assertSame([null, null], $method->invoke($service, 'invalid'));
        $this->assertSame([null, null], $method->invoke($service, 'short:token'));
    }

    public function testShouldUseSecureCookieInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        $service = new RememberLoginService();
        $method = new ReflectionMethod($service, 'shouldUseSecureCookie');

        $this->assertTrue($method->invoke($service));
    }

    public function testValidateCookieValueLogsUsedEventOnSuccessfulRedemption(): void
    {
        Bootstrap::setupTestDatabase();

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $user = User::create([
            'first_name' => 'Remember',
            'last_name' => 'Tester',
            'email' => 'remember.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $service = new RememberLoginService($logger);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/login');
        $cookieValue = $service->issueForUser((int) $user->id, $request);

        $token = $service->validateCookieValue($cookieValue);

        $this->assertNotNull($token);

        $records = $handler->getRecords();
        $match = array_values(array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'auth.remember_me.used'
        ));

        $this->assertNotEmpty($match);
        $this->assertSame((int) $user->id, $match[0]->context['user_id']);

        foreach ($records as $record) {
            $this->assertStringNotContainsString($cookieValue, (string) json_encode($record->context));
        }

        RememberLogin::where('user_id', $user->id)->delete();
        $user->delete();
    }

    public function testValidateCookieValueLogsRejectedEventForMalformedCookie(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $service = new RememberLoginService($logger);
        $result = $service->validateCookieValue('not-a-valid-cookie-value');

        $this->assertNull($result);

        $records = $handler->getRecords();
        $match = array_values(array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'auth.remember_me.rejected'
        ));

        $this->assertNotEmpty($match);
        $this->assertSame('malformed', $match[0]->context['reason']);

        foreach ($records as $record) {
            $this->assertStringNotContainsString('not-a-valid-cookie-value', (string) json_encode($record->context));
        }
    }
}

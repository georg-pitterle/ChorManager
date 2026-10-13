<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RememberLogin;
use App\Models\User;
use App\Services\RememberLoginService;
use App\Util\PasswordHasher;
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
            'password' => PasswordHasher::hash('test123'),
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

    /**
     * Die Obergrenze zählt ab dem Beginn der Kette, nicht ab dem letzten
     * Tokentausch. Eine Kette, die älter ist als REMEMBER_ME_MAX_DAYS, wird
     * abgewiesen - auch wenn das Token selbst noch lange gilt. Genau das war
     * vorher nicht so: Jede Drehung setzte die Frist neu, und ein regelmäßig
     * benutztes Gerät blieb unbegrenzt angemeldet.
     */
    public function testAChainOlderThanTheMaximumIsRejected(): void
    {
        Bootstrap::setupTestDatabase();

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $user = $this->createUser();
        $service = new RememberLoginService($logger);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/login');
        $cookieValue = $service->issueForUser((int) $user->id, $request);

        // Kette auf 181 Tage zurückdatieren, das Token selbst bleibt gültig.
        [$selector] = explode(':', $cookieValue, 2);
        RememberLogin::where('selector', $selector)->update([
            'chain_started_at' => date('Y-m-d H:i:s', time() - (181 * 86400)),
            'expires_at' => date('Y-m-d H:i:s', time() + (30 * 86400)),
        ]);

        $this->assertNull(
            $service->validateCookieValue($cookieValue),
            'Eine Kette über der Höchstdauer darf nicht mehr anmelden.'
        );
        $this->assertSame(
            0,
            RememberLogin::where('selector', $selector)->count(),
            'Das abgelaufene Token gehört gelöscht, nicht nur abgewiesen.'
        );

        $reasons = array_map(
            static fn ($record) => $record->context['reason'] ?? null,
            $handler->getRecords()
        );
        $this->assertContains('chain_expired', $reasons);

        RememberLogin::where('user_id', $user->id)->delete();
        $user->delete();
    }

    /**
     * Gegenprobe: Innerhalb der Obergrenze dreht das Token weiter, und die
     * Kette behält ihren Beginn. Ohne die Übernahme wäre die Obergrenze
     * wirkungslos - jede Drehung setzte sie zurück.
     */
    public function testRotationKeepsTheChainStart(): void
    {
        Bootstrap::setupTestDatabase();

        $user = $this->createUser();
        $service = new RememberLoginService();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/login');
        $cookieValue = $service->issueForUser((int) $user->id, $request);

        $chainStart = date('Y-m-d H:i:s', time() - (100 * 86400));
        [$selector] = explode(':', $cookieValue, 2);
        RememberLogin::where('selector', $selector)->update(['chain_started_at' => $chainStart]);

        $token = $service->validateCookieValue($cookieValue);
        $this->assertNotNull($token, 'Innerhalb der Höchstdauer muss die Anmeldung durchgehen.');

        $rotated = $service->rotateToken($token, $request);
        [$newSelector] = explode(':', $rotated, 2);
        $this->assertNotSame($selector, $newSelector, 'Die Drehung muss ein neues Token ergeben.');

        $fresh = RememberLogin::where('selector', $newSelector)->first();
        $this->assertNotNull($fresh);
        $this->assertSame(
            $chainStart,
            $fresh->chain_started_at instanceof \DateTimeInterface
                ? $fresh->chain_started_at->format('Y-m-d H:i:s')
                : (string) $fresh->chain_started_at,
            'Die Drehung darf den Beginn der Kette nicht verschieben.'
        );

        RememberLogin::where('user_id', $user->id)->delete();
        $user->delete();
    }

    /**
     * `expires_at` wird am Ende der Kette gekappt. Dadurch räumt der schon
     * vorhandene Kehraus (`clearExpiredTokens()`) eine ausgelaufene Kette von
     * selbst weg und braucht keine zweite Bedingung.
     */
    public function testExpiryIsCappedAtTheEndOfTheChain(): void
    {
        Bootstrap::setupTestDatabase();

        $user = $this->createUser();
        $service = new RememberLoginService();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/login');
        $cookieValue = $service->issueForUser((int) $user->id, $request);

        // Kette so weit zurückdatieren, dass nur noch zwei Tage übrig sind.
        $chainStart = date('Y-m-d H:i:s', time() - (178 * 86400));
        [$selector] = explode(':', $cookieValue, 2);
        RememberLogin::where('selector', $selector)->update(['chain_started_at' => $chainStart]);

        $token = $service->validateCookieValue($cookieValue);
        $this->assertNotNull($token);
        $rotated = $service->rotateToken($token, $request);

        [$newSelector] = explode(':', $rotated, 2);
        $fresh = RememberLogin::where('selector', $newSelector)->first();
        $this->assertNotNull($fresh);

        $expiresAt = $fresh->expires_at instanceof \DateTimeInterface
            ? $fresh->expires_at->getTimestamp()
            : strtotime((string) $fresh->expires_at);
        $chainDeadline = strtotime($chainStart) + (180 * 86400);

        $this->assertLessThanOrEqual(
            $chainDeadline,
            $expiresAt,
            'Das gedrehte Token darf nicht über das Ende der Kette hinaus gelten.'
        );

        RememberLogin::where('user_id', $user->id)->delete();
        $user->delete();
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Remember',
            'last_name' => 'Tester',
            'email' => 'remember.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);
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

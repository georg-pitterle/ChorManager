<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\RememberLoginService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
        $method->setAccessible(true);

        $selector = str_repeat('a', 18);
        $validator = str_repeat('b', 64);
        $result = $method->invoke($service, $selector . ':' . $validator);

        $this->assertSame([$selector, $validator], $result);
    }

    public function testSplitCookieValueRejectsInvalidFormat(): void
    {
        $service = new RememberLoginService();
        $method = new ReflectionMethod($service, 'splitCookieValue');
        $method->setAccessible(true);

        $this->assertSame([null, null], $method->invoke($service, 'invalid'));
        $this->assertSame([null, null], $method->invoke($service, 'short:token'));
    }

    public function testShouldUseSecureCookieInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        $service = new RememberLoginService();
        $method = new ReflectionMethod($service, 'shouldUseSecureCookie');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service));
    }
}

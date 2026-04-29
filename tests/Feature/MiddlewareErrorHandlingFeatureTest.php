<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class MiddlewareErrorHandlingFeatureTest extends TestCase
{
    public function testMiddlewareRegistersDedicatedNotFoundHandlerWithTwigTemplate(): void
    {
        $middlewareConfig = file_get_contents(dirname(__DIR__) . '/../src/Middleware.php');

        $this->assertIsString($middlewareConfig);
        $this->assertStringContainsString('HttpNotFoundException::class', $middlewareConfig);
        $this->assertStringContainsString('Twig::class', $middlewareConfig);
        $this->assertStringContainsString("'errors/404.twig'", $middlewareConfig);
        $this->assertStringContainsString("['requested_path' => \$request->getUri()->getPath()]", $middlewareConfig);
        $this->assertStringContainsString('setErrorHandler(', $middlewareConfig);
        $this->assertStringContainsString(
            'return $defaultErrorHandler($request, $exception, $displayErrorDetails, false, false);',
            $middlewareConfig
        );
    }

    public function testNotFoundTemplateProvidesFriendlyNavigation(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/errors/404.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('{% extends "layout.twig" %}', $template);
        $this->assertStringContainsString('Seite nicht gefunden', $template);
        $this->assertStringContainsString('href="/dashboard"', $template);
        $this->assertStringContainsString('href="/"', $template);
        $this->assertStringContainsString('{{ requested_path|default("/") }}', $template);
    }
}

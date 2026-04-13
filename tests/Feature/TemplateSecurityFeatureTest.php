<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class TemplateSecurityFeatureTest extends TestCase
{
    public function testNoInlineScriptOrStyleOutsideEmailTemplates(): void
    {
        $templateRoot = dirname(__DIR__, 2) . '/templates';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateRoot));
        $twigFiles = new RegexIterator($iterator, '/^.+\.twig$/i');

        foreach ($twigFiles as $fileInfo) {
            $path = (string) $fileInfo->getPathname();
            $relativePath = str_replace('\\', '/', substr($path, strlen($templateRoot) + 1));

            if (str_starts_with($relativePath, 'emails/')) {
                continue;
            }

            $content = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression(
                '/<script(?![^>]*src=)/i',
                $content,
                'Inline script found in template: ' . $relativePath
            );

            $this->assertDoesNotMatchRegularExpression(
                '/<style(?![^>]*src=)/i',
                $content,
                'Inline style found in template: ' . $relativePath
            );
        }
    }
}

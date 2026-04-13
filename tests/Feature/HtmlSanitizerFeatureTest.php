<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerFeatureTest extends TestCase
{
    public function testSanitizeTaskHtmlRemovesScriptAndJavascriptLinks(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<p>Safe</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>';

        $output = $sanitizer->sanitizeTaskHtml($input);

        $this->assertStringNotContainsString('<script', strtolower($output));
        $this->assertStringNotContainsString('javascript:', strtolower($output));
        $this->assertStringContainsString('<p>Safe</p>', $output);
    }

    public function testSanitizeNewsletterHtmlKeepsBasicLayoutButRemovesEventHandlers(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<table><tr><td onclick="alert(1)">Cell</td></tr></table>';

        $output = $sanitizer->sanitizeNewsletterHtml($input);

        $this->assertStringContainsString('<table>', $output);
        $this->assertStringNotContainsString('onclick', strtolower($output));
    }

    public function testSanitizeNewsletterHtmlRemovesExternalAndDataBackedImages(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<p>Body</p><img src="https://tracker.example/pixel.png" alt="x"><img src="data:image/png;base64,abcd">';

        $output = $sanitizer->sanitizeNewsletterHtml($input);

        $this->assertStringContainsString('<p>Body</p>', $output);
        $this->assertStringNotContainsString('tracker.example', $output);
        $this->assertStringNotContainsString('data:image', strtolower($output));
        $this->assertStringNotContainsString('<img', strtolower($output));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class ProfileMailboxAsyncTemplateTest extends TestCase
{
    public function testProfileTemplateHasAsyncMailboxFormWiring(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/profile/index.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString('id="mailboxForm"', $template);
        $this->assertStringContainsString('id="mailboxFormFeedback"', $template);
        $this->assertStringContainsString('<script src="/js/profile-mailbox.js"></script>', $template);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserMailAccount;
use PHPUnit\Framework\TestCase;

final class WebmailFeatureFlagTest extends TestCase
{
    public function testUserMailAccountHasExternalWebmailUrlFillable(): void
    {
        $this->assertContains('external_webmail_url', (new UserMailAccount())->getFillable());
    }

    public function testMigrationForExternalWebmailUrlExists(): void
    {
        $migration = dirname(__DIR__, 2)
            . '/db/migrations/20260704200000_add_external_webmail_url_to_user_mail_accounts.php';
        $this->assertFileExists($migration);

        $content = file_get_contents($migration);
        $this->assertIsString($content);
        $this->assertStringContainsString("'external_webmail_url'", $content);
        $this->assertStringContainsString("'null' => true", $content);
    }
}

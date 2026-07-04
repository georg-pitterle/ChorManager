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

    public function testSettingsExposeWebmailFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'webmail'\\s*=>\\s*EnvHelper::read\\('FEATURE_WEBMAIL', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testWebmailRouteIsRegisteredOnlyInsideFeatureGate(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        $gatePos = strpos($content, "if (\$settings['modules']['webmail'] ?? false) {");
        $routePos = strpos($content, "'/profile/webmail/start'");

        $this->assertNotFalse($gatePos, 'Webmail feature gate missing in Routes.php.');
        $this->assertNotFalse($routePos, 'Webmail route missing in Routes.php.');
        $this->assertGreaterThan($gatePos, $routePos, 'Webmail route must be inside the feature gate.');
    }
}

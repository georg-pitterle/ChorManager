<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class RegistrationFeatureFlagTest extends TestCase
{
    public function testSettingsExposeRegistrationFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'registration'\\s*=>\\s*EnvHelper::read\\('FEATURE_REGISTRATION', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testEnvExamplesAndProdComposeDocumentFeatureRegistration(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_REGISTRATION=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_REGISTRATION=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_REGISTRATION: ${FEATURE_REGISTRATION:-false}', $compose);
    }
}

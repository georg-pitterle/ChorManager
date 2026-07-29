<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Guards login sessions against container recreation.
 *
 * PHP stores session files in the container's writable layer by default, so
 * every image update or recreate discarded them and logged every user out. The
 * session directory therefore has to live in a named volume, and - like the
 * backup volume - a freshly created volume belongs to root, so the entrypoint
 * must hand it to the PHP-FPM worker user before php-fpm starts.
 */
final class SessionPersistenceFeatureTest extends TestCase
{
    private const SESSION_PATH = '/var/lib/php-sessions';

    private function productionCompose(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/dist/docker-compose.prod.yml');
        $this->assertIsString($content);

        return $content;
    }

    private function entrypoint(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/entrypoint.sh');
        $this->assertIsString($content);

        return $content;
    }

    public function testProductionStackMountsANamedVolumeForSessions(): void
    {
        $compose = $this->productionCompose();

        $this->assertStringContainsString(
            '- session_data:${SESSION_SAVE_PATH:-' . self::SESSION_PATH . '}',
            $compose
        );
        $this->assertMatchesRegularExpression('/^volumes:\n(?:.*\n)*?  session_data:$/m', $compose);
    }

    public function testProductionStackPointsTheAppAtTheMountedSessionDirectory(): void
    {
        $this->assertStringContainsString(
            'SESSION_SAVE_PATH: ${SESSION_SAVE_PATH:-' . self::SESSION_PATH . '}',
            $this->productionCompose()
        );
    }

    public function testEntrypointPreparesTheSessionDirectoryForTheWorkerUser(): void
    {
        $entrypoint = $this->entrypoint();

        $this->assertStringContainsString('SESSION_SAVE_PATH="${SESSION_SAVE_PATH:-}"', $entrypoint);
        $this->assertStringContainsString('mkdir -p "${SESSION_SAVE_PATH}"', $entrypoint);
        $this->assertStringContainsString('chown -R www-data:www-data "${SESSION_SAVE_PATH}"', $entrypoint);
        $this->assertStringContainsString('chmod 700 "${SESSION_SAVE_PATH}"', $entrypoint);
    }

    public function testEntrypointPreparesSessionsBeforePhpFpmStarts(): void
    {
        $entrypoint = $this->entrypoint();

        $chownPosition = strpos($entrypoint, 'chown -R www-data:www-data "${SESSION_SAVE_PATH}"');
        $fpmPosition = strpos($entrypoint, 'php-fpm -F');

        $this->assertIsInt($chownPosition);
        $this->assertIsInt($fpmPosition);
        $this->assertLessThan($fpmPosition, $chownPosition);
    }

    public function testFrontControllerAppliesTheConfiguredSavePathBeforeStartingSessions(): void
    {
        $frontController = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $this->assertIsString($frontController);

        $applyPosition = strpos($frontController, 'SessionConfig::applySavePath()');
        $cookieParamsPosition = strpos($frontController, 'session_set_cookie_params(');

        $this->assertIsInt($applyPosition);
        $this->assertIsInt($cookieParamsPosition);
        $this->assertLessThan($cookieParamsPosition, $applyPosition);
    }

    public function testDeploymentEnvExampleDocumentsTheSessionDirectory(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/dist/.env.example');

        $this->assertIsString($content);
        $this->assertStringContainsString('SESSION_SAVE_PATH=' . self::SESSION_PATH, $content);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\SessionConfig;
use PHPUnit\Framework\TestCase;

/**
 * Session files live in the container's writable layer by default, so every
 * image update logs every user out. SESSION_SAVE_PATH points PHP at a mounted
 * volume instead; an unusable path must fall back to the PHP default rather
 * than break sessions completely.
 */
final class SessionConfigTest extends TestCase
{
    private const ENV_KEY = 'SESSION_SAVE_PATH';

    private string $originalSavePath = '';
    private ?string $originalEnvValue = null;
    private bool $hadEnvValue = false;
    private string $tempRoot = '';

    protected function setUp(): void
    {
        $this->originalSavePath = (string) ini_get('session.save_path');
        $this->hadEnvValue = array_key_exists(self::ENV_KEY, $_ENV);
        $this->originalEnvValue = $this->hadEnvValue ? (string) $_ENV[self::ENV_KEY] : null;
        $this->tempRoot = sys_get_temp_dir() . '/chormanager-session-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        ini_set('session.save_path', $this->originalSavePath);

        if ($this->hadEnvValue) {
            $_ENV[self::ENV_KEY] = $this->originalEnvValue;
            $_SERVER[self::ENV_KEY] = $this->originalEnvValue;
        } else {
            unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
        }

        if (is_dir($this->tempRoot)) {
            rmdir($this->tempRoot);
        }
    }

    public function testUnsetEnvironmentKeepsThePhpDefault(): void
    {
        unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);

        $this->assertFalse(SessionConfig::applySavePath());
        $this->assertSame($this->originalSavePath, (string) ini_get('session.save_path'));
    }

    public function testConfiguredPathIsCreatedAndApplied(): void
    {
        $_ENV[self::ENV_KEY] = $_SERVER[self::ENV_KEY] = $this->tempRoot;

        $this->assertTrue(SessionConfig::applySavePath());
        $this->assertDirectoryExists($this->tempRoot);
        $this->assertSame($this->tempRoot, (string) ini_get('session.save_path'));
    }

    public function testUnusablePathFallsBackToThePhpDefault(): void
    {
        $filePath = $this->tempRoot . '-file';
        file_put_contents($filePath, 'not a directory');

        try {
            $_ENV[self::ENV_KEY] = $_SERVER[self::ENV_KEY] = $filePath;

            $this->assertFalse(SessionConfig::applySavePath());
            $this->assertSame($this->originalSavePath, (string) ini_get('session.save_path'));
        } finally {
            unlink($filePath);
        }
    }
}

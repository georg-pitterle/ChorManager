<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\AppSettingController;
use PHPUnit\Framework\TestCase;

final class AppSettingLogSettingsTest extends TestCase
{
    public function testNormalizesKnownLevel(): void
    {
        $this->assertSame('DEBUG', AppSettingController::normalizeLogLevel('debug'));
    }

    public function testFallsBackToInfoOnUnknownLevel(): void
    {
        $this->assertSame('INFO', AppSettingController::normalizeLogLevel('trace'));
    }

    public function testFallsBackToInfoOnNull(): void
    {
        $this->assertSame('INFO', AppSettingController::normalizeLogLevel(null));
    }

    public function testNormalizesCheckboxToFlag(): void
    {
        $this->assertSame('1', AppSettingController::normalizeBooleanFlag('on'));
        $this->assertSame('0', AppSettingController::normalizeBooleanFlag(null));
    }
}

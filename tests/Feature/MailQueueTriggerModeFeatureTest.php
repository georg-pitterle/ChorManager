<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Util\MailQueueTriggerMode;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Betriebsart des Mailversands lasen die drei Middlewares vorher jede für
 * sich. Nur die Warteschlange behandelte einen leeren Eintrag wie einen
 * fehlenden; die beiden Erinnerungen lasen ihn als unbekannte Betriebsart und
 * stellten ihre Arbeit dauerhaft ein. Jetzt entscheidet eine Stelle.
 */
final class MailQueueTriggerModeFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        Capsule::connection()->rollBack();
        parent::tearDown();
    }

    public function testMissingSettingMeansHybrid(): void
    {
        AppSetting::query()->where('setting_key', 'mailqueue_trigger_mode')->delete();

        $this->assertSame(MailQueueTriggerMode::HYBRID, MailQueueTriggerMode::current());
        $this->assertTrue(MailQueueTriggerMode::allowsOpportunisticWork());
    }

    public function testEmptySettingMeansHybridInsteadOfSilence(): void
    {
        $this->storeTriggerMode('');

        $this->assertSame(MailQueueTriggerMode::HYBRID, MailQueueTriggerMode::current());
        $this->assertTrue(MailQueueTriggerMode::allowsOpportunisticWork());
    }

    public function testCronModeKeepsWorkOutOfTheRequestPath(): void
    {
        $this->storeTriggerMode(MailQueueTriggerMode::CRON);

        $this->assertSame(MailQueueTriggerMode::CRON, MailQueueTriggerMode::current());
        $this->assertFalse(MailQueueTriggerMode::allowsOpportunisticWork());
    }

    public function testOpportunisticModeAllowsWorkInTheRequestPath(): void
    {
        $this->storeTriggerMode(MailQueueTriggerMode::OPPORTUNISTIC);

        $this->assertTrue(MailQueueTriggerMode::allowsOpportunisticWork());
    }

    private function storeTriggerMode(string $value): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => 'mailqueue_trigger_mode'],
            [
                'setting_value' => $value,
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ]
        );
    }
}

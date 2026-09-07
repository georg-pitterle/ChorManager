<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Util\OpportunisticRunGate;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die drei Middlewares mit Arbeit im Anfrageweg - Mailwarteschlange,
 * Anmelde-Erinnerung, Benachrichtigungs-Erinnerung - schrieben dieselbe
 * Wartezeit-Logik je einmal aus. Sie steht jetzt einmal hier, und dieser Test
 * beschreibt sie an einer Stelle statt dreimal verstreut.
 */
final class OpportunisticRunGateFeatureTest extends TestCase
{
    private const MARKER_KEY = 'test_opportunistic_run_gate_at';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Bootstrap::setupTestDatabase();
    }

    protected function setUp(): void
    {
        $this->forgetMarker();
        $this->setTriggerMode('hybrid');
    }

    protected function tearDown(): void
    {
        $this->forgetMarker();
        AppSetting::query()->where('setting_key', 'mailqueue_trigger_mode')->delete();
    }

    public function testCronOnlyModeRefusesTheClaimAndLeavesNoMarker(): void
    {
        $this->setTriggerMode('cron');

        $this->assertFalse(OpportunisticRunGate::tryClaim(self::MARKER_KEY, 3600));
        $this->assertNull($this->marker(), 'Ein abgelehnter Anlauf darf keinen Merker hinterlassen.');
    }

    public function testFirstClaimSucceedsAndWritesTheMarker(): void
    {
        $this->assertTrue(OpportunisticRunGate::tryClaim(self::MARKER_KEY, 3600));
        $this->assertNotNull($this->marker());
    }

    public function testSecondClaimWithinTheIntervalIsRefused(): void
    {
        $this->assertTrue(OpportunisticRunGate::tryClaim(self::MARKER_KEY, 3600));
        $this->assertFalse(OpportunisticRunGate::tryClaim(self::MARKER_KEY, 3600));
    }

    public function testClaimSucceedsAgainOnceTheIntervalHasPassed(): void
    {
        $this->writeMarker(Carbon::now()->subSeconds(120));

        $this->assertTrue(OpportunisticRunGate::tryClaim(self::MARKER_KEY, 60));

        $marker = $this->marker();
        $this->assertNotNull($marker);
        $this->assertTrue(
            Carbon::parse($marker)->greaterThan(Carbon::now()->subSeconds(30)),
            'Ein erfolgreicher Anlauf muss den Merker auf jetzt setzen.'
        );
    }

    /**
     * Über die Oberfläche kann der Merker nicht leer werden, über einen direkten
     * Datenbankzugriff schon. Ein leerer Eintrag zählt wie ein fehlender - sonst
     * bliebe die Arbeit dauerhaft und lautlos stehen.
     */
    public function testEmptyMarkerCountsAsNeverRun(): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => self::MARKER_KEY],
            ['setting_value' => '', 'binary_content' => '', 'mime_type' => 'text/plain']
        );

        $this->assertTrue(OpportunisticRunGate::tryClaim(self::MARKER_KEY, 3600));
    }

    /**
     * Der Merker wird vor der Arbeit gesetzt, nicht danach: Bricht der Lauf ab,
     * wartet die nächste Anfrage die volle Wartezeit, statt es sofort wieder zu
     * versuchen und jede Anfrage mit demselben Fehler zu belasten.
     */
    public function testMarkerIsWrittenBeforeTheWorkRuns(): void
    {
        $claimed = OpportunisticRunGate::tryClaim(self::MARKER_KEY, 3600);

        $this->assertTrue($claimed);
        $this->assertNotNull(
            $this->marker(),
            'Der Merker muss schon stehen, wenn der Aufrufer mit der Arbeit beginnt.'
        );
    }

    private function setTriggerMode(string $mode): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => 'mailqueue_trigger_mode'],
            ['setting_value' => $mode, 'binary_content' => '', 'mime_type' => 'text/plain']
        );
    }

    private function writeMarker(Carbon $moment): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => self::MARKER_KEY],
            [
                'setting_value' => $moment->format('Y-m-d H:i:s'),
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ]
        );
    }

    private function marker(): ?string
    {
        $value = AppSetting::query()->where('setting_key', self::MARKER_KEY)->value('setting_value');

        return $value === null ? null : (string) $value;
    }

    private function forgetMarker(): void
    {
        AppSetting::query()->where('setting_key', self::MARKER_KEY)->delete();
    }
}

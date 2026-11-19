<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Einstellungen als Schlüssel-Wert-Paar holen nie das Logo mit.
 *
 * `app_settings.binary_content` ist ein longblob und trägt das hochgeladene
 * Logo - erlaubt sind bis zu 2 MB (`UploadValidator::validateImageSize()` in
 * `AppSettingController`). Gebraucht wird davon an zwei Stellen nichts:
 * `Dependencies.php` legt die Einstellungen als Twig-Global `app_settings` an,
 * und `AppSettingController::index()` füllt damit das Formular. Beide wollen
 * nur `setting_key` und `setting_value`.
 *
 * Beide riefen `AppSetting::all()->pluck(...)`. Das ist der Unterschied, der
 * hier zählt: `all()` führt die Abfrage als `select *` aus und gibt eine
 * Collection zurück, die zweite Spaltenwahl findet also erst im Speicher
 * statt - das Logo ist längst geladen. `pluck()` auf dem Query Builder
 * dagegen wählt die beiden Spalten schon in SQL.
 *
 * Die Twig-Global entsteht bei jedem gerenderten Seitenaufruf. Mit einem
 * 2-MB-Logo gingen damit 2 MB je Seitenaufruf durch den Speicher, nur um zwei
 * Textspalten in ein Array zu legen.
 *
 * Das Logo selbst bleibt erreichbar: Die Auslieferung und das Mail-Branding
 * holen es gezielt über eine eigene Abfrage.
 */
final class AppSettingBlobLoadFeatureTest extends TestCase
{
    /** Zwei Megabyte - die Obergrenze, die der Upload zulässt. */
    private const LOGO_BYTES = 2 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheTextSettingsQueryNeverSelectsTheBlob(): void
    {
        $this->storeLogo();

        $statements = $this->settingStatementsDuring(static function (): void {
            AppSetting::textSettings();
        });

        $this->assertNotSame([], $statements, 'Ohne Abfrage auf app_settings prüft der Test nichts.');

        foreach ($statements as $sql) {
            $this->assertStringNotContainsString(
                '*',
                $sql,
                'Eine Abfrage ohne Spaltenliste holt das Logo mit: ' . $sql
            );
            $this->assertStringNotContainsString(
                'binary_content',
                $sql,
                'Das Logo gehört in keine Einstellungsliste: ' . $sql
            );
        }
    }

    public function testTheTextSettingsStillCarryEveryValue(): void
    {
        AppSetting::putText('app_name', 'Chorkuma');
        AppSetting::putText('primary_color', '#336699');
        $this->storeLogo();

        $settings = AppSetting::textSettings();

        $this->assertSame('Chorkuma', $settings['app_name'] ?? null);
        $this->assertSame('#336699', $settings['primary_color'] ?? null);
        $this->assertSame(
            'logo.png',
            $settings['app_logo'] ?? null,
            'Beim Logo steht der Dateiname im setting_value; den zeigt die Einstellungsseite an.'
        );

        foreach ($settings as $key => $value) {
            $this->assertIsString($value, $key);
            $this->assertLessThan(
                1024,
                strlen($value),
                $key . ' trägt einen Wert in Logo-Größe - dann ist der BLOB doch mitgekommen.'
            );
        }
    }

    public function testTheLogoStaysReachableForDelivery(): void
    {
        $this->storeLogo();

        $logo = AppSetting::find('app_logo');

        $this->assertNotNull($logo);
        $this->assertSame(
            self::LOGO_BYTES,
            strlen((string) $logo->binary_content),
            'Auslieferung und Mail-Branding lesen die Eigenschaft direkt.'
        );
    }

    private function storeLogo(): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => 'app_logo'],
            [
                'setting_value' => 'logo.png',
                'binary_content' => str_repeat("\x89", self::LOGO_BYTES),
                'mime_type' => 'image/png',
            ]
        );
    }

    /**
     * @param callable(): void $run
     * @return list<string>
     */
    private function settingStatementsDuring(callable $run): array
    {
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $run();
            $log = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return array_values(array_filter(
            array_map(static fn (array $entry): string => (string) ($entry['query'] ?? ''), $log),
            static fn (string $sql): bool => str_contains($sql, 'from `app_settings`')
        ));
    }
}

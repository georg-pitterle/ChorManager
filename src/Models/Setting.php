<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Schlüssel-Wert-Speicher des Finanzbereichs: `fiscal_year_start`,
 * `finance_next_running_number` und `finance_closed_until`.
 *
 * Bewusst getrennt von `AppSetting` (`app_settings`), obwohl das wie dieselbe
 * Tabelle mit einer BLOB-Spalte mehr aussieht und ein Zusammenlegen naheliegt.
 * Der Grund ist die Sperre: `FinanceBookingService::reserveRunningNumbers()`
 * holt die Zeile `finance_next_running_number` mit `lockForUpdate()`, um den
 * Nummernkreis zu reservieren. In `app_settings` liegt das Logo, und die
 * Twig-Global `app_settings` liest die Tabelle bei jedem gerenderten
 * Seitenaufruf (`AppSetting::textSettings()`). Lägen beide in einer Tabelle,
 * liefe jede Buchung gegen den Seitenaufbau - für einen Gewinn, der sich auf
 * eine Tabelle weniger beschränkt.
 *
 * Wer hier eine Einstellung ergänzt, die mit den Finanzen nichts zu tun hat,
 * nimmt `AppSetting::putText()`.
 */
class Setting extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'setting_key';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'setting_key',
        'setting_value'
    ];
}

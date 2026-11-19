<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Schlüssel-Wert-Speicher der Anwendung: Name, Farbe, Logo, Namensformat,
 * Log-Stufe, die Einstellungen der Mail-Warteschlange und die Erinnerungen.
 *
 * Nicht zu verwechseln mit `Setting` (`settings`), das nur die drei
 * Finanz-Schlüssel führt. Die Trennung sieht wie eine Doppelung aus, ist aber
 * Absicht - die Begründung steht bei `Setting`, damit sie an der Tabelle
 * steht, deren Zeilen gesperrt werden.
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';
    protected $primaryKey = 'setting_key';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'setting_key',
        'setting_value',
        'binary_content',
        'mime_type'
    ];

    /**
     * Das Logo liegt als BLOB in der Spalte. Gleiche Begründung wie bei
     * `Attachment::$hidden`: In einer Logzeile oder einer Fehlerausgabe, die ein
     * Modell mitschreibt, sprengt der Inhalt jede Zeile im Container-Log.
     *
     * Auf `$setting->binary_content` wirkt sich das nicht aus - AppSettingController
     * und MailBranding lesen die Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'binary_content',
    ];

    /**
     * Alle Einstellungen als `setting_key => setting_value`, ohne das Logo.
     *
     * Das Gegenstück zu `putText()` auf der Leseseite, und aus demselben Grund
     * am Modell: Die beiden Aufrufer - die Twig-Global `app_settings` in
     * `Dependencies.php` und `AppSettingController::index()` für das Formular -
     * brauchen genau diese zwei Spalten und riefen beide
     * `AppSetting::all()->pluck(...)`.
     *
     * Der Unterschied liegt im `all()`: Es führt die Abfrage als `select *`
     * aus und gibt eine Collection zurück, die Spaltenwahl von `pluck()`
     * findet also erst im Speicher statt - das Logo ist da längst geladen. Der
     * Upload lässt 2 MB zu (`UploadValidator::validateImageSize()`), und die
     * Twig-Global entsteht bei jedem gerenderten Seitenaufruf. Damit gingen
     * 2 MB je Seitenaufruf durch den Speicher, nur um zwei Textspalten in ein
     * Array zu legen - gleiche Familie wie `Attachment::METADATA_COLUMNS` und
     * `Newsletter::LIST_COLUMNS`.
     *
     * `pluck()` steht hier auf dem Query Builder und wählt die zwei Spalten
     * schon in SQL. Dass das so bleibt, hält
     * AppSettingBlobLoadFeatureTest fest - der Unterschied zwischen
     * `all()->pluck()` und `query()->pluck()` ist zu klein, um ihn einer
     * Aufrufstelle anzuvertrauen.
     *
     * Beim Logo kommt der Dateiname mit; er steht im `setting_value` und wird
     * auf der Einstellungsseite angezeigt. Den Inhalt holt, wer ihn ausliefert.
     *
     * @return array<string, string>
     */
    public static function textSettings(): array
    {
        return self::query()
            ->pluck('setting_value', 'setting_key')
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }

    /**
     * Schreibt eine Einstellung, die nur Text ist.
     *
     * Jeder dieser Schreibvorgänge füllt dieselben drei Spalten: den Wert, einen
     * leeren BLOB und `text/plain`. Als `updateOrCreate` mit beiden Arrays stand
     * das zwölfmal im AppSettingController, einmal in OpportunisticRunGate und
     * einmal im SessionInvalidationService - jedes Mal fünf Zeilen, von denen
     * zwei immer gleich lauten. Das Logo geht weiter eigene Wege: Es ist der
     * einzige Eintrag mit Inhalt im BLOB und eigenem Medientyp.
     */
    public static function putText(string $key, string $value): self
    {
        return self::updateOrCreate(
            ['setting_key' => $key],
            [
                'setting_value' => $value,
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ]
        );
    }
}

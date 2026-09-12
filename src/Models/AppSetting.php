<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}

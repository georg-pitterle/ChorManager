<?php

declare(strict_types=1);

namespace App\Models;

use App\Util\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Die abweichende Entscheidung einer Person zu einem Benachrichtigungs-Anlass,
 * je Kanal - Mail oder Glocke (Migration 20261005090100).
 *
 * Fehlt die Zeile, gilt die Vorgabe aus `NotificationType` - siehe die
 * Begründung in Migration 20260830140000.
 */
class UserNotificationSetting extends Model
{
    /**
     * Die drei Spalten, die eine Zeile eindeutig benennen. Die Tabelle hat
     * keine `id`; ihr Primärschlüssel ist das Tripel (Migrationen 20260830140000
     * und 20261005090100).
     *
     * @var list<string>
     */
    private const KEY_COLUMNS = ['user_id', 'notification_type', 'channel'];

    protected $table = 'user_notification_settings';

    /**
     * Eloquent kann nur einen einspaltigen Schlüssel benennen. `user_id` ist
     * davon der Teil, den es tatsächlich als Spalte gibt - die Vorgabe `id`
     * zeigte auf eine Spalte, die diese Tabelle nie hatte. Adressiert wird eine
     * Zeile trotzdem immer über alle drei Spalten; dafür sorgen die beiden
     * Überschreibungen weiter unten.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';

    public $incrementing = false;
    public $timestamps = false;

    /**
     * Derselbe Standard wie die Spalte. Ohne ihn kennt eine neu angelegte Zeile
     * ihren Kanal nicht, und `refresh()` suchte nach `channel IS NULL`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'channel' => NotificationChannel::MAIL,
    ];

    protected $fillable = [
        'user_id',
        'notification_type',
        'channel',
        'enabled',
    ];

    /**
     * `$timestamps` steht auf false, deshalb kümmert sich Eloquent nicht von
     * selbst um `created_at` - ohne Cast käme die Spalte als Zeichenkette.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Schreibzugriffe auf beide Schlüsselspalten einschränken.
     *
     * Ohne das baut Eloquent jede Änderung und jedes Löschen einer geladenen
     * Zeile als `where <primaryKey> = ...`. Mit der alten Vorgabe `id` war das
     * ein SQL-Fehler ("Unknown column 'id' in 'WHERE'"), mit `user_id` allein
     * träfe es alle Anlässe dieser Person statt nur den gemeinten.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $this->scopeToKeyColumns($query);
    }

    /**
     * Dasselbe für `refresh()` und `fresh()`, die die Zeile neu einlesen.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        return $this->scopeToKeyColumns($query);
    }

    /**
     * Die Bedingung, die beide Überschreibungen brauchen - einmal formuliert,
     * damit sie nicht auseinanderlaufen können.
     *
     * Gelesen wird der ursprüngliche Wert, damit auch eine Zeile mit geändertem
     * Schlüssel dort landet, wo sie herkam.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    private function scopeToKeyColumns($query)
    {
        foreach (self::KEY_COLUMNS as $column) {
            $query->where($column, $this->getOriginal($column, $this->getAttribute($column)));
        }

        return $query;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

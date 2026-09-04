<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Die abweichende Entscheidung einer Person zu einem Benachrichtigungs-Anlass.
 *
 * Fehlt die Zeile, gilt die Vorgabe aus `NotificationType` - siehe die
 * Begründung in Migration 20260830140000.
 */
class UserNotificationSetting extends Model
{
    /**
     * Die beiden Spalten, die eine Zeile eindeutig benennen. Die Tabelle hat
     * keine `id`; ihr Primärschlüssel ist das Paar (Migration 20260830140000).
     *
     * @var list<string>
     */
    private const KEY_COLUMNS = ['user_id', 'notification_type'];

    protected $table = 'user_notification_settings';

    /**
     * Eloquent kann nur einen einspaltigen Schlüssel benennen. `user_id` ist
     * davon die Hälfte, die es tatsächlich als Spalte gibt - die Vorgabe `id`
     * zeigte auf eine Spalte, die diese Tabelle nie hatte. Adressiert wird eine
     * Zeile trotzdem immer über beide Spalten; dafür sorgen die beiden
     * Überschreibungen weiter unten.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'notification_type',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Schreibzugriffe auf beide Schlüsselspalten einschränken.
     *
     * Ohne das baut Eloquent jede Änderung und jedes Löschen einer geladenen
     * Zeile als `where <primaryKey> = ...`. Mit der alten Vorgabe `id` war das
     * ein SQL-Fehler ("Unknown column 'id' in 'WHERE'"), mit `user_id` allein
     * träfe es alle Anlässe dieser Person statt nur den gemeinten. Gelesen wird
     * der ursprüngliche Wert, damit auch eine Zeile mit geändertem Schlüssel
     * dort landet, wo sie herkam.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        foreach (self::KEY_COLUMNS as $column) {
            $query->where($column, $this->getOriginal($column, $this->getAttribute($column)));
        }

        return $query;
    }

    /**
     * Dasselbe für `refresh()` und `fresh()`, die die Zeile neu einlesen.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
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

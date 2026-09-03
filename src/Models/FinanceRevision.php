<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Änderungsjournal des Kassabuchs. Jede Anlage, Änderung und jedes Storno einer
 * Buchung hinterlässt hier einen Eintrag mit den Vorher-Werten.
 */
class FinanceRevision extends Model
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_REVERSE = 'reverse';
    /** Buchungsabschluss verschoben - haengt an keiner einzelnen Buchung. */
    public const ACTION_LOCK = 'lock';

    protected $table = 'finance_revisions';
    public $timestamps = false;

    protected $fillable = [
        'finance_id',
        'user_id',
        'user_first_name',
        'user_last_name',
        'action',
        'change_set',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function finance()
    {
        return $this->belongsTo(Finance::class, 'finance_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Die handelnde Person für die Anzeige - als Vor-/Nachname-Paar, damit der
     * `person_name`-Filter die eingestellte Namensreihenfolge anwenden kann.
     *
     * Gelesen wird der Schnappschuss aus der Zeile, nicht das verknüpfte
     * Mitglied: Nur so bleibt der Eintrag lesbar, nachdem das Mitglied gelöscht
     * wurde. `null` heißt "keine Person" und wird als "System" angezeigt.
     *
     * Bewusst ein Accessor und keine actor()-Methode - gleiche Begründung wie bei
     * Finance::getIsReversalAttribute(): Eine parameterlose Methode hält Eloquent
     * beim Property-Zugriff für eine Relation und wirft "must return a
     * relationship instance", sobald das Template sie anfasst.
     *
     * @return array{first_name: string, last_name: string}|null
     */
    public function getActorAttribute(): ?array
    {
        $firstName = trim((string) $this->getAttribute('user_first_name'));
        $lastName = trim((string) $this->getAttribute('user_last_name'));

        if ($firstName === '' && $lastName === '') {
            return null;
        }

        return ['first_name' => $firstName, 'last_name' => $lastName];
    }

    /**
     * Decoded change set: field => ['from' => mixed, 'to' => mixed].
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function changeSet(): array
    {
        $raw = $this->getAttribute('change_set');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

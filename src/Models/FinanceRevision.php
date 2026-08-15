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

    protected $table = 'finance_revisions';
    public $timestamps = false;

    protected $fillable = [
        'finance_id',
        'user_id',
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

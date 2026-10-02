<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Bedingung eines Zielgruppen-Filters. reference_id zeigt je nach
 * Kategorie auf Rolle, Stimmgruppe, Untergruppe, Projekt oder Mitglied.
 */
class AudienceFilterCondition extends Model
{
    public const CATEGORY_ROLE = 'role';
    public const CATEGORY_VOICE_GROUP = 'voice_group';
    public const CATEGORY_SUB_VOICE = 'sub_voice';
    public const CATEGORY_PROJECT = 'project';
    public const CATEGORY_USER = 'user';

    /** Reihenfolge gilt auch für Anzeige und Formular. */
    public const CATEGORIES = [
        self::CATEGORY_ROLE,
        self::CATEGORY_VOICE_GROUP,
        self::CATEGORY_SUB_VOICE,
        self::CATEGORY_PROJECT,
        self::CATEGORY_USER,
    ];

    public $timestamps = false;

    protected $table = 'audience_filter_conditions';

    protected $fillable = ['audience_filter_id', 'category', 'reference_id'];

    protected $casts = [
        'audience_filter_id' => 'integer',
        'reference_id' => 'integer',
    ];
}

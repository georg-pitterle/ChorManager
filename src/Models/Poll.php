<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Umfrage an eine Zielgruppe: entweder eine Frage mit festen Antworten
 * (KIND_CHOICE) oder eine offene Frage, auf die jede Person selbst schreibt
 * (KIND_TEXT). Die Art steht mit dem Start fest.
 *
 * Es gibt kein Statusfeld. Beendet ist eine Umfrage, deren Frist vorbei ist;
 * "Jetzt beenden" setzt die Frist auf jetzt, Verlängern öffnet sie wieder.
 */
class Poll extends Model
{
    public const TITLE_MAX_LENGTH = 255;
    public const OPTION_MAX_LENGTH = 200;
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 20;

    public const KIND_CHOICE = 'choice';
    public const KIND_TEXT = 'text';

    /**
     * Die Werte spiegeln das ENUM der Spalte `polls.answer_kind` (Migration
     * 20261103123000). Die Konstanten gab es schon, die Liste nicht - und erst
     * sie hält ModelSchemaConsistencyTest gegen die Spalte.
     *
     * @var list<string>
     */
    public const ANSWER_KINDS = [
        self::KIND_CHOICE,
        self::KIND_TEXT,
    ];

    protected $table = 'polls';

    /** Wie die Spaltenvorgabe; sonst trüge ein frisch angelegtes Modell null. */
    protected $attributes = [
        'answer_kind' => self::KIND_CHOICE,
    ];

    protected $fillable = [
        'title',
        'description',
        'answer_kind',
        'allows_multiple',
        'is_anonymous',
        'closes_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'allows_multiple' => 'boolean',
        'is_anonymous' => 'boolean',
        'closes_at' => 'datetime',
        'created_by_user_id' => 'integer',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class, 'poll_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isOpenQuestion(): bool
    {
        return $this->answer_kind === self::KIND_TEXT;
    }

    public function textAnswers(): HasMany
    {
        return $this->hasMany(PollTextAnswer::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'poll_id');
    }

    /**
     * Zielgruppen-Zeilen, aufsteigend nach `id` - gleiche Begründung wie bei
     * Event::audienceFilters(): Ohne die Sortierung steht die vorab geladene
     * Zielgruppe anders herum als eine nachgeladene, und die Umfrage stünde
     * anders als Termin, Newsletter und Vorlage. Darüber wacht
     * tests/Unit/Models/AudienceFilterRelationOrderingTest.
     *
     * Aufgelöst wird die Zielgruppe heute nicht über diese Relation, sondern
     * über AudienceFilterService (PollAudienceService) - die Relation ist der
     * gemeinsame Zugang, den AudienceOwnerService mit `unsetRelation()`
     * voraussetzt.
     */
    public function audienceFilters(): HasMany
    {
        return $this->hasMany(AudienceFilter::class, 'poll_id')
            ->orderBy('audience_filters.id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isClosed(?Carbon $now = null): bool
    {
        return $this->closes_at === null || !$this->closes_at->greaterThan($now ?? Carbon::now());
    }

    /** Ob schon jemand abgestimmt hat - danach sind Antworten und Auswahlart gesperrt. */
    public function hasVotes(): bool
    {
        return Capsule::table('poll_participants')->where('poll_id', $this->id)->exists();
    }
}

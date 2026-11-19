<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsletterTemplate extends Model
{
    /**
     * Spalten, die die Übersicht und das Auswahlfeld laden dürfen.
     *
     * Gleiche Grenze und gleiche Begründung wie `Newsletter::LIST_COLUMNS`:
     * `content_html` ist longtext, und weder `newsletters/templates_index.twig`
     * noch das Vorlagen-Auswahlfeld in `newsletters/create.twig` zeigen den
     * Text an - sie brauchen Name und Kontext. Der Bearbeiten-Dialog holt die
     * Vorlage ohnehin einzeln über `/newsletters/templates/{id}/edit?modal=1`
     * nach und bekommt den Text dort.
     *
     * @var list<string>
     */
    public const LIST_COLUMNS = [
        'id',
        'name',
        'project_id',
    ];

    protected $table = 'newsletter_templates';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'default_title',
        'description',
        'content_html',
        'project_id',
        'category',
        'created_by',
    ];

    /**
     * Zweite Absicherung neben LIST_COLUMNS, wie bei `Newsletter::$hidden`:
     * Wird eine Vorlage doch einmal mit allen Spalten geladen und danach
     * serialisiert, bleibt der Mailtext aus der Ausgabe.
     *
     * Auf `$template->content_html` wirkt sich das nicht aus - der
     * Bearbeiten-Dialog und das Übernehmen in einen Newsletter lesen die
     * Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'content_html',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Zielgruppen-Zeilen; dazu kommen die Termine aus recipientSources().
     *
     * Aufsteigend nach `id` - gleiche Begründung wie bei Event::audienceFilters():
     * Ohne die Sortierung steht die vorab geladene Zielgruppe anders herum als
     * die in HasAudienceConditionSets nachgeladene.
     */
    public function audienceFilters(): HasMany
    {
        return $this->hasMany(AudienceFilter::class, 'newsletter_template_id', 'id')
            ->orderBy('audience_filters.id');
    }

    /** Nur noch "Zielgruppe eines Termins" (event_attendees). */
    public function recipientSources(): HasMany
    {
        return $this->hasMany(NewsletterTemplateRecipientSource::class, 'template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

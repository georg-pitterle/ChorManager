<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    use HasEntityAttachments;

    /** Der Wert, unter dem Anhänge an diesem Lied hängen. */
    public const ENTITY_TYPE = 'song';

    protected $table = 'songs';
    public $timestamps = false;

    protected $fillable = [
        'title',
        'composer',
        'arranger',
        'publisher',
        'created_by_user_id',
    ];

    /**
     * `$timestamps` steht auf false, deshalb kümmert sich Eloquent nicht von
     * selbst um `created_at` - ohne Cast käme die Spalte als Zeichenkette.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Die Repertoire-Kategorien in der eingestellten Reihenfolge, der Name
     * bricht den Gleichstand.
     *
     * Die Sortierung steht hier und nicht im Eager-Load eines Aufrufers:
     * `repertoire_categories.sort_order` gibt es nur, damit die Kategorien in
     * der eingestellten Ordnung stehen. Vorher sortierte allein
     * `SongLibraryController::show()`, während die Übersicht sie ohne
     * `ORDER BY` lud - dieselben Abzeichen standen damit auf der Einzelansicht
     * und in `songs/manage.twig` unterschiedlich herum. Gleiche Begründung wie
     * bei `Event::audienceFilters()`.
     */
    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'song_category_assignments',
            'song_id',
            'repertoire_category_id'
        )->orderBy('repertoire_categories.sort_order')->orderBy('repertoire_categories.name');
    }

    /**
     * Einziger Weg vom Lied zum Projekt. Eine direkte belongsTo-Relation gibt es
     * nicht mehr: `songs.project_id` ist mit
     * 20260421120000_drop_songs_project_id entfallen.
     */
    public function projectAssignments()
    {
        return $this->hasMany(ProjectSongAssignment::class, 'song_id', 'id');
    }

    public function resources()
    {
        return $this->hasMany(SongResource::class, 'song_id', 'id');
    }

    public function linkResources()
    {
        return $this->hasMany(SongResource::class, 'song_id', 'id')
            ->where('resource_type', SongResource::TYPE_LINK)
            ->orderBy('title', 'asc');
    }

    public function sheetArchive()
    {
        return $this->hasOne(SheetArchive::class, 'song_id', 'id');
    }
}

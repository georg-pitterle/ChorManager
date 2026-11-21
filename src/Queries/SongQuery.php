<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Category;
use App\Models\Project;
use App\Models\Song;
use Illuminate\Database\Eloquent\Collection;

/**
 * Die Abfragen der Liedbibliothek.
 *
 * Sie standen im SongLibraryController. Alles ist read-only.
 */
class SongQuery
{
    /**
     * Die Übersicht: Lieder nach Titel mit Kategorien, Anhängen (nach Name) und
     * Projektzuordnungen. Ein leerer Suchbegriff bzw. Kategorie 0 filtert nicht.
     *
     * @return Collection<int, Song>
     */
    public function forIndex(string $search, int $categoryId): Collection
    {
        $songs = Song::with([
            'categories',
            'attachments' => function ($query) {
                $query->orderBy('original_name', 'asc');
            },
            // Ohne `.project`: Die Übersicht gibt allein die Anzahl der Zuordnungen
            // aus (`song.projectAssignments|length` in songs/manage.twig). Das
            // Projekt hinter jeder Zuordnung kostete eine Abfrage je Seitenaufruf,
            // die niemand ausliest - dieselbe Richtung wie bei
            // UserQuery::findIncludingArchived(). Die Detailmaske lädt es weiter,
            // dort steht der Projektname auch (SongLibraryController::show()).
            'projectAssignments',
        ])->orderBy('title', 'asc');

        if ($search !== '') {
            // Die Platzhalter von LIKE werden maskiert: Im Suchfeld sind "%" und "_"
            // gesuchte Zeichen, keine Muster. Unmaskiert traf die Suche nach "100%"
            // auch "1000", und ein "_" stand für ein beliebiges Zeichen - die
            // Trefferliste war breiter als die Eingabe. Dieselbe Maskierung wie in
            // FileSearchService::search().
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            $songs->where(function ($query) use ($pattern) {
                $query->where('title', 'like', $pattern)
                    ->orWhere('composer', 'like', $pattern)
                    ->orWhere('arranger', 'like', $pattern)
                    ->orWhere('publisher', 'like', $pattern);
            });
        }

        if ($categoryId > 0) {
            $songs->whereHas('categories', function ($query) use ($categoryId) {
                $query->where('repertoire_categories.id', $categoryId);
            });
        }

        return $songs->get();
    }

    /**
     * Kategorien nach Reihenfolge, bei Gleichstand nach Name.
     *
     * @return Collection<int, Category>
     */
    public function categoriesInOrder(): Collection
    {
        return Category::orderBy('sort_order', 'asc')->orderBy('name', 'asc')->get();
    }

    /** @return Collection<int, Project> */
    public function projectsChronological(): Collection
    {
        return Project::query()->chronological()->get();
    }
}

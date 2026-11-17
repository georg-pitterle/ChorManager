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
            'projectAssignments.project',
        ])->orderBy('title', 'asc');

        if ($search !== '') {
            $songs->where(function ($query) use ($search) {
                $query->where('title', 'like', '%' . $search . '%')
                    ->orWhere('composer', 'like', '%' . $search . '%')
                    ->orWhere('arranger', 'like', '%' . $search . '%')
                    ->orWhere('publisher', 'like', '%' . $search . '%');
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

    /**
     * Projekte nach Name mit den ihnen zugeordneten Liedern (nach Titel) und deren
     * Anhängen (nach Name).
     *
     * @return Collection<int, Project>
     */
    public function projectsWithAssignedSongs(): Collection
    {
        return Project::with([
            'assignedSongs' => function ($query) {
                $query->orderBy('title', 'asc');
            },
            'assignedSongs.attachments' => function ($query) {
                $query->orderBy('original_name', 'asc');
            },
        ])->orderBy('name', 'asc')->get();
    }

    /** @return Collection<int, Project> */
    public function projectsChronological(): Collection
    {
        return Project::query()->chronological()->get();
    }
}

<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\Song;
use App\Models\SongResource;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Schreibt Lieder, ihre Kategorien und ihre Link-Ressourcen.
 *
 * Die Schreibaufrufe standen im SongLibraryController. Was ein gültiger Titel oder
 * eine gültige Adresse ist, prüft weiterhin der Controller; hier kommen fertige Werte an.
 */
class SongPersistence
{
    /**
     * Ein neues Lied und seine Kategorien entstehen zusammen oder gar nicht.
     *
     * @param array<string, mixed> $attributes
     * @param list<int> $categoryIds
     */
    public function createSong(array $attributes, array $categoryIds): Song
    {
        return Capsule::connection()->transaction(static function () use ($attributes, $categoryIds): Song {
            $song = Song::create($attributes);
            if ($categoryIds !== []) {
                $song->categories()->sync($categoryIds);
            }

            return $song;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateSong(Song $song, array $attributes): void
    {
        $song->update($attributes);
    }

    public function deleteSong(Song $song): void
    {
        $song->delete();
    }

    /** @param list<int> $categoryIds */
    public function syncCategories(Song $song, array $categoryIds): void
    {
        $song->categories()->sync($categoryIds);
    }

    /** @param array<string, mixed> $attributes */
    public function createLinkResource(int $songId, array $attributes): SongResource
    {
        return SongResource::create([
            'song_id' => $songId,
            'resource_type' => SongResource::TYPE_LINK,
        ] + $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function updateLinkResource(SongResource $resource, array $attributes): void
    {
        $resource->update($attributes);
    }

    public function deleteLinkResource(SongResource $resource): void
    {
        $resource->delete();
    }
}

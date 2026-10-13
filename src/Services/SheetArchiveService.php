<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SheetArchive;
use App\Models\SheetArchiveLineItem;
use App\Models\Song;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;

class SheetArchiveService
{
    /**
     * Save archive data (metadata + line items)
     * Merges duplicate voice categories and validates data
     *
     * @param int $songId
     * @param string|null $archiveNumber
     * @param string|null $location
     * @param array<array{voice_category: string, count: int}> $lineItems
     */
    public function saveArchiveData(
        int $songId,
        ?string $archiveNumber,
        ?string $location,
        array $lineItems
    ): SheetArchive {
        // Validate input
        $this->validateLineItems($lineItems);

        // Kopfdaten anlegen oder fortschreiben. Vorher standen dieselben zwei Werte
        // zweimal da - einmal als Vorgabe für `firstOrCreate()`, einmal in einem
        // `update()` unmittelbar danach, weil `firstOrCreate()` einen bestehenden
        // Satz unverändert zurückgibt. `updateOrCreate()` ist genau diese Kombination
        // und kommt ohne die zweite Abschrift aus, die bei einer Änderung sonst
        // mitgepflegt werden müsste.
        $archive = SheetArchive::updateOrCreate(
            ['song_id' => $songId],
            [
                'archive_number' => $archiveNumber ? trim($archiveNumber) : null,
                'location' => $location ? trim($location) : null,
            ]
        );

        // Merge duplicate categories and filter empty entries
        $mergedItems = $this->mergeAndFilterLineItems($lineItems);

        // Löschen und Neuanlegen gehören zusammen: Scheitert eine Position beim
        // Schreiben, stand das Archiv sonst ohne jede Position da - der alte Stand
        // war bereits gelöscht, der neue nie vollständig geschrieben.
        Capsule::connection()->transaction(function () use ($archive, $mergedItems): void {
            $archive->lineItems()->delete();

            $sortOrder = 0;
            foreach ($mergedItems as $item) {
                SheetArchiveLineItem::create([
                    'sheet_archive_id' => $archive->id,
                    'voice_category' => trim($item['voice_category']),
                    'count' => (int) $item['count'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        });

        return $archive->fresh();
    }

    /**
     * Get archive data for a song
     */
    public function getArchiveData(int $songId): ?SheetArchive
    {
        return SheetArchive::where('song_id', $songId)->with('lineItems')->first();
    }

    /**
     * Get all voice categories used across archives
     */
    public function getAllVoiceCategories(): array
    {
        return SheetArchiveLineItem::select('voice_category')
            ->distinct()
            ->orderBy('voice_category', 'asc')
            ->pluck('voice_category')
            ->toArray();
    }

    /**
     * Validate line items structure and content
     *
     * @throws InvalidArgumentException
     */
    private function validateLineItems(array $lineItems): void
    {
        foreach ($lineItems as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each line item must be an array');
            }

            if (empty($item['voice_category']) || !is_string($item['voice_category'])) {
                throw new InvalidArgumentException('voice_category must be a non-empty string');
            }

            $category = trim($item['voice_category']);

            // Validate length (DB column: varchar(100)). MySQL zählt Zeichen, nicht
            // Bytes - mit strlen() wurde eine Stimmkategorie mit Umlauten schon bei
            // gut der halben erlaubten Länge abgelehnt.
            if (mb_strlen($category) > 100) {
                throw new InvalidArgumentException('voice_category must not exceed 100 characters');
            }

            // Buchstaben, Ziffern, Leerraum und die übliche Zeichensetzung. Umlaute
            // standen hier zusätzlich aufgezählt - `\p{L}` deckt sie längst ab, und
            // die Aufzählung ließ den Eindruck entstehen, andere Schriften bräuchten
            // einen eigenen Eintrag.
            //
            // `!== 1` statt `!`: Bei ungültigem UTF-8 gibt preg_match() false zurück,
            // und das muss wie "Format nicht erfüllt" wirken, nicht wie ein Treffer.
            if (preg_match('/^[\p{L}\p{N}\s\-\.\/\(\)]+$/u', $category) !== 1) {
                throw new InvalidArgumentException('voice_category contains invalid characters');
            }

            if (!isset($item['count']) || !is_numeric($item['count'])) {
                throw new InvalidArgumentException('count must be numeric');
            }

            $count = (int) $item['count'];
            if ($count < 0) {
                throw new InvalidArgumentException('count must be >= 0');
            }
        }
    }

    /**
     * Merge duplicate voice categories (sum counts) and filter empty entries
     *
     * @param array<array{voice_category: string, count: int}> $lineItems
     * @return array<array{voice_category: string, count: int}>
     */
    private function mergeAndFilterLineItems(array $lineItems): array
    {
        $merged = [];

        foreach ($lineItems as $item) {
            $category = trim($item['voice_category']);
            $count = (int) $item['count'];

            // Skip empty categories or zero counts
            if (empty($category) || $count === 0) {
                continue;
            }

            if (!isset($merged[$category])) {
                $merged[$category] = 0;
            }
            $merged[$category] += $count;
        }

        // Convert back to indexed array format
        //
        // `(string)` ist nicht nur Form: Eine rein numerische Stimmkategorie ist
        // erlaubt (validateLineItems() lässt `\p{N}` durch - etwa eine Regalnummer
        // als Kategorie), und PHP macht aus einem Zahlen-String als Array-Schlüssel
        // stillschweigend einen int. Ohne die Rückwandlung bekam der Aufrufer dort
        // eine Zahl statt des eingegebenen Textes, und das `trim()` beim Schreiben
        // der Positionen scheiterte unter `strict_types` mit einem TypeError. Der
        // ist ein `Error`, den das `catch (\Exception)` im SheetArchiveController
        // nicht fängt: 500 ohne JSON-Antwort, und die Kopfdaten des Archivs standen
        // schon in der Datenbank, während keine einzige Position dazu geschrieben war.
        $result = [];
        foreach ($merged as $category => $count) {
            $result[] = [
                'voice_category' => (string) $category,
                'count' => $count,
            ];
        }

        return $result;
    }
}

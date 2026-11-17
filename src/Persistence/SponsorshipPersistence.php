<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\Attachment;
use App\Models\Sponsorship;
use App\Models\SponsorshipBenefit;
use App\Models\SponsorshipDeliverable;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Schreibt Sponsoring-Vereinbarungen und ihre Leistungszeilen.
 *
 * Die Schreibaufrufe standen im SponsorshipController. Die Fachregeln - welcher Status
 * ein Paket trägt, ob ein Logo gebraucht wird, wer betreuen darf - bleiben dort; hier
 * kommen fertige Attribute an.
 */
class SponsorshipPersistence
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Sponsorship
    {
        return Sponsorship::create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Sponsorship $sponsorship, array $attributes): void
    {
        $sponsorship->update($attributes);
    }

    public function delete(Sponsorship $sponsorship): void
    {
        $sponsorship->delete();
    }

    /**
     * Setzt oder löscht den Haken einer Leistungszeile. $field ist der Wortstamm der
     * Spalten ("delivered" => delivered_at, delivered_by_user_id). Ein Haken, der schon
     * so steht, bleibt unberührt: Wer ein zweites Mal abhakt, ändert weder das Datum
     * noch die Person des ersten.
     */
    public function tickLine(
        SponsorshipBenefit|SponsorshipDeliverable $line,
        string $field,
        bool $done,
        ?int $actorId
    ): void {
        $isDone = $line->getAttribute($field . '_at') !== null;
        if ($done === $isDone) {
            return;
        }

        $line->update([
            $field . '_at' => $done ? Carbon::today()->format('Y-m-d') : null,
            $field . '_by_user_id' => $done ? $actorId : null,
        ]);
    }

    /**
     * Gleicht eine Leistungsliste der Vereinbarung - Gegenleistungen oder Leistungen des
     * Sponsors - mit der neuen Liste ab.
     *
     * Eine Zeile, deren Text gleich bleibt, behält ihren Haken - sonst müsste nach jeder
     * Korrektur der Liste alles neu abgehakt werden. Neue Zeilen kommen offen hinzu,
     * gestrichene verschwinden. Alles in einer Transaktion.
     *
     * @param HasMany<Model, Sponsorship> $relation
     * @param list<string> $items
     */
    public function syncLines(HasMany $relation, array $items): void
    {
        Capsule::connection()->transaction(static function () use ($relation, $items): void {
            $existing = (clone $relation)->get();
            $kept = [];

            foreach ($items as $index => $description) {
                $match = $existing->first(
                    static fn (Model $line): bool => $line->getAttribute('description') === $description
                        && !in_array($line->getKey(), $kept, true)
                );

                if ($match !== null) {
                    $match->update(['sort_order' => $index]);
                    $kept[] = $match->getKey();
                    continue;
                }

                $created = (clone $relation)->create(['description' => $description, 'sort_order' => $index]);
                $kept[] = $created->getKey();
            }

            (clone $relation)->whereNotIn('id', $kept === [] ? [0] : $kept)->delete();
        });
    }

    public function renameAttachment(Attachment $attachment, string $name): void
    {
        $attachment->update(['original_name' => $name]);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Die öffentlichen Notizen einer Entität, die neueste zuerst.
 *
 * Termin, Projekt und Aufgabe beantworteten dieselbe Frage vorher an drei
 * Stellen wortgleich - unterschiedlich war allein der `entity_type`. Damit stand
 * sowohl die Grenze um die privaten Notizen als auch die Sortierung dreimal da,
 * und wer die eine Fassung anfasste, musste die beiden anderen mitnehmen. Genau
 * so fehlte die Sortierung bei Aufgabe und Projekt eine Zeit lang ganz, während
 * der Termin sie längst hatte.
 *
 * Private Notizen bleiben hier aussen vor: Die Beziehung kennt die angemeldete
 * Person nicht und könnte eine fremde private Notiz nicht von der eigenen
 * unterscheiden. Ohne diese Grenze hinge es am jeweiligen Aufrufer, ob eine
 * fremde private Notiz in der Ausgabe landet - und `withCount('comments')` in
 * einer Liste hätte sie sogar mitgerechnet. Wer auch die eigenen privaten
 * Notizen braucht, setzt die Beziehung mit Comment::visibleTo() bewusst selbst.
 *
 * Voraussetzung ist eine Konstante `ENTITY_TYPE` auf dem Modell; darüber wacht
 * tests/Unit/Models/EntityRelationTraitsTest.
 */
trait HasEntityComments
{
    /**
     * Die neueste zuerst, `id` bricht den Gleichstand: `comments.created_at`
     * steht auf Sekunden genau, und zwei Notizen in derselben Sekunde sind keine
     * Ausnahme. Ohne den zweiten Schlüssel wechselt ihre Reihenfolge zwischen
     * zwei Aufrufen. Alle Entitäten teilen sich mit `partials/comments.twig`
     * dieselbe Darstellung und dürfen nicht unterschiedlich herum stehen.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'entity_id', 'id')
            ->where('entity_type', static::ENTITY_TYPE)
            ->where('is_private', false)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
    }
}

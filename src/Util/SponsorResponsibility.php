<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\Sponsor;
use App\Models\User;

/**
 * Wer sich gerade um einen Sponsor kümmert.
 *
 * Die Zuständigkeit steht am einzelnen Vorgang, weil Rechte daran hängen
 * (SponsoringPolicy). Die Organisation fragt aber nach dem Sponsor: Diese Klasse
 * fasst die laufenden Vorgänge zusammen, ohne einen zweiten Wert am Sponsor
 * einzuführen, der von den Vorgängen weglaufen könnte - dieselbe Überlegung wie
 * bei SponsorEngagementState.
 *
 * Als Zuständige zählt nur, wer sich auch kümmern kann: aktiv und mit einem der
 * beiden Sponsoring-Rechte (User::hasSponsoringAccess()). Eine archivierte oder
 * rechtlos gewordene Person darf eingetragen bleiben, der Vorgang gilt aber als
 * Lücke - dieselbe Grenze wie in Sponsorship::scopeWithoutCapableAssignee().
 *
 * Erwartet die Relationen `sponsorships`, `sponsorships.assignedUser` und
 * `sponsorships.assignedUser.roles` - die Rollen für die Rechtefrage.
 */
final class SponsorResponsibility
{
    /**
     * @return array{assignees: list<User>, unassigned: int} die Zuständigen je
     *     einmal und nach Name sortiert, dazu die Anzahl der laufenden Vorgänge
     *     ohne aktive Person
     */
    public static function forSponsor(Sponsor $sponsor): array
    {
        $assignees = [];
        $unassigned = 0;

        foreach ($sponsor->sponsorships as $sponsorship) {
            if (!in_array((string) $sponsorship->status, SponsorshipStatus::ONGOING, true)) {
                continue;
            }

            if (!$sponsorship->hasCapableAssignee()) {
                $unassigned++;
                continue;
            }

            $person = $sponsorship->assignedUser;
            $assignees[(int) $person->id] = $person;
        }

        usort($assignees, static fn (User $a, User $b): int => [$a->last_name, $a->first_name]
            <=> [$b->last_name, $b->first_name]);

        return ['assignees' => $assignees, 'unassigned' => $unassigned];
    }
}

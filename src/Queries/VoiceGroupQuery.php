<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Die Mitgliederzahlen je Stimmgruppe und Unterstimme.
 *
 * Gezählt wird in zwei Abfragen statt je Gruppe einer: Die Seite zeigt alle Gruppen
 * mit allen Unterstimmen, und eine Zählung je Zeile wäre ein N+1. Die Zahlen kamen
 * aus dem VoiceGroupController.
 */
class VoiceGroupQuery
{
    /**
     * Nur vorhandene Zuordnungen: Eine Gruppe ohne Mitglieder hat keinen Eintrag, die
     * Vorbelegung mit 0 gehört dem Aufrufer.
     *
     * @return array{groups: array<int, int>, sub_voices: array<int, int>}
     */
    public function memberCounts(): array
    {
        $groups = [];
        $rows = Capsule::table('user_voice_groups')
            ->selectRaw('voice_group_id, COUNT(*) AS member_count')
            ->groupBy('voice_group_id')
            ->get();
        foreach ($rows as $row) {
            $groups[(int) $row->voice_group_id] = (int) $row->member_count;
        }

        $subVoices = [];
        $subRows = Capsule::table('user_voice_groups')
            ->selectRaw('sub_voice_id, COUNT(*) AS member_count')
            ->whereNotNull('sub_voice_id')
            ->groupBy('sub_voice_id')
            ->get();
        foreach ($subRows as $row) {
            $subVoices[(int) $row->sub_voice_id] = (int) $row->member_count;
        }

        return ['groups' => $groups, 'sub_voices' => $subVoices];
    }
}

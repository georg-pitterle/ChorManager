<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\AudienceFilter;
use App\Models\AudienceFilterCondition as C;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;

/**
 * Zielgruppen-Filter auswerten: innerhalb einer Kategorie ODER, zwischen den
 * Kategorien UND, ein Filter ohne Bedingung trifft alle. Ausgewertet wird in
 * PHP über die geladenen Bedingungen - die Regel steht damit an genau einer
 * Stelle, und für einen Chorbestand ist das schneller als verschachteltes SQL.
 */
final class AudienceFilterService
{
    public function profileOf(int $userId): ?MemberProfile
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        $pivot = DB::table('user_voice_groups')->where('user_id', $userId)->get(['voice_group_id', 'sub_voice_id']);

        return new MemberProfile(
            $userId,
            self::ints($user->roles()->pluck('role_id')),
            self::ints($pivot->pluck('voice_group_id')),
            self::ints($pivot->pluck('sub_voice_id')->filter()),
            self::ints($user->projects()->pluck('project_id'))
        );
    }

    /**
     * Welche der übergebenen Filter auf das Mitglied passen. Unbekannte
     * Filter passen nie.
     *
     * @param list<int> $filterIds
     * @return list<int>
     */
    public function matchingFilterIds(MemberProfile $profile, array $filterIds): array
    {
        $matching = [];
        foreach ($this->conditionsOf($filterIds) as $filterId => $categories) {
            if ($this->fits($profile, $categories)) {
                $matching[] = $filterId;
            }
        }

        return $matching;
    }

    /**
     * Bedingungen je Filter, Kategorien in fester Reihenfolge, Kennungen
     * aufsteigend. Jeder existierende Filter steht als Schlüssel darin, auch
     * ohne Bedingung.
     *
     * @param list<int> $filterIds
     * @return array<int, array<string, list<int>>>
     */
    public function conditionsOf(array $filterIds): array
    {
        $filterIds = array_values(array_unique(array_map('intval', $filterIds)));
        if ($filterIds === []) {
            return [];
        }

        $existing = AudienceFilter::query()->whereIn('id', $filterIds)->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
        $collected = array_fill_keys($existing, []);
        $rows = DB::table('audience_filter_conditions')
            ->whereIn('audience_filter_id', $existing ?: [0])
            ->orderBy('reference_id')
            ->get(['audience_filter_id', 'category', 'reference_id']);
        foreach ($rows as $row) {
            $collected[(int) $row->audience_filter_id][(string) $row->category][] = (int) $row->reference_id;
        }

        $result = [];
        foreach ($collected as $filterId => $categories) {
            $ordered = [];
            foreach (C::CATEGORIES as $category) {
                if (isset($categories[$category])) {
                    $ordered[$category] = $categories[$category];
                }
            }
            $result[$filterId] = $ordered;
        }

        return $result;
    }

    /**
     * @param array<string, list<int>> $conditions bereits normalisiert
     */
    public function create(array $conditions): AudienceFilter
    {
        $filter = AudienceFilter::create([]);
        foreach ($conditions as $category => $ids) {
            foreach ($ids as $id) {
                $filter->conditions()->create(['category' => $category, 'reference_id' => (int) $id]);
            }
        }

        return $filter;
    }

    /**
     * @param list<int> $filterIds
     */
    public function delete(array $filterIds): void
    {
        if ($filterIds !== []) {
            AudienceFilter::query()->whereIn('id', $filterIds)->delete();
        }
    }

    /**
     * Aktive Mitglieder, die der Filter trifft.
     *
     * @return Builder<User>
     */
    public function membersQuery(int $filterId): Builder
    {
        $query = User::query()->where('users.is_active', 1);
        $relations = [
            C::CATEGORY_ROLE => ['roles', 'roles.id'],
            C::CATEGORY_VOICE_GROUP => ['voiceGroups', 'voice_groups.id'],
            C::CATEGORY_SUB_VOICE => ['subVoices', 'sub_voices.id'],
            C::CATEGORY_PROJECT => ['projects', 'projects.id'],
        ];

        $conditions = $this->conditionsOf([$filterId]);
        if (!isset($conditions[$filterId])) {
            // Unbekannter Filter trifft niemanden.
            return $query->whereRaw('1 = 0');
        }

        foreach ($conditions[$filterId] as $category => $ids) {
            if ($category === C::CATEGORY_USER) {
                $query->whereIn('users.id', $ids);
                continue;
            }
            [$relation, $column] = $relations[$category];
            $query->whereHas($relation, static fn ($q) => $q->whereIn($column, $ids));
        }

        return $query;
    }

    /**
     * @param array<string, list<int>> $categories
     */
    private function fits(MemberProfile $profile, array $categories): bool
    {
        foreach ($categories as $category => $ids) {
            $any = false;
            foreach ($ids as $id) {
                if ($profile->has($category, $id)) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param iterable<mixed> $values
     * @return list<int>
     */
    private static function ints(iterable $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }
}

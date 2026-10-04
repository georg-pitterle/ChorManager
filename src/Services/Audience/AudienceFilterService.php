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
 * Kategorien UND, ein Filter ohne Bedingung trifft alle. Mehrere Filter eines
 * Besitzers sind untereinander ODER; ein Besitzer ohne Filter trifft niemanden.
 * Ausgewertet wird in PHP über die geladenen Bedingungen - die Regel steht
 * damit an genau einer Stelle, und für einen Chorbestand ist das schneller als
 * verschachteltes SQL.
 */
final class AudienceFilterService
{
    public function profileOf(int $userId): ?MemberProfile
    {
        return $this->profilesOf([$userId])[$userId] ?? null;
    }

    /**
     * Profile mehrerer Mitglieder mit vier Abfragen statt vier je Mitglied.
     *
     * @param list<int> $userIds
     * @return array<int, MemberProfile>
     */
    public function profilesOf(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $existing = User::query()->whereIn('id', $userIds ?: [0])->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
        if ($existing === []) {
            return [];
        }

        $roles = DB::table('user_roles')->whereIn('user_id', $existing)->get(['user_id', 'role_id']);
        $voices = DB::table('user_voice_groups')->whereIn('user_id', $existing)
            ->get(['user_id', 'voice_group_id', 'sub_voice_id']);
        $projects = DB::table('project_users')->whereIn('user_id', $existing)->get(['user_id', 'project_id']);

        $profiles = [];
        foreach ($existing as $userId) {
            $own = static fn ($rows) => $rows->filter(static fn ($row): bool => (int) $row->user_id === $userId);
            $profiles[$userId] = new MemberProfile(
                $userId,
                self::ints($own($roles)->pluck('role_id')),
                self::ints($own($voices)->pluck('voice_group_id')),
                self::ints($own($voices)->pluck('sub_voice_id')->filter()),
                self::ints($own($projects)->pluck('project_id'))
            );
        }

        return $profiles;
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
    public function create(array $conditions, string $ownerColumn, int $ownerId): AudienceFilter
    {
        $filter = AudienceFilter::create([self::ownerColumn($ownerColumn) => $ownerId]);
        foreach ($conditions as $category => $ids) {
            foreach ($ids as $id) {
                $filter->conditions()->create(['category' => $category, 'reference_id' => (int) $id]);
            }
        }

        return $filter;
    }

    /**
     * Ersetzt alle Filter eines Besitzers. Löschen und Anlegen gehören
     * zusammen: Bricht es dazwischen ab, stünde der Besitzer ohne Filter da.
     *
     * @param list<array<string, list<int>>> $conditionSets bereits normalisiert
     */
    public function replaceForOwner(string $ownerColumn, int $ownerId, array $conditionSets): void
    {
        $column = self::ownerColumn($ownerColumn);
        DB::connection()->transaction(function () use ($column, $ownerId, $conditionSets): void {
            AudienceFilter::query()->where($column, $ownerId)->delete();
            foreach ($conditionSets as $conditions) {
                $this->create($conditions, $column, $ownerId);
            }
        });
    }

    /**
     * Bedingungsmengen je Besitzer. Jeder angefragte Besitzer steht als
     * Schlüssel darin, ohne Filter mit [].
     *
     * @param list<int> $ownerIds
     * @return array<int, list<array<string, list<int>>>>
     */
    public function conditionSetsForOwners(string $ownerColumn, array $ownerIds): array
    {
        $column = self::ownerColumn($ownerColumn);
        $ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
        $result = array_fill_keys($ownerIds, []);
        if ($ownerIds === []) {
            return $result;
        }

        $filters = AudienceFilter::query()->with('conditions')->whereIn($column, $ownerIds)->orderBy('id')->get();
        foreach ($filters as $filter) {
            $result[(int) $filter->{$column}][] = $filter->conditionSet();
        }

        return $result;
    }

    /**
     * Aktive Mitglieder, die mindestens eine der Bedingungsmengen trifft.
     *
     * @param list<array<string, list<int>>> $conditionSets
     * @return Builder<User>
     */
    public function membersQueryForSets(array $conditionSets): Builder
    {
        $query = User::query()->where('users.is_active', 1);
        if ($conditionSets === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $any) use ($conditionSets): void {
            foreach ($conditionSets as $conditions) {
                $any->orWhere(function (Builder $all) use ($conditions): void {
                    $all->whereRaw('1 = 1');
                    $this->applyConditions($all, $conditions);
                });
            }
        });

        return $query;
    }

    /**
     * @return Builder<User>
     */
    public function membersQueryForOwner(string $ownerColumn, int $ownerId): Builder
    {
        return $this->membersQueryForSets($this->conditionSetsForOwners($ownerColumn, [$ownerId])[$ownerId]);
    }

    /**
     * @param list<array<string, list<int>>> $conditionSets
     */
    public function fitsAny(MemberProfile $profile, array $conditionSets): bool
    {
        foreach ($conditionSets as $conditions) {
            if ($this->fits($profile, $conditions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Besitzer, von denen mindestens ein Filter auf das Mitglied passt.
     *
     * @return list<int>
     */
    public function matchingOwnerIds(MemberProfile $profile, string $ownerColumn): array
    {
        $column = self::ownerColumn($ownerColumn);
        $owners = AudienceFilter::query()->whereNotNull($column)->pluck($column, 'id')->all();
        $matching = [];
        foreach ($this->matchingFilterIds($profile, array_map('intval', array_keys($owners))) as $filterId) {
            $matching[(int) $owners[$filterId]] = true;
        }

        return array_keys($matching);
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
     * @param Builder<User> $query
     * @param array<string, list<int>> $conditions
     */
    private function applyConditions(Builder $query, array $conditions): void
    {
        $relations = [
            C::CATEGORY_ROLE => ['roles', 'roles.id'],
            C::CATEGORY_VOICE_GROUP => ['voiceGroups', 'voice_groups.id'],
            C::CATEGORY_SUB_VOICE => ['subVoices', 'sub_voices.id'],
            C::CATEGORY_PROJECT => ['projects', 'projects.id'],
        ];
        foreach ($conditions as $category => $ids) {
            if ($category === C::CATEGORY_USER) {
                $query->whereIn('users.id', $ids);
                continue;
            }
            [$relation, $column] = $relations[$category];
            $query->whereHas($relation, static fn ($q) => $q->whereIn($column, $ids));
        }
    }

    private static function ownerColumn(string $column): string
    {
        if (!in_array($column, AudienceFilter::OWNER_COLUMNS, true)) {
            throw new \InvalidArgumentException('Unbekannte Besitzer-Spalte: ' . $column);
        }

        return $column;
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

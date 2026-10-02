<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\AudienceFilterCondition as C;

/** Zugehörigkeiten eines Mitglieds, einmal je Prüfung geladen. */
final class MemberProfile
{
    /** @var array<string, array<int, true>> */
    private array $index;

    /**
     * @param list<int> $roleIds
     * @param list<int> $voiceGroupIds
     * @param list<int> $subVoiceIds
     * @param list<int> $projectIds
     */
    public function __construct(
        public readonly int $userId,
        array $roleIds,
        array $voiceGroupIds,
        array $subVoiceIds,
        array $projectIds
    ) {
        $this->index = [
            C::CATEGORY_ROLE => array_fill_keys($roleIds, true),
            C::CATEGORY_VOICE_GROUP => array_fill_keys($voiceGroupIds, true),
            C::CATEGORY_SUB_VOICE => array_fill_keys($subVoiceIds, true),
            C::CATEGORY_PROJECT => array_fill_keys($projectIds, true),
            C::CATEGORY_USER => [$userId => true],
        ];
    }

    public function has(string $category, int $id): bool
    {
        return isset($this->index[$category][$id]);
    }
}

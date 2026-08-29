<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Policy for project member management authorization.
 *
 * Two independent rights lead here:
 *  - can_manage_project_members: manage members of every voice group in every
 *    project, also without being a member of that project. Anything narrower
 *    locks a freshly created project: it has no members yet, so a
 *    membership-scoped right could never add the first one.
 *  - can_assign_own_voice_group_to_project: manage only members that share
 *    one of the actor's own voice groups, and only in projects the actor
 *    participates in. The candidate list and every add/remove is filtered to
 *    that voice-group scope.
 */
class ProjectMemberPolicy
{
    private int $userId;
    private bool $canManageProjectMembers;
    private bool $canAssignOwnVoiceGroup;
    /** @var array<int> */
    private array $ownVoiceGroupIds;
    private ?array $accessibleProjectIdsCache = null;

    public function __construct()
    {
        // Auf Wahrheitswert prüfen, nicht strikt auf true: Middleware und Controller
        // lesen dieselben Session-Schlüssel überall nur truthy. Eine 1 aus einer
        // anderen Quelle ließe die Middleware passieren, während ein === true hier
        // still verweigert - die Abweisung erschiene dann als Fehler ohne Ursache.
        $this->userId = (int) ($_SESSION['user_id'] ?? 0);
        $this->canManageProjectMembers = (bool) ($_SESSION['can_manage_project_members'] ?? false);
        $this->canAssignOwnVoiceGroup = (bool) ($_SESSION['can_assign_own_voice_group_to_project'] ?? false);
        $this->ownVoiceGroupIds = array_map('intval', (array) ($_SESSION['voice_group_ids'] ?? []));
    }

    /**
     * Check if the user can view members of the specified project.
     *
     * Ohne eines der beiden Rechte ist ohnehin Schluss. Darüber hinaus laufen beide
     * über dieselbe Liste - welche Projekte sie umfasst, entscheidet
     * getAccessibleProjectIds(). Damit endet auch das breite Recht an einer
     * Projekt-Id, die es gar nicht gibt: sonst nimmt ProjectController::addMember()
     * die Zuordnung an und der Fremdschlüssel auf projects quittiert sie mit einem
     * HTTP 500 statt mit einer Abweisung.
     */
    public function canViewMembers(int $projectId): bool
    {
        if (!$this->canManageProjectMembers && !$this->canAssignOwnVoiceGroup) {
            return false;
        }

        return in_array($projectId, $this->getAccessibleProjectIds(), true);
    }

    /**
     * Check if the user can add a member to the specified project.
     */
    public function canAddMember(int $projectId): bool
    {
        return $this->canViewMembers($projectId);
    }

    /**
     * Check if the user can remove a member from the specified project.
     */
    public function canRemoveMember(int $projectId): bool
    {
        return $this->canViewMembers($projectId);
    }

    /**
     * Check if the user can view all active users as candidates.
     *
     * Only the broad can_manage_project_members right sees every candidate. A
     * holder of the voice-group-scoped right gets a candidate list filtered to
     * their own voice group instead (see ownVoiceGroupIds()).
     */
    public function canViewAllCandidates(): bool
    {
        return $this->canManageProjectMembers;
    }

    /**
     * The voice group ids the current user belongs to.
     *
     * @return array<int>
     */
    public function ownVoiceGroupIds(): array
    {
        return $this->ownVoiceGroupIds;
    }

    /**
     * Check whether the user may add or remove a specific member, identified by
     * the voice groups that member belongs to.
     *
     * @param array<int> $memberVoiceGroupIds
     */
    public function canManageMember(int $projectId, array $memberVoiceGroupIds): bool
    {
        if (!$this->canViewMembers($projectId)) {
            return false;
        }

        if ($this->canViewAllCandidates()) {
            return true;
        }

        // Voice-group-scoped holder: the member must share at least one voice
        // group with the actor. A member without any voice group is out of scope.
        $memberVoiceGroupIds = array_map('intval', $memberVoiceGroupIds);

        return array_intersect($memberVoiceGroupIds, $this->ownVoiceGroupIds) !== [];
    }

    /**
     * Get the list of project IDs the current user can manage members for.
     *
     * @return array<int> Array of project IDs
     */
    public function getAccessibleProjectIds(): array
    {
        if ($this->accessibleProjectIdsCache !== null) {
            return $this->accessibleProjectIdsCache;
        }

        // Das breite Recht sieht alle Projekte - auch die, in denen der Nutzer
        // selbst nicht Mitglied ist (etwa ein gerade angelegtes Projekt).
        if ($this->canManageProjectMembers) {
            $this->accessibleProjectIdsCache = array_map(
                'intval',
                Project::query()->pluck('id')->all()
            );
            return $this->accessibleProjectIdsCache;
        }

        // Das stimmgruppen-beschraenkte Recht bleibt auf die eigenen Projekte begrenzt.
        if ($this->canAssignOwnVoiceGroup && $this->userId > 0) {
            $user = User::find($this->userId);
            if ($user) {
                $this->accessibleProjectIdsCache = array_map(
                    'intval',
                    $user->projects()->pluck('projects.id')->all()
                );
                return $this->accessibleProjectIdsCache;
            }
        }

        // No access by default
        $this->accessibleProjectIdsCache = [];
        return $this->accessibleProjectIdsCache;
    }
}

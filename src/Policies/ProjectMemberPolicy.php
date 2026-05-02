<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Policy for project member management authorization.
 *
 * Determines whether a user can view, add, or remove project members,
 * scoped to projects where the user participates.
 */
class ProjectMemberPolicy
{
    private int $userId;
    private bool $isGlobalAdmin;
    private bool $canManageProjectMembers;
    private ?array $accessibleProjectIdsCache = null;

    public function __construct()
    {
        $this->userId = (int) ($_SESSION['user_id'] ?? 0);
        $this->isGlobalAdmin = ($_SESSION['can_manage_users'] ?? false) === true;
        $this->canManageProjectMembers = ($_SESSION['can_manage_project_members'] ?? false) === true;
    }

    /**
     * Check if the user can view members of the specified project.
     */
    public function canViewMembers(int $projectId): bool
    {
        if ($this->isGlobalAdmin) {
            return true;
        }

        if (!$this->canManageProjectMembers) {
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
     * Check if the user can view all active users as candidates for the specified project.
     *
     * Global admins and project-scoped managers can see all candidates.
     * Others are restricted (voice group scope would apply, but not relevant for flag holders).
     */
    public function canViewAllCandidates(int $projectId): bool
    {
        return $this->canViewMembers($projectId);
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

        // Global admins have access to all projects
        if ($this->isGlobalAdmin) {
            $this->accessibleProjectIdsCache = \App\Models\Project::query()
                ->pluck('id')
                ->toArray();
            return $this->accessibleProjectIdsCache;
        }

        // Users with project member management flag can access only their own projects
        if ($this->canManageProjectMembers && $this->userId > 0) {
            $user = User::find($this->userId);
            if ($user) {
                $this->accessibleProjectIdsCache = $user->projects()
                    ->pluck('projects.id')
                    ->toArray();
                return $this->accessibleProjectIdsCache;
            }
        }

        // No access by default
        $this->accessibleProjectIdsCache = [];
        return $this->accessibleProjectIdsCache;
    }
}

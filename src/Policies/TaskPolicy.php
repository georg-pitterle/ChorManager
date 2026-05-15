<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Project;

/**
 * Policy for task management authorization.
 *
 * Determines whether a user can create, view, update, or delete tasks
 * within a specific project, respecting project membership.
 */
class TaskPolicy
{
    private int $userId;
    private bool $isGlobalAdmin;
    private bool $canManageTasks;
    private array $projectMemberCache = [];

    public function __construct()
    {
        $this->userId = (int) ($_SESSION['user_id'] ?? 0);
        $this->isGlobalAdmin = ($_SESSION['can_manage_users'] ?? false) === true;
        $this->canManageTasks = ($_SESSION['can_manage_tasks'] ?? false) === true;
    }

    /**
     * Check if the user can manage tasks in a specific project.
     *
     * User must:
     * 1. Have can_manage_tasks permission, OR be a global admin
     * 2. Be a member of the project
     */
    public function canManageTasks(int $projectId): bool
    {
        // Global admins can manage all tasks
        if ($this->isGlobalAdmin) {
            return true;
        }

        // User must have explicit task management permission
        if (!$this->canManageTasks) {
            return false;
        }

        // User must be a member of the project (cached)
        return $this->isProjectMember($projectId);
    }

    /**
     * Check if a user is a member of a project (with caching).
     */
    private function isProjectMember(int $projectId): bool
    {
        // Return cached result if available
        if (isset($this->projectMemberCache[$projectId])) {
            return $this->projectMemberCache[$projectId];
        }

        if ($this->userId <= 0) {
            $this->projectMemberCache[$projectId] = false;
            return false;
        }

        $user = User::find($this->userId);
        if (!$user) {
            $this->projectMemberCache[$projectId] = false;
            return false;
        }

        $isMember = $user->projects()
            ->where('projects.id', $projectId)
            ->exists();

        // Cache the result
        $this->projectMemberCache[$projectId] = $isMember;

        return $isMember;
    }
}

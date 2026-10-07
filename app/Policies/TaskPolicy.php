<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Support\Access;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        if ($task->trashed()) {
            // 削除済みは社内（共有、または自分の非公開）のみ。復元判断のため。
            return $user->isInternal()
                && Access::canViewState($user, $task->visibility->value, $task->project_id, $task->created_by);
        }

        return Access::canViewState($user, $task->visibility->value, $task->project_id, $task->created_by);
    }

    public function create(User $user): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return $user->isContractor() && Access::contractorProjectIds($user, true) !== [];
    }

    public function update(User $user, Task $task): bool
    {
        if ($task->trashed() || ! $this->view($user, $task)) {
            return false;
        }

        if ($task->isPrivate()) {
            return $task->created_by === $user->id;
        }

        return Access::canEditProjectContent($user, $task->project_id);
    }

    public function delete(User $user, Task $task): bool
    {
        if ($task->trashed() || ! $user->isInternal() || ! $this->view($user, $task)) {
            return false;
        }

        return ! $task->isPrivate() || $task->created_by === $user->id;
    }

    /** 履歴からの復元（削除済みの復元を含む）。外注は不可。 */
    public function restore(User $user, Task $task): bool
    {
        if (! $user->isInternal() || ! $this->view($user, $task)) {
            return false;
        }

        return ! $task->isPrivate() || $task->created_by === $user->id;
    }
}

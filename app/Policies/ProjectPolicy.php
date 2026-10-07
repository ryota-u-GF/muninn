<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Access;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $user->is_active && Access::canViewProject($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->isInternal();
    }

    /** 名称変更・並び順・アーカイブ */
    public function update(User $user, Project $project): bool
    {
        return $user->isInternal();
    }
}

<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

class MemberDirectory
{
    /**
     * 外注に見せてよい利用者：自分＋閲覧できるタスクの担当者
     *
     * @return list<int>
     */
    public function visibleMemberIds(User $user): array
    {
        if ($user->isInternal()) {
            return User::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $taskIds = Access::scopeVisible(Task::query(), $user, 'tasks')->select('tasks.id');
        $ids = DB::table('task_assignees')->whereIn('task_id', $taskIds)->distinct()->pluck('user_id')
            ->map(fn ($id) => (int) $id)->all();
        $ids[] = $user->id;

        return array_values(array_unique($ids));
    }
}

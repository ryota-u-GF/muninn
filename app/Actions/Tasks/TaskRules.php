<?php

namespace App\Actions\Tasks;

use App\Enums\Visibility;
use App\Models\Project;
use App\Models\User;
use App\Support\Access;
use Illuminate\Validation\ValidationException;

/** タスク書き込み時の意味的な検証（FormRequest の形式検証の後で使う） */
class TaskRules
{
    public function assertProjectWritable(?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        $project = Project::query()->find($projectId);
        if ($project === null || $project->isArchived()) {
            throw ValidationException::withMessages(['project_id' => '案件が存在しないか、アーカイブ済みです。']);
        }
    }

    /**
     * 担当者の妥当性：有効・TV以外、非公開は作成者のみ、外注は案件の閲覧権限が必要。
     *
     * @param  list<int>  $assigneeIds
     */
    public function assertAssignees(array $assigneeIds, string $visibility, int $creatorId, ?int $projectId): void
    {
        if ($assigneeIds === []) {
            return;
        }

        if ($visibility === Visibility::Private->value && $assigneeIds !== [$creatorId]) {
            throw ValidationException::withMessages(['assignee_ids' => '非公開タスクの担当者は作成者本人のみです。']);
        }

        $users = User::query()->assignable()->whereIn('id', $assigneeIds)->get();
        if ($users->count() !== count($assigneeIds)) {
            throw ValidationException::withMessages(['assignee_ids' => '担当者に指定できない利用者が含まれています。']);
        }

        foreach ($users as $user) {
            if ($user->isContractor() && ! Access::canViewState($user, Visibility::Shared->value, $projectId, null)) {
                throw ValidationException::withMessages(['assignee_ids' => "{$user->name} さんはこの案件の権限がありません。"]);
            }
        }
    }

    /**
     * @param  list<int>|null  $ids
     * @return list<int>
     */
    public function normalizeIds(?array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids ?? [])));
        sort($ids);

        return $ids;
    }
}

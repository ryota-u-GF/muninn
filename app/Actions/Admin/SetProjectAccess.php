<?php

namespace App\Actions\Admin;

use App\Exceptions\RevisionConflictException;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\User;
use App\Services\RevisionRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 外注の案件別権限（view / edit / none=取消）。管理者のみ。監査記録を残す。 */
class SetProjectAccess
{
    public function __construct(private RevisionRecorder $revisions) {}

    public function handle(User $admin, int $userId, int $projectId, string $permission, string $expectedPermission): ?ProjectAccess
    {
        $target = User::query()->findOrFail($userId);
        if (! $target->isContractor()) {
            throw ValidationException::withMessages(['user_id' => '案件別権限は外注アカウントにのみ設定できます。']);
        }

        $project = Project::query()->findOrFail($projectId);
        if ($permission !== 'none' && $project->isArchived()) {
            throw ValidationException::withMessages(['project_id' => 'アーカイブ済みの案件には権限を付与できません。']);
        }

        return DB::transaction(function () use ($admin, $target, $project, $permission, $expectedPermission) {
            // 対象ユーザー行をロックして同時変更を直列化する
            User::query()->whereKey($target->id)->lockForUpdate()->first();
            $existing = ProjectAccess::query()
                ->where('user_id', $target->id)->where('project_id', $project->id)
                ->lockForUpdate()->first();

            $current = $existing?->permission->value ?? 'none';
            if ($current !== $expectedPermission) {
                throw new RevisionConflictException(null, '他の管理者が先に権限を変更しました。画面を更新してください。');
            }
            if ($current === $permission) {
                return $existing;
            }

            $result = null;
            if ($permission === 'none') {
                $existing?->delete();
            } elseif ($existing !== null) {
                $existing->update(['permission' => $permission, 'granted_by' => $admin->id]);
                $result = $existing;
            } else {
                $result = ProjectAccess::create([
                    'user_id' => $target->id,
                    'project_id' => $project->id,
                    'permission' => $permission,
                    'granted_by' => $admin->id,
                ]);
            }

            $this->revisions->audit(
                'project_access',
                $target->id,
                $permission === 'none' ? 'revoke' : ($current === 'none' ? 'grant' : 'change'),
                $admin,
                ['project_id' => $project->id, 'project_name' => $project->name, 'permission' => $current],
                ['project_id' => $project->id, 'project_name' => $project->name, 'permission' => $permission],
                $project->id,
            );

            return $result;
        });
    }
}

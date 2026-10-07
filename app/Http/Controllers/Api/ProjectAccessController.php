<?php

namespace App\Http\Controllers\Api;

use App\Actions\Admin\SetProjectAccess;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetProjectAccessRequest;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** 管理者用：外注の案件別権限 */
class ProjectAccessController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'contractors' => User::query()->where('role', Role::Contractor->value)->orderBy('id')
                ->get(['id', 'name', 'email', 'is_active']),
            'projects' => Project::query()->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'archived_at'])
                ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'archived' => $p->archived_at !== null]),
            'grants' => ProjectAccess::query()->with('project:id,name')->get()
                ->map(fn (ProjectAccess $a) => [
                    'user_id' => $a->user_id,
                    'project_id' => $a->project_id,
                    'permission' => $a->permission->value,
                    'updated_at' => $a->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    public function update(SetProjectAccessRequest $request, SetProjectAccess $action): JsonResponse
    {
        $data = $request->validated();
        $grant = $action->handle($request->user(), (int) $data['user_id'], (int) $data['project_id'], $data['permission'], $data['expected_permission']);

        return response()->json([
            'user_id' => (int) $data['user_id'],
            'project_id' => (int) $data['project_id'],
            'permission' => $grant?->permission->value ?? 'none',
        ]);
    }
}

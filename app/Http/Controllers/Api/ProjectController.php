<?php

namespace App\Http\Controllers\Api;

use App\Actions\Projects\ProjectWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Project::query()->orderBy('sort_order')->orderBy('id');

        if ($user->isInternal()) {
            if (! $request->boolean('include_archived')) {
                $query->whereNull('archived_at');
            }
        } else {
            // 外注：付与された有効案件のみ（アーカイブは含まない）
            $query->whereIn('id', Access::contractorProjectIds($user));
        }

        return ProjectResource::collection($query->get());
    }

    public function store(StoreProjectRequest $request, ProjectWriter $writer): ProjectResource
    {
        Gate::authorize('create', Project::class);

        return new ProjectResource($writer->create($request->user(), $request->validated('name')));
    }

    public function update(UpdateProjectRequest $request, int $project, ProjectWriter $writer): ProjectResource
    {
        $model = Project::query()->find($project);
        abort_if($model === null || ! Gate::allows('view', $model), 404);
        Gate::authorize('update', $model);

        $data = collect($request->validated())->except('expected_revision')->all();

        return new ProjectResource($writer->update($request->user(), $project, $data, (int) $request->validated('expected_revision')));
    }
}

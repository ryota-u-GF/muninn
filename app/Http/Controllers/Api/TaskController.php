<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\TaskStatus;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpectedRevisionRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /**
     * 一覧・検索・件数はすべて閲覧スコープ内で計算する。
     * パラメータ：q, project_id（数値 / none）, assignee_id（数値 / me / none）, status, mine=1,
     * include_old_done=1, include_archived=1
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'project_id' => ['nullable', 'regex:/^(\d+|none)$/'],
            'assignee_id' => ['nullable', 'regex:/^(\d+|me|none)$/'],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'mine' => ['nullable', 'boolean'],
            'include_old_done' => ['nullable', 'boolean'],
            'include_archived' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();

        $query = Access::scopeVisible(Task::query(), $user, 'tasks')->with('assignees');

        if (! $request->boolean('include_archived')) {
            $query->where(fn ($q) => $q->whereNull('project_id')
                ->orWhereHas('project', fn ($p) => $p->whereNull('archived_at')));
        }

        if ($term = $request->string('q')->trim()->value()) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like));
        }

        $projectId = $request->input('project_id');
        if ($projectId === 'none') {
            $query->whereNull('project_id');
        } elseif ($projectId !== null) {
            $query->where('project_id', (int) $projectId);
        }

        $assignee = $request->input('assignee_id');
        if ($assignee === 'none') {
            $query->whereDoesntHave('assignees');
        } elseif ($assignee !== null) {
            $id = $assignee === 'me' ? $user->id : (int) $assignee;
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', $id));
        }

        if ($request->boolean('mine')) {
            $query->where(fn ($q) => $q
                ->whereHas('assignees', fn ($a) => $a->where('users.id', $user->id))
                ->orWhere(fn ($p) => $p->where('visibility', Visibility::Private->value)->where('created_by', $user->id)));
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if (! $request->boolean('include_old_done')) {
            $threshold = now()->subDays((int) config('gf.done_hidden_after_days'));
            $query->where(fn ($q) => $q->where('status', '!=', TaskStatus::Done->value)
                ->orWhereNull('completed_at')
                ->orWhere('completed_at', '>=', $threshold));
        }

        $tasks = $query->orderBy('sort_order')->orderBy('id')->limit(2000)->get();

        return TaskResource::collection($tasks);
    }

    public function show(Request $request, int $task): TaskResource
    {
        return new TaskResource($this->findVisible($request, $task));
    }

    public function store(StoreTaskRequest $request, CreateTask $action): TaskResource
    {
        Gate::authorize('create', Task::class);

        return new TaskResource($action->handle($request->user(), $request->validated())->load('assignees'));
    }

    public function update(UpdateTaskRequest $request, int $task, UpdateTask $action): TaskResource
    {
        $this->findVisible($request, $task);

        return new TaskResource($action->handle($request->user(), $task, $request->changes(), (int) $request->validated('expected_revision')));
    }

    public function destroy(ExpectedRevisionRequest $request, int $task, DeleteTask $action): TaskResource
    {
        $this->findVisible($request, $task);

        return new TaskResource($action->handle($request->user(), $task, (int) $request->validated('expected_revision')));
    }

    /** 閲覧できないものは存在を秘匿して 404 */
    private function findVisible(Request $request, int $id): Task
    {
        $task = Task::withTrashed()->with('assignees')->find($id);
        abort_if($task === null || ! Gate::allows('view', $task), 404);

        return $task;
    }
}

<?php

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\Revision;
use App\Models\Task;
use App\Models\User;
use App\Services\RevisionRecorder;
use App\Services\VersionedWriter;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 過去の版の内容で「新しい版」を作る。古い履歴は上書きしない。
 * 削除済みタスクも、この操作で復元（削除解除）される。
 */
class RestoreTaskVersion
{
    public function __construct(
        private TaskRules $rules,
        private RevisionRecorder $revisions,
        private VersionedWriter $writer,
    ) {}

    public function handle(User $actor, int $taskId, int $version, int $expectedRevision): Task
    {
        return DB::transaction(function () use ($actor, $taskId, $version, $expectedRevision) {
            /** @var Task $task */
            $task = $this->writer->lock(Task::class, $taskId, $expectedRevision);
            $task->load('assignees');

            abort_unless(Gate::forUser($actor)->allows('view', $task), 404);
            abort_unless(Gate::forUser($actor)->allows('restore', $task), 403, 'このタスクを復元する権限がありません。');

            $revision = Revision::query()
                ->where('entity_type', 'task')->where('entity_id', $taskId)->where('version', $version)
                ->first();
            abort_if($revision === null || $revision->after === null, 404);

            $snap = $revision->after;
            abort_unless(Access::canViewState($actor, $snap['visibility'] ?? null, $snap['project_id'] ?? null, $task->created_by), 404);

            $before = $task->snapshot();
            if (($snap['visibility'] ?? null) !== $before['visibility']) {
                abort_unless($task->created_by === $actor->id, 403, '公開区分は作成者だけが変更できます。');
            }

            $projectId = $snap['project_id'] ?? null;
            $this->rules->assertProjectWritable($projectId);
            $assigneeIds = $this->rules->normalizeIds($snap['assignee_ids'] ?? []);
            $this->rules->assertAssignees($assigneeIds, $snap['visibility'], $task->created_by, $projectId);

            $status = $snap['status'];
            $completedAt = $status === TaskStatus::Done->value
                ? ($task->status === TaskStatus::Done ? $task->completed_at : now())
                : null;

            $this->writer->update($task, $expectedRevision, [
                'title' => $snap['title'],
                'description' => $snap['description'],
                'status' => $status,
                'visibility' => $snap['visibility'],
                'project_id' => $projectId,
                'start_date' => $snap['start_date'],
                'due_date' => $snap['due_date'],
                'sort_order' => $snap['sort_order'],
                'completed_at' => $completedAt,
                'deleted_at' => null,
            ]);
            $task->assignees()->sync($assigneeIds);

            /** @var Task $fresh */
            $fresh = Task::withTrashed()->with('assignees')->findOrFail($taskId);
            $this->revisions->forTask($fresh, 'restore', $before, $actor);

            return $fresh;
        });
    }
}

<?php

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Services\RevisionRecorder;
use App\Services\VersionedWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** ソフト削除。履歴は残る。 */
class DeleteTask
{
    public function __construct(private RevisionRecorder $revisions, private VersionedWriter $writer) {}

    public function handle(User $actor, int $taskId, int $expectedRevision): Task
    {
        return DB::transaction(function () use ($actor, $taskId, $expectedRevision) {
            /** @var Task $task */
            $task = $this->writer->lock(Task::class, $taskId, $expectedRevision);
            $task->load('assignees');

            abort_unless(Gate::forUser($actor)->allows('view', $task), 404);
            abort_unless(Gate::forUser($actor)->allows('delete', $task), 403, 'このタスクを削除する権限がありません。');

            $before = $task->snapshot();
            $this->writer->update($task, $expectedRevision, ['deleted_at' => now()]);

            /** @var Task $fresh */
            $fresh = Task::withTrashed()->with('assignees')->findOrFail($taskId);
            $this->revisions->forTask($fresh, 'delete', $before, $actor);

            return $fresh;
        });
    }
}

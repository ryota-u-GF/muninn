<?php

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\RevisionRecorder;
use App\Services\VersionedWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateTask
{
    /** 外注が変更できない項目 */
    private const CONTRACTOR_LOCKED = ['project_id', 'visibility', 'assignee_ids', 'source_type', 'source_url'];

    public function __construct(
        private TaskRules $rules,
        private RevisionRecorder $revisions,
        private VersionedWriter $writer,
    ) {}

    /** @param array<string, mixed> $data 検証済み入力（送られた項目のみ） */
    public function handle(User $actor, int $taskId, array $data, int $expectedRevision): Task
    {
        return DB::transaction(function () use ($actor, $taskId, $data, $expectedRevision) {
            /** @var Task $task */
            $task = $this->writer->lock(Task::class, $taskId, $expectedRevision);
            $task->load('assignees');

            abort_unless(Gate::forUser($actor)->allows('view', $task), 404);
            abort_unless(Gate::forUser($actor)->allows('update', $task), 403, 'このタスクを編集する権限がありません。');

            $before = $task->snapshot();
            $next = $before;
            foreach (['title', 'description', 'status', 'visibility', 'project_id', 'start_date', 'due_date', 'sort_order', 'source_type', 'source_url'] as $key) {
                if (array_key_exists($key, $data)) {
                    $next[$key] = $data[$key];
                }
            }
            if (array_key_exists('project_id', $data)) {
                $next['project_id'] = $data['project_id'] === null ? null : (int) $data['project_id'];
            }
            if (array_key_exists('assignee_ids', $data)) {
                $next['assignee_ids'] = $this->rules->normalizeIds($data['assignee_ids']);
            }

            $changed = array_keys(array_filter($next, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));

            if ($actor->isContractor()) {
                $locked = array_values(array_intersect($changed, self::CONTRACTOR_LOCKED));
                abort_if($locked !== [], 403, '外注はこの項目を変更できません：'.implode(', ', $locked));
            }

            if (in_array('visibility', $changed, true)) {
                abort_unless($task->created_by === $actor->id, 403, '公開区分は作成者だけが変更できます。');
            }

            if (in_array('project_id', $changed, true)) {
                $this->rules->assertProjectWritable($next['project_id']);
            }

            if ($next['start_date'] !== null && $next['due_date'] !== null && $next['start_date'] > $next['due_date']) {
                throw ValidationException::withMessages(['due_date' => '期限は開始日以降にしてください。']);
            }

            if (array_intersect($changed, ['assignee_ids', 'visibility', 'project_id']) !== []) {
                $this->rules->assertAssignees($next['assignee_ids'], $next['visibility'], $task->created_by, $next['project_id']);
            }

            $attributes = [];
            foreach (['title', 'description', 'status', 'visibility', 'project_id', 'start_date', 'due_date', 'sort_order', 'source_type', 'source_url'] as $key) {
                if (in_array($key, $changed, true)) {
                    $attributes[$key] = $next[$key];
                }
            }
            if (in_array('status', $changed, true)) {
                $attributes['completed_at'] = $next['status'] === TaskStatus::Done->value ? now() : null;
            }

            $this->writer->update($task, $expectedRevision, $attributes);

            if (in_array('assignee_ids', $changed, true)) {
                $task->assignees()->sync($next['assignee_ids']);
            }

            /** @var Task $fresh */
            $fresh = Task::withTrashed()->with('assignees')->findOrFail($taskId);
            $this->revisions->forTask($fresh, 'update', $before, $actor);

            return $fresh;
        });
    }
}

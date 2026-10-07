<?php

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Enums\Visibility;
use App\Models\Task;
use App\Models\User;
use App\Services\RevisionRecorder;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

class CreateTask
{
    public function __construct(private TaskRules $rules, private RevisionRecorder $revisions) {}

    /**
     * @param  array<string, mixed>  $data  検証済み入力
     * @param  string  $createdVia  作成経路はサーバーが決める（manual / ai_import）
     */
    public function handle(User $actor, array $data, string $createdVia = 'manual'): Task
    {
        $visibility = $data['visibility'] ?? Visibility::Shared->value;
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $assigneeIds = $this->rules->normalizeIds($data['assignee_ids'] ?? []);

        if ($actor->isContractor()) {
            // 外注：共有のみ・編集権限のある案件のみ・情報源指定不可・担当者は自分のみ
            abort_if($visibility !== Visibility::Shared->value, 403, '外注は非公開タスクを作成できません。');
            abort_unless(Access::canEditProjectContent($actor, $projectId), 403, 'この案件にタスクを作成する権限がありません。');
            abort_if(! empty($data['source_type']) || ! empty($data['source_url']), 403, '情報源は指定できません。');
            abort_unless($assigneeIds === [] || $assigneeIds === [$actor->id], 403, '担当者は指定できません。');
        }

        $this->rules->assertProjectWritable($projectId);
        $this->rules->assertAssignees($assigneeIds, $visibility, $actor->id, $projectId);

        $status = $data['status'] ?? TaskStatus::Todo->value;

        return DB::transaction(function () use ($actor, $data, $visibility, $projectId, $assigneeIds, $status, $createdVia) {
            $sortOrder = $data['sort_order'] ?? ((float) Task::query()
                ->where('project_id', $projectId)
                ->where('status', $status)
                ->max('sort_order') + 1);

            $task = Task::create([
                'project_id' => $projectId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $status,
                'visibility' => $visibility,
                'created_by' => $actor->id,
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'sort_order' => $sortOrder,
                'source_type' => $data['source_type'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'created_via' => $createdVia,
                'completed_at' => $status === TaskStatus::Done->value ? now() : null,
            ]);
            $task->assignees()->sync($assigneeIds);
            $task->refresh();

            $this->revisions->forTask($task, 'create', null, $actor);

            return $task;
        });
    }
}

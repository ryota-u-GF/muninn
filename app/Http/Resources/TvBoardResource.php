<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * TV 返却項目の許可リスト。メール・本文・情報源・非公開・AI 候補・カレンダー詳細は含めない。
 *
 * @property array{tasks: Collection<int, Task>, today: string} $resource
 */
class TvBoardResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tasks = $this->resource['tasks'];

        $users = $tasks->flatMap(fn (Task $t) => $t->assignees)->unique('id')->sortBy('id')
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values();
        $projects = $tasks->pluck('project')->filter()->unique('id')->sortBy('id')
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values();

        return [
            'generated_at' => Clock::now()->toIso8601String(),
            'today' => $this->resource['today'],
            'users' => $users,
            'projects' => $projects,
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status->value,
                'project_id' => $t->project_id,
                'due_date' => $t->due_date?->format('Y-m-d'),
                'assignee_ids' => $t->assignees->pluck('id')->sort()->values()->all(),
            ])->values(),
        ];
    }
}

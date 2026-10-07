<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Task */
class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $internal = $viewer->isInternal();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'sort_order' => $this->sort_order,
            'assignee_ids' => $this->assigneeIds(),
            'revision' => $this->revision,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted' => $this->deleted_at !== null,
            'is_mine' => $this->created_by === $viewer->id,
            // 情報源・内部作成経路・作成者は社内のみ
            'created_by' => $this->when($internal, $this->created_by),
            'source_type' => $this->when($internal, $this->source_type),
            'source_url' => $this->when($internal, $this->source_url),
            'created_via' => $this->when($internal, $this->created_via),
            'can' => [
                'update' => Gate::forUser($viewer)->allows('update', $this->resource),
                'delete' => Gate::forUser($viewer)->allows('delete', $this->resource),
                'change_visibility' => $this->created_by === $viewer->id && $internal,
                'change_project' => $internal,
                'change_assignees' => $internal,
            ],
        ];
    }
}

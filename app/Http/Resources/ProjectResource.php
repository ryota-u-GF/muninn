<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'archived' => $this->archived_at !== null,
            'revision' => $this->revision,
            // 自分がこの案件で書き込めるか（外注は edit 付与時のみ）
            'can_edit_content' => Access::canEditProjectContent($viewer, $this->id),
            'can_manage' => $viewer->isInternal(),
        ];
    }
}

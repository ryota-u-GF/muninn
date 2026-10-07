<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    /** 一覧では本文の抜粋のみ返す */
    public bool $withBody = true;

    public function summary(): static
    {
        $this->withBody = false;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'kind' => $this->kind->value,
            'visibility' => $this->visibility->value,
            'title' => $this->title,
            'body' => $this->when($this->withBody, $this->body ?? ''),
            'excerpt' => mb_substr(preg_replace('/\s+/u', ' ', (string) $this->body) ?? '', 0, 120),
            'meeting_date' => $this->meeting_date?->format('Y-m-d'),
            'participants' => $this->participants,
            'revision' => $this->revision,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted' => $this->deleted_at !== null,
            'is_mine' => $this->created_by === $viewer->id,
            'can' => [
                'update' => Gate::forUser($viewer)->allows('update', $this->resource),
                'delete' => Gate::forUser($viewer)->allows('delete', $this->resource),
                'change_visibility' => $this->created_by === $viewer->id && $viewer->isInternal(),
                'change_project' => $viewer->isInternal(),
            ],
        ];
    }
}

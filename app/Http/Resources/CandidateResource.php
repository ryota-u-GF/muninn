<?php

namespace App\Http\Resources;

use App\Models\ImportCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ImportCandidate */
class CandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'status' => $this->status->value,
            'payload' => $this->payload,
            'warnings' => $this->warnings ?? [],
            'revision' => $this->revision,
            'task_id' => $this->task_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

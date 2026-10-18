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
            // 取り込み元のメール（Gmail 連携のとき）。本文は保持しない。
            'mail' => $this->mailMessage === null ? null : [
                'subject' => $this->mailMessage->subject,
                'sender' => $this->mailMessage->sender,
                'received_at' => $this->mailMessage->received_at?->toIso8601String(),
                'kind' => $this->mailMessage->kind,
                'url' => $this->mailMessage->openUrl(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

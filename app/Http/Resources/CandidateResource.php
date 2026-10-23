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
            // 画面の振り分け：minutes（議事録）／mail（メール・その他）
            'kind' => $this->kind(),
            'status' => $this->status->value,
            'payload' => $this->payload,
            'warnings' => $this->warnings ?? [],
            'revision' => $this->revision,
            'task_id' => $this->task_id,
            // 旧 Gmail/Meet 連携で作った候補の取り込み元。新しい候補には付かない（外部から届く候補は source_url を使う）。本文は保持しない。
            'mail' => $this->mailMessage === null ? null : [
                'subject' => $this->mailMessage->subject,
                'sender' => $this->mailMessage->sender,
                'received_at' => $this->mailMessage->received_at?->toIso8601String(),
                'kind' => $this->mailMessage->kind,
                'url' => $this->mailMessage->openUrl(),
            ],
            // 外部システムから受け取った候補の、元のメール・議事録へのリンク
            'source_url' => $this->source_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

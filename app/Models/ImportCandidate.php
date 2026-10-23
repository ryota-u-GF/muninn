<?php

namespace App\Models;

use App\Enums\CandidateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $source
 * @property array<string, mixed>|null $payload
 * @property list<string>|null $warnings
 * @property CandidateStatus $status
 * @property int $revision
 * @property int|null $task_id
 * @property string $fingerprint
 * @property int|null $mail_message_id
 * @property string|null $external_id 外部システムから受け取った候補の、先方での ID
 * @property string|null $source_url 外部システムから受け取った候補の、元のメール・議事録へのリンク
 * @property-read MailMessage|null $mailMessage
 */
class ImportCandidate extends Model
{
    protected $guarded = ['id', 'revision'];

    /** 議事録から見つけた候補なら 'minutes'、それ以外（メール・手動など）は 'mail' */
    public function kind(): string
    {
        $minutes = ($this->payload['source_type'] ?? null) === 'minutes' || $this->mailMessage?->kind === 'minutes';

        return $minutes ? 'minutes' : 'mail';
    }

    /** @return BelongsTo<MailMessage, $this> */
    public function mailMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'warnings' => 'array',
            'status' => CandidateStatus::class,
            'revision' => 'integer',
        ];
    }
}

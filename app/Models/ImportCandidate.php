<?php

namespace App\Models;

use App\Enums\CandidateStatus;
use Illuminate\Database\Eloquent\Model;

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
 */
class ImportCandidate extends Model
{
    protected $guarded = ['id', 'revision'];

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

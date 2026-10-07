<?php

namespace App\Models;

use App\Enums\DocumentKind;
use App\Enums\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $project_id
 * @property Visibility $visibility
 * @property DocumentKind $kind
 * @property string $title
 * @property string|null $body
 * @property CarbonImmutable|null $meeting_date
 * @property string|null $participants
 * @property int $created_by
 * @property int $revision
 * @property CarbonImmutable|null $deleted_at
 */
class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'revision'];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'kind' => DocumentKind::class,
            'meeting_date' => 'date:Y-m-d',
            'revision' => 'integer',
        ];
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Visibility::Private;
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'kind' => $this->kind->value,
            'visibility' => $this->visibility->value,
            'project_id' => $this->project_id,
            'meeting_date' => $this->meeting_date?->format('Y-m-d'),
            'participants' => $this->participants,
            'deleted' => $this->deleted_at !== null,
        ];
    }
}

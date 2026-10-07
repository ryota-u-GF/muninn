<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $project_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property Visibility $visibility
 * @property int $created_by
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property float $sort_order
 * @property string|null $source_type
 * @property string|null $source_url
 * @property string $created_via
 * @property int $revision
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $deleted_at
 */
class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'revision'];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'visibility' => Visibility::class,
            'start_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'sort_order' => 'float',
            'revision' => 'integer',
            'completed_at' => 'datetime',
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

    /** @return BelongsToMany<User, $this> */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees');
    }

    /** @return list<int> */
    public function assigneeIds(): array
    {
        return $this->assignees->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /**
     * 履歴に保存する版の内容
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'project_id' => $this->project_id,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'sort_order' => $this->sort_order,
            'assignee_ids' => $this->assigneeIds(),
            'source_type' => $this->source_type,
            'source_url' => $this->source_url,
            'created_via' => $this->created_via,
            'deleted' => $this->deleted_at !== null,
        ];
    }
}

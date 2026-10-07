<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property CarbonImmutable|null $archived_at
 * @property int|null $created_by
 * @property int $revision
 */
class Project extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sort_order', 'archived_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'sort_order' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<Project> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return HasMany<ProjectAccess, $this> */
    public function access(): HasMany
    {
        return $this->hasMany(ProjectAccess::class);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'archived' => $this->archived_at !== null,
        ];
    }
}

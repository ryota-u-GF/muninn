<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Role $role
 * @property bool $is_active
 * @property string|null $google_id
 * @property int $session_version
 * @property int $revision
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'role', 'is_active'];

    protected $hidden = ['remember_token', 'google_id', 'session_version'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'session_version' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** 管理者または社員 */
    public function isInternal(): bool
    {
        return $this->role->isInternal();
    }

    public function isContractor(): bool
    {
        return $this->role === Role::Contractor;
    }

    public function isTv(): bool
    {
        return $this->role === Role::Tv;
    }

    /** パスワードは保持しない（Google／開発デモのみ） */
    public function getAuthPassword(): string
    {
        return '';
    }

    /** @return HasMany<ProjectAccess, $this> */
    public function projectAccess(): HasMany
    {
        return $this->hasMany(ProjectAccess::class);
    }

    /** @return BelongsToMany<Task, $this> */
    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_assignees');
    }

    /**
     * タスクの担当者に指定できる利用者（有効な管理者・社員・外注。TVは不可）
     *
     * @param  Builder<User>  $query
     */
    public function scopeAssignable(Builder $query): void
    {
        $query->where('is_active', true)->where('role', '!=', Role::Tv->value);
    }
}

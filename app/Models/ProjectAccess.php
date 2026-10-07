<?php

namespace App\Models;

use App\Enums\AccessPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property AccessPermission $permission
 * @property int|null $granted_by
 */
class ProjectAccess extends Model
{
    protected $table = 'project_access';

    protected $fillable = ['user_id', 'project_id', 'permission', 'granted_by'];

    protected function casts(): array
    {
        return ['permission' => AccessPermission::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 変更履歴・監査記録。アプリからは追記のみ可能。
 * （DB 管理者による直接変更まで防ぐものではない）
 *
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property int $version
 * @property int|null $actor_id
 * @property string $action
 * @property int|null $project_id
 * @property string|null $visibility
 * @property int|null $owner_id
 * @property int|null $before_project_id
 * @property string|null $before_visibility
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property CarbonImmutable $created_at
 */
class Revision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('履歴は更新できません。'));
        static::deleting(fn () => throw new LogicException('履歴は削除できません。'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

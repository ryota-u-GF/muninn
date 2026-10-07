<?php

namespace App\Services;

use App\Enums\Visibility;
use App\Models\Revision;
use App\Models\User;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 履歴の閲覧範囲。
 * ① 対象（タスク／文書）を現在閲覧できること、かつ ② その版（after）の案件・公開区分を閲覧できること。
 * 変更前（before）は ResourceRedactor 側で個別に伏せる。
 */
class HistoryQuery
{
    /** @return Builder<Revision> */
    public function visibleTo(User $user, ?string $entityType = null, ?int $entityId = null): Builder
    {
        $query = Revision::query();

        if (! $user->is_active || $user->isTv()) {
            return $query->whereRaw('1 = 0');
        }

        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }
        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where(function (Builder $q) use ($user) {
                $q->whereIn('entity_type', ['task', 'document'])
                    ->where(fn (Builder $q) => $this->stateVisible($q, $user))
                    ->where(function (Builder $q) use ($user) {
                        $q->where(fn (Builder $q) => $this->currentVisible($q, $user, 'task', 'tasks'))
                            ->orWhere(fn (Builder $q) => $this->currentVisible($q, $user, 'document', 'documents'));
                    });
            });

            if ($user->isAdmin()) {
                $q->orWhereIn('entity_type', ['project', 'project_access', 'user']);
            } elseif ($user->isInternal()) {
                $q->orWhere('entity_type', 'project');
            }
        });
    }

    /**
     * この版（after）を閲覧できるか
     *
     * @param  Builder<Revision>  $q
     */
    private function stateVisible(Builder $q, User $user): void
    {
        if ($user->isInternal()) {
            $q->where('revisions.visibility', Visibility::Shared->value)
                ->orWhere(fn (Builder $q) => $q->where('revisions.visibility', Visibility::Private->value)->where('revisions.owner_id', $user->id));

            return;
        }

        $ids = Access::contractorProjectIds($user);
        $q->where('revisions.visibility', Visibility::Shared->value)->whereIn('revisions.project_id', $ids === [] ? [0] : $ids);
    }

    /**
     * 対象を現在閲覧できるか（外注は削除済み不可）
     *
     * @param  Builder<Revision>  $q
     */
    private function currentVisible(Builder $q, User $user, string $type, string $table): void
    {
        $q->where('revisions.entity_type', $type)->whereExists(function ($sub) use ($user, $table) {
            $sub->select(DB::raw(1))->from($table)->whereColumn("{$table}.id", 'revisions.entity_id');
            if (! $user->isInternal()) {
                $sub->whereNull("{$table}.deleted_at");
            }
            Access::scopeVisible($sub, $user, $table);
        });
    }
}

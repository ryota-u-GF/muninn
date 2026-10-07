<?php

namespace App\Support;

use App\Enums\AccessPermission;
use App\Enums\Visibility;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * 公開範囲・案件権限の判定を一か所に集約する。
 * 一覧・検索・件数・履歴・ID直指定のすべてがここを通る。
 */
final class Access
{
    /**
     * 外注が閲覧（または編集）できる有効案件 ID。アーカイブ案件は含まない。
     * 同一リクエスト内でのみキャッシュする（権限取消は次のリクエストから即時反映）。
     *
     * @return list<int>
     */
    public static function contractorProjectIds(User $user, bool $editOnly = false): array
    {
        if (! $user->isContractor() || ! $user->is_active) {
            return [];
        }

        $key = 'gf.access.'.$user->id.($editOnly ? '.edit' : '.view');
        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            $ids = ProjectAccess::query()
                ->where('user_id', $user->id)
                ->when($editOnly, fn ($q) => $q->where('permission', AccessPermission::Edit->value))
                ->whereHas('project', fn ($q) => $q->whereNull('archived_at'))
                ->pluck('project_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $attributes->set($key, $ids);
        }

        /** @var list<int> */
        return $attributes->get($key);
    }

    public static function forgetCache(User $user): void
    {
        request()->attributes->remove('gf.access.'.$user->id.'.view');
        request()->attributes->remove('gf.access.'.$user->id.'.edit');
    }

    /**
     * ある「状態」（現在または過去の版）を閲覧できるか。
     * 非公開は作成者本人だけ（管理者も不可）。外注は付与された有効案件の共有のみ。
     */
    public static function canViewState(User $user, ?string $visibility, ?int $projectId, ?int $ownerId): bool
    {
        if (! $user->is_active || $user->isTv()) {
            return false;
        }

        if ($visibility === Visibility::Private->value) {
            return $user->isInternal() && $ownerId !== null && $ownerId === $user->id;
        }

        if ($visibility !== Visibility::Shared->value) {
            return false;
        }

        if ($user->isInternal()) {
            return true;
        }

        return $projectId !== null && in_array($projectId, self::contractorProjectIds($user), true);
    }

    public static function canEditProjectContent(User $user, ?int $projectId): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return $projectId !== null && in_array($projectId, self::contractorProjectIds($user, true), true);
    }

    public static function canViewProject(User $user, Project $project): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return in_array($project->id, self::contractorProjectIds($user), true);
    }

    /**
     * タスク・文書テーブル共通の閲覧スコープ（visibility / project_id / created_by 列を持つもの）。
     * Eloquent / Query Builder のどちらにも適用できる（履歴の whereExists でも使う）。渡したクエリを直接絞り込む。
     */
    public static function scopeVisible(Builder $query, User $user, string $table): void
    {
        if (! $user->is_active || $user->isTv()) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->isInternal()) {
            $query->where(function ($q) use ($user, $table) {
                $q->where("{$table}.visibility", Visibility::Shared->value)
                    ->orWhere(function ($q) use ($user, $table) {
                        $q->where("{$table}.visibility", Visibility::Private->value)
                            ->where("{$table}.created_by", $user->id);
                    });
            });

            return;
        }

        $projectIds = self::contractorProjectIds($user);

        if ($projectIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where("{$table}.visibility", Visibility::Shared->value)
            ->whereIn("{$table}.project_id", $projectIds);
    }
}

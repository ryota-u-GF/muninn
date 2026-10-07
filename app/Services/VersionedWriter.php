<?php

namespace App\Services;

use App\Exceptions\RevisionConflictException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * expected_revision を条件にした更新。呼び出し側のトランザクション内で使う。
 */
class VersionedWriter
{
    /**
     * 行をロックして現在の版を確認する。不一致なら 409。
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public function lock(string $modelClass, int $id, int $expectedRevision): Model
    {
        $query = $modelClass::query();
        if (method_exists($modelClass, 'bootSoftDeletes')) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        /** @var TModel $model */
        $model = $query->whereKey($id)->lockForUpdate()->firstOrFail();

        if ((int) $model->getAttribute('revision') !== $expectedRevision) {
            throw new RevisionConflictException((int) $model->getAttribute('revision'));
        }

        return $model;
    }

    /**
     * 条件付き UPDATE（revision = expected のときだけ更新し revision を +1）。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Model $model, int $expectedRevision, array $attributes): void
    {
        $affected = DB::table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->where('revision', $expectedRevision)
            ->update($attributes + [
                'revision' => $expectedRevision + 1,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new RevisionConflictException;
        }
    }
}

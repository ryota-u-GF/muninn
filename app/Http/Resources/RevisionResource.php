<?php

namespace App\Http\Resources;

use App\Models\Revision;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 履歴 1 件。変更前の版を閲覧できない場合は before を伏せる。
 * 外注には情報源・内部作成経路を返さず、範囲外の操作者名も伏せる。
 *
 * @mixin Revision
 */
class RevisionResource extends JsonResource
{
    public bool $withSnapshots = false;

    /** @var list<int>|null 外注に名前を見せてよい利用者 ID */
    public static ?array $visibleMemberIds = null;

    public function withSnapshots(): static
    {
        $this->withSnapshots = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $after = $this->after;
        $before = $this->before;

        if (in_array($this->entity_type, ['task', 'document'], true) && $before !== null
            && ! Access::canViewState($viewer, $this->before_visibility, $this->before_project_id, $this->owner_id)) {
            $before = null;
            $beforeHidden = true;
        }

        if (! $viewer->isInternal()) {
            $after = $after !== null ? $this->strip($after) : null;
            $before = $before !== null ? $this->strip($before) : null;
        }

        $actorName = $this->actor?->name;
        if (! $viewer->isInternal() && $this->actor_id !== $viewer->id
            && ! in_array($this->actor_id, self::$visibleMemberIds ?? [], true)) {
            $actorName = '社内メンバー';
        }

        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'version' => $this->version,
            'action' => $this->action,
            'actor' => ['id' => $viewer->isInternal() ? $this->actor_id : null, 'name' => $actorName],
            'created_at' => $this->created_at->toIso8601String(),
            'title' => $after['title'] ?? $after['name'] ?? $after['project_name'] ?? $before['title'] ?? null,
            'project_id' => $this->project_id,
            'visibility' => $this->visibility,
            'before_hidden' => $beforeHidden ?? false,
            'changed' => $before !== null && $after !== null
                ? array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH))
                : [],
            // 監査記録（案件・権限・利用者）は閲覧者が限定されるため一覧にも内容を含める
            'before' => $this->when($this->withSnapshots || ! in_array($this->entity_type, ['task', 'document'], true), $before),
            'after' => $this->when($this->withSnapshots || ! in_array($this->entity_type, ['task', 'document'], true), $after),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function strip(array $snapshot): array
    {
        unset($snapshot['source_type'], $snapshot['source_url'], $snapshot['created_via']);

        return $snapshot;
    }
}

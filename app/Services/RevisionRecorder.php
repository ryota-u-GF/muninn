<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Revision;
use App\Models\Task;
use App\Models\User;

/** 履歴の追記。必ず状態変更と同じ DB トランザクション内で呼ぶ。 */
class RevisionRecorder
{
    /** @param array<string, mixed>|null $before */
    public function forTask(Task $task, string $action, ?array $before, ?User $actor): Revision
    {
        $task->load('assignees');

        return $this->write('task', $task->id, $task->revision, $action, $actor, $task->created_by, $before, $task->snapshot());
    }

    /** @param array<string, mixed>|null $before */
    public function forDocument(Document $document, string $action, ?array $before, ?User $actor): Revision
    {
        return $this->write('document', $document->id, $document->revision, $action, $actor, $document->created_by, $before, $document->snapshot());
    }

    /**
     * 案件・権限・利用者など監査用の記録（閲覧は管理者のみ）
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function audit(string $entityType, int $entityId, string $action, ?User $actor, ?array $before, ?array $after, ?int $projectId = null): Revision
    {
        $version = (int) Revision::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->lockForUpdate()
            ->max('version') + 1;

        return Revision::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'version' => $version,
            'actor_id' => $actor?->id,
            'action' => $action,
            'project_id' => $projectId,
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function write(string $type, int $id, int $version, string $action, ?User $actor, int $ownerId, ?array $before, array $after): Revision
    {
        return Revision::create([
            'entity_type' => $type,
            'entity_id' => $id,
            'version' => $version,
            'actor_id' => $actor?->id,
            'action' => $action,
            'project_id' => $after['project_id'] ?? null,
            'visibility' => $after['visibility'] ?? null,
            'owner_id' => $ownerId,
            'before_project_id' => $before['project_id'] ?? null,
            'before_visibility' => $before['visibility'] ?? null,
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
    }
}

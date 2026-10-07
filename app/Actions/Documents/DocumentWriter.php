<?php

namespace App\Actions\Documents;

use App\Actions\Tasks\TaskRules;
use App\Enums\DocumentKind;
use App\Enums\Visibility;
use App\Models\Document;
use App\Models\Revision;
use App\Models\User;
use App\Services\RevisionRecorder;
use App\Services\VersionedWriter;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** 文書・議事録の作成／更新／削除／版の復元 */
class DocumentWriter
{
    private const FIELDS = ['title', 'body', 'kind', 'visibility', 'project_id', 'meeting_date', 'participants'];

    public function __construct(
        private TaskRules $rules,
        private RevisionRecorder $revisions,
        private VersionedWriter $writer,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Document
    {
        $visibility = $data['visibility'] ?? Visibility::Shared->value;
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;

        if ($actor->isContractor()) {
            abort_if($visibility !== Visibility::Shared->value, 403, '外注は非公開文書を作成できません。');
            abort_unless(Access::canEditProjectContent($actor, $projectId), 403, 'この案件に文書を作成する権限がありません。');
        }

        $this->rules->assertProjectWritable($projectId);
        $this->assertMinutes($data['kind'] ?? DocumentKind::Document->value, $data['meeting_date'] ?? null);

        return DB::transaction(function () use ($actor, $data, $visibility, $projectId) {
            $document = Document::create([
                'project_id' => $projectId,
                'visibility' => $visibility,
                'kind' => $data['kind'] ?? DocumentKind::Document->value,
                'title' => $data['title'],
                'body' => $data['body'] ?? '',
                'meeting_date' => $data['meeting_date'] ?? null,
                'participants' => $data['participants'] ?? null,
                'created_by' => $actor->id,
            ]);
            $document->refresh();
            $this->revisions->forDocument($document, 'create', null, $actor);

            return $document;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data, int $expectedRevision): Document
    {
        return DB::transaction(function () use ($actor, $id, $data, $expectedRevision) {
            /** @var Document $document */
            $document = $this->writer->lock(Document::class, $id, $expectedRevision);
            abort_unless(Gate::forUser($actor)->allows('view', $document), 404);
            abort_unless(Gate::forUser($actor)->allows('update', $document), 403, 'この文書を編集する権限がありません。');

            $before = $document->snapshot();
            $next = $before;
            foreach (self::FIELDS as $key) {
                if (array_key_exists($key, $data)) {
                    $next[$key] = $key === 'project_id' && $data[$key] !== null ? (int) $data[$key] : $data[$key];
                }
            }
            if ($next['body'] === null) {
                $next['body'] = '';
            }
            $changed = array_keys(array_filter($next, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));

            if ($actor->isContractor()) {
                $locked = array_values(array_intersect($changed, ['project_id', 'visibility', 'kind']));
                abort_if($locked !== [], 403, '外注は案件・公開区分・種類を変更できません。');
            }
            if (in_array('visibility', $changed, true)) {
                abort_unless($document->created_by === $actor->id, 403, '公開区分は作成者だけが変更できます。');
            }
            if (in_array('project_id', $changed, true)) {
                $this->rules->assertProjectWritable($next['project_id']);
            }
            $this->assertMinutes($next['kind'], $next['meeting_date']);

            $attributes = [];
            foreach (self::FIELDS as $key) {
                if (in_array($key, $changed, true)) {
                    $attributes[$key] = $next[$key];
                }
            }
            $this->writer->update($document, $expectedRevision, $attributes);

            /** @var Document $fresh */
            $fresh = Document::withTrashed()->findOrFail($id);
            $this->revisions->forDocument($fresh, 'update', $before, $actor);

            return $fresh;
        });
    }

    public function delete(User $actor, int $id, int $expectedRevision): Document
    {
        return DB::transaction(function () use ($actor, $id, $expectedRevision) {
            /** @var Document $document */
            $document = $this->writer->lock(Document::class, $id, $expectedRevision);
            abort_unless(Gate::forUser($actor)->allows('view', $document), 404);
            abort_unless(Gate::forUser($actor)->allows('delete', $document), 403, 'この文書を削除する権限がありません。');

            $before = $document->snapshot();
            $this->writer->update($document, $expectedRevision, ['deleted_at' => now()]);

            /** @var Document $fresh */
            $fresh = Document::withTrashed()->findOrFail($id);
            $this->revisions->forDocument($fresh, 'delete', $before, $actor);

            return $fresh;
        });
    }

    public function restore(User $actor, int $id, int $version, int $expectedRevision): Document
    {
        return DB::transaction(function () use ($actor, $id, $version, $expectedRevision) {
            /** @var Document $document */
            $document = $this->writer->lock(Document::class, $id, $expectedRevision);
            abort_unless(Gate::forUser($actor)->allows('view', $document), 404);
            abort_unless(Gate::forUser($actor)->allows('restore', $document), 403, 'この文書を復元する権限がありません。');

            $revision = Revision::query()
                ->where('entity_type', 'document')->where('entity_id', $id)->where('version', $version)
                ->first();
            abort_if($revision === null || $revision->after === null, 404);

            $snap = $revision->after;
            abort_unless(Access::canViewState($actor, $snap['visibility'] ?? null, $snap['project_id'] ?? null, $document->created_by), 404);

            $before = $document->snapshot();
            if ($snap['visibility'] !== $before['visibility']) {
                abort_unless($document->created_by === $actor->id, 403, '公開区分は作成者だけが変更できます。');
            }
            $this->rules->assertProjectWritable($snap['project_id'] ?? null);
            $this->assertMinutes($snap['kind'], $snap['meeting_date'] ?? null);

            $this->writer->update($document, $expectedRevision, [
                'title' => $snap['title'],
                'body' => $snap['body'],
                'kind' => $snap['kind'],
                'visibility' => $snap['visibility'],
                'project_id' => $snap['project_id'],
                'meeting_date' => $snap['meeting_date'],
                'participants' => $snap['participants'],
                'deleted_at' => null,
            ]);

            /** @var Document $fresh */
            $fresh = Document::withTrashed()->findOrFail($id);
            $this->revisions->forDocument($fresh, 'restore', $before, $actor);

            return $fresh;
        });
    }

    private function assertMinutes(string $kind, ?string $meetingDate): void
    {
        if ($kind === DocumentKind::Minutes->value && empty($meetingDate)) {
            throw ValidationException::withMessages(['meeting_date' => '議事録には会議日が必要です。']);
        }
    }
}

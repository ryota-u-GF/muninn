<?php

namespace App\Http\Controllers\Api;

use App\Actions\Documents\DocumentWriter;
use App\Actions\Tasks\RestoreTaskVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\RestoreVersionRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\RevisionResource;
use App\Http\Resources\TaskResource;
use App\Models\Document;
use App\Models\Revision;
use App\Models\Task;
use App\Services\HistoryQuery;
use App\Services\MemberDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class HistoryController extends Controller
{
    private const ENTITIES = ['tasks' => 'task', 'documents' => 'document'];

    public function __construct(private HistoryQuery $history, private MemberDirectory $members) {}

    /** 全体の履歴（現在の権限で閲覧できる変更のみ、ページング） */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(['task', 'document', 'project', 'project_access', 'user'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = (int) config('gf.history.per_page');
        $maxPage = (int) ceil(config('gf.history.global_limit') / $perPage);
        $page = min((int) $request->input('page', 1), $maxPage);

        $query = $this->history->visibleTo($request->user(), $request->input('type'))
            ->with('actor')->orderByDesc('id');

        return $this->paginate($request, $query, $page, $perPage, (int) config('gf.history.global_limit'));
    }

    /** 個別の履歴 */
    public function entity(Request $request, string $entity, int $id): JsonResponse
    {
        $this->findVisible($entity, $id);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $perPage = (int) config('gf.history.per_page');
        $maxPage = (int) ceil(config('gf.history.entity_limit') / $perPage);
        $page = min((int) $request->input('page', 1), $maxPage);

        $query = $this->history->visibleTo($request->user(), self::ENTITIES[$entity], $id)
            ->with('actor')->orderByDesc('version');

        return $this->paginate($request, $query, $page, $perPage, (int) config('gf.history.entity_limit'));
    }

    /** 版のプレビュー */
    public function show(Request $request, string $entity, int $id, int $version): JsonResponse
    {
        $this->findVisible($entity, $id);
        $revision = $this->history->visibleTo($request->user(), self::ENTITIES[$entity], $id)
            ->with('actor')->where('version', $version)->first();
        abort_if($revision === null, 404);
        $this->prepareMemberMask($request);

        return response()->json(['data' => (new RevisionResource($revision))->withSnapshots()->resolve($request)]);
    }

    /** 確認後の復元。復元は新しい版として記録する。 */
    public function restore(RestoreVersionRequest $request, string $entity, int $id, RestoreTaskVersion $restoreTask, DocumentWriter $documents): JsonResponse
    {
        $this->findVisible($entity, $id);
        $version = (int) $request->validated('version');
        $expected = (int) $request->validated('expected_revision');

        if ($entity === 'tasks') {
            return response()->json(['data' => (new TaskResource($restoreTask->handle($request->user(), $id, $version, $expected)))->resolve($request)]);
        }

        return response()->json(['data' => (new DocumentResource($documents->restore($request->user(), $id, $version, $expected)))->resolve($request)]);
    }

    private function findVisible(string $entity, int $id): Model
    {
        abort_unless(isset(self::ENTITIES[$entity]), 404);
        $model = $entity === 'tasks' ? Task::withTrashed()->find($id) : Document::withTrashed()->find($id);
        abort_if($model === null || ! Gate::allows('view', $model), 404);

        return $model;
    }

    private function prepareMemberMask(Request $request): void
    {
        $user = $request->user();
        RevisionResource::$visibleMemberIds = $user->isInternal() ? null : $this->members->visibleMemberIds($user);
    }

    /** @param Builder<Revision> $query */
    private function paginate(Request $request, $query, int $page, int $perPage, int $limit): JsonResponse
    {
        $this->prepareMemberMask($request);
        $total = min((clone $query)->count(), $limit);
        $items = $query->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $items->map(fn ($r) => (new RevisionResource($r))->resolve($request))->values(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'limit' => $limit,
            ],
        ]);
    }
}

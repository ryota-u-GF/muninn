<?php

namespace App\Http\Controllers\Api;

use App\Actions\Documents\DocumentWriter;
use App\Enums\DocumentKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpectedRevisionRequest;
use App\Http\Requests\ParseTextFileRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    /** パラメータ：kind, project_id（数値 / none）, q（タイトル・本文・参加者）, from, to, date */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'kind' => ['nullable', Rule::enum(DocumentKind::class)],
            'project_id' => ['nullable', 'regex:/^(\d+|none)$/'],
            'q' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = Document::query();
        Access::scopeVisible($query, $request->user(), 'documents');
        $query->where(fn ($q) => $q->whereNull('project_id')->orWhereHas('project', fn ($p) => $p->whereNull('archived_at')));

        if ($kind = $request->input('kind')) {
            $query->where('kind', $kind);
        }
        $projectId = $request->input('project_id');
        if ($projectId === 'none') {
            $query->whereNull('project_id');
        } elseif ($projectId !== null) {
            $query->where('project_id', (int) $projectId);
        }
        if ($term = $request->string('q')->trim()->value()) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)
                ->orWhere('body', 'like', $like)
                ->orWhere('participants', 'like', $like));
        }
        if ($date = $request->input('date')) {
            $query->whereDate('meeting_date', $date);
        }
        if ($from = $request->input('from')) {
            $query->whereDate('meeting_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('meeting_date', '<=', $to);
        }

        $documents = $query
            ->orderByDesc('meeting_date')->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(1000)->get();

        return response()->json([
            'data' => $documents->map(fn (Document $d) => (new DocumentResource($d))->summary()->resolve($request))->values(),
        ]);
    }

    public function show(Request $request, int $document): DocumentResource
    {
        return new DocumentResource($this->findVisible($document));
    }

    public function store(StoreDocumentRequest $request, DocumentWriter $writer): DocumentResource
    {
        Gate::authorize('create', Document::class);

        return new DocumentResource($writer->create($request->user(), $request->validated()));
    }

    public function update(UpdateDocumentRequest $request, int $document, DocumentWriter $writer): DocumentResource
    {
        $this->findVisible($document);

        return new DocumentResource($writer->update($request->user(), $document, $request->changes(), (int) $request->validated('expected_revision')));
    }

    public function destroy(ExpectedRevisionRequest $request, int $document, DocumentWriter $writer): DocumentResource
    {
        $this->findVisible($document);

        return new DocumentResource($writer->delete($request->user(), $document, (int) $request->validated('expected_revision')));
    }

    /** UTF-8 の .txt / .md を検証して本文として返す（保存はしない） */
    public function parseFile(ParseTextFileRequest $request): JsonResponse
    {
        $content = (string) file_get_contents($request->file('file')->getRealPath());
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'UTF-8 のテキストファイルを選択してください。']);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (mb_strlen($content) > config('gf.limits.document_body')) {
            throw ValidationException::withMessages(['file' => '本文の上限（'.number_format(config('gf.limits.document_body')).' 文字）を超えています。']);
        }

        return response()->json(['text' => $content, 'characters' => mb_strlen($content)]);
    }

    private function findVisible(int $id): Document
    {
        $document = Document::withTrashed()->find($id);
        abort_if($document === null || ! Gate::allows('view', $document), 404);

        return $document;
    }
}

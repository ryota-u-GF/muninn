<?php

namespace App\Http\Controllers\Api;

use App\Actions\Imports\ImportService;
use App\Enums\CandidateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmImportRequest;
use App\Http\Requests\ExtractImportRequest;
use App\Http\Requests\ImportJsonRequest;
use App\Http\Requests\UpdateCandidateRequest;
use App\Http\Resources\CandidateResource;
use App\Models\ImportCandidate;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Ai\AiRequestFailedException;
use App\Services\Ai\TaskExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** メール（旧AI候補）の候補（本人限定）。社内メンバーのみ（ルートの Gate で確認）。 */
class ImportController extends Controller
{
    public function __construct(private ImportService $imports) {}

    public function index(Request $request): JsonResponse
    {
        $candidates = ImportCandidate::query()->with('mailMessage')
            ->where('owner_id', $request->user()->id)
            ->where(fn ($q) => $q->where('status', CandidateStatus::Pending->value)
                ->orWhere('updated_at', '>=', now()->subDays(14)))
            ->orderByDesc('id')->limit(300)->get();

        return response()->json([
            'data' => CandidateResource::collection($candidates)->resolve($request),
        ]);
    }

    public function store(ImportJsonRequest $request): JsonResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = $request->items();

        return CandidateResource::collection($this->imports->createCandidates($request->user(), 'manual_json', $items))
            ->response()->setStatusCode(201);
    }

    /** 貼り付けた文章から、本人の AI で候補を作る（文章は保存しない）。 */
    public function extract(ExtractImportRequest $request, TaskExtractor $extractor): JsonResponse
    {
        try {
            $items = $extractor->extract($request->user(), (string) $request->validated('text'));
        } catch (AiNotConfiguredException|AiRequestFailedException $e) {
            throw ValidationException::withMessages(['ai' => $e->getMessage()]);
        }

        $candidates = $items === [] ? [] : $this->imports->createCandidates($request->user(), 'ai_text', $items);

        return CandidateResource::collection($candidates)->response()->setStatusCode(201);
    }

    public function update(UpdateCandidateRequest $request, int $candidate): CandidateResource
    {
        $data = collect($request->validated())->except('expected_revision')->all();

        return new CandidateResource($this->imports->update($request->user(), $candidate, $data, (int) $request->validated('expected_revision')));
    }

    public function reject(Request $request, int $candidate): CandidateResource
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);

        return new CandidateResource($this->imports->reject($request->user(), $candidate, (int) $data['expected_revision']));
    }

    public function confirm(ConfirmImportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->imports->confirm($request->user(), $data['idempotency_key'], $data['items']);

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}

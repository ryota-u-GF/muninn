<?php

namespace App\Http\Controllers\Api;

use App\Actions\Imports\ImportService;
use App\Enums\CandidateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmImportRequest;
use App\Http\Requests\ImportJsonRequest;
use App\Http\Requests\UpdateCandidateRequest;
use App\Http\Resources\CandidateResource;
use App\Models\ImportCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** メール・議事録から見つけたタスクの候補（本人限定）。社内メンバーのみ（ルートの Gate で確認）。 */
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

    /** サイドバーに出す、未確認の候補の件数（本人分のみ）。メールと議事録に分けて返す。 */
    public function pendingCount(Request $request): JsonResponse
    {
        $kinds = ImportCandidate::query()->with('mailMessage')
            ->where('owner_id', $request->user()->id)
            ->where('status', CandidateStatus::Pending->value)
            ->get()->map(fn (ImportCandidate $candidate) => $candidate->kind());

        $minutes = $kinds->filter(fn (string $kind) => $kind === 'minutes')->count();

        return response()->json(['data' => [
            'count' => $kinds->count(),
            'mail' => $kinds->count() - $minutes,
            'minutes' => $minutes,
        ]]);
    }

    public function store(ImportJsonRequest $request): JsonResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = $request->items();

        return CandidateResource::collection($this->imports->createCandidates($request->user(), 'manual_json', $items))
            ->response()->setStatusCode(201);
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

<?php

namespace App\Http\Controllers\Api;

use App\Actions\Imports\ImportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\InboundCandidatesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** 外部システムが AI で「タスクらしい」と判定したものを、宛先の本人専用の候補として受け取る。 */
class InboundCandidateController extends Controller
{
    public function __construct(private ImportService $imports) {}

    public function store(InboundCandidatesRequest $request): JsonResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = $request->validated('items');

        $results = [];
        foreach ($items as $item) {
            $results[] = ['external_id' => $item['external_id']] + $this->receive($item);
        }

        $status = collect($results)->contains('result', 'created') ? 201 : 200;

        return response()->json(['data' => $results], $status);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{result: string, candidate_id?: int, message?: string}
     */
    private function receive(array $item): array
    {
        $owner = User::query()->where('email', mb_strtolower((string) $item['owner_email']))->first();
        if ($owner === null || ! Gate::forUser($owner)->allows('use-imports')) {
            return ['result' => 'rejected', 'message' => '宛先のユーザーが見つからないか、候補を受け取れません。'];
        }

        $emails = array_map(fn ($email) => mb_strtolower(trim((string) $email)), (array) ($item['assignee_emails'] ?? []));
        $found = User::query()->assignable()->whereIn('email', $emails)->get(['id', 'email']);
        $item['assignee_ids'] = $found->pluck('id')->all();
        $item['unmatched_assignees'] = array_values(array_diff($emails, $found->pluck('email')->all()));
        $item['source_type'] = $item['source_type'] ?? 'other';

        $candidate = $this->imports->createInbound($owner, $item, (string) $item['external_id'], $item['source_url'] ?? null);

        return $candidate === null
            ? ['result' => 'duplicate']
            : ['result' => 'created', 'candidate_id' => $candidate->id];
    }
}

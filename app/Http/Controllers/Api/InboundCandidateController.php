<?php

namespace App\Http\Controllers\Api;

use App\Actions\Imports\ImportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\InboundCandidatesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** 外部システムが AI で「タスクらしい」と判定したものを、宛先の本人専用の候補として受け取る。 */
class InboundCandidateController extends Controller
{
    public function __construct(private ImportService $imports) {}

    public function store(InboundCandidatesRequest $request): JsonResponse
    {
        /** @var list<mixed> $items */
        $items = array_values($request->validated('items'));

        // 宛先・担当者は、1 回の呼び出しの分をまとめて引く（1 件ごとに検索しない）
        $owners = $this->usersByEmail($this->emails(array_column(array_filter($items, 'is_array'), 'owner_email')));
        $assignees = $this->usersByEmail($this->emails(array_merge(
            [],
            ...array_map(fn ($item) => is_array($item) && is_array($item['assignee_emails'] ?? null) ? $item['assignee_emails'] : [], $items),
        )), assignableOnly: true);

        $results = array_map(fn ($item) => $this->receive($item, $owners, $assignees), $items);
        $status = collect($results)->contains('result', 'created') ? 201 : 200;

        return response()->json(['data' => $results], $status);
    }

    /**
     * 1 件を受け取る。その 1 件に不備があっても、他の件は取り込む。
     *
     * @param  Collection<string, User>  $owners
     * @param  Collection<string, User>  $assignees
     * @return array{external_id: string|null, result: string, candidate_id?: int, status?: string, message?: string}
     */
    private function receive(mixed $item, Collection $owners, Collection $assignees): array
    {
        $externalId = is_array($item) && is_string($item['external_id'] ?? null) ? $item['external_id'] : null;

        $validator = Validator::make(is_array($item) ? $item : [], InboundCandidatesRequest::itemRules());
        if ($validator->fails()) {
            return ['external_id' => $externalId, 'result' => 'rejected', 'message' => $validator->errors()->first()];
        }

        /** @var array<string, mixed> $item */
        $owner = $owners->get(mb_strtolower((string) $item['owner_email']));
        if ($owner === null || ! Gate::forUser($owner)->allows('use-imports')) {
            return ['external_id' => $externalId, 'result' => 'rejected', 'message' => '宛先のユーザーが見つからないか、候補を受け取れません。'];
        }

        $emails = $this->emails((array) ($item['assignee_emails'] ?? []));
        $found = $assignees->only($emails);
        $item['assignee_ids'] = $found->pluck('id')->values()->all();
        $item['unmatched_assignees'] = array_values(array_diff($emails, $found->keys()->all()));
        $item['source_type'] = $item['source_type'] ?? 'other';

        [$candidate, $created] = $this->imports->createInbound($owner, $item, (string) $item['external_id'], $item['source_url'] ?? null);

        // 作成済みの再送は、作り直さず上書きもしない。送り手が状況を判断できるよう、既存の候補の状態を返す。
        return $created
            ? ['external_id' => $externalId, 'result' => 'created', 'candidate_id' => $candidate->id]
            : ['external_id' => $externalId, 'result' => 'duplicate', 'candidate_id' => $candidate->id, 'status' => $candidate->status->value];
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function emails(iterable $values): array
    {
        $emails = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $emails[] = mb_strtolower(trim($value));
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @param  list<string>  $emails
     * @return Collection<string, User>
     */
    private function usersByEmail(array $emails, bool $assignableOnly = false): Collection
    {
        if ($emails === []) {
            return collect();
        }

        return User::query()->when($assignableOnly, fn ($q) => $q->assignable())
            ->whereIn('email', $emails)->get()->toBase()->keyBy(fn (User $user) => mb_strtolower($user->email));
    }
}

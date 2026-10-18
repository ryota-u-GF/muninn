<?php

namespace App\Actions\Imports;

use App\Actions\Tasks\CreateTask;
use App\Enums\CandidateStatus;
use App\Exceptions\RevisionConflictException;
use App\Models\ImportBatch;
use App\Models\ImportCandidate;
use App\Models\User;
use App\Services\VersionedWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * メール（旧AI候補）：入力 → 本人限定の候補 → 人が確認 → 選択分だけタスク化。
 * AI や外部からの直接の共有化経路は持たない。
 */
class ImportService
{
    public function __construct(
        private CandidateNormalizer $normalizer,
        private CreateTask $createTask,
        private VersionedWriter $writer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<ImportCandidate>
     */
    public function createCandidates(User $owner, string $source, array $items, ?int $mailMessageId = null): array
    {
        return DB::transaction(function () use ($owner, $source, $items, $mailMessageId) {
            $created = [];
            foreach ($items as $item) {
                $normalized = $this->normalizer->normalize($item, $owner);
                $fingerprint = $this->fingerprint($owner, $normalized['payload']);
                $warnings = $normalized['warnings'];

                $previous = ImportCandidate::query()
                    ->where('owner_id', $owner->id)->where('fingerprint', $fingerprint)
                    ->latest('id')->first();
                if ($previous !== null) {
                    $warnings[] = match ($previous->status) {
                        CandidateStatus::Confirmed => '同じ内容の候補は以前に確定済みです（重複の可能性）。',
                        CandidateStatus::Rejected => '同じ内容の候補は以前に却下されています。',
                        CandidateStatus::Pending => '同じ内容の未確認候補がすでにあります。',
                    };
                }

                $created[] = ImportCandidate::create([
                    'owner_id' => $owner->id,
                    'source' => $source,
                    'payload' => $normalized['payload'],
                    'warnings' => $warnings,
                    'status' => CandidateStatus::Pending,
                    'fingerprint' => $fingerprint,
                    'mail_message_id' => $mailMessageId,
                ])->refresh();
            }

            return $created;
        });
    }

    /** @param array<string, mixed> $item */
    public function update(User $owner, int $id, array $item, int $expectedRevision): ImportCandidate
    {
        return DB::transaction(function () use ($owner, $id, $item, $expectedRevision) {
            /** @var ImportCandidate $candidate */
            $candidate = $this->writer->lock(ImportCandidate::class, $id, $expectedRevision);
            abort_unless($candidate->owner_id === $owner->id, 404);
            abort_unless($candidate->status === CandidateStatus::Pending, 409, 'この候補はすでに処理済みです。');

            $normalized = $this->normalizer->normalize(array_merge($candidate->payload ?? [], $item), $owner);
            $this->writer->update($candidate, $expectedRevision, [
                'payload' => json_encode($normalized['payload'], JSON_UNESCAPED_UNICODE),
                'warnings' => json_encode($normalized['warnings'], JSON_UNESCAPED_UNICODE),
                'fingerprint' => $this->fingerprint($owner, $normalized['payload']),
            ]);

            return ImportCandidate::query()->findOrFail($id);
        });
    }

    /** 却下：本文（payload）を削除し、重複防止のハッシュと状態だけ残す */
    public function reject(User $owner, int $id, int $expectedRevision): ImportCandidate
    {
        return DB::transaction(function () use ($owner, $id, $expectedRevision) {
            /** @var ImportCandidate $candidate */
            $candidate = $this->writer->lock(ImportCandidate::class, $id, $expectedRevision);
            abort_unless($candidate->owner_id === $owner->id, 404);
            abort_unless($candidate->status === CandidateStatus::Pending, 409, 'この候補はすでに処理済みです。');

            $this->writer->update($candidate, $expectedRevision, [
                'payload' => null,
                'warnings' => null,
                'status' => CandidateStatus::Rejected->value,
            ]);

            return ImportCandidate::query()->findOrFail($id);
        });
    }

    /**
     * 選択した候補を原子的にタスク化する（公開区分は候補ごとに共有／非公開）。1 件でも競合・不備があれば全体を確定しない。
     * 同じ本人＋idempotency_key の再送は保存済みの結果を返す。
     *
     * @param  list<array{id: int, expected_revision: int}>  $items
     * @return array{replayed: bool, task_ids: list<int>, candidate_ids: list<int>}
     */
    public function confirm(User $owner, string $key, array $items): array
    {
        $normalizedItems = collect($items)
            ->map(fn ($i) => ['id' => (int) $i['id'], 'expected_revision' => (int) $i['expected_revision']])
            ->sortBy('id')->values()->all();
        $hash = hash('sha256', json_encode($normalizedItems, JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($owner, $key, $normalizedItems, $hash) {
                $existing = ImportBatch::query()
                    ->where('owner_id', $owner->id)->where('idempotency_key', $key)
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    return $this->replay($existing, $hash);
                }

                $batch = ImportBatch::create([
                    'owner_id' => $owner->id,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ]);

                $ids = array_column($normalizedItems, 'id');
                $candidates = ImportCandidate::query()
                    ->whereIn('id', $ids)->where('owner_id', $owner->id)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $taskIds = [];
                foreach ($normalizedItems as $item) {
                    /** @var ImportCandidate|null $candidate */
                    $candidate = $candidates->get($item['id']);
                    abort_if($candidate === null, 404, '候補が見つかりません。');
                    if ($candidate->status !== CandidateStatus::Pending) {
                        throw new RevisionConflictException($candidate->revision, "候補 #{$candidate->id} はすでに処理済みです。全体を確定しませんでした。");
                    }
                    if ($candidate->revision !== $item['expected_revision']) {
                        throw new RevisionConflictException($candidate->revision, "候補 #{$candidate->id} が別の画面で変更されています。全体を確定しませんでした。");
                    }

                    $payload = $candidate->payload ?? [];
                    if (trim((string) ($payload['title'] ?? '')) === '') {
                        throw ValidationException::withMessages(["items.{$candidate->id}" => "候補 #{$candidate->id} のタイトルが空です。"]);
                    }

                    $task = $this->createTask->handle($owner, [
                        'title' => $payload['title'],
                        'description' => $payload['description'] ?? null,
                        'project_id' => $payload['project_id'] ?? null,
                        'assignee_ids' => $payload['assignee_ids'] ?? [],
                        'start_date' => $payload['start_date'] ?? null,
                        'due_date' => $payload['due_date'] ?? null,
                        'source_type' => $payload['source_type'] ?? 'other',
                        'visibility' => $payload['visibility'] ?? 'shared',
                        'status' => 'todo',
                    ], 'ai_import');

                    $this->writer->update($candidate, $item['expected_revision'], [
                        'status' => CandidateStatus::Confirmed->value,
                        'task_id' => $task->id,
                    ]);
                    $taskIds[] = $task->id;
                }

                $result = ['task_ids' => $taskIds, 'candidate_ids' => $ids];
                $batch->update(['result' => $result]);

                return ['replayed' => false] + $result;
            }, attempts: 3); // 同一キーの同時送信によるデッドロックは再試行（再試行時は保存済み結果を返す）
        } catch (UniqueConstraintViolationException) {
            // 同じキーの同時送信。先に確定した結果を返す。
            $existing = ImportBatch::query()->where('owner_id', $owner->id)->where('idempotency_key', $key)->first();
            if ($existing === null || $existing->result === null) {
                throw new RevisionConflictException(null, '同じ確定処理が実行中です。少し待ってから再読み込みしてください。');
            }

            return $this->replay($existing, $hash);
        }
    }

    /** @return array{replayed: bool, task_ids: list<int>, candidate_ids: list<int>} */
    private function replay(ImportBatch $batch, string $hash): array
    {
        if ($batch->request_hash !== $hash) {
            throw ValidationException::withMessages(['idempotency_key' => '同じ確定キーで異なる内容が送信されました。']);
        }

        /** @var array{task_ids: list<int>, candidate_ids: list<int>} $result */
        $result = $batch->result;

        return ['replayed' => true] + $result;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(User $owner, array $payload): string
    {
        return hash('sha256', implode('|', [
            $owner->id,
            mb_strtolower(trim((string) ($payload['title'] ?? ''))),
            $payload['due_date'] ?? '',
            $payload['project_id'] ?? '',
        ]));
    }
}

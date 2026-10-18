<?php

namespace App\Services\Meet;

use App\Actions\Imports\ImportService;
use App\Enums\ExtractKind;
use App\Models\MailMessage;
use App\Models\MeetConnection;
use App\Models\User;
use App\Services\Ai\AiContentRejectedException;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Ai\AiRequestFailedException;
use App\Services\Ai\TaskExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 終わった会議の「Meet のメモ」（予定に付いたドキュメント）を読み、本人専用のタスク候補にする。
 * 本文は保存せず、AI に渡すだけ。承認するまでタスクにはならない。
 */
class MeetNotesSyncer
{
    public function __construct(
        private MeetClient $meet,
        private TaskExtractor $extractor,
        private ImportService $imports,
    ) {}

    /**
     * 1 回に最大 gf.meet.max_per_sync 件を処理する。残りがあれば has_more が true になるので、続けて呼ぶ。
     *
     * @return array{processed: int, skipped: int, candidates: int, has_more: bool}
     *
     * @throws MeetException
     * @throws AiNotConfiguredException AI のキーが未設定のとき
     * @throws AiRequestFailedException AI の呼び出しに失敗したとき（そのメモは記録せず、次回やり直す）
     */
    public function sync(User $user): array
    {
        $connection = MeetConnection::query()->where('user_id', $user->id)->first()
            ?? throw new MeetException('Meetのメモが連携されていません。');

        $now = CarbonImmutable::now();

        try {
            $token = $this->meet->accessToken($connection);
            $notes = $this->meet->listNoteDocuments($token, $this->from($connection, $now), $now, $connection->keywords(), (int) config('gf.meet.list_size'));

            $ids = array_column($notes, 'file_id');
            $known = MailMessage::query()->where('owner_id', $user->id)->where('kind', 'minutes')->whereIn('external_id', $ids)
                ->pluck('external_id')->all();
            $todo = array_values(array_filter($notes, fn (array $n) => ! in_array($n['file_id'], $known, true)));
            $batch = array_slice($todo, 0, (int) config('gf.meet.max_per_sync'));

            $result = ['processed' => 0, 'skipped' => 0, 'candidates' => 0, 'has_more' => count($todo) > count($batch)];
            foreach ($batch as $note) {
                $count = $this->process($user, $connection, $token, $note);
                $count === null ? $result['skipped']++ : $result['processed']++;
                $result['candidates'] += $count ?? 0;
            }
        } catch (MeetException|AiNotConfiguredException|AiRequestFailedException $e) {
            // 失敗は画面に残し、自動取得が同じ失敗を繰り返さないための目印にもする
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }

        if (! $result['has_more']) {
            $connection->update(['last_synced_at' => $now, 'last_error' => null]);
        }

        return $result;
    }

    /**
     * メモ 1 件を処理する。AI が受け付けない内容なら記録だけ残してスキップ（null）する。
     *
     * @param  array{file_id: string, doc_title: string, event_title: string, organizer: string, attendees: list<string>, start: int|null}  $note
     */
    private function process(User $user, MeetConnection $connection, string $token, array $note): ?int
    {
        $body = $this->meet->documentText($token, $note['file_id']);
        $title = $note['event_title'] !== '' ? $note['event_title'] : $note['doc_title'];
        $date = $note['start'] === null ? '' : CarbonImmutable::createFromTimestamp($note['start'], config('gf.timezone'))->format('Y年n月j日 H:i');
        $header = "会議名：{$title}\n日時：{$date}\n参加者：".implode('、', $note['attendees'])."\n\n";
        $text = mb_substr($header.$body, 0, (int) config('gf.ai.max_text_chars'));

        try {
            $items = trim($body) === '' ? [] : $this->extractor->extract($user, $text, ExtractKind::Minutes);
            $status = 'processed';
        } catch (AiContentRejectedException) {
            $items = [];
            $status = 'skipped';
        }

        DB::transaction(function () use ($user, $connection, $note, $title, $items, $status) {
            $record = MailMessage::query()->create([
                'owner_id' => $user->id,
                'kind' => 'minutes',
                'external_id' => $note['file_id'],
                'account_email' => $connection->google_email,
                'subject' => $title !== '' ? $title : null,
                'sender' => $note['organizer'] !== '' ? $note['organizer'] : null,
                'received_at' => $note['start'] === null ? null : CarbonImmutable::createFromTimestamp($note['start']),
                'status' => $status,
                'candidate_count' => count($items),
            ]);
            if ($items !== []) {
                $this->imports->createCandidates($user, 'meet', $items, $record->id);
            }
        });

        return $status === 'skipped' ? null : count($items);
    }

    /** 取得を始める日時（初回は、設定した日数前の 0 時から。2 回目以降は、前回の 1 時間前から）。 */
    private function from(MeetConnection $connection, CarbonImmutable $now): CarbonImmutable
    {
        return $connection->last_synced_at === null
            ? $now->setTimezone(config('gf.timezone'))->startOfDay()->subDays($connection->initial_days)
            : $connection->last_synced_at->subHour(); // 取りこぼし防止（二重取り込みは ID で防ぐ）
    }
}

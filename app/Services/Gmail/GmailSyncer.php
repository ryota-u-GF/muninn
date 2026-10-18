<?php

namespace App\Services\Gmail;

use App\Actions\Imports\ImportService;
use App\Models\GmailConnection;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Ai\AiContentRejectedException;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Ai\AiRequestFailedException;
use App\Services\Ai\TaskExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gmail の新着メールを取り込み、本人専用のタスク候補にする。
 * 本文は保存せず、AI に渡すだけ。承認するまでタスクにはならない。
 */
class GmailSyncer
{
    public function __construct(
        private GmailClient $gmail,
        private TaskExtractor $extractor,
        private ImportService $imports,
    ) {}

    /**
     * 1 回に最大 gf.gmail.max_per_sync 通を処理する。残りがあれば has_more が true になるので、続けて呼ぶ。
     *
     * @return array{processed: int, skipped: int, candidates: int, has_more: bool}
     *
     * @throws GmailException
     * @throws AiNotConfiguredException AI のキーが未設定のとき
     * @throws AiRequestFailedException AI の呼び出しに失敗したとき（そのメールは記録せず、次回やり直す）
     */
    public function sync(User $user): array
    {
        $connection = GmailConnection::query()->where('user_id', $user->id)->first()
            ?? throw new GmailException('Gmailが連携されていません。');

        $startedAt = CarbonImmutable::now();

        try {
            $token = $this->gmail->accessToken($connection);
            $ids = $this->gmail->listMessageIds($token, $this->query($connection), (int) config('gf.gmail.list_size'));

            $known = MailMessage::query()->where('owner_id', $user->id)->whereIn('gmail_message_id', $ids)
                ->pluck('gmail_message_id')->all();
            $todo = array_values(array_diff($ids, $known));
            $batch = array_slice($todo, 0, (int) config('gf.gmail.max_per_sync'));

            $result = ['processed' => 0, 'skipped' => 0, 'candidates' => 0, 'has_more' => count($todo) > count($batch)];
            foreach ($batch as $id) {
                $mail = $this->gmail->getMessage($token, $id);
                $candidates = $this->process($user, $connection, $mail);
                $candidates === null ? $result['skipped']++ : $result['processed']++;
                $result['candidates'] += $candidates ?? 0;
            }
        } catch (GmailException $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }

        if (! $result['has_more']) {
            $connection->update(['last_synced_at' => $startedAt, 'last_error' => null]);
        }

        return $result;
    }

    /**
     * メール 1 通を処理する。AI が受け付けない内容なら記録だけ残してスキップ（null）する。
     * AI の設定不備・一時的な失敗は例外のまま上へ伝え、そのメールは記録しない（次回やり直す）。
     *
     * @param  array{id: string, subject: string, sender: string, received_at: int|null, text: string}  $mail
     */
    private function process(User $user, GmailConnection $connection, array $mail): ?int
    {
        $text = mb_substr("件名：{$mail['subject']}\n差出人：{$mail['sender']}\n\n{$mail['text']}", 0, (int) config('gf.ai.max_text_chars'));

        try {
            $items = trim($mail['text']) === '' ? [] : $this->extractor->extract($user, $text);
            $status = 'processed';
        } catch (AiContentRejectedException) {
            $items = [];
            $status = 'skipped';
        }

        DB::transaction(function () use ($user, $connection, $mail, $items, $status) {
            $record = MailMessage::query()->create([
                'owner_id' => $user->id,
                'gmail_message_id' => $mail['id'],
                'account_email' => $connection->google_email,
                'subject' => $mail['subject'] !== '' ? $mail['subject'] : null,
                'sender' => $mail['sender'] !== '' ? $mail['sender'] : null,
                'received_at' => $mail['received_at'] === null ? null : CarbonImmutable::createFromTimestamp($mail['received_at']),
                'status' => $status,
                'candidate_count' => count($items),
            ]);
            if ($items !== []) {
                $this->imports->createCandidates($user, 'gmail', $items, $record->id);
            }
        });

        return $status === 'skipped' ? null : count($items);
    }

    /** 取り込み対象の Gmail 検索条件を、設定から組み立てる。 */
    public function query(GmailConnection $connection): string
    {
        $parts = [];
        if ($connection->scope_mode === 'label' && filled($connection->label_name)) {
            $parts[] = 'label:"'.str_replace('"', '', (string) $connection->label_name).'"';
        } else {
            $parts[] = 'in:inbox';
        }
        if ($connection->exclude_promotions) {
            $parts[] = '-category:promotions -category:social -category:forums';
        }
        $parts[] = $connection->last_synced_at === null
            ? 'newer_than:'.$connection->initial_days.'d'
            : 'after:'.($connection->last_synced_at->getTimestamp() - 3600); // 取りこぼし防止に 1 時間重ねる（二重取り込みは ID で防ぐ）

        return implode(' ', $parts);
    }
}

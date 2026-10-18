<?php

namespace App\Services\Ai;

use App\Enums\ExtractKind;
use App\Models\User;

/** 文章からタスク候補を取り出す。実装は差し替え可能（現在は Claude のみ）。 */
interface TaskExtractor
{
    /**
     * 利用者本人の AI 設定で、文章からタスク候補を取り出す。
     *
     * @return list<array<string, mixed>> 候補（title, description, due_date, project_id, source_type）
     *
     * @throws AiNotConfiguredException 設定が無いとき
     * @throws AiRequestFailedException 呼び出しに失敗したとき
     */
    public function extract(User $user, string $text, ExtractKind $kind = ExtractKind::Mail): array;

    /**
     * 保存済みの設定で AI に接続できるか確認する（ごく小さなリクエスト）。
     *
     * @throws AiNotConfiguredException
     * @throws AiRequestFailedException
     */
    public function verify(User $user): void;
}

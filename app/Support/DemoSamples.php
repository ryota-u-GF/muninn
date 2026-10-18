<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;

/**
 * AI 候補の「合成デモ」。実際の AI 推論・Gmail 取得・録音ではない。
 * 固定の合成テキストから、決まった規則で候補を作るだけ。
 */
final class DemoSamples
{
    /** @return array<string, array{label: string, text: string}> */
    public static function inputs(): array
    {
        return [
            'email' => [
                'label' => '合成メール',
                'text' => "件名：プロジェクト1の素材について\n差出人：sample-client@example.test\n\n【案件】プロジェクト1\nトップページ用の写真素材を来週金曜までにご用意ください。\nあわせて会社概要ページの文言確認もお願いします（期限未定）。",
            ],
            'minutes' => [
                'label' => '合成議事録',
                'text' => "定例会議（合成データ）\n【案件】プロジェクト2\n決定事項：\n・テスト環境の構築を今週中に完了する（担当：エンジニア）\n・移行手順書の初稿を作る\nアクション：見積の再確認",
            ],
            'voice' => [
                'label' => '合成音声メモ',
                'text' => "（音声入力の文字起こしを模した合成データ）\nえーと、展示会のブース手配、来月の頭までに業者に連絡。あと名刺の追加発注も。",
            ],
        ];
    }

    /**
     * 合成テキストに対応する固定の候補。案件は本文中の【案件】名と完全一致した場合だけ設定する。
     *
     * @return list<array<string, mixed>>
     */
    public static function candidates(string $key): array
    {
        $today = Clock::now();
        $projectId = fn (string $name) => Project::query()->active()->where('name', $name)->value('id');
        $engineer = User::query()->assignable()->where('email', 'engineer@example.test')->value('id');

        return match ($key) {
            'email' => [
                ['title' => 'トップページ用の写真素材を用意する', 'description' => '合成メールより', 'project_id' => $projectId('プロジェクト1'), 'due_date' => $today->next('Friday')->addWeek()->format('Y-m-d'), 'source_type' => 'email'],
                ['title' => '会社概要ページの文言確認', 'description' => '期限は本文に記載なし（推測しない）', 'project_id' => $projectId('プロジェクト1'), 'due_date' => null, 'source_type' => 'email'],
            ],
            'minutes' => [
                ['title' => 'テスト環境の構築', 'project_id' => $projectId('プロジェクト2'), 'assignee_ids' => $engineer ? [$engineer] : [], 'due_date' => $today->endOfWeek()->format('Y-m-d'), 'source_type' => 'minutes'],
                ['title' => '移行手順書の初稿作成', 'project_id' => $projectId('プロジェクト2'), 'source_type' => 'minutes'],
                ['title' => '見積の再確認', 'project_id' => $projectId('プロジェクト2'), 'source_type' => 'minutes'],
            ],
            'voice' => [
                ['title' => '展示会ブース手配の業者連絡', 'due_date' => $today->addMonthNoOverflow()->startOfMonth()->format('Y-m-d'), 'source_type' => 'voice'],
                ['title' => '名刺の追加発注', 'source_type' => 'voice'],
            ],
            default => [],
        };
    }
}

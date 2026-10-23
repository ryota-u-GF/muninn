<?php

namespace Database\Seeders;

use App\Actions\Admin\SetProjectAccess;
use App\Actions\Admin\UserWriter;
use App\Actions\Documents\DocumentWriter;
use App\Actions\Projects\ProjectWriter;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\Access;
use App\Support\Clock;
use App\Support\DemoMode;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 開発・テスト専用のデモデータ。今日を基準に生成し、固定日付に依存しない。
 * メールはすべて example.test（実在しない）。秘密値は含まない。
 */
class DemoSeeder extends Seeder
{
    public function __construct(
        private UserWriter $users,
        private ProjectWriter $projects,
        private SetProjectAccess $access,
        private CreateTask $createTask,
        private UpdateTask $updateTask,
        private DocumentWriter $documents,
    ) {}

    public function run(): void
    {
        if (! DemoMode::seedingAllowed()) {
            throw new RuntimeException('デモデータは local / testing 環境でのみ投入できます。');
        }
        if (User::query()->exists()) {
            throw new RuntimeException('利用者が既に存在します。デモデータは空の開発用DBにのみ投入してください（php artisan migrate:fresh --seed）。');
        }

        $today = Clock::now()->startOfDay();
        $d = fn (int $offset) => $today->addDays($offset)->format('Y-m-d');

        // 利用者
        $president = $this->users->create(null, '社長', 'president@example.test', Role::Admin);
        $sales = $this->users->create($president, '営業', 'sales@example.test', Role::Employee);
        $engineer = $this->users->create($president, 'エンジニア', 'engineer@example.test', Role::Employee);
        $c1 = $this->users->create($president, '外注', 'contractor1@example.test', Role::Contractor);
        $internal = [$president, $sales, $engineer];

        // 案件（プロジェクト1〜3）
        $a = $this->projects->create($president, 'プロジェクト1');
        $b = $this->projects->create($president, 'プロジェクト2');
        $c = $this->projects->create($sales, 'プロジェクト3');

        // 外注権限：外注=プロジェクト1を編集
        $this->access->handle($president, $c1->id, $a->id, 'edit', 'none');
        Access::forgetCache($c1);

        // 共有タスク：期限・状態・担当人数の代表的なパターンを少数ずつ用意する
        // [案件, タイトル, 状態, 開始日(今日からの日数), 期限(同), 担当者, 作成者]
        $tasks = [
            // 期限超過（未完了）
            [$a, 'トップページのデザイン確認', 'doing', -7, -2, [$c1], $sales],
            [$b, 'ベンダー見積の比較', 'todo', -10, -3, [$president], $engineer],
            // 本日期限
            [$a, 'お問い合わせフォームの実装', 'doing', -3, 0, [$engineer, $c1], $president],
            [null, '備品の発注', 'doing', -1, 0, [$sales], $sales],
            // 間近（明日〜数日以内）
            [$b, 'テスト環境の準備', 'doing', -5, 1, [$engineer], $engineer],
            [$a, '写真素材の手配', 'todo', 0, 2, [$sales], $sales],
            [$c, 'ブース位置の確認', 'doing', -2, 4, [$sales], $president],
            // 来週あたり
            [$a, '原稿の最終確認', 'todo', 3, 10, [$president, $sales], $president],
            [$b, 'データ移行手順の検討', 'todo', null, 7, [$engineer, $president], $engineer],
            [null, '月次報告の作成', 'todo', null, 5, [$president], $president],
            // まだ先
            [$c, 'パネルデザイン', 'todo', 1, 12, [$sales, $engineer], $sales],
            [$b, '受入テスト計画', 'todo', 10, 25, [$engineer, $sales, $president], $engineer],
            [$a, '公開前チェック', 'todo', 14, 28, [], $president],
            // 日付が一部だけ・未設定
            [$c, '来場者アンケート作成', 'todo', 5, null, [], $sales],
            [$b, '操作マニュアルの作成', 'todo', null, null, [], $engineer],
            [null, '防災訓練の計画', 'todo', null, null, [], $president],
            // 完了
            [$a, 'サイトマップ確定', 'done', -14, -8, [$sales], $sales],
            [$c, '展示会予算の確定', 'done', -20, -12, [$president], $president],
        ];

        foreach ($tasks as $i => [$project, $title, $status, $start, $due, $assignees, $creator]) {
            $task = $this->createTask->handle($creator, [
                'title' => $title,
                'description' => "{$title}についての作業メモ（デモデータ）。",
                'project_id' => $project?->id,
                'status' => $status,
                'start_date' => $start === null ? null : $d($start),
                'due_date' => $due === null ? null : $d($due),
                'assignee_ids' => array_map(fn (User $u) => $u->id, $assignees),
                'visibility' => 'shared',
            ]);

            // 履歴用に一部を更新しておく
            if ($i % 6 === 0) {
                $this->updateTask->handle($creator, $task->id, ['description' => "{$title}：先方確認済み（デモの更新履歴）。"], $task->revision);
            }
        }

        // 古い完了タスク（通常非表示、全件表示で確認）
        foreach (['旧ロゴの差し替え', '前年度の展示会精算'] as $title) {
            $task = $this->createTask->handle($sales, ['title' => $title, 'project_id' => $c->id, 'status' => 'done', 'start_date' => $d(-40), 'due_date' => $d(-35)]);
            Task::query()->whereKey($task->id)->update(['completed_at' => $today->subDays(30)]);
        }

        // 本人の非公開タスク（管理者でも他人のものは見えない）
        $privates = [
            [$president, '取締役会の資料下書き（非公開）'],
            [$sales, '個人の営業目標の整理（非公開）'],
            [$engineer, '資格試験の勉強（非公開）'],
        ];
        foreach ($privates as $i => [$owner, $title]) {
            $this->createTask->handle($owner, [
                'title' => $title,
                'visibility' => 'private',
                'assignee_ids' => [$owner->id],
                'due_date' => $d(($i % 3) * 3),
                'status' => $i % 2 ? 'doing' : 'todo',
            ]);
        }

        // 非公開→共有に切り替えたタスク（公開前の内容を履歴から漏らさない確認用）
        $draft = $this->createTask->handle($engineer, ['title' => '社外秘：仮の作業名', 'description' => '公開前の下書き（他人には見えない）', 'visibility' => 'private', 'assignee_ids' => [$engineer->id]]);
        $this->updateTask->handle($engineer, $draft->id, ['visibility' => 'shared', 'title' => '負荷試験の実施', 'description' => 'チームに共有しました。', 'project_id' => $b->id], $draft->revision);

        // 文書
        $docs = [
            [$a, 'サイト構成と担当', "# サイト構成\n- トップ\n- 会社概要\n- サービス\n- 採用\n\n更新担当は営業、実装はエンジニアと外注。"],
            [$a, '原稿の書き方ルール', "1. です・ます調で統一\n2. 数字は半角\n3. 固有名詞は正式名称"],
            [$b, '移行作業の手順（初稿）', "## 手順\n1. バックアップ取得\n2. 書込み停止\n3. 移行\n4. 照合\n5. 切替"],
            [$b, 'テスト環境の接続情報について', '接続情報は別途管理者から共有します（この文書には記載しません）。'],
            [$c, '展示会当日の動き', "09:00 搬入\n10:00 開場\n17:00 撤収"],
            [null, '就業規則の要点（社内向け）', '始業 8:30／昼休憩 12:00〜13:00／終業 17:30'],
        ];
        foreach ($docs as $i => [$project, $title, $body]) {
            $doc = $this->documents->create($internal[$i % 3], ['kind' => 'document', 'project_id' => $project?->id, 'title' => $title, 'body' => $body]);
            if ($i % 3 === 0) {
                $this->documents->update($internal[$i % 3], $doc->id, ['body' => $body."\n\n（追記）デモの更新履歴です。"], $doc->revision);
            }
        }
        $this->documents->create($sales, ['kind' => 'document', 'visibility' => 'private', 'title' => '個人メモ：商談の振り返り（非公開）', 'body' => '他の人（管理者を含む）には表示されません。']);

        // 議事録（過去1か月〜未来1か月、同日に複数会議を含む）
        $minutes = [
            [-28, $a, 'キックオフ', [$president, $sales, $c1]],
            [-21, $b, '要件定義レビュー', [$engineer, $president]],
            [-14, $a, 'デザイン確認', [$sales, $c1]],
            [-14, $c, '展示会 準備会議', [$sales, $president]],
            [-3, $b, '移行方式の決定', [$engineer, $president]],
            [0, $a, '公開スケジュール確認', [$president, $sales, $c1]],
            [0, null, '全体朝会', [$president, $sales, $engineer]],
            [7, $c, '展示会 最終確認', [$sales, $president]],
            [14, $b, '受入テスト計画レビュー', [$engineer, $president]],
            [28, $a, '公開後ふりかえり', [$president, $sales, $c1]],
        ];
        foreach ($minutes as $i => [$offset, $project, $title, $people]) {
            $body = "## 議題\n- {$title}\n\n## 決定事項\n- 次回までに担当者が対応する\n\n## 議論\n- 進捗と課題を共有した\n\n## アクション\n- 担当：".$people[0]->name;
            $doc = $this->documents->create($people[0]->isContractor() ? $president : $people[0], [
                'kind' => 'minutes',
                'project_id' => $project?->id,
                'title' => $title,
                'meeting_date' => $d($offset),
                'participants' => implode('、', array_map(fn ($u) => $u->name, $people)),
                'body' => $body,
            ]);
            if ($i % 4 === 1) {
                $this->documents->update($president, $doc->id, ['body' => $body."\n- 追加：予算の再確認"], $doc->revision);
            }
        }
    }
}

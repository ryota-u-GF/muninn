<?php

namespace Database\Seeders;

use App\Actions\Admin\SetProjectAccess;
use App\Actions\Admin\UserWriter;
use App\Actions\Documents\DocumentWriter;
use App\Actions\Projects\ProjectWriter;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\Role;
use App\Models\Project;
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

        mt_srand(20261007);
        $today = Clock::now()->startOfDay();
        $d = fn (int $offset) => $today->addDays($offset)->format('Y-m-d');

        // 利用者
        $president = $this->users->create(null, '山田 太郎（社長）', 'president@example.test', Role::Admin);
        $sales = $this->users->create($president, '佐藤 花子（営業）', 'sales@example.test', Role::Employee);
        $engineer = $this->users->create($president, '鈴木 一郎（エンジニア）', 'engineer@example.test', Role::Employee);
        $c1 = $this->users->create($president, '外注1 高橋（A編集）', 'contractor1@example.test', Role::Contractor);
        $c2 = $this->users->create($president, '外注2 田中（A閲覧）', 'contractor2@example.test', Role::Contractor);
        $c3 = $this->users->create($president, '外注3 伊藤（B編集）', 'contractor3@example.test', Role::Contractor);
        $this->users->create($president, '会議室TV', 'tv@example.test', Role::Tv);
        $internal = [$president, $sales, $engineer];

        // 案件（A〜D は有効、E はアーカイブ）
        $a = $this->projects->create($president, 'ウェブサイト刷新');
        $b = $this->projects->create($president, '社内システム更改');
        $c = $this->projects->create($sales, '展示会出展2026');
        $dd = $this->projects->create($sales, '新商品パッケージ');
        $e = $this->projects->create($president, '旧顧客ポータル（終了）');

        // 外注権限：外注1=A編集、外注2=A閲覧、外注3=B編集
        $this->access->handle($president, $c1->id, $a->id, 'edit', 'none');
        $this->access->handle($president, $c2->id, $a->id, 'view', 'none');
        $this->access->handle($president, $c3->id, $b->id, 'edit', 'none');
        foreach ([$c1, $c2, $c3] as $u) {
            Access::forgetCache($u);
        }

        $titles = [
            $a->id => ['トップページのワイヤーフレーム', 'デザインカンプ作成', '写真撮影の手配', 'CMS選定', 'お問い合わせフォーム実装', 'サイトマップ確定', 'SEOキーワード調査', 'ステージング環境構築', '原稿入稿', 'アクセシビリティ確認', 'OGP画像作成', '公開前チェックリスト', '旧サイトのリダイレクト設計', 'フッター文言修正', 'ニュース一覧の改修', 'スマホ表示確認', '会社概要ページ更新', '採用ページ作成', 'フォント選定', '表示速度の計測'],
            $b->id => ['現行業務のヒアリング', '要件定義書レビュー', 'テスト環境の準備', 'データ移行手順の検討', 'アカウント棚卸し', 'バックアップ方式の確認', 'ベンダー見積比較', '操作マニュアル作成', '権限設計', '帳票レイアウト確認', '障害時連絡網の整理', 'ネットワーク構成確認', '受入テスト計画', 'パイロット部署の選定', '移行リハーサル', '保守契約の確認', 'ログ保管方針', '研修日程調整'],
            $c->id => ['ブース位置の確認', '出展申込書の提出', 'パネルデザイン', 'ノベルティ発注', '配布資料の印刷', '当日シフト作成', '搬入出業者の手配', '来場者アンケート作成', '名刺の追加発注', 'デモ機の準備', '招待状の送付', '事後フォローメール', '展示会予算の確定', 'ブース照明の手配', '動画デモの編集', '出展者説明会への参加'],
            $dd->id => ['競合パッケージ調査', 'コンセプト案作成', '素材サンプル取り寄せ', 'デザイン3案作成', '社内投票', '印刷会社との打合せ', 'JANコード取得', '法定表示の確認', '試作品の評価', '最終入稿', '撮影用サンプル手配', '価格表の更新', '販促POPの作成', '取扱説明書の改訂', '卸先への案内', '在庫計画の見直し'],
            0 => ['月次報告の作成', '備品の発注', '健康診断の案内', '年末調整の準備', '防災訓練の計画', '社内勉強会の企画', '経費精算の締め', 'オフィス清掃業者の更新'],
        ];

        $assigneePool = [
            $a->id => [$president->id, $sales->id, $engineer->id, $c1->id],
            $b->id => [$engineer->id, $president->id, $c3->id],
            $c->id => [$sales->id, $president->id],
            $dd->id => [$sales->id, $engineer->id],
            0 => [$president->id, $sales->id, $engineer->id],
        ];

        $count = 0;
        foreach ($titles as $projectId => $list) {
            foreach ($list as $i => $title) {
                $creator = $internal[$count % 3];
                $pattern = $count % 10;
                $start = null;
                $due = null;
                $status = 'todo';
                switch ($pattern) {
                    case 0: // 期限超過（未完了）
                        $start = $d(-mt_rand(10, 30));
                        $due = $d(-mt_rand(1, 9));
                        $status = mt_rand(0, 1) ? 'todo' : 'doing';
                        break;
                    case 1: // 進行中・期間中
                        $start = $d(-mt_rand(1, 10));
                        $due = $d(mt_rand(1, 20));
                        $status = 'doing';
                        break;
                    case 2: // 本日期限
                        $start = $d(-mt_rand(0, 5));
                        $due = $d(0);
                        $status = mt_rand(0, 1) ? 'todo' : 'doing';
                        break;
                    case 3: // 日付未設定
                        break;
                    case 4: // 完了（最近）
                        $start = $d(-mt_rand(5, 20));
                        $due = $d(-mt_rand(0, 4));
                        $status = 'done';
                        break;
                    case 5: // 未来（遠い期限）
                        $start = $d(mt_rand(5, 20));
                        $due = $d(mt_rand(21, 31));
                        break;
                    case 6: // 期限のみ
                        $due = $d(mt_rand(1, 14));
                        break;
                    case 7: // 開始日のみ
                        $start = $d(mt_rand(-3, 10));
                        break;
                    default:
                        $start = $d(mt_rand(-20, 10));
                        $due = $d(mt_rand(11, 30));
                        $status = mt_rand(0, 2) === 0 ? 'doing' : 'todo';
                }

                // 担当：未割当・単独・複数担当を混ぜる
                $pool = $assigneePool[$projectId];
                $assignees = match ($count % 4) {
                    0 => [],
                    1, 2 => [$pool[mt_rand(0, count($pool) - 1)]],
                    default => array_values(array_unique([$pool[0], $pool[mt_rand(1, count($pool) - 1)]])),
                };

                $task = $this->createTask->handle($creator, [
                    'title' => $title,
                    'description' => "{$title}についての作業メモ（デモデータ）。",
                    'project_id' => $projectId ?: null,
                    'status' => $status,
                    'start_date' => $start,
                    'due_date' => $due,
                    'assignee_ids' => $assignees,
                    'visibility' => 'shared',
                ]);

                // 履歴用に一部を更新しておく
                if ($count % 7 === 0) {
                    $this->updateTask->handle($creator, $task->id, ['description' => "{$title}：先方確認済み（デモの更新履歴）。"], $task->revision);
                }
                $count++;
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
            [$president, '人事評価メモ（非公開）'],
            [$sales, '個人の営業目標の整理（非公開）'],
            [$sales, '見込み客メモ（非公開）'],
            [$engineer, '資格試験の勉強（非公開）'],
            [$engineer, '技術調査の個人メモ（非公開）'],
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

        // アーカイブ案件のタスク（通常表示から除外）
        $this->createTask->handle($president, ['title' => '旧ポータルの停止連絡', 'project_id' => $e->id, 'status' => 'done']);

        // 文書
        $docs = [
            [$a, 'サイト構成と担当', "# サイト構成\n- トップ\n- 会社概要\n- サービス\n- 採用\n\n更新担当は営業、実装はエンジニアと外注1。"],
            [$a, '原稿の書き方ルール', "1. です・ます調で統一\n2. 数字は半角\n3. 固有名詞は正式名称"],
            [$b, '移行作業の手順（初稿）', "## 手順\n1. バックアップ取得\n2. 書込み停止\n3. 移行\n4. 照合\n5. 切替"],
            [$b, 'テスト環境の接続情報について', '接続情報は別途管理者から共有します（この文書には記載しません）。'],
            [$c, '展示会当日の動き', "09:00 搬入\n10:00 開場\n17:00 撤収"],
            [$dd, 'パッケージ要件', "サイズ：W120×H180\n素材：再生紙\n表示：原材料・内容量・販売者"],
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
            [-21, $b, '要件定義レビュー', [$engineer, $president, $c3]],
            [-14, $a, 'デザイン確認', [$sales, $c1, $c2]],
            [-14, $c, '展示会 準備会議', [$sales, $president]],
            [-7, $dd, 'パッケージ案 社内検討', [$sales, $engineer]],
            [-3, $b, '移行方式の決定', [$engineer, $c3]],
            [0, $a, '公開スケジュール確認', [$president, $sales, $c1]],
            [0, null, '全体朝会', [$president, $sales, $engineer]],
            [7, $c, '展示会 最終確認', [$sales, $president]],
            [14, $b, '受入テスト計画レビュー', [$engineer, $president, $c3]],
            [21, $dd, '試作品評価会', [$sales, $engineer]],
            [28, $a, '公開後ふりかえり', [$president, $sales, $c1, $c2]],
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

        $this->projects->update($president, $e->id, ['archived' => true], Project::query()->findOrFail($e->id)->revision);
    }
}

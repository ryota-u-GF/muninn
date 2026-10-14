# 業務アプリ（Laravel 再構築版）

Golden Field 社内向けの単一会社用業務アプリ。共有タスク・スケジュール・文書／議事録・変更履歴・外注の案件別権限・AI候補の確認フロー・TV 常時表示を 1 つにまとめたもの。
「GF社内業務アプリ Laravel再構築仕様書 v1.0（2026-10-07）」を正として新規に実装した。

> **状態：開発用デモとして動作確認済み。実運用は未開始。**
> Google ログイン・Google カレンダー・MCP/LLM は「接続準備のみ」で、実アカウントでの検証はしていない。実データ移行・本番公開も未実施（別途承認が必要）。

---

## 1. 構成

| 項目         | 内容                                                                                    |
| ------------ | --------------------------------------------------------------------------------------- |
| バックエンド | Laravel 13.35（PHP 8.3 以上。開発コンテナは PHP 8.5）                                   |
| 画面         | React 19 + TypeScript + Inertia 3（Laravel 公式 React Starter Kit `dev-main` から作成） |
| ビルド       | Vite 8（vite-plus）、Tailwind CSS 4                                                     |
| DB           | MySQL 8.4（本番想定と同じ種類）。単体テストは SQLite（インメモリ）と MySQL の両方で実行 |
| 認証         | セッション Cookie + CSRF。本番は Google（Socialite）、開発は環境限定のデモログイン      |
| 依存の固定   | `composer.lock` / `package-lock.json`                                                   |

Starter Kit からの主な変更：Fortify（パスワード・2FA・パスキー・新規登録・パスワード再設定）と Wayfinder を撤去、外部フォント（Bunny Fonts）の読み込みを撤去。

### ディレクトリの要点

```
app/Support/Access.php           公開範囲・案件権限の判定を一元化（一覧・検索・件数・履歴・ID直指定すべてで使用）
app/Policies/*                   Task / Document / Project の Policy
app/Http/Requests/*              FormRequest（形式検証）
app/Http/Resources/*             API Resource（返却項目の許可リスト。TV は TvBoardResource）
app/Actions/*                    書き込み処理（トランザクション・条件付き更新・履歴記録）
app/Services/VersionedWriter.php expected_revision による行ロック＋条件付き UPDATE（不一致は 409）
app/Services/HistoryQuery.php    履歴の閲覧範囲（現在のアクセス＋各版の案件・公開区分）
app/Models/Revision.php          追記専用の履歴・監査（アプリからの更新・削除は例外）
resources/js/lib/tv.ts           TV の時刻・通知・休憩ロジック（純粋関数、注入時計）
tests/Feature, tests/js, tests/e2e, tests/concurrency  各種試験
```

---

## 2. 起動手順（開発）

前提：Docker（Docker Desktop など）。PHP / Composer / Node はコンテナ内のものを使う。

```bash
cp .env.example .env
# .env の DB_PASSWORD に開発用の任意の値を設定し、デモを使う場合は GF_DEMO_LOGIN=true にする

# 依存のインストール（初回のみ。vendor が無いと compose.yaml のビルド定義を読めないため一時コンテナで実行）
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app laravelsail/php84-composer composer install

docker compose up -d                       # app（:8080）と mysql（:3307）
docker compose exec -u sail laravel.test php artisan key:generate
docker compose exec -u sail laravel.test npm ci
docker compose exec -u sail laravel.test npm run build
docker compose exec -u sail laravel.test php artisan migrate:fresh --seed   # デモデータ（local/testing のみ）
```

ブラウザで http://localhost:8080 を開き、ログイン画面の「開発用デモログイン」から利用者を選ぶ。
画面を編集しながら確認する場合は `docker compose exec -u sail laravel.test npm run dev`（Vite :5174）。

### 初期管理者・利用者の管理（本番を含む）

最初にログインした人を自動で管理者にすることはない。管理者は明示的にコマンドで作る。

```bash
php artisan gf:user admin@your-company.example --name="管理者名" --role=admin
php artisan gf:user staff@your-company.example --role=employee      # 役割変更にも使える
php artisan gf:user-deactivate staff@your-company.example           # 無効化（既存セッションも失効）
```

管理者は画面「権限・利用者」からも、利用者の登録・役割変更・無効化と外注の案件別権限を操作できる。

### デモアカウント（すべて example.test、パスワードなし）

| 表示名                                      | 役割   | 権限                         |
| ------------------------------------------- | ------ | ---------------------------- |
| 山田 太郎（社長）                           | 管理者 | 全共有情報、外注権限の管理   |
| 佐藤 花子（営業） / 鈴木 一郎（エンジニア） | 社員   | 全共有情報                   |
| 外注1 高橋                                  | 外注   | 案件「ウェブサイト刷新」編集 |
| 外注2 田中                                  | 外注   | 案件「ウェブサイト刷新」閲覧 |
| 外注3 伊藤                                  | 外注   | 案件「社内システム更改」編集 |
| 会議室TV                                    | TV専用 | TV 画面のみ                  |

デモデータは「今日」を基準に生成：有効案件 4＋アーカイブ 1、共有タスク 82 件（過去1か月〜未来1か月、未着手／進行中／完了／期限超過／未割当／複数担当／日付未設定／古い完了）、本人の非公開タスク、非公開→共有に切り替えたタスク、文書 8 件、議事録 12 件（同日に 2 件を含む）、更新履歴。

---

## 3. 試験

```bash
docker compose exec -u sail laravel.test php artisan test          # PHP Feature/Unit（SQLite インメモリ）
docker compose exec -u sail laravel.test composer test:mysql       # 同じ試験を MySQL（testing DB）で
docker compose exec -u sail laravel.test npx tsc --noEmit          # TypeScript 型確認
docker compose exec -u sail laravel.test npm test                  # Vitest（TV ロジック・スケジュール・並び順）
docker compose exec -u sail laravel.test npm run build             # production build
docker compose exec -u sail laravel.test ./vendor/bin/phpstan analyse --memory-limit=1G
# ブラウザ試験（Playwright / Chromium）。初回のみブラウザを root で導入：
docker compose exec laravel.test bash -lc 'PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright npx playwright install --with-deps chromium'
docker compose exec -u sail -e PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright laravel.test npx playwright test
# 実サーバーへの同時リクエスト試験（MySQL、複数ワーカー）
docker compose exec -u sail laravel.test composer test:concurrency
```

> ブラウザ試験と同時リクエスト試験は **開発用 DB をデモデータで作り直す**（`migrate:fresh --seed`）。本番 DB では実行しないこと（production では Seeder と破壊的コマンドが拒否される）。

TV の時刻は注入時計で試験している（Vitest は時刻を引数で渡し、Playwright は `page.clock` でページ内の時計だけを置き換える）。端末・サーバーの時刻は変更しない。

---

## 4. 画面一覧

| 画面                                                                                              | URL             | 利用者               |
| ------------------------------------------------------------------------------------------------- | --------------- | -------------------- |
| ログイン（Google 未接続表示／開発用デモ）                                                         | `/login`        | 全員                 |
| タスクボード（案件別 3 列、追加・詳細編集・検索・担当者／案件絞込・ドラッグ・古い完了の表示切替） | `/`             | 管理者・社員・外注   |
| 今日（自分の期限超過・本日期限・進行中）                                                          | `/today`        | 同上                 |
| 自分のタスク（担当＋自分の非公開）                                                                | `/my-tasks`     | 同上                 |
| 担当者一覧（人別・未割当）                                                                        | `/people`       | 同上                 |
| スケジュール（案件別／人別、日／週、期間・担当者・完了、今日へ、棒から編集、縦横スクロール）      | `/schedule`     | 同上                 |
| 文書（一覧・検索・編集・テンプレート・.txt/.md 取込・履歴）                                       | `/documents`    | 同上                 |
| 議事録（一覧／月カレンダー、会議日・参加者・案件・本文検索・日付絞込）                            | `/minutes`      | 同上                 |
| 変更履歴（閲覧可能な変更の一覧、版のプレビュー、確認して復元）                                    | `/history`      | 同上                 |
| 案件（作成・名称変更・並び順・アーカイブ）                                                        | `/projects`     | 管理者・社員         |
| AI候補（本人のみ：合成デモ／手動JSON → 候補 → 選択 → 最終確認）                                   | `/imports`      | 管理者・社員         |
| TV 表示（全画面）                                                                                 | `/tv`           | TV専用・管理者・社員 |
| 権限・利用者（外注の案件別 view/edit/取消、利用者の役割・有効化）                                 | `/admin/access` | 管理者               |

すべての画面で、保存中・成功・エラー・空状態を表示し、未保存の編集から離れるときは確認する。API エラー・409 のときも入力内容は消さない。

---

## 5. 権限（UI で隠すだけでなく全 API で制限）

- **管理者も他人の非公開タスク・文書を閲覧できない**（一覧・検索・件数・ID 直指定・履歴すべて）。
- 非公開タスクの担当者は作成者本人のみ。公開区分の変更は作成者のみ。
- 作成者・作成経路はサーバーが決める（リクエストの `owner_id`・`created_by`・`created_via` は無視）。
- **外注**：付与された有効（非アーカイブ）案件の共有タスク・共有文書・議事録・許可された履歴のみ。会社共通・他案件・非公開・TV・AI 候補・権限管理・案件管理は不可。
    - 閲覧外注：書き込みはすべて 403。
    - 編集外注：担当案件の共有タスク／文書の作成・編集のみ。案件・公開区分・担当者・情報源の変更、削除、復元、案件編集は 403。同一案件内の状態ドラッグは成功する。
    - メンバー一覧は自分と閲覧できるタスクの担当者だけ。他人のメール、タスクの情報源 URL・種類、作成経路、作成者 ID は返さない。履歴の操作者名も範囲外は「社内メンバー」と表示。
- **TV 専用**：`/tv` と `GET /api/tv` とログアウトのみ。その他の画面は `/tv` へ、API は 403。
- **履歴**：対象を「現在」閲覧できること＋その版（after）の案件・公開区分を閲覧できること。変更前（before）が閲覧範囲外なら伏せる（公開前の非公開内容を漏らさない）。外注は削除済みの履歴を見られない。案件・権限・利用者の監査は管理者のみ（案件の履歴は社員も可）。
- 閲覧できない ID は存在を秘匿して 404、閲覧できるが操作できない場合は 403。
- 権限取消・役割変更・無効化は次のリクエストから即時に反映（役割変更・無効化は `session_version` を進めて既存セッションを失効させ、DB セッションも削除）。

---

## 6. API 契約（すべて `/api`、セッション Cookie + `X-XSRF-TOKEN`、JSON）

共通の応答：未認証 401／権限違反 403／存在秘匿 404／検証 422（`errors`）／競合 409（`{"code":"conflict","current_revision":n}`）／回数制限 429。
すべての変更・削除・復元は `expected_revision` が必須。

| メソッド・パス                                                                                                                                          | 内容                                                                                                                          |
| ------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `GET /members`                                                                                                                                          | 担当者候補（外注には限定版）                                                                                                  |
| `GET /projects` `?include_archived=1`                                                                                                                   | 案件一覧（外注は付与分のみ）                                                                                                  |
| `POST /projects` `{name}` / `PATCH /projects/{id}` `{name?, sort_order?, archived?, expected_revision}`                                                 | 案件の作成・名称変更・並び順・アーカイブ（社内）                                                                              |
| `GET /tasks` `?q&project_id(数値                                                                                                                        | none)&assignee_id(数値                                                                                                        | me                                                 | none)&status&mine=1&include_old_done=1&include_archived=1` | タスク一覧。完了後 7 日を過ぎたものは `include_old_done` で表示 |
| `GET /tasks/{id}`                                                                                                                                       | 詳細                                                                                                                          |
| `POST /tasks` `{title, description?, status?, visibility?, project_id?, start_date?, due_date?, assignee_ids?, sort_order?, source_type?, source_url?}` | 作成                                                                                                                          |
| `PATCH /tasks/{id}` `{…変更項目, expected_revision}`                                                                                                    | 更新（送った項目のみ）                                                                                                        |
| `DELETE /tasks/{id}` `{expected_revision}`                                                                                                              | ソフト削除                                                                                                                    |
| `GET /documents` `?kind(document                                                                                                                        | minutes)&project_id&q&from&to&date`                                                                                           | 一覧（本文は抜粋のみ。q はタイトル・本文・参加者） |
| `GET/POST /documents`、`PATCH/DELETE /documents/{id}`                                                                                                   | 文書・議事録（議事録は `meeting_date` 必須）                                                                                  |
| `POST /documents/parse-file`（multipart `file`）                                                                                                        | UTF-8 の .txt/.md（400KB 以下・100,000 文字以内）を検証して本文を返す（保存しない）                                           |
| `GET /history` `?type&page`                                                                                                                             | 全体の履歴（50 件／ページ、最大 500 件まで表示）                                                                              |
| `GET /{tasks                                                                                                                                            | documents}/{id}/revisions` `?page`                                                                                            | 個別の履歴（最大 200 件まで表示）                  |
| `GET /{tasks                                                                                                                                            | documents}/{id}/revisions/{version}`                                                                                          | 版のプレビュー（before / after）                   |
| `POST /{tasks                                                                                                                                           | documents}/{id}/restore` `{version, expected_revision}`                                                                       | 版の内容で新しい版を作成（削除済みも復元される）   |
| `GET /project-access` / `PUT /project-access` `{user_id, project_id, permission(view                                                                    | edit                                                                                                                          | none), expected_permission}`                       | 外注の案件別権限（管理者、監査記録あり）                   |
| `GET /users` / `POST /users` / `PATCH /users/{id}` `{role?, is_active?, name?, expected_revision}`                                                      | 利用者管理（管理者）                                                                                                          |
| `GET /imports`                                                                                                                                          | 本人の候補（未確認＋直近 14 日の処理済み）と合成デモ一覧                                                                      |
| `POST /imports` `{json}`                                                                                                                                | 手動 JSON（候補の配列、または `{"candidates":[…]}`）                                                                          |
| `POST /imports/demo` `{sample: email                                                                                                                    | minutes                                                                                                                       | voice}`                                            | 合成デモから候補作成（実際の AI 推論ではない）             |
| `PATCH /imports/{id}` / `POST /imports/{id}/reject`                                                                                                     | 候補の編集・却下（却下で本文削除）                                                                                            |
| `POST /imports/confirm` `{idempotency_key, items:[{id, expected_revision}]}`                                                                            | 選択分を原子的に共有タスク化。同じキーの再送は保存済みの結果（`replayed: true`）                                              |
| `GET /tv`                                                                                                                                               | TV 用。`generated_at, today, users[id,name], projects[id,name], tasks[id,title,status,project_id,due_date,assignee_ids]` のみ |

回数制限：ログイン（IP 30 回／分・アカウント 10 回／分）、取り込み 30 回／分、ファイル取込 20 回／分、API 全体 600 回／分。

---

## 7. 整合性の実装

- 変更・削除・復元：トランザクション内で行を `SELECT … FOR UPDATE` → `expected_revision` を確認 → `UPDATE … WHERE revision = ?`（影響 0 行なら 409）→ 同じトランザクションで履歴を追記。状態と履歴の片方だけが保存されることはない。
- 履歴は `revisions` に追記のみ（`(entity_type, entity_id, version)` 一意）。表示件数の制限で削除しない。DB 管理者による直接改ざんまで防ぐものではない。
- AI 候補の確定：`(owner_id, idempotency_key)` 一意。選択全体を 1 トランザクションで処理し、1 件でも処理済み・版不一致・不備があれば全体をロールバック。同一キーの同時送信による MySQL のデッドロックは再試行し、保存済みの結果を返す。
- ログ：DB エラーはバインド値（本文・タイトル）を含めずに記録。409 は記録しない。入力の本文はセッションに戻さない。

---

## 8. TV ダッシュボード

- Asia/Tokyo 基準（端末のタイムゾーンに依存しない）。初期値：平日、始業 08:30、昼休憩 12:00〜13:00、終業 17:30。設定は端末ごとに localStorage、HH:mm と時刻順を検証。
- 時刻画面（優先）：始業 08:30〜3 分／昼休憩／再開 13:00〜3 分／終業 17:30〜当日末／非稼働日は休日。テスト中はテスト画面が最優先。
- 通常 300 秒 → 暗い背景で動く時計とロゴの画面休憩 30 秒 → 通常。手動休憩と戻る操作。休憩中も取得・認証確認を継続。`prefers-reduced-motion` では動きを止める。
- 30 秒ごとに取得（15 秒でタイムアウト）。失敗時は古いタスクを消して「通信エラー」、401 は再ログイン案内。オンライン復帰・画面再表示で即時取得。
- カード：幅 760 未満または高さ 620 未満は 1 件、高さ 850 未満は 4 件、それ以外は 6 件。15 秒ごとにページ巡回。
- 通知音：「通知音を有効にする」を押した後だけ Web Audio のチャイム。境界（8:30／12:00／13:00／17:30）を通過したときに一度だけ。初回観測では鳴らさない、観測間隔 90 秒超（スリープ復帰）や境界から 60 秒以上経過では鳴らさない、時計の巻き戻しでも「日付＋種類」の発火済みキーで重複しない。
- 画面テスト：始業・昼休憩・再開・終業・休日・画面休憩の 6 ボタン、通常に戻る、5 分で自動終了。実時刻・時刻設定・本番の発火済みキーは変更しない。有効化済みの音だけテスト再生。設定パネルは非モーダルで、テスト中のバーは常に最前面に出るため重なって操作できなくなることはない。
- 焼き付き防止は保証しない。TV 本体の設定も運用で確認すること。祝日カレンダー連携はない。

---

## 9. 実施した検証（2026-10-07、開発コンテナ）

| 区分                                           | 結果                                                                         |
| ---------------------------------------------- | ---------------------------------------------------------------------------- |
| PHP Feature/Unit（SQLite）                     | 59 件成功                                                                    |
| PHP Feature/Unit（MySQL 8.4）                  | 59 件成功                                                                    |
| Vitest（TV ロジック・スケジュール・並び順）    | 21 件成功                                                                    |
| Playwright（Chromium、主要フロー 18 シナリオ） | 18 件成功                                                                    |
| 同時リクエスト（MySQL、8 並列 × 3 回）         | 同時更新は 200×1／409×7、履歴 +1、版 +1。同一キーの同時確定でタスク 1 件のみ |
| TypeScript 型確認・production build・lint      | 成功                                                                         |

主な受入条件の対応：未ログイン API 401、無効ユーザー拒否、各役割のログイン、デモ認証の production 無効化、外注の別案件 ID 直指定・検索・件数・履歴・メンバーからの漏洩なし、閲覧外注の書込み拒否、編集外注の状態ドラッグ成功と案件／担当者変更・削除・復元の拒否、管理者が他人の非公開を読めない、権限取消直後のアクセス不可、2 ブラウザ同時編集で一方 409 と入力保持、履歴と状態の同時確定、議事録の会議日・参加者の復元と新版、公開前の非公開版の秘匿、スケジュールの縦横スクロール・複数担当・未設定日付・棒からの編集、AI 候補の本人限定・選択分のみ確定・再送で二重作成なし・一件の競合で全体ロールバック・却下で本文削除、TV の返却項目制限・6 画面・戻る・自動終了・通知音・一回通知・スリープ／巻き戻し・曜日・優先順位・通信失敗／401・画面サイズと巡回。

**未検証**：Google ログインの実接続（モックした Socialite での単体試験のみ）、実機 TV のスピーカー・ブラウザの自動再生制限、Chromium 以外のブラウザ、実データでの性能。

---

## 10. 設計判断の記録

- 参照元ソース（発注者 Mac のパス）はこの端末に存在しなかったため、**既存ソースは参照しておらず、仕様書のみに基づく独立実装**。既存 UI との見た目の差異は、ソース受領後に確認が必要。
- 文書の `project_id` は任意（会社共通文書を許可）。外注は会社共通を閲覧できない。
- 社員も案件の作成・名称変更・アーカイブが可能（仕様は「案件：作成…」で役割を限定していないため）。外注は不可。
- TV は完了タスクを表示しない（進行中／本日期限／期間中のうち未完了）。
- 「古い完了」は完了から 7 日超（`config/gf.php` で変更可）。
- 外注をタスクの担当者にできるのは、その案件の閲覧権限がある場合のみ。TV 専用アカウントは担当者にできない。
- 外注の新規タスクの担当者は「なし」か「自分」のみ。
- 復元は常に削除解除を伴う（削除状態の版への復元は行わない）。他人が作成者のタスクで公開区分が異なる版への復元は 403。
- 案件の並び順・名称変更・アーカイブにも `expected_revision` を適用。外注権限の変更は `expected_permission` で競合を検出。
- 開発サーバーは `php artisan serve`（複数ワーカー）。本番の Web サーバー構成は未決定。

---

## 11. 既知の制限

- Google ログイン・Google カレンダー（予定詳細／free-busy）・MCP・LLM は**未接続**（カレンダー・MCP は初版未実装、後続工程）。未接続を「空き」と扱う画面はない。
- AI 候補の入力は合成デモと手動 JSON のみ。Gmail・Drive・録音・自動解析は対象外。
- API トークンは発行していない（MCP 用トークンは後続工程で設計。外注・TV には発行しない方針）。
- 文書はプレーンテキストのみ（リッチブロック・共同カーソル・コメントは対象外）。PDF・Word の取込は対象外。
- スケジュールの日付ドラッグ変更は未実装（初版必須ではない）。
- 権限取消後、外注が開いたままの画面は次の API 呼び出しで 404/空になる（プッシュ通知はしない）。

---

## 12. 公開・移行に残る判断（別途承認が必要）

1. Google OAuth クライアント（Workspace の許可ドメイン、リダイレクト URI）の用意と実アカウントでの検証。
2. 本番インフラ・ドメイン・HTTPS・Web サーバー（php-fpm 等）・MySQL の運用、バックアップ、例外監視、ログ保存期間。
3. 実データ移行：ステージングで dry-run → 件数・公開区分・外注権限・担当者・並び順・日時・履歴の照合 → 切替前バックアップ・書込み停止・移行・検証・切替・失敗時の戻し方を決めてから実施。既存の `role` と `access_kind` の二軸を本アプリの 4 役割へ明示的に対応づける必要がある。セッション・トークン・秘密設定は移行しない。既存に無い過去履歴は生成しない。
4. Google カレンダー・MCP/LLM の接続方針（送信内容・課金・保存方針、refresh token の暗号化保存）。

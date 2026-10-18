<?php

return [
    /*
    | 開発限定のデモログイン。production 環境では値に関わらず常に無効（App\Support\DemoMode）。
    */
    'demo_login' => (bool) env('GF_DEMO_LOGIN', false),

    /*
    | Google ログインで自動作成を許可する会社ドメイン（カンマ区切り）。
    | 空の場合、事前登録済みユーザーのみログインできる。自動作成は常に「社員」で、管理者にはしない。
    */
    'google_allowed_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GF_GOOGLE_ALLOWED_DOMAINS', '')),
    ))),

    'limits' => [
        'title' => 300,
        'task_description' => 10000,
        'document_body' => 100000,
        'participants' => 2000,
        'upload_bytes' => 400 * 1024,
        'project_name' => 100,
        'import_items' => 100,
    ],

    // AI（Claude）連携。API キーは利用者ごとに登録する。
    'ai' => [
        'default_model' => 'claude-haiku-5-5',
        // 画面で選べるモデル（id => 表示名）
        'models' => [
            'claude-haiku-5-5' => 'Haiku 5.5（軽い・低コスト／既定）',
            'claude-sonnet-5-5' => 'Sonnet 5.5（標準）',
            'claude-opus-5-5' => 'Opus 5.5（高精度・高コスト）',
        ],
        // 1 回に AI へ送る文章の最大文字数
        'max_text_chars' => 20000,
    ],

    // Gmail 連携（読み取り専用）
    'gmail' => [
        'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
        // 1 回の取得で処理するメールの最大数（AI の呼び出し回数を抑える）
        'max_per_sync' => 5,
        // 1 回に確認するメールの最大数（新しい順）
        'list_size' => 100,
        'initial_days_options' => [3, 7, 30],
        // アプリを開いている間の自動取得の間隔（分）。画面で選べる値と、既定値。
        'sync_interval_options' => [5, 10, 30],
        'default_sync_interval' => 10,
    ],

    'history' => [
        'global_limit' => 500,
        'entity_limit' => 200,
        'per_page' => 50,
    ],

    // 「古い完了タスク」とみなす日数（これより前に完了したものは通常非表示）
    'done_hidden_after_days' => 7,

    'timezone' => 'Asia/Tokyo',
];

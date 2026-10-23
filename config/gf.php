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

    // 外部システム（メール・議事録を溜めて AI で判定する側）からの候補の受け取り。トークンが空なら無効。
    'inbound' => [
        'token' => env('GF_INBOUND_TOKEN', ''),
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

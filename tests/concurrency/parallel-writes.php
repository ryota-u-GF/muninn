<?php

/**
 * 実サーバー（複数ワーカー）に同時リクエストを送り、MySQL 上で
 *  1) 同じ expected_revision の同時更新は 1 件だけ成功し、残りは 409
 *  2) 同じ idempotency_key の同時確定は 1 回分しかタスクを作らない
 * ことを確認する。開発用DB（デモデータ）を使う。
 *
 * 実行：docker compose exec -u sail laravel.test php tests/concurrency/parallel-writes.php
 */
$base = getenv('E2E_BASE_URL') ?: 'http://localhost';
$parallel = 8;

function session(string $base, int $userId): array
{
    $jar = tempnam(sys_get_temp_dir(), 'gf');
    $ch = curl_init("{$base}/login");
    curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true]);
    curl_exec($ch);
    unset($ch); // クッキーを書き出す
    $token = xsrf($jar);
    $ch = curl_init("{$base}/demo-login");
    curl_setopt_array($ch, [
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['user_id' => $userId]),
        CURLOPT_HTTPHEADER => ['X-XSRF-TOKEN: '.$token, 'Accept: text/html'],
    ]);
    curl_exec($ch);
    unset($ch);

    return [$jar, xsrf($jar)];
}

function xsrf(string $jar): string
{
    foreach (file($jar) as $line) {
        $cols = explode("\t", trim($line));
        if (count($cols) >= 7 && $cols[5] === 'XSRF-TOKEN') {
            return urldecode($cols[6]);
        }
    }
    throw new RuntimeException('XSRF token not found');
}

function request(string $base, array $s, string $method, string $path, ?array $body = null): CurlHandle
{
    $ch = curl_init($base.$path);
    curl_setopt_array($ch, [
        CURLOPT_COOKIEFILE => $s[0], CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-XSRF-TOKEN: '.$s[1]],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    return $ch;
}

function run(string $base, array $s, string $method, string $path, ?array $body = null): array
{
    $ch = request($base, $s, $method, $path, $body);
    $out = curl_exec($ch);

    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) $out, true)];
}

/** @param list<CurlHandle> $handles */
function parallel(array $handles): array
{
    $mh = curl_multi_init();
    foreach ($handles as $h) {
        curl_multi_add_handle($mh, $h);
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    return array_map(fn ($h) => curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
}

$failures = 0;
$check = function (bool $ok, string $label) use (&$failures) {
    echo ($ok ? '[OK] ' : '[NG] ').$label.PHP_EOL;
    $failures += $ok ? 0 : 1;
};

// 社員（佐藤）でログイン（デモの id=2）
$s = session($base, 2);
[$code, $list] = run($base, $s, 'GET', '/api/tasks');
$check($code === 200, "ログインしてタスク一覧を取得 ({$code})");
$task = $list['data'][0];

[, $before] = run($base, $s, 'GET', "/api/tasks/{$task['id']}/revisions");
$handles = [];
for ($i = 0; $i < $parallel; $i++) {
    $handles[] = request($base, $s, 'PATCH', "/api/tasks/{$task['id']}", ['title' => "同時更新{$i}", 'expected_revision' => $task['revision']]);
}
$codes = parallel($handles);
$counts = array_count_values($codes);
$check(($counts[200] ?? 0) === 1 && ($counts[409] ?? 0) === $parallel - 1, "同時更新 {$parallel} 件：200×".($counts[200] ?? 0).' / 409×'.($counts[409] ?? 0));
[, $after] = run($base, $s, 'GET', "/api/tasks/{$task['id']}/revisions");
$check($after['meta']['total'] === $before['meta']['total'] + 1, "履歴は 1 件だけ増加（{$before['meta']['total']}→{$after['meta']['total']}）");
[, $fresh] = run($base, $s, 'GET', "/api/tasks/{$task['id']}");
$check($fresh['data']['revision'] === $task['revision'] + 1, "版は 1 つだけ進む（{$task['revision']}→{$fresh['data']['revision']}）");

// 同じ idempotency_key の同時確定
[$code, $created] = run($base, $s, 'POST', '/api/imports', ['json' => json_encode([['title' => '同時確定テスト'.bin2hex(random_bytes(3))]])]);
$candidate = $created['data'][0];
$key = 'parallel-'.bin2hex(random_bytes(6));
$handles = [];
for ($i = 0; $i < $parallel; $i++) {
    $handles[] = request($base, $s, 'POST', '/api/imports/confirm', ['idempotency_key' => $key, 'items' => [['id' => $candidate['id'], 'expected_revision' => $candidate['revision']]]]);
}
$codes = parallel($handles);
if (! in_array(201, $codes, true)) {
    echo '  応答例: '.substr((string) curl_multi_getcontent($handles[0]), 0, 300).PHP_EOL;
}
$ok = array_filter($codes, fn ($c) => in_array($c, [200, 201, 409], true));
$check(count($ok) === $parallel && in_array(201, $codes, true), '同時確定のステータス：'.implode(',', $codes));
[, $search] = run($base, $s, 'GET', '/api/tasks?q='.urlencode($candidate['payload']['title']));
$check(count($search['data']) === 1, '同じキーの同時確定で作成されたタスクは 1 件（'.count($search['data']).' 件）');

echo $failures === 0 ? "すべて成功\n" : "{$failures} 件失敗\n";
exit($failures === 0 ? 0 : 1);

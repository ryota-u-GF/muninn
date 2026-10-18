<?php

namespace App\Services\Gmail;

use App\Models\GmailConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gmail API（読み取り専用）の薄いラッパー。アクセストークンは保存せず、毎回、更新トークンから取得する。
 */
class GmailClient
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /** 更新トークンからアクセストークンを取得する。失効していたら再連携を促す。 */
    public function accessToken(GmailConnection $connection): string
    {
        $response = $this->send(fn () => Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]));

        if ($response->status() === 400 || $response->status() === 401) {
            throw new GmailException('Gmailの連携が無効になりました。もう一度「Gmailを連携する」を押してください。', true);
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new GmailException('Googleから認証情報を取得できませんでした。しばらくしてからやり直してください。');
        }

        return (string) $response->json('access_token');
    }

    /**
     * 条件に合うメールの ID を新しい順に返す。
     *
     * @return list<string>
     */
    public function listMessageIds(string $accessToken, string $query, int $max): array
    {
        $response = $this->send(fn () => $this->api($accessToken)->get(self::API.'/messages', [
            'q' => $query,
            'maxResults' => $max,
        ]));
        $this->assertOk($response);

        $ids = [];
        foreach ((array) $response->json('messages', []) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * メール 1 通を取得して、件名・差出人・日時・本文（テキスト）にまとめる。
     *
     * @return array{id: string, subject: string, sender: string, received_at: int|null, text: string}
     */
    public function getMessage(string $accessToken, string $id): array
    {
        $response = $this->send(fn () => $this->api($accessToken)->get(self::API.'/messages/'.rawurlencode($id), ['format' => 'full']));
        $this->assertOk($response);

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        return self::summarize($data);
    }

    /** 連携解除時に、Google 側でもトークンを無効にする（失敗しても解除は続ける）。 */
    public function revoke(string $refreshToken): void
    {
        try {
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $refreshToken]);
        } catch (ConnectionException) {
            // Google に届かなくても、こちらの保存分は削除する。
        }
    }

    /**
     * Gmail API のメール（format=full）から、必要な項目だけを取り出す。
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string, subject: string, sender: string, received_at: int|null, text: string}
     */
    public static function summarize(array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $headers = [];
        foreach ((array) ($payload['headers'] ?? []) as $header) {
            if (is_array($header) && isset($header['name'], $header['value'])) {
                $headers[strtolower((string) $header['name'])] = (string) $header['value'];
            }
        }

        $internalDate = $data['internalDate'] ?? null;

        return [
            'id' => (string) ($data['id'] ?? ''),
            'subject' => mb_substr(self::decodeHeader($headers['subject'] ?? ''), 0, 500),
            'sender' => mb_substr(self::decodeHeader($headers['from'] ?? ''), 0, 255),
            'received_at' => is_numeric($internalDate) ? intdiv((int) $internalDate, 1000) : null,
            'text' => self::bodyText($payload),
        ];
    }

    /**
     * 本文は text/plain を優先し、無ければ HTML のタグを除いて使う。
     *
     * @param  array<string, mixed>  $part
     */
    private static function bodyText(array $part): string
    {
        $plain = self::findPart($part, 'text/plain');
        if ($plain !== null && trim($plain) !== '') {
            return trim($plain);
        }
        $html = self::findPart($part, 'text/html');
        if ($html === null) {
            return '';
        }
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</p>|</div>|</tr>|</li>#i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param array<string, mixed> $part */
    private static function findPart(array $part, string $mime): ?string
    {
        if (($part['mimeType'] ?? null) === $mime && is_string($part['body']['data'] ?? null)) {
            $decoded = base64_decode(strtr($part['body']['data'], '-_', '+/'), true);

            return $decoded === false ? null : $decoded;
        }
        foreach ((array) ($part['parts'] ?? []) as $child) {
            if (is_array($child) && ($found = self::findPart($child, $mime)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** =?UTF-8?B?...?= 形式のヘッダーを読める文字にする。 */
    private static function decodeHeader(string $value): string
    {
        // エンコード済み（=?...?=）のときだけデコードする。生の UTF-8 はそのまま使う。
        $decoded = str_contains($value, '=?') ? mb_decode_mimeheader($value) : $value;

        return trim(preg_replace('/\s+/u', ' ', $decoded) ?? $decoded);
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(20);
    }

    /** @param callable(): Response $request */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new GmailException('Googleに接続できませんでした。ネットワークを確認してやり直してください。');
        }
    }

    private function assertOk(Response $response): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new GmailException('Gmailを読み取る権限がありません。もう一度「Gmailを連携する」を押してください。', true);
        }
        if ($response->status() === 429) {
            throw new GmailException('Googleの利用回数の上限に達しました。しばらくしてからやり直してください。');
        }
        if (! $response->successful()) {
            throw new GmailException('Gmailからメールを取得できませんでした。しばらくしてからやり直してください。');
        }
    }
}

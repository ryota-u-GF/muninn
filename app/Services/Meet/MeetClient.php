<?php

namespace App\Services\Meet;

use App\Models\MeetConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google カレンダー・ドキュメントの API（読み取り専用）の薄いラッパー。
 * アクセストークンは保存せず、毎回、更新トークンから取得する。
 */
class MeetClient
{
    private const DOC_MIME = 'application/vnd.google-apps.document';

    /** 更新トークンからアクセストークンを取得する。失効していたら再連携を促す。 */
    public function accessToken(MeetConnection $connection): string
    {
        $response = $this->send(fn () => Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]));

        if ($response->status() === 400 || $response->status() === 401) {
            throw new MeetException('Meetのメモの連携が無効になりました。もう一度「Meetのメモを連携する」を押してください。', true);
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new MeetException('Googleから認証情報を取得できませんでした。しばらくしてからやり直してください。');
        }

        return (string) $response->json('access_token');
    }

    /**
     * 指定の期間に終わった予定のうち、メモ（タイトルに $keywords のどれかを含む Google ドキュメント）が
     * 添付されているものを、古い順に返す。
     *
     * @param  list<string>  $keywords  空のときは、添付の Google ドキュメントをすべて対象にする
     * @return list<array{file_id: string, doc_title: string, event_title: string, organizer: string, attendees: list<string>, start: int|null}>
     */
    public function listNoteDocuments(string $accessToken, CarbonImmutable $from, CarbonImmutable $to, array $keywords, int $max): array
    {
        $response = $this->send(fn () => Http::withToken($accessToken)->acceptJson()->timeout(20)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                'timeMin' => $from->toRfc3339String(),
                'timeMax' => $to->toRfc3339String(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => $max,
                'fields' => 'items(id,summary,start,end,organizer,attendees(displayName,email),attachments(fileId,title,mimeType))',
            ]));
        $this->assertOk($response);

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        return self::notesFromEvents((array) ($data['items'] ?? []), $to, $keywords);
    }

    /**
     * 予定の一覧から、メモのドキュメントを取り出す（終わっていない予定は除く）。
     *
     * @param  array<mixed>  $events
     * @param  list<string>  $keywords
     * @return list<array{file_id: string, doc_title: string, event_title: string, organizer: string, attendees: list<string>, start: int|null}>
     */
    public static function notesFromEvents(array $events, CarbonImmutable $now, array $keywords): array
    {
        $notes = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $end = self::timestamp($event['end'] ?? null);
            if ($end === null || $end > $now->getTimestamp()) {
                continue;
            }

            $attendees = [];
            foreach ((array) ($event['attendees'] ?? []) as $a) {
                $label = is_array($a) ? ($a['displayName'] ?? $a['email'] ?? '') : '';
                if (is_string($label) && $label !== '') {
                    $attendees[] = $label;
                }
            }
            $organizer = is_array($event['organizer'] ?? null) ? (string) ($event['organizer']['displayName'] ?? $event['organizer']['email'] ?? '') : '';

            foreach ((array) ($event['attachments'] ?? []) as $attachment) {
                if (! is_array($attachment) || ($attachment['mimeType'] ?? null) !== self::DOC_MIME || ! is_string($attachment['fileId'] ?? null)) {
                    continue;
                }
                $title = (string) ($attachment['title'] ?? '');
                if ($keywords !== [] && ! self::titleMatches($title, $keywords)) {
                    continue;
                }
                $notes[] = [
                    'file_id' => $attachment['fileId'],
                    'doc_title' => mb_substr($title, 0, 500),
                    'event_title' => mb_substr((string) ($event['summary'] ?? ''), 0, 500),
                    'organizer' => mb_substr($organizer, 0, 255),
                    'attendees' => array_slice($attendees, 0, 30),
                    'start' => self::timestamp($event['start'] ?? null),
                ];
            }
        }

        return $notes;
    }

    /** メモのドキュメントの本文（テキスト）を取得する。タブがあるときは、最初のタブ（メモ）だけを読む。 */
    public function documentText(string $accessToken, string $fileId): string
    {
        $response = $this->send(fn () => Http::withToken($accessToken)->acceptJson()->timeout(20)
            ->get('https://docs.googleapis.com/v1/documents/'.rawurlencode($fileId)));
        $this->assertOk($response);

        /** @var array<string, mixed> $doc */
        $doc = (array) $response->json();

        return self::documentToText($doc);
    }

    /** 連携解除時に、Google 側でもトークンを無効にする（失敗しても解除は続ける）。 */
    public function revoke(string $refreshToken): void
    {
        try {
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $refreshToken]);
        } catch (Throwable) {
            // Google に届かなくても、こちらの保存分は削除する。
        }
    }

    /**
     * Docs API の文書から、本文のテキストを取り出す（表の中も含む）。
     *
     * @param  array<string, mixed>  $doc
     */
    public static function documentToText(array $doc): string
    {
        $body = is_array($doc['body'] ?? null) ? $doc['body'] : [];

        return trim(self::walk((array) ($body['content'] ?? [])));
    }

    /** @param array<mixed> $elements */
    private static function walk(array $elements): string
    {
        $text = '';
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            if (is_array($element['paragraph'] ?? null)) {
                foreach ((array) ($element['paragraph']['elements'] ?? []) as $part) {
                    if (is_array($part) && is_string($part['textRun']['content'] ?? null)) {
                        $text .= $part['textRun']['content'];
                    }
                }
            } elseif (is_array($element['table'] ?? null)) {
                foreach ((array) ($element['table']['tableRows'] ?? []) as $row) {
                    foreach ((array) ($row['tableCells'] ?? []) as $cell) {
                        $text .= self::walk((array) ($cell['content'] ?? []));
                    }
                }
            }
        }

        return $text;
    }

    /** @param list<string> $keywords */
    private static function titleMatches(string $title, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /** カレンダーの日時（dateTime／date）を UNIX 秒にする。 */
    private static function timestamp(mixed $value): ?int
    {
        if (! is_array($value)) {
            return null;
        }
        $raw = $value['dateTime'] ?? $value['date'] ?? null;
        if (! is_string($raw)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw, config('gf.timezone'))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param callable(): Response $request */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new MeetException('Googleに接続できませんでした。ネットワークを確認してやり直してください。');
        }
    }

    private function assertOk(Response $response): void
    {
        if ($response->status() === 401) {
            throw new MeetException('Googleの連携が無効になりました。もう一度「Meetのメモを連携する」を押してください。', true);
        }
        if ($response->status() === 403) {
            throw new MeetException('カレンダーまたはドキュメントを読み取る権限がありません。管理者が Google Cloud で Calendar API と Docs API を有効にしているか確認し、もう一度連携してください。', true);
        }
        if ($response->status() === 429) {
            throw new MeetException('Googleの利用回数の上限に達しました。しばらくしてからやり直してください。');
        }
        if (! $response->successful()) {
            throw new MeetException('Googleからデータを取得できませんでした。しばらくしてからやり直してください。');
        }
    }
}

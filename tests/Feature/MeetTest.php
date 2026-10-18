<?php

namespace Tests\Feature;

use App\Enums\ExtractKind;
use App\Models\AiSetting;
use App\Models\MailMessage;
use App\Models\MeetConnection;
use App\Models\User;
use App\Services\Ai\AiContentRejectedException;
use App\Services\Ai\TaskExtractor;
use App\Services\Meet\MeetClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class MeetTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    private const REFRESH = 'meet-refresh-token-secret-0123456789';

    private const DOC = 'application/vnd.google-apps.document';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.meet_redirect' => 'http://localhost/auth/meet/callback',
        ]);
    }

    private function connect(User $user, array $overrides = []): MeetConnection
    {
        return MeetConnection::query()->create($overrides + [
            'user_id' => $user->id,
            'google_email' => 'taro@example.test',
            'refresh_token' => self::REFRESH,
        ]);
    }

    private function withAiKey(User $user): void
    {
        AiSetting::query()->create(['user_id' => $user->id, 'api_key' => 'sk-ant-test-0123456789abcdefghij', 'model' => 'claude-haiku-5-5']);
    }

    /** 終わった会議（1 時間前に終了）。メモのドキュメントを添付する。 */
    private function event(string $id, string $docTitle = 'Notes by Gemini', string $mime = self::DOC, ?CarbonImmutable $end = null): array
    {
        $end ??= CarbonImmutable::now()->subHour();

        return [
            'id' => "ev-{$id}",
            'summary' => "定例会議 {$id}",
            'start' => ['dateTime' => $end->subHour()->toRfc3339String()],
            'end' => ['dateTime' => $end->toRfc3339String()],
            'organizer' => ['displayName' => '山田 太郎', 'email' => 'y@example.test'],
            'attendees' => [['displayName' => '山田 太郎'], ['email' => 'sato@example.test']],
            'attachments' => [['fileId' => "doc-{$id}", 'title' => "{$docTitle} {$id}", 'mimeType' => $mime]],
        ];
    }

    /** @param list<array<string, mixed>> $events */
    private function fakeGoogle(array $events, int $tokenStatus = 200): void
    {
        // 前に登録した偽の応答が優先されないよう、毎回、作り直す
        Http::swap(new HttpFactory);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(
                $tokenStatus === 200 ? ['access_token' => 'access-abc'] : ['error' => 'invalid_grant'],
                $tokenStatus,
            ),
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => $events]),
            'docs.googleapis.com/v1/documents/*' => function (Request $request) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));

                return Http::response(['body' => ['content' => [
                    ['paragraph' => ['elements' => [['textRun' => ['content' => "決定事項 {$id}：山田さんが見積書を送付する\n"]]]]],
                ]]]);
            },
        ]);
    }

    private function fakeExtractor(?string $rejectContaining = null, ?\stdClass $captured = null): void
    {
        $captured ??= new \stdClass;
        $this->app->bind(TaskExtractor::class, fn () => new class($rejectContaining, $captured) implements TaskExtractor
        {
            public function __construct(private ?string $reject, private \stdClass $captured) {}

            public function extract(User $user, string $text, ExtractKind $kind = ExtractKind::Mail): array
            {
                $this->captured->kind = $kind;
                $this->captured->text = $text;
                if ($this->reject !== null && str_contains($text, $this->reject)) {
                    throw new AiContentRejectedException('処理できません');
                }

                return [['title' => '見積書を送付する', 'description' => null, 'due_date' => null, 'project_id' => null, 'source_type' => 'minutes',
                    'assignee_ids' => [], 'unmatched_assignees' => []]];
            }

            public function verify(User $user): void {}
        });
    }

    public function test_settings_never_expose_or_store_the_refresh_token_in_plain_text(): void
    {
        $this->connect($this->employee);

        $res = $this->loginAs($this->employee)->getJson('/api/meet')->assertOk()
            ->assertJsonPath('data.connected', true)->assertJsonPath('data.oauth_ready', true)
            ->assertJsonPath('data.title_keywords', 'Gemini,メモ,議事録,Notes');
        $this->assertStringNotContainsString(self::REFRESH, $res->getContent());

        $raw = (string) DB::table('meet_connections')->where('user_id', $this->employee->id)->value('refresh_token');
        $this->assertStringNotContainsString(self::REFRESH, $raw);
        $this->assertSame(self::REFRESH, MeetConnection::query()->firstOrFail()->refresh_token);

        $this->loginAs($this->employee2)->getJson('/api/meet')->assertJsonPath('data.connected', false);
    }

    public function test_settings_can_be_updated_and_validated(): void
    {
        $this->connect($this->employee, ['last_synced_at' => now()]);
        $this->loginAs($this->employee);
        $ok = ['title_keywords' => 'Gemini、議事録', 'initial_days' => 7, 'auto_sync' => false, 'sync_interval_minutes' => 30];

        $this->putJson('/api/meet', $ok)->assertOk()
            ->assertJsonPath('data.title_keywords', 'Gemini、議事録')->assertJsonPath('data.auto_sync', false)
            ->assertJsonPath('data.sync_interval_minutes', 30)->assertJsonPath('data.last_synced_at', null);
        $this->assertSame(['Gemini', '議事録'], MeetConnection::query()->firstOrFail()->keywords());

        $this->putJson('/api/meet', ['title_keywords' => '', 'initial_days' => 3, 'auto_sync' => true, 'sync_interval_minutes' => 10])->assertOk(); // 空＝すべてのドキュメント
        $this->putJson('/api/meet', ['initial_days' => 365] + $ok)->assertJsonValidationErrors('initial_days');
        $this->putJson('/api/meet', ['sync_interval_minutes' => 1] + $ok)->assertJsonValidationErrors('sync_interval_minutes');
        $this->putJson('/api/meet', ['title_keywords' => str_repeat('あ', 201)] + $ok)->assertJsonValidationErrors('title_keywords');

        $this->loginAs($this->employee2)->putJson('/api/meet', $ok)->assertNotFound();
    }

    public function test_contractors_and_guests_cannot_use_meet(): void
    {
        $this->getJson('/api/meet')->assertUnauthorized();
        $this->loginAs($this->c1)->getJson('/api/meet')->assertForbidden();
        $this->postJson('/api/meet/sync')->assertForbidden();
        $this->get('/auth/meet/redirect')->assertForbidden();
    }

    public function test_disconnect_revokes_the_token_and_keeps_import_history(): void
    {
        $this->fakeGoogle([]);
        $connection = $this->connect($this->employee);
        MailMessage::query()->create(['owner_id' => $this->employee->id, 'kind' => 'minutes', 'external_id' => 'doc-1']);

        $this->loginAs($this->employee)->deleteJson('/api/meet')->assertOk()->assertJsonPath('data.connected', false);

        $this->assertNull(MeetConnection::query()->find($connection->id));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke') && $r['token'] === self::REFRESH);
        $this->assertSame(1, MailMessage::query()->count());
    }

    public function test_sync_reads_only_finished_meetings_with_note_documents(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle([
            $this->event('a'),                                                    // メモ → 対象
            $this->event('b', 'Agenda'),                                          // 議題メモではない → 除外
            $this->event('c', 'Notes by Gemini', 'application/pdf'),              // ドキュメントではない → 除外
            $this->event('d', '会議の議事録'),                                    // キーワード「議事録」 → 対象
            $this->event('e', 'Notes by Gemini', self::DOC, CarbonImmutable::now()->addHour()), // まだ終わっていない → 除外
        ]);
        $captured = new \stdClass;
        $this->fakeExtractor(null, $captured);

        $res = $this->loginAs($this->employee)->postJson('/api/meet/sync')->assertOk();
        $this->assertSame(2, $res->json('data.processed'));
        $this->assertSame(['doc-a', 'doc-d'], MailMessage::query()->orderBy('id')->pluck('external_id')->all());
        $this->assertSame(ExtractKind::Minutes, $captured->kind);
        // AI には、会議名・参加者（表示名がなければメール）とメモの本文を渡す
        $this->assertStringContainsString('会議名：定例会議 d', $captured->text);
        $this->assertStringContainsString('参加者：山田 太郎、sato@example.test', $captured->text);
        $this->assertStringContainsString('決定事項 doc-d', $captured->text);
    }

    public function test_sync_processes_in_batches_without_duplicates_and_keeps_candidates_private(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle(array_map(fn ($i) => $this->event((string) $i), range(1, 5)));
        $this->fakeExtractor();
        $this->loginAs($this->employee);

        $first = $this->postJson('/api/meet/sync')->assertOk();
        $this->assertSame(3, $first->json('data.processed'));
        $this->assertTrue($first->json('data.has_more'));
        $this->assertNull(MeetConnection::query()->firstOrFail()->last_synced_at);

        $second = $this->postJson('/api/meet/sync')->assertOk();
        $this->assertSame(2, $second->json('data.processed'));
        $this->assertFalse($second->json('data.has_more'));
        $this->assertNotNull(MeetConnection::query()->firstOrFail()->last_synced_at);

        $this->assertSame(0, $this->postJson('/api/meet/sync')->json('data.processed')); // 同じメモは二重に取り込まない
        $this->assertSame(5, MailMessage::query()->where('kind', 'minutes')->count());

        // 候補に、取り込み元（議事録）が付く。本文は保存しない。
        $candidate = $this->getJson('/api/imports')->assertOk()->json('data.0');
        $this->assertSame('meet', $candidate['source']);
        $this->assertSame('minutes', $candidate['mail']['kind']);
        $this->assertStringStartsWith('定例会議', $candidate['mail']['subject']);
        $this->assertSame('山田 太郎', $candidate['mail']['sender']);
        $this->assertStringContainsString('docs.google.com/document/d/doc-', $candidate['mail']['url']);
        $this->assertStringNotContainsString('決定事項', (string) json_encode(MailMessage::query()->get()->toArray(), JSON_UNESCAPED_UNICODE));

        // 他の人（管理者を含む）には見えない
        $this->loginAs($this->employee2)->getJson('/api/imports')->assertJsonCount(0, 'data');
        $this->loginAs($this->admin)->getJson('/api/imports')->assertJsonCount(0, 'data');
    }

    public function test_content_the_ai_rejects_is_skipped_and_does_not_block_the_rest(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle([$this->event('ok1'), $this->event('bad'), $this->event('ok2')]);
        $this->fakeExtractor('doc-bad');

        $res = $this->loginAs($this->employee)->postJson('/api/meet/sync')->assertOk();
        $this->assertSame(2, $res->json('data.processed'));
        $this->assertSame(1, $res->json('data.skipped'));
        $this->assertSame('skipped', MailMessage::query()->where('external_id', 'doc-bad')->firstOrFail()->status);

        $again = $this->postJson('/api/meet/sync')->assertOk();
        $this->assertSame(0, $again->json('data.processed') + $again->json('data.skipped'));
    }

    public function test_gmail_and_meet_records_do_not_get_mixed_up(): void
    {
        // 同じ ID でも、メールと議事録は別の記録として扱う
        MailMessage::query()->create(['owner_id' => $this->employee->id, 'kind' => 'mail', 'external_id' => 'doc-1']);
        $this->connect($this->employee);
        $this->fakeGoogle([$this->event('1')]);
        $this->fakeExtractor();

        $this->loginAs($this->employee)->postJson('/api/meet/sync')->assertOk()->assertJsonPath('data.processed', 1);
        $this->assertSame(['mail', 'minutes'], MailMessage::query()->orderBy('id')->pluck('kind')->all());

        // 議事録のほうは、2 回目は二重に取り込まない
        $this->postJson('/api/meet/sync')->assertOk()->assertJsonPath('data.processed', 0);
        $this->assertSame(2, MailMessage::query()->count());
    }

    public function test_sync_without_ai_key_stops_and_records_nothing(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle([$this->event('1')]);

        $this->loginAs($this->employee)->postJson('/api/meet/sync')->assertUnprocessable()->assertJsonValidationErrors('meet');
        $this->assertSame(0, MailMessage::query()->count());
        $this->assertStringContainsString('AI', (string) MeetConnection::query()->firstOrFail()->last_error);
    }

    public function test_revoked_google_access_asks_for_reconnection(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle([$this->event('1')], 400);
        $this->fakeExtractor();

        $res = $this->loginAs($this->employee)->postJson('/api/meet/sync')->assertUnprocessable();
        $this->assertStringNotContainsString(self::REFRESH, $res->getContent());
        $this->assertStringContainsString('連携', (string) $res->json('errors.meet.0'));
        $this->assertNotNull(MeetConnection::query()->firstOrFail()->last_error);
    }

    public function test_sync_requires_a_connection(): void
    {
        $this->loginAs($this->employee)->postJson('/api/meet/sync')->assertUnprocessable()->assertJsonValidationErrors('meet');
    }

    public function test_auto_sync_rules(): void
    {
        $this->withAiKey($this->employee);
        $this->fakeExtractor();
        $this->loginAs($this->employee);

        // 連携なし・オフ → 何もしない
        $this->fakeGoogle([$this->event('1')]);
        $this->postJson('/api/meet/sync', ['auto' => true])->assertJsonPath('data.status', 'disabled');
        $connection = $this->connect($this->employee, ['auto_sync' => false]);
        $this->postJson('/api/meet/sync', ['auto' => true])->assertJsonPath('data.status', 'disabled');
        Http::assertNothingSent();

        // 間隔（既定 10 分）が過ぎていなければ何もしない。手動は実行する。
        $connection->update(['auto_sync' => true, 'last_synced_at' => now()->subMinutes(5)]);
        $this->postJson('/api/meet/sync', ['auto' => true])->assertJsonPath('data.status', 'recent');
        Http::assertNothingSent();
        $this->postJson('/api/meet/sync')->assertOk()->assertJsonPath('data.status', 'done');

        // 間隔が過ぎていれば実行する
        $connection->update(['last_synced_at' => now()->subMinutes(30), 'last_error' => null]);
        $this->fakeGoogle([$this->event('2')]);
        $this->postJson('/api/meet/sync', ['auto' => true])->assertJsonPath('data.status', 'done')->assertJsonPath('data.processed', 1);
    }

    public function test_auto_sync_does_nothing_without_an_ai_key_and_waits_after_a_failure(): void
    {
        $this->connect($this->employee, ['last_synced_at' => now()->subHour()]);
        $this->fakeGoogle([$this->event('1')]);
        $this->loginAs($this->employee)->postJson('/api/meet/sync', ['auto' => true])->assertJsonPath('data.status', 'disabled');
        Http::assertNothingSent();

        $this->withAiKey($this->employee);
        $this->fakeGoogle([$this->event('1')], 400); // 連携が失効している
        $this->postJson('/api/meet/sync', ['auto' => true])->assertUnprocessable();

        Http::fake();
        $this->postJson('/api/meet/sync', ['auto' => true])->assertOk()->assertJsonPath('data.status', 'recent');
        Http::assertNothingSent();
    }

    public function test_concurrent_sync_for_the_same_user_is_not_run_twice(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle([$this->event('1')]);
        $this->fakeExtractor();

        $lock = Cache::lock('meet-sync:'.$this->employee->id, 30);
        $this->assertTrue($lock->get());
        try {
            $this->loginAs($this->employee)->postJson('/api/meet/sync')->assertOk()->assertJsonPath('data.status', 'busy');
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->postJson('/api/meet/sync')->assertOk()->assertJsonPath('data.status', 'done');
    }

    public function test_document_text_includes_paragraphs_and_tables(): void
    {
        $text = MeetClient::documentToText(['body' => ['content' => [
            ['paragraph' => ['elements' => [['textRun' => ['content' => "要約\n"]]]]],
            ['table' => ['tableRows' => [['tableCells' => [
                ['content' => [['paragraph' => ['elements' => [['textRun' => ['content' => "担当：佐藤\n"]]]]]]],
                ['content' => [['paragraph' => ['elements' => [['textRun' => ['content' => "期限：来週\n"]]]]]]],
            ]]]]],
            ['sectionBreak' => []],
        ]]]);

        $this->assertSame("要約\n担当：佐藤\n期限：来週", $text);
        $this->assertSame('', MeetClient::documentToText([]));
    }

    public function test_oauth_redirect_requests_read_only_scopes(): void
    {
        $res = $this->loginAs($this->employee)->get('/auth/meet/redirect');
        $res->assertRedirect();
        $url = (string) $res->headers->get('Location');
        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString(rawurlencode('https://www.googleapis.com/auth/calendar.events.readonly'), $url);
        $this->assertStringContainsString(rawurlencode('https://www.googleapis.com/auth/documents.readonly'), $url);
        foreach (['gmail', 'drive', 'calendar.events%20', 'documents%20', 'spreadsheets'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, str_replace('.readonly', '', $url));
        }
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
    }

    public function test_oauth_redirect_reports_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->loginAs($this->employee)->get('/auth/meet/redirect')->assertRedirect('/imports?meet=unconfigured');
    }

    /** @param list<string> $scopes */
    private function mockGoogleUser(?string $refreshToken, array $scopes): void
    {
        $user = (new SocialiteUser)->map(['id' => 'g1', 'email' => 'Taro@Example.test', 'name' => 'Taro']);
        $user->setRefreshToken($refreshToken)->setApprovedScopes($scopes);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_oauth_callback_saves_the_connection_only_when_both_permissions_are_granted(): void
    {
        $both = ['openid', 'email', 'https://www.googleapis.com/auth/calendar.events.readonly', 'https://www.googleapis.com/auth/documents.readonly'];
        $this->mockGoogleUser(self::REFRESH, $both);
        $this->loginAs($this->employee)->get('/auth/meet/callback?code=abc&state=x')->assertRedirect('/imports?meet=connected');
        $connection = MeetConnection::query()->where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame('taro@example.test', $connection->google_email);
        $this->assertSame(self::REFRESH, $connection->refresh_token);
    }

    public function test_oauth_callback_rejects_missing_refresh_token_or_a_missing_permission(): void
    {
        $this->mockGoogleUser(null, ['https://www.googleapis.com/auth/calendar.events.readonly', 'https://www.googleapis.com/auth/documents.readonly']);
        $this->loginAs($this->employee)->get('/auth/meet/callback?code=abc')->assertRedirect('/imports?meet=denied');

        $this->mockGoogleUser(self::REFRESH, ['https://www.googleapis.com/auth/calendar.events.readonly']); // ドキュメントの許可が無い
        $this->get('/auth/meet/callback?code=abc')->assertRedirect('/imports?meet=denied');
        $this->assertSame(0, MeetConnection::query()->count());
    }
}

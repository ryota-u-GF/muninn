<?php

namespace Tests\Feature;

use App\Models\GmailConnection;
use App\Models\ImportCandidate;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Ai\AiContentRejectedException;
use App\Services\Ai\TaskExtractor;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\GmailSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

class GmailTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    private const REFRESH = 'refresh-token-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.gmail_redirect' => 'http://localhost/auth/gmail/callback',
        ]);
    }

    private function connect(User $user, array $overrides = []): GmailConnection
    {
        return GmailConnection::query()->create($overrides + [
            'user_id' => $user->id,
            'google_email' => 'taro@example.test',
            'refresh_token' => self::REFRESH,
        ]);
    }

    /** @param list<string> $ids */
    private function fakeGoogle(array $ids, int $tokenStatus = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(
                $tokenStatus === 200 ? ['access_token' => 'access-abc'] : ['error' => 'invalid_grant'],
                $tokenStatus,
            ),
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => array_map(fn ($id) => ['id' => $id], $ids)]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/*' => function (Request $request) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));

                return Http::response([
                    'id' => $id,
                    'internalDate' => (string) (1_760_000_000 * 1000),
                    'payload' => [
                        'mimeType' => 'text/plain',
                        'headers' => [['name' => 'Subject', 'value' => "件名 {$id}"], ['name' => 'From', 'value' => 'client@example.test']],
                        'body' => ['data' => rtrim(strtr(base64_encode("本文 {$id}：見積書を送ってください。"), '+/', '-_'), '=')],
                    ],
                ]);
            },
        ]);
    }

    private function fakeExtractor(?string $rejectSubjectContaining = null): void
    {
        $this->app->bind(TaskExtractor::class, fn () => new class($rejectSubjectContaining) implements TaskExtractor
        {
            public function __construct(private ?string $reject) {}

            public function extract(User $user, string $text): array
            {
                if ($this->reject !== null && str_contains($text, $this->reject)) {
                    throw new AiContentRejectedException('処理できません');
                }

                return [['title' => 'タスク：'.mb_substr($text, 0, 20), 'description' => null, 'due_date' => null, 'project_id' => null, 'source_type' => 'email']];
            }

            public function verify(User $user): void {}
        });
    }

    public function test_settings_never_expose_or_store_the_refresh_token_in_plain_text(): void
    {
        $this->connect($this->employee);

        $res = $this->loginAs($this->employee)->getJson('/api/gmail')->assertOk()
            ->assertJsonPath('data.connected', true)->assertJsonPath('data.google_email', 'taro@example.test')
            ->assertJsonPath('data.oauth_ready', true);
        $this->assertStringNotContainsString(self::REFRESH, $res->getContent());

        $raw = (string) DB::table('gmail_connections')->where('user_id', $this->employee->id)->value('refresh_token');
        $this->assertStringNotContainsString(self::REFRESH, $raw);
        $this->assertSame(self::REFRESH, GmailConnection::query()->firstOrFail()->refresh_token);

        // 他の人の連携は見えない
        $this->loginAs($this->employee2)->getJson('/api/gmail')->assertJsonPath('data.connected', false);
    }

    public function test_settings_can_be_updated_and_validated(): void
    {
        $this->connect($this->employee, ['last_synced_at' => now()]);
        $this->loginAs($this->employee);

        $this->putJson('/api/gmail', ['scope_mode' => 'label', 'label_name' => 'タスク', 'exclude_promotions' => false, 'initial_days' => 7, 'auto_sync' => false])
            ->assertOk()->assertJsonPath('data.scope_mode', 'label')->assertJsonPath('data.label_name', 'タスク')
            ->assertJsonPath('data.initial_days', 7)->assertJsonPath('data.auto_sync', false)->assertJsonPath('data.last_synced_at', null);

        $this->putJson('/api/gmail', ['scope_mode' => 'label', 'label_name' => '', 'exclude_promotions' => true, 'initial_days' => 3, 'auto_sync' => true])
            ->assertJsonValidationErrors('label_name');
        $this->putJson('/api/gmail', ['scope_mode' => 'all', 'exclude_promotions' => true, 'initial_days' => 3, 'auto_sync' => true])
            ->assertJsonValidationErrors('scope_mode');
        $this->putJson('/api/gmail', ['scope_mode' => 'inbox', 'exclude_promotions' => true, 'initial_days' => 365, 'auto_sync' => true])
            ->assertJsonValidationErrors('initial_days');

        $this->loginAs($this->employee2)
            ->putJson('/api/gmail', ['scope_mode' => 'inbox', 'exclude_promotions' => true, 'initial_days' => 3, 'auto_sync' => true])->assertNotFound();
    }

    public function test_contractors_and_guests_cannot_use_gmail(): void
    {
        $this->getJson('/api/gmail')->assertUnauthorized();
        $this->loginAs($this->c1)->getJson('/api/gmail')->assertForbidden();
        $this->postJson('/api/gmail/sync')->assertForbidden();
        $this->get('/auth/gmail/redirect')->assertForbidden();
    }

    public function test_disconnect_revokes_the_token_and_deletes_it_but_keeps_import_history(): void
    {
        $this->fakeGoogle([]);
        $connection = $this->connect($this->employee);
        MailMessage::query()->create(['owner_id' => $this->employee->id, 'gmail_message_id' => 'm1']);

        $this->loginAs($this->employee)->deleteJson('/api/gmail')->assertOk()->assertJsonPath('data.connected', false);

        $this->assertNull(GmailConnection::query()->find($connection->id));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke') && $r['token'] === self::REFRESH);
        $this->assertSame(1, MailMessage::query()->count()); // 取り込み済みの記録は残す（再連携で二重にならない）
    }

    public function test_sync_processes_in_batches_without_duplicates_and_keeps_candidates_private(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle(['m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7']);
        $this->fakeExtractor();
        $this->loginAs($this->employee);

        $first = $this->postJson('/api/gmail/sync')->assertOk();
        $this->assertSame(5, $first->json('data.processed'));
        $this->assertTrue($first->json('data.has_more'));
        $this->assertNull(GmailConnection::query()->firstOrFail()->last_synced_at); // 途中は完了扱いにしない

        $second = $this->postJson('/api/gmail/sync')->assertOk();
        $this->assertSame(2, $second->json('data.processed'));
        $this->assertFalse($second->json('data.has_more'));
        $this->assertNotNull(GmailConnection::query()->firstOrFail()->last_synced_at);

        $third = $this->postJson('/api/gmail/sync')->assertOk();
        $this->assertSame(0, $third->json('data.processed')); // 同じメールは二重に取り込まない
        $this->assertSame(7, MailMessage::query()->count());
        $this->assertSame(7, ImportCandidate::query()->count());

        // 候補にメールの出どころが付く（本文は保存しない）
        $candidate = $this->getJson('/api/imports')->assertOk()->json('data.0');
        $this->assertSame('gmail', $candidate['source']);
        $this->assertStringStartsWith('件名 m', $candidate['mail']['subject']);
        $this->assertSame('client@example.test', $candidate['mail']['sender']);
        $this->assertStringContainsString('mail.google.com', $candidate['mail']['url']);
        $this->assertStringNotContainsString('見積書を送ってください', (string) json_encode(MailMessage::query()->get()->toArray(), JSON_UNESCAPED_UNICODE));

        // 他の人（管理者を含む）には見えない
        $this->loginAs($this->employee2)->getJson('/api/imports')->assertJsonCount(0, 'data');
        $this->loginAs($this->admin)->getJson('/api/imports')->assertJsonCount(0, 'data');
    }

    public function test_content_the_ai_rejects_is_skipped_and_does_not_block_the_rest(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle(['ok1', 'bad', 'ok2']);
        $this->fakeExtractor('bad');

        $res = $this->loginAs($this->employee)->postJson('/api/gmail/sync')->assertOk();
        $this->assertSame(2, $res->json('data.processed'));
        $this->assertSame(1, $res->json('data.skipped'));
        $this->assertSame('skipped', MailMessage::query()->where('gmail_message_id', 'bad')->firstOrFail()->status);

        // 次回は、スキップしたメールを再処理しない
        $again = $this->postJson('/api/gmail/sync')->assertOk();
        $this->assertSame(0, $again->json('data.processed') + $again->json('data.skipped'));
    }

    public function test_sync_without_ai_key_stops_and_records_nothing(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle(['m1']);

        $this->loginAs($this->employee)->postJson('/api/gmail/sync')->assertUnprocessable()->assertJsonValidationErrors('gmail');
        $this->assertSame(0, MailMessage::query()->count()); // 次回やり直せるように記録しない
    }

    public function test_revoked_google_access_asks_for_reconnection(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle(['m1'], 400);
        $this->fakeExtractor();

        $res = $this->loginAs($this->employee)->postJson('/api/gmail/sync')->assertUnprocessable();
        $this->assertStringNotContainsString(self::REFRESH, $res->getContent());
        $this->assertStringContainsString('連携', (string) $res->json('errors.gmail.0'));
        $this->assertNotNull(GmailConnection::query()->firstOrFail()->last_error);
        $this->assertSame(0, MailMessage::query()->count());
    }

    public function test_auto_sync_does_nothing_when_turned_off(): void
    {
        $this->connect($this->employee, ['auto_sync' => false]);
        $this->fakeGoogle(['m1']);
        $this->fakeExtractor();

        $this->loginAs($this->employee)->postJson('/api/gmail/sync', ['auto' => true])
            ->assertOk()->assertJsonPath('data.status', 'disabled')->assertJsonPath('data.processed', 0);
        Http::assertNothingSent();
        $this->assertSame(0, MailMessage::query()->count());
    }

    public function test_auto_sync_skips_when_the_last_sync_was_recent_but_manual_sync_does_not(): void
    {
        $this->connect($this->employee, ['last_synced_at' => now()->subMinutes(5)]);
        $this->fakeGoogle(['m1']);
        $this->fakeExtractor();
        $this->loginAs($this->employee);

        $this->postJson('/api/gmail/sync', ['auto' => true])->assertOk()->assertJsonPath('data.status', 'recent');
        Http::assertNothingSent();

        // 手動の「いま取得」は、間隔に関係なく実行する
        $this->postJson('/api/gmail/sync')->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.processed', 1);
    }

    public function test_auto_sync_runs_when_enough_time_has_passed(): void
    {
        $this->connect($this->employee, ['last_synced_at' => now()->subMinutes(30)]);
        $this->fakeGoogle(['m1', 'm2']);
        $this->fakeExtractor();

        $this->loginAs($this->employee)->postJson('/api/gmail/sync', ['auto' => true])
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.processed', 2);
        $this->assertSame(2, ImportCandidate::query()->count());
    }

    public function test_concurrent_sync_for_the_same_user_is_not_run_twice(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle(['m1']);
        $this->fakeExtractor();

        $lock = Cache::lock('gmail-sync:'.$this->employee->id, 30);
        $this->assertTrue($lock->get());
        try {
            $this->loginAs($this->employee)->postJson('/api/gmail/sync')
                ->assertOk()->assertJsonPath('data.status', 'busy');
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }

        // ロックが外れれば実行できる
        $this->postJson('/api/gmail/sync')->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame(1, MailMessage::query()->count());
    }

    public function test_the_lock_is_released_even_when_sync_fails(): void
    {
        $this->connect($this->employee);
        $this->fakeGoogle(['m1']);

        // AI キー未設定で失敗しても、次の取得を妨げない
        $this->loginAs($this->employee)->postJson('/api/gmail/sync')->assertUnprocessable();
        $this->fakeExtractor();
        $this->postJson('/api/gmail/sync')->assertOk()->assertJsonPath('data.status', 'done');
    }

    public function test_sync_requires_a_connection(): void
    {
        $this->loginAs($this->employee)->postJson('/api/gmail/sync')->assertUnprocessable()->assertJsonValidationErrors('gmail');
    }

    public function test_search_query_follows_the_settings(): void
    {
        $syncer = app(GmailSyncer::class);
        $c = new GmailConnection(['scope_mode' => 'inbox', 'exclude_promotions' => true, 'initial_days' => 7]);
        $this->assertSame('in:inbox -category:promotions -category:social -category:forums newer_than:7d', $syncer->query($c));

        $c = new GmailConnection(['scope_mode' => 'label', 'label_name' => 'タ"スク', 'exclude_promotions' => false, 'initial_days' => 3]);
        $this->assertSame('label:"タスク" newer_than:3d', $syncer->query($c));

        $c = new GmailConnection(['scope_mode' => 'inbox', 'exclude_promotions' => false, 'initial_days' => 3]);
        $c->last_synced_at = CarbonImmutable::createFromTimestamp(1_760_003_600, config('app.timezone'));
        $this->assertSame('in:inbox after:1760000000', $syncer->query($c)); // 1時間重ねる
    }

    public function test_message_parsing_handles_multipart_html_and_encoded_headers(): void
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $multipart = GmailClient::summarize([
            'id' => 'x1', 'internalDate' => '1760000000000',
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'headers' => [['name' => 'Subject', 'value' => '=?UTF-8?B?'.base64_encode('見積のお願い').'?='], ['name' => 'From', 'value' => '山田 <y@example.test>']],
                'parts' => [
                    ['mimeType' => 'text/html', 'body' => ['data' => $b64('<p>HTML版</p>')]],
                    ['mimeType' => 'text/plain', 'body' => ['data' => $b64('テキスト版です')]],
                ],
            ],
        ]);
        $this->assertSame('見積のお願い', $multipart['subject']);
        $this->assertSame('テキスト版です', $multipart['text']); // text/plain を優先
        $this->assertSame(1_760_000_000, $multipart['received_at']);

        $htmlOnly = GmailClient::summarize([
            'id' => 'x2',
            'payload' => ['mimeType' => 'text/html', 'headers' => [], 'body' => ['data' => $b64('<style>p{}</style><p>こんにちは</p><script>x()</script><div>資料を送付</div>')]],
        ]);
        $this->assertSame("こんにちは\n資料を送付", $htmlOnly['text']);
        $this->assertNull($htmlOnly['received_at']);
    }

    public function test_oauth_redirect_requests_read_only_scope_and_offline_access(): void
    {
        $res = $this->loginAs($this->employee)->get('/auth/gmail/redirect');
        $res->assertRedirect();
        $url = (string) $res->headers->get('Location');
        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString(rawurlencode('https://www.googleapis.com/auth/gmail.readonly'), $url);
        $this->assertStringNotContainsString('gmail.send', $url);
        $this->assertStringNotContainsString('gmail.modify', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
    }

    public function test_oauth_redirect_reports_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->loginAs($this->employee)->get('/auth/gmail/redirect')->assertRedirect('/imports?gmail=unconfigured');
    }

    private function mockGoogleUser(?string $refreshToken, array $scopes): void
    {
        $user = (new SocialiteUser)->map(['id' => 'g1', 'email' => 'Taro@Example.test', 'name' => 'Taro']);
        $user->setRefreshToken($refreshToken)->setApprovedScopes($scopes);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_oauth_callback_saves_the_connection(): void
    {
        $this->mockGoogleUser(self::REFRESH, ['openid', 'email', 'https://www.googleapis.com/auth/gmail.readonly']);

        $this->loginAs($this->employee)->get('/auth/gmail/callback?code=abc&state=x')->assertRedirect('/imports?gmail=connected');

        $connection = GmailConnection::query()->where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame('taro@example.test', $connection->google_email);
        $this->assertSame(self::REFRESH, $connection->refresh_token);
    }

    public function test_oauth_callback_rejects_missing_refresh_token_or_scope(): void
    {
        $this->mockGoogleUser(null, ['openid', 'email', 'https://www.googleapis.com/auth/gmail.readonly']);
        $this->loginAs($this->employee)->get('/auth/gmail/callback?code=abc')->assertRedirect('/imports?gmail=denied');
        $this->assertSame(0, GmailConnection::query()->count());
    }

    public function test_oauth_callback_rejects_when_gmail_permission_was_not_granted(): void
    {
        $this->mockGoogleUser(self::REFRESH, ['openid', 'email']);
        $this->loginAs($this->employee)->get('/auth/gmail/callback?code=abc')->assertRedirect('/imports?gmail=denied');
        $this->assertSame(0, GmailConnection::query()->count());
    }
}

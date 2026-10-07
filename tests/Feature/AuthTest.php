<?php

namespace Tests\Feature;

use App\Actions\Admin\UserWriter;
use App\Enums\Role;
use App\Models\User;
use App\Support\DemoMode;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_requests_are_rejected_with_401(): void
    {
        foreach (['/api/tasks', '/api/projects', '/api/documents', '/api/history', '/api/tv', '/api/members', '/api/imports'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/tasks', ['title' => 'x'])->assertUnauthorized();
    }

    public function test_unauthenticated_pages_redirect_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/tv')->assertRedirect('/login');
    }

    public function test_login_page_shows_google_as_not_connected_when_unconfigured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->where('googleConfigured', false));

        $this->get('/auth/google/redirect')->assertRedirect('/login')->assertSessionHas('error');
    }

    public function test_demo_login_works_only_when_enabled_in_non_production(): void
    {
        $user = User::factory()->create();

        config(['gf.demo_login' => false]);
        $this->post('/demo-login', ['user_id' => $user->id])->assertNotFound();

        config(['gf.demo_login' => true]);
        $this->post('/demo-login', ['user_id' => $user->id])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/tasks')->assertOk();
    }

    public function test_demo_login_cannot_be_enabled_in_production(): void
    {
        $user = User::factory()->create();
        config(['gf.demo_login' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertFalse(DemoMode::loginEnabled());
        $this->assertFalse(DemoMode::seedingAllowed());
        $this->withSession(['_token' => 'csrf-test'])->post('/demo-login', ['user_id' => $user->id, '_token' => 'csrf-test'])->assertNotFound();
        $this->assertGuest();
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->app->make(DatabaseSeeder::class)->run();
    }

    public function test_inactive_user_session_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->loginAs($user)->getJson('/api/tasks')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_demo_login(): void
    {
        config(['gf.demo_login' => true]);
        $user = User::factory()->inactive()->create();

        $this->post('/demo-login', ['user_id' => $user->id])->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_role_change_invalidates_existing_session(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->loginAs($user)->getJson('/api/tasks')->assertOk();

        app(UserWriter::class)->update($admin, $user->id, ['role' => Role::Contractor->value], $user->fresh()->revision);

        // 実運用ではユーザーは毎リクエスト DB から読み直される（テストでは明示的に再読込）
        $this->actingAs($user->fresh())->getJson('/api/tasks')->assertUnauthorized();
    }

    public function test_deactivation_via_admin_api_revokes_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->loginAs($admin)->patchJson("/api/users/{$user->id}", ['is_active' => false, 'expected_revision' => 1])->assertOk();

        $this->assertSame(2, $user->fresh()->session_version);
        $this->loginAs($user)->withSession(['gf.session_version' => 1])->getJson('/api/tasks')->assertUnauthorized();
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->loginAs($admin)->patchJson("/api/users/{$admin->id}", ['role' => 'employee', 'expected_revision' => 1])
            ->assertUnprocessable();
    }

    public function test_tv_account_can_only_use_tv_endpoints(): void
    {
        $tv = User::factory()->tv()->create();

        $this->loginAs($tv)->getJson('/api/tv')->assertOk();
        $this->get('/tv')->assertOk();
        foreach (['/api/tasks', '/api/projects', '/api/documents', '/api/history', '/api/members', '/api/imports', '/api/project-access'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson('/api/tasks', ['title' => 'x'])->assertForbidden();
        $this->get('/')->assertRedirect('/tv');
        $this->get('/documents')->assertRedirect('/tv');
    }

    public function test_each_role_can_log_in_and_reach_its_screens(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $contractor = User::factory()->contractor()->create();

        $this->loginAs($admin)->get('/admin/access')->assertOk();
        $this->loginAs($employee)->get('/')->assertOk();
        $this->loginAs($employee)->get('/admin/access')->assertForbidden();
        $this->loginAs($contractor)->get('/')->assertOk();
        $this->loginAs($contractor)->get('/tv')->assertForbidden();
        $this->loginAs($contractor)->get('/imports')->assertForbidden();
        $this->loginAs($contractor)->get('/projects')->assertForbidden();
    }

    public function test_google_login_never_creates_admin_and_rejects_unregistered_accounts(): void
    {
        config([
            'services.google.client_id' => 'test-id',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
            'gf.google_allowed_domains' => ['company.test'],
        ]);

        // 未登録・許可外ドメイン → 拒否
        $this->mockGoogleUser('g-1', 'someone@other.test', ['email_verified' => true]);
        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);

        // 許可ドメインの最初のログイン → 社員（管理者ではない）
        $this->mockGoogleUser('g-2', 'first@company.test', ['email_verified' => true, 'hd' => 'company.test']);
        $this->get('/auth/google/callback')->assertSessionMissing('error')->assertRedirect('/');
        $user = User::query()->where('email', 'first@company.test')->firstOrFail();
        $this->assertSame(Role::Employee, $user->role);
        $this->assertSame('g-2', $user->google_id);
    }

    public function test_google_login_rejects_unverified_email_and_mismatched_google_id(): void
    {
        config([
            'services.google.client_id' => 'test-id',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
        User::factory()->create(['email' => 'member@company.test', 'google_id' => 'g-original']);

        $this->mockGoogleUser('g-x', 'member@company.test', ['email_verified' => false]);
        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();

        $this->mockGoogleUser('g-other', 'member@company.test', ['email_verified' => true]);
        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();

        $this->mockGoogleUser('g-original', 'member@company.test', ['email_verified' => true]);
        $this->get('/auth/google/callback')->assertRedirect('/');
        $this->assertAuthenticated();
    }

    private ?SocialiteUser $nextGoogleUser = null;

    /** @param array<string, mixed> $raw */
    private function mockGoogleUser(string $id, string $email, array $raw): void
    {
        $this->nextGoogleUser = (new SocialiteUser)->setRaw($raw + ['email' => $email])->map(['id' => $id, 'email' => $email, 'name' => 'Google User']);

        if (! isset($this->googleMocked)) {
            $provider = Mockery::mock(GoogleProvider::class);
            $provider->shouldReceive('user')->andReturnUsing(fn () => $this->nextGoogleUser);
            Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
            $this->googleMocked = true;
        }
    }

    private bool $googleMocked;
}

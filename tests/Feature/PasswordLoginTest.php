<?php

namespace Tests\Feature;

use App\Models\Revision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function userWithPassword(?string $state = null): User
    {
        $factory = User::factory();
        $user = ($state === null ? $factory : $factory->{$state}())->create();
        $user->forceFill(['password' => self::PASSWORD])->save();

        return $user->fresh();
    }

    public function test_user_with_password_can_log_in(): void
    {
        $user = $this->userWithPassword();

        $this->post('/password-login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/tasks')->assertOk();
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $user = $this->userWithPassword();

        $this->post('/password-login', ['email' => mb_strtoupper($user->email), 'password' => self::PASSWORD])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_tv_user_is_sent_to_the_tv_page(): void
    {
        $user = $this->userWithPassword('tv');

        $this->post('/password-login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/tv');
    }

    public function test_wrong_password_unknown_email_inactive_and_unset_password_all_fail_with_the_same_message(): void
    {
        $user = $this->userWithPassword();
        $inactive = $this->userWithPassword('inactive');
        $unset = User::factory()->create();
        $message = 'メールアドレスまたはパスワードが違います。';

        $cases = [
            ['email' => $user->email, 'password' => 'wrong-password-123'],
            ['email' => 'nobody@example.test', 'password' => self::PASSWORD],
            ['email' => $inactive->email, 'password' => self::PASSWORD],
            ['email' => $unset->email, 'password' => self::PASSWORD],
            ['email' => $unset->email, 'password' => ''],
        ];

        foreach (array_slice($cases, 0, 4) as $case) {
            $this->post('/password-login', $case)->assertRedirect()->assertSessionHas('error', $message);
            $this->assertGuest();
        }
        $this->post('/password-login', $cases[4])->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_repeated_failures_for_one_email_are_throttled(): void
    {
        $user = $this->userWithPassword();

        foreach (range(1, 5) as $_) {
            $this->post('/password-login', ['email' => $user->email, 'password' => 'wrong-password-123'])->assertRedirect();
        }

        $this->post('/password-login', ['email' => $user->email, 'password' => self::PASSWORD])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_login_page_is_served_with_the_password_form_available_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->where('demoEnabled', false));
    }

    public function test_admin_can_set_a_password_and_the_user_can_then_log_in(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->loginAs($admin)->putJson("/api/users/{$user->id}/password", [
            'expected_revision' => 1,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('data.password_set', true)->assertJsonMissingPath('data.password');

        $user = $user->fresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertSame(2, $user->revision);

        $this->post('/logout');
        $this->post('/password-login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_setting_anothers_password_revokes_their_sessions_but_not_for_yourself(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $payload = ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];

        $this->loginAs($admin)->putJson("/api/users/{$user->id}/password", $payload + ['expected_revision' => 1])->assertOk();

        $this->assertSame(2, $user->fresh()->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);

        $this->putJson("/api/users/{$admin->id}/password", $payload + ['expected_revision' => 1])->assertOk();
        $this->assertSame(1, $admin->fresh()->session_version);
    }

    public function test_password_rules_are_enforced(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->loginAs($admin);

        $this->putJson("/api/users/{$user->id}/password", ['expected_revision' => 1, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson("/api/users/{$user->id}/password", ['expected_revision' => 1, 'password' => self::PASSWORD, 'password_confirmation' => 'different-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertNull($user->fresh()->password);
    }

    public function test_stale_revision_is_rejected_with_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->loginAs($admin)->putJson("/api/users/{$user->id}/password", [
            'expected_revision' => 99,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(409);
        $this->assertNull($user->fresh()->password);
    }

    public function test_only_admins_can_set_passwords(): void
    {
        $employee = User::factory()->create();
        $user = User::factory()->create();

        $this->loginAs($employee)->putJson("/api/users/{$user->id}/password", [
            'expected_revision' => 1,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertForbidden();
        $this->assertNull($user->fresh()->password);
    }

    public function test_audit_records_that_a_password_was_set_without_the_hash(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->loginAs($admin)->putJson("/api/users/{$user->id}/password", [
            'expected_revision' => 1,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk();

        $audit = Revision::query()->where('entity_type', 'user')->where('entity_id', $user->id)->where('action', 'password_set')->firstOrFail();
        $this->assertFalse($audit->before['password_set']);
        $this->assertTrue($audit->after['password_set']);
        $this->assertStringNotContainsString($user->fresh()->password, json_encode($audit->after));
    }

    public function test_user_list_shows_whether_a_password_is_set_but_never_the_hash(): void
    {
        $admin = User::factory()->admin()->create();
        $with = $this->userWithPassword();

        $response = $this->loginAs($admin)->getJson('/api/users')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $with->id);
        $this->assertTrue($row['password_set']);
        $this->assertArrayNotHasKey('password', $row);
    }

    public function test_gf_user_command_sets_a_password_interactively(): void
    {
        $this->artisan('gf:user', ['email' => 'owner@example.test', '--name' => 'Owner', '--role' => 'admin', '--password' => true])
            ->expectsQuestion('パスワード（12文字以上）', self::PASSWORD)
            ->expectsQuestion('パスワード（確認）', self::PASSWORD)
            ->expectsOutputToContain('パスワードを設定しました')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->post('/password-login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertRedirect('/');
    }

    public function test_gf_user_command_rejects_short_or_mismatched_passwords(): void
    {
        $this->artisan('gf:user', ['email' => 'short@example.test', '--password' => true])
            ->expectsQuestion('パスワード（12文字以上）', 'short')
            ->assertFailed();

        $this->artisan('gf:user', ['email' => 'mismatch@example.test', '--password' => true])
            ->expectsQuestion('パスワード（12文字以上）', self::PASSWORD)
            ->expectsQuestion('パスワード（確認）', 'different-password')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'short@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.test']);
    }
}

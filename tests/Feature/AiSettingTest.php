<?php

namespace Tests\Feature;

use App\Enums\ExtractKind;
use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\AiRequestFailedException;
use App\Services\Ai\TaskExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class AiSettingTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    private const KEY = 'sk-ant-test-0123456789abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    public function test_key_is_saved_encrypted_and_never_returned(): void
    {
        $res = $this->loginAs($this->employee)
            ->putJson('/api/ai-settings', ['api_key' => self::KEY, 'model' => 'claude-haiku-5-5'])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.key_hint', '…ghij')
            ->assertJsonPath('data.model', 'claude-haiku-5-5');
        $this->assertStringNotContainsString(self::KEY, $res->getContent());

        // DB には平文で入らない。モデル経由では復号できる。
        $raw = DB::table('user_ai_settings')->where('user_id', $this->employee->id)->value('api_key');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertStringNotContainsString('sk-ant', (string) $raw);
        $this->assertSame(self::KEY, AiSetting::query()->where('user_id', $this->employee->id)->firstOrFail()->api_key);

        $show = $this->getJson('/api/ai-settings')->assertOk();
        $this->assertStringNotContainsString(self::KEY, $show->getContent());
    }

    public function test_settings_are_per_user(): void
    {
        $this->loginAs($this->employee)->putJson('/api/ai-settings', ['api_key' => self::KEY, 'model' => 'claude-haiku-5-5'])->assertOk();

        $this->loginAs($this->employee2)->getJson('/api/ai-settings')
            ->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.key_hint', null);
    }

    public function test_model_can_be_changed_without_resending_the_key(): void
    {
        $this->loginAs($this->employee)->putJson('/api/ai-settings', ['api_key' => self::KEY, 'model' => 'claude-haiku-5-5'])->assertOk();
        $this->putJson('/api/ai-settings', ['model' => 'claude-sonnet-5-5'])
            ->assertOk()->assertJsonPath('data.model', 'claude-sonnet-5-5')->assertJsonPath('data.key_hint', '…ghij');
        $this->assertSame(self::KEY, AiSetting::query()->firstOrFail()->api_key);
    }

    public function test_validation(): void
    {
        $this->loginAs($this->employee);
        $this->putJson('/api/ai-settings', ['model' => 'claude-haiku-5-5'])->assertJsonValidationErrors('api_key'); // 初回はキー必須
        $this->putJson('/api/ai-settings', ['api_key' => 'sk-ant-short', 'model' => 'claude-haiku-5-5'])->assertJsonValidationErrors('api_key');
        $this->putJson('/api/ai-settings', ['api_key' => 'not-an-anthropic-key-0123456789', 'model' => 'claude-haiku-5-5'])->assertJsonValidationErrors('api_key');
        $this->putJson('/api/ai-settings', ['api_key' => self::KEY, 'model' => 'gpt-4'])->assertJsonValidationErrors('model');
    }

    public function test_delete_removes_the_key(): void
    {
        $this->loginAs($this->employee)->putJson('/api/ai-settings', ['api_key' => self::KEY, 'model' => 'claude-haiku-5-5'])->assertOk();
        $this->deleteJson('/api/ai-settings')->assertOk()->assertJsonPath('data.configured', false);
        $this->assertSame(0, AiSetting::query()->count());
    }

    public function test_contractors_and_guests_cannot_use_ai_settings(): void
    {
        $this->getJson('/api/ai-settings')->assertUnauthorized();
        $this->loginAs($this->c1)->getJson('/api/ai-settings')->assertForbidden();
        $this->putJson('/api/ai-settings', ['api_key' => self::KEY, 'model' => 'claude-haiku-5-5'])->assertForbidden();
        $this->assertSame(0, AiSetting::query()->count());
    }

    public function test_connection_test_without_a_key_reports_a_message(): void
    {
        $this->loginAs($this->employee)->postJson('/api/ai-settings/test')->assertUnprocessable()->assertJsonValidationErrors('ai');
    }

    public function test_connection_test_reports_provider_failures_without_leaking_the_key(): void
    {
        $this->app->bind(TaskExtractor::class, fn () => new class implements TaskExtractor
        {
            public function extract(User $user, string $text, ExtractKind $kind = ExtractKind::Mail): array
            {
                return [];
            }

            public function verify(User $user): void
            {
                throw new AiRequestFailedException('APIキーが正しくないか、使えない状態です。');
            }
        });

        $res = $this->loginAs($this->employee)->postJson('/api/ai-settings/test')->assertUnprocessable();
        $this->assertStringNotContainsString('sk-ant', $res->getContent());
        $this->assertStringContainsString('APIキー', (string) $res->json('errors.ai.0'));
    }
}

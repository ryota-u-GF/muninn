<?php

namespace Tests\Feature;

use App\Enums\ExtractKind;
use App\Models\ImportCandidate;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiRequestFailedException;
use App\Services\Ai\AnthropicTaskExtractor;
use App\Services\Ai\TaskExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class ImportExtractTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    /** @param list<array<string, mixed>> $items */
    private function fakeExtractor(array $items): void
    {
        $this->app->bind(TaskExtractor::class, fn () => new class($items) implements TaskExtractor
        {
            /** @param list<array<string, mixed>> $items */
            public function __construct(private array $items) {}

            public function extract(User $user, string $text, ExtractKind $kind = ExtractKind::Mail): array
            {
                return $this->items;
            }

            public function verify(User $user): void {}
        });
    }

    public function test_extract_creates_private_pending_candidates_only(): void
    {
        $this->fakeExtractor([
            ['title' => '見積書を送付する', 'description' => 'A社より', 'due_date' => '2026-10-30', 'project_id' => $this->projectA->id, 'source_type' => 'email'],
            ['title' => '資料を確認する', 'description' => null, 'due_date' => null, 'project_id' => null, 'source_type' => 'email'],
        ]);

        $res = $this->loginAs($this->employee)
            ->postJson('/api/imports/extract', ['text' => 'メール本文'])->assertCreated();
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('ai_text', $res->json('data.0.source'));
        $this->assertSame('pending', $res->json('data.0.status'));
        $this->assertSame('shared', $res->json('data.0.payload.visibility')); // 承認時に選べる
        $this->assertSame(0, Task::query()->count()); // 承認するまでタスクにならない

        // 本人以外（管理者を含む）には見えない
        $this->loginAs($this->admin)->getJson('/api/imports')->assertJsonCount(0, 'data');
        $this->loginAs($this->employee2)->getJson('/api/imports')->assertJsonCount(0, 'data');
    }

    public function test_extract_with_no_tasks_creates_nothing(): void
    {
        $this->fakeExtractor([]);
        $this->loginAs($this->employee)->postJson('/api/imports/extract', ['text' => 'ご案内です'])
            ->assertCreated()->assertJsonCount(0, 'data');
        $this->assertSame(0, ImportCandidate::query()->count());
    }

    public function test_extract_requires_a_key_and_validates_text(): void
    {
        $this->loginAs($this->employee);
        // キー未設定（実装クラスは AI を呼ばずに 422）
        $this->postJson('/api/imports/extract', ['text' => 'メール本文'])->assertUnprocessable()->assertJsonValidationErrors('ai');
        $this->postJson('/api/imports/extract', ['text' => ''])->assertJsonValidationErrors('text');
        $this->postJson('/api/imports/extract', ['text' => str_repeat('あ', config('gf.ai.max_text_chars') + 1)])->assertJsonValidationErrors('text');
        $this->assertSame(0, ImportCandidate::query()->count());
    }

    public function test_provider_failure_is_reported_and_creates_nothing(): void
    {
        $this->app->bind(TaskExtractor::class, fn () => new class implements TaskExtractor
        {
            public function extract(User $user, string $text, ExtractKind $kind = ExtractKind::Mail): array
            {
                throw new AiRequestFailedException('AIの利用回数の上限に達しました。');
            }

            public function verify(User $user): void {}
        });

        $this->loginAs($this->employee)->postJson('/api/imports/extract', ['text' => 'メール本文'])
            ->assertUnprocessable()->assertJsonValidationErrors('ai');
        $this->assertSame(0, ImportCandidate::query()->count());
    }

    public function test_contractors_cannot_extract(): void
    {
        $this->fakeExtractor([['title' => 'x']]);
        $this->loginAs($this->c1)->postJson('/api/imports/extract', ['text' => 'メール本文'])->assertForbidden();
    }

    public function test_ai_output_is_mapped_safely_to_candidates(): void
    {
        $projects = collect([$this->projectA, $this->projectB]);
        $json = json_encode(['candidates' => [
            ['title' => '案件Aの作業', 'description' => '根拠', 'due_date' => '2026-11-01', 'project_name' => mb_strtoupper($this->projectA->name)],
            ['title' => '不明な案件', 'description' => null, 'due_date' => null, 'project_name' => '存在しない案件'],
            ['title' => '  ', 'description' => null, 'due_date' => null, 'project_name' => null], // タイトル空は捨てる
            'not-an-object',
        ]], JSON_UNESCAPED_UNICODE);

        $items = AnthropicTaskExtractor::candidatesFromJson((string) $json, $projects);

        $this->assertCount(2, $items);
        $this->assertSame($this->projectA->id, $items[0]['project_id']);
        $this->assertNull($items[1]['project_id']); // 推測しない
        $this->assertSame('email', $items[0]['source_type']);
    }

    public function test_malformed_ai_output_is_rejected(): void
    {
        $this->expectException(AiRequestFailedException::class);
        AnthropicTaskExtractor::candidatesFromJson('これはJSONではありません', collect());
    }

    public function test_minutes_extraction_uses_its_own_source_and_passes_the_kind(): void
    {
        $captured = new \stdClass;
        $this->app->bind(TaskExtractor::class, fn () => new class($captured) implements TaskExtractor
        {
            public function __construct(private \stdClass $captured) {}

            public function extract(User $user, string $text, ExtractKind $kind = ExtractKind::Mail): array
            {
                $this->captured->kind = $kind;

                return [['title' => '見積書を送付する', 'description' => null, 'due_date' => null, 'project_id' => null, 'source_type' => 'minutes',
                    'assignee_ids' => [], 'unmatched_assignees' => ['山本']]];
            }

            public function verify(User $user): void {}
        });

        $res = $this->loginAs($this->employee)->postJson('/api/imports/extract', ['text' => '議事録', 'kind' => 'minutes'])->assertCreated();
        $this->assertSame(ExtractKind::Minutes, $captured->kind);
        $this->assertSame('ai_minutes', $res->json('data.0.source'));
        $this->assertSame('minutes', $res->json('data.0.payload.source_type'));
        // 担当者を特定できなかった名前は警告になる（担当者は未設定）
        $this->assertContains('担当者「山本」を登録済みの担当者から特定できなかったため、未設定にしました。', $res->json('data.0.warnings'));
        $this->assertSame([], $res->json('data.0.payload.assignee_ids'));

        // 種類の省略はメール
        $this->postJson('/api/imports/extract', ['text' => 'メール'])->assertCreated();
        $this->assertSame(ExtractKind::Mail, $captured->kind);
        $this->postJson('/api/imports/extract', ['text' => '本文', 'kind' => 'voice'])->assertJsonValidationErrors('kind');
    }

    public function test_minutes_assignees_are_matched_to_registered_members_safely(): void
    {
        $members = collect([
            new User(['name' => '山田 太郎（社長）']),
            new User(['name' => '佐藤 花子（営業）']),
            new User(['name' => '鈴木 一郎（エンジニア）']),
            new User(['name' => '外注 佐藤']),
        ])->each(fn (User $u, int $i) => $u->id = $i + 1);

        $match = fn (string $name) => AnthropicTaskExtractor::matchMember($name, $members);

        $this->assertSame(1, $match('山田 太郎（社長）'));   // 一覧と同じ書き方
        $this->assertSame(1, $match('山田太郎'));            // 空白・役職なし
        $this->assertSame(1, $match('山田さん'));            // 姓＋敬称（一人だけに当てはまる）
        $this->assertSame(3, $match('鈴木 一郎さん'));
        $this->assertNull($match('佐藤'));                   // 「佐藤 花子」と「外注 佐藤」の両方に当てはまる → 特定しない
        $this->assertSame(2, $match('佐藤花子'));
        $this->assertSame(4, $match('外注 佐藤'));
        $this->assertNull($match('田中'));                   // 一覧にいない
        $this->assertNull($match('山'));                     // 短すぎる呼び方は特定しない
        $this->assertNull($match('  '));
    }

    public function test_minutes_output_is_mapped_to_assignee_ids_and_unmatched_names(): void
    {
        $members = collect([new User(['name' => '山田 太郎']), new User(['name' => '佐藤 花子'])])
            ->each(fn (User $u, int $i) => $u->id = $i + 1);
        $json = json_encode(['candidates' => [
            ['title' => '見積を送る', 'description' => null, 'due_date' => '2026-11-01', 'project_name' => null, 'assignee_names' => ['山田 太郎', '佐藤さん', '田中']],
            ['title' => '担当未定', 'description' => null, 'due_date' => null, 'project_name' => null, 'assignee_names' => []],
        ]], JSON_UNESCAPED_UNICODE);

        $items = AnthropicTaskExtractor::candidatesFromJson((string) $json, collect(), $members);

        $this->assertSame([1, 2], $items[0]['assignee_ids']);
        $this->assertSame(['田中'], $items[0]['unmatched_assignees']);
        $this->assertSame('minutes', $items[0]['source_type']);
        $this->assertSame([], $items[1]['assignee_ids']);
    }
}

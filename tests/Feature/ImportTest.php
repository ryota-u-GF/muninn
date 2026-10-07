<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ImportCandidate;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    /** @return list<array<string, mixed>> */
    private function importJson(array $items): array
    {
        return $this->loginAs($this->employee)
            ->postJson('/api/imports', ['json' => json_encode($items, JSON_UNESCAPED_UNICODE)])
            ->assertCreated()->json('data');
    }

    public function test_candidates_are_private_to_owner_and_not_shared_until_confirmed(): void
    {
        $created = $this->importJson([['title' => '見積を送る', 'project_id' => $this->projectA->id, 'due_date' => '2026-10-20', 'source_type' => 'email']]);
        $this->assertSame('pending', $created[0]['status']);
        $this->assertSame(1, $created[0]['revision']);
        $this->assertSame(0, Task::query()->count()); // 未確認のまま共有タスクは作られない

        $this->loginAs($this->employee2)->getJson('/api/imports')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/imports/{$created[0]['id']}", ['title' => '横取り', 'expected_revision' => 1])->assertNotFound();
        $this->postJson("/api/imports/{$created[0]['id']}/reject", ['expected_revision' => 1])->assertNotFound();
        $this->postJson('/api/imports/confirm', ['idempotency_key' => 'other-key-1', 'items' => [['id' => $created[0]['id'], 'expected_revision' => 1]]])->assertNotFound();
        $this->loginAs($this->admin)->getJson('/api/imports')->assertJsonCount(0, 'data');
        $this->assertSame(0, Task::query()->count());
    }

    public function test_unknown_values_are_not_guessed(): void
    {
        $created = $this->importJson([[
            'title' => '推測しない', 'project_id' => 99999, 'assignee_ids' => [99999, $this->employee2->id],
            'due_date' => '来週中', 'start_date' => '2026-13-01', 'source_type' => 'fax',
        ]]);
        $payload = $created[0]['payload'];
        $this->assertNull($payload['project_id']);
        $this->assertSame([$this->employee2->id], $payload['assignee_ids']);
        $this->assertNull($payload['due_date']);
        $this->assertNull($payload['start_date']);
        $this->assertSame('other', $payload['source_type']);
        $this->assertGreaterThanOrEqual(3, count($created[0]['warnings']));
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->loginAs($this->employee);
        $this->postJson('/api/imports', ['json' => '{not json'])->assertJsonValidationErrors('json');
        $this->postJson('/api/imports', ['json' => '{"title":"配列ではない"}'])->assertJsonValidationErrors('json');
        $this->postJson('/api/imports', ['json' => '[]'])->assertJsonValidationErrors('json');
        $this->postJson('/api/imports', ['json' => '{"candidates":[{"title":"包んだ形式も可"}]}'])->assertCreated();
    }

    public function test_confirm_only_selected_items_and_idempotent_replay(): void
    {
        $c = $this->importJson([
            ['title' => '候補1', 'project_id' => $this->projectA->id, 'assignee_ids' => [$this->employee->id]],
            ['title' => '候補2'],
            ['title' => '候補3'],
        ]);
        $payload = ['idempotency_key' => 'confirm-key-001', 'items' => [
            ['id' => $c[0]['id'], 'expected_revision' => 1],
            ['id' => $c[2]['id'], 'expected_revision' => 1],
        ]];

        $first = $this->postJson('/api/imports/confirm', $payload)->assertCreated();
        $this->assertFalse($first->json('replayed'));
        $this->assertCount(2, $first->json('task_ids'));
        $this->assertSame(['候補1', '候補3'], Task::query()->orderBy('id')->pluck('title')->all());
        $task = Task::query()->where('title', '候補1')->firstOrFail();
        $this->assertSame('shared', $task->visibility->value);
        $this->assertSame('ai_import', $task->created_via);
        $this->assertSame($this->employee->id, $task->created_by);

        // 再送は同じ結果、二重作成なし
        $again = $this->postJson('/api/imports/confirm', $payload)->assertOk();
        $this->assertTrue($again->json('replayed'));
        $this->assertSame($first->json('task_ids'), $again->json('task_ids'));
        $this->assertSame(2, Task::query()->count());

        // 同じキーで別内容は拒否
        $this->postJson('/api/imports/confirm', ['idempotency_key' => 'confirm-key-001', 'items' => [['id' => $c[1]['id'], 'expected_revision' => 1]]])
            ->assertUnprocessable();
        // 確定済みを別キーで再確定 → 409
        $this->postJson('/api/imports/confirm', ['idempotency_key' => 'confirm-key-002', 'items' => [['id' => $c[0]['id'], 'expected_revision' => 2]]])
            ->assertStatus(409);
        $this->assertSame('pending', ImportCandidate::find($c[1]['id'])->status->value);
    }

    public function test_one_conflict_rolls_back_the_whole_selection(): void
    {
        $c = $this->importJson([['title' => 'A'], ['title' => 'B']]);
        // B は別画面で編集され revision=2 になっている
        $this->patchJson("/api/imports/{$c[1]['id']}", ['title' => 'B改', 'expected_revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);

        $this->postJson('/api/imports/confirm', ['idempotency_key' => 'rollback-key-1', 'items' => [
            ['id' => $c[0]['id'], 'expected_revision' => 1],
            ['id' => $c[1]['id'], 'expected_revision' => 1],
        ]])->assertStatus(409);

        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, ImportBatch::query()->count());
        $this->assertSame('pending', ImportCandidate::find($c[0]['id'])->status->value);

        // 同じキーで正しい版を送り直せば確定できる
        $this->postJson('/api/imports/confirm', ['idempotency_key' => 'rollback-key-1', 'items' => [
            ['id' => $c[0]['id'], 'expected_revision' => 1],
            ['id' => $c[1]['id'], 'expected_revision' => 2],
        ]])->assertCreated();
        $this->assertSame(['A', 'B改'], Task::query()->orderBy('id')->pluck('title')->all());
    }

    public function test_invalid_candidate_rolls_back_everything(): void
    {
        $c = $this->importJson([['title' => 'OK'], ['title' => '']]);
        $this->postJson('/api/imports/confirm', ['idempotency_key' => 'invalid-key-1', 'items' => [
            ['id' => $c[0]['id'], 'expected_revision' => 1],
            ['id' => $c[1]['id'], 'expected_revision' => 1],
        ]])->assertUnprocessable();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_reject_deletes_body_and_keeps_minimal_duplicate_record(): void
    {
        $c = $this->importJson([['title' => '却下する候補', 'description' => '本文']]);
        $this->postJson("/api/imports/{$c[0]['id']}/reject", ['expected_revision' => 1])->assertOk()->assertJsonPath('data.status', 'rejected');

        $candidate = ImportCandidate::find($c[0]['id']);
        $this->assertNull($candidate->payload);
        $this->assertNull($candidate->warnings);
        $this->assertSame(64, strlen($candidate->fingerprint));

        $again = $this->importJson([['title' => '却下する候補']]);
        $this->assertContains('同じ内容の候補は以前に却下されています。', $again[0]['warnings']);
    }

    public function test_synthetic_demo_creates_private_candidates_only(): void
    {
        $this->loginAs($this->employee)->postJson('/api/imports/demo', ['sample' => 'email'])->assertCreated()->assertJsonPath('data.0.source', 'demo_email');
        $this->assertSame(0, Task::query()->count());
        $this->postJson('/api/imports/demo', ['sample' => 'unknown'])->assertUnprocessable();
    }
}

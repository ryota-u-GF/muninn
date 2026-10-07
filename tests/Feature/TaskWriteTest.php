<?php

namespace Tests\Feature;

use App\Models\Revision;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class TaskWriteTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    public function test_crud_with_revisions_and_soft_delete(): void
    {
        $this->loginAs($this->employee);
        $id = $this->postJson('/api/tasks', [
            'title' => '  前後の空白  ', 'project_id' => $this->projectA->id,
            'assignee_ids' => [$this->employee->id, $this->employee2->id],
            'start_date' => '2026-10-01', 'due_date' => '2026-10-10',
        ])->assertCreated()->assertJsonPath('data.title', '前後の空白')
            ->assertJsonPath('data.assignee_ids', [$this->employee->id, $this->employee2->id])
            ->assertJsonPath('data.revision', 1)->json('data.id');

        $this->patchJson("/api/tasks/{$id}", ['status' => 'done', 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.status', 'done');
        $this->assertNotNull(Task::find($id)->completed_at);

        $this->deleteJson("/api/tasks/{$id}", ['expected_revision' => 2])->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertSoftDeleted('tasks', ['id' => $id]);
        $this->getJson('/api/tasks')->assertJsonCount(0, 'data');

        $actions = Revision::query()->where('entity_type', 'task')->where('entity_id', $id)->orderBy('version')->pluck('action')->all();
        $this->assertSame(['create', 'update', 'delete'], $actions);

        // 削除の復元（新しい版として記録）
        $this->postJson("/api/tasks/{$id}/restore", ['version' => 2, 'expected_revision' => 3])
            ->assertOk()->assertJsonPath('data.deleted', false)->assertJsonPath('data.revision', 4);
        $this->assertSame(4, Revision::query()->where('entity_type', 'task')->where('entity_id', $id)->count());
    }

    public function test_validation_rules(): void
    {
        $this->loginAs($this->employee);
        $this->postJson('/api/tasks', ['title' => '   '])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/tasks', ['title' => str_repeat('あ', 301)])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/tasks', ['title' => str_repeat('あ', 300)])->assertCreated(); // 文字数で数える（バイト数ではない）
        $this->postJson('/api/tasks', ['title' => 'x', 'status' => 'unknown'])->assertJsonValidationErrors('status');
        $this->postJson('/api/tasks', ['title' => 'x', 'start_date' => '2026-10-10', 'due_date' => '2026-10-01'])->assertJsonValidationErrors('due_date');
        $this->postJson('/api/tasks', ['title' => 'x', 'due_date' => '2026/10/01'])->assertJsonValidationErrors('due_date');
        $this->postJson('/api/tasks', ['title' => 'x', 'project_id' => 99999])->assertJsonValidationErrors('project_id');
        $this->postJson('/api/tasks', ['title' => 'x', 'assignee_ids' => [99999]])->assertJsonValidationErrors('assignee_ids');
        $this->postJson('/api/tasks', ['title' => 'x', 'source_url' => 'javascript:alert(1)'])->assertJsonValidationErrors('source_url');

        $task = $this->createTaskVia($this->employee, ['title' => 'y', 'start_date' => '2026-10-05']);
        $this->loginAs($this->employee)->patchJson("/api/tasks/{$task->id}", ['due_date' => '2026-10-01', 'expected_revision' => 1])
            ->assertJsonValidationErrors('due_date');
        $this->patchJson("/api/tasks/{$task->id}", ['title' => 'z'])->assertJsonValidationErrors('expected_revision');
    }

    public function test_stale_revision_returns_409_and_changes_nothing(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => '元']);

        // ブラウザ1が先に保存
        $this->loginAs($this->employee)->patchJson("/api/tasks/{$task->id}", ['title' => 'ブラウザ1', 'expected_revision' => 1])->assertOk();
        // ブラウザ2は古い版で保存 → 409
        $this->loginAs($this->employee2)->patchJson("/api/tasks/{$task->id}", ['title' => 'ブラウザ2', 'expected_revision' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'conflict')->assertJsonPath('current_revision', 2);
        $this->deleteJson("/api/tasks/{$task->id}", ['expected_revision' => 1])->assertStatus(409);
        $this->postJson("/api/tasks/{$task->id}/restore", ['version' => 1, 'expected_revision' => 1])->assertStatus(409);

        $this->assertSame('ブラウザ1', $task->fresh()->title);
        $this->assertSame(2, Revision::query()->where('entity_type', 'task')->where('entity_id', $task->id)->count());
    }

    public function test_history_and_state_are_committed_together(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => '元', 'project_id' => $this->projectA->id]);
        // 不正な担当者で失敗した更新は、状態も履歴も残さない
        $this->loginAs($this->employee)->patchJson("/api/tasks/{$task->id}", [
            'title' => '変更', 'project_id' => $this->projectB->id, 'assignee_ids' => [$this->c1->id], 'expected_revision' => 1,
        ])->assertUnprocessable();

        $this->assertSame('元', $task->fresh()->title);
        $this->assertSame(1, $task->fresh()->revision);
        $this->assertSame(1, Revision::query()->where('entity_type', 'task')->where('entity_id', $task->id)->count());
    }

    public function test_board_filters_and_old_done_tasks(): void
    {
        $this->loginAs($this->employee);
        $old = $this->createTaskVia($this->employee, ['title' => '古い完了', 'status' => 'done']);
        Task::query()->whereKey($old->id)->update(['completed_at' => now()->subDays(30)]);
        $this->createTaskVia($this->employee, ['title' => '最近完了', 'status' => 'done']);
        $this->createTaskVia($this->employee, ['title' => '担当なし']);
        $this->createTaskVia($this->employee, ['title' => '自分担当', 'assignee_ids' => [$this->employee->id], 'description' => '検索キーワード']);

        $titles = fn (string $q) => collect($this->loginAs($this->employee)->getJson('/api/tasks'.$q)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

        $this->assertNotContains('古い完了', $titles(''));
        $this->assertContains('古い完了', $titles('?include_old_done=1'));
        $this->assertSame(['自分担当'], $titles('?assignee_id=me'));
        $this->assertSame(['自分担当'], $titles('?q=検索キーワード'));
        $this->assertContains('担当なし', $titles('?assignee_id=none'));
        // LIKE のワイルドカード % はエスケープされ、全件一致にならない
        $this->assertSame([], $titles('?q=%25'));
    }

    public function test_private_task_appears_in_my_tasks_for_owner_only(): void
    {
        $this->createTaskVia($this->employee, ['title' => '私の非公開', 'visibility' => 'private']);

        $mine = collect($this->loginAs($this->employee)->getJson('/api/tasks?mine=1')->json('data'))->pluck('title')->all();
        $this->assertContains('私の非公開', $mine);
        $this->loginAs($this->employee2)->getJson('/api/tasks?mine=1')->assertJsonCount(0, 'data');
    }
}

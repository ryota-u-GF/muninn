<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class TaskAuthorizationTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    public function test_admin_cannot_see_other_users_private_tasks_anywhere(): void
    {
        $private = $this->createTaskVia($this->employee, ['title' => '秘密のメモ', 'visibility' => 'private', 'assignee_ids' => [$this->employee->id]]);

        $this->loginAs($this->admin);
        $this->getJson('/api/tasks')->assertOk()->assertJsonMissing(['title' => '秘密のメモ']);
        $this->getJson('/api/tasks?q=秘密')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/tasks/{$private->id}")->assertNotFound();
        $this->getJson("/api/tasks/{$private->id}/revisions")->assertNotFound();
        $this->getJson('/api/history')->assertOk()->assertJsonMissing(['title' => '秘密のメモ']);
        $this->patchJson("/api/tasks/{$private->id}", ['title' => 'x', 'expected_revision' => 1])->assertNotFound();
        $this->deleteJson("/api/tasks/{$private->id}", ['expected_revision' => 1])->assertNotFound();

        // 本人は見られる
        $this->loginAs($this->employee)->getJson("/api/tasks/{$private->id}")->assertOk()->assertJsonPath('data.title', '秘密のメモ');
    }

    public function test_private_task_assignee_must_be_creator_only(): void
    {
        $this->loginAs($this->employee)->postJson('/api/tasks', [
            'title' => '非公開', 'visibility' => 'private', 'assignee_ids' => [$this->employee2->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');

        $task = $this->createTaskVia($this->employee, ['title' => '共有', 'assignee_ids' => [$this->employee2->id]]);
        // 共有→非公開にするとき、他人が担当なら拒否
        $this->loginAs($this->employee)->patchJson("/api/tasks/{$task->id}", ['visibility' => 'private', 'expected_revision' => $task->revision])
            ->assertUnprocessable();
    }

    public function test_only_creator_can_change_visibility(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => '共有タスク']);

        $this->loginAs($this->admin)->patchJson("/api/tasks/{$task->id}", ['visibility' => 'private', 'expected_revision' => 1])
            ->assertForbidden();
        $this->loginAs($this->employee)->patchJson("/api/tasks/{$task->id}", ['visibility' => 'private', 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.visibility', 'private');
    }

    public function test_owner_id_from_request_is_ignored(): void
    {
        $response = $this->loginAs($this->employee)->postJson('/api/tasks', [
            'title' => 'なりすまし', 'created_by' => $this->admin->id, 'owner_id' => $this->admin->id, 'created_via' => 'ai_import',
        ])->assertCreated();

        $task = Task::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->employee->id, $task->created_by);
        $this->assertSame('manual', $task->created_via);
    }

    public function test_contractor_sees_only_shared_tasks_of_granted_active_projects(): void
    {
        $this->createTaskVia($this->employee, ['title' => 'A共有', 'project_id' => $this->projectA->id]);
        $b = $this->createTaskVia($this->employee, ['title' => 'B共有', 'project_id' => $this->projectB->id]);
        $common = $this->createTaskVia($this->employee, ['title' => '会社共通']);
        $this->createTaskVia($this->employee, ['title' => 'A非公開', 'project_id' => $this->projectA->id, 'visibility' => 'private']);

        $this->loginAs($this->c1);
        $titles = collect($this->getJson('/api/tasks')->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['A共有'], $titles);

        // ID 直指定・検索・件数でも漏れない
        $this->getJson("/api/tasks/{$b->id}")->assertNotFound();
        $this->getJson("/api/tasks/{$common->id}")->assertNotFound();
        $this->getJson('/api/tasks?q=B共有')->assertJsonCount(0, 'data');
        $this->getJson("/api/tasks?project_id={$this->projectB->id}")->assertJsonCount(0, 'data');
        $this->getJson('/api/tasks?project_id=none')->assertJsonCount(0, 'data');
        $this->getJson("/api/tasks/{$b->id}/revisions")->assertNotFound();

        // 案件一覧も付与分のみ
        $projects = collect($this->getJson('/api/projects')->json('data'))->pluck('name')->all();
        $this->assertSame(['案件A'], $projects);
    }

    public function test_contractor_response_omits_sources_and_internal_fields(): void
    {
        $this->createTaskVia($this->employee, [
            'title' => '情報源あり', 'project_id' => $this->projectA->id,
            'source_type' => 'email', 'source_url' => 'https://mail.example.test/secret',
        ]);

        $data = $this->loginAs($this->c2)->getJson('/api/tasks')->assertOk()->json('data.0');
        $this->assertSame('情報源あり', $data['title']);
        foreach (['source_type', 'source_url', 'created_via', 'created_by'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->assertStringNotContainsString('mail.example.test', $this->getJson('/api/tasks')->getContent());
    }

    public function test_view_only_contractor_cannot_write(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => 'A', 'project_id' => $this->projectA->id]);

        $this->loginAs($this->c2);
        $this->postJson('/api/tasks', ['title' => '新規', 'project_id' => $this->projectA->id])->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'doing', 'expected_revision' => 1])->assertForbidden();
        $this->deleteJson("/api/tasks/{$task->id}", ['expected_revision' => 1])->assertForbidden();
        $this->postJson("/api/tasks/{$task->id}/restore", ['version' => 1, 'expected_revision' => 1])->assertForbidden();
    }

    public function test_edit_contractor_can_create_and_edit_but_not_change_locked_fields(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => 'A', 'project_id' => $this->projectA->id, 'assignee_ids' => [$this->employee->id]]);

        $this->loginAs($this->c1);
        // 同一案件内の状態ドラッグ・並び替え・本文編集は成功
        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'doing', 'sort_order' => 5.5, 'project_id' => $this->projectA->id, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.status', 'doing');
        $this->patchJson("/api/tasks/{$task->id}", ['title' => '編集後', 'due_date' => '2026-12-01', 'expected_revision' => 2])->assertOk();

        // 案件・公開区分・担当者・情報源の変更、削除、復元は拒否
        $this->patchJson("/api/tasks/{$task->id}", ['project_id' => $this->projectB->id, 'expected_revision' => 3])->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['project_id' => null, 'expected_revision' => 3])->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['visibility' => 'private', 'expected_revision' => 3])->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['assignee_ids' => [$this->c1->id], 'expected_revision' => 3])->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['source_url' => 'https://x.test', 'expected_revision' => 3])->assertForbidden();
        $this->deleteJson("/api/tasks/{$task->id}", ['expected_revision' => 3])->assertForbidden();
        $this->postJson("/api/tasks/{$task->id}/restore", ['version' => 1, 'expected_revision' => 3])->assertForbidden();
        $this->assertSame(3, $task->fresh()->revision);

        // 作成：担当案件の共有のみ
        $this->postJson('/api/tasks', ['title' => '外注作成', 'project_id' => $this->projectA->id])->assertCreated()
            ->assertJsonPath('data.visibility', 'shared');
        $this->postJson('/api/tasks', ['title' => '他案件', 'project_id' => $this->projectB->id])->assertForbidden();
        $this->postJson('/api/tasks', ['title' => '共通'])->assertForbidden();
        $this->postJson('/api/tasks', ['title' => '非公開', 'project_id' => $this->projectA->id, 'visibility' => 'private'])->assertForbidden();
        $this->postJson('/api/tasks', ['title' => '他人担当', 'project_id' => $this->projectA->id, 'assignee_ids' => [$this->employee->id]])->assertForbidden();
    }

    public function test_contractor_cannot_access_other_project_even_with_edit_elsewhere(): void
    {
        $taskB = $this->createTaskVia($this->employee, ['title' => 'B', 'project_id' => $this->projectB->id]);

        $this->loginAs($this->c1)->patchJson("/api/tasks/{$taskB->id}", ['status' => 'done', 'expected_revision' => 1])->assertNotFound();
        $this->loginAs($this->c3)->patchJson("/api/tasks/{$taskB->id}", ['status' => 'done', 'expected_revision' => 1])->assertOk();
    }

    public function test_revoking_access_takes_effect_immediately(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => 'A', 'project_id' => $this->projectA->id]);
        $this->loginAs($this->c1)->getJson("/api/tasks/{$task->id}")->assertOk();

        $this->loginAs($this->admin)->putJson('/api/project-access', [
            'user_id' => $this->c1->id, 'project_id' => $this->projectA->id, 'permission' => 'none', 'expected_permission' => 'edit',
        ])->assertOk();

        $this->loginAs($this->c1)->getJson("/api/tasks/{$task->id}")->assertNotFound();
        $this->getJson('/api/tasks')->assertJsonCount(0, 'data');
        $this->getJson('/api/projects')->assertJsonCount(0, 'data');
    }

    public function test_archived_project_is_hidden_from_contractors(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => 'A', 'project_id' => $this->projectA->id]);
        $this->loginAs($this->employee)->patchJson("/api/projects/{$this->projectA->id}", ['archived' => true, 'expected_revision' => 1])->assertOk();

        $this->loginAs($this->c1)->getJson("/api/tasks/{$task->id}")->assertNotFound();
        $this->getJson('/api/projects')->assertJsonCount(0, 'data');
        // 社員の通常一覧からも外れるが、データは残る
        $this->loginAs($this->employee)->getJson('/api/tasks')->assertJsonCount(0, 'data');
        $this->getJson('/api/tasks?include_archived=1')->assertJsonCount(1, 'data');
    }

    public function test_members_list_for_contractor_is_limited_and_has_no_other_emails(): void
    {
        $this->createTaskVia($this->employee, ['title' => 'A', 'project_id' => $this->projectA->id, 'assignee_ids' => [$this->employee->id]]);
        $this->createTaskVia($this->employee, ['title' => 'B', 'project_id' => $this->projectB->id, 'assignee_ids' => [$this->employee2->id]]);

        $members = collect($this->loginAs($this->c2)->getJson('/api/members')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$this->c2->id, $this->employee->id], $members->pluck('id')->all());
        $this->assertNull($members->firstWhere('id', $this->employee->id)['email'] ?? null);
        $this->assertSame($this->c2->email, $members->firstWhere('id', $this->c2->id)['email']);

        $this->loginAs($this->employee)->getJson('/api/members')->assertOk()->assertJsonFragment(['email' => $this->employee2->email]);
    }

    public function test_contractor_assignee_requires_project_access(): void
    {
        $this->loginAs($this->employee)->postJson('/api/tasks', [
            'title' => 'B担当に外注1', 'project_id' => $this->projectB->id, 'assignee_ids' => [$this->c1->id],
        ])->assertUnprocessable();

        $this->postJson('/api/tasks', [
            'title' => 'TVは担当不可', 'assignee_ids' => [$this->tv->id],
        ])->assertUnprocessable();
    }

    public function test_contractor_and_tv_cannot_use_admin_or_import_endpoints(): void
    {
        foreach ([$this->c1, $this->tv, $this->employee] as $user) {
            $this->loginAs($user)->getJson('/api/project-access')->assertForbidden();
            $this->putJson('/api/project-access', ['user_id' => $this->c1->id, 'project_id' => $this->projectB->id, 'permission' => 'edit', 'expected_permission' => 'none'])->assertForbidden();
            $this->getJson('/api/users')->assertForbidden();
        }
        $this->loginAs($this->c1)->getJson('/api/imports')->assertForbidden();
        $this->postJson('/api/imports', ['json' => '[{"title":"x"}]'])->assertForbidden();
        $this->getJson('/api/tv')->assertForbidden();
        $this->postJson('/api/projects', ['name' => '勝手な案件'])->assertForbidden();
        $this->patchJson("/api/projects/{$this->projectA->id}", ['name' => 'x', 'expected_revision' => 1])->assertForbidden();
    }

    public function test_internal_member_can_move_task_across_projects(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => 'A', 'project_id' => $this->projectA->id]);

        $this->loginAs($this->employee2)->patchJson("/api/tasks/{$task->id}", ['project_id' => $this->projectB->id, 'status' => 'doing', 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.project_id', $this->projectB->id);
    }
}

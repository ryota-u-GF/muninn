<?php

namespace Tests\Feature;

use App\Models\Revision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    public function test_private_versions_before_publishing_are_not_leaked(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => '社外秘の下書き', 'description' => '秘密の本文', 'visibility' => 'private']);
        $this->loginAs($this->employee)->patchJson("/api/tasks/{$task->id}", [
            'visibility' => 'shared', 'title' => '公開タイトル', 'description' => '公開本文', 'expected_revision' => 1,
        ])->assertOk();

        // 他の社員・管理者：公開後の版だけ見え、公開前の内容は伏せられる
        foreach ([$this->admin, $this->employee2] as $viewer) {
            $this->loginAs($viewer);
            $list = $this->getJson("/api/tasks/{$task->id}/revisions")->assertOk();
            $this->assertSame([2], collect($list->json('data'))->pluck('version')->all());
            $this->assertStringNotContainsString('社外秘', $list->getContent());

            $this->getJson("/api/tasks/{$task->id}/revisions/1")->assertNotFound();
            $preview = $this->getJson("/api/tasks/{$task->id}/revisions/2")->assertOk();
            $this->assertNull($preview->json('data.before'));
            $this->assertTrue($preview->json('data.before_hidden'));
            $this->assertStringNotContainsString('秘密の本文', $preview->getContent());

            $global = $this->getJson('/api/history')->assertOk()->getContent();
            $this->assertStringNotContainsString('社外秘', $global);

            // 非公開版への復元は不可
            $this->postJson("/api/tasks/{$task->id}/restore", ['version' => 1, 'expected_revision' => 2])->assertNotFound();
        }

        // 作成者本人は全履歴を見られる
        $this->loginAs($this->employee)->getJson("/api/tasks/{$task->id}/revisions/1")->assertOk()->assertJsonPath('data.after.title', '社外秘の下書き');
    }

    public function test_history_of_task_made_private_is_hidden_from_others(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => '共有から非公開へ']);
        $this->loginAs($this->employee)->patchJson("/api/tasks/{$task->id}", ['visibility' => 'private', 'expected_revision' => 1])->assertOk();

        $this->loginAs($this->admin)->getJson("/api/tasks/{$task->id}/revisions")->assertNotFound();
        $this->assertStringNotContainsString('共有から非公開へ', $this->getJson('/api/history')->getContent());
    }

    public function test_contractor_history_is_limited_to_versions_in_granted_projects(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => 'B時代のタイトル', 'project_id' => $this->projectB->id, 'source_url' => 'https://secret.example.test']);
        $this->loginAs($this->employee)->patchJson("/api/tasks/{$task->id}", ['project_id' => $this->projectA->id, 'title' => 'A移動後', 'expected_revision' => 1])->assertOk();
        $other = $this->createTaskVia($this->employee, ['title' => 'B専用', 'project_id' => $this->projectB->id]);

        $this->loginAs($this->c2);
        $list = $this->getJson("/api/tasks/{$task->id}/revisions")->assertOk();
        $this->assertSame([2], collect($list->json('data'))->pluck('version')->all());
        $preview = $this->getJson("/api/tasks/{$task->id}/revisions/2")->assertOk();
        $this->assertNull($preview->json('data.before'));
        $this->assertArrayNotHasKey('source_url', $preview->json('data.after'));
        $this->assertArrayNotHasKey('created_via', $preview->json('data.after'));
        $this->assertStringNotContainsString('B時代', $preview->getContent());
        $this->getJson("/api/tasks/{$task->id}/revisions/1")->assertNotFound();

        $global = $this->getJson('/api/history')->assertOk();
        $this->assertStringNotContainsString('B専用', $global->getContent());
        $this->assertStringNotContainsString('B時代', $global->getContent());
        $this->getJson("/api/tasks/{$other->id}/revisions")->assertNotFound();
        // 監査（権限・案件・利用者）は外注に見えない
        $this->getJson('/api/history?type=project_access')->assertJsonCount(0, 'data');
        // 社内メンバーの名前は伏せる（自分と閲覧できるタスクの担当者以外）
        $this->assertSame('社内メンバー', $global->json('data.0.actor.name'));
    }

    public function test_access_changes_are_audited_and_visible_to_admin_only(): void
    {
        $this->loginAs($this->admin)->putJson('/api/project-access', [
            'user_id' => $this->c2->id, 'project_id' => $this->projectA->id, 'permission' => 'edit', 'expected_permission' => 'view',
        ])->assertOk()->assertJsonPath('permission', 'edit');

        // 古い前提での変更は 409
        $this->putJson('/api/project-access', [
            'user_id' => $this->c2->id, 'project_id' => $this->projectA->id, 'permission' => 'none', 'expected_permission' => 'view',
        ])->assertStatus(409);

        $audit = Revision::query()->where('entity_type', 'project_access')->sole();
        $this->assertSame('change', $audit->action);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame('view', $audit->before['permission']);
        $this->assertSame('edit', $audit->after['permission']);

        $this->getJson('/api/history?type=project_access')->assertJsonCount(1, 'data');
        $this->loginAs($this->employee)->getJson('/api/history?type=project_access')->assertJsonCount(0, 'data');

        // 外注以外には付与できない
        $this->loginAs($this->admin)->putJson('/api/project-access', [
            'user_id' => $this->employee->id, 'project_id' => $this->projectA->id, 'permission' => 'edit', 'expected_permission' => 'none',
        ])->assertUnprocessable();
    }

    public function test_revisions_are_append_only(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => 'x']);
        $revision = Revision::query()->where('entity_id', $task->id)->firstOrFail();

        try {
            $revision->update(['action' => 'tampered']);
            $this->fail('更新できてはいけない');
        } catch (LogicException) {
        }
        try {
            $revision->delete();
            $this->fail('削除できてはいけない');
        } catch (LogicException) {
        }
        $this->assertSame('create', $revision->fresh()->action);
    }

    public function test_history_is_paginated_with_limits(): void
    {
        $task = $this->createTaskVia($this->employee, ['title' => 'v1']);
        $this->loginAs($this->employee);
        for ($v = 1; $v <= 55; $v++) {
            $this->patchJson("/api/tasks/{$task->id}", ['title' => "v{$v}x", 'expected_revision' => $v])->assertOk();
        }

        $page1 = $this->getJson("/api/tasks/{$task->id}/revisions")->assertOk();
        $this->assertCount(50, $page1->json('data'));
        $this->assertSame(56, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        $this->assertCount(6, $this->getJson("/api/tasks/{$task->id}/revisions?page=2")->json('data'));
        // 表示件数の制限で履歴を削除しない
        $this->assertSame(56, Revision::query()->where('entity_id', $task->id)->where('entity_type', 'task')->count());
    }
}

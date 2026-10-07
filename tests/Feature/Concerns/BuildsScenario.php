<?php

namespace Tests\Feature\Concerns;

use App\Enums\AccessPermission;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * 権限テスト用の最小構成：
 * 管理者・社員2名、外注1（A編集）・外注2（A閲覧）・外注3（B編集）、案件 A/B、TV
 */
trait BuildsScenario
{
    protected User $admin;

    protected User $employee;

    protected User $employee2;

    protected User $c1;

    protected User $c2;

    protected User $c3;

    protected User $tv;

    protected Project $projectA;

    protected Project $projectB;

    protected function buildScenario(): void
    {
        $this->admin = User::factory()->admin()->create(['name' => '管理者']);
        $this->employee = User::factory()->create(['name' => '社員']);
        $this->employee2 = User::factory()->create(['name' => '社員2']);
        $this->c1 = User::factory()->contractor()->create(['name' => '外注1']);
        $this->c2 = User::factory()->contractor()->create(['name' => '外注2']);
        $this->c3 = User::factory()->contractor()->create(['name' => '外注3']);
        $this->tv = User::factory()->tv()->create(['name' => 'TV']);
        $this->projectA = Project::factory()->create(['name' => '案件A']);
        $this->projectB = Project::factory()->create(['name' => '案件B']);
        $this->grant($this->c1, $this->projectA, AccessPermission::Edit);
        $this->grant($this->c2, $this->projectA, AccessPermission::View);
        $this->grant($this->c3, $this->projectB, AccessPermission::Edit);
    }

    /** API 経由で作成（履歴も記録される） */
    protected function createTaskVia(User $actor, array $data): Task
    {
        $response = $this->loginAs($actor)->postJson('/api/tasks', $data + ['title' => 'テスト']);
        $response->assertCreated();

        return Task::query()->findOrFail($response->json('data.id'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\ImportCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class TvApiTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 時計を注入（2026-10-07 10:00 JST）。端末・サーバーの実時刻は変更しない。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 01:00:00', 'UTC'));
        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_returns_only_whitelisted_fields_for_relevant_shared_tasks(): void
    {
        $this->createTaskVia($this->employee, ['title' => '進行中', 'status' => 'doing', 'project_id' => $this->projectA->id, 'assignee_ids' => [$this->employee->id], 'description' => '本文は返さない', 'source_url' => 'https://secret.example.test']);
        $this->createTaskVia($this->employee, ['title' => '本日期限', 'due_date' => '2026-10-07']);
        $this->createTaskVia($this->employee, ['title' => '期間中', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31']);
        $this->createTaskVia($this->employee, ['title' => '未来', 'start_date' => '2026-10-20', 'due_date' => '2026-10-31']);
        $this->createTaskVia($this->employee, ['title' => '完了', 'status' => 'done', 'due_date' => '2026-10-07']);
        $this->createTaskVia($this->employee, ['title' => '非公開', 'status' => 'doing', 'visibility' => 'private']);
        $deleted = $this->createTaskVia($this->employee, ['title' => '削除済み', 'status' => 'doing']);
        $this->loginAs($this->employee)->deleteJson("/api/tasks/{$deleted->id}", ['expected_revision' => 1]);
        $archived = $this->createTaskVia($this->employee, ['title' => 'アーカイブ案件', 'status' => 'doing', 'project_id' => $this->projectB->id]);
        $this->projectB->forceFill(['archived_at' => now()])->save();
        ImportCandidate::query()->create(['owner_id' => $this->employee->id, 'source' => 'manual_json', 'payload' => ['title' => 'AI候補'], 'fingerprint' => str_repeat('a', 64)]);
        Document::factory()->create(['title' => '文書', 'created_by' => $this->employee->id]);

        $response = $this->loginAs($this->tv)->getJson('/api/tv')->assertOk();
        $json = $response->json();

        $this->assertSame('2026-10-07', $json['today']);
        $this->assertEqualsCanonicalizing(['進行中', '本日期限', '期間中'], collect($json['tasks'])->pluck('title')->all());
        $this->assertSame(['generated_at', 'today', 'users', 'projects', 'tasks'], array_keys($json));
        $this->assertSame(['id', 'title', 'status', 'project_id', 'due_date', 'assignee_ids'], array_keys($json['tasks'][0]));
        $this->assertSame(['id', 'name'], array_keys($json['users'][0]));
        $this->assertSame(['id', 'name'], array_keys($json['projects'][0]));

        $content = $response->getContent();
        foreach (['@example.test', '本文は返さない', 'secret.example.test', 'AI候補', '非公開', '削除済み', 'アーカイブ案件', 'email'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    public function test_today_uses_tokyo_timezone(): void
    {
        // UTC では 10/06 だが東京では 10/07 08:00
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-06 23:00:00', 'UTC'));
        $this->createTaskVia($this->employee, ['title' => '東京の今日', 'due_date' => '2026-10-07']);

        $this->loginAs($this->tv)->getJson('/api/tv')->assertJsonPath('today', '2026-10-07')->assertJsonPath('tasks.0.title', '東京の今日');
    }

    public function test_contractors_cannot_use_tv_api(): void
    {
        $this->loginAs($this->c1)->getJson('/api/tv')->assertForbidden();
        $this->loginAs($this->employee)->getJson('/api/tv')->assertOk();
    }
}

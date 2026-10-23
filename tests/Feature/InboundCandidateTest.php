<?php

namespace Tests\Feature;

use App\Models\ImportCandidate;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class InboundCandidateTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    private const TOKEN = 'test-inbound-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
        config(['gf.inbound.token' => self::TOKEN]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function item(array $overrides = []): array
    {
        return $overrides + [
            'external_id' => 'hub-1',
            'owner_email' => $this->employee->email,
            'title' => '見積を送る',
            'due_date' => '2026-10-30',
            'source_type' => 'email',
        ];
    }

    /** @param  list<array<string, mixed>>  $items */
    private function send(array $items, ?string $token = self::TOKEN): TestResponse
    {
        $headers = $token === null ? [] : ['Authorization' => "Bearer {$token}"];

        return $this->postJson('/api/inbound/candidates', ['items' => $items], $headers);
    }

    public function test_requires_the_shared_token(): void
    {
        $this->send([$this->item()], null)->assertUnauthorized();
        $this->send([$this->item()], 'wrong')->assertUnauthorized();
        $this->assertSame(0, ImportCandidate::query()->count());

        config(['gf.inbound.token' => '']);
        $this->send([$this->item()], '')->assertStatus(503);
    }

    public function test_creates_a_pending_candidate_for_the_owner_only(): void
    {
        $this->send([$this->item(['assignee_emails' => [$this->employee2->email, 'nobody@example.com']])])
            ->assertCreated()->assertJsonPath('data.0.result', 'created');

        $candidate = ImportCandidate::query()->sole();
        $this->assertSame($this->employee->id, $candidate->owner_id);
        $this->assertSame('inbound', $candidate->source);
        $this->assertSame([$this->employee2->id], $candidate->payload['assignee_ids']);
        $this->assertNotEmpty($candidate->warnings); // 特定できない担当者は警告つきで未設定
        $this->assertSame(0, Task::query()->count());

        $this->loginAs($this->employee)->getJson('/api/imports')->assertJsonCount(1, 'data');
        $this->loginAs($this->employee2)->getJson('/api/imports')->assertJsonCount(0, 'data');
    }

    public function test_resending_the_same_external_id_does_not_duplicate_or_overwrite(): void
    {
        $this->send([$this->item()])->assertCreated();
        $id = ImportCandidate::query()->sole()->id;
        $this->loginAs($this->employee)->patchJson("/api/imports/{$id}", ['title' => '編集した', 'expected_revision' => 1])->assertOk();

        $this->send([$this->item(['title' => '送り直し'])])->assertOk()->assertJsonPath('data.0.result', 'duplicate');

        $this->assertSame(1, ImportCandidate::query()->count());
        $this->assertSame('編集した', ImportCandidate::query()->sole()->payload['title']);
    }

    public function test_rejects_unknown_or_ineligible_owners_per_item(): void
    {
        $response = $this->send([
            $this->item(['external_id' => 'a', 'owner_email' => 'nobody@example.com']),
            $this->item(['external_id' => 'b', 'owner_email' => $this->c1->email]),
            $this->item(['external_id' => 'c']),
        ])->assertCreated();

        $this->assertSame(['rejected', 'rejected', 'created'], array_column($response->json('data'), 'result'));
        $this->assertSame(1, ImportCandidate::query()->count());
    }

    public function test_confirming_an_inbound_candidate_creates_the_task(): void
    {
        $this->send([$this->item(['project_id' => $this->projectA->id])])->assertCreated();
        $candidate = ImportCandidate::query()->sole();

        $this->loginAs($this->employee)->postJson('/api/imports/confirm', [
            'idempotency_key' => 'inbound-confirm-1',
            'items' => [['id' => $candidate->id, 'expected_revision' => 1]],
        ])->assertCreated();

        $this->assertSame('見積を送る', Task::query()->sole()->title);
    }

    public function test_validates_the_request_shape(): void
    {
        $this->send([])->assertUnprocessable();
        $this->send([['title' => 'external_id なし', 'owner_email' => $this->employee->email]])->assertUnprocessable();
    }
}

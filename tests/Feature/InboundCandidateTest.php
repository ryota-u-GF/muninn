<?php

namespace Tests\Feature;

use App\Actions\Imports\ImportService;
use App\Models\ImportCandidate;
use App\Models\Task;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
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

    public function test_keeps_the_source_link_and_only_accepts_https(): void
    {
        $url = 'https://mail.google.com/mail/u/0/#all/abc';
        $this->send([$this->item(['source_url' => $url])])->assertCreated();

        $this->loginAs($this->employee)->getJson('/api/imports')->assertJsonPath('data.0.source_url', $url);

        $this->send([$this->item(['external_id' => 'x', 'source_url' => 'javascript:alert(1)'])])->assertOk()->assertJsonPath('data.0.result', 'rejected');
        $this->send([$this->item(['external_id' => 'y', 'source_url' => 'http://example.com'])])->assertOk()->assertJsonPath('data.0.result', 'rejected');
        $this->assertSame(1, ImportCandidate::query()->count());
    }

    public function test_an_invalid_item_is_rejected_alone_and_the_rest_are_created(): void
    {
        $response = $this->send([
            $this->item(['external_id' => 'ok-1']),
            $this->item(['external_id' => 'too-long', 'title' => str_repeat('あ', 301)]),
            $this->item(['external_id' => 'bad-url', 'source_url' => 'http://example.com']),
            ['title' => 'external_id なし', 'owner_email' => $this->employee->email],
            $this->item(['external_id' => 'ok-2']),
        ])->assertCreated();

        $this->assertSame(['created', 'rejected', 'rejected', 'rejected', 'created'], array_column($response->json('data'), 'result'));
        $this->assertNotEmpty($response->json('data.1.message'));
        $this->assertNull($response->json('data.3.external_id'));
        $this->assertSame(2, ImportCandidate::query()->count());
    }

    public function test_the_batch_shape_is_validated(): void
    {
        $this->send([])->assertUnprocessable();
        $this->postJson('/api/inbound/candidates', [], ['Authorization' => 'Bearer '.self::TOKEN])->assertUnprocessable();
    }

    public function test_duplicate_reports_the_state_of_the_existing_candidate(): void
    {
        $this->send([$this->item()])->assertCreated();
        $candidate = ImportCandidate::query()->sole();
        $this->loginAs($this->employee)->postJson("/api/imports/{$candidate->id}/reject", ['expected_revision' => 1])->assertOk();

        $this->send([$this->item(['title' => '直した内容'])])->assertOk()
            ->assertJsonPath('data.0.result', 'duplicate')
            ->assertJsonPath('data.0.candidate_id', $candidate->id)
            ->assertJsonPath('data.0.status', 'rejected');
    }

    public function test_a_concurrent_duplicate_send_returns_the_existing_candidate(): void
    {
        $this->partialMock(ImportService::class, function (MockInterface $mock) {
            // 存在確認の後、保存の直前に別のリクエストが同じ候補を作った状況
            $mock->shouldReceive('createCandidates')->once()->andReturnUsing(function () {
                ImportCandidate::query()->create([
                    'owner_id' => $this->employee->id, 'source' => 'inbound', 'external_id' => 'hub-1',
                    'status' => 'pending', 'fingerprint' => 'x',
                ]);
                throw new UniqueConstraintViolationException('mysql', 'insert', [], new Exception('duplicate'));
            });
        });

        $this->send([$this->item()])->assertOk()
            ->assertJsonPath('data.0.result', 'duplicate')
            ->assertJsonPath('data.0.status', 'pending');
        $this->assertSame(1, ImportCandidate::query()->count());
    }

    public function test_assignees_without_project_access_are_dropped_with_a_warning(): void
    {
        $this->send([$this->item(['assignee_emails' => [$this->c3->email, $this->employee2->email]])])->assertCreated();

        $candidate = ImportCandidate::query()->sole();
        $this->assertSame([$this->employee2->id], $candidate->payload['assignee_ids']);
        $this->assertNotEmpty($candidate->warnings);
    }
}

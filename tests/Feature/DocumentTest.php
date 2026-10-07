<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\BuildsScenario;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    public function test_minutes_crud_search_and_calendar_filter(): void
    {
        $this->loginAs($this->employee);
        $id = $this->postJson('/api/documents', [
            'kind' => 'minutes', 'title' => '定例会議', 'project_id' => $this->projectA->id,
            'meeting_date' => '2026-10-07', 'participants' => '佐藤、鈴木', 'body' => "## 決定事項\n予算を承認",
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/documents', ['kind' => 'minutes', 'title' => '同日の別会議', 'meeting_date' => '2026-10-07', 'participants' => '山田'])->assertCreated();
        $this->postJson('/api/documents', ['kind' => 'minutes', 'title' => '翌月の会議', 'meeting_date' => '2026-11-02'])->assertCreated();
        $this->postJson('/api/documents', ['kind' => 'minutes', 'title' => '会議日なし'])->assertJsonValidationErrors('meeting_date');

        $titles = fn (string $q) => collect($this->getJson('/api/documents?kind=minutes'.$q)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['同日の別会議', '定例会議'], $titles('&date=2026-10-07'));
        $this->assertSame(['定例会議'], $titles('&q=予算'));           // 本文検索
        $this->assertSame(['同日の別会議'], $titles('&q=山田'));        // 参加者検索
        $this->assertSame(['翌月の会議'], $titles('&from=2026-10-08&to=2026-11-30'));
        $this->assertSame(['定例会議'], $titles('&project_id='.$this->projectA->id));

        // 一覧は本文を含まず、詳細で取得
        $this->assertArrayNotHasKey('body', $this->getJson('/api/documents?kind=minutes')->json('data.0'));
        $this->getJson("/api/documents/{$id}")->assertJsonPath('data.body', "## 決定事項\n予算を承認");

        // 会議日・参加者を変更 → 履歴から復元で正しく戻り、新版が増える
        $this->patchJson("/api/documents/{$id}", ['meeting_date' => '2026-10-09', 'participants' => '佐藤', 'body' => '変更後', 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->postJson("/api/documents/{$id}/restore", ['version' => 1, 'expected_revision' => 2])
            ->assertOk()
            ->assertJsonPath('data.meeting_date', '2026-10-07')
            ->assertJsonPath('data.participants', '佐藤、鈴木')
            ->assertJsonPath('data.body', "## 決定事項\n予算を承認")
            ->assertJsonPath('data.revision', 3);
        $this->assertSame(['restore', 'update', 'create'], collect($this->getJson("/api/documents/{$id}/revisions")->json('data'))->pluck('action')->all());

        // 競合
        $this->patchJson("/api/documents/{$id}", ['title' => '古い版から', 'expected_revision' => 2])->assertStatus(409);
    }

    public function test_limits_are_enforced_in_characters(): void
    {
        $this->loginAs($this->employee);
        $this->postJson('/api/documents', ['kind' => 'document', 'title' => 'x', 'body' => str_repeat('あ', 100000)])->assertCreated();
        $this->postJson('/api/documents', ['kind' => 'document', 'title' => 'x', 'body' => str_repeat('a', 100001)])->assertJsonValidationErrors('body');
        $this->postJson('/api/documents', ['kind' => 'document', 'title' => str_repeat('あ', 301)])->assertJsonValidationErrors('title');
        $this->postJson('/api/documents', ['kind' => 'minutes', 'title' => 'x', 'meeting_date' => '2026-10-07', 'participants' => str_repeat('あ', 2001)])->assertJsonValidationErrors('participants');
        $this->postJson('/api/documents', ['kind' => 'minutes', 'title' => 'x', 'meeting_date' => '2026-02-30'])->assertJsonValidationErrors('meeting_date');
    }

    public function test_text_file_import_is_validated(): void
    {
        $this->loginAs($this->employee);
        $this->post('/api/documents/parse-file', ['file' => UploadedFile::fake()->createWithContent('memo.md', "\xEF\xBB\xBF# 見出し\r\n本文")], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('text', "# 見出し\n本文");
        $this->post('/api/documents/parse-file', ['file' => UploadedFile::fake()->createWithContent('memo.txt', "\x82\xA0\x82\xA2")], ['Accept' => 'application/json'])
            ->assertUnprocessable(); // Shift_JIS は拒否
        $this->post('/api/documents/parse-file', ['file' => UploadedFile::fake()->createWithContent('memo.pdf', 'x')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->post('/api/documents/parse-file', ['file' => UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 410 * 1024))], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->post('/api/documents/parse-file', ['file' => UploadedFile::fake()->createWithContent('long.txt', str_repeat('a', 100001))], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_private_documents_and_contractor_rules(): void
    {
        $private = Document::factory()->private()->create(['created_by' => $this->employee->id, 'title' => '個人メモ']);
        $a = $this->loginAs($this->employee)->postJson('/api/documents', ['kind' => 'document', 'title' => 'A仕様', 'project_id' => $this->projectA->id])->json('data');
        $b = $this->postJson('/api/documents', ['kind' => 'document', 'title' => 'B仕様', 'project_id' => $this->projectB->id])->json('data');
        $this->postJson('/api/documents', ['kind' => 'document', 'title' => '共通規程'])->assertCreated();

        $this->loginAs($this->admin)->getJson("/api/documents/{$private->id}")->assertNotFound();
        $this->getJson('/api/documents?q=個人')->assertJsonCount(0, 'data');

        // 閲覧外注
        $this->loginAs($this->c2);
        $this->assertSame(['A仕様'], collect($this->getJson('/api/documents')->json('data'))->pluck('title')->all());
        $this->getJson("/api/documents/{$b['id']}")->assertNotFound();
        $this->patchJson("/api/documents/{$a['id']}", ['body' => 'x', 'expected_revision' => 1])->assertForbidden();
        $this->postJson('/api/documents', ['kind' => 'document', 'title' => 'x', 'project_id' => $this->projectA->id])->assertForbidden();

        // 編集外注：本文編集は可、案件・公開区分の変更・削除・復元は不可
        $this->loginAs($this->c1);
        $this->patchJson("/api/documents/{$a['id']}", ['body' => '外注が編集', 'expected_revision' => 1])->assertOk();
        $this->patchJson("/api/documents/{$a['id']}", ['project_id' => $this->projectB->id, 'expected_revision' => 2])->assertForbidden();
        $this->patchJson("/api/documents/{$a['id']}", ['visibility' => 'private', 'expected_revision' => 2])->assertForbidden();
        $this->deleteJson("/api/documents/{$a['id']}", ['expected_revision' => 2])->assertForbidden();
        $this->postJson("/api/documents/{$a['id']}/restore", ['version' => 1, 'expected_revision' => 2])->assertForbidden();
        $this->postJson('/api/documents', ['kind' => 'minutes', 'title' => '外注議事録', 'meeting_date' => '2026-10-07', 'project_id' => $this->projectA->id])->assertCreated();
        $this->postJson('/api/documents', ['kind' => 'document', 'title' => 'B', 'project_id' => $this->projectB->id])->assertForbidden();
    }
}

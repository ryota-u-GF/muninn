<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Auth\GmailController as GmailOAuth;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveGmailSettingsRequest;
use App\Models\GmailConnection;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Ai\AiRequestFailedException;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\GmailException;
use App\Services\Gmail\GmailSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** 利用者本人の Gmail 連携の設定・取得・解除。更新トークンは返さない。 */
class GmailController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    public function update(SaveGmailSettingsRequest $request): JsonResponse
    {
        $connection = GmailConnection::query()->where('user_id', $request->user()->id)->first();
        abort_if($connection === null, 404, 'Gmailが連携されていません。');

        $connection->update([
            'scope_mode' => $request->validated('scope_mode'),
            'label_name' => $request->validated('scope_mode') === 'label' ? trim((string) $request->validated('label_name')) : null,
            'exclude_promotions' => $request->boolean('exclude_promotions'),
            'initial_days' => (int) $request->validated('initial_days'),
            'auto_sync' => $request->boolean('auto_sync'),
            // 対象を変えたら、初回と同じ範囲から取り込み直す（取り込み済みのメールは二重にならない）
            'last_synced_at' => null,
        ]);

        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    public function destroy(Request $request, GmailClient $gmail): JsonResponse
    {
        $connection = GmailConnection::query()->where('user_id', $request->user()->id)->first();
        if ($connection !== null) {
            $gmail->revoke($connection->refresh_token);
            $connection->delete();
        }

        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    /**
     * 新着メールを取り込む。auto=true（アプリを開いたときの自動取得）は、設定がオフのときと、
     * 前回の取得から間もないときは何もしない。同じ人の取得が同時に走ったときは busy を返す。
     */
    public function sync(Request $request, GmailSyncer $syncer): JsonResponse
    {
        $user = $request->user();
        $idle = ['processed' => 0, 'skipped' => 0, 'candidates' => 0, 'has_more' => false];

        if ($request->boolean('auto')) {
            $connection = GmailConnection::query()->where('user_id', $user->id)->first();
            if ($connection === null || ! $connection->auto_sync) {
                return response()->json(['data' => $idle + ['status' => 'disabled']]);
            }
            $minutes = (int) config('gf.gmail.auto_sync_min_minutes');
            if ($connection->last_synced_at !== null && $connection->last_synced_at->greaterThan(CarbonImmutable::now()->subMinutes($minutes))) {
                return response()->json(['data' => $idle + ['status' => 'recent']]);
            }
        }

        $lock = Cache::lock('gmail-sync:'.$user->id, 130);
        if (! $lock->get()) {
            return response()->json(['data' => $idle + ['status' => 'busy']]);
        }

        set_time_limit(120);
        try {
            $result = $syncer->sync($user);
        } catch (GmailException|AiNotConfiguredException|AiRequestFailedException $e) {
            throw ValidationException::withMessages(['gmail' => $e->getMessage()]);
        } finally {
            $lock->release();
        }

        return response()->json(['data' => $result + ['status' => 'done']]);
    }

    /** @return array<string, mixed> */
    private function payload(int $userId): array
    {
        $connection = GmailConnection::query()->where('user_id', $userId)->first();

        return [
            'oauth_ready' => GmailOAuth::configured(),
            'connected' => $connection !== null,
            'google_email' => $connection?->google_email,
            'scope_mode' => $connection->scope_mode ?? 'inbox',
            'label_name' => $connection?->label_name,
            'exclude_promotions' => $connection->exclude_promotions ?? true,
            'initial_days' => $connection->initial_days ?? 3,
            'auto_sync' => $connection->auto_sync ?? true,
            'auto_sync_min_minutes' => config('gf.gmail.auto_sync_min_minutes'),
            'initial_days_options' => config('gf.gmail.initial_days_options'),
            'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
            'last_error' => $connection?->last_error,
        ];
    }
}

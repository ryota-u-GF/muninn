<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Auth\MeetController as MeetOAuth;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveMeetSettingsRequest;
use App\Models\AiSetting;
use App\Models\MeetConnection;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Ai\AiRequestFailedException;
use App\Services\Meet\MeetClient;
use App\Services\Meet\MeetException;
use App\Services\Meet\MeetNotesSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** 利用者本人の Meet のメモの連携の設定・取得・解除。更新トークンは返さない。 */
class MeetController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    public function update(SaveMeetSettingsRequest $request): JsonResponse
    {
        $connection = MeetConnection::query()->where('user_id', $request->user()->id)->first();
        abort_if($connection === null, 404, 'Meetのメモが連携されていません。');

        $connection->update([
            'title_keywords' => trim((string) $request->validated('title_keywords')),
            'initial_days' => (int) $request->validated('initial_days'),
            'auto_sync' => $request->boolean('auto_sync'),
            'sync_interval_minutes' => (int) $request->validated('sync_interval_minutes'),
            // 条件を変えたら、初回と同じ範囲から確認し直す（取り込み済みのメモは二重にならない）
            'last_synced_at' => null,
        ]);

        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    public function destroy(Request $request, MeetClient $meet): JsonResponse
    {
        $connection = MeetConnection::query()->where('user_id', $request->user()->id)->first();
        if ($connection !== null) {
            $meet->revoke($connection->refresh_token);
            $connection->delete();
        }

        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    /**
     * 終わった会議のメモを取り込む。auto=true（アプリを開いている間の自動取得）は、設定がオフのとき・AI のキーが
     * 未設定のとき・設定した間隔が過ぎていないとき・直前に失敗したときは何もしない。
     * 同じ人の取得が同時に走ったときは busy を返す。
     */
    public function sync(Request $request, MeetNotesSyncer $syncer): JsonResponse
    {
        $user = $request->user();
        $idle = ['processed' => 0, 'skipped' => 0, 'candidates' => 0, 'has_more' => false];

        if ($request->boolean('auto')) {
            $connection = MeetConnection::query()->where('user_id', $user->id)->first();
            if ($connection === null || ! $connection->auto_sync || ! AiSetting::query()->where('user_id', $user->id)->exists()) {
                return response()->json(['data' => $idle + ['status' => 'disabled']]);
            }
            // 少し早めに届いた確認でも動けるよう、30 秒の余裕を見る。
            $threshold = CarbonImmutable::now()->subSeconds($connection->sync_interval_minutes * 60 - 30);
            if ($connection->last_synced_at !== null && $connection->last_synced_at->greaterThan($threshold)) {
                return response()->json(['data' => $idle + ['status' => 'recent']]);
            }
            // 直前に失敗していたら、次の間隔まで待つ（同じ失敗を毎分繰り返さない）
            if ($connection->last_error !== null && $connection->updated_at?->greaterThan($threshold)) {
                return response()->json(['data' => $idle + ['status' => 'recent']]);
            }
        }

        $lock = Cache::lock('meet-sync:'.$user->id, 130);
        if (! $lock->get()) {
            return response()->json(['data' => $idle + ['status' => 'busy']]);
        }

        set_time_limit(120);
        try {
            $result = $syncer->sync($user);
        } catch (MeetException|AiNotConfiguredException|AiRequestFailedException $e) {
            throw ValidationException::withMessages(['meet' => $e->getMessage()]);
        } finally {
            $lock->release();
        }

        return response()->json(['data' => $result + ['status' => 'done']]);
    }

    /** @return array<string, mixed> */
    private function payload(int $userId): array
    {
        $connection = MeetConnection::query()->where('user_id', $userId)->first();

        return [
            'oauth_ready' => MeetOAuth::configured(),
            'connected' => $connection !== null,
            'google_email' => $connection?->google_email,
            'title_keywords' => $connection->title_keywords ?? config('gf.meet.default_keywords'),
            'initial_days' => $connection->initial_days ?? 3,
            'initial_days_options' => config('gf.gmail.initial_days_options'),
            'auto_sync' => $connection->auto_sync ?? true,
            'sync_interval_minutes' => $connection->sync_interval_minutes ?? config('gf.gmail.default_sync_interval'),
            'sync_interval_options' => config('gf.gmail.sync_interval_options'),
            'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
            'last_error' => $connection?->last_error,
        ];
    }
}

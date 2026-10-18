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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function sync(Request $request, GmailSyncer $syncer): JsonResponse
    {
        set_time_limit(120);

        try {
            $result = $syncer->sync($request->user());
        } catch (GmailException|AiNotConfiguredException|AiRequestFailedException $e) {
            throw ValidationException::withMessages(['gmail' => $e->getMessage()]);
        }

        return response()->json(['data' => $result]);
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
            'initial_days_options' => config('gf.gmail.initial_days_options'),
            'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
            'last_error' => $connection?->last_error,
        ];
    }
}

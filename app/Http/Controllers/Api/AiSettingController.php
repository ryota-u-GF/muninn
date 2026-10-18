<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAiSettingRequest;
use App\Models\AiSetting;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Ai\AiRequestFailedException;
use App\Services\Ai\TaskExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** 利用者本人の AI 設定。API キーは保存のみで、取得 API では返さない。 */
class AiSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    public function update(SaveAiSettingRequest $request): JsonResponse
    {
        $values = ['provider' => 'anthropic', 'model' => $request->validated('model')];
        if ($request->filled('api_key')) {
            $values['api_key'] = $request->validated('api_key');
        }
        AiSetting::query()->updateOrCreate(['user_id' => $request->user()->id], $values);

        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        AiSetting::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    public function test(Request $request, TaskExtractor $extractor): JsonResponse
    {
        try {
            $extractor->verify($request->user());
        } catch (AiNotConfiguredException|AiRequestFailedException $e) {
            throw ValidationException::withMessages(['ai' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(int $userId): array
    {
        $setting = AiSetting::query()->where('user_id', $userId)->first();
        /** @var array<string, string> $models */
        $models = config('gf.ai.models');

        return [
            'configured' => $setting !== null,
            'key_hint' => $setting?->keyHint(),
            'model' => $setting->model ?? config('gf.ai.default_model'),
            'models' => array_map(
                fn (string $id, string $label) => ['id' => $id, 'label' => $label],
                array_keys($models),
                array_values($models),
            ),
        ];
    }
}
